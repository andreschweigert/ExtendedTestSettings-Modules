<?php
/**
 * ExamBattery Handler — stiller Akku-Monitor für Prüfungsgeräte
 * Part of ExtendedTestSettings Plugin
 *
 * Meldet Akkustände der Prüfungsgeräte (Battery Status API, nur Chromium +
 * Secure Context) still an den Server. Im Testplayer entsteht KEINERLEI
 * sichtbare Änderung — die Aufsicht sieht die Stände in einer eigenen
 * Ansicht (cmd=showStatus über den Modul-Proxy) und entscheidet selbst,
 * ob sie eingreift.
 */

class ilExamBatteryHandler implements ilExamModuleInterface
{
    private const MODULE_ID   = 'exambattery';
    private const PROXY_CLASS = 'ilExtendedTestSettingsModuleProxyGUI';
    private const PROXY_PATH  = ['ilUIPluginRouterGUI', self::PROXY_CLASS];

    private const TABLE_SETTINGS = 'exambattery_settings';

    private const DEFAULT_WARN_PCT     = 20;
    private const DEFAULT_CRIT_PCT     = 10;
    private const HEARTBEAT_SECONDS    = 180;

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
                'SELECT enabled FROM ' . self::TABLE_SETTINGS . ' WHERE test_id = %s',
                ['integer'],
                [$test_id]
            );
            if ($row = $db->fetchAssoc($result)) {
                return (bool)$row['enabled'];
            }
        } catch (Exception $e) {
            error_log('[ExamBattery] isEnabled Fehler: ' . $e->getMessage());
        }
        return false;
    }

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        $module_path = $this->plugin_path . '/modules/exambattery';

        // Die Schwellwerte steuern nur die Einfärbung der Aufsichts-Ansicht —
        // das Client-JS braucht sie nicht (keinerlei Anzeige beim Studierenden).
        $config = [
            'refId'            => $ref_id,
            'reportUrl'        => ILIAS_HTTP_PATH . '/ilias.php?baseClass=ilUIPluginRouterGUI'
                . '&cmdClass=ilextendedtestsettingsmoduleproxygui'
                . '&module_id=exambattery&cmd=report&ref_id=' . $ref_id,
            'heartbeatSeconds' => self::HEARTBEAT_SECONDS,
            'debug'            => false,
        ];

        // Config MUSS vor dem JS gesetzt sein — addJavaScript läuft vor addOnLoadCode.
        $tpl->addOnLoadCode(
            'window.ExamBattery = window.ExamBattery || {};'
            . ' window.ExamBattery.config = ' . json_encode($config) . ';'
        );
        $tpl->addJavaScript($module_path . '/js/exambattery.js');
        // Bewusst kein CSS: das Modul erzeugt im Player kein UI.
    }

    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();
        try {
            $obj_id  = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['exambattery_enabled']) ? 1 : 0;
            $warn    = max(5, min(50, (int)($post_data['exambattery_warn_pct'] ?? self::DEFAULT_WARN_PCT)));
            $crit    = max(5, min(30, (int)($post_data['exambattery_crit_pct'] ?? self::DEFAULT_CRIT_PCT)));

            // crit muss unter warn liegen — sonst auf Defaults zurückfallen
            if ($crit >= $warn) {
                $warn = self::DEFAULT_WARN_PCT;
                $crit = self::DEFAULT_CRIT_PCT;
            }

            $db->replace(
                self::TABLE_SETTINGS,
                ['test_id' => ['integer', $obj_id]],
                [
                    'enabled'    => ['integer',   $enabled],
                    'warn_pct'   => ['integer',   $warn],
                    'crit_pct'   => ['integer',   $crit],
                    'updated_at' => ['timestamp', date('Y-m-d H:i:s')],
                ]
            );
            return true;
        } catch (Exception $e) {
            error_log('[ExamBattery] saveSettings Fehler: ' . $e->getMessage());
            return false;
        }
    }

    public function getFormItems(int $ref_id): array
    {
        global $DIC;
        $ctrl = $DIC->ctrl();

        $s = $this->getSettings($ref_id);

        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'exambattery_enabled');
        $cb->setChecked((bool)$s['enabled']);
        $cb->setInfo($this->lng->txt('enable_info'));

        $warn = new ilNumberInputGUI($this->lng->txt('warn_label'), 'exambattery_warn_pct');
        $warn->setValue((int)$s['warn_pct']);
        $warn->setMinValue(5);
        $warn->setMaxValue(50);
        $warn->setInfo($this->lng->txt('warn_info'));

        $crit = new ilNumberInputGUI($this->lng->txt('crit_label'), 'exambattery_crit_pct');
        $crit->setValue((int)$s['crit_pct']);
        $crit->setMinValue(5);
        $crit->setMaxValue(30);
        $crit->setInfo($this->lng->txt('crit_info'));

        $cb->addSubItem($warn);
        $cb->addSubItem($crit);

        // Link auf die Aufsichts-Ansicht (Proxy-Routing, Muster exampreflight)
        $ctrl->setParameterByClass(self::PROXY_CLASS, 'module_id', self::MODULE_ID);
        $ctrl->setParameterByClass(self::PROXY_CLASS, 'ref_id', $ref_id);
        $status_link = $ctrl->getLinkTargetByClass(self::PROXY_PATH, 'showStatus');

        $link = new ilCustomInputGUI($this->lng->txt('status_link_label'), '');
        $link->setHtml(
            '<a href="' . htmlspecialchars($status_link) . '" class="btn btn-default">'
            . htmlspecialchars($this->lng->txt('open_status')) . '</a>'
        );
        $link->setInfo($this->lng->txt('status_link_info'));

        return [$cb, $link];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'ExamBattery',
            'title'       => $this->lng->txt('title'),
            'icon'        => '🔋',
            'description' => $this->lng->txt('description'),
            'version'     => '1.0.1',
            'features'    => [
                'Rein passive Akku-Überwachung via Battery Status API (navigator.getBattery)',
                'Keinerlei Anzeige beim Studierenden — kein Banner, kein Button, kein DOM-Element',
                'Reports bei Akkustand-/Ladezustand-Änderung plus Heartbeat alle 3 Minuten',
                'Desktop-Erkennung (level=100 + lädt) — Arbeitsplatz-PCs erzeugen keine Reports',
                'Ohne Battery-API (Firefox, HTTP-Kontext): Modul bleibt komplett stumm',
                'Aufsichts-Ansicht pro Test: farbige Tabelle (grün/gelb/rot), kritische Geräte oben',
                'Auto-Refresh alle 30 s; Meldungen älter als 10 Minuten werden ausgegraut',
                'Konfigurierbare Anzeige-Schwellen pro Test (gelb/rot), bewusst keine Warnungen an Studierende',
                'Entwickler-Helfer window.ExamBattery.simulate(level, charging) für Tests ohne Akku-Gerät',
            ],
        ];
    }

    private function getSettings(int $ref_id): array
    {
        global $DIC;
        $db       = $DIC->database();
        $defaults = [
            'enabled'  => 0,
            'warn_pct' => self::DEFAULT_WARN_PCT,
            'crit_pct' => self::DEFAULT_CRIT_PCT,
        ];
        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            if ($test_id <= 0) {
                return $defaults;
            }
            $result = $db->queryF(
                'SELECT * FROM ' . self::TABLE_SETTINGS . ' WHERE test_id = %s',
                ['integer'],
                [$test_id]
            );
            if ($row = $db->fetchAssoc($result)) {
                return array_merge($defaults, $row);
            }
        } catch (Exception $e) {
            error_log('[ExamBattery] getSettings Fehler: ' . $e->getMessage());
        }
        return $defaults;
    }

    private function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET['cmdClass'] ?? '');
        foreach ([
            'iltestplayer', 'iltestplayergui',
            'iltestplayerfixedquestionsetgui', 'iltestplayerrandquestionsetgui',
            'iltestoutputgui',
        ] as $cls) {
            if (str_contains($cmd_class, $cls)) {
                return true;
            }
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($uri, 'cmd=startPlayer') || str_contains($uri, 'cmd=resumePlayer');
    }
}
