<?php
/**
 * ExamArchiver GUI - Archiv-Verwaltung: Erzeugung (paketweise), Download, Prüfung, Löschung
 * Part of ExtendedTestSettings Plugin
 *
 * Routing ausschließlich über ilExtendedTestSettingsModuleProxyGUI (write-Recht auf den Test).
 * Die globale Admin-Ansicht (renderAdminView) wird zusätzlich von der Plugin-ConfigGUI
 * eingebunden; alle AJAX-Aktionen laufen auch dort pro Test über den Proxy.
 */

class ilExamArchiverGUI
{
    private const TABLE      = 'examarchiver_archives';
    private const LOG_TABLE  = 'examarchiver_log';
    private const CHUNK_SIZE = 5;
    private const VERSION    = '1.0.0';

    private ilModuleLanguage $lng;
    private ilExamArchiverCollector $collector;
    private ilExamArchiverRenderer $renderer;

    public function __construct()
    {
        require_once __DIR__ . '/../../classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);

        // Über den Proxy sind Collector/Renderer bereits geladen (require_files);
        // beim Einbinden aus der ConfigGUI ggf. noch nicht.
        require_once __DIR__ . '/class.ilExamArchiverCollector.php';
        require_once __DIR__ . '/class.ilExamArchiverRenderer.php';
        $this->collector = new ilExamArchiverCollector();
        $this->renderer  = new ilExamArchiverRenderer($this->lng);
    }

    public function executeCommand(): void
    {
        $cmd = (string) ($_REQUEST['cmd'] ?? 'showArchives');

        switch ($cmd) {
            case 'startArchive':
                $this->startArchive();
                break;
            case 'processChunk':
                $this->processChunk();
                break;
            case 'finalizeArchive':
                $this->finalizeArchive();
                break;
            case 'downloadArchive':
                $this->downloadArchive();
                break;
            case 'verifyArchive':
                $this->verifyArchive();
                break;
            case 'deleteArchive':
                $this->deleteArchive();
                break;
            default:
                $this->showArchives();
                break;
        }
    }

    // ─── PER-TEST-ANSICHT (via Proxy) ────────────────────────────────────────

    private function showArchives(): void
    {
        global $DIC;

        $ref_id = (int) ($_GET['ref_id'] ?? 0);
        $obj_id = (int) ilObject::_lookupObjectId($ref_id);
        $title  = $obj_id > 0 ? (string) ilObject::_lookupTitle($obj_id) : '';

        $html  = '<h3>' . htmlspecialchars($this->lng->txt('title')) . ' — ' . htmlspecialchars($title) . '</h3>';
        $html .= '<p><a href="' . htmlspecialchars(ILIAS_HTTP_PATH . '/goto.php?target=tst_' . $ref_id) . '">'
               . '&laquo; ' . htmlspecialchars($this->lng->txt('back_to_test')) . '</a></p>';

        $html .= '<p><button class="btn btn-primary btn-sm" id="xarch-start" '
               . 'data-url="' . htmlspecialchars($this->proxyUrl($ref_id)) . '" '
               . 'data-title="' . htmlspecialchars($title) . '">'
               . htmlspecialchars($this->lng->txt('btn_archive_now')) . '</button> '
               . '<span id="xarch-progress" class="text-muted"></span></p>';

        $html .= $this->renderArchiveTable($obj_id);
        $html .= $this->archiveJs();

        $DIC['tpl']->setContent($html);
    }

