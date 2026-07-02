<?php
/**
 * ExamPreflight GUI — Cockpit, AJAX-Endpunkte und Prüfbericht
 *
 * Wird über ilExtendedTestSettingsModuleProxyGUI geroutet — NICHT direkt in
 * ilCtrl registriert. Alle Links/Redirects laufen über die proxyXxx()-Helper.
 *
 * Kommandos:
 *   cmd=show          GET  — Cockpit: Testplayer im iframe + Steuerleiste
 *   cmd=startRun      POST — AJAX: neuen Lauf anlegen, run_id zurückgeben
 *   cmd=report        POST — AJAX: Ergebnis einer Frage speichern (+ Screenshot-Upload)
 *   cmd=finishRun     POST — AJAX: Lauf abschließen (completed/aborted)
 *   cmd=showReport    GET  — Prüfbericht: Läufe-Liste + Fragen-Karten
 *   cmd=screenshot    GET  — Screenshot-Datei ausliefern (result_id)
 *   cmd=deleteRun     GET  — Lauf inkl. Screenshots löschen
 *
 * Alle Kommandos sind durch den Proxy auf Schreibrecht (write) am Test beschränkt.
 */

class ilExamPreflightGUI
{
    private const MODULE_ID   = 'exampreflight';
    private const PROXY_CLASS = 'ilExtendedTestSettingsModuleProxyGUI';
    private const PROXY_PATH  = ['ilUIPluginRouterGUI', self::PROXY_CLASS];

    private const TABLE_RUNS    = 'exampreflight_runs';
    private const TABLE_RESULTS = 'exampreflight_results';

    private const MODULE_WEB_PATH =
        'Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/modules/exampreflight';

    private const MAX_SHOT_BYTES   = 8388608;  // 8 MB pro Screenshot
    private const MAX_CHECKS_BYTES = 65536;    // 64 KB Befund-JSON pro Frage

    private ilCtrl $ctrl;
    private ilGlobalTemplateInterface $tpl;
    private ilDBInterface $db;
    private ilObjUser $user;
    private ilModuleLanguage $lng;

    public function __construct()
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl  = $DIC->ui()->mainTemplate();
        $this->db   = $DIC->database();
        $this->user = $DIC->user();

        require_once dirname(__DIR__, 2) . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    // ─── Einstiegspunkt (vom Proxy aufgerufen) ────────────────────────────────

    public function executeCommand(): void
    {
        $cmd    = strtolower((string)($_REQUEST['cmd'] ?? 'show'));
        $ref_id = (int)($_GET['ref_id'] ?? $_POST['ref_id'] ?? 0);

        // AJAX-Endpunkte: JSON, kein Seiten-Rendering
        switch ($cmd) {
            case 'startrun':
                $this->ajaxStartRun($ref_id);
                return;
            case 'report':
                $this->ajaxReport($ref_id);
                return;
            case 'finishrun':
                $this->ajaxFinishRun($ref_id);
                return;
        }

        if ($ref_id <= 0) {
            $this->tpl->setOnScreenMessage('failure', $this->lng->txt('access_denied'));
            $this->tpl->printToStdout();
            return;
        }

        switch ($cmd) {
            case 'screenshot':
                $this->serveScreenshot($ref_id);
                return; // exit im Erfolgsfall
            case 'deleterun':
                $this->deleteRun($ref_id);
                break;
            case 'showreport':
                $this->showReport($ref_id);
                break;
            default:
                $this->showCockpit($ref_id);
        }

        $this->tpl->printToStdout();
    }

    // ─── Proxy-Routing-Helper ─────────────────────────────────────────────────

    private function setProxyParams(int $ref_id): void
    {
        $this->ctrl->setParameterByClass(self::PROXY_CLASS, 'module_id', self::MODULE_ID);
        $this->ctrl->setParameterByClass(self::PROXY_CLASS, 'ref_id', $ref_id);
    }

