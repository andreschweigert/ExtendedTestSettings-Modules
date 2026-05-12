<?php
/**
 * ExamAlert Handler - Zeit-basierte Benachrichtigungen während Tests
 * Part of ExtendedTestSettings Plugin
 */

class ilExamAlertHandler implements ilExamModuleInterface
{
    private string $plugin_path;

    // Standardnachricht mit Formatierung
    private const DEFAULT_MESSAGE = '<strong><u>Achtung:</u></strong> Ihr Test endet bald! Klicken Sie auf „Speichern und Test beenden", <strong>bevor</strong> die Zeit abläuft. Sonst kann nicht gewährleistet werden, dass die letzte Eingabe gespeichert und bewertet wird.

<strong><u>Attention:</u></strong> Your test will end soon! Click on "Save Answers and Finish the Test" <strong>before</strong> the time runs out. Otherwise, we cannot guarantee that your last entry will be saved and evaluated.';

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;
    }

    /**
     * Prüft ob ExamAlert für den Test aktiviert ist.
     * Legt bei fehlendem Eintrag automatisch Defaults an (enabled=1).
     */
    public function isEnabled(int $ref_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            if ($test_id <= 0) {
                return false;
            }

            $query = "SELECT enabled FROM examalert_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            // Kein Eintrag: Default anlegen — enabled hängt von globaler Auto-Aktivierungs-Einstellung ab
            $auto_activate = (new ilSetting('extendedtestsettings'))->get('examalert_auto_activate', '1') === '1';
            $this->ensureDefaultSettings($test_id, $auto_activate);
            return $auto_activate;

        } catch (Exception $e) {
            error_log("[ExamAlert] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lädt die Alert-Konfiguration für einen Test
     */
    public function getAlertConfig(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            $query = "SELECT alert_time, alert_message FROM examalert_settings WHERE test_id = %s AND enabled = 1";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled' => true,
                    'alert_time' => (int) $row['alert_time'],
                    'alert_message' => $row['alert_message'] ?? self::DEFAULT_MESSAGE
                ];
            }

            // Prüfe ob Test existiert aber deaktiviert ist
            $query2 = "SELECT enabled FROM examalert_settings WHERE test_id = %s";
            $result2 = $db->queryF($query2, ['integer'], [$test_id]);
            if ($db->fetchAssoc($result2)) {
                return ['enabled' => false, 'alert_time' => 30, 'alert_message' => ''];
            }

            // Keine Einstellungen vorhanden = Defaults anlegen, enabled abhängig von globaler Einstellung
            $auto_activate = (new ilSetting('extendedtestsettings'))->get('examalert_auto_activate', '1') === '1';
            $this->ensureDefaultSettings($test_id, $auto_activate);

            return [
                'enabled'       => $auto_activate,
                'alert_time'    => 30,
                'alert_message' => self::DEFAULT_MESSAGE
            ];

        } catch (Exception $e) {
            error_log("[ExamAlert] Fehler beim Laden der Konfiguration: " . $e->getMessage());
        }

        return ['enabled' => false, 'alert_time' => 30, 'alert_message' => ''];
    }

    /**
     * Legt Default-Einstellungen an, falls noch kein Eintrag existiert.
     * $enabled_default wird von der globalen Auto-Aktivierungs-Einstellung der ConfigGUI gesteuert.
     */
    private function ensureDefaultSettings(int $test_id, bool $enabled_default = true): void
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $query = "SELECT test_id FROM examalert_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if (!$db->fetchAssoc($result)) {
                $db->insert('examalert_settings', [
                    'test_id'       => ['integer',   $test_id],
                    'enabled'       => ['integer',   $enabled_default ? 1 : 0],
                    'alert_time'    => ['integer',   30],
                    'alert_message' => ['text',      self::DEFAULT_MESSAGE],
                    'updated_at'    => ['timestamp', date('Y-m-d H:i:s')]
                ]);

                error_log("[ExamAlert] Default-Einstellungen erstellt für Test-ID: $test_id (enabled=" . ($enabled_default ? 1 : 0) . ')');
            }
        } catch (Exception $e) {
            error_log("[ExamAlert] Fehler beim Erstellen der Default-Einstellungen: " . $e->getMessage());
        }
    }

    /**
     * Prüft ob aktueller Kontext ein Testplayer ist
     */
    public function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET["cmdClass"] ?? "");

        // Testplayer-Klassen
        $test_player_classes = [
            'iltestplayer',
            'iltestplayergui',
            'iltestplayercommandsgui',
            'iltestoutputgui'
        ];

        foreach ($test_player_classes as $test_class) {
            if (str_contains($cmd_class, $test_class)) {
                return true;
            }
        }

        // URL-Pattern prüfen
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($request_uri, 'cmd=startPlayer') ||
            str_contains($request_uri, 'cmd=resumePlayer') ||
            str_contains($request_uri, 'cmdClass=ilTestPlayer')) {
            return true;
        }

        return false;
    }

    /**
     * Injiziert alle nötigen Assets (JS, CSS) in Template
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        $config = $this->getAlertConfig($ref_id);

        if (!$config['enabled']) {
            return;
        }

        $css_path = $this->plugin_path . "/modules/examalert/css/examalert.css";
        $tpl->addCss($css_path);

        $js_path = $this->plugin_path . "/modules/examalert/js/examalert.js";
        $tpl->addJavaScript($js_path);

        $config_json = json_encode($config);

        $tpl->addOnLoadCode("
            window.ExamAlert = window.ExamAlert || {};
            window.ExamAlert.config = $config_json;
            window.ExamAlert.refId = $ref_id;
        ");
    }

    /**
     * Speichert ExamAlert-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examalert_enabled']) ? 1 : 0;
            $alert_time = (int) ($post_data['examalert_time'] ?? 30);
            $alert_message = trim($post_data['examalert_message'] ?? '');

            // Validierung
            if ($alert_time < 5) {
                $alert_time = 5; // Minimum 5 Sekunden
            }
            if ($alert_time > 3600) {
                $alert_time = 3600; // Maximum 1 Stunde
            }

            if (empty($alert_message)) {
                $alert_message = self::DEFAULT_MESSAGE;
            }

            $db->replace('examalert_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled],
                'alert_time' => ['integer', $alert_time],
                'alert_message' => ['text', $alert_message],
                'updated_at' => ['timestamp', date('Y-m-d H:i:s')]
            ]);

            return true;

        } catch (Exception $e) {
            error_log("[ExamAlert] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt ILIAS-Formular-Elemente zurück (kein HTML)
     */
    public function getFormItems(int $ref_id): array
    {
        $config = $this->getAlertConfig($ref_id);

        $cb = new ilCheckboxInputGUI('Zeit-Warnung aktivieren', 'examalert_enabled');
        $cb->setChecked($config['enabled']);
        $cb->setInfo('Zeigt eine Warnung zu einer bestimmten Zeit vor Testende an.');

        $num = new ilNumberInputGUI('Warnung anzeigen', 'examalert_time');
        $num->setValue((string) ($config['alert_time'] ?? 30));
        $num->setMinValue(5);
        $num->setMaxValue(3600);
        $num->setSuffix('Sekunden vor Testende');
        $cb->addSubItem($num);

        $ta = new ilTextAreaInputGUI('Nachricht', 'examalert_message');
        $ta->setValue($config['alert_message'] ?? '');
        $ta->setRows(6);
        $ta->setInfo('Diese Nachricht wird den Teilnehmern zur eingestellten Zeit angezeigt. HTML erlaubt: &lt;strong&gt;, &lt;u&gt;, &lt;br&gt;');
        $cb->addSubItem($ta);

        return [$cb];
    }

    /**
     * Generiert HTML für Einstellungs-Formular
     */
    public function getSettingsHTML(int $ref_id): string
    {
        $enabled = $this->isEnabled($ref_id) ? 'checked' : '';
        $config = $this->getAlertConfig($ref_id);
        $alert_time = $config['alert_time'] ?? 30;
        $alert_message = htmlspecialchars($config['alert_message'] ?? self::DEFAULT_MESSAGE, ENT_QUOTES);

        return <<<EOT
<div class="mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="examalert_enabled" value="1" id="examalert_enabled" $enabled>
        <label class="form-check-label fw-bold" for="examalert_enabled">Zeit-Warnung aktivieren</label>
    </div>
    <div class="form-text">Zeigt eine Warnung zu einer bestimmten Zeit vor Testende an.</div>
</div>
<div class="mb-3">
    <label for="examalert_time" class="form-label fw-semibold">Warnung anzeigen:</label>
    <div class="input-group" style="max-width: 300px;">
        <input type="number" class="form-control" name="examalert_time" id="examalert_time"
               value="$alert_time" min="5" max="3600">
        <span class="input-group-text">Sekunden vor Testende</span>
    </div>
</div>
<div class="mb-3">
    <label for="examalert_message" class="form-label fw-semibold">Nachricht:</label>
    <textarea name="examalert_message" id="examalert_message" rows="6"
              class="form-control font-monospace" placeholder="Nachricht...">$alert_message</textarea>
    <div class="form-text">
        Diese Nachricht wird den Teilnehmern zur eingestellten Zeit angezeigt.
        HTML-Formatierung erlaubt: &lt;strong&gt;, &lt;u&gt;, &lt;br&gt;
    </div>
</div>
EOT;
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return [
            'name' => 'ExamAlert',
            'title' => 'Zeit-Warnung',
            'icon' => '⏰',
            'description' => 'Zeigt zeitbasierte Warnungen mit benutzerdefinierten Nachrichten während des Tests an',
            'version' => '1.1.0',
            'features' => [
                'Konfigurierbare Auto-Aktivierung für neue Tests',
                'Einstellbare Warnzeit (5 Sekunden bis 1 Stunde, Default: 30s)',
                'Benutzerdefinierte Warnnachrichten mit HTML-Formatierung',
                'Zweisprachige Standardnachricht (Deutsch/Englisch)',
                'Warnung wird nur einmal pro Test angezeigt',
                'Automatische Zeitmessung im Test',
                'Elegante Popup-Anzeige mit Live-Countdown',
                'Persistente Anzeige bis manuelles Schließen',
                'Responsive Design'
            ]
        ];
    }
}
