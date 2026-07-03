<?php
/**
 * ExamBattery Aufsichts-GUI + AJAX-Endpoint
 * Part of ExtendedTestSettings Plugin
 *
 * Commands:
 *   cmd=report      POST — Studierenden-seitiger Akku-Report (allow_student_access)
 *   cmd=showStatus  GET  — Aufsichts-Ansicht: letzte Gerätestände pro Test (write-only)
 */
class ilExamBatteryViewGUI
{
    private const TABLE_SETTINGS = 'exambattery_settings';
    private const TABLE_STATUS   = 'exambattery_status';

    private const DEFAULT_WARN_PCT = 20;
    private const DEFAULT_CRIT_PCT = 10;

    // Meldungen älter als 10 Minuten gelten als veraltet (Gerät evtl. aus / Teilnehmer fertig)
    private const STALE_SECONDS = 600;

    // Auto-Refresh-Intervall der Aufsichts-Ansicht
    private const REFRESH_SECONDS = 30;

    private ilModuleLanguage $lng;

    public function __construct()
    {
        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once __DIR__ . '/../../classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    // ─── Dispatch ───────────────────────────────────────────────────────────────

    public function executeCommand(): void
    {
        $cmd = strtolower((string)($_REQUEST['cmd'] ?? ''));

        if ($cmd === 'report') {
            $this->handleReport();
            return;
        }

        global $rbacsystem;
        $ref_id = (int)($_GET['ref_id'] ?? 0);

        if ($ref_id <= 0 || !$rbacsystem->checkAccess('write', $ref_id)) {
            global $DIC;
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', $this->lng->txt('access_denied'));
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        $this->showStatus($ref_id);
    }

    // ─── AJAX: Akku-Report ───────────────────────────────────────────────────────

    private function handleReport(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST only']);
            exit;
        }

        global $DIC;
        $user_id = $DIC->user()->getId();
        $ref_id  = (int)($_GET['ref_id'] ?? 0);

        if ($user_id <= 0 || $ref_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid request']);
            exit;
        }

        // Zusätzliche Prüfung: aktive Test-Session oder Write-Recht
        if (!$this->canReport($DIC, $ref_id, $user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'no active test session']);
            exit;
        }

        $raw  = (string)file_get_contents('php://input');
        $data = json_decode($raw, true);

        // Harte Validierung: level ganzzahlig 0–100, charging exakt 0 oder 1
        $level    = is_array($data) ? ($data['level'] ?? null) : null;
        $charging = is_array($data) ? ($data['charging'] ?? null) : null;

        if (!is_int($level) || $level < 0 || $level > 100
            || ($charging !== 0 && $charging !== 1)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid payload']);
            exit;
        }

        $obj_id = (int)ilObject::_lookupObjectId($ref_id);
        $db     = $DIC->database();

        try {
            // Upsert — es zählt nur der letzte Stand pro (Test, Nutzer)
            $db->replace(
                self::TABLE_STATUS,
                [
                    'test_obj_id' => ['integer', $obj_id],
                    'user_id'     => ['integer', $user_id],
                ],
                [
                    'level_pct'   => ['integer', $level],
                    'charging'    => ['integer', $charging],
                    'ip_addr'     => ['text',    $this->getClientIp()],
                    'test_ref_id' => ['integer', $ref_id],
                    'tstamp'      => ['integer', time()],
                ]
            );
        } catch (Exception $e) {
            error_log('[ExamBattery] handleReport Fehler: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'db error']);
            exit;
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    private function canReport($DIC, int $ref_id, int $user_id): bool
    {
        global $rbacsystem;

        // Admin/Kursleiter darf immer
        if ($rbacsystem->checkAccess('write', $ref_id)) {
            return true;
        }

        // Studierende müssen eine aktive Session für diesen Test haben
        try {
            $db     = $DIC->database();
            $obj_id = (int)ilObject::_lookupObjectId($ref_id);
            $res    = $db->queryF("SELECT test_id FROM tst_tests WHERE obj_fi = %s", ['integer'], [$obj_id]);
            $row    = $db->fetchAssoc($res);
            if (!$row) {
                return false;
            }
            $test_id = (int)$row['test_id'];
            $res     = $db->queryF(
                "SELECT active_id FROM tst_active WHERE test_fi = %s AND user_fi = %s",
                ['integer', 'integer'],
                [$test_id, $user_id]
            );
            return (bool)$db->fetchAssoc($res);
        } catch (Exception $e) {
            return false;
        }
    }

    // ─── Aufsichts-Ansicht ───────────────────────────────────────────────────────

    private function showStatus(int $ref_id): void
    {
        global $DIC;
        $db  = $DIC->database();
        $tpl = $DIC->ui()->mainTemplate();

        $this->setupNavigation($ref_id);

        $obj_id   = (int)ilObject::_lookupObjectId($ref_id);
        $settings = $this->getTestSettings($obj_id);
        $warn     = (int)$settings['warn_pct'];
        $crit     = (int)$settings['crit_pct'];

        $rows = [];
        try {
            // Zentrales Geräte-Inventar des Plugins (etest_hosts, seit Plugin 2.0.1) —
            // nur lesend, Guard falls das Plugin noch nicht aktualisiert ist
            $hasHosts = $db->tableExists('etest_hosts');
            $hostCols = $hasHosts
                ? 'h.ili_nr, h.koffer, h.geraet_nr,'
                : "'' AS ili_nr, '' AS koffer, '' AS geraet_nr,";
            $hostJoin = $hasHosts
                ? 'LEFT JOIN etest_hosts h ON h.ip_addr = s.ip_addr'
                : '';

            // Aufsteigend nach Akkustand — kritische Geräte oben
            $result = $db->queryF(
                "SELECT s.user_id, s.level_pct, s.charging, s.ip_addr, s.tstamp,
                        $hostCols
                        u.login, u.firstname, u.lastname
                 FROM " . self::TABLE_STATUS . " s
                 LEFT JOIN usr_data u ON u.usr_id = s.user_id
                 $hostJoin
                 WHERE s.test_obj_id = %s
                 ORDER BY s.level_pct ASC, s.tstamp ASC",
                ['integer'],
                [$obj_id]
            );
            while ($row = $db->fetchAssoc($result)) {
                $rows[] = $row;
            }
        } catch (Exception $e) {
            error_log('[ExamBattery] showStatus Fehler: ' . $e->getMessage());
        }

        // KS-Komponenten: Panel um die Tabelle, MessageBox für den Leer-Zustand
        $f = $DIC->ui()->factory();
        $r = $DIC->ui()->renderer();

        if (empty($rows)) {
            $content = $r->render($f->messageBox()->info($this->lng->txt('no_data')));
        } else {
            $panel = $f->panel()->standard(
                sprintf($this->lng->txt('devices_reported'), count($rows)),
                $f->legacy($this->renderStatusTable($rows, $warn, $crit))
            );
            $content = $r->render($panel);
        }

        $tpl->setContent($content . $this->refreshScript());
        // Proxy druckt das Template nicht selbst — ohne printToStdout bleibt die Seite weiß.
        $tpl->printToStdout();
    }

    private function setupNavigation(int $ref_id): void
    {
        global $DIC;
        try {
            $obj_id     = (int)ilObject::_lookupObjectId($ref_id);
            $test_title = ilObject::_lookupTitle($obj_id);
            $tpl        = $DIC->ui()->mainTemplate();

            $tabs = $DIC->offsetExists('ilTabs') ? $DIC['ilTabs'] : null;
            if ($tabs) {
                $tabs->setBackTarget(
                    '← ' . $test_title,
                    'ilias.php?ref_id=' . $ref_id . '&baseClass=ilRepositoryGUI'
                );
            }

            // Brotkrumen-Pfad zum Test (wie auf regulären Repository-Seiten)
            if ($DIC->offsetExists('ilLocator')) {
                $DIC['ilLocator']->addRepositoryItems($ref_id);
                $tpl->setLocator();
            }

            $tpl->setTitleIcon(ilObject::_getIcon($obj_id, 'big', 'tst'));
            $tpl->setTitle(sprintf($this->lng->txt('status_title'), $test_title));
            $tpl->setDescription($this->lng->txt('description'));
        } catch (Exception $e) {
            // Navigation-Setup ist nicht kritisch
        }
    }

    private function renderStatusTable(array $rows, int $warn, int $crit): string
    {
        $now = time();

        $html  = '<p style="font-size:13px;color:#555;margin:0 0 12px;">'
               . sprintf($this->lng->txt('legend'), $warn, $crit)
               . ' &nbsp;|&nbsp; ' . $this->lng->txt('refresh_hint')
               . '<br>' . $this->lng->txt('stale_hint')
               . '</p>';

        $html .= '<table class="table table-striped table-condensed table-hover" style="font-size:13px;">';
        $html .= '<thead><tr>'
               . '<th>' . $this->lng->txt('th_user') . '</th>'
               . '<th>' . $this->lng->txt('th_level') . '</th>'
               . '<th>' . $this->lng->txt('th_charging') . '</th>'
               . '<th>' . $this->lng->txt('th_device') . '</th>'
               . '<th>' . $this->lng->txt('th_ip') . '</th>'
               . '<th>' . $this->lng->txt('th_last') . '</th>'
               . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $level    = (int)$row['level_pct'];
            $charging = (bool)$row['charging'];
            $tstamp   = (int)$row['tstamp'];
            $stale    = ($now - $tstamp) > self::STALE_SECONDS;

            $userName = trim(($row['lastname'] ?? '') . ', ' . ($row['firstname'] ?? ''));
            $userName = ($userName !== ',')
                ? $userName . ' (' . ($row['login'] ?? '') . ')'
                : ($row['login'] ?? ('ID ' . $row['user_id']));

            $rowStyle = $stale ? ' style="opacity:0.45;"' : '';

            // Geräte-Zelle aus dem Plugin-Inventar (Darstellung wie im examlogger-Protokoll)
            $koffer   = $row['koffer']    ?? '';
            $geraetNr = $row['geraet_nr'] ?? '';
            $iliNr    = $row['ili_nr']    ?? '';
            if ($koffer !== '' && $geraetNr !== '') {
                $tooltip    = htmlspecialchars(trim($iliNr . '  ' . ($row['ip_addr'] ?? '')));
                $deviceCell = '<td title="' . $tooltip . '">'
                            . htmlspecialchars($koffer) . ' &middot; ' . htmlspecialchars($geraetNr)
                            . '</td>';
            } elseif ($iliNr !== '') {
                $deviceCell = '<td title="' . htmlspecialchars($row['ip_addr'] ?? '') . '">' . htmlspecialchars($iliNr) . '</td>';
            } else {
                $deviceCell = '<td class="text-muted">—</td>';
            }

            $html .= '<tr' . $rowStyle . '>'
                   . '<td>' . htmlspecialchars($userName) . '</td>'
                   . '<td>' . $this->levelBar($level, $warn, $crit) . '</td>'
                   . '<td>' . ($charging
                        ? '<span class="text-success">⚡ ' . $this->lng->txt('charging_yes') . '</span>'
                        : $this->lng->txt('charging_no')) . '</td>'
                   . $deviceCell
                   . '<td>' . htmlspecialchars($row['ip_addr'] ?? '') . '</td>'
                   . '<td>' . date('d.m.Y H:i:s', $tstamp) . '</td>'
                   . '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function levelBar(int $level, int $warn, int $crit): string
    {
        if ($level <= $crit) {
            $col = '#e53935';
        } elseif ($level <= $warn) {
            $col = '#f9a825';
        } else {
            $col = '#43a047';
        }
        return '<div style="display:flex;align-items:center;gap:8px;min-width:160px;">'
             . '<div style="flex:1;background:#eceff1;border-radius:4px;height:14px;overflow:hidden;">'
             . '<div style="width:' . $level . '%;height:100%;background:' . $col . ';"></div>'
             . '</div>'
             . '<strong style="min-width:44px;text-align:right;color:' . $col . ';">' . $level . '&nbsp;%</strong>'
             . '</div>';
    }

    private function refreshScript(): string
    {
        // Auto-Refresh — die Aufsicht lässt die Seite offen liegen
        return '<script>setTimeout(function () { window.location.reload(); }, '
            . (self::REFRESH_SECONDS * 1000) . ');</script>';
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function getTestSettings(int $obj_id): array
    {
        global $DIC;
        $db       = $DIC->database();
        $defaults = ['warn_pct' => self::DEFAULT_WARN_PCT, 'crit_pct' => self::DEFAULT_CRIT_PCT];
        try {
            $res = $db->queryF(
                'SELECT warn_pct, crit_pct FROM ' . self::TABLE_SETTINGS . ' WHERE test_id = %s',
                ['integer'],
                [$obj_id]
            );
            if ($row = $db->fetchAssoc($res)) {
                return array_merge($defaults, $row);
            }
        } catch (Exception $e) { /* ignore */ }
        return $defaults;
    }

    private function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        return trim(explode(',', $ip)[0]);
    }
}