    private function proxyLink(string $cmd, array $params = []): string
    {
        foreach ($params as $k => $v) {
            $this->ctrl->setParameterByClass(self::PROXY_CLASS, $k, $v);
        }
        $link = $this->ctrl->getLinkTargetByClass(self::PROXY_PATH, $cmd);
        foreach (array_keys($params) as $k) {
            $this->ctrl->clearParameterByClass(self::PROXY_CLASS, $k);
        }
        return $link;
    }

    // ─── Cockpit ──────────────────────────────────────────────────────────────

    private function showCockpit(int $ref_id): void
    {
        $this->setProxyParams($ref_id);

        $obj_id     = (int)ilObject::_lookupObjectId($ref_id);
        $test_title = ilObject::_lookupTitle($obj_id);
        $test_url   = ILIAS_HTTP_PATH . '/goto.php?target=tst_' . $ref_id;

        $config = [
            'refId'             => $ref_id,
            'testUrl'           => $test_url,
            'startRunUrl'       => $this->ajaxUrl('startRun', $ref_id),
            'reportUrl'         => $this->ajaxUrl('report', $ref_id),
            'finishRunUrl'      => $this->ajaxUrl('finishRun', $ref_id),
            'reportPageUrl'     => str_replace('&amp;', '&', $this->proxyLink('showReport')),
            'expectedQuestions' => $this->expectedQuestionCount($obj_id),
            'settleMs'          => 1500,
            'maxSegments'       => 5,
            'lang'              => $this->jsTexts(),
        ];

        $this->tpl->setTitle($this->lng->txt('cockpit_title') . ': ' . $test_title);
        $this->tpl->addCss(self::MODULE_WEB_PATH . '/css/exampreflight.css');
        $this->tpl->addJavaScript(self::MODULE_WEB_PATH . '/js/exampreflight.js');
        // Config live übergeben — nie beim Script-Load cachen (addJavaScript läuft vor addOnLoadCode)
        $this->tpl->addOnLoadCode(
            'window.ExamPreflightCockpit && window.ExamPreflightCockpit.init(' . json_encode($config) . ');'
        );

        $html = '<div id="epf-cockpit">'
            . '<div id="epf-statusbar">'
            .   '<div class="epf-status-left">'
            .     '<span id="epf-state" class="epf-badge epf-badge-idle">' . $this->esc($this->lng->txt('state_idle')) . '</span>'
            .     '<span id="epf-progress"></span>'
            .   '</div>'
            .   '<div class="epf-status-right">'
            .     '<label class="epf-shot-toggle"><input type="checkbox" id="epf-shots-enabled" checked> '
            .        $this->esc($this->lng->txt('with_screenshots')) . '</label>'
            .     '<button id="epf-btn-start" class="btn btn-primary">' . $this->esc($this->lng->txt('btn_start')) . '</button>'
            .     '<button id="epf-btn-pause" class="btn btn-default" disabled>' . $this->esc($this->lng->txt('btn_pause')) . '</button>'
            .     '<button id="epf-btn-stop" class="btn btn-default" disabled>' . $this->esc($this->lng->txt('btn_stop')) . '</button>'
            .   '</div>'
            . '</div>'
            . '<div id="epf-hint" class="epf-hint">' . $this->esc($this->lng->txt('hint_initial')) . '</div>'
            . '<div id="epf-findings"></div>'
            . '<iframe id="epf-frame" src="' . htmlspecialchars($test_url) . '" title="Test"></iframe>'
            . '</div>';

        $this->tpl->setContent($html);
    }

    private function ajaxUrl(string $cmd, int $ref_id): string
    {
        return ILIAS_HTTP_PATH . '/ilias.php?baseClass=ilUIPluginRouterGUI'
            . '&cmdClass=ilextendedtestsettingsmoduleproxygui'
            . '&module_id=' . self::MODULE_ID
            . '&cmd=' . $cmd
            . '&ref_id=' . $ref_id;
    }

