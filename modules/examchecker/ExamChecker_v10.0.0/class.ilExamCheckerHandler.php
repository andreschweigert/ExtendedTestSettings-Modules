<?php

/**
 * ExamChecker Handler - Prüfung von Test-Einstellungen gegen Standards
 * Part of ExtendedTestSettings Plugin
 */

class ilExamCheckerHandler implements ilExamModuleInterface
{
    private string $plugin_path;
    private ilModuleLanguage $lng;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    /**
     * ExamChecker ist immer "enabled" - es ist ein Tool, kein Feature
     */
    public function isEnabled(int $ref_id): bool
    {
        return true; // Immer verfügbar
    }

    /**
     * ExamChecker injiziert keine Assets in den Testplayer
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        // ExamChecker läuft nur in den Test-Einstellungen, nicht im Testplayer
    }

    /**
     * Gibt ILIAS-Formular-Elemente zurück
     * ExamChecker rendert sein UI als Custom-HTML-Block
     */
    public function getFormItems(int $ref_id): array
    {
        $item = new ilCustomInputGUI($this->lng->txt('check_title'), '');
        $item->setHTML($this->getSettingsHTML($ref_id));
        return [$item];
    }

    /**
     * Generiert HTML für Einstellungs-Formular (mit Check-Button)
     */
    public function getSettingsHTML(int $ref_id): string
    {
        $t_type      = $this->lng->txt('exam_type_label');
        $t_praesenz  = $this->lng->txt('type_praesenz');
        $t_fern      = $this->lng->txt('type_fern');
        $t_start     = $this->lng->txt('start_check');
        $t_checking  = $this->lng->txt('checking');

        $html = '<div class="mb-3">
            <label for="exam_type" class="form-label fw-semibold">' . $t_type . ':</label>
            <select name="exam_type" id="exam_type" class="form-select" style="max-width: 250px;">
                <option value="praesenz">' . $t_praesenz . '</option>
                <option value="fern">' . $t_fern . '</option>
            </select>
        </div>
        <div class="mb-3">
            <button type="button" id="start-exam-check" class="btn btn-success">
                ' . $t_start . '
            </button>
        </div>
        <div id="check-loading" class="d-none text-center py-4 text-muted">
            ' . $t_checking . '
        </div>
        <div id="check-results" class="d-none mt-3">
            <!-- Ergebnisse werden hier per AJAX eingefügt -->
        </div>';

        $html .= $this->getJavaScriptCode($ref_id);

        return $html;
    }

