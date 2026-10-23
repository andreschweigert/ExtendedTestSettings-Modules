<?php
/**
 * ExamNetQos Handler — clientseitige Netzwerk-Qualitätsmessung
 * Part of ExtendedTestSettings Plugin
 *
 * Misst RTT (und optional Paketverlust via parallele Probes) im Hintergrund
 * während der Prüfung. Schwellwert-Überschreitungen werden automatisch als
 * Ereignisse gespeichert und können in der Admin-Ansicht mit Beschwerden
 * korreliert werden.
 */

class ilExamNetQosHandler implements ilExamModuleInterface
{
    private string $plugin_path;

    private const PROBE_PATH      = '/probe.txt';
    private const TABLE_SETTINGS  = 'examnetqos_settings';
    private const DEFAULT_INTERVAL = 10;
    private const DEFAULT_RTT_WARN = 500;
    private const DEFAULT_RTT_CRIT = 2000;

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
            error_log('[ExamNetQos] isEnabled Fehler: ' . $e->getMessage());
        }
        return false;
    }

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        $settings    = $this->getSettings($ref_id);
        $module_path = $this->plugin_path . '/modules/examnetqos';

        $config = [
            'refId'           => $ref_id,
            'probeUrl'        => $module_path . self::PROBE_PATH,
            'reportUrl'       => ILIAS_HTTP_PATH . '/ilias.php?baseClass=ilUIPluginRouterGUI'
                . '&cmdClass=ilextendedtestsettingsmoduleproxygui'
                . '&module_id=examnetqos&cmd=report&ref_id=' . $ref_id,
            'intervalSeconds' => (int)$settings['interval_seconds'],
            'rttWarnMs'       => (int)$settings['rtt_warn_ms'],
            'rttCritMs'       => (int)$settings['rtt_crit_ms'],
            'lossEnabled'     => (bool)$settings['loss_enabled'],
            'lossProbes'      => (int)$settings['loss_probes'],
            'debug'           => false,
        ];

        // Config MUSS vor dem JS gesetzt sein — addJavaScript läuft vor addOnLoadCode.
        $tpl->addOnLoadCode(
            'window.ExamNetQos = window.ExamNetQos || {};'
            . ' window.ExamNetQos.config = ' . json_encode($config) . ';'
        );
        $tpl->addCss($module_path . '/css/examnetqos.css');
        $tpl->addJavaScript($module_path . '/js/examnetqos.js');
    }

    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();
        try {
            $obj_id   = ilObject::_lookupObjectId($ref_id);
            $enabled  = isset($post_data['examnetqos_enabled']) ? 1 : 0;
            $interval = max(5, min(60,    (int)($post_data['examnetqos_interval']    ?? self::DEFAULT_INTERVAL)));
            $rtt_warn = max(100, min(5000, (int)($post_data['examnetqos_rtt_warn']   ?? self::DEFAULT_RTT_WARN)));
            $rtt_crit = max(200, min(10000,(int)($post_data['examnetqos_rtt_crit']   ?? self::DEFAULT_RTT_CRIT)));
            $loss_en  = isset($post_data['examnetqos_loss_enabled']) ? 1 : 0;
            $loss_n   = max(2, min(10, (int)($post_data['examnetqos_loss_probes'] ?? 3)));

            $db->replace(
                self::TABLE_SETTINGS,
                ['test_id' => ['integer', $obj_id]],
                [
                    'enabled'          => ['integer',   $enabled],
                    'interval_seconds' => ['integer',   $interval],
                    'rtt_warn_ms'      => ['integer',   $rtt_warn],
                    'rtt_crit_ms'      => ['integer',   $rtt_crit],
                    'loss_enabled'     => ['integer',   $loss_en],
                    'loss_probes'      => ['integer',   $loss_n],
                    'updated_at'       => ['timestamp', date('Y-m-d H:i:s')],
                ]
            );
            return true;
        } catch (Exception $e) {
            error_log('[ExamNetQos] saveSettings Fehler: ' . $e->getMessage());
            return false;
        }
    }

    public function getFormItems(int $ref_id): array
    {
        $s = $this->getSettings($ref_id);

        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'examnetqos_enabled');
        $cb->setChecked((bool)$s['enabled']);
        $cb->setInfo($this->lng->txt('enable_info'));

        $interval = new ilSelectInputGUI($this->lng->txt('interval_label'), 'examnetqos_interval');
        $interval->setOptions([
            5  => $this->lng->txt('interval_5'),
            10 => $this->lng->txt('interval_10'),
            30 => $this->lng->txt('interval_30'),
        ]);
        $interval->setValue((int)$s['interval_seconds']);

        $rtt_warn = new ilNumberInputGUI($this->lng->txt('rtt_warn_label'), 'examnetqos_rtt_warn');
        $rtt_warn->setValue((int)$s['rtt_warn_ms']);
        $rtt_warn->setMinValue(100);
        $rtt_warn->setMaxValue(5000);
        $rtt_warn->setInfo($this->lng->txt('rtt_warn_info'));

        $rtt_crit = new ilNumberInputGUI($this->lng->txt('rtt_crit_label'), 'examnetqos_rtt_crit');
        $rtt_crit->setValue((int)$s['rtt_crit_ms']);
        $rtt_crit->setMinValue(200);
        $rtt_crit->setMaxValue(10000);
        $rtt_crit->setInfo($this->lng->txt('rtt_crit_info'));

        $loss_cb = new ilCheckboxInputGUI($this->lng->txt('loss_enable'), 'examnetqos_loss_enabled');
        $loss_cb->setChecked((bool)$s['loss_enabled']);
        $loss_cb->setInfo($this->lng->txt('loss_enable_info'));

        $loss_probes = new ilNumberInputGUI($this->lng->txt('loss_probes_label'), 'examnetqos_loss_probes');
        $loss_probes->setValue((int)$s['loss_probes']);
        $loss_probes->setMinValue(2);
        $loss_probes->setMaxValue(10);
        $loss_probes->setInfo($this->lng->txt('loss_probes_info'));
        $loss_cb->addSubItem($loss_probes);

        $cb->addSubItem($interval);
        $cb->addSubItem($rtt_warn);
        $cb->addSubItem($rtt_crit);
        $cb->addSubItem($loss_cb);

        return [$cb];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'ExamNetQos',
            'title'       => $this->lng->txt('title'),
            'icon'        => '📶',
            'description' => $this->lng->txt('description'),
            'version'     => '1.2.1',
            'features'    => [
                'Hintergrund-RTT-Messung via HEAD-Request auf probe.txt (kein Eingriff in Testplayer)',
                'Optionaler Paketverlust via N parallele Probes (konfigurierbar pro Test)',
                'navigator.connection opportunistisch ausgelesen (effectiveType, kein Extra-Request)',
                'Automatische Ereignis-Erkennung: degraded, critical, recovered',
                'Batch-Reporting via fetch() + sendBeacon() beim Seitenverlassen',
                'Konfigurierbar pro Test: Intervall, RTT-Schwellwerte, Paketverlust-Probes',
                'Admin-Zeitstrahl: SVG-Chart mit RTT-Kurve und Ereignis-Markierungen pro Session',
                'CSV-Export aller Mess-Ereignisse',
            ],
        ];
    }

    private function getSettings(int $ref_id): array
    {
        global $DIC;
        $db       = $DIC->database();
        $defaults = [
            'enabled'          => 0,
            'interval_seconds' => self::DEFAULT_INTERVAL,
            'rtt_warn_ms'      => self::DEFAULT_RTT_WARN,
            'rtt_crit_ms'      => self::DEFAULT_RTT_CRIT,
            'loss_enabled'     => 1,
            'loss_probes'      => 3,
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
            error_log('[ExamNetQos] getSettings Fehler: ' . $e->getMessage());
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