    /**
     * Globale Admin-Ansicht: Test-Auswahl + alle Archive + Zugriffsprotokoll.
     * Wird von der Plugin-ConfigGUI (Tab "Archiv") aufgerufen.
     */
    public function renderAdminView(): string
    {
        $t = fn(string $key) => htmlspecialchars($this->lng->txt($key));

        $html  = '<p class="text-muted" style="max-width:820px;">' . $t('admin_intro') . '</p>';

        if (ilExamArchiverRenderer::findWkhtmltopdf() === null) {
            $html .= '<div class="alert alert-warning">' . $t('no_wkhtmltopdf') . '</div>';
        }

        // Test-Liste mit Checkboxen
        $html .= '<h4>' . $t('admin_tests') . '</h4>';
        $html .= '<p><button class="btn btn-primary btn-sm" id="xarch-start-selected">'
               . $t('btn_archive_selected') . '</button> '
               . '<span id="xarch-progress" class="text-muted"></span></p>';

        $html .= '<table class="table table-striped table-condensed" style="font-size:13px;">';
        $html .= '<thead><tr><th></th>'
               . '<th>' . $t('th_test') . '</th>'
               . '<th>' . $t('th_ref_id') . '</th>'
               . '<th>' . $t('th_participants') . '</th>'
               . '<th>' . $t('th_last_archive') . '</th>'
               . '<th>' . $t('th_status') . '</th>'
               . '</tr></thead><tbody>';

        foreach ($this->fetchTests() as $row) {
            $last = $row['last_archive'] > 0
                ? date('d.m.Y H:i', (int) $row['last_archive'])
                : '—';
            $html .= '<tr>'
                   . '<td><input type="checkbox" class="xarch-cb" '
                   . 'data-url="' . htmlspecialchars($this->proxyUrl((int) $row['ref_id'])) . '" '
                   . 'data-title="' . htmlspecialchars($row['title']) . '" '
                   . ((int) $row['participants'] === 0 ? 'disabled' : '') . '></td>'
                   . '<td><a href="' . htmlspecialchars(ILIAS_HTTP_PATH . '/goto.php?target=tst_' . (int) $row['ref_id']) . '">'
                   . htmlspecialchars($row['title']) . '</a></td>'
                   . '<td>' . (int) $row['ref_id'] . '</td>'
                   . '<td>' . (int) $row['participants'] . '</td>'
                   . '<td>' . $last . '</td>'
                   . '<td class="xarch-status"></td>'
                   . '</tr>';
        }
        $html .= '</tbody></table>';

        // Alle Archive
        $html .= '<h4>' . $t('admin_archives') . '</h4>';
        $html .= $this->renderArchiveTable(0);

        // Zugriffsprotokoll
        $html .= '<details style="margin-top:20px;"><summary style="cursor:pointer;color:#16457c;">'
               . $t('admin_log') . '</summary>' . $this->renderLogTable() . '</details>';

        $html .= $this->archiveJs();
        return $html;
    }

    // ─── ARCHIV-ERZEUGUNG (AJAX, chunked) ────────────────────────────────────