    /**
     * JavaScript-Code separat
     */
    private function getJavaScriptCode(int $ref_id): string
    {
        $t_checking_btn = addslashes($this->lng->txt('checking_btn'));
        $t_start        = addslashes($this->lng->txt('start_check'));
        $t_err          = addslashes($this->lng->txt('err_label'));

        return '
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const checkButton = document.getElementById("start-exam-check");
            const resultsDiv = document.getElementById("check-results");
            const loadingDiv = document.getElementById("check-loading");
            const examTypeSelect = document.getElementById("exam_type");

            if (checkButton) {
                checkButton.addEventListener("click", function() {
                    const examType = examTypeSelect.value;
                    const refId = ' . $ref_id . ';


                    // UI-Status
                    checkButton.disabled = true;
                    checkButton.innerHTML = "<span>⏳</span><span>' . $t_checking_btn . '</span>";
                    resultsDiv.style.display = "none";
                    loadingDiv.style.display = "block";

                    const postData = "action=exam_check&ref_id=" + refId + "&exam_type=" + examType;

                    // KORREKTE AJAX-URL: cmd=saveSettings (nicht cmd=show!)
                    let ajaxUrl = window.location.href;
                    ajaxUrl = ajaxUrl.replace("cmd=show", "cmd=saveSettings");
                    ajaxUrl = ajaxUrl.replace("&debug=1", ""); // Debug-Parameter entfernen für AJAX


                    // AJAX-Request
                    fetch(ajaxUrl, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded",
                        },
                        body: postData
                    })
                    .then(response => {

                        if (!response.ok) {
                            throw new Error("HTTP " + response.status + ": " + response.statusText);
                        }

                        return response.text(); // Erstmal als Text um zu sehen was ankommt
                    })
                    .then(text => {

                        try {
                            const data = JSON.parse(text);

                            loadingDiv.style.display = "none";
                            resultsDiv.innerHTML = data.html;
                            resultsDiv.style.display = "block";

                            // Button zurücksetzen
                            checkButton.disabled = false;
                            checkButton.innerHTML = "<span>🔍</span><span>' . $t_start . '</span>";

                        } catch (parseError) {
                            console.error("[ExamChecker] JSON Parse Error:", parseError);
                            throw new Error("Antwort ist kein gültiges JSON: " + text.substring(0, 200));
                        }
                    })
                    .catch(error => {
                        console.error("[ExamChecker] Fetch Error:", error);
                        loadingDiv.style.display = "none";
                        resultsDiv.innerHTML = "<div class=\"alert alert-danger\">❌ ' . $t_err . ' " + error.message + "</div>";
                        resultsDiv.style.display = "block";

                        checkButton.disabled = false;
                        checkButton.innerHTML = "<span>🔍</span><span>' . $t_start . '</span>";
                    });
                });
            } else {
                console.error("[ExamChecker] Button nicht gefunden!");
            }
        });
        </script>';
    }


    /**
     * Speichert ExamChecker-Einstellungen und führt Check durch
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        if (empty($post_data)) {
            $post_data = $_POST;
        }

        if (isset($post_data['action']) && $post_data['action'] === 'exam_check') {
            $this->handleExamCheck($ref_id, $post_data);
            return true;
        }

        return true;
    }

    /**
     * Führt den eigentlichen Exam-Check durch
     */
    private function handleExamCheck(int $ref_id, array $post_data): void
    {
        try {
            $exam_type = $post_data['exam_type'] ?? 'praesenz';
            $results = $this->performExamCheck($ref_id, $exam_type);
            $html = $this->generateCheckResultsHTML($results, $exam_type);

            header('Content-Type: application/json');
            $response = json_encode([
                'html' => $html,
                'success' => true
            ]);

            echo $response;
            exit;
        } catch (Exception $e) {
            error_log("[ExamChecker] Fehler in handleExamCheck: " . $e->getMessage());
            error_log("[ExamChecker] Stack Trace: " . $e->getTraceAsString());

            header('Content-Type: application/json');
            echo json_encode(array(
                'error' => $e->getMessage(),
                'success' => false,
                'debug' => 'Fehler aufgetreten'
            ));
            exit;
        }
    }

    /**
     * Hauptfunktion: Führt alle Checks durch
     */
    private function performExamCheck(int $ref_id, string $exam_type): array
    {
        $test_data = $this->getTestDataFromDB($ref_id);
        if (!$test_data) {
            throw new Exception($this->lng->txt('err_no_test_data'));
        }

        return array(
            'einstellungen' => $this->checkEinstellungen($test_data, $exam_type),
            'auswertung' => $this->checkAuswertung($test_data, $exam_type),
            'manuell_check' => $this->checkManuellePrüfungen($test_data, $exam_type)
        );
    }

    /**
     * Lädt Test-Daten direkt aus der Datenbank
     */
    private function getTestDataFromDB(int $ref_id): ?array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            if ($obj_id <= 0) {
                return null;
            }

            // Prüfe ob es wirklich ein Test ist
            $obj_type = ilObject::_lookupType($obj_id);
            if ($obj_type !== 'tst') {
                return null;
            }

            // KORRIGIERT: obj_fi statt test_id verwenden!
            $query = "SELECT * FROM tst_tests WHERE obj_fi = %s";
            $result = $db->queryF($query, array('integer'), array($obj_id));

            if ($row = $db->fetchAssoc($result)) {
                return $row;
            } else {
                return null;
            }
        } catch (Exception $e) {
            error_log("[ExamChecker] Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Prüft die Grundeinstellungen des Tests
     */
    private function checkEinstellungen(array $test_data, string $exam_type): array
    {
        $checks = array();

        // Standards aus Plugin-Konfiguration laden
        $required_standards = $this->getConfiguredStandards($exam_type);

        // Alle möglichen Checks durchführen, aber nur die konfigurierten bewerten
        $all_checks = $this->performAllChecks($test_data, $exam_type);

        // Nur die konfigurierten Standards zurückgeben
        foreach ($required_standards as $standard) {
            if (isset($all_checks[$standard])) {
                $checks[$standard] = $all_checks[$standard];
            }
        }

        return $checks;
    }

    private function getConfiguredStandards(string $exam_type): array
    {
        try {
            $settings = new ilSetting('extendedtestsettings');
            $standards_string = $settings->get("examchecker_standards_$exam_type", '');

            if (!empty($standards_string)) {
                return explode(',', $standards_string);
            }
        } catch (Exception $e) {
            error_log("[ExamChecker] Fehler beim Laden der Standards: " . $e->getMessage());
        }

        // Fallback auf hardcodierte Defaults
        return $this->getHardcodedDefaults($exam_type);
    }

    private function getHardcodedDefaults(string $exam_type): array
    {
        $defaults = [
            'fragenpools_verwenden',
            'datenschutz',
            'online',
            'passwort',
            'nur_ausgewaehlte_teilnehmer',
            'testdurchlaeufe_begrenzen',
            'wartezeit_erzwingen',
            'pruefungsansicht',
            'automatisches_speichern',
            'direkte_rueckmeldung',
            'fragen_markieren',
            'bewertungssystem',
            'negative_punkte',
            'besten_durchlauf_bewerten',
            'keine_vorbedingungen',
            'direkte_notenuebermittlung',
            'zeitlich_begrenzte_verfuegbarkeit'
        ];

        if ($exam_type === 'fern') {
            $defaults[] = 'eigenstaendigkeitserklaerung';
            $defaults[] = 'bearbeitungsdauer';
        }

        if ($exam_type === 'praesenz') {
            $defaults[] = 'weiterleitung';
        }

        return $defaults;
    }

    /**
     * Führt alle möglichen Checks durch (ohne Filterung)
     */
    private function performAllChecks(array $test_data, string $exam_type): array
    {
        $checks = array();

        // Basis-Checks für alle Prüfungstypen
        $checks['fragenpools_verwenden'] = (int)$test_data['pool_usage'] === 1;
        $checks['datenschutz'] = (int)$test_data['anonymity'] === 0; // Mit Namen
        $checks['online'] = (int)$test_data['online_status'] === 1;
        $checks['passwort'] = (int)$test_data['password_enabled'] === 1;
        $checks['nur_ausgewaehlte_teilnehmer'] = (int)$test_data['fixed_participants'] === 0; // Negiert!

        // Testdurchläufe begrenzen (auf 1)
        $checks['testdurchlaeufe_begrenzen'] = (int)$test_data['nr_of_tries'] === 1;

        // Wartezeit zwischen Durchläufen erzwingen (sollte AUS sein)
        $checks['wartezeit_erzwingen'] = empty($test_data['pass_waiting']) || $test_data['pass_waiting'] === '0';

        // Prüfungsansicht (Kiosk-Modus)
        $checks['pruefungsansicht'] = (int)$test_data['kiosk'] === 1;

        // Automatisches Speichern (30s Intervall)
        $autosave_enabled = (int)$test_data['autosave'] === 1;
        $autosave_interval = (int)$test_data['autosave_ival'] === 30000; # 30 Sekunden in Millisekunden
        $checks['automatisches_speichern'] = $autosave_enabled && $autosave_interval;

        // Weitere Standard-Einstellungen
        $checks['direkte_rueckmeldung'] = (int)$test_data['instant_verification'] === 0; // Sollte AUS sein
        $checks['fragen_markieren'] = (int)$test_data['show_marker'] === 1;

        // Check ob Vorbedingungen aktiv
        $checks['keine_vorbedingungen'] = $this->checkKeineVorbedingungen($test_data);

        // Direkte Notenübermittlung sollte AUS sein (0)
        $checks['direkte_notenuebermittlung'] = (int)$test_data['score_reporting'] === 0;

        // Zeitlich begrenzte Verfügbarkeit prüfen
        $checks['zeitlich_begrenzte_verfuegbarkeit'] = $this->checkZeitlichBegrenzteVerfuegbarkeit($test_data);

        // Spezifische Checks je nach Prüfungstyp
        if ($exam_type === 'fern') {
            $checks['eigenstaendigkeitserklaerung'] = (int)$test_data['require_authorship_statement'] === 1;
            $checks['bearbeitungsdauer'] = (int)$test_data['enable_processing_time'] === 1;
        }

        if ($exam_type === 'praesenz') {
            // Weiterleitung bei Präsenzprüfungen (Mode 2 = nur bei Kiosk, logout.php)
            $redirection_mode = (int)$test_data['redirection_mode'] === 2;
            $redirection_url = trim($test_data['redirection_url']) === 'logout.php';
            $checks['weiterleitung'] = $redirection_mode && $redirection_url;
        }

        // Bewertungssystem-Checks (für beide Typen relevant)
        $checks['bewertungssystem'] = (int)$test_data['count_system'] === 0;
        $checks['negative_punkte'] = (int)$test_data['score_cutting'] === 0;
        $checks['besten_durchlauf_bewerten'] = (int)$test_data['pass_scoring'] === 1;

        return $checks;
    }

    /**
     * Prüft ob zeitlich begrenzte Verfügbarkeit DEAKTIVIERT ist
     */
    private function checkZeitlichBegrenzteVerfuegbarkeit(array $test_data): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();

            $obj_id = $test_data['obj_fi'];

            $ref_query = "SELECT ref_id FROM object_reference WHERE obj_id = %s AND deleted IS NULL LIMIT 1";
            $ref_result = $db->queryF($ref_query, ['integer'], [$obj_id]);
            $ref_id = 0;
            if ($ref_row = $db->fetchAssoc($ref_result)) {
                $ref_id = (int)$ref_row['ref_id'];
            }

            if ($ref_id <= 0) {
                return true; // Bei Fehler als "OK" bewerten
            }

            $timing_query = "SELECT timing_start, timing_end FROM crs_items WHERE obj_id = %s";
            $timing_result = $db->queryF($timing_query, ['integer'], [$ref_id]);

            if ($timing_row = $db->fetchAssoc($timing_result)) {
                $timing_start = (int)($timing_row['timing_start'] ?? 0);
                $timing_end = (int)($timing_row['timing_end'] ?? 0);

                return ($timing_start === 0) && ($timing_end === 0);
            } else {
                return true; // Kein Eintrag = keine Begrenzung = OK
            }
        } catch (Exception $e) {
            return true; // Bei Fehler als "OK" bewerten
        }
    }

    /**
     * Prüft ob KEINE Vorbedingungen für den Test definiert sind
     */
    private function checkKeineVorbedingungen(array $test_data): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();

            $obj_id = $test_data['obj_fi'];

            $ref_query = "SELECT ref_id FROM object_reference WHERE obj_id = %s AND deleted IS NULL LIMIT 1";
            $ref_result = $db->queryF($ref_query, ['integer'], [$obj_id]);
            $ref_id = 0;
            if ($ref_row = $db->fetchAssoc($ref_result)) {
                $ref_id = (int)$ref_row['ref_id'];
            }

            $details_query = "
            SELECT
                c.trigger_obj_id,
                c.trigger_ref_id,
                c.operator,
                c.value,
                od.title as trigger_title,
                od.type as trigger_type
            FROM conditions c
            LEFT JOIN object_data od ON c.trigger_obj_id = od.obj_id
            WHERE c.target_obj_id = %s OR c.target_ref_id = %s
        ";

            $details_result = $db->queryF($details_query, ['integer', 'integer'], [$obj_id, $ref_id]);
            $conditions = [];

            while ($row = $db->fetchAssoc($details_result)) {
                $conditions[] = [
                    'trigger_title' => $row['trigger_title'] ?? $this->lng->txt('cond_unknown'),
                    'trigger_type' => $row['trigger_type'] ?? '',
                    'operator' => $row['operator'] ?? '',
                    'value' => $row['value'] ?? '',
                    'description' => $this->formatConditionDescription($row)
                ];
            }

            $has_conditions = !empty($conditions);

            // Details für HTML-Generierung speichern
            global $examchecker_conditions_details;
            $examchecker_conditions_details = [
                'has_conditions' => $has_conditions,
                'count' => count($conditions),
                'details' => $conditions,
                'obj_id' => $obj_id,
                'ref_id' => $ref_id
            ];

            return !$has_conditions; // TRUE wenn KEINE Vorbedingungen
        } catch (Exception $e) {
            return true; // Bei Fehler als "OK" bewerten
        }
    }

    /**
     * Formatiert Vorbedingung für Anzeige
     */
    private function formatConditionDescription(array $condition): string
    {
        $type_names = [
            'crs'  => $this->lng->txt('ctype_crs'),
            'tst'  => $this->lng->txt('ctype_tst'),
            'lm'   => $this->lng->txt('ctype_lm'),
            'file' => $this->lng->txt('ctype_file'),
            'exc'  => $this->lng->txt('ctype_exc'),
            'frm'  => $this->lng->txt('ctype_frm'),
            'grp'  => $this->lng->txt('ctype_grp'),
            'cat'  => $this->lng->txt('ctype_cat'),
        ];

        $type = $type_names[$condition['trigger_type']] ?? ucfirst($condition['trigger_type']);
        $title = $condition['trigger_title'] ?? $this->lng->txt('cond_unknown');
        $operator = $condition['operator'];
        $value = $condition['value'];

        if ($operator === 'passed') {
            return sprintf($this->lng->txt('cond_must_pass'), $type, $title);
        } elseif ($operator === 'finished') {
            return sprintf($this->lng->txt('cond_must_finish'), $type, $title);
        } elseif ($operator && $value) {
            return sprintf($this->lng->txt('cond_op_value'), $type, $title, $operator, $value);
        } else {
            return sprintf($this->lng->txt('cond_op_only'), $type, $title, $operator);
        }
    }

    /**
     * Prüft die Auswertungseinstellungen
     */
    private function checkAuswertung(array $test_data, string $exam_type): array
    {
        // Auswertung ist in performAllChecks() integriert
        return [];
    }

    /**
     * Einstellungen die manuell geprüft werden müssen
     */
    private function checkManuellePrüfungen(array $test_data, string $exam_type): array
    {
        $manual_checks = array();

        $start_enabled = (int)$test_data['starting_time_enabled'] === 1;
        $end_enabled = (int)$test_data['ending_time_enabled'] === 1;
        $start_time = (int)$test_data['starting_time'];
        $end_time = (int)$test_data['ending_time'];

        $times_properly_set = $start_enabled && $end_enabled &&
            $start_time > 0 && $end_time > 0 &&
            !$this->isDefaultDateTime($start_time) &&
            !$this->isDefaultDateTime($end_time);

        $manual_checks['start_und_ende'] = array(
            'status' => $times_properly_set,
            'current_start' => $start_time > 0 ? date('d.m.Y H:i', $start_time) : $this->lng->txt('mc_not_set'),
            'current_end' => $end_time > 0 ? date('d.m.Y H:i', $end_time) : $this->lng->txt('mc_not_set'),
            'message' => $this->lng->txt('mc_start_end_msg')
        );

        $processing_enabled = (int)$test_data['enable_processing_time'] === 1;
        $processing_time = $test_data['processing_time'] ?? '';

        $manual_checks['bearbeitungsdauer'] = array(
            'status' => $processing_enabled && !empty($processing_time) && $processing_time > 0,
            'current_time' => $processing_enabled ? ($processing_time . ' ' . $this->lng->txt('mc_minutes')) : $this->lng->txt('mc_not_active'),
            'message' => $this->lng->txt('mc_duration_msg')
        );

        return $manual_checks;
    }

    /**
     * Generiert HTML für Check-Ergebnisse
     */
    private function generateCheckResultsHTML(array $results, string $exam_type): string
    {
        $settings_descriptions = $this->getSettingDescriptions();

        $total = count($results['einstellungen']);
        $passed = count(array_filter($results['einstellungen']));
        $failed = $total - $passed;
        $percentage = $total > 0 ? round(($passed / $total) * 100) : 0;

        if ($percentage >= 90) {
            $summary_color = '#28a745';
            $summary_icon = '🎉';
        } elseif ($percentage >= 70) {
            $summary_color = '#fd7e14';
            $summary_icon = '⚠️';
        } else {
            $summary_color = '#dc3545';
            $summary_icon = '❌';
        }

        $config_hint = $total > 0 ? '' : '
    <div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem;">
        <div style="color: #856404; display: flex; align-items: center; gap: 0.5rem;">
            <span>⚙️</span>
            <strong>' . $this->lng->txt('no_standards_title') . '</strong>
        </div>
        <p style="margin: 0.5rem 0 0 0; color: #856404; font-size: 0.9rem;">
            ' . $this->lng->txt('no_standards_text') . '
            <br><a href="ilias.php?baseClass=ilAdministrationGUI&cmdClass=ilobjcomponentsettingsgui&cmdNode=lg:mk&cmd=configure&pluginname=ExtendedTestSettings" style="color: #856404; text-decoration: underline;">' . $this->lng->txt('to_config') . '</a>
        </p>
    </div>';

        $html = '<div class="check-results-container" style="margin-top: 1rem;">';
        $html .= $config_hint;

        if ($total > 0) {
            // Zusammenfassung
            $html .= '<div style="background: ' . $summary_color . '15; border: 2px solid ' . $summary_color . '; border-radius: 0.5rem; padding: 1.5rem; margin-bottom: 2rem; text-align: center;">';
            $html .= '<div style="font-size: 3rem; margin-bottom: 0.5rem;">' . $summary_icon . '</div>';
            $html .= '<h3 style="color: ' . $summary_color . '; margin: 0 0 0.5rem 0;">' . $this->lng->txt('check_done') . ' ' . $percentage . '%</h3>';
            $html .= '<p style="margin: 0; color: #6c757d;">';
            $html .= '<strong>' . $passed . '</strong> ' . $this->lng->txt('res_correct') . ' • <strong>' . $failed . '</strong> ' . $this->lng->txt('res_tocheck') . ' • <strong>' . count($results['manuell_check']) . '</strong> ' . $this->lng->txt('res_manual');
            $html .= '</p></div>';

            // Erfolgreich geprüfte Einstellungen
            $passed_items = array();
            foreach ($results['einstellungen'] as $key => $passed_check) {
                if ($passed_check) $passed_items[] = $settings_descriptions[$key] ?? $key;
            }

            if (!empty($passed_items)) {
                $html .= '<div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem;">';
                $html .= '<h4 style="color: #155724; margin: 0 0 0.75rem 0; display: flex; align-items: center; gap: 0.5rem;">';
                $html .= '<span>✅</span> ' . $this->lng->txt('correct_heading') . ' (' . count($passed_items) . ')';
                $html .= '</h4>';
                $html .= '<ul style="margin: 0; padding-left: 1.5rem; color: #155724;">';
                foreach ($passed_items as $item) {
                    $html .= '<li style="margin-bottom: 0.25rem;">' . htmlspecialchars($item) . '</li>';
                }
                $html .= '</ul></div>';
            }

            // Fehlerhafte Einstellungen
            $failed_items = array();
            foreach ($results['einstellungen'] as $key => $passed_check) {
                if (!$passed_check) $failed_items[] = $settings_descriptions[$key] ?? $key;
            }

            if (!empty($failed_items)) {
                $html .= '<div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem;">';
                $html .= '<h4 style="color: #721c24; margin: 0 0 0.75rem 0; display: flex; align-items: center; gap: 0.5rem;">';
                $html .= '<span>❌</span> ' . $this->lng->txt('failed_heading') . ' (' . count($failed_items) . ')';
                $html .= '</h4>';
                $html .= '<ul style="margin: 0; padding-left: 1.5rem; color: #721c24;">';
                foreach ($failed_items as $item) {
                    $html .= '<li style="margin-bottom: 0.25rem;"><strong>' . htmlspecialchars($item) . '</strong></li>';
                }
                $html .= '</ul></div>';
            }
        }

        // Vorbedingungen-Warnung
        global $examchecker_conditions_details;
        if (isset($examchecker_conditions_details) && $examchecker_conditions_details['has_conditions']) {
            $details = $examchecker_conditions_details;

            $html .= '<div style="
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 2px solid #fd7e14;
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin: 2rem 0;
            box-shadow: 0 4px 12px rgba(253, 126, 20, 0.15);
            position: relative;
            overflow: hidden;
        ">';
            $html .= '<div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">';
            $html .= '<div style="font-size: 2.5rem; animation: pulse 2s infinite; filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.1));">⚠️</div>';
            $html .= '<div>';
            $html .= '<h3 style="color: #dc6545; margin: 0; font-size: 1.3rem; font-weight: 700;">' . $this->lng->txt('cond_found') . '</h3>';
            $html .= '<p style="color: #dc6545; margin: 0.25rem 0 0 0; font-weight: 500;">' . sprintf($this->lng->txt('cond_count'), $details['count']) . '</p>';
            $html .= '</div></div>';
            $html .= '<div style="background: rgba(255,255,255,0.7); border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem;">';
            $html .= '<h4 style="color: #dc6545; margin: 0 0 0.75rem 0;">📋 ' . $this->lng->txt('cond_list_heading') . '</h4>';
            $html .= '<ul style="margin: 0; padding-left: 1.5rem; color: #dc6545; font-weight: 500;">';
            foreach ($details['details'] as $condition) {
                $html .= '<li style="margin-bottom: 0.5rem;">🔗 ' . htmlspecialchars($condition['description']) . '</li>';
            }
            $html .= '</ul></div>';
            $html .= '<div style="background: rgba(220,101,69,0.1); border-left: 4px solid #dc6545; padding: 1rem; border-radius: 0 0.375rem 0.375rem 0;">';
            $html .= '<h4 style="color: #dc6545; margin: 0 0 0.5rem 0;">💡 ' . $this->lng->txt('cond_attention') . '</h4>';
            $html .= '<p style="color: #dc6545; margin: 0; font-size: 0.9rem;">' . $this->lng->txt('cond_advice') . '</p>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<style>@keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }</style>';
        }

        // Manuelle Checks
        if (!empty($results['manuell_check'])) {
            $html .= '<div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem;">';
            $html .= '<h4 style="color: #856404; margin: 0 0 0.75rem 0; display: flex; align-items: center; gap: 0.5rem;"><span>⚠️</span> ' . $this->lng->txt('manual_heading') . '</h4>';

            foreach ($results['manuell_check'] as $key => $data) {
                $name = $settings_descriptions[$key] ?? $key;
                $status_icon = $data['status'] ? '✅' : '⚠️';
                $status_text = $data['status'] ? $this->lng->txt('status_ok') : $this->lng->txt('status_tocheck');

                $html .= '<div style="margin-bottom: 0.75rem; padding: 0.5rem; background: white; border-radius: 0.25rem;">';
                $html .= '<div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">';
                $html .= '<span>' . $status_icon . '</span>';
                $html .= '<strong style="color: #856404;">' . htmlspecialchars($name) . ': ' . $status_text . '</strong>';
                $html .= '</div>';
                $html .= '<p style="margin: 0; color: #6c757d; font-size: 0.9rem; padding-left: 1.5rem;">' . htmlspecialchars($data['message']) . '</p>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Deutsche Beschreibungen für Settings
     */
    private function getSettingDescriptions(): array
    {
        $keys = [
            'fragenpools_verwenden', 'datenschutz', 'online', 'passwort',
            'nur_ausgewaehlte_teilnehmer', 'testdurchlaeufe_begrenzen', 'wartezeit_erzwingen',
            'pruefungsansicht', 'automatisches_speichern', 'direkte_rueckmeldung',
            'fragen_markieren', 'eigenstaendigkeitserklaerung', 'bearbeitungsdauer',
            'weiterleitung', 'bewertungssystem', 'negative_punkte', 'besten_durchlauf_bewerten',
            'start_und_ende', 'keine_vorbedingungen', 'direkte_notenuebermittlung',
            'zeitlich_begrenzte_verfuegbarkeit',
        ];

        $descriptions = array();
        foreach ($keys as $key) {
            $descriptions[$key] = $this->lng->txt('desc_' . $key);
        }

        return $descriptions;
    }

    /**
     * Hilfsfunktion: Prüft ob DateTime ein Default-Wert ist
     */
    private function isDefaultDateTime($timestamp): bool
    {
        if (!$timestamp || $timestamp <= 0) {
            return true;
        }

        $date_str = date('d.m.Y', $timestamp);
        return in_array($date_str, array('01.04.2022', '01.12.2022', '01.01.1970'));
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return array(
            'name' => 'ExamChecker',
            'title' => $this->lng->txt('title'),
            'icon' => '🔍',
            'description' => $this->lng->txt('description'),
            'version' => '1.2.0',
            'features' => array()
        );
    }
}
