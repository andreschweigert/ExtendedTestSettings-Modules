<?php
/**
 * ExamDyslexia Handler - Legasthenie-freundlicher Lesemodus
 * Part of ExtendedTestSettings Plugin
 */

class ilExamDyslexiaHandler implements ilExamModuleInterface
{
    private string $plugin_path;

    private const ALLOWED_BG_COLORS = ['none', '#FEFAE0', '#FFFDE7', '#E3F2FD'];
    private const ALLOWED_LINE_HEIGHTS = ['1.5', '1.8', '2.0', '2.5'];
    private const ALLOWED_LETTER_SPACINGS = ['0', '0.05em', '0.12em', '0.2em'];

    private ilModuleLanguage $lng;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    /**
     * Prüft ob ExamDyslexia für den Test aktiviert ist
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

            $query = "SELECT enabled FROM examdyslexia_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            return false;

        } catch (Exception $e) {
            error_log("[ExamDyslexia] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lädt die vollständige Konfiguration für einen Test
     */
    private function getConfig(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $defaults = [
            'enabled'        => false,
            'allow_toggle'   => true,
            'use_font'       => true,
            'line_height'    => '1.8',
            'letter_spacing' => '0.05em',
            'bg_color'       => 'none',
        ];

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            if ($test_id <= 0) {
                return $defaults;
            }

            $query = "SELECT enabled, allow_toggle, use_font, line_height, letter_spacing, bg_color
                      FROM examdyslexia_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled'        => (bool) $row['enabled'],
                    'allow_toggle'   => (bool) $row['allow_toggle'],
                    'use_font'       => (bool) $row['use_font'],
                    'line_height'    => $row['line_height'] ?? '1.8',
                    'letter_spacing' => $row['letter_spacing'] ?? '0.05em',
                    'bg_color'       => $row['bg_color'] ?? 'none',
                ];
            }

        } catch (Exception $e) {
            error_log("[ExamDyslexia] Fehler beim Laden der Konfiguration: " . $e->getMessage());
        }

        return $defaults;
    }

    /**
     * Prüft ob aktueller Kontext ein Testplayer ist
     */
    private function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET["cmdClass"] ?? "");

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

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($request_uri, 'cmd=startPlayer') ||
            str_contains($request_uri, 'cmd=resumePlayer') ||
            str_contains($request_uri, 'cmdClass=ilTestPlayer')) {
            return true;
        }

        return false;
    }

    /**
     * Injiziert CSS, JS und Konfiguration in das Template
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        $config = $this->getConfig($ref_id);

        if (!$config['enabled']) {
            return;
        }

        // Cache-Busting via filemtime: URL ändert sich bei jeder Dateiänderung
        $css_file = $this->plugin_path . "/modules/examdyslexia/css/examdyslexia.css";
        $js_file  = $this->plugin_path . "/modules/examdyslexia/js/examdyslexia.js";
        $css_v    = @filemtime(ILIAS_ABSOLUTE_PATH . '/' . $css_file) ?: time();
        $js_v     = @filemtime(ILIAS_ABSOLUTE_PATH . '/' . $js_file)  ?: time();

        $tpl->addCss($css_file . '?v=' . $css_v);
        $tpl->addJavaScript($js_file . '?v=' . $js_v);

        // Font-Basispfad für JS-Fallback (absoluter Web-Pfad)
        $font_path = $this->plugin_path . "/modules/examdyslexia/fonts/";

        $config_json = json_encode([
            'enabled'        => $config['enabled'],
            'allow_toggle'   => $config['allow_toggle'],
            'use_font'       => $config['use_font'],
            'line_height'    => $config['line_height'],
            'letter_spacing' => $config['letter_spacing'],
            'bg_color'       => $config['bg_color'] !== 'none' ? $config['bg_color'] : 'transparent',
            'font_path'      => $font_path,
        ]);

        $tpl->addOnLoadCode("
            window.ExamDyslexia = window.ExamDyslexia || {};
            window.ExamDyslexia.config = $config_json;
        ");
    }

    /**
     * Speichert ExamDyslexia-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled      = isset($post_data['examdyslexia_enabled']) ? 1 : 0;
            $allow_toggle = isset($post_data['examdyslexia_allow_toggle']) ? 1 : 0;
            $use_font     = isset($post_data['examdyslexia_use_font']) ? 1 : 0;

            $bg_color = $post_data['examdyslexia_bg_color'] ?? 'none';
            if (!in_array($bg_color, self::ALLOWED_BG_COLORS, true)) {
                $bg_color = 'none';
            }

            $line_height = $post_data['examdyslexia_line_height'] ?? '1.8';
            if (!in_array($line_height, self::ALLOWED_LINE_HEIGHTS, true)) {
                $line_height = '1.8';
            }

            $letter_spacing = $post_data['examdyslexia_letter_spacing'] ?? '0.05em';
            if (!in_array($letter_spacing, self::ALLOWED_LETTER_SPACINGS, true)) {
                $letter_spacing = '0.05em';
            }

            $db->replace('examdyslexia_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled'        => ['integer',   $enabled],
                'allow_toggle'   => ['integer',   $allow_toggle],
                'use_font'       => ['integer',   $use_font],
                'line_height'    => ['text',      $line_height],
                'letter_spacing' => ['text',      $letter_spacing],
                'bg_color'       => ['text',      $bg_color],
                'updated_at'     => ['timestamp', date('Y-m-d H:i:s')],
            ]);

            return true;

        } catch (Exception $e) {
            error_log("[ExamDyslexia] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt ILIAS-Formular-Elemente zurück
     */
    public function getFormItems(int $ref_id): array
    {
        $config = $this->getConfig($ref_id);

        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'examdyslexia_enabled');
        $cb->setChecked($config['enabled']);
        $cb->setInfo($this->lng->txt('enable_info'));

        $allow = new ilCheckboxInputGUI($this->lng->txt('allow_toggle'), 'examdyslexia_allow_toggle');
        $allow->setChecked($config['allow_toggle']);
        $allow->setInfo($this->lng->txt('allow_toggle_info'));
        $cb->addSubItem($allow);

        $font = new ilCheckboxInputGUI($this->lng->txt('use_font'), 'examdyslexia_use_font');
        $font->setChecked($config['use_font']);
        $font->setInfo($this->lng->txt('use_font_info'));
        $cb->addSubItem($font);

        $bg = new ilSelectInputGUI($this->lng->txt('bg_label'), 'examdyslexia_bg_color');
        $bg->setOptions([
            'none'    => $this->lng->txt('bg_none'),
            '#FEFAE0' => $this->lng->txt('bg_cream'),
            '#FFFDE7' => $this->lng->txt('bg_yellow'),
            '#E3F2FD' => $this->lng->txt('bg_blue'),
        ]);
        $bg->setValue($config['bg_color']);
        $cb->addSubItem($bg);

        $lh = new ilSelectInputGUI($this->lng->txt('lh_label'), 'examdyslexia_line_height');
        $lh->setOptions([
            '1.5' => '1.5',
            '1.8' => $this->lng->txt('lh_18'),
            '2.0' => '2.0',
            '2.5' => '2.5',
        ]);
        $lh->setValue($config['line_height']);
        $cb->addSubItem($lh);

        $ls = new ilSelectInputGUI($this->lng->txt('ls_label'), 'examdyslexia_letter_spacing');
        $ls->setOptions([
            '0'      => $this->lng->txt('ls_normal'),
            '0.05em' => $this->lng->txt('ls_light'),
            '0.12em' => $this->lng->txt('ls_medium'),
            '0.2em'  => $this->lng->txt('ls_strong'),
        ]);
        $ls->setValue($config['letter_spacing']);
        $cb->addSubItem($ls);

        return [$cb];
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return [
            'name'        => 'ExamDyslexia',
            'title'       => $this->lng->txt('title'),
            'icon'        => '👁',
            'description' => $this->lng->txt('description'),
            'version'     => '1.1.0',
            'features'    => [
                'OpenDyslexic Schrift (lokal, kein CDN)',
                'Konfigurierbarer Zeilen- und Buchstabenabstand',
                'Optionaler Pastell-Hintergrund (Creme/Gelb/Blau)',
                'Toggle-Button für Studierende (oben rechts, kein Konflikt mit examcalc)',
                'Zustand in sessionStorage (überlebt Fragenwechsel)',
                'Default: deaktiviert – Student schaltet selbst ein',
                'Erzwingungsmodus ohne Toggle-Button konfigurierbar',
            ]
        ];
    }
}
