<?php
/**
 * ExamMagnifier Handler - Bildschirmlupe mit Vollbild-Zoom, Farbinvertierung und Zeiger-Hervorhebung
 * Part of ExtendedTestSettings Plugin
 */

class ilExamMagnifierHandler implements ilExamModuleInterface
{
    private string $plugin_path;
    private ilModuleLanguage $lng;

    private const ALLOWED_MAX_ZOOM = [400, 800, 1600];
    private const ALLOWED_MODES = ['all', 'list'];
    private const USER_LIST_MAX_LENGTH = 8000;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    /**
     * Prüft ob ExamMagnifier für den Test aktiviert ist
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

            $query = "SELECT enabled FROM exammagnifier_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            return false;

        } catch (Exception $e) {
            error_log("[ExamMagnifier] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
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
            'enabled'      => false,
            'mode'         => 'all',
            'user_list'    => '',
            'allow_zoom'   => true,
            'max_zoom'     => 1600,
            'allow_invert' => true,
            'allow_cursor' => true,
        ];

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            if ($test_id <= 0) {
                return $defaults;
            }

            $query = "SELECT enabled, mode, user_list, allow_zoom, max_zoom, allow_invert, allow_cursor
                      FROM exammagnifier_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled'      => (bool) $row['enabled'],
                    'mode'         => in_array($row['mode'] ?? 'all', self::ALLOWED_MODES, true) ? $row['mode'] : 'all',
                    'user_list'    => (string) ($row['user_list'] ?? ''),
                    'allow_zoom'   => (bool) $row['allow_zoom'],
                    'max_zoom'     => in_array((int) $row['max_zoom'], self::ALLOWED_MAX_ZOOM, true) ? (int) $row['max_zoom'] : 1600,
                    'allow_invert' => (bool) $row['allow_invert'],
                    'allow_cursor' => (bool) $row['allow_cursor'],
                ];
            }

        } catch (Exception $e) {
            error_log("[ExamMagnifier] Fehler beim Laden der Konfiguration: " . $e->getMessage());
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
     * Zerlegt die hinterlegte Benutzerliste in normalisierte Einträge
     * (ein Eintrag pro Zeile, Komma/Semikolon ebenfalls erlaubt)
     */
    private function parseUserList(string $user_list): array
    {
        $entries = preg_split('/[\r\n,;]+/', $user_list) ?: [];
        $normalized = [];

        foreach ($entries as $entry) {
            $entry = mb_strtolower(trim($entry));
            if ($entry !== '') {
                $normalized[$entry] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * Prüft ob der aktuelle Benutzer die Hilfen sehen darf
     * (mode=all: jeder; mode=list: Abgleich Login ODER Matrikelnummer)
     */
    private function userHasAccess(array $config): bool
    {
        if ($config['mode'] !== 'list') {
            return true;
        }

        $entries = $this->parseUserList($config['user_list']);
        if (empty($entries)) {
            return false;
        }

        try {
            global $DIC;
            $user = $DIC->user();

            $login         = mb_strtolower(trim($user->getLogin()));
            $matriculation = mb_strtolower(trim((string) $user->getMatriculation()));

            foreach ($entries as $entry) {
                if ($entry === $login || ($matriculation !== '' && $entry === $matriculation)) {
                    return true;
                }
            }

        } catch (Exception $e) {
            error_log("[ExamMagnifier] Fehler beim Benutzerabgleich: " . $e->getMessage());
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

        if (!$config['enabled'] || !$this->userHasAccess($config)) {
            return;
        }

        // Mindestens eine Hilfe muss freigegeben sein
        if (!$config['allow_zoom'] && !$config['allow_invert'] && !$config['allow_cursor']) {
            return;
        }

        // Cache-Busting via filemtime: URL ändert sich bei jeder Dateiänderung
        $css_file = $this->plugin_path . "/modules/exammagnifier/css/exammagnifier.css";
        $js_file  = $this->plugin_path . "/modules/exammagnifier/js/exammagnifier.js";
        $css_v    = @filemtime(ILIAS_ABSOLUTE_PATH . '/' . $css_file) ?: time();
        $js_v     = @filemtime(ILIAS_ABSOLUTE_PATH . '/' . $js_file)  ?: time();

        $tpl->addCss($css_file . '?v=' . $css_v);
        $tpl->addJavaScript($js_file . '?v=' . $js_v);

        $config_json = json_encode([
            'enabled'      => true,
            'ref_id'       => $ref_id,
            'allow_zoom'   => $config['allow_zoom'],
            'max_zoom'     => $config['max_zoom'],
            'allow_invert' => $config['allow_invert'],
            'allow_cursor' => $config['allow_cursor'],
            'labels'       => [
                'tb_label'  => $this->lng->txt('js_tb_label'),
                'tb_open'   => $this->lng->txt('js_tb_open'),
                'tb_close'  => $this->lng->txt('js_tb_close'),
                'zoom_in'   => $this->lng->txt('js_zoom_in'),
                'zoom_out'  => $this->lng->txt('js_zoom_out'),
                'invert'    => $this->lng->txt('js_invert'),
                'cursor'    => $this->lng->txt('js_cursor'),
                'deadzone'  => $this->lng->txt('js_deadzone'),
            ],
        ]);

        $tpl->addOnLoadCode("
            window.ExamMagnifier = window.ExamMagnifier || {};
            window.ExamMagnifier.config = $config_json;
        ");
    }

    /**
     * Speichert ExamMagnifier-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id  = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['exammagnifier_enabled']) ? 1 : 0;

            $mode = $post_data['exammagnifier_mode'] ?? 'all';
            if (!in_array($mode, self::ALLOWED_MODES, true)) {
                $mode = 'all';
            }

            // Benutzerliste normalisieren (eine Zeile pro Eintrag) und begrenzen
            $user_list = implode("\n", $this->parseUserList((string) ($post_data['exammagnifier_user_list'] ?? '')));
            $user_list = mb_substr($user_list, 0, self::USER_LIST_MAX_LENGTH);

            $allow_zoom   = isset($post_data['exammagnifier_allow_zoom']) ? 1 : 0;
            $allow_invert = isset($post_data['exammagnifier_allow_invert']) ? 1 : 0;
            $allow_cursor = isset($post_data['exammagnifier_allow_cursor']) ? 1 : 0;

            $max_zoom = (int) ($post_data['exammagnifier_max_zoom'] ?? 1600);
            if (!in_array($max_zoom, self::ALLOWED_MAX_ZOOM, true)) {
                $max_zoom = 1600;
            }

            $db->replace('exammagnifier_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled'      => ['integer',   $enabled],
                'mode'         => ['text',      $mode],
                'user_list'    => ['clob',      $user_list],
                'allow_zoom'   => ['integer',   $allow_zoom],
                'max_zoom'     => ['integer',   $max_zoom],
                'allow_invert' => ['integer',   $allow_invert],
                'allow_cursor' => ['integer',   $allow_cursor],
                'updated_at'   => ['timestamp', date('Y-m-d H:i:s')],
            ]);

            return true;

        } catch (Exception $e) {
            error_log("[ExamMagnifier] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt ILIAS-Formular-Elemente zurück
     */
    public function getFormItems(int $ref_id): array
    {
        $config = $this->getConfig($ref_id);

        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'exammagnifier_enabled');
        $cb->setChecked($config['enabled']);
        $cb->setInfo($this->lng->txt('enable_info'));

        // Zielgruppe: alle Teilnehmer oder nur hinterlegte Benutzer
        $mode = new ilRadioGroupInputGUI($this->lng->txt('mode_label'), 'exammagnifier_mode');

        $opt_all = new ilRadioOption($this->lng->txt('mode_all'), 'all');
        $opt_all->setInfo($this->lng->txt('mode_all_info'));
        $mode->addOption($opt_all);

        $opt_list = new ilRadioOption($this->lng->txt('mode_list'), 'list');
        $opt_list->setInfo($this->lng->txt('mode_list_info'));

        $ta = new ilTextAreaInputGUI($this->lng->txt('user_list_label'), 'exammagnifier_user_list');
        $ta->setRows(5);
        $ta->setInfo($this->lng->txt('user_list_info'));
        $ta->setValue($config['user_list']);
        $opt_list->addSubItem($ta);

        $mode->addOption($opt_list);
        $mode->setValue($config['mode']);
        $cb->addSubItem($mode);

        // Vollbild-Zoom
        $zoom = new ilCheckboxInputGUI($this->lng->txt('allow_zoom'), 'exammagnifier_allow_zoom');
        $zoom->setChecked($config['allow_zoom']);
        $zoom->setInfo($this->lng->txt('allow_zoom_info'));

        $mz = new ilSelectInputGUI($this->lng->txt('max_zoom_label'), 'exammagnifier_max_zoom');
        $mz->setOptions([
            '400'  => '400 %',
            '800'  => '800 %',
            '1600' => '1600 %',
        ]);
        $mz->setValue((string) $config['max_zoom']);
        $zoom->addSubItem($mz);
        $cb->addSubItem($zoom);

        // Farbinvertierung
        $inv = new ilCheckboxInputGUI($this->lng->txt('allow_invert'), 'exammagnifier_allow_invert');
        $inv->setChecked($config['allow_invert']);
        $inv->setInfo($this->lng->txt('allow_invert_info'));
        $cb->addSubItem($inv);

        // Großer Mauszeiger
        $cur = new ilCheckboxInputGUI($this->lng->txt('allow_cursor'), 'exammagnifier_allow_cursor');
        $cur->setChecked($config['allow_cursor']);
        $cur->setInfo($this->lng->txt('allow_cursor_info'));
        $cb->addSubItem($cur);

        return [$cb];
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return [
            'name'        => 'ExamMagnifier',
            'title'       => $this->lng->txt('title'),
            'icon'        => '🔍',
            'description' => $this->lng->txt('description'),
            'version'     => '1.0.2',
            'features'    => [
                'Vollbild-Zoom bis 1600 % mit Schiebe-Steuerung: Ruhezone in der Mitte, Mausbewegung am Rand schiebt den Ausschnitt (wie Windows-Bildschirmlupe)',
                'Farbinvertierung (Bilder/Videos bleiben natürlich)',
                'Großer Mauszeiger mit Klick-Hervorhebung',
                'Schwebende Toolbar unten links + Tastatur-Shortcuts (Strg+Alt+Plus/Minus/0/I/P)',
                'Freigabe für alle Teilnehmer oder nur für hinterlegte Benutzer (Nachteilsausgleich)',
                'Zustand in sessionStorage (überlebt Fragenwechsel)',
                'Rein clientseitig, keine externen Ressourcen (Kiosk-tauglich)',
            ]
        ];
    }
}
