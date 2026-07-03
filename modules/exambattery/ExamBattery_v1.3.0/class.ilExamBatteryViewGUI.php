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

        // Harte Validierung: level ganzzahlig 0–100, charging exakt 0 oder 1,
        // remaining (geschätzte Restzeit in Minuten) ganzzahlig -1–6000 (-1 = unbekannt)
        $level     = is_array($data) ? ($data['level'] ?? null) : null;
        $charging  = is_array($data) ? ($data['charging'] ?? null) : null;
        $remaining = is_array($data) ? ($data['remaining'] ?? -1) : -1;

        if (!is_int($level) || $level < 0 || $level > 100
            || ($charging !== 0 && $charging !== 1)
            || !is_int($remaining) || $remaining < -1 || $remaining > 6000) {
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
                    'level_pct'       => ['integer', $level],
                    'charging'        => ['integer', $charging],
                    'discharging_min' => ['integer', $remaining],
                    'ip_addr'         => ['text',    $this->getClientIp()],
                    'test_ref_id'     => ['integer', $ref_id],
                    'tstamp'          => ['integer', time()],
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

        // Sortierung über Spaltenkopf-Links (Whitelist), Default: Akkustand aufsteigend
        $sort = strtolower((string)($_GET['sort'] ?? 'level'));
        $dir  = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

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

            switch ($sort) {
                case 'user':
                    $order = "u.lastname $dir, u.firstname $dir, u.login $dir";
                    break;
                case 'charging':
                    $order = "s.charging $dir, s.level_pct ASC";
                    break;
                case 'device':
                    $order = $hasHosts ? "h.koffer $dir, h.geraet_nr $dir" : "s.ip_addr $dir";
                    break;
                case 'ip':
                    $order = "s.ip_addr $dir";
                    break;
                case 'last':
                    $order = "s.tstamp $dir";
                    break;
                case 'remaining':
                    // Unbekannte Restzeit (-1) immer ans Ende
                    $order = "(s.discharging_min < 0) ASC, s.discharging_min $dir";
                    break;
                default:
                    $sort  = 'level';
                    $order = "s.level_pct $dir, s.tstamp ASC";
            }

            $result = $db->queryF(
                "SELECT s.user_id, s.level_pct, s.charging, s.discharging_min, s.ip_addr, s.tstamp,
                        $hostCols
                        u.login, u.firstname, u.lastname
                 FROM " . self::TABLE_STATUS . " s
                 LEFT JOIN usr_data u ON u.usr_id = s.user_id
                 $hostJoin
                 WHERE s.test_obj_id = %s
                 ORDER BY $order",
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
                $f->legacy($this->renderStatusTable($rows, $warn, $crit, $sort, $dir))
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

    private function renderStatusTable(array $rows, int $warn, int $crit, string $sort, string $dir): string
    {
        $now = time();

        $html  = '<p style="font-size:13px;color:#555;margin:0 0 12px;">'
               . sprintf($this->lng->txt('legend'), $warn, $crit)
               . ' &nbsp;|&nbsp; ' . $this->lng->txt('refresh_hint')
               . '<br>' . $this->lng->txt('stale_hint')
               . ' ' . $this->lng->txt('remaining_hint')
               . '</p>';

        $html .= '<table class="table table-striped table-condensed table-hover" style="font-size:13px;">';
        $html .= '<thead><tr>'
               . '<th>' . $this->sortLink('user', $this->lng->txt('th_user'), $sort, $dir) . '</th>'
               . '<th>' . $this->sortLink('level', $this->lng->txt('th_level'), $sort, $dir) . '</th>'
               . '<th>' . $this->sortLink('remaining', $this->lng->txt('th_remaining'), $sort, $dir) . '</th>'
               . '<th>' . $this->sortLink('charging', $this->lng->txt('th_charging'), $sort, $dir) . '</th>'
               . '<th>' . $this->sortLink('device', $this->lng->txt('th_device'), $sort, $dir) . '</th>'
               . '<th>' . $this->sortLink('ip', $this->lng->txt('th_ip'), $sort, $dir) . '</th>'
               . '<th>' . $this->sortLink('last', $this->lng->txt('th_last'), $sort, $dir) . '</th>'
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
                   . '<td>' . $this->formatRemaining((int)($row['discharging_min'] ?? -1), $charging) . '</td>'
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

    private function sortLink(string $key, string $label, string $sort, string $dir): string
    {
        $params = $_GET;
        $params['sort'] = $key;
        $params['dir']  = ($sort === $key && $dir === 'ASC') ? 'desc' : 'asc';
        $url   = 'ilias.php?' . http_build_query($params);
        $arrow = ($sort === $key) ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '';
        return '<a href="' . htmlspecialchars($url) . '" style="white-space:nowrap;color:inherit;">'
             . htmlspecialchars($label) . $arrow . '</a>';
    }

    /**
     * Geschätzte Restzeit (Minuten aus der Battery-API); -1 = unbekannt.
     * Beim Laden zeigt die API keine Entlade-Restzeit — dann "—".
     */
    private function formatRemaining(int $min, bool $charging): string
    {
        if ($min < 0 || $charging) {
            return '<span class="text-muted">—</span>';
        }
        $txt = $min < 60
            ? '~' . $min . ' min'
            : '~' . intdiv($min, 60) . ' h ' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT) . ' min';
        if ($min <= 30) {
            return '<strong style="color:#e53935;">' . $txt . '</strong>';
        }
        return $txt;
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
