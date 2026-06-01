<?php
/**
 * ExamNetQos Admin GUI + AJAX-Endpoint
 *
 * Commands:
 *   cmd=report        POST — Studierenden-seitiger Batch-Upload von Messwerten (allow_student_access)
 *   cmd=showChart     GET  — Admin-Zeitstrahl-Ansicht (write-only)
 *   cmd=exportCsv     GET  — CSV-Export aller gefilterten Ereignisse (write-only)
 */
class ilExamNetQosViewGUI
{
    private const TABLE_EVENTS   = 'examnetqos_events';
    private const TABLE_SETTINGS = 'examnetqos_settings';

    private string $chartCmd  = 'showChart';
    private string $exportCmd = 'exportCsv';

    public function setChartCmd(string $cmd): void  { $this->chartCmd  = $cmd; }
    public function setExportCmd(string $cmd): void { $this->exportCmd = $cmd; }

    private const EVENT_LABELS = [
        'measurement' => 'Messung',
        'degraded'    => 'Erhöht',
        'critical'    => 'Kritisch',
        'recovered'   => 'Wiederhergestellt',
        'reconnect'   => 'Reconnect (ConnGuard)',
    ];

    private const EVENT_COLORS = [
        'measurement' => '#888',
        'degraded'    => '#f9a825',
        'critical'    => '#e53935',
        'recovered'   => '#43a047',
        'reconnect'   => '#1976d2',
    ];

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

        if ($ref_id > 0 && !$rbacsystem->checkAccess('write', $ref_id)) {
            global $DIC;
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', 'Zugriff verweigert.');
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        if ($cmd === 'exportcsv') {
            $this->exportCsv();
        } else {
            $this->showChart();
        }
    }

    // ─── AJAX: Messwert-Empfang ──────────────────────────────────────────────────

