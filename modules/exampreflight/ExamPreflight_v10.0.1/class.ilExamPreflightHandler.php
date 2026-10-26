<?php
/**
 * ExamPreflight Handler — geführter Probedurchlauf durch den Test
 * Part of ExtendedTestSettings Plugin
 *
 * Anders als ExamChecker (statische Einstellungs-Prüfung) prüft ExamPreflight
 * das tatsächliche Rendering: Ein Cockpit (eigene GUI-Seite) lädt den Testplayer
 * in einem iframe, navigiert automatisch durch alle Fragen, prüft pro Frage
 * Medien/Formeln/Layout und erstellt Screenshots. Ergebnisse landen in einem
 * Prüfbericht.
 *
 * Der Handler selbst injiziert NICHTS in den Testplayer — die gesamte Logik
 * läuft im Cockpit (class.ilExamPreflightGUI.php + js/exampreflight.js).
 */

class ilExamPreflightHandler implements ilExamModuleInterface
{
    private const MODULE_ID   = 'exampreflight';
    private const PROXY_CLASS = 'ilExtendedTestSettingsModuleProxyGUI';
    private const PROXY_PATH  = ['ilUIPluginRouterGUI', self::PROXY_CLASS];

    private string $plugin_path;
    private ilModuleLanguage $lng;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    /**
     * Kein Testplayer-Eingriff: Preflight läuft ausschließlich über das Cockpit.
     * false verhindert, dass der UIHook injectAssets() aufruft.
     */
    public function isEnabled(int $ref_id): bool
    {
        return false;
    }

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        // no-op — siehe isEnabled()
    }

    /**
     * Block in den Erweiterten Test-Einstellungen:
     * Start-Button für das Cockpit + Link auf die Berichte + Kurzinfo zum letzten Lauf.
     */
    public function getFormItems(int $ref_id): array
    {
        global $DIC;
        $ctrl = $DIC->ctrl();

        $ctrl->setParameterByClass(self::PROXY_CLASS, 'module_id', self::MODULE_ID);
        $ctrl->setParameterByClass(self::PROXY_CLASS, 'ref_id', $ref_id);

        $cockpit_link = $ctrl->getLinkTargetByClass(self::PROXY_PATH, 'show');
        $report_link  = $ctrl->getLinkTargetByClass(self::PROXY_PATH, 'showReport');

        $html = '<div class="exampreflight-settings-block">'
            . '<p>' . htmlspecialchars($this->lng->txt('settings_intro')) . '</p>'
            . '<a href="' . htmlspecialchars($cockpit_link) . '" class="btn btn-primary">'
            . htmlspecialchars($this->lng->txt('start_cockpit')) . '</a> '
            . '<a href="' . htmlspecialchars($report_link) . '" class="btn btn-default">'
            . htmlspecialchars($this->lng->txt('show_reports')) . '</a>'
            . $this->getLastRunSummaryHTML($ref_id)
            . '</div>';

        $item = new ilCustomInputGUI($this->lng->txt('title'));
        $item->setHtml($html);
        $item->setInfo($this->lng->txt('settings_info'));

        return [$item];
    }

    /**
     * ExamPreflight hat keine test-spezifischen Einstellungen zu speichern.
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        return true;
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'ExamPreflight',
            'title'       => $this->lng->txt('title'),
            'icon'        => '🛫',
            'description' => $this->lng->txt('description'),
            'version'     => '10.0.1',
            'features'    => [
                'Cockpit mit Testplayer im iframe — kein Eingriff in echte Prüfungsdurchläufe',
                'Vollautomatischer Durchlauf: Test-Start, Passwort und Prüfungsbedingungen werden selbst bedient',
                'Automatische Navigation durch alle Fragen (Pause/Abbruch jederzeit)',
                'Eigener Testlauf wird am Ende auf Wunsch automatisch gelöscht (offizielle ILIAS-API, nur der eigene)',
                'Medien-Checks: kaputte Bilder, Video-/Audio-Quellen, iframe-Inhalte, 404-Ressourcen',
                'Formel-Checks: MathJax-Renderfehler (merror) + unverarbeiteter Formel-Code im Text',
                'Layout-Checks: horizontaler Overflow, überstehende Elemente, leere Frageninhalte',
                'Formatierungs-Checks: Encoding-Salat, sichtbarer HTML-Code, Mini-Schrift, kaum lesbarer Text',
                'CSS-Hintergrundbilder per HEAD-Request, Antwortoptionen-Plausibilität (leere Menüs, Einzel-Optionen)',
                'Screenshots pro Frage via Screen-Capture-API (ganze Frage, segmentiert zusammengesetzt)',
                'Prüfbericht pro Lauf: Fragen-Karten mit Status, Befunden und Screenshot',
                'Läufe verwaltbar (Bericht ansehen, Lauf löschen inkl. Screenshots)',
            ],
        ];
    }

    private function getLastRunSummaryHTML(int $ref_id): string
    {
        global $DIC;

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            if ($obj_id <= 0) {
                return '';
            }
            $db  = $DIC->database();
            // Hängengebliebene "running"-Läufe (Tab geschlossen) nicht als letzten Lauf anzeigen
            $res = $db->queryF(
                'SELECT started_at, status, questions_checked, error_count, warning_count'
                . ' FROM exampreflight_runs WHERE test_obj_id = %s AND status <> %s ORDER BY run_id DESC',
                ['integer', 'text'],
                [$obj_id, 'running']
            );
            if ($row = $db->fetchAssoc($res)) {
                $date = date('d.m.Y H:i', (int)$row['started_at']);
                $txt  = sprintf(
                    $this->lng->txt('last_run_summary'),
                    $date,
                    (int)$row['questions_checked'],
                    (int)$row['error_count'],
                    (int)$row['warning_count']
                );
                $cls = ((int)$row['error_count'] > 0) ? 'text-danger'
                    : (((int)$row['warning_count'] > 0) ? 'text-warning' : 'text-success');
                return '<p class="' . $cls . '" style="margin-top:8px;">' . htmlspecialchars($txt) . '</p>';
            }
        } catch (Exception $e) {
            // Tabelle evtl. noch nicht installiert — Block einfach ohne Zusammenfassung zeigen
        }
        return '';
    }
}
