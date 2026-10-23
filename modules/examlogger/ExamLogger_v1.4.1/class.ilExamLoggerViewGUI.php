<?php

class ilExamLoggerViewGUI
{
    private const TABLE       = 'examlogger_events';
    // Zentrales Geräte-Inventar des Plugins (seit Plugin 2.0.1), gepflegt in der
    // Plugin-Administration — hier nur lesend, mit tableExists()-Guard.
    private const HOSTS_TABLE = 'etest_hosts';

    private string $form_cmd   = 'showLog';
    private string $export_cmd = 'exportCsv';
    private string $device_cmd = 'showDevices';
    private string $import_cmd = 'importDevices';
    private int    $default_ref = 0;
    private ?int   $allowed_ref = null;

    public function setFormCmd(string $cmd): void   { $this->form_cmd   = $cmd; }
    public function setExportCmd(string $cmd): void { $this->export_cmd = $cmd; }
    public function setDeviceCmd(string $cmd): void { $this->device_cmd = $cmd; }
    public function setImportCmd(string $cmd): void { $this->import_cmd = $cmd; }
    public function setDefaultFilterRef(int $ref_id): void { $this->default_ref = $ref_id; }

    private ilModuleLanguage $lng;

    public function __construct()
    {
        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once __DIR__ . '/../../classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    /**
     * Übersetztes Label für einen Ereignistyp (Sprachschlüssel "ev_<typ>").
     */
    private function eventLabel(string $type): string
    {
        return $this->lng->txt('ev_' . $type, $type);
    }

    public function executeCommand(): void
    {
        if (!$this->authorizeScope()) {
            http_response_code(403);
            return;
        }

        $cmd = (string)($_REQUEST['cmd'] ?? 'showLog');
        if ($cmd === $this->export_cmd || $cmd === 'exportCsv') {
            $this->exportCsv();
        } else {
            $this->showLog();
        }
    }

    private function authorizeScope(): bool
    {
        global $rbacsystem;

        // Die globale Ansicht gehört nur zur Plugin-Administration. Im Modul-Proxy
        // reicht Schreibrecht an einem Test; dort müssen alle Abfragen auf genau
        // diesen Test begrenzt bleiben, auch wenn filter_ref_id manipuliert wird.
        if ($rbacsystem->checkAccess('write', SYSTEM_FOLDER_ID)) {
            return true;
        }

        $ref_id = (int)($_GET['ref_id'] ?? 0);
        if ($ref_id <= 0 || ilObject::_lookupType($ref_id, true) !== 'tst'
            || !$rbacsystem->checkAccess('write', $ref_id)) {
            return false;
        }

        $this->allowed_ref = $ref_id;
        $this->default_ref = $ref_id;
        return true;
    }

    // ─── LOG VIEW ────────────────────────────────────────────────────────────

    private function showLog(): void
    {
        global $DIC;

        $filter_event   = $this->sanitizeEventType((string)($_GET['filter_event']   ?? ''));
        $filter_ref     = $this->selectedTestRef();
        $filter_user_id = (int)($_GET['filter_user_id'] ?? 0);
        $filter_from    = $this->sanitizeDate((string)($_GET['filter_from']   ?? ''));
        $filter_to      = $this->sanitizeDate((string)($_GET['filter_to']     ?? ''));
        $filter_koffer  = $this->sanitizeKoffer((string)($_GET['filter_koffer'] ?? ''));

        [$rows, $eventTypes, $kofferList, $testList, $userList] = $this->fetchRows(
            $filter_event, $filter_ref, $filter_user_id, $filter_from, $filter_to, $filter_koffer, 1000
        );

        $DIC['tpl']->setContent(
            $this->renderView($rows, $eventTypes, $kofferList, $testList, $userList,
                $filter_event, $filter_ref, $filter_user_id, $filter_from, $filter_to, $filter_koffer)
        );
    }

    private function fetchRows(
        string $filter_event, int $filter_ref, int $filter_user_id,
        string $filter_from, string $filter_to, string $filter_koffer, int $limit
    ): array {
        global $DIC;
        $db = $DIC->database();
        $filter_ref = $this->allowed_ref ?? $filter_ref;

        $where  = [];
        $types  = [];
        $values = [];

        if ($filter_event !== '') {
            $where[] = 'e.event_type = %s'; $types[] = 'text'; $values[] = $filter_event;
        }
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
        // etest_hosts kommt vom Plugin (2.0.1+) — Guard falls das Modul vor dem Plugin aktualisiert wurde
        $hasHosts = $db->tableExists(self::HOSTS_TABLE);

        if ($filter_koffer !== '' && $hasHosts) {
            $where[] = 'h.koffer = %s'; $types[] = 'text'; $values[] = $filter_koffer;
        }

        $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $hostCols = $hasHosts
            ? 'h.ili_nr, h.koffer, h.geraet_nr,'
            : "'' AS ili_nr, '' AS koffer, '' AS geraet_nr,";
        $hostJoin = $hasHosts
            ? 'LEFT JOIN ' . self::HOSTS_TABLE . ' h ON h.ip_addr = e.ip_addr'
            : '';

        $sql = "SELECT e.id, e.test_ref_id, e.test_obj_id, e.user_id, e.event_type,
                       e.question_id, e.ip_addr, e.tstamp, e.details,
                       u.login, o.title AS test_title,
                       $hostCols
                       q.title AS question_title
                FROM " . self::TABLE . " e
                LEFT JOIN usr_data u ON u.usr_id = e.user_id
                LEFT JOIN object_data o ON o.obj_id = e.test_obj_id
                $hostJoin
                LEFT JOIN qpl_questions q ON q.question_id = e.question_id
                $whereStr
                ORDER BY e.tstamp DESC, e.id DESC
                LIMIT $limit";

        $rows = [];
        $result = $types ? $db->queryF($sql, $types, $values) : $db->query($sql);
        while ($row = $db->fetchAssoc($result)) {
            $rows[] = $row;
        }

        $typeSql = 'SELECT DISTINCT event_type FROM ' . self::TABLE;
        $typeResult = $this->allowed_ref !== null
            ? $db->queryF($typeSql . ' WHERE test_ref_id = %s ORDER BY event_type', ['integer'], [$this->allowed_ref])
            : $db->query($typeSql . ' ORDER BY event_type');
        $eventTypes = [];
        while ($r = $db->fetchAssoc($typeResult)) {
            $eventTypes[] = $r['event_type'];
        }

        $kofferList = [];
        if ($db->tableExists(self::HOSTS_TABLE)) {
            $kofferSql = "SELECT DISTINCT h.koffer FROM " . self::HOSTS_TABLE . " h";
            $kofferResult = $this->allowed_ref !== null
                ? $db->queryF(
                    $kofferSql . ' JOIN ' . self::TABLE . " e ON e.ip_addr = h.ip_addr WHERE e.test_ref_id = %s AND h.koffer != '' ORDER BY h.koffer",
                    ['integer'],
                    [$this->allowed_ref]
                )
                : $db->query($kofferSql . " WHERE h.koffer != '' ORDER BY h.koffer");
            while ($r = $db->fetchAssoc($kofferResult)) {
                $kofferList[] = $r['koffer'];
            }
        }

        // Test-Liste nur für die globale Admin-Ansicht laden.
        $testList = [];
        if ($this->allowed_ref === null) {
            $testResult = $db->query(
                "SELECT DISTINCT e.test_ref_id, o.title
                 FROM " . self::TABLE . " e
                 LEFT JOIN object_data o ON o.obj_id = e.test_obj_id
                 ORDER BY o.title, e.test_ref_id"
            );
            while ($r = $db->fetchAssoc($testResult)) {
                $testList[(int)$r['test_ref_id']] = $r['title'] ?? ('ref_id ' . $r['test_ref_id']);
            }
        }

        // Nutzer-Liste für Dropdown — wenn Test gefiltert, nur Nutzer dieses Tests
        $userList = [];
        if ($filter_ref > 0) {
            $userResult = $db->queryF(
                "SELECT DISTINCT e.user_id, u.login, u.lastname, u.firstname
                 FROM " . self::TABLE . " e
                 LEFT JOIN usr_data u ON u.usr_id = e.user_id
                 WHERE e.test_ref_id = %s
                 ORDER BY u.lastname, u.firstname, u.login",
                ['integer'],
                [$filter_ref]
            );
        } else {
            $userResult = $db->query(
                "SELECT DISTINCT e.user_id, u.login, u.lastname, u.firstname
                 FROM " . self::TABLE . " e
                 LEFT JOIN usr_data u ON u.usr_id = e.user_id
                 ORDER BY u.lastname, u.firstname, u.login"
            );
        }
        while ($r = $db->fetchAssoc($userResult)) {
            $uid   = (int)$r['user_id'];
            $label = trim(($r['lastname'] ?? '') . ', ' . ($r['firstname'] ?? ''));
            $label = ($label !== ', ' ? $label . ' (' . $r['login'] . ')' : ($r['login'] ?? 'ID ' . $uid));
            $userList[$uid] = $label;
        }

        return [$rows, $eventTypes, $kofferList, $testList, $userList];
    }

    private function renderView(
        array $rows, array $eventTypes, array $kofferList, array $testList, array $userList,
        string $filter_event, int $filter_ref, int $filter_user_id,
        string $filter_from, string $filter_to, string $filter_koffer
    ): string {
        $base     = $this->buildBaseUrl();
        $exportQs = http_build_query(array_filter([
            'filter_event'   => $filter_event,
            'filter_ref_id'  => $filter_ref > 0 ? $filter_ref : '',
            'filter_user_id' => $filter_user_id > 0 ? $filter_user_id : '',
            'filter_from'    => $filter_from,
            'filter_to'      => $filter_to,
            'filter_koffer'  => $filter_koffer,
            'cmd'            => $this->export_cmd,
        ]));

        $html  = $this->renderFilterForm($eventTypes, $kofferList, $testList, $userList,
            $filter_event, $filter_ref, $filter_user_id, $filter_from, $filter_to, $filter_koffer);

        $html .= '<p style="margin:10px 0 15px;">';
        $html .= '<a href="' . htmlspecialchars($base . '&' . $exportQs) . '" class="btn btn-default btn-sm">' . $this->lng->txt('export_csv') . '</a>';
        $html .= '&nbsp;&nbsp;' . sprintf($this->lng->txt('entries_count'), count($rows));
        if (count($rows) >= 1000) {
            $html .= ' <span class="text-muted">' . $this->lng->txt('limit_hint') . '</span>';
        }
        $html .= '</p>';

        if (empty($rows)) {
            $html .= '<div class="alert alert-info">' . $this->lng->txt('no_entries') . '</div>';
            return $html;
        }

        $html .= '<table class="table table-striped table-condensed" style="font-size:13px;">';
        $html .= '<thead><tr>'
               . '<th>' . $this->lng->txt('th_datetime') . '</th>'
               . '<th>' . $this->lng->txt('th_user') . '</th>'
               . '<th>' . $this->lng->txt('th_test') . '</th>'
               . '<th>' . $this->lng->txt('th_event') . '</th>'
               . '<th>' . $this->lng->txt('th_details') . '</th>'
               . '<th>' . $this->lng->txt('th_question') . '</th>'
               . '<th>' . $this->lng->txt('th_device') . '</th>'
               . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $label    = htmlspecialchars($this->eventLabel($row['event_type']));
            $details  = $row['details'] ?? '';
            $koffer   = $row['koffer']    ?? '';
            $geraetNr = $row['geraet_nr'] ?? '';
            $iliNr    = $row['ili_nr']    ?? '';
            $ipAddr   = $row['ip_addr']   ?? '';

            if ($koffer !== '' && $geraetNr !== '') {
                $tooltip    = htmlspecialchars(trim("$iliNr  $ipAddr"));
                $deviceCell = '<td title="' . $tooltip . '">'
                            . htmlspecialchars($koffer) . ' &middot; ' . htmlspecialchars($geraetNr)
                            . '</td>';
            } elseif ($iliNr !== '') {
                $deviceCell = '<td title="' . htmlspecialchars($ipAddr) . '">' . htmlspecialchars($iliNr) . '</td>';
            } else {
                $deviceCell = '<td style="font-size:12px;color:#999;">' . htmlspecialchars($ipAddr) . '</td>';
            }

            $html .= '<tr>'
                   . '<td>' . date('d.m.Y H:i:s', (int)$row['tstamp']) . '</td>'
                   . '<td>' . htmlspecialchars($row['login'] ?? 'ID ' . $row['user_id']) . '</td>'
                   . '<td>' . htmlspecialchars($row['test_title'] ?? 'ref_id ' . $row['test_ref_id']) . '</td>'
                   . '<td>' . $this->renderEventBadge($row['event_type'], $label) . '</td>'
                   . '<td style="font-size:12px;color:#555;">' . htmlspecialchars($details) . '</td>'
                   . '<td>' . $this->renderQuestionCell((int)$row['question_id'], $row['question_title'] ?? '') . '</td>'
                   . $deviceCell
                   . '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function renderFilterForm(
        array $eventTypes, array $kofferList, array $testList, array $userList,
        string $filter_event, int $filter_ref, int $filter_user_id,
        string $filter_from, string $filter_to, string $filter_koffer
    ): string {
        $action    = $this->buildBaseUrl();
        $hasFilter = $filter_event !== '' || $filter_from !== '' || $filter_to !== ''
                  || $filter_user_id > 0 || $filter_koffer !== ''
                  || ($filter_ref > 0 && $this->default_ref === 0);

        $html  = '<form method="get" action="' . htmlspecialchars($action) . '" class="form-inline"'
               . ' style="margin-bottom:10px;padding:12px;background:#f5f5f5;border-radius:4px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
        $html .= '<input type="hidden" name="cmd" value="' . htmlspecialchars($this->form_cmd) . '">';

        $filterKeys = ['cmd', 'filter_event', 'filter_ref_id', 'filter_user_id',
                       'filter_from', 'filter_to', 'filter_user', 'filter_koffer'];
        foreach ($_GET as $key => $value) {
            if (!in_array($key, $filterKeys, true)) {
                $html .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$value) . '">';
            }
        }

        // Test-Dropdown (nur in der globalen Ansicht, nicht bei fixem Ref-Filter)
        if ($this->default_ref === 0 && !empty($testList)) {
            $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">' . $this->lng->txt('filter_test') . '</label>';
            $html .= '<select name="filter_ref_id" class="form-control input-sm" style="max-width:220px;margin-right:8px;">';
            $html .= '<option value="">' . $this->lng->txt('filter_all') . '</option>';
            foreach ($testList as $refId => $title) {
                $selected = ($filter_ref === $refId) ? ' selected' : '';
                $html .= '<option value="' . $refId . '"' . $selected . '>'
                       . htmlspecialchars($title) . '</option>';
            }
            $html .= '</select>';
        } elseif ($filter_ref > 0) {
            $html .= '<input type="hidden" name="filter_ref_id" value="' . (int)$filter_ref . '">';
        }

        // Nutzer-Dropdown
        if (!empty($userList)) {
            $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">' . $this->lng->txt('filter_user') . '</label>';
            $html .= '<select name="filter_user_id" class="form-control input-sm" style="max-width:200px;margin-right:8px;">';
            $html .= '<option value="">' . $this->lng->txt('filter_all') . '</option>';
            foreach ($userList as $uid => $label) {
                $selected = ($filter_user_id === $uid) ? ' selected' : '';
                $html .= '<option value="' . $uid . '"' . $selected . '>'
                       . htmlspecialchars($label) . '</option>';
            }
            $html .= '</select>';
        }

        // Ereignis
        $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">' . $this->lng->txt('filter_event') . '</label>';
        $html .= '<select name="filter_event" class="form-control input-sm" style="margin-right:8px;">';
        $html .= '<option value="">' . $this->lng->txt('filter_all') . '</option>';
        foreach ($eventTypes as $type) {
            $label    = $this->eventLabel($type);
            $selected = ($filter_event === $type) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($type) . '"' . $selected . '>'
                   . htmlspecialchars($label) . '</option>';
        }
        $html .= '</select>';

        // Koffer (nur wenn Geräte importiert)
        if (!empty($kofferList)) {
            $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">' . $this->lng->txt('filter_koffer') . '</label>';
            $html .= '<select name="filter_koffer" class="form-control input-sm" style="margin-right:8px;">';
            $html .= '<option value="">' . $this->lng->txt('filter_all') . '</option>';
            foreach ($kofferList as $k) {
                $selected = ($filter_koffer === $k) ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars($k) . '"' . $selected . '>'
                       . htmlspecialchars($k) . '</option>';
            }
            $html .= '</select>';
        }

        // Datum Von/Bis
        $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">' . $this->lng->txt('filter_from') . '</label>';
        $html .= '<input type="date" name="filter_from" class="form-control input-sm" value="' . htmlspecialchars($filter_from) . '" style="margin-right:8px;">';
        $html .= '<label style="margin-right:4px;font-weight:normal;white-space:nowrap;">' . $this->lng->txt('filter_to') . '</label>';
        $html .= '<input type="date" name="filter_to" class="form-control input-sm" value="' . htmlspecialchars($filter_to) . '" style="margin-right:8px;">';

        $html .= '<button type="submit" class="btn btn-default btn-sm">' . $this->lng->txt('filter_submit') . '</button>';
        if ($hasFilter) {
            $resetUrl = $this->buildBaseUrl() . '&cmd=' . urlencode($this->form_cmd);
            $html .= '&nbsp;<a href="' . htmlspecialchars($resetUrl) . '" class="btn btn-link btn-sm">' . $this->lng->txt('filter_reset') . '</a>';
        }

        $html .= '</form>';
        return $html;
    }