    private function startArchive(): void
    {
        global $DIC;

        try {
            $ref_id = (int) ($_GET['ref_id'] ?? 0);
            $obj_id = (int) ilObject::_lookupObjectId($ref_id);
            if ($obj_id <= 0) {
                $this->jsonOut(['error' => 'invalid ref_id'], 400);
            }

            $test = $this->collector->getTestData($obj_id, $ref_id);
            $ids  = $this->collector->getActiveIds($test['test_id']);
            if (count($ids) === 0) {
                $this->jsonOut(['error' => $this->lng->txt('err_no_participants')], 400);
            }

            $db  = $DIC->database();
            $id  = $db->nextId(self::TABLE);
            $pdf = ilExamArchiverRenderer::findWkhtmltopdf() !== null;

            $work = $this->workDir($obj_id, $id);
            if (!is_dir($work . '/teilnehmer') && !mkdir($work . '/teilnehmer', 0770, true)) {
                throw new Exception('Arbeitsverzeichnis nicht anlegbar: ' . $work);
            }
            mkdir($work . '/data', 0770, true);

            // Test-Snapshot festschreiben (Chunks + Finalisierung lesen diesen Stand)
            file_put_contents(
                $work . '/data/test.json',
                json_encode($test, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            $db->insert(self::TABLE, [
                'id'          => ['integer', $id],
                'obj_id'      => ['integer', $obj_id],
                'ref_id'      => ['integer', $ref_id],
                'title'       => ['text', mb_substr($test['title'], 0, 255)],
                'status'      => ['text', 'running'],
                'created_by'  => ['integer', $DIC->user()->getId()],
                'created_at'  => ['integer', time()],
                'total_count' => ['integer', count($ids)],
                'done_count'  => ['integer', 0],
                'pdf_enabled' => ['integer', $pdf ? 1 : 0],
            ]);

            $this->jsonOut(['archive_id' => $id, 'total' => count($ids), 'pdf' => $pdf]);
        } catch (Throwable $e) {
            error_log('[ExamArchiver] startArchive Fehler: ' . $e->getMessage());
            $this->jsonOut(['error' => $e->getMessage()], 500);
        }
    }

    private function processChunk(): void
    {
        global $DIC;
        $db = $DIC->database();

        $archive = $this->loadArchiveForRequest();
        if (($archive['status'] ?? '') !== 'running') {
            $this->jsonOut(['error' => 'archive not running'], 409);
        }

        @set_time_limit(300);

        try {
            $work = $this->workDir((int) $archive['obj_id'], (int) $archive['id']);
            $test = json_decode((string) file_get_contents($work . '/data/test.json'), true);
            if (!is_array($test)) {
                throw new Exception('test.json fehlt oder ist defekt');
            }

            $ids   = $this->collector->getActiveIds((int) $test['test_id']);
            $done  = (int) $archive['done_count'];
            $slice = array_slice($ids, $done, self::CHUNK_SIZE);
            $meta  = $this->buildMeta($archive);

            foreach ($slice as $active_id) {
                $p    = $this->collector->getParticipantData($test, $active_id);
                $base = $this->participantFileBase($p);

                $html = $this->renderer->renderParticipantHtml($test, $p, $meta);
                file_put_contents($work . '/teilnehmer/' . $base . '.html', $html);

                $pdf_ok = false;
                if ((int) $archive['pdf_enabled'] === 1) {
                    $pdf_ok = $this->renderer->htmlToPdf(
                        $work . '/teilnehmer/' . $base . '.html',
                        $work . '/teilnehmer/' . $base . '.pdf'
                    );
                }

                // Zusammenfassung + Volldaten zeilenweise puffern (Finalisierung liest beides)
                $line = [
                    'summary' => $this->buildSummary($p, $base, $pdf_ok),
                    'full'    => $p,
                ];
                file_put_contents(
                    $work . '/data/participants.jsonl',
                    json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                    FILE_APPEND
                );
                $done++;
            }

            $db->update(
                self::TABLE,
                ['done_count' => ['integer', $done]],
                ['id' => ['integer', (int) $archive['id']]]
            );

            $this->jsonOut([
                'done'     => $done,
                'total'    => (int) $archive['total_count'],
                'finished' => $done >= (int) $archive['total_count'],
            ]);
        } catch (Throwable $e) {
            $this->markFailed($archive, 'processChunk: ' . $e->getMessage());
            $this->jsonOut(['error' => $e->getMessage()], 500);
        }
    }

    private function finalizeArchive(): void
    {
        global $DIC;
        $db = $DIC->database();

        $archive = $this->loadArchiveForRequest();
        if (($archive['status'] ?? '') !== 'running') {
            $this->jsonOut(['error' => 'archive not running'], 409);
        }

        @set_time_limit(600);

        try {
            $db->update(self::TABLE, ['status' => ['text', 'zipping']], ['id' => ['integer', (int) $archive['id']]]);

            $work = $this->workDir((int) $archive['obj_id'], (int) $archive['id']);
            $test = json_decode((string) file_get_contents($work . '/data/test.json'), true);

            $summaries = [];
            $full      = [];
            $jsonl     = $work . '/data/participants.jsonl';
            foreach (is_file($jsonl) ? (file($jsonl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $summaries[] = $row['summary'];
                    $full[]      = $row['full'];
                }
            }
            usort($summaries, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            $meta = $this->buildMeta($archive);

            // Übersichtsseite + PDF
            $index = $this->renderer->renderIndexHtml($test, $summaries, $meta);
            file_put_contents($work . '/index.html', $index);
            if ((int) $archive['pdf_enabled'] === 1) {
                $this->renderer->htmlToPdf($work . '/index.html', $work . '/index.pdf');
            }

            // Maschinenlesbare Formate
            file_put_contents($work . '/data/ergebnisse.csv', $this->renderer->buildCsv($summaries));
            file_put_contents(
                $work . '/data/archive.json',
                json_encode(
                    ['meta' => $meta, 'test' => $test, 'participants' => $full],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                )
            );
            @unlink($jsonl);

            // Manifest mit SHA-256 aller Dateien
            $files = [];
            foreach ($this->listFiles($work) as $rel) {
                $files[$rel] = hash_file('sha256', $work . '/' . $rel);
            }
            ksort($files);
            $manifest = [
                'generator'   => 'ExamArchiver v' . self::VERSION . ' (ExtendedTestSettings)',
                'algorithm'   => 'sha256',
                'meta'        => $meta,
                'file_count'  => count($files),
                'files'       => $files,
            ];
            file_put_contents(
                $work . '/manifest.json',
                json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );
            $manifest_sha = hash_file('sha256', $work . '/manifest.json');

            // ZIP bauen (mit Wrapper-Ordner), Arbeitsverzeichnis entfernen
            $wrapper  = 'ExamArchive_' . (int) $archive['obj_id'] . '_'
                      . date('Y-m-d_Hi', (int) $archive['created_at']) . '_' . (int) $archive['id'];
            $zip_path = $this->archiveDir((int) $archive['obj_id']) . '/' . $wrapper . '.zip';
            $this->zipDirectory($work, $zip_path, $wrapper);
            $this->removeDirectory($work);

            $zip_sha = hash_file('sha256', $zip_path);
            $db->update(self::TABLE, [
                'status'          => ['text', 'done'],
                'finished_at'     => ['integer', time()],
                'zip_name'        => ['text', basename($zip_path)],
                'zip_size'        => ['integer', (int) filesize($zip_path)],
                'zip_sha256'      => ['text', $zip_sha],
                'manifest_sha256' => ['text', $manifest_sha],
            ], ['id' => ['integer', (int) $archive['id']]]);

            $this->writeLog(
                (int) $archive['id'],
                (int) $archive['obj_id'],
                'created',
                'files=' . count($files) . ' zip=' . basename($zip_path) . ' sha256=' . $zip_sha
            );

            $this->jsonOut([
                'finished'   => true,
                'zip'        => basename($zip_path),
                'size'       => (int) filesize($zip_path),
                'sha256'     => $zip_sha,
            ]);
        } catch (Throwable $e) {
            $this->markFailed($archive, 'finalizeArchive: ' . $e->getMessage());
            $this->jsonOut(['error' => $e->getMessage()], 500);
        }
    }

    // ─── DOWNLOAD / PRÜFUNG / LÖSCHUNG ───────────────────────────────────────

    private function downloadArchive(): void
    {
        $archive = $this->loadArchiveForRequest();
        if (($archive['status'] ?? '') !== 'done') {
            $this->jsonOut(['error' => 'archive not available'], 404);
        }

        $path = $this->archiveDir((int) $archive['obj_id']) . '/' . $archive['zip_name'];
        if (!is_file($path)) {
            $this->jsonOut(['error' => 'file missing on disk'], 404);
        }

        $this->writeLog((int) $archive['id'], (int) $archive['obj_id'], 'downloaded', $archive['zip_name']);

        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . addslashes($archive['zip_name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private function verifyArchive(): void
    {
        $archive = $this->loadArchiveForRequest();
        if (($archive['status'] ?? '') !== 'done') {
            $this->jsonOut(['error' => 'archive not available'], 404);
        }

        $path = $this->archiveDir((int) $archive['obj_id']) . '/' . $archive['zip_name'];
        if (!is_file($path)) {
            $this->writeLog((int) $archive['id'], (int) $archive['obj_id'], 'verify_failed', 'file missing');
            $this->jsonOut(['ok' => false, 'reason' => $this->lng->txt('verify_missing')]);
        }

        @set_time_limit(300);
        $actual = hash_file('sha256', $path);
        $ok     = hash_equals((string) $archive['zip_sha256'], (string) $actual);

        $this->writeLog(
            (int) $archive['id'],
            (int) $archive['obj_id'],
            $ok ? 'verified' : 'verify_failed',
            'actual=' . $actual
        );

        $this->jsonOut(['ok' => $ok, 'expected' => $archive['zip_sha256'], 'actual' => $actual]);
    }

    private function deleteArchive(): void
    {
        global $DIC;

        $archive = $this->loadArchiveForRequest();

        // Laufende/abgebrochene Läufe: Arbeitsverzeichnis wegräumen
        $work = $this->workDir((int) $archive['obj_id'], (int) $archive['id']);
        if (is_dir($work)) {
            $this->removeDirectory($work);
        }

        if (($archive['zip_name'] ?? '') !== '') {
            $path = $this->archiveDir((int) $archive['obj_id']) . '/' . $archive['zip_name'];
            if (is_file($path)) {
                @unlink($path);
            }
        }

        // Metadaten-Zeile bleibt als Nachweis erhalten (status=deleted)
        $DIC->database()->update(
            self::TABLE,
            ['status' => ['text', 'deleted']],
            ['id' => ['integer', (int) $archive['id']]]
        );
        $this->writeLog((int) $archive['id'], (int) $archive['obj_id'], 'deleted', (string) ($archive['zip_name'] ?? ''));

        $this->jsonOut(['ok' => true]);
    }

    // ─── DATEN / RENDERING-HELPER ────────────────────────────────────────────

    /**
     * Lädt den Archiv-Datensatz zur Anfrage und stellt sicher, dass er zum
     * ref_id der (vom Proxy rechte-geprüften) URL gehört.
     */
    private function loadArchiveForRequest(): array
    {
        global $DIC;
        $db = $DIC->database();

        $id     = (int) ($_REQUEST['archive_id'] ?? 0);
        $ref_id = (int) ($_GET['ref_id'] ?? 0);

        $res = $db->queryF("SELECT * FROM " . self::TABLE . " WHERE id = %s", ['integer'], [$id]);
        $row = $db->fetchAssoc($res);
        if (!$row || (int) $row['ref_id'] !== $ref_id) {
            $this->jsonOut(['error' => 'archive not found'], 404);
        }
        return $row;
    }

    private function buildSummary(array $p, string $file_base, bool $pdf): array
    {
        $r    = $p['result'];
        $name = trim($p['lastname'] . ', ' . $p['firstname']);
        if ($name === ',') {
            $name = $p['login'] !== '' ? $p['login'] : ('active_id ' . $p['active_id']);
        }

        // Zeitraum des gewerteten Durchlaufs
        $started = $finished = '';
        if ($r !== null) {
            foreach ($p['passes'] as $pass) {
                if ((int) $pass['pass'] === (int) $r['scored_pass']) {
                    $started  = $pass['started'];
                    $finished = $pass['finished'];
                }
            }
        } elseif (!empty($p['passes'])) {
            $started  = $p['passes'][0]['started'];
            $finished = $p['passes'][count($p['passes']) - 1]['finished'];
        }

        return [
            'login'         => $p['login'],
            'name'          => $name,
            'lastname'      => $p['lastname'],
            'firstname'     => $p['firstname'],
            'matriculation' => $p['matriculation'],
            'points'        => $r['reached_points'] ?? 0.0,
            'max_points'    => $r['max_points'] ?? 0.0,
            'mark'          => (string) ($r['mark_official'] ?? ''),
            'mark_short'    => (string) ($r['mark_short'] ?? ''),
            'passed'        => $r !== null ? $r['passed'] : null,
            'scored_pass'   => $r !== null ? (int) $r['scored_pass'] : null,
            'started'       => $started,
            'finished'      => $finished,
            'file'          => 'teilnehmer/' . $file_base,
            'pdf'           => $pdf,
        ];
    }

    private function buildMeta(array $archive): array
    {
        global $DIC;
        return [
            'archive_id'       => (int) $archive['id'],
            'obj_id'           => (int) $archive['obj_id'],
            'ref_id'           => (int) $archive['ref_id'],
            'test_title'       => (string) $archive['title'],
            'created_at'       => (int) $archive['created_at'],
            'created_at_text'  => date('c', (int) $archive['created_at']),
            'created_by'       => (int) $archive['created_by'],
            'created_by_login' => (string) $DIC->user()->getLogin(),
            'participants'     => (int) $archive['total_count'],
            'module_version'   => self::VERSION,
            'platform'         => ILIAS_HTTP_PATH . ' (Client ' . CLIENT_ID . ')',
        ];
    }

    private function participantFileBase(array $p): string
    {
        $login = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $p['login']);
        if ($login === '' || $login === null) {
            $login = 'user';
        }
        return $login . '_' . (int) $p['active_id'];
    }

    /**
     * Alle Tests der Installation mit Teilnehmerzahl und letztem Archiv-Lauf.
     */
    private function fetchTests(): array
    {
        global $DIC;
        $db = $DIC->database();

        $rows = [];
        $res  = $db->query(
            "SELECT o.obj_id, o.title, r.ref_id, t.test_id,
                    (SELECT COUNT(*) FROM tst_active a WHERE a.test_fi = t.test_id) AS participants,
                    (SELECT MAX(x.created_at) FROM " . self::TABLE . " x
                      WHERE x.obj_id = o.obj_id AND x.status = " . $db->quote('done', 'text') . ") AS last_archive
             FROM object_data o
             JOIN object_reference r ON r.obj_id = o.obj_id AND r.deleted IS NULL
             JOIN tst_tests t ON t.obj_fi = o.obj_id
             WHERE o.type = " . $db->quote('tst', 'text') . "
             ORDER BY o.title"
        );
        while ($row = $db->fetchAssoc($res)) {
            $rows[] = [
                'obj_id'       => (int) $row['obj_id'],
                'ref_id'       => (int) $row['ref_id'],
                'title'        => (string) $row['title'],
                'participants' => (int) $row['participants'],
                'last_archive' => (int) ($row['last_archive'] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * Archiv-Tabelle; obj_id = 0 → alle Tests (globale Ansicht).
     */
    private function renderArchiveTable(int $obj_id): string
    {
        global $DIC;
        $db = $DIC->database();
        $t  = fn(string $key) => htmlspecialchars($this->lng->txt($key));

        if ($obj_id > 0) {
            $res = $db->queryF(
                "SELECT a.*, u.login FROM " . self::TABLE . " a
                 LEFT JOIN usr_data u ON u.usr_id = a.created_by
                 WHERE a.obj_id = %s ORDER BY a.created_at DESC",
                ['integer'],
                [$obj_id]
            );
        } else {
            $res = $db->query(
                "SELECT a.*, u.login FROM " . self::TABLE . " a
                 LEFT JOIN usr_data u ON u.usr_id = a.created_by
                 ORDER BY a.created_at DESC LIMIT 500"
            );
        }

        $rows = [];
        while ($row = $db->fetchAssoc($res)) {
            $rows[] = $row;
        }
        if (!$rows) {
            return '<div class="alert alert-info">' . $t('no_archives') . '</div>';
        }

        $html = '<table class="table table-striped table-condensed" style="font-size:13px;">'
              . '<thead><tr>'
              . '<th>#</th>'
              . ($obj_id === 0 ? '<th>' . $t('th_test') . '</th>' : '')
              . '<th>' . $t('th_created') . '</th>'
              . '<th>' . $t('th_by') . '</th>'
              . '<th>' . $t('th_participants') . '</th>'
              . '<th>' . $t('th_size') . '</th>'
              . '<th>SHA-256</th>'
              . '<th>' . $t('th_status') . '</th>'
              . '<th>' . $t('th_actions') . '</th>'
              . '</tr></thead><tbody>';

        foreach ($rows as $a) {
            $url    = $this->proxyUrl((int) $a['ref_id']);
            $status = htmlspecialchars($this->lng->txt('st_' . $a['status'], (string) $a['status']));
            $sha    = (string) $a['zip_sha256'];

            $actions = '';
            if ($a['status'] === 'done') {
                $actions = '<a class="btn btn-default btn-xs" href="'
                         . htmlspecialchars($url . '&cmd=downloadArchive&archive_id=' . (int) $a['id']) . '">'
                         . $t('btn_download') . '</a> '
                         . '<button class="btn btn-default btn-xs xarch-verify" data-url="'
                         . htmlspecialchars($url) . '" data-id="' . (int) $a['id'] . '">'
                         . $t('btn_verify') . '</button> ';
            }
            if ($a['status'] !== 'deleted') {
                $actions .= '<button class="btn btn-danger btn-xs xarch-delete" data-url="'
                          . htmlspecialchars($url) . '" data-id="' . (int) $a['id'] . '">'
                          . $t('btn_delete') . '</button>';
            }

            $html .= '<tr>'
                   . '<td>' . (int) $a['id'] . '</td>'
                   . ($obj_id === 0 ? '<td>' . htmlspecialchars($a['title']) . '</td>' : '')
                   . '<td>' . date('d.m.Y H:i', (int) $a['created_at']) . '</td>'
                   . '<td>' . htmlspecialchars($a['login'] ?? ('ID ' . $a['created_by'])) . '</td>'
                   . '<td>' . (int) $a['done_count'] . ' / ' . (int) $a['total_count'] . '</td>'
                   . '<td>' . $this->formatBytes((int) $a['zip_size']) . '</td>'
                   . '<td>' . ($sha !== ''
                        ? '<code title="' . htmlspecialchars($sha) . '" style="font-size:11px;">'
                          . htmlspecialchars(substr($sha, 0, 12)) . '…</code>'
                        : '—') . '</td>'
                   . '<td><span class="label label-' . $this->statusColor((string) $a['status']) . '">' . $status . '</span>'
                   . ($a['status'] === 'failed' && !empty($a['error'])
                        ? ' <span class="text-danger" style="font-size:11px;" title="'
                          . htmlspecialchars((string) $a['error']) . '">(!)</span>'
                        : '')
                   . '</td>'
                   . '<td class="xarch-row-actions">' . $actions . '</td>'
                   . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function renderLogTable(): string
    {
        global $DIC;
        $db = $DIC->database();
        $t  = fn(string $key) => htmlspecialchars($this->lng->txt($key));

        $res  = $db->query(
            "SELECT l.*, u.login FROM " . self::LOG_TABLE . " l
             LEFT JOIN usr_data u ON u.usr_id = l.user_id
             ORDER BY l.tstamp DESC, l.id DESC LIMIT 200"
        );

        $html = '<table class="table table-condensed" style="font-size:12px;margin-top:8px;">'
              . '<thead><tr><th>' . $t('th_created') . '</th><th>' . $t('th_by') . '</th>'
              . '<th>' . $t('th_archive') . '</th><th>' . $t('th_action') . '</th>'
              . '<th>' . $t('th_details') . '</th><th>IP</th></tr></thead><tbody>';

        $count = 0;
        while ($row = $db->fetchAssoc($res)) {
            $count++;
            $html .= '<tr>'
                   . '<td>' . date('d.m.Y H:i:s', (int) $row['tstamp']) . '</td>'
                   . '<td>' . htmlspecialchars($row['login'] ?? ('ID ' . $row['user_id'])) . '</td>'
                   . '<td>#' . (int) $row['archive_id'] . '</td>'
                   . '<td>' . htmlspecialchars($this->lng->txt('lg_' . $row['action'], (string) $row['action'])) . '</td>'
                   . '<td style="word-break:break-all;color:#666;">' . htmlspecialchars((string) ($row['details'] ?? '')) . '</td>'
                   . '<td>' . htmlspecialchars((string) ($row['ip_addr'] ?? '')) . '</td>'
                   . '</tr>';
        }
        $html .= '</tbody></table>';

        return $count > 0 ? $html : '<p class="text-muted" style="margin-top:8px;">' . $t('no_log') . '</p>';
    }

    /**
     * Gemeinsames Inline-JS für Erzeugung (sequenziell, chunked), Prüfung und Löschung.
     */
    private function archiveJs(): string
    {
        $cfg = json_encode([
            'confirmDelete' => $this->lng->txt('js_confirm_delete'),
            'preparing'     => $this->lng->txt('js_preparing'),
            'progress'      => $this->lng->txt('js_progress'),
            'zipping'       => $this->lng->txt('js_zipping'),
            'doneReload'    => $this->lng->txt('js_done_reload'),
            'failed'        => $this->lng->txt('js_failed'),
            'verifyOk'      => $this->lng->txt('js_verify_ok'),
            'verifyFail'    => $this->lng->txt('js_verify_fail'),
            'selectOne'     => $this->lng->txt('js_select_one'),
        ], JSON_UNESCAPED_UNICODE);

        return <<<JS
<script>
(function () {
    var L = $cfg;
    var progressEl = document.getElementById('xarch-progress');

    function say(msg) { if (progressEl) { progressEl.textContent = msg; } }

    async function post(url, params) {
        var qs = Object.keys(params).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        var resp = await fetch(url + '&' + qs, { method: 'POST', credentials: 'same-origin' });
        var data = null;
        try { data = await resp.json(); } catch (e) { /* leer */ }
        if (!resp.ok || !data || data.error) {
            throw new Error((data && data.error) ? data.error : ('HTTP ' + resp.status));
        }
        return data;
    }

    async function archiveOne(url, title, statusCell) {
        function report(msg) {
            say(title + ': ' + msg);
            if (statusCell) { statusCell.textContent = msg; }
        }
        report(L.preparing);
        var start = await post(url, { cmd: 'startArchive' });
        var done = 0;
        while (done < start.total) {
            var r = await post(url, { cmd: 'processChunk', archive_id: start.archive_id });
            done = r.done;
            report(L.progress.replace('%1', done).replace('%2', start.total));
            if (r.finished) { break; }
        }
        report(L.zipping);
        var fin = await post(url, { cmd: 'finalizeArchive', archive_id: start.archive_id });
        report('OK (' + fin.zip + ')');
        return fin;
    }

    async function runQueue(items) {
        for (var i = 0; i < items.length; i++) {
            try {
                await archiveOne(items[i].url, items[i].title, items[i].cell);
            } catch (e) {
                console.error('[ExamArchiver]', e);
                say(items[i].title + ': ' + L.failed + ' — ' + e.message);
                if (items[i].cell) { items[i].cell.textContent = L.failed; }
                return;
            }
        }
        say(L.doneReload);
        setTimeout(function () { window.location.reload(); }, 1200);
    }

    var single = document.getElementById('xarch-start');
    if (single) {
        single.addEventListener('click', function () {
            single.disabled = true;
            runQueue([{ url: single.getAttribute('data-url'), title: single.getAttribute('data-title'), cell: null }]);
        });
    }

    var multi = document.getElementById('xarch-start-selected');
    if (multi) {
        multi.addEventListener('click', function () {
            var items = [];
            document.querySelectorAll('.xarch-cb:checked').forEach(function (cb) {
                items.push({
                    url: cb.getAttribute('data-url'),
                    title: cb.getAttribute('data-title'),
                    cell: cb.closest('tr').querySelector('.xarch-status')
                });
            });
            if (!items.length) { alert(L.selectOne); return; }
            multi.disabled = true;
            runQueue(items);
        });
    }

    document.querySelectorAll('.xarch-verify').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            btn.disabled = true;
            try {
                var r = await post(btn.getAttribute('data-url'), { cmd: 'verifyArchive', archive_id: btn.getAttribute('data-id') });
                alert(r.ok ? L.verifyOk : (L.verifyFail + '\\n' + r.expected + '\\n' + r.actual));
            } catch (e) {
                alert(L.failed + ': ' + e.message);
            }
            btn.disabled = false;
        });
    });

    document.querySelectorAll('.xarch-delete').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            if (!confirm(L.confirmDelete)) { return; }
            btn.disabled = true;
            try {
                await post(btn.getAttribute('data-url'), { cmd: 'deleteArchive', archive_id: btn.getAttribute('data-id') });
                window.location.reload();
            } catch (e) {
                alert(L.failed + ': ' + e.message);
                btn.disabled = false;
            }
        });
    });
})();
</script>
JS;
    }

    // ─── INFRASTRUKTUR ───────────────────────────────────────────────────────

    private function proxyUrl(int $ref_id): string
    {
        return ILIAS_HTTP_PATH . '/ilias.php?baseClass=ilUIPluginRouterGUI'
             . '&cmdClass=ilextendedtestsettingsmoduleproxygui'
             . '&module_id=examarchiver&ref_id=' . $ref_id;
    }

    private function baseDir(): string
    {
        $base = defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : (ILIAS_DATA_DIR . '/' . CLIENT_ID);
        return rtrim($base, '/') . '/plugins/ExtendedTestSettings/examarchiver';
    }

    private function archiveDir(int $obj_id): string
    {
        $dir = $this->baseDir() . '/' . $obj_id;
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        return $dir;
    }

    private function workDir(int $obj_id, int $archive_id): string
    {
        return $this->archiveDir($obj_id) . '/work_' . $archive_id;
    }

    /** @return string[] relative Pfade aller Dateien unterhalb von $dir */
    private function listFiles(string $dir): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files[] = ltrim(substr($file->getPathname(), strlen($dir)), '/');
            }
        }
        sort($files);
        return $files;
    }

    private function zipDirectory(string $dir, string $zip_path, string $wrapper): void
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('PHP-Erweiterung zip fehlt');
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('ZIP nicht anlegbar: ' . $zip_path);
        }
        foreach ($this->listFiles($dir) as $rel) {
            $zip->addFile($dir . '/' . $rel, $wrapper . '/' . $rel);
        }
        if (!$zip->close()) {
            throw new Exception('ZIP-Erstellung fehlgeschlagen: ' . $zip_path);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function markFailed(array $archive, string $message): void
    {
        global $DIC;
        try {
            $DIC->database()->update(self::TABLE, [
                'status' => ['text', 'failed'],
                'error'  => ['text', mb_substr($message, 0, 4000)],
            ], ['id' => ['integer', (int) $archive['id']]]);
            $this->writeLog((int) $archive['id'], (int) $archive['obj_id'], 'failed', mb_substr($message, 0, 1000));
        } catch (Exception $e) {
            error_log('[ExamArchiver] markFailed Fehler: ' . $e->getMessage());
        }
        error_log('[ExamArchiver] Archiv #' . $archive['id'] . ' fehlgeschlagen: ' . $message);
    }

    private function writeLog(int $archive_id, int $obj_id, string $action, ?string $details = null): void
    {
        global $DIC;
        try {
            $db = $DIC->database();
            $db->insert(self::LOG_TABLE, [
                'id'         => ['integer', $db->nextId(self::LOG_TABLE)],
                'archive_id' => ['integer', $archive_id],
                'obj_id'     => ['integer', $obj_id],
                'user_id'    => ['integer', $DIC->user()->getId()],
                'action'     => ['text', $action],
                'details'    => ['text', $details !== null ? mb_substr($details, 0, 1000) : null],
                'ip_addr'    => ['text', $this->getClientIp()],
                'tstamp'     => ['integer', time()],
            ]);
        } catch (Exception $e) {
            error_log('[ExamArchiver] writeLog Fehler: ' . $e->getMessage());
        }
    }

    private function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        return trim(explode(',', $ip)[0]);
    }

    private function statusColor(string $status): string
    {
        return [
            'done'    => 'success',
            'running' => 'info',
            'zipping' => 'info',
            'failed'  => 'danger',
            'deleted' => 'default',
        ][$status] ?? 'default';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }

    /**
     * JSON-Antwort senden und Request beenden.
     */
    private function jsonOut(array $data, int $status = 200): never
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
