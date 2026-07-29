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
    private const ALLOWED_WORD_SPACINGS = ['0', '0.16em', '0.3em', '0.5em'];

    /**
     * Schrift-Registry. Weitere Schrift ergänzen heißt: Eintrag hier,
     * woff2 nach fonts/, Lizenztext daneben und die Labels 'font_<id>' und
     * 'js_font_<id>' in beide lang-Dateien. CSS und JS bleiben unberührt —
     * @font-face-Regeln und Auswahlliste werden daraus erzeugt.
     *
     * 'stack' leer = Standardschrift des Testplayers, keine Ersetzung.
     */
    private const FONTS = [
        'default' => [
            'family' => '',
            'stack'  => '',
            'faces'  => [],
        ],
        'opendyslexic' => [
            'family' => 'OpenDyslexic',
            'stack'  => '"OpenDyslexic", Arial, sans-serif',
            'faces'  => [
                ['file' => 'OpenDyslexic-Regular', 'weight' => 'normal', 'style' => 'normal', 'formats' => ['woff2', 'woff']],
                ['file' => 'OpenDyslexic-Bold',    'weight' => 'bold',   'style' => 'normal', 'formats' => ['woff2', 'woff']],
            ],
        ],
        'atkinson' => [
            'family' => 'Atkinson Hyperlegible',
            'stack'  => '"Atkinson Hyperlegible", Arial, sans-serif',
            'faces'  => [
                ['file' => 'AtkinsonHyperlegible-Regular',    'weight' => 'normal', 'style' => 'normal', 'formats' => ['woff2']],
                ['file' => 'AtkinsonHyperlegible-Bold',       'weight' => 'bold',   'style' => 'normal', 'formats' => ['woff2']],
                ['file' => 'AtkinsonHyperlegible-Italic',     'weight' => 'normal', 'style' => 'italic', 'formats' => ['woff2']],
                ['file' => 'AtkinsonHyperlegible-BoldItalic', 'weight' => 'bold',   'style' => 'italic', 'formats' => ['woff2']],
            ],
        ],
    ];

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
            'enabled'         => false,
            'allow_toggle'    => true,
            'allow_customize' => true,
            'font_family'     => 'opendyslexic',
            'line_height'     => '1.8',
            'letter_spacing'  => '0.05em',
            'word_spacing'    => '0',
            'bg_color'        => 'none',
            'ruler'           => true,
        ];

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            if ($test_id <= 0) {
                return $defaults;
            }

            // SELECT *: allow_customize (1.2.0), font_family und word_spacing
            // (1.2.2) sowie ruler (1.2.3) kamen nachträglich dazu — so bleibt das
            // Modul auch lauffähig, bevor install.php die Spalten angelegt hat
            $query = "SELECT * FROM examdyslexia_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                // Ohne font_family-Spalte greift die alte use_font-Checkbox
                $font = $row['font_family'] ?? (empty($row['use_font']) ? 'default' : 'opendyslexic');

                return [
                    'enabled'         => (bool) $row['enabled'],
                    'allow_toggle'    => (bool) $row['allow_toggle'],
                    'allow_customize' => (bool) ($row['allow_customize'] ?? 1),
                    'font_family'     => isset(self::FONTS[$font]) ? $font : 'opendyslexic',
                    'line_height'     => $row['line_height'] ?? '1.8',
                    'letter_spacing'  => $row['letter_spacing'] ?? '0.05em',
                    'word_spacing'    => $row['word_spacing'] ?? '0',
                    'bg_color'        => $row['bg_color'] ?? 'none',
                    'ruler'           => (bool) ($row['ruler'] ?? 1),
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
     * Baut die Schriftliste für das JS: Auswahl-Label, CSS-Stack und die
     * @font-face-Quellen mit web-relativem Pfad (das JS löst ihn absolut auf).
     */
    private function getFontList(string $font_path): array
    {
        $fonts = [];

        foreach (self::FONTS as $id => $font) {
            $faces = [];

            foreach ($font['faces'] as $face) {
                $sources = [];
                foreach ($face['formats'] as $format) {
                    $sources[] = [
                        'url'    => $font_path . $face['file'] . '.' . $format,
                        'format' => $format,
                    ];
                }

                $faces[] = [
                    'weight'  => $face['weight'],
                    'style'   => $face['style'],
                    'sources' => $sources,
                ];
            }

            $fonts[] = [
                'id'     => $id,
                'label'  => $this->lng->txt('js_font_' . $id),
                'family' => $font['family'],
                'stack'  => $font['stack'],
                'faces'  => $faces,
            ];
        }

        return $fonts;
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
        // Web-relativer Pfad taugt bei Web-Requests auch als FS-Pfad
        // (CWD = Webroot in 9 wie 10); ILIAS_ABSOLUTE_PATH wäre in 10 ohne public/ falsch.
        $css_v    = @filemtime($css_file) ?: time();
        $js_v     = @filemtime($js_file)  ?: time();

        $tpl->addCss($css_file . '?v=' . $css_v);
        $tpl->addJavaScript($js_file . '?v=' . $js_v);

        // Font-Basispfad für JS-Fallback (absoluter Web-Pfad)
        $font_path = $this->plugin_path . "/modules/examdyslexia/fonts/";

        // Die Dozentenwerte sind die Startwerte; bei allow_customize verstellt der
        // Studierende sie im Panel entlang derselben Whitelisten (Rohwerte, 'none'
        // wird erst im JS zu 'transparent').
        $config_json = json_encode([
            'enabled'         => $config['enabled'],
            'ref_id'          => $ref_id,
            'allow_toggle'    => $config['allow_toggle'],
            'allow_customize' => $config['allow_customize'],
            'font_family'     => $config['font_family'],
            'line_height'     => $config['line_height'],
            'letter_spacing'  => $config['letter_spacing'],
            'word_spacing'    => $config['word_spacing'],
            'bg_color'        => $config['bg_color'],
            'ruler'           => $config['ruler'],
            'font_path'       => $font_path,
            'fonts'           => $this->getFontList($font_path),
            'bg_colors'       => self::ALLOWED_BG_COLORS,
            'line_heights'    => self::ALLOWED_LINE_HEIGHTS,
            'letter_spacings' => self::ALLOWED_LETTER_SPACINGS,
            'word_spacings'   => self::ALLOWED_WORD_SPACINGS,
            'labels'          => [
                'toggle_on'    => $this->lng->txt('js_toggle_on'),
                'toggle_off'   => $this->lng->txt('js_toggle_off'),
                'toggle_aria'  => $this->lng->txt('js_toggle_aria'),
                'toggle_title' => $this->lng->txt('js_toggle_title'),
                'panel_label'  => $this->lng->txt('js_panel_label'),
                'panel_open'   => $this->lng->txt('js_panel_open'),
                'panel_close'  => $this->lng->txt('js_panel_close'),
                'bg_label'     => $this->lng->txt('js_bg_label'),
                'bg_none'      => $this->lng->txt('js_bg_none'),
                'bg_cream'     => $this->lng->txt('js_bg_cream'),
                'bg_yellow'    => $this->lng->txt('js_bg_yellow'),
                'bg_blue'      => $this->lng->txt('js_bg_blue'),
                'lh_label'     => $this->lng->txt('js_lh_label'),
                'lh_inc'       => $this->lng->txt('js_lh_inc'),
                'lh_dec'       => $this->lng->txt('js_lh_dec'),
                'ls_label'     => $this->lng->txt('js_ls_label'),
                'ls_inc'       => $this->lng->txt('js_ls_inc'),
                'ls_dec'       => $this->lng->txt('js_ls_dec'),
                'ls_0'         => $this->lng->txt('js_ls_0'),
                'ls_1'         => $this->lng->txt('js_ls_1'),
                'ls_2'         => $this->lng->txt('js_ls_2'),
                'ls_3'         => $this->lng->txt('js_ls_3'),
                'font_label'   => $this->lng->txt('js_font_label'),
                'ws_label'     => $this->lng->txt('js_ws_label'),
                'ws_inc'       => $this->lng->txt('js_ws_inc'),
                'ws_dec'       => $this->lng->txt('js_ws_dec'),
                'ws_0'         => $this->lng->txt('js_ws_0'),
                'ws_1'         => $this->lng->txt('js_ws_1'),
                'ws_2'         => $this->lng->txt('js_ws_2'),
                'ws_3'         => $this->lng->txt('js_ws_3'),
                'ruler_label'  => $this->lng->txt('js_ruler_label'),
                'ruler_on'     => $this->lng->txt('js_ruler_on'),
                'ruler_off'    => $this->lng->txt('js_ruler_off'),
                'ruler_title'  => $this->lng->txt('js_ruler_title'),
                'reset'        => $this->lng->txt('js_reset'),
                'reset_title'  => $this->lng->txt('js_reset_title'),
            ],
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
            $enabled         = isset($post_data['examdyslexia_enabled']) ? 1 : 0;
            $allow_toggle    = isset($post_data['examdyslexia_allow_toggle']) ? 1 : 0;
            $allow_customize = isset($post_data['examdyslexia_allow_customize']) ? 1 : 0;

            $font_family = $post_data['examdyslexia_font_family'] ?? 'opendyslexic';
            if (!isset(self::FONTS[$font_family])) {
                $font_family = 'opendyslexic';
            }

            // use_font weiterhin konsistent halten: die Spalte bleibt in der DB
            // und wird gelesen, solange font_family fehlt (Downgrade/Altstand)
            $use_font = $font_family === 'default' ? 0 : 1;

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

            $word_spacing = $post_data['examdyslexia_word_spacing'] ?? '0';
            if (!in_array($word_spacing, self::ALLOWED_WORD_SPACINGS, true)) {
                $word_spacing = '0';
            }

            $ruler = isset($post_data['examdyslexia_ruler']) ? 1 : 0;

            $db->replace('examdyslexia_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled'         => ['integer',   $enabled],
                'allow_toggle'    => ['integer',   $allow_toggle],
                'allow_customize' => ['integer',   $allow_customize],
                'use_font'        => ['integer',   $use_font],
                'font_family'     => ['text',      $font_family],
                'line_height'     => ['text',      $line_height],
                'letter_spacing'  => ['text',      $letter_spacing],
                'word_spacing'    => ['text',      $word_spacing],
                'bg_color'        => ['text',      $bg_color],
                'ruler'           => ['integer',   $ruler],
                'updated_at'      => ['timestamp', date('Y-m-d H:i:s')],
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

        $custom = new ilCheckboxInputGUI($this->lng->txt('allow_customize'), 'examdyslexia_allow_customize');
        $custom->setChecked($config['allow_customize']);
        $custom->setInfo($this->lng->txt('allow_customize_info'));
        $cb->addSubItem($custom);

        $font_options = [];
        foreach (array_keys(self::FONTS) as $font_id) {
            $font_options[$font_id] = $this->lng->txt('font_' . $font_id);
        }

        $font = new ilSelectInputGUI($this->lng->txt('font_label'), 'examdyslexia_font_family');
        $font->setOptions($font_options);
        $font->setValue($config['font_family']);
        $font->setInfo($this->lng->txt('font_label_info'));
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

        $ws = new ilSelectInputGUI($this->lng->txt('ws_label'), 'examdyslexia_word_spacing');
        $ws->setOptions([
            '0'      => $this->lng->txt('ws_normal'),
            '0.16em' => $this->lng->txt('ws_light'),
            '0.3em'  => $this->lng->txt('ws_medium'),
            '0.5em'  => $this->lng->txt('ws_strong'),
        ]);
        $ws->setValue($config['word_spacing']);
        $cb->addSubItem($ws);

        $ruler = new ilCheckboxInputGUI($this->lng->txt('ruler'), 'examdyslexia_ruler');
        $ruler->setChecked($config['ruler']);
        $ruler->setInfo($this->lng->txt('ruler_info'));
        $cb->addSubItem($ruler);

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
            'version'     => '1.2.3',
            'features'    => [
                'Schriftauswahl OpenDyslexic oder Atkinson Hyperlegible (lokal, kein CDN)',
                'Studierende passen Schrift, Hintergrundfarbe, Zeilen-, Zeichen- und Wortabstand im Testplayer selbst an',
                'Dozentenwerte sind Startwerte; „Zurücksetzen" stellt sie wieder her',
                'Optionaler Pastell-Hintergrund (Creme/Gelb/Blau)',
                'Leseschiene folgt dem Cursor über der aktuellen Zeile, im Panel abschaltbar',
                'Wirkt auch im TinyMCE-Editor der Freitextfragen',
                'Zuordnungsfragen: Klick auf einen Begriff hebt ihn und seine Zuordnungen hervor',
                'Video- und Audioplayer bleiben von der Einfärbung ausgenommen (Steuerelemente bleiben sichtbar)',
                'Bedienleiste unten mittig (frei von ILIAS-Navigation, examcalc und exammagnifier)',
                'Zustand in sessionStorage (überlebt Fragenwechsel)',
                'Default: deaktiviert – Student schaltet selbst ein',
                'Erzwingungsmodus ohne Toggle-Button konfigurierbar; Anpassung einzeln sperrbar',
            ]
        ];
    }
}