    /** Texte, die das Cockpit-JS braucht */
    private function jsTexts(): array
    {
        $keys = [
            'state_idle', 'state_waiting', 'state_running', 'state_paused',
            'state_done', 'state_aborted', 'state_error',
            'hint_capture', 'hint_capture_denied', 'hint_start_test', 'hint_running',
            'hint_done', 'hint_done_page', 'hint_aborted', 'hint_non_question',
            'progress_of', 'question_short', 'open_report', 'confirm_stop',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->lng->txt($k);
        }
        return $out;
    }

    // ─── AJAX: Lauf starten ───────────────────────────────────────────────────

    private function ajaxStartRun(int $ref_id): void
    {
        $this->beginJson();

        $obj_id = (int)ilObject::_lookupObjectId($ref_id);
        if ($ref_id <= 0 || $obj_id <= 0 || $this->user->getId() <= 0) {
            $this->jsonOut(['error' => 'invalid request'], 400);
        }

        $meta = [
            'userAgent'   => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'screenshots' => !empty($_POST['screenshots']),
            'viewport'    => substr(preg_replace('/[^0-9x]/', '', (string)($_POST['viewport'] ?? '')), 0, 16),
        ];

        $run_id = (int)$this->db->nextId(self::TABLE_RUNS);
        $this->db->insert(self::TABLE_RUNS, [
            'run_id'            => ['integer', $run_id],
            'test_obj_id'       => ['integer', $obj_id],
            'test_ref_id'       => ['integer', $ref_id],
            'user_id'           => ['integer', $this->user->getId()],
            'status'            => ['text', 'running'],
            'started_at'        => ['integer', time()],
            'finished_at'       => ['integer', 0],
            'questions_checked' => ['integer', 0],
            'error_count'       => ['integer', 0],
            'warning_count'     => ['integer', 0],
            'meta'              => ['clob', json_encode($meta)],
        ]);

        $this->jsonOut(['ok' => true, 'runId' => $run_id]);
    }

    // ─── AJAX: Fragen-Ergebnis speichern ─────────────────────────────────────

    private function ajaxReport(int $ref_id): void
    {
        $this->beginJson();

        $run = $this->getOwnRunningRun((int)($_POST['run_id'] ?? 0), $ref_id);
        if ($run === null) {
            $this->jsonOut(['error' => 'no active run'], 403);
        }

        $position = max(0, min(9999, (int)($_POST['position'] ?? 0)));
        $title    = substr(trim((string)($_POST['title'] ?? '')), 0, 255);
        $qtype    = substr(preg_replace('/[^a-zA-Z0-9_ -]/', '', (string)($_POST['qtype'] ?? '')), 0, 64);
        $page_url = substr((string)($_POST['page_url'] ?? ''), 0, 1000);

        $checks_raw = (string)($_POST['checks'] ?? '[]');
        if (strlen($checks_raw) > self::MAX_CHECKS_BYTES) {
            $checks_raw = '[]';
        }
        $checks = json_decode($checks_raw, true);
        if (!is_array($checks)) {
            $checks = [];
        }

        $errors   = 0;
        $warnings = 0;
        $clean    = [];
        foreach (array_slice($checks, 0, 100) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $level = in_array(($c['level'] ?? ''), ['error', 'warning', 'info'], true) ? $c['level'] : 'info';
            $clean[] = [
                'check'   => substr((string)($c['check'] ?? ''), 0, 40),
                'level'   => $level,
                'message' => substr((string)($c['message'] ?? ''), 0, 300),
                'detail'  => substr((string)($c['detail'] ?? ''), 0, 500),
            ];
            if ($level === 'error') {
                $errors++;
            } elseif ($level === 'warning') {
                $warnings++;
            }
        }
        $status = $errors > 0 ? 'fail' : ($warnings > 0 ? 'warn' : 'ok');

        // Screenshot (optional, JPEG)
        $shot_file = '';
        $shot_w    = max(0, min(20000, (int)($_POST['shot_w'] ?? 0)));
        $shot_h    = max(0, min(60000, (int)($_POST['shot_h'] ?? 0)));
        if (!empty($_FILES['shot']) && is_uploaded_file($_FILES['shot']['tmp_name'])) {
            if ((int)$_FILES['shot']['size'] <= self::MAX_SHOT_BYTES) {
                $dir = $this->storageDir((int)$run['test_obj_id'], (int)$run['run_id']);
                if ($dir !== '') {
                    $shot_file = 'q' . str_pad((string)$position, 3, '0', STR_PAD_LEFT) . '.jpg';
                    if (!move_uploaded_file($_FILES['shot']['tmp_name'], $dir . '/' . $shot_file)) {
                        $shot_file = '';
                    }
                }
            }
        }

        $result_id = (int)$this->db->nextId(self::TABLE_RESULTS);
        $this->db->insert(self::TABLE_RESULTS, [
            'result_id'     => ['integer', $result_id],
            'run_id'        => ['integer', (int)$run['run_id']],
            'position'      => ['integer', $position],
            'title'         => ['text', $title],
            'qtype'         => ['text', $qtype],
            'status'        => ['text', $status],
            'error_count'   => ['integer', $errors],
            'warning_count' => ['integer', $warnings],
            'checks'        => ['clob', json_encode($clean)],
            'page_url'      => ['text', $page_url],
            'shot_file'     => ['text', $shot_file],
            'shot_w'        => ['integer', $shot_w],
            'shot_h'        => ['integer', $shot_h],
            'created_at'    => ['integer', time()],
        ]);

        $this->jsonOut(['ok' => true, 'resultId' => $result_id, 'status' => $status]);
    }

