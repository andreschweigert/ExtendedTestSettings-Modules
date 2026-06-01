<?php
/**
 * ExamFullscreen Handler - Vollbild-Modus während Prüfungen
 * Part of ExtendedTestSettings Plugin
 */

class ilExamFullscreenHandler implements ilExamModuleInterface
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
                'SELECT enabled FROM examfullscr_settings WHERE test_id = %s',
                ['integer'],
                [$test_id]
            );

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }
        } catch (Exception $e) {
            error_log('[ExamFullscreen] Fehler beim Prüfen der Aktivierung: ' . $e->getMessage());
        }

        return false;
    }

    public function getConfig(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $defaults = ['enabled' => false, 'mode' => 'warn'];

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            $result = $db->queryF(
                'SELECT enabled, mode FROM examfullscr_settings WHERE test_id = %s',
                ['integer'],
                [$test_id]
            );

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled' => (bool) $row['enabled'],
                    'mode'    => in_array($row['mode'], ['warn', 'force']) ? $row['mode'] : 'warn',
                ];
            }
        } catch (Exception $e) {
            error_log('[ExamFullscreen] Fehler beim Laden der Konfiguration: ' . $e->getMessage());
        }

        return $defaults;
    }

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

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id)) {
            return;
        }

        if (!$this->isTestPlayerContext()) {
            // Nicht im Test-Player: Session-Key aufräumen, damit ein
            // neuer Teststart immer das Start-Overlay zeigt.
            $tpl->addOnLoadCode("sessionStorage.removeItem('examfullscreen_{$ref_id}');");
            return;
        }

        $config = $this->getConfig($ref_id);
        $config['ref_id'] = $ref_id;

        $tpl->addCss($this->plugin_path . '/modules/examfullscreen/css/examfullscreen.css');

        $config_json = json_encode($config);

        // Config MUSS vor dem JS verfügbar sein, da examfullscreen.js
        // beim Laden sofort auf window.ExamFullscreen.config zugreift.
        $tpl->addOnLoadCode("
            window.ExamFullscreen = window.ExamFullscreen || {};
            window.ExamFullscreen.config = {$config_json};
        ");
        $tpl->addJavaScript($this->plugin_path . '/modules/examfullscreen/js/examfullscreen.js');
    }

    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id  = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examfullscreen_enabled']) ? 1 : 0;
            $mode    = ($post_data['examfullscreen_mode'] ?? 'warn') === 'force' ? 'force' : 'warn';

            $db->replace(
                'examfullscr_settings',
                ['test_id' => ['integer', $obj_id]],
                [
                    'enabled' => ['integer', $enabled],
                    'mode'    => ['text',    $mode],
                ]
            );

            return true;
        } catch (Exception $e) {
            error_log('[ExamFullscreen] Fehler beim Speichern: ' . $e->getMessage());
            return false;
        }
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'ExamFullscreen',
            'title'       => $this->lng->txt('title'),
            'icon'        => '⛶',
            'description' => $this->lng->txt('description'),
            'version'     => '2.1.0',
            'features'    => [
                'Browser-Fullscreen (F11) statt Fullscreen-API — überlebt Fragenwechsel',
                'OS-Erkennung: zeigt F11 (Windows/Linux) oder Ctrl+Cmd+F (Mac)',
                'Erkennung wenn Vollbild verlassen wird (via resize-Event)',
                'Modus Warnen: Hinweis-Overlay mit Dismiss-Option',
                'Modus Sperren: Blockiert Test bis Vollbild wiederhergestellt',
                'Session-Key pro Test (ref_id) — kein Bluten zwischen Tests',
            ],
        ];
    }

    public function getFormItems(int $ref_id): array
    {
        $config = $this->getConfig($ref_id);

        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'examfullscreen_enabled');
        $cb->setChecked($config['enabled']);
        $cb->setInfo($this->lng->txt('enable_info'));

        $sel = new ilSelectInputGUI($this->lng->txt('mode_label'), 'examfullscreen_mode');
        $sel->setOptions([
            'warn'  => $this->lng->txt('mode_warn'),
            'force' => $this->lng->txt('mode_force'),
        ]);
        $sel->setValue($config['mode']);
        $sel->setInfo($this->lng->txt('mode_info'));

        $cb->addSubItem($sel);

        return [$cb];
    }
}
