<?php

/**
 * ExamCalc Handler - Komplette Logic für Taschenrechner-Integration
 * Part of ExtendedTestSettings Plugin
 */

class ilExamCalcHandler implements ilExamModuleInterface
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
     * Prüft ob ExamCalc für den Test aktiviert ist
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

            $query = "SELECT enabled FROM examcalc_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            return false;
        } catch (Exception $e) {
            error_log("[ExamCalc] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
            return false;
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
        if (
            str_contains($request_uri, 'cmd=startPlayer') ||
            str_contains($request_uri, 'cmd=resumePlayer') ||
            str_contains($request_uri, 'cmdClass=ilTestPlayer')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Injiziert alle nötigen Assets (CSS, JS, HTML) in Template
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        $calculator_mode = $this->getCalculatorMode($ref_id);

        $css_path = $this->plugin_path . "/modules/examcalc/css/examcalc.css";
        $tpl->addCss($css_path);

        $template_filename = ($calculator_mode === 'simple')
            ? 'calculator_simple.html'
            : 'calculator_overlay.html';

        if ($calculator_mode === 'simple') {
            $js_calc_path = $this->plugin_path . "/modules/examcalc/js/calculator-simple.js";
        } else {
            // math.js muss vor calculator.js geladen werden (kein CDN — Prüfungskontext)
            $tpl->addJavaScript($this->plugin_path . "/modules/examcalc/js/math.min.js");
            $js_calc_path = $this->plugin_path . "/modules/examcalc/js/calculator.js";
        }

        $js_loader_path = $this->plugin_path . "/modules/examcalc/js/examcalc-loader.js";

        $tpl->addJavaScript($js_calc_path);
        $tpl->addJavaScript($js_loader_path);

        $template_path = $this->plugin_path . "/modules/examcalc/templates/$template_filename";
        $template_path_js = json_encode($template_path, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $calculator_mode_js = json_encode($calculator_mode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        $tpl->addOnLoadCode("
            window.ExamCalc = window.ExamCalc || {};
            window.ExamCalc.templatePath = $template_path_js;
            window.ExamCalc.calculatorMode = $calculator_mode_js;
        ");
    }

    /**
     * Prüft welcher Modus aktiv ist
     */
    public function getCalculatorMode(int $ref_id): string
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            if ($test_id <= 0) {
                return 'simple';
            }

            $query = "SELECT calculator_mode FROM examcalc_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return $this->normalizeCalculatorMode($row['calculator_mode'] ?? null);
            }

            return 'simple';
        } catch (Exception $e) {
            error_log("[ExamCalc] Fehler beim Laden des Modus: " . $e->getMessage());
            return 'simple';
        }
    }

    /**
     * Speichert ExamCalc-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examcalc_enabled']) ? 1 : 0;
            $calculator_mode = $this->normalizeCalculatorMode($post_data['examcalc_mode'] ?? null);

            $db->replace('examcalc_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled],
                'calculator_mode' => ['text', $calculator_mode]
            ]);

            return true;
        } catch (Exception $e) {
            error_log("[ExamCalc] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    private function normalizeCalculatorMode(mixed $mode): string
    {
        return is_string($mode) && in_array($mode, ['simple', 'advanced'], true)
            ? $mode
            : 'simple';
    }

    /**
     * Gibt ILIAS-Formular-Elemente zurück (kein HTML)
     */
    public function getFormItems(int $ref_id): array
    {
        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'examcalc_enabled');
        $cb->setChecked($this->isEnabled($ref_id));
        $cb->setInfo($this->lng->txt('enable_info'));

        $sel = new ilSelectInputGUI($this->lng->txt('type_label'), 'examcalc_mode');
        $sel->setOptions([
            'simple'   => $this->lng->txt('mode_simple'),
            'advanced' => $this->lng->txt('mode_advanced'),
        ]);
        $sel->setValue($this->getCalculatorMode($ref_id));
        $sel->setInfo($this->lng->txt('type_info'));
        $cb->addSubItem($sel);

        return [$cb];
    }

    /**
     * Generiert HTML für Einstellungs-Formular
     */
    public function getSettingsHTML(int $ref_id): string
    {
        $enabled = $this->isEnabled($ref_id) ? 'checked' : '';
        $current_mode = $this->getCalculatorMode($ref_id);

        $simple_selected = ($current_mode === 'simple') ? 'selected' : '';
        $advanced_selected = ($current_mode === 'advanced') ? 'selected' : '';

        $t_enable      = $this->lng->txt('enable');
        $t_enable_info = $this->lng->txt('enable_info');
        $t_type        = $this->lng->txt('type_label');
        $t_simple      = $this->lng->txt('mode_simple');
        $t_advanced    = $this->lng->txt('mode_advanced');
        $t_type_info   = $this->lng->txt('type_info');

        return <<<HTML
<div class="mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="examcalc_enabled" value="1" id="examcalc_enabled" $enabled>
        <label class="form-check-label fw-bold" for="examcalc_enabled">$t_enable</label>
    </div>
    <div class="form-text">$t_enable_info</div>
</div>
<div class="mb-3">
    <label for="examcalc_mode" class="form-label fw-semibold">$t_type:</label>
    <select name="examcalc_mode" id="examcalc_mode" class="form-select">
        <option value="simple" $simple_selected>$t_simple</option>
        <option value="advanced" $advanced_selected>$t_advanced</option>
    </select>
    <div class="form-text">$t_type_info</div>
</div>
HTML;
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return [
            'name' => 'ExamCalc',
            'title' => $this->lng->txt('title'),
            'icon' => '🧮',
            'description' => $this->lng->txt('description'),
            'version' => '10.0.2',
            'features' => [
                'Floating Button (Alt + R)',
                'Einfacher Modus (Grundrechenarten)',
                'Wissenschaftlicher Modus (Beta)',
                'Drag & Drop',
                'Verlaufspeicher (nur wissenschaftlich)'
            ]
        ];
    }
}