    // ─── AJAX: Lauf abschließen ───────────────────────────────────────────────

    private function ajaxFinishRun(int $ref_id): void
    {
        $this->beginJson();

        $run = $this->getOwnRunningRun((int)($_POST['run_id'] ?? 0), $ref_id);
        if ($run === null) {
            $this->jsonOut(['error' => 'no active run'], 403);
        }

        $status = ((string)($_POST['result'] ?? '')) === 'aborted' ? 'aborted' : 'completed';

        // Aggregation serverseitig aus den gespeicherten Ergebnissen
        $res = $this->db->queryF(
            'SELECT COUNT(*) cnt, COALESCE(SUM(error_count),0) errs, COALESCE(SUM(warning_count),0) warns'
            . ' FROM ' . self::TABLE_RESULTS . ' WHERE run_id = %s',
            ['integer'],
            [(int)$run['run_id']]
        );
        $agg = $this->db->fetchAssoc($res) ?: ['cnt' => 0, 'errs' => 0, 'warns' => 0];

        $this->db->update(self::TABLE_RUNS, [
            'status'            => ['text', $status],
            'finished_at'       => ['integer', time()],
            'questions_checked' => ['integer', (int)$agg['cnt']],
            'error_count'       => ['integer', (int)$agg['errs']],
            'warning_count'     => ['integer', (int)$agg['warns']],
        ], [
            'run_id' => ['integer', (int)$run['run_id']],
        ]);

        $this->jsonOut([
            'ok'       => true,
            'status'   => $status,
            'checked'  => (int)$agg['cnt'],
            'errors'   => (int)$agg['errs'],
            'warnings' => (int)$agg['warns'],
        ]);
    }

    // ─── Prüfbericht ──────────────────────────────────────────────────────────

    private function showReport(int $ref_id): void
    {
        $this->setProxyParams($ref_id);

        $obj_id     = (int)ilObject::_lookupObjectId($ref_id);
        $test_title = ilObject::_lookupTitle($obj_id);
        $run_id     = (int)($_GET['run_id'] ?? 0);

        $runs = [];
        $res  = $this->db->queryF(
            'SELECT * FROM ' . self::TABLE_RUNS . ' WHERE test_obj_id = %s ORDER BY run_id DESC',
            ['integer'],
            [$obj_id]
        );
        while ($row = $this->db->fetchAssoc($res)) {
            $runs[] = $row;
        }

        $this->tpl->setTitle($this->lng->txt('report_title') . ': ' . $test_title);
        $this->tpl->addCss(self::MODULE_WEB_PATH . '/css/exampreflight.css');

        if (empty($runs)) {
            $html = '<p>' . $this->esc($this->lng->txt('no_runs')) . '</p>'
                . '<a class="btn btn-primary" href="' . htmlspecialchars($this->proxyLink('show')) . '">'
                . $this->esc($this->lng->txt('start_cockpit')) . '</a>';
            $this->tpl->setContent($html);
            return;
        }

        if ($run_id <= 0) {
            $run_id = (int)$runs[0]['run_id'];
        }

        $html = $this->renderRunList($runs, $run_id)
            . $this->renderRunDetail($runs, $run_id);

        $this->tpl->setContent($html);
    }

