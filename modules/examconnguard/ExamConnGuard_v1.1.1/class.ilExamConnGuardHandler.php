<?php
/**
 * ExamConnGuard Handler - Verbindungs-Schutz im Test-Player
 * Part of ExtendedTestSettings Plugin
 */

class ilExamConnGuardHandler implements ilExamModuleInterface
{
    private string $plugin_path;

    // Probe-Defaults — können später per Plugin-Setting überschrieben werden.
    // Probe-URL liegt im Modul selbst (probe.txt), garantiert vorhanden,
    // unabhängig von Skin/Server-Konfiguration.
    private const PROBE_PATH             = '/modules/examconnguard/probe.txt';
    private const PREFLIGHT_TIMEOUT_MS   = 1200;
    private const RECONNECT_INTERVAL_MS  = 2000;
    private const RECONNECT_GRACE_MS     = 800;

    private ilModuleLanguage $lng;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    /**
     * Prüft ob ExamConnGuard für den Test aktiviert ist.
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
                'SELECT enabled FROM examconnguard_settings WHERE test_id = %s',
                ['integer'],
                [$test_id]
            );

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }
        } catch (Exception $e) {
            error_log('[ExamConnGuard] Fehler beim Prüfen der Aktivierung: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Prüft ob aktueller Kontext ein Testplayer ist.
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
     * Injiziert Assets (CSS, JS, Config) in den Test-Player.
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        $module_path = $this->plugin_path . '/modules/examconnguard';

        $config = [
            'refId'               => $ref_id,
            'heartbeatUrl'        => $this->plugin_path . self::PROBE_PATH,
            'preflightTimeout'    => self::PREFLIGHT_TIMEOUT_MS,
            'reconnectIntervalMs' => self::RECONNECT_INTERVAL_MS,
            'reconnectGraceMs'    => self::RECONNECT_GRACE_MS,
            'debug'               => false,
        ];

        $config_json = json_encode($config);

        // Config MUSS vor dem JS verfügbar sein — examconnguard.js liest beim
        // Laden direkt window.ExamConnGuard.config.
        $tpl->addOnLoadCode("
            window.ExamConnGuard = window.ExamConnGuard || {};
            window.ExamConnGuard.config = {$config_json};
        ");

        $tpl->addCss($module_path . '/css/examconnguard.css');
        $tpl->addJavaScript($module_path . '/js/examconnguard.js');
    }

    /**
     * Speichert die Modul-Einstellungen für diesen Test.
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id  = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examconnguard_enabled']) ? 1 : 0;

            $db->replace(
                'examconnguard_settings',
                ['test_id' => ['integer', $obj_id]],
                [
                    'enabled'    => ['integer',   $enabled],
                    'updated_at' => ['timestamp', date('Y-m-d H:i:s')],
                ]
            );

            return true;
        } catch (Exception $e) {
            error_log('[ExamConnGuard] Fehler beim Speichern: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt ILIAS-Formular-Elemente für den "Erweiterte Test-Einstellungen"-Tab zurück.
     */
    public function getFormItems(int $ref_id): array
    {
        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'examconnguard_enabled');
        $cb->setChecked($this->isEnabled($ref_id));
        $cb->setInfo($this->lng->txt('enable_info'));

        return [$cb];
    }

    /**
     * Gibt Plugin-Info zurück.
     */
    public function getInfo(): array
    {
        return [
            'name'        => 'ExamConnGuard',
            'title'       => $this->lng->txt('title'),
            'icon'        => '📡',
            'description' => $this->lng->txt('description'),
            'version'     => '1.1.1',
            'features'    => [
                'On-Demand-Probe bei Submit/Link-Klick (kein Hintergrund-Traffic)',
                'Probe-Datei probe.txt im Plugin-Modul (kein Verlass auf Server-Defaults)',
                'Vollbild-Overlay (Shadow-DOM) mit Outage-Timer bei Verbindungsverlust',
                'Queued Submits & Navigationen während Outage',
                'Reconnect-Heartbeat alle 2 s nur während Outage, mit 800 ms Grace',
                'Auto-Replay nach bestätigtem Reconnect',
                'fetch()-Wrapper mit Exponential-Backoff (max 6 Versuche) für Autosaves',
                'beforeunload-Schutz gegen versehentliches F5/Strg+R während Outage',
            ],
        ];
    }
}