    // ─── DEVICE MANAGEMENT ───────────────────────────────────────────────────

    public function renderDevicesView(): string
    {
        global $DIC;
        $db = $DIC->database();

        $base      = $this->buildBaseUrl();
        $importUrl = $base . '&cmd=' . urlencode($this->import_cmd);

        $html  = '<div style="padding:15px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;margin-bottom:20px;">';
        $html .= '<h4 style="margin-top:0;">' . $this->lng->txt('import_title') . '</h4>';
        $html .= '<p style="color:#666;font-size:13px;margin-bottom:10px;">';
        $html .= $this->lng->txt('import_format_label') . ' <code>ip_addr;hostname;ili_nr;koffer;geraet_nr;import_note</code>';
        $html .= ' &nbsp;' . $this->lng->txt('import_format_hint');
        $html .= '</p>';
        $html .= '<form method="post" action="' . htmlspecialchars($importUrl) . '" enctype="multipart/form-data">';
        $filterKeys = ['cmd', 'filter_event', 'filter_ref_id', 'filter_user_id',
                       'filter_from', 'filter_to', 'filter_user', 'filter_koffer'];
        foreach ($_GET as $key => $value) {
            if (!in_array($key, $filterKeys, true)) {
                $html .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$value) . '">';
            }
        }
        $html .= '<input type="hidden" name="cmd" value="' . htmlspecialchars($this->import_cmd) . '">';
        $html .= '<div style="display:flex;align-items:center;gap:12px;">';
        $html .= '<input type="file" name="geraete_csv" accept=".csv" class="form-control" style="max-width:420px;">';
        $html .= '<button type="submit" class="btn btn-primary btn-sm">' . $this->lng->txt('btn_import') . '</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';