    private function handleReport(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');

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

        if (!is_array($data) || empty($data['events']) || !is_array($data['events'])) {
            http_response_code(400);
            echo json_encode(['error' => 'missing events']);
            exit;
        }

        $obj_id      = (int)ilObject::_lookupObjectId($ref_id);
        $db          = $DIC->database();
        $session_key = substr(preg_replace('/[^a-z0-9]/', '', strtolower((string)($data['sessionKey'] ?? ''))), 0, 32);
        $ip          = $this->getClientIp();
        $now         = time();

        $valid_types = ['measurement', 'degraded', 'critical', 'recovered', 'reconnect'];
        $saved       = 0;
        $batch       = array_slice($data['events'], 0, 20);  // max 20 pro Request

        foreach ($batch as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $type = (string)($ev['event_type'] ?? '');
            if (!in_array($type, $valid_types, true)) {
                continue;
            }

            // Client-Timestamp innerhalb ±1h akzeptieren, sonst server-seitig ersetzen
            $ts = isset($ev['ts']) ? (int)$ev['ts'] : $now;
            $ts = max($now - 3600, min($now + 60, $ts));

            $rtt_ms   = isset($ev['rtt_ms'])   ? max(0, min(99999, (int)$ev['rtt_ms']))   : 0;
            $loss_pct = isset($ev['loss_pct'])  ? max(-1, min(100,  (int)$ev['loss_pct'])) : -1;
            $conn     = isset($ev['conn_type']) ? substr(preg_replace('/[^a-z0-9-]/', '', (string)$ev['conn_type']), 0, 16) : '';

            $db->insert(self::TABLE_EVENTS, [
                'id'          => ['integer', $db->nextId(self::TABLE_EVENTS)],
                'test_obj_id' => ['integer', $obj_id],
                'test_ref_id' => ['integer', $ref_id],
                'user_id'     => ['integer', $user_id],
                'session_key' => ['text',    $session_key],
                'event_type'  => ['text',    $type],
                'rtt_ms'      => ['integer', $rtt_ms],
                'loss_pct'    => ['integer', $loss_pct],
                'conn_type'   => ['text',    $conn],
                'ip_addr'     => ['text',    $ip],
                'tstamp'      => ['integer', $ts],
            ]);
            $saved++;
        }

        echo json_encode(['ok' => true, 'saved' => $saved]);
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

    // ─── Admin: Zeitstrahl-Ansicht ───────────────────────────────────────────────

    public function showChart(): void
    {
        global $DIC;

        $filter_ref        = (int)($_GET['filter_ref_id']    ?? 0);
        $filter_user_id    = (int)($_GET['filter_user_id']   ?? 0);
        $filter_from       = $this->sanitizeDate((string)($_GET['filter_from']       ?? ''));
        $filter_to         = $this->sanitizeDate((string)($_GET['filter_to']         ?? ''));
        $filter_session    = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_GET['filter_session_key'] ?? '')));

        [$rows, $testList, $userList] = $this->fetchEvents(
            $filter_ref, $filter_user_id, $filter_from, $filter_to, $filter_session, 3000
        );

        $DIC['tpl']->setContent(
            $this->renderView($rows, $testList, $userList,
                $filter_ref, $filter_user_id, $filter_from, $filter_to, $filter_session)
        );
    }

    private function fetchEvents(
        int $filter_ref, int $filter_user_id,
        string $filter_from, string $filter_to,
        string $filter_session, int $limit
    ): array {
        global $DIC;
        $db = $DIC->database();

        $where  = [];
        $types  = [];
        $values = [];

        if ($filter_ref > 0) {
            $where[] = 'e.test_ref_id = %s'; $types[] = 'integer'; $values[] = $filter_ref;
        }
        if ($filter_user_id > 0) {
            $where[] = 'e.user_id = %s'; $types[] = 'integer'; $values[] = $filter_user_id;
        }
        if ($filter_from !== '') {
            $where[] = 'e.tstamp >= %s'; $types[] = 'integer'; $values[] = strtotime($filter_from . ' 00:00:00');
        }
        if ($filter_to !== '') {
            $where[] = 'e.tstamp <= %s'; $types[] = 'integer'; $values[] = strtotime($filter_to . ' 23:59:59');
        }
        if ($filter_session !== '') {
            $where[] = 'e.session_key = %s'; $types[] = 'text'; $values[] = $filter_session;
        }

        $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT e.id, e.test_ref_id, e.test_obj_id, e.user_id, e.session_key,
                       e.event_type, e.rtt_ms, e.loss_pct, e.conn_type, e.ip_addr, e.tstamp,
                       u.login, u.firstname, u.lastname, o.title AS test_title
                FROM " . self::TABLE_EVENTS . " e
                LEFT JOIN usr_data u ON u.usr_id = e.user_id
                LEFT JOIN object_data o ON o.obj_id = e.test_obj_id
                $whereStr
                ORDER BY e.tstamp ASC, e.id ASC
                LIMIT $limit";

        $rows = [];
        $result = $types ? $db->queryF($sql, $types, $values) : $db->query($sql);
        while ($row = $db->fetchAssoc($result)) {
            $rows[] = $row;
        }

        // Test-Liste für Dropdown
        $testList  = [];
        $testRes   = $db->query(
            "SELECT DISTINCT e.test_ref_id, o.title
             FROM " . self::TABLE_EVENTS . " e
             LEFT JOIN object_data o ON o.obj_id = e.test_obj_id
             ORDER BY o.title, e.test_ref_id"
        );
        while ($r = $db->fetchAssoc($testRes)) {
            $testList[(int)$r['test_ref_id']] = $r['title'] ?? ('ref_id ' . $r['test_ref_id']);
        }

        // Nutzer-Liste (gefiltert nach Test)
        $userList = [];
        if ($filter_ref > 0) {
            $userRes = $db->queryF(
                "SELECT DISTINCT e.user_id, u.login, u.firstname, u.lastname
                 FROM " . self::TABLE_EVENTS . " e
                 LEFT JOIN usr_data u ON u.usr_id = e.user_id
                 WHERE e.test_ref_id = %s
                 ORDER BY u.lastname, u.firstname",
                ['integer'], [$filter_ref]
            );
        } else {
            $userRes = $db->query(
                "SELECT DISTINCT e.user_id, u.login, u.firstname, u.lastname
                 FROM " . self::TABLE_EVENTS . " e
                 LEFT JOIN usr_data u ON u.usr_id = e.user_id
                 ORDER BY u.lastname, u.firstname"
            );
        }
        while ($r = $db->fetchAssoc($userRes)) {
            $uid   = (int)$r['user_id'];
            $label = trim(($r['lastname'] ?? '') . ', ' . ($r['firstname'] ?? ''));
            $label = ($label !== ', ')
                ? $label . ' (' . ($r['login'] ?? '') . ')'
                : ($r['login'] ?? 'ID ' . $uid);
            $userList[$uid] = $label;
        }

        return [$rows, $testList, $userList];
    }

    private function renderView(
        array $rows, array $testList, array $userList,
        int $filter_ref, int $filter_user_id,
        string $filter_from, string $filter_to, string $filter_session
    ): string {
        $base = $this->buildBaseUrl();

        $html = $this->renderFilterForm($testList, $userList,
            $filter_ref, $filter_user_id, $filter_from, $filter_to, $filter_session);

        if (empty($rows)) {
            $html .= '<div class="alert alert-info">Keine Messwerte für den gewählten Filter.</div>';
            return $html;
        }

        // Ereignisse nach Session gruppieren
        $sessions = $this->groupBySessions($rows);

        $exportQs = http_build_query(array_filter([
            'filter_ref_id'    => $filter_ref > 0 ? $filter_ref : '',
            'filter_user_id'   => $filter_user_id > 0 ? $filter_user_id : '',
            'filter_from'      => $filter_from,
            'filter_to'        => $filter_to,
            'filter_session_key' => $filter_session,
            'cmd'              => $this->exportCmd,
        ]));
        $html .= '<p style="margin:10px 0 15px;">';
        $html .= '<a href="' . htmlspecialchars($base . '&' . $exportQs) . '" class="btn btn-default btn-sm">Als CSV exportieren</a>';
        $html .= '&nbsp;&nbsp;<strong>' . count($rows) . '</strong> Ereignisse in <strong>' . count($sessions) . '</strong> Sessions';
        if (count($rows) >= 3000) {
            $html .= ' <span class="text-muted">(Limit 3000 — Filter eingrenzen)</span>';
        }
        $html .= '</p>';

        // Detail-Ansicht einer einzelnen Session
        if ($filter_session !== '' && count($sessions) === 1) {
            $session = reset($sessions);
            $html   .= $this->renderSessionDetail($session, $base);
            return $html;
        }

        // Übersichts-Tabelle aller Sessions
        $html .= $this->renderSessionsTable($sessions, $base, $filter_ref, $filter_user_id, $filter_from, $filter_to);
        return $html;
    }

    /**
     * Gruppiert Event-Rows nach (user_id, session_key, test_ref_id).
     */
    private function groupBySessions(array $rows): array
    {
        $sessions = [];
        foreach ($rows as $row) {
            $key = $row['user_id'] . '_' . $row['session_key'] . '_' . $row['test_ref_id'];
            if (!isset($sessions[$key])) {
                $sessions[$key] = [
                    'user_id'     => (int)$row['user_id'],
                    'session_key' => $row['session_key'],
                    'test_ref_id' => (int)$row['test_ref_id'],
                    'login'       => $row['login'] ?? ('ID ' . $row['user_id']),
                    'firstname'   => $row['firstname'] ?? '',
                    'lastname'    => $row['lastname']  ?? '',
                    'test_title'  => $row['test_title'] ?? ('ref_id ' . $row['test_ref_id']),
                    'ip_addr'     => $row['ip_addr'] ?? '',
                    'events'      => [],
                ];
            }
            $sessions[$key]['events'][] = $row;
        }
        // Neueste Session zuerst (nach Start-Timestamp)
        uasort($sessions, function ($a, $b) {
            $ta = (int)($a['events'][0]['tstamp'] ?? 0);
            $tb = (int)($b['events'][0]['tstamp'] ?? 0);
            return $tb - $ta;
        });
        return $sessions;
    }

    private function renderSessionsTable(
        array $sessions, string $base,
        int $filter_ref, int $filter_user_id, string $filter_from, string $filter_to
    ): string {
        $html = '<table class="table table-striped table-condensed table-hover" style="font-size:13px;">';
        $html .= '<thead><tr>'
               . '<th>Nutzer</th>'
               . '<th>Test</th>'
               . '<th>Start</th>'
               . '<th>Dauer</th>'
               . '<th>Ø RTT</th>'
               . '<th>Max RTT</th>'
               . '<th>Ereignisse</th>'
               . '<th>Sparkline</th>'
               . '<th></th>'
               . '</tr></thead><tbody>';

        foreach ($sessions as $session) {
            $events  = $session['events'];
            $measurements = array_filter($events, fn($e) => $e['event_type'] === 'measurement');

            $start_ts = (int)($events[0]['tstamp'] ?? 0);
            $end_ts   = (int)(end($events)['tstamp'] ?? $start_ts);
            $duration = $end_ts - $start_ts;

            $rtts     = array_filter(array_column(array_values($measurements), 'rtt_ms'), fn($r) => $r > 0);
            $avg_rtt  = $rtts ? (int)round(array_sum($rtts) / count($rtts)) : 0;
            $max_rtt  = $rtts ? max($rtts) : 0;

            $n_meas = count($measurements);
            $n_deg  = count(array_filter($events, fn($e) => $e['event_type'] === 'degraded'));
            $n_crit = count(array_filter($events, fn($e) => $e['event_type'] === 'critical'));
            $n_rec  = count(array_filter($events, fn($e) => $e['event_type'] === 'recovered'));

            $rtt_warn = 500; $rtt_crit = 2000;  // Defaults für Sparkline
            $eventBadges = '<span class="badge" style="background:#888;">' . $n_meas . ' Mess.</span> ';
            if ($n_deg)  $eventBadges .= '<span class="badge" style="background:#f9a825;">' . $n_deg . ' deg</span> ';
            if ($n_crit) $eventBadges .= '<span class="badge" style="background:#e53935;">' . $n_crit . ' crit</span> ';
            if ($n_rec)  $eventBadges .= '<span class="badge" style="background:#43a047;">' . $n_rec . ' rec</span>';

            $userName = trim($session['lastname'] . ', ' . $session['firstname']);
            if ($userName === ', ') {
                $userName = $session['login'];
            } else {
                $userName .= ' (' . $session['login'] . ')';
            }

            $detailQs = http_build_query(array_filter([
                'cmd'               => $this->chartCmd,
                'filter_ref_id'     => $filter_ref > 0 ? $filter_ref : $session['test_ref_id'],
                'filter_user_id'    => $filter_user_id > 0 ? $filter_user_id : '',
                'filter_from'       => $filter_from,
                'filter_to'         => $filter_to,
                'filter_session_key'=> $session['session_key'],
            ]));
            $detailUrl = $base . '&' . $detailQs;

            $html .= '<tr>'
                   . '<td>' . htmlspecialchars($userName) . '</td>'
                   . '<td style="font-size:12px;color:#555;">' . htmlspecialchars($session['test_title']) . '</td>'
                   . '<td>' . date('d.m.Y H:i:s', $start_ts) . '</td>'
                   . '<td>' . $this->formatDuration($duration) . '</td>'
                   . '<td>' . $this->rttBadge($avg_rtt, $rtt_warn, $rtt_crit) . '</td>'
                   . '<td>' . $this->rttBadge($max_rtt, $rtt_warn, $rtt_crit) . '</td>'
                   . '<td>' . $eventBadges . '</td>'
                   . '<td>' . $this->renderSparkline(array_values($measurements), $rtt_warn, $rtt_crit) . '</td>'
                   . '<td><a href="' . htmlspecialchars($detailUrl) . '" class="btn btn-xs btn-default">Detail</a></td>'
                   . '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function renderSessionDetail(array $session, string $base): string
    {
        global $DIC;
        $db     = $DIC->database();
        $events = $session['events'];

        // Test-Settings für Schwellwerte laden
        $settings = $this->getTestSettings($session['test_ref_id']);
        $rtt_warn = (int)($settings['rtt_warn_ms'] ?? 500);
        $rtt_crit = (int)($settings['rtt_crit_ms'] ?? 2000);

        $start_ts = (int)($events[0]['tstamp'] ?? 0);
        $end_ts   = (int)(end($events)['tstamp'] ?? $start_ts);

        $userName = trim($session['lastname'] . ', ' . $session['firstname']);
        if ($userName === ', ') $userName = $session['login'];

        $html  = '<div class="panel panel-default">';
        $html .= '<div class="panel-heading">';
        $html .= '<h4 class="panel-title" style="margin:0;">';
        $html .= htmlspecialchars($userName) . ' &mdash; ' . htmlspecialchars($session['test_title']);
        $html .= '</h4>';
        $html .= '</div>';
        $html .= '<div class="panel-body" style="font-size:13px;color:#555;">';
        $html .= 'Session: <code>' . htmlspecialchars($session['session_key']) . '</code>';
        $html .= ' &nbsp;|&nbsp; IP: ' . htmlspecialchars($session['ip_addr']);
        $html .= ' &nbsp;|&nbsp; ' . date('d.m.Y H:i:s', $start_ts) . ' – ' . date('H:i:s', $end_ts);
        $html .= ' &nbsp;(' . $this->formatDuration($end_ts - $start_ts) . ')';
        $html .= ' &nbsp;|&nbsp; Schwellwerte: warn=' . $rtt_warn . 'ms, crit=' . $rtt_crit . 'ms';
        $html .= '</div>';
        $html .= '</div>';

        // Vollständiges SVG
        $html .= $this->renderDetailChart($events, $rtt_warn, $rtt_crit);

        // Ereignis-Tabelle
        $html .= '<h5 style="margin-top:24px;">Rohdaten</h5>';
        $html .= '<table class="table table-condensed" style="font-size:12px;">';
        $html .= '<thead><tr><th>Zeit</th><th>Ereignis</th><th>RTT (ms)</th><th>Verlust (%)</th><th>Verbindungstyp</th></tr></thead><tbody>';
        foreach ($events as $ev) {
            $label = self::EVENT_LABELS[$ev['event_type']] ?? $ev['event_type'];
            $color = self::EVENT_COLORS[$ev['event_type']] ?? '#888';
            $rtt   = (int)$ev['rtt_ms'] > 0 ? $ev['rtt_ms'] . ' ms' : '<span style="color:#e53935;">Timeout</span>';
            $loss  = (int)$ev['loss_pct'] >= 0 ? $ev['loss_pct'] . '%' : '—';
            $conn  = $ev['conn_type'] !== '' ? htmlspecialchars($ev['conn_type']) : '—';
            $html .= '<tr>'
                   . '<td>' . date('H:i:s', (int)$ev['tstamp']) . '</td>'
                   . '<td><span style="color:' . $color . ';font-weight:bold;">' . htmlspecialchars($label) . '</span></td>'
                   . '<td>' . $rtt . '</td>'
                   . '<td>' . $loss . '</td>'
                   . '<td>' . $conn . '</td>'
                   . '</tr>';
        }
        $html .= '</tbody></table>';

        // Zurück-Link
        $backUrl = preg_replace('/&filter_session_key=[^&]*/', '', $_SERVER['REQUEST_URI'] ?? $base);
        $html .= '<p><a href="' . htmlspecialchars($backUrl) . '" class="btn btn-default btn-sm">&larr; Zurück zur Übersicht</a></p>';

        return $html;
    }

    // ─── SVG Charts ──────────────────────────────────────────────────────────────

    private function renderSparkline(array $measurements, int $rtt_warn, int $rtt_crit): string
    {
        $rtts = array_filter(array_column($measurements, 'rtt_ms'), fn($r) => $r > 0);
        if (empty($rtts)) {
            return '<span class="text-muted">—</span>';
        }

        $w = 160; $h = 36;
        $max_rtt = max(max($rtts), $rtt_crit * 1.2, 500);
        $n       = count($measurements);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" style="display:inline-block;vertical-align:middle;">';
        $svg .= '<rect width="' . $w . '" height="' . $h . '" fill="#f9f9f9" rx="2"/>';

        // Threshold Bands
        $y_warn = $h - ($rtt_warn / $max_rtt) * $h;
        $y_crit = $h - ($rtt_crit / $max_rtt) * $h;
        $svg .= '<rect x="0" y="' . $y_warn . '" width="' . $w . '" height="' . ($h - $y_warn) . '" fill="#e8f5e9" opacity="0.6"/>';
        $svg .= '<rect x="0" y="' . $y_crit . '" width="' . $w . '" height="' . ($y_warn - $y_crit) . '" fill="#fff8e1" opacity="0.6"/>';
        $svg .= '<rect x="0" y="0" width="' . $w . '" height="' . $y_crit . '" fill="#ffebee" opacity="0.6"/>';

        // Polyline
        $points = [];
        foreach ($measurements as $i => $m) {
            $rtt = (int)$m['rtt_ms'];
            if ($rtt <= 0) continue;
            $x = round($i / max(1, $n - 1) * $w, 1);
            $y = round($h - min(1.0, $rtt / $max_rtt) * $h, 1);
            $points[] = $x . ',' . $y;
        }
        if (count($points) >= 2) {
            $svg .= '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="#1976d2" stroke-width="1.5"/>';
        }
        $svg .= '</svg>';
        return $svg;
    }

    private function renderDetailChart(array $events, int $rtt_warn, int $rtt_crit): string
    {
        $measurements    = array_values(array_filter($events, fn($e) => $e['event_type'] === 'measurement'));
        $non_measurements = array_values(array_filter($events, fn($e) => $e['event_type'] !== 'measurement'));

        if (empty($measurements)) {
            return '<div class="alert alert-warning">Nur Ereignisse vorhanden, keine Messwerte.</div>';
        }

        $W = 900; $H = 200;
        $ml = 12; $mr = 72; $mt = 12; $mb = 32;
        $pw = $W - $ml - $mr;
        $ph = $H - $mt - $mb;

        $all_ts  = array_column($events, 'tstamp');
        $min_ts  = (int)min($all_ts);
        $max_ts  = (int)max($all_ts);
        $dur     = max(60, $max_ts - $min_ts);

        $valid_rtts = array_filter(array_column($measurements, 'rtt_ms'), fn($r) => $r > 0);
        $max_rtt = $valid_rtts ? max(max($valid_rtts), $rtt_crit * 1.3, 500) : max($rtt_crit * 1.3, 1000);

        $xOf = fn(int $ts): float => $ml + ($ts - $min_ts) / $dur * $pw;
        $yOf = fn(int $rtt): float => $mt + $ph - min(1.0, max(0, $rtt) / $max_rtt) * $ph;

        $y_warn   = $yOf($rtt_warn);
        $y_crit   = $yOf($rtt_crit);
        $y_bottom = $mt + $ph;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 ' . $W . ' ' . $H . '"'
             . ' style="display:block;border:1px solid #ddd;border-radius:4px;background:#fff;margin-bottom:16px;">';

        // Threshold-Bänder
        $svg .= '<rect x="' . $ml . '" y="' . $y_warn . '" width="' . $pw . '" height="' . ($y_bottom - $y_warn) . '" fill="#e8f5e9"/>';
        $svg .= '<rect x="' . $ml . '" y="' . $y_crit . '" width="' . $pw . '" height="' . ($y_warn - $y_crit) . '" fill="#fff8e1"/>';
        $svg .= '<rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . ($y_crit - $mt) . '" fill="#ffebee"/>';

        // Threshold-Linien
        $svg .= '<line x1="' . $ml . '" y1="' . $y_warn . '" x2="' . ($ml + $pw) . '" y2="' . $y_warn . '" stroke="#f9a825" stroke-dasharray="4,4" stroke-width="1"/>';
        $svg .= '<line x1="' . $ml . '" y1="' . $y_crit . '" x2="' . ($ml + $pw) . '" y2="' . $y_crit . '" stroke="#e53935" stroke-dasharray="4,4" stroke-width="1"/>';

        // Y-Labels
        $svg .= '<text x="' . ($ml + $pw + 4) . '" y="' . ($y_warn + 4) . '" font-size="10" fill="#f9a825">' . $rtt_warn . ' ms</text>';
        $svg .= '<text x="' . ($ml + $pw + 4) . '" y="' . ($y_crit + 4) . '" font-size="10" fill="#e53935">' . $rtt_crit . ' ms</text>';
        $svg .= '<text x="' . ($ml + $pw + 4) . '" y="' . ($mt + 10) . '" font-size="10" fill="#999">' . round($max_rtt) . '</text>';

        // X-Zeitachse (alle ~5 Minuten)
        $label_step = max(60, (int)round($dur / 8 / 60) * 60);
        for ($ts = $min_ts; $ts <= $max_ts + 1; $ts += $label_step) {
            $x = $xOf($ts);
            $svg .= '<line x1="' . round($x, 1) . '" y1="' . $y_bottom . '" x2="' . round($x, 1) . '" y2="' . ($y_bottom + 4) . '" stroke="#bbb" stroke-width="1"/>';
            $svg .= '<text x="' . round($x, 1) . '" y="' . ($y_bottom + 16) . '" font-size="9" text-anchor="middle" fill="#666">'
                  . date('H:i', $ts) . '</text>';
        }

        // RTT-Linie als farbige Segmente
        $pts = [];
        foreach ($measurements as $m) {
            $rtt = (int)$m['rtt_ms'];
            if ($rtt > 0) {
                $pts[] = ['x' => $xOf((int)$m['tstamp']), 'y' => $yOf($rtt), 'rtt' => $rtt, 'ts' => (int)$m['tstamp']];
            }
        }
        for ($i = 0; $i + 1 < count($pts); $i++) {
            $a    = $pts[$i]; $b = $pts[$i + 1];
            $avg  = ($a['rtt'] + $b['rtt']) / 2;
            $col  = $avg >= $rtt_crit ? '#e53935' : ($avg >= $rtt_warn ? '#f9a825' : '#43a047');
            $svg .= '<line x1="' . round($a['x'], 1) . '" y1="' . round($a['y'], 1) . '"'
                  . ' x2="' . round($b['x'], 1) . '" y2="' . round($b['y'], 1) . '"'
                  . ' stroke="' . $col . '" stroke-width="2"/>';
        }

        // Messpunkte
        foreach ($pts as $p) {
            $col   = $p['rtt'] >= $rtt_crit ? '#e53935' : ($p['rtt'] >= $rtt_warn ? '#f9a825' : '#43a047');
            $title = date('H:i:s', $p['ts']) . ' | ' . $p['rtt'] . ' ms';
            $svg  .= '<circle cx="' . round($p['x'], 1) . '" cy="' . round($p['y'], 1) . '" r="3" fill="' . $col . '">'
                   . '<title>' . htmlspecialchars($title) . '</title></circle>';
        }

        // Timeout-Messungen (rtt_ms = 0)
        foreach ($measurements as $m) {
            if ((int)$m['rtt_ms'] === 0) {
                $x = $xOf((int)$m['tstamp']);
                $svg .= '<line x1="' . round($x, 1) . '" y1="' . $mt . '" x2="' . round($x, 1) . '" y2="' . $y_bottom . '"'
                      . ' stroke="#e53935" stroke-width="1" stroke-dasharray="2,2" opacity="0.5"/>';
            }
        }

        // Ereignis-Markierungen (non-measurement)
        // Kein Text im SVG — bei dichten Events überlappen Labels. Stattdessen:
        // farbiger Kreis am Linien-Kopf + <title>-Tooltip mit Zeit + Label.
        foreach ($non_measurements as $ev) {
            $x   = round($xOf((int)$ev['tstamp']), 1);
            $col = self::EVENT_COLORS[$ev['event_type']] ?? '#666';
            $lbl = self::EVENT_LABELS[$ev['event_type']] ?? $ev['event_type'];
            $tip = htmlspecialchars(date('H:i:s', (int)$ev['tstamp']) . ' | ' . $lbl);
            $svg .= '<line x1="' . $x . '" y1="' . $mt . '" x2="' . $x . '" y2="' . $y_bottom . '"'
                  . ' stroke="' . $col . '" stroke-width="2" stroke-dasharray="6,3">'
                  . '<title>' . $tip . '</title>'
                  . '</line>';
            $svg .= '<circle cx="' . $x . '" cy="' . ($mt + 5) . '" r="4" fill="' . $col . '">'
                  . '<title>' . $tip . '</title>'
                  . '</circle>';
        }

        $svg .= '</svg>';

        // Legende
        $legend = '<div style="font-size:11px;color:#666;margin-bottom:8px;">';
        $legend .= '<span style="margin-right:12px;"><span style="display:inline-block;width:20px;height:3px;background:#43a047;vertical-align:middle;margin-right:4px;"></span>RTT normal</span>';
        $legend .= '<span style="margin-right:12px;"><span style="display:inline-block;width:20px;height:3px;background:#f9a825;vertical-align:middle;margin-right:4px;"></span>RTT erhöht</span>';
        $legend .= '<span style="margin-right:12px;"><span style="display:inline-block;width:20px;height:3px;background:#e53935;vertical-align:middle;margin-right:4px;"></span>RTT kritisch / Timeout</span>';
        foreach (self::EVENT_COLORS as $type => $col) {
            if ($type === 'measurement') continue;
            $legend .= '<span style="margin-right:12px;"><span style="display:inline-block;width:2px;height:14px;background:' . $col . ';vertical-align:middle;margin-right:4px;"></span>'
                     . htmlspecialchars(self::EVENT_LABELS[$type] ?? $type) . '</span>';
        }
        $legend .= '</div>';

        return $legend . $svg;
    }

    // ─── Filter-Formular ─────────────────────────────────────────────────────────

    private function renderFilterForm(
        array $testList, array $userList,
        int $filter_ref, int $filter_user_id,
        string $filter_from, string $filter_to, string $filter_session
    ): string {
        $action    = $this->buildBaseUrl();
        $hasFilter = $filter_from !== '' || $filter_to !== '' || $filter_user_id > 0
                  || $filter_session !== ''
                  || ($filter_ref > 0);

        $html  = '<form method="get" action="' . htmlspecialchars($action) . '" class="form-inline"'
               . ' style="margin-bottom:10px;padding:12px;background:#f5f5f5;border-radius:4px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
        $html .= '<input type="hidden" name="cmd" value="' . htmlspecialchars($this->chartCmd) . '">';

        foreach ($_GET as $key => $value) {
            if (!in_array($key, ['cmd','filter_ref_id','filter_user_id','filter_from','filter_to','filter_session_key'], true)) {
                $html .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$value) . '">';
            }
        }

        // Test
        if (!empty($testList)) {
            $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">Test:</label>';
            $html .= '<select name="filter_ref_id" class="form-control input-sm" style="max-width:220px;margin-right:8px;">';
            $html .= '<option value="">— Alle —</option>';
            foreach ($testList as $refId => $title) {
                $sel   = ($filter_ref === $refId) ? ' selected' : '';
                $html .= '<option value="' . $refId . '"' . $sel . '>' . htmlspecialchars($title) . '</option>';
            }
            $html .= '</select>';
        }

        // Nutzer
        if (!empty($userList)) {
            $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">Nutzer:</label>';
            $html .= '<select name="filter_user_id" class="form-control input-sm" style="max-width:200px;margin-right:8px;">';
            $html .= '<option value="">— Alle —</option>';
            foreach ($userList as $uid => $label) {
                $sel   = ($filter_user_id === $uid) ? ' selected' : '';
                $html .= '<option value="' . $uid . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
            }
            $html .= '</select>';
        }

        // Datum
        $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">Von:</label>';
        $html .= '<input type="date" name="filter_from" class="form-control input-sm" value="' . htmlspecialchars($filter_from) . '" style="margin-right:8px;">';
        $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">Bis:</label>';
        $html .= '<input type="date" name="filter_to" class="form-control input-sm" value="' . htmlspecialchars($filter_to) . '" style="margin-right:8px;">';

        $html .= '<button type="submit" class="btn btn-default btn-sm">Filtern</button>';
        if ($hasFilter) {
            $resetUrl = $action . '&cmd=showChart';
            $html    .= '&nbsp;<a href="' . htmlspecialchars($resetUrl) . '" class="btn btn-link btn-sm">Zurücksetzen</a>';
        }
        $html .= '</form>';
        return $html;
    }

    // ─── CSV-Export ───────────────────────────────────────────────────────────────

    public function exportCsv(): void
    {
        global $DIC;
        $db = $DIC->database();

        $filter_ref     = (int)($_GET['filter_ref_id']    ?? 0);
        $filter_user_id = (int)($_GET['filter_user_id']   ?? 0);
        $filter_from    = $this->sanitizeDate((string)($_GET['filter_from']       ?? ''));
        $filter_to      = $this->sanitizeDate((string)($_GET['filter_to']         ?? ''));
        $filter_session = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_GET['filter_session_key'] ?? '')));

        $where  = []; $types = []; $values = [];
        if ($filter_ref > 0)     { $where[] = 'e.test_ref_id = %s'; $types[] = 'integer'; $values[] = $filter_ref; }
        if ($filter_user_id > 0) { $where[] = 'e.user_id = %s';     $types[] = 'integer'; $values[] = $filter_user_id; }
        if ($filter_from !== '')  { $where[] = 'e.tstamp >= %s';     $types[] = 'integer'; $values[] = strtotime($filter_from . ' 00:00:00'); }
        if ($filter_to !== '')    { $where[] = 'e.tstamp <= %s';     $types[] = 'integer'; $values[] = strtotime($filter_to . ' 23:59:59'); }
        if ($filter_session !== ''){ $where[] = 'e.session_key = %s'; $types[] = 'text';  $values[] = $filter_session; }

        $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql      = "SELECT e.*, u.login, u.firstname, u.lastname, o.title AS test_title
                     FROM " . self::TABLE_EVENTS . " e
                     LEFT JOIN usr_data u ON u.usr_id = e.user_id
                     LEFT JOIN object_data o ON o.obj_id = e.test_obj_id
                     $whereStr ORDER BY e.tstamp ASC, e.id ASC";

        $result = $types ? $db->queryF($sql, $types, $values) : $db->query($sql);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="examnetqos_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Datum','Uhrzeit','Login','Vorname','Nachname','Test','Ref-ID','Session-Key','Ereignis','RTT (ms)','Verlust (%)','Verbindungstyp','IP-Adresse'], ';');

        while ($row = $db->fetchAssoc($result)) {
            $rtt  = (int)$row['rtt_ms'] > 0  ? (int)$row['rtt_ms']  : 'Timeout';
            $loss = (int)$row['loss_pct'] >= 0 ? (int)$row['loss_pct'] : '';
            fputcsv($out, [
                date('d.m.Y', (int)$row['tstamp']),
                date('H:i:s', (int)$row['tstamp']),
                $row['login']      ?? '',
                $row['firstname']  ?? '',
                $row['lastname']   ?? '',
                $row['test_title'] ?? '',
                $row['test_ref_id'],
                $row['session_key'],
                self::EVENT_LABELS[$row['event_type']] ?? $row['event_type'],
                $rtt,
                $loss,
                $row['conn_type'],
                $row['ip_addr'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function getTestSettings(int $ref_id): array
    {
        global $DIC;
        $db      = $DIC->database();
        $defaults = ['rtt_warn_ms' => 500, 'rtt_crit_ms' => 2000];
        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $res    = $db->queryF(
                'SELECT rtt_warn_ms, rtt_crit_ms FROM ' . self::TABLE_SETTINGS . ' WHERE test_id = %s',
                ['integer'], [$obj_id]
            );
            if ($row = $db->fetchAssoc($res)) {
                return array_merge($defaults, $row);
            }
        } catch (Exception $e) { /* ignore */ }
        return $defaults;
    }

    private function rttBadge(int $rtt, int $warn, int $crit): string
    {
        if ($rtt <= 0) return '<span class="text-muted">—</span>';
        $cls = $rtt >= $crit ? 'danger' : ($rtt >= $warn ? 'warning' : 'success');
        return '<span class="label label-' . $cls . '">' . $rtt . ' ms</span>';
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return $seconds . ' s';
        if ($seconds < 3600) return round($seconds / 60) . ' min';
        return round($seconds / 3600, 1) . ' h';
    }

    private function buildBaseUrl(): string
    {
        $skip = ['cmd', 'filter_ref_id', 'filter_user_id', 'filter_from', 'filter_to', 'filter_session_key'];
        $params = [];
        foreach ($_GET as $key => $value) {
            if (!in_array($key, $skip, true)) {
                $params[$key] = $value;
            }
        }
        return 'ilias.php?' . http_build_query($params);
    }

    private function sanitizeDate(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        return trim(explode(',', $ip)[0]);
    }
}