    private function renderRunList(array $runs, int $active_run_id): string
    {
        $html = '<div class="epf-runlist"><table class="table table-striped table-condensed">'
            . '<thead><tr>'
            . '<th>' . $this->esc($this->lng->txt('th_run')) . '</th>'
            . '<th>' . $this->esc($this->lng->txt('th_date')) . '</th>'
            . '<th>' . $this->esc($this->lng->txt('th_user')) . '</th>'
            . '<th>' . $this->esc($this->lng->txt('th_status')) . '</th>'
            . '<th>' . $this->esc($this->lng->txt('th_questions')) . '</th>'
            . '<th>' . $this->esc($this->lng->txt('th_findings')) . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody>';

        $token = $this->csrfToken();

        foreach ($runs as $run) {
            $rid      = (int)$run['run_id'];
            $link     = $this->proxyLink('showReport', ['run_id' => $rid]);
            $del_url  = str_replace('&amp;', '&', $this->proxyLink('deleteRun'));
            $active   = $rid === $active_run_id ? ' class="epf-run-active"' : '';
            $duration = '';
            if ((int)$run['finished_at'] > 0) {
                $duration = ' (' . max(0, (int)$run['finished_at'] - (int)$run['started_at']) . ' s)';
            }
            $findings = ((int)$run['error_count'] > 0 || (int)$run['warning_count'] > 0)
                ? '<span class="text-danger">' . (int)$run['error_count'] . ' ❌</span> '
                    . '<span class="text-warning">' . (int)$run['warning_count'] . ' ⚠️</span>'
                : '<span class="text-success">✓</span>';

            $html .= '<tr' . $active . '>'
                . '<td><a href="' . htmlspecialchars($link) . '">#' . $rid . '</a></td>'
                . '<td>' . date('d.m.Y H:i', (int)$run['started_at']) . $duration . '</td>'
                . '<td>' . $this->esc(ilObjUser::_lookupLogin((int)$run['user_id'])) . '</td>'
                . '<td>' . $this->esc($this->lng->txt('status_' . $run['status'], (string)$run['status'])) . '</td>'
                . '<td>' . (int)$run['questions_checked'] . '</td>'
                . '<td>' . $findings . '</td>'
                . '<td><form method="post" action="' . htmlspecialchars($del_url) . '" style="display:inline"'
                . ' onsubmit="return confirm(\'' . $this->esc($this->lng->txt('confirm_delete_run')) . '\');">'
                . '<input type="hidden" name="run_id" value="' . $rid . '">'
                . '<input type="hidden" name="epf_token" value="' . htmlspecialchars($token) . '">'
                . '<button type="submit" class="btn btn-link text-danger" style="padding:0">🗑</button>'
                . '</form></td>'
                . '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function renderRunDetail(array $runs, int $run_id): string
    {
        $run = null;
        foreach ($runs as $r) {
            if ((int)$r['run_id'] === $run_id) {
                $run = $r;
                break;
            }
        }
        if ($run === null) {
            return '';
        }

        $results = [];
        $res     = $this->db->queryF(
            'SELECT * FROM ' . self::TABLE_RESULTS . ' WHERE run_id = %s ORDER BY position ASC',
            ['integer'],
            [$run_id]
        );
        while ($row = $this->db->fetchAssoc($res)) {
            $results[] = $row;
        }

        $html = '<h3>' . $this->esc(sprintf($this->lng->txt('run_detail_heading'), $run_id)) . '</h3>';

        if ((string)$run['status'] === 'running') {
            $html .= '<p class="text-warning">' . $this->esc($this->lng->txt('run_still_running')) . '</p>';
        }
        $html .= '<p class="epf-run-note">' . $this->esc($this->lng->txt('testdata_note')) . '</p>';

        if (empty($results)) {
            return $html . '<p>' . $this->esc($this->lng->txt('no_results')) . '</p>';
        }

        $html .= '<div class="epf-cards">';
        foreach ($results as $r) {
            $html .= $this->renderResultCard($r);
        }
        return $html . '</div>';
    }

    private function renderResultCard(array $r): string
    {
        $status_icon = ['ok' => '✅', 'warn' => '⚠️', 'fail' => '❌'][(string)$r['status']] ?? '❓';

        $checks = json_decode((string)$r['checks'], true);
        $checks = is_array($checks) ? $checks : [];

        $findings_html = '';
        foreach ($checks as $c) {
            $icon = ['error' => '❌', 'warning' => '⚠️', 'info' => 'ℹ️'][(string)($c['level'] ?? 'info')] ?? 'ℹ️';
            $findings_html .= '<li>' . $icon . ' ' . $this->esc((string)($c['message'] ?? ''));
            if (!empty($c['detail'])) {
                $findings_html .= ' <small class="text-muted">' . $this->esc((string)$c['detail']) . '</small>';
            }
            $findings_html .= '</li>';
        }
        if ($findings_html === '') {
            $findings_html = '<li class="text-success">' . $this->esc($this->lng->txt('no_findings')) . '</li>';
        }

        $shot_html = '';
        if (!empty($r['shot_file'])) {
            $src = $this->proxyLink('screenshot', ['result_id' => (int)$r['result_id']]);
            $shot_html = '<a href="' . htmlspecialchars($src) . '" target="_blank">'
                . '<img class="epf-shot-thumb" src="' . htmlspecialchars($src) . '" alt="Screenshot" loading="lazy"></a>';
        }

        $qtype = !empty($r['qtype'])
            ? ' <small class="text-muted">(' . $this->esc((string)$r['qtype']) . ')</small>'
            : '';

        return '<div class="epf-card epf-card-' . $this->esc((string)$r['status']) . '">'
            . '<div class="epf-card-head">' . $status_icon . ' <strong>'
            . $this->esc($this->lng->txt('question_short')) . ' ' . (int)$r['position'] . ':</strong> '
            . $this->esc((string)$r['title']) . $qtype . '</div>'
            . '<div class="epf-card-body">'
            . '<ul class="epf-findings-list">' . $findings_html . '</ul>'
            . $shot_html
            . '</div></div>';
    }

    // ─── Screenshot ausliefern ────────────────────────────────────────────────

    private function serveScreenshot(int $ref_id): void
    {
        $result_id = (int)($_GET['result_id'] ?? 0);
        $obj_id    = (int)ilObject::_lookupObjectId($ref_id);

        $res = $this->db->queryF(
            'SELECT res.shot_file, res.run_id, r.test_obj_id FROM ' . self::TABLE_RESULTS . ' res'
            . ' JOIN ' . self::TABLE_RUNS . ' r ON r.run_id = res.run_id'
            . ' WHERE res.result_id = %s',
            ['integer'],
            [$result_id]
        );
        $row = $this->db->fetchAssoc($res);

        // Ergebnis muss zum Test gehören, für den der Proxy das Schreibrecht geprüft hat
        if (!$row || (int)$row['test_obj_id'] !== $obj_id || empty($row['shot_file'])) {
            http_response_code(404);
            exit;
        }

        $file = $this->storageDir((int)$row['test_obj_id'], (int)$row['run_id'], false)
            . '/' . basename((string)$row['shot_file']);

        if (!is_file($file)) {
            http_response_code(404);
            exit;
        }

        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string)filesize($file));
        header('Cache-Control: private, max-age=3600');
        readfile($file);
        exit;
    }