        if (!$db->tableExists(self::HOSTS_TABLE)) {
            $html .= '<div class="alert alert-warning">' . $this->lng->txt('hosts_table_missing') . '</div>';
            return $html;
        }

        $result  = $db->query('SELECT ip_addr, hostname, ili_nr, koffer, geraet_nr, import_note FROM ' . self::HOSTS_TABLE . ' ORDER BY koffer, geraet_nr');
        $devices = [];
        while ($row = $db->fetchAssoc($result)) {
            $devices[] = $row;
        }

        $html .= '<p>' . sprintf($this->lng->txt('devices_count'), count($devices)) . '</p>';

        if (empty($devices)) {
            $html .= '<div class="alert alert-info">' . $this->lng->txt('no_devices') . '</div>';
            return $html;
        }

        $html .= '<table class="table table-striped table-condensed" style="font-size:13px;">';
        $html .= '<thead><tr><th>' . $this->lng->txt('dev_th_koffer') . '</th><th>' . $this->lng->txt('dev_th_geraet') . '</th><th>' . $this->lng->txt('dev_th_ili') . '</th><th>' . $this->lng->txt('dev_th_ip') . '</th><th>' . $this->lng->txt('dev_th_hostname') . '</th><th>' . $this->lng->txt('dev_th_note') . '</th></tr></thead><tbody>';
        foreach ($devices as $dev) {
            $noteStyle = $dev['import_note'] !== '' ? 'color:#bbb;font-size:12px;' : '';
            $html .= '<tr>'
                   . '<td><strong>' . htmlspecialchars($dev['koffer']) . '</strong></td>'
                   . '<td>' . htmlspecialchars($dev['geraet_nr']) . '</td>'
                   . '<td>' . htmlspecialchars($dev['ili_nr']) . '</td>'
                   . '<td style="font-size:12px;color:#666;">' . htmlspecialchars($dev['ip_addr']) . '</td>'
                   . '<td style="font-size:12px;color:#888;">' . htmlspecialchars($dev['hostname']) . '</td>'
                   . '<td style="' . $noteStyle . '">' . htmlspecialchars($dev['import_note']) . '</td>'
                   . '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    public function handleCsvImport(): array
    {
        global $DIC;
        $db = $DIC->database();

        $errorCode = (int)($_FILES['geraete_csv']['error'] ?? -1);
        if (empty($_FILES['geraete_csv']['tmp_name']) || $errorCode !== UPLOAD_ERR_OK) {
            return [sprintf($this->lng->txt('import_no_file'), $errorCode), 'failure'];
        }

        $content = file_get_contents($_FILES['geraete_csv']['tmp_name']);
        if ($content === false || $content === '') {
            return [$this->lng->txt('import_empty'), 'failure'];
        }
        // UTF-8 BOM entfernen
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $lines = preg_split('/\r?\n/', trim($content));
        if (empty($lines)) {
            return [$this->lng->txt('import_empty2'), 'failure'];
        }

        $header   = str_getcsv(array_shift($lines), ';');
        $expected = ['ip_addr', 'hostname', 'ili_nr', 'koffer', 'geraet_nr', 'import_note'];
        if (array_map('trim', array_slice($header, 0, 6)) !== $expected) {
            return [sprintf($this->lng->txt('import_bad_format'), implode(';', $expected)), 'failure'];
        }

        $count   = 0;
        $skipped = 0;

        if (!$db->tableExists(self::HOSTS_TABLE)) {
            return [$this->lng->txt('import_table_missing'), 'failure'];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $cols     = str_getcsv($line, ';');
            $ip       = trim($cols[0] ?? '');
            $hostname = trim($cols[1] ?? '');
            $ili_nr   = trim($cols[2] ?? '');
            $koffer   = trim($cols[3] ?? '');
            $geraet   = trim($cols[4] ?? '');
            $note     = trim($cols[5] ?? '');

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $skipped++;
                continue;
            }

            $db->replace(self::HOSTS_TABLE,
                ['ip_addr' => ['text', $ip]],
                [
                    'hostname'    => ['text', $hostname],
                    'ili_nr'      => ['text', $ili_nr],
                    'koffer'      => ['text', $koffer],
                    'geraet_nr'   => ['text', $geraet],
                    'import_note' => ['text', $note],
                ]
            );
            $count++;
        }

        $msg = sprintf($this->lng->txt('import_done'), $count);
        if ($skipped > 0) {
            $msg .= sprintf($this->lng->txt('import_skipped'), $skipped);
        }
        return [$msg, 'success'];
    }

