<?php
/**
 * ExamKeymap Handler - Virtuelles Tastatur-Layout (DE/EN) für Kiosk-Geräte
 * Part of ExtendedTestSettings Plugin
 */

class ilExamKeymapHandler implements ilExamModuleInterface
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
     * Prüft ob ExamKeymap für den Test aktiviert ist
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

            $result = $db->queryF(
                'SELECT enabled FROM examkeymap_settings WHERE test_id = %s',
                ['integer'],
                [$test_id]
            );

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }
        } catch (Exception $e) {
            error_log('[ExamKeymap] Fehler beim Prüfen der Aktivierung: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Lädt die Konfiguration des Tests (defensiv, mit Defaults)
     */
    public function getConfig(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $defaults = ['enabled' => false, 'default_layout' => 'off', 'search_caps' => false];

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            $result = $db->queryF(
                'SELECT enabled, default_layout, search_caps FROM examkeymap_settings WHERE test_id = %s',
                ['integer'],
                [$test_id]
            );

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled'        => (bool) $row['enabled'],
                    'default_layout' => in_array($row['default_layout'], ['de', 'en', 'off'], true)
                        ? $row['default_layout']
                        : 'off',
                    'search_caps'    => (bool) $row['search_caps'],
                ];
            }
        } catch (Exception $e) {
            error_log('[ExamKeymap] Fehler beim Laden der Konfiguration: ' . $e->getMessage());
        }

        return $defaults;
    }

    /**
     * Lädt die globale Übersetzungstabelle (Overrides aus der Administration).
     * Rückgabe: ['de' => [keycode => ['b'=>…, 's'=>…, 'g'=>…]], 'en' => […]]
     */
    public function getCustomMap(): array
    {
        global $DIC;
        $db = $DIC->database();

        $custom = ['de' => [], 'en' => []];

        try {
            $result = $db->query(
                'SELECT layout, keycode, base_char, shift_char, altgr_char FROM examkeymap_custom'
            );
            while ($row = $db->fetchAssoc($result)) {
                $layout = $row['layout'];
                if (!isset($custom[$layout])) {
                    continue;
                }
                $custom[$layout][$row['keycode']] = [
                    'b' => (string) ($row['base_char'] ?? ''),
                    's' => (string) ($row['shift_char'] ?? ''),
                    'g' => (string) ($row['altgr_char'] ?? ''),
                ];
            }
        } catch (Exception $e) {
            // Tabelle könnte fehlen (Update noch nicht eingespielt) → keine Overrides
            error_log('[ExamKeymap] Fehler beim Laden der Übersetzungstabelle: ' . $e->getMessage());
        }

        return $custom;
    }

    /**
     * Prüft ob aktueller Kontext ein Testplayer ist
     */
    public function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET['cmdClass'] ?? '');

        $player_classes = [
            'iltestplayer',
            'iltestplayergui',
            'iltestplayercommandsgui',
            'iltestoutputgui',
        ];

        foreach ($player_classes as $cls) {
            if (str_contains($cmd_class, $cls)) {
                return true;
            }
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($uri, 'cmd=startPlayer')
            || str_contains($uri, 'cmd=resumePlayer')
            || str_contains($uri, 'cmdClass=ilTestPlayer');
    }

    /**
     * Injiziert Assets (CSS + JS + Config) in Template — nur im Testplayer
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        $config = $this->getConfig($ref_id);
        $config['ref_id'] = $ref_id;
        $config['player'] = true;
        $config['custom'] = $this->getCustomMap();

        // Sichtbare Button-Texte aus den Modul-Sprachdateien mitgeben —
        // das JS hat keinen Zugriff auf den Plugin-Übersetzer.
        $config['txt'] = [
            'tooltip'      => $this->lng->txt('btn_tooltip'),
            'caps_tooltip' => $this->lng->txt('btn_caps_tooltip'),
            'os'           => $this->lng->txt('btn_os'),
            'de'           => $this->lng->txt('btn_de'),
            'en'           => $this->lng->txt('btn_en'),
        ];

        $tpl->addCss($this->plugin_path . '/modules/examkeymap/css/examkeymap.css');

        $config_json = json_encode($config);

        $tpl->addOnLoadCode("
            window.ExamKeymap = window.ExamKeymap || {};
            window.ExamKeymap.config = {$config_json};
        ");
        $tpl->addJavaScript($this->plugin_path . '/modules/examkeymap/js/examkeymap.js');
    }

    /**
     * Speichert ExamKeymap-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id      = ilObject::_lookupObjectId($ref_id);
            $enabled     = isset($post_data['examkeymap_enabled']) ? 1 : 0;
            $search_caps = isset($post_data['examkeymap_search_caps']) ? 1 : 0;
            $default     = $post_data['examkeymap_default'] ?? 'off';

            if (!in_array($default, ['de', 'en', 'off'], true)) {
                $default = 'off';
            }

            // created_at beim Update erhalten (replace überschreibt alle Spalten)
            $now     = date('Y-m-d H:i:s');
            $created = $now;
            $result  = $db->queryF(
                'SELECT created_at FROM examkeymap_settings WHERE test_id = %s',
                ['integer'],
                [$obj_id]
            );
            if (($row = $db->fetchAssoc($result)) && !empty($row['created_at'])) {
                $created = $row['created_at'];
            }

            $db->replace(
                'examkeymap_settings',
                ['test_id' => ['integer', $obj_id]],
                [
                    'enabled'        => ['integer',   $enabled],
                    'default_layout' => ['text',      $default],
                    'search_caps'    => ['integer',   $search_caps],
                    'created_at'     => ['timestamp', $created],
                    'updated_at'     => ['timestamp', $now],
                ]
            );

            return true;
        } catch (Exception $e) {
            error_log('[ExamKeymap] Fehler beim Speichern: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt ILIAS-Formular-Elemente zurück (kein HTML)
     */
    public function getFormItems(int $ref_id): array
    {
        $config = $this->getConfig($ref_id);

        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'examkeymap_enabled');
        $cb->setChecked($config['enabled']);
        $cb->setInfo($this->lng->txt('enable_info'));

        $sel = new ilSelectInputGUI($this->lng->txt('default_label'), 'examkeymap_default');
        $sel->setOptions([
            'off' => $this->lng->txt('default_off'),
            'de'  => $this->lng->txt('default_de'),
            'en'  => $this->lng->txt('default_en'),
        ]);
        $sel->setValue($config['default_layout']);
        $sel->setInfo($this->lng->txt('default_info'));

        $caps = new ilCheckboxInputGUI($this->lng->txt('search_caps'), 'examkeymap_search_caps');
        $caps->setChecked($config['search_caps']);
        $caps->setInfo($this->lng->txt('search_caps_info'));

        $cb->addSubItem($sel);
        $cb->addSubItem($caps);

        return [$cb];
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return [
            'name'        => 'ExamKeymap',
            'title'       => $this->lng->txt('title'),
            'icon'        => '⌨',
            'description' => $this->lng->txt('description'),
            'version'     => '10.0.2',
            'features'    => [
                'Virtuelles Tastatur-Layout DE (QWERTZ) / EN-US (QWERTY), unabhängig vom OS-Layout',
                'Umschalt-Button im Testplayer: OS → DE → EN → OS',
                'Basiert auf KeyboardEvent.code (physische Taste) + Shift/AltGr/CapsLock',
                'AltGr-Belegung DE: @ € { } [ ] \\ ~ | µ ² ³, Umlaute ä ö ü ß',
                'Funktioniert in input, textarea und TinyMCE (Freitextfragen)',
                'Layout-Wahl überlebt Fragennavigation (sessionStorage pro Test)',
                'Virtuelle Feststelltaste: ⇪-Button im Testplayer + Lupe-Taste (pro Test)',
                'Globale Übersetzungstabelle mit virtuellem Keyboard-Editor in der Administration',
            ],
        ];
    }
}