    // ─── Lauf löschen ─────────────────────────────────────────────────────────

    private function deleteRun(int $ref_id): void
    {
        // Zustandsändernd → nur POST mit gültigem Session-Token (CSRF-Schutz)
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !hash_equals($this->csrfToken(), (string)($_POST['epf_token'] ?? ''))
        ) {
            $this->tpl->setOnScreenMessage('failure', $this->lng->txt('access_denied'));
            $this->showReport($ref_id);
            return;
        }

        $run_id = (int)($_POST['run_id'] ?? 0);
        $obj_id = (int)ilObject::_lookupObjectId($ref_id);

        $res = $this->db->queryF(
            'SELECT run_id, test_obj_id FROM ' . self::TABLE_RUNS . ' WHERE run_id = %s AND test_obj_id = %s',
            ['integer', 'integer'],
            [$run_id, $obj_id]
        );

        if ($this->db->fetchAssoc($res)) {
            $dir = $this->storageDir($obj_id, $run_id, false);
            if (is_dir($dir)) {
                foreach (glob($dir . '/*.jpg') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
            $this->db->manipulateF(
                'DELETE FROM ' . self::TABLE_RESULTS . ' WHERE run_id = %s',
                ['integer'],
                [$run_id]
            );
            $this->db->manipulateF(
                'DELETE FROM ' . self::TABLE_RUNS . ' WHERE run_id = %s',
                ['integer'],
                [$run_id]
            );
            $this->tpl->setOnScreenMessage('success', $this->lng->txt('run_deleted'));
        }

        $this->showReport($ref_id);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    /** Lauf laden, wenn er dem aktuellen User gehört, zum Test passt und noch läuft */
    private function getOwnRunningRun(int $run_id, int $ref_id): ?array
    {
        if ($run_id <= 0 || $ref_id <= 0) {
            return null;
        }
        $obj_id = (int)ilObject::_lookupObjectId($ref_id);
        $res    = $this->db->queryF(
            'SELECT * FROM ' . self::TABLE_RUNS
            . ' WHERE run_id = %s AND test_obj_id = %s AND user_id = %s AND status = %s',
            ['integer', 'integer', 'integer', 'text'],
            [$run_id, $obj_id, $this->user->getId(), 'running']
        );
        $row = $this->db->fetchAssoc($res);
        return $row ?: null;
    }

    /** Screenshot-Verzeichnis; legt es bei $create an. Leerer String bei Fehler. */
    private function storageDir(int $obj_id, int $run_id, bool $create = true): string
    {
        $base = (defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : ILIAS_DATA_DIR . '/' . CLIENT_ID)
            . '/plugins/ExtendedTestSettings/exampreflight';
        $dir = $base . '/' . $obj_id . '/' . $run_id;

        if ($create && !is_dir($dir) && !mkdir($dir, 0755, true)) {
            error_log('[ExamPreflight] Screenshot-Verzeichnis nicht anlegbar: ' . $dir);
            return '';
        }
        return $dir;
    }

    private function expectedQuestionCount(int $obj_id): int
    {
        try {
            $res = $this->db->queryF(
                'SELECT COUNT(*) cnt FROM tst_test_question q'
                . ' JOIN tst_tests t ON t.test_id = q.test_fi WHERE t.obj_fi = %s',
                ['integer'],
                [$obj_id]
            );
            $row = $this->db->fetchAssoc($res);
            return (int)($row['cnt'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /** Session-gebundener Token für zustandsändernde Formulare (deleteRun) */
    private function csrfToken(): string
    {
        $token = (string)ilSession::get('exampreflight_csrf');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            ilSession::set('exampreflight_csrf', $token);
        }
        return $token;
    }

    private function beginJson(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
    }

    /** @return never */
    private function jsonOut(array $payload, int $http_code = 200): void
    {
        http_response_code($http_code);
        echo json_encode($payload);
        exit;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }
}