    // ─── CSV EXPORT ──────────────────────────────────────────────────────────

    private function exportCsv(): void
    {
        global $DIC;
        $db = $DIC->database();

        $filter_event   = $this->sanitizeEventType((string)($_GET['filter_event']   ?? ''));
        $filter_ref     = $this->selectedTestRef();
        $filter_user_id = (int)($_GET['filter_user_id'] ?? 0);
        $filter_from    = $this->sanitizeDate((string)($_GET['filter_from']   ?? ''));
        $filter_to      = $this->sanitizeDate((string)($_GET['filter_to']     ?? ''));
        $filter_koffer  = $this->sanitizeKoffer((string)($_GET['filter_koffer'] ?? ''));

        $where  = [];
        $types  = [];
        $values = [];

        if ($filter_event !== '') {
            $where[] = 'e.event_type = %s'; $types[] = 'text'; $values[] = $filter_event;
        }
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
        $hasHosts = $db->tableExists(self::HOSTS_TABLE);

        if ($filter_koffer !== '' && $hasHosts) {
            $where[] = 'h.koffer = %s'; $types[] = 'text'; $values[] = $filter_koffer;
        }

        $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $hostCols = $hasHosts
            ? 'h.ili_nr, h.koffer, h.geraet_nr,'
            : "'' AS ili_nr, '' AS koffer, '' AS geraet_nr,";
        $hostJoin = $hasHosts
            ? 'LEFT JOIN ' . self::HOSTS_TABLE . ' h ON h.ip_addr = e.ip_addr'
            : '';

        $sql = "SELECT e.id, e.test_ref_id, e.user_id, e.event_type, e.question_id, e.ip_addr, e.tstamp, e.details,
                       u.login, u.firstname, u.lastname, o.title AS test_title,
                       $hostCols
                       q.title AS question_title
                FROM " . self::TABLE . " e
                LEFT JOIN usr_data u ON u.usr_id = e.user_id
                LEFT JOIN object_data o ON o.obj_id = e.test_obj_id
                $hostJoin
                LEFT JOIN qpl_questions q ON q.question_id = e.question_id
                $whereStr
                ORDER BY e.tstamp DESC, e.id DESC";

        $result = $types ? $db->queryF($sql, $types, $values) : $db->query($sql);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="examlog_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, array_map([$this, 'csvCell'], [
            $this->lng->txt('csv_date'),
            $this->lng->txt('csv_time'),
            $this->lng->txt('csv_login'),
            $this->lng->txt('csv_firstname'),
            $this->lng->txt('csv_lastname'),
            $this->lng->txt('csv_test'),
            $this->lng->txt('csv_refid'),
            $this->lng->txt('csv_event'),
            $this->lng->txt('csv_details'),
            $this->lng->txt('csv_qid'),
            $this->lng->txt('csv_qname'),
            $this->lng->txt('csv_koffer'),
            $this->lng->txt('csv_geraet'),
            $this->lng->txt('csv_ili'),
            $this->lng->txt('csv_ip'),
        ]), ';');

        while ($row = $db->fetchAssoc($result)) {
            fputcsv($out, array_map([$this, 'csvCell'], [
                date('d.m.Y', (int)$row['tstamp']),
                date('H:i:s', (int)$row['tstamp']),
                $row['login']      ?? '',
                $row['firstname']  ?? '',
                $row['lastname']   ?? '',
                $row['test_title'] ?? '',
                $row['test_ref_id'],
                $this->eventLabel($row['event_type']),
                $row['details']    ?? '',
                $row['question_id'] > 0 ? $row['question_id'] : '',
                $row['question_title'] ?? '',
                $row['koffer']     ?? '',
                $row['geraet_nr']  ?? '',
                $row['ili_nr']     ?? '',
                $row['ip_addr']    ?? '',
            ]), ';');
        }

        fclose($out);
        exit;
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────────

    private function selectedTestRef(): int
    {
        return $this->allowed_ref ?? (int)($_GET['filter_ref_id'] ?? $this->default_ref);
    }

    private function csvCell($value): string
    {
        $cell = (string)$value;
        if (preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) || preg_match('/^[\t\r\n]/', $cell)) {
            return "'" . $cell;
        }
        return $cell;
    }

    private function renderQuestionCell(int $question_id, string $question_title): string
    {
        if ($question_id <= 0) {
            return '—';
        }
        $id = $question_id;
        if ($question_title !== '') {
            return $id . ' &middot; <span style="color:#555;">' . htmlspecialchars($question_title) . '</span>';
        }
        return (string)$id;
    }

    private function renderEventBadge(string $event_type, string $label): string
    {
        $colorMap = [
            'test_start'            => 'success',
            'time_extension'        => 'warning',
            'test_overview'         => 'info',
            'test_submit'           => 'primary',
            'test_submit_confirmed' => 'danger',
            'navigate_next'         => 'default',
            'navigate_prev'         => 'default',
            'navigate_jump'         => 'default',
            'plugin_settings_saved' => 'warning',
            'test_settings_saved'   => 'warning',
            'test_settings_viewed'  => 'info',
        ];
        $cls = $colorMap[$event_type] ?? 'default';
        return '<span class="label label-' . $cls . '">' . $label . '</span>';
    }

    private function buildBaseUrl(): string
    {
        $skip = ['cmd', 'filter_event', 'filter_ref_id', 'filter_user_id',
                  'filter_from', 'filter_to', 'filter_user', 'filter_koffer'];
        $params = [];
        foreach ($_GET as $key => $value) {
            if (!in_array($key, $skip, true)) {
                $params[$key] = $value;
            }
        }
        return 'ilias.php?' . http_build_query($params);
    }

    private function sanitizeEventType(string $value): string
    {
        return preg_match('/^[a-z_]{1,64}$/', $value) ? $value : '';
    }

    private function sanitizeDate(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function sanitizeKoffer(string $value): string
    {
        return preg_match('/^K\d{1,4}$/', $value) ? $value : '';
    }
}
