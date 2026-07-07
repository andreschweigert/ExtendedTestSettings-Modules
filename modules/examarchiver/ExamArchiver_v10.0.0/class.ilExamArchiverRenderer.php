<?php
/**
 * ExamArchiver Renderer - Erzeugt die selbsttragenden Archiv-Dateien (HTML, CSV, PDF)
 * Part of ExtendedTestSettings Plugin
 *
 * Alle HTML-Dateien sind offline lesbar: CSS/JS inline, Bilder als data-URIs eingebettet.
 * Die Antwort-Darstellung übernimmt die jeweilige ILIAS-Fragenklasse (getSolutionOutput),
 * damit alle Fragetypen korrekt gerendert werden; bei Fehlern fällt der Renderer auf
 * eine Roh-Darstellung aus tst_solutions zurück.
 */

class ilExamArchiverRenderer
{
    private const WKHTMLTOPDF_CANDIDATES = ['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf'];
    private const MAX_INLINE_IMAGE_BYTES = 2097152; // 2 MB pro Bild

    private ilModuleLanguage $lng;

    public function __construct(ilModuleLanguage $lng)
    {
        $this->lng = $lng;
    }

    // ─── TEILNEHMER-SEITE ────────────────────────────────────────────────────

    public function renderParticipantHtml(array $test, array $p, array $meta): string
    {
        $t = fn(string $key) => $this->lng->txt($key);
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $name = trim($p['lastname'] . ', ' . $p['firstname']);
        if ($name === ',') {
            $name = $p['login'] !== '' ? $p['login'] : ('active_id ' . $p['active_id']);
        }

        $html  = $this->htmlHead($e($test['title']) . ' — ' . $e($name));
        $html .= '<body><div class="wrap">';
        $html .= '<header><h1>' . $e($test['title']) . '</h1>';
        $html .= '<h2>' . $e($name) . '</h2></header>';

        // Teilnehmer-Stammdaten
        $html .= '<table class="kv">';
        $rows = [
            [$t('p_login'),         $p['login']],
            [$t('p_matriculation'), $p['matriculation']],
            [$t('p_email'),         $p['email']],
            [$t('p_active_id'),     $p['active_id']],
            [$t('p_tries'),         $p['tries']],
            [$t('p_submitted'),     $p['submitted'] ? $t('yes') . ($p['submit_time'] !== '' ? ' (' . $p['submit_time'] . ')' : '') : $t('no')],
        ];
        foreach ($rows as [$k, $v]) {
            $html .= '<tr><th>' . $e($k) . '</th><td>' . $e($v) . '</td></tr>';
        }
        $html .= '</table>';

        // Gewertetes Endergebnis
        if ($p['result']) {
            $r   = $p['result'];
            $pct = $r['max_points'] > 0 ? round($r['reached_points'] / $r['max_points'] * 100, 2) : 0;
            $html .= '<div class="result ' . ($r['passed'] ? 'passed' : 'failed') . '">';
            $html .= '<strong>' . $e($t('p_result')) . ':</strong> '
                   . $e($r['reached_points'] . ' / ' . $r['max_points']) . ' (' . $pct . ' %)'
                   . ' &nbsp;·&nbsp; ' . $e($t('p_mark')) . ': <strong>' . $e($r['mark_official'] !== '' ? $r['mark_official'] : $r['mark_short']) . '</strong>'
                   . ' &nbsp;·&nbsp; ' . ($r['passed'] ? $e($t('p_passed')) : $e($t('p_not_passed')))
                   . ' &nbsp;·&nbsp; ' . $e($t('p_scored_pass')) . ': ' . ((int) $r['scored_pass'] + 1);
            $html .= '</div>';
        } else {
            $html .= '<div class="result neutral">' . $e($t('p_no_result')) . '</div>';
        }

        // Durchläufe
        foreach ($p['passes'] as $pass) {
            $scored = $p['result'] && (int) $p['result']['scored_pass'] === (int) $pass['pass'];
            $html .= '<section class="pass' . ($scored ? ' scored' : '') . '">';
            $html .= '<h3>' . $e($t('p_pass')) . ' ' . ((int) $pass['pass'] + 1)
                   . ($scored ? ' <span class="badge">' . $e($t('p_scored')) . '</span>' : '')
                   . '</h3>';
            $html .= '<p class="passmeta">'
                   . $e($t('p_points')) . ': ' . $e($pass['points'] . ' / ' . $pass['max_points'])
                   . ' · ' . $e($t('p_answered')) . ': ' . $e($pass['answered'] . ' / ' . $pass['questions'])
                   . ' · ' . $e($t('p_workingtime')) . ': ' . $this->formatDuration((int) $pass['workingtime_s'])
                   . ($pass['started'] !== ''  ? ' · ' . $e($t('p_started'))  . ': ' . $e($pass['started'])  : '')
                   . ($pass['finished'] !== '' ? ' · ' . $e($t('p_finished')) . ': ' . $e($pass['finished']) : '')
                   . '</p>';

            foreach ($pass['question_data'] as $q) {
                $html .= '<div class="question">';
                $html .= '<div class="qhead"><span class="qnr">' . $e($t('p_question')) . ' ' . ((int) $q['sequence'] + 0) . '</span> '
                       . '<span class="qtitle">' . $e($q['title']) . '</span>'
                       . '<span class="qpoints">' . $e($q['reached_points'] . ' / ' . $q['max_points']) . ' ' . $e($t('p_pts'))
                       . ($q['manual_scored'] ? ' · ' . $e($t('p_manual')) : '') . '</span></div>';

                // Antwort des Teilnehmers (gerendert durch die ILIAS-Fragenklasse)
                $html .= '<div class="qblock"><div class="qlabel">' . $e($t('p_given_answer')) . '</div>';
                $html .= $this->renderSolution($q['question_id'], $p['active_id'], (int) $pass['pass'], false, $q);
                $html .= '</div>';

                // Musterlösung
                $html .= '<details class="qblock best"><summary>' . $e($t('p_best_solution')) . '</summary>';
                $html .= $this->renderSolution($q['question_id'], $p['active_id'], (int) $pass['pass'], true, $q);
                $html .= '</details>';

                // Manuelles Feedback der Korrektoren
                if (!empty($q['feedback']['feedback'])) {
                    $html .= '<div class="qblock feedback"><div class="qlabel">' . $e($t('p_feedback'))
                           . (!empty($q['feedback']['finalized_by']) ? ' (' . $e($q['feedback']['finalized_by']) . ')' : '')
                           . '</div>' . $q['feedback']['feedback'] . '</div>';
                }

                $html .= '</div>';
            }
            $html .= '</section>';
        }

        $html .= $this->footer($meta);
        $html .= '</div></body></html>';

        return $this->inlineImages($html);
    }

    /**
     * Rendert die Lösung über die ILIAS-Fragen-GUI; Fallback: Roh-Antworten-Tabelle.
     */
    private function renderSolution(int $question_id, int $active_id, int $pass, bool $best, array $q): string
    {
        try {
            // ILIAS 10: instantiateQuestionGUI() ist nullable (z. B. gelöschte Frage)
            $gui = assQuestion::instantiateQuestionGUI($question_id);
            if ($gui === null) {
                error_log('[ExamArchiver] Frage ' . $question_id . ' nicht instanzierbar (Fragetyp nicht auflösbar)');
                return '<p class="muted">' . htmlspecialchars($this->lng->txt('p_render_failed')) . '</p>';
            }
            // Signatur: active_id, pass, graphicalOutput, result_output, show_question_only,
            //           show_feedback, show_correct_solution, show_manual_scoring, show_question_text,
            //           show_inline_feedback (10. Param nur in ILIAS 10, in 9 ignoriert)
            $out = $gui->getSolutionOutput(
                $active_id,
                $pass,
                false,
                false,
                true,
                false,
                $best,
                false,
                !$best,
                false
            );
            if (trim((string) $out) !== '') {
                return '<div class="ilias-q">' . $out . '</div>';
            }
        } catch (Throwable $ex) {
            error_log('[ExamArchiver] getSolutionOutput Fehler (question ' . $question_id . '): ' . $ex->getMessage());
        }

        // Fallback: Roh-Antworten
        if ($best) {
            return '<p class="muted">' . htmlspecialchars($this->lng->txt('p_render_failed')) . '</p>';
        }
        if (empty($q['raw_solutions'])) {
            return '<p class="muted">' . htmlspecialchars($this->lng->txt('p_not_answered')) . '</p>';
        }
        $html = '<table class="raw"><tr><th>value1</th><th>value2</th><th>' . htmlspecialchars($this->lng->txt('p_time')) . '</th></tr>';
        foreach ($q['raw_solutions'] as $sol) {
            $html .= '<tr><td>' . htmlspecialchars((string) $sol['value1']) . '</td>'
                   . '<td>' . htmlspecialchars((string) $sol['value2']) . '</td>'
                   . '<td>' . date('d.m.Y H:i:s', (int) $sol['tstamp']) . '</td></tr>';
        }
        return $html . '</table>';
    }

    // ─── ÜBERSICHTSSEITE ─────────────────────────────────────────────────────

    /**
     * @param array $summaries pro Teilnehmer: login, name, matriculation, points, max_points,
     *                         mark, passed, started, finished, file (relativer Pfad), pdf (bool)
     */
    public function renderIndexHtml(array $test, array $summaries, array $meta): string
    {
        $t = fn(string $key) => $this->lng->txt($key);
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $s = $test['settings'];

        $html  = $this->htmlHead($e($test['title']) . ' — ' . $e($t('idx_title')));
        $html .= '<body><div class="wrap">';
        $html .= '<header><h1>' . $e($test['title']) . '</h1><h2>' . $e($t('idx_title')) . '</h2></header>';

        // Test-Stammdaten + Einstellungs-Snapshot
        $html .= '<div class="cols"><div>';
        $html .= '<h3>' . $e($t('idx_testdata')) . '</h3><table class="kv">';
        $kv = [
            [$t('idx_obj_id'),   $test['obj_id']],
            [$t('idx_ref_id'),   $test['ref_id']],
            [$t('idx_qset'),     str_starts_with((string) $s['question_set_type'], 'RANDOM') ? $t('idx_qset_random') : $t('idx_qset_fixed')],
            [$t('idx_tries'),    (int) $s['nr_of_tries'] === 0 ? $t('idx_unlimited') : $s['nr_of_tries']],
            [$t('idx_timelimit'), $s['enable_processing_time'] ? $s['processing_time'] : $t('idx_none')],
            [$t('idx_start'),    $s['starting_time'] > 0 ? date('d.m.Y H:i', $s['starting_time']) : '—'],
            [$t('idx_end'),      $s['ending_time'] > 0 ? date('d.m.Y H:i', $s['ending_time']) : '—'],
            [$t('idx_scoring'),  $s['pass_scoring'] === '1' ? $t('idx_scoring_best') : $t('idx_scoring_last')],
            [$t('idx_shuffle'),  $s['shuffle_questions'] ? $t('yes') : $t('no')],
            [$t('idx_anonymity'), $s['anonymity'] ? $t('yes') : $t('no')],
        ];
        foreach ($kv as [$k, $v]) {
            $html .= '<tr><th>' . $e($k) . '</th><td>' . $e($v) . '</td></tr>';
        }
        $html .= '</table></div><div>';

        // Notenschlüssel
        $html .= '<h3>' . $e($t('idx_marks')) . '</h3><table class="list"><tr>'
               . '<th>' . $e($t('idx_mark_short')) . '</th><th>' . $e($t('idx_mark_official')) . '</th>'
               . '<th>' . $e($t('idx_mark_min')) . '</th><th>' . $e($t('idx_mark_passed')) . '</th></tr>';
        foreach ($test['marks'] as $m) {
            $html .= '<tr><td>' . $e($m['short_name']) . '</td><td>' . $e($m['official_name']) . '</td>'
                   . '<td>' . $e($m['minimum_level']) . ' %</td>'
                   . '<td>' . ($m['passed'] ? $e($t('yes')) : $e($t('no'))) . '</td></tr>';
        }
        $html .= '</table></div></div>';

        // Statistik
        $withResult = array_filter($summaries, fn($x) => $x['max_points'] > 0 || $x['points'] > 0 || $x['mark'] !== '');
        $passedCnt  = count(array_filter($summaries, fn($x) => $x['passed'] === true));
        $avg = 0.0;
        $pcts = [];
        foreach ($summaries as $x) {
            if ($x['max_points'] > 0) {
                $pcts[] = $x['points'] / $x['max_points'] * 100;
            }
        }
        if ($pcts) {
            $avg = round(array_sum($pcts) / count($pcts), 1);
        }
        $html .= '<h3>' . $e($t('idx_stats')) . '</h3>';
        $html .= '<p>' . $e($t('idx_participants')) . ': <strong>' . count($summaries) . '</strong>'
               . ' · ' . $e($t('idx_with_result')) . ': <strong>' . count($withResult) . '</strong>'
               . ' · ' . $e($t('idx_passed_cnt')) . ': <strong>' . $passedCnt . '</strong>'
               . ' · ' . $e($t('idx_avg_pct')) . ': <strong>' . $avg . ' %</strong></p>';

        // Notenverteilung als CSS-Balken
        $dist = [];
        foreach ($summaries as $x) {
            if ($x['mark'] !== '') {
                $dist[$x['mark']] = ($dist[$x['mark']] ?? 0) + 1;
            }
        }
        if ($dist) {
            ksort($dist, SORT_NATURAL);
            $maxCnt = max($dist);
            $html .= '<table class="dist">';
            foreach ($dist as $mark => $cnt) {
                $w = $maxCnt > 0 ? round($cnt / $maxCnt * 100) : 0;
                $html .= '<tr><th>' . $e($mark) . '</th>'
                       . '<td><div class="bar" style="width:' . $w . '%;">&nbsp;</div></td>'
                       . '<td class="cnt">' . $cnt . '</td></tr>';
            }
            $html .= '</table>';
        }

        // Teilnehmer-Tabelle (Suche + Sortierung, offline per Vanilla-JS)
        $html .= '<h3>' . $e($t('idx_list')) . '</h3>';
        $html .= '<input type="text" id="q" placeholder="' . $e($t('idx_search')) . '" oninput="filterRows()">';
        $html .= '<table class="list sortable" id="plist"><thead><tr>';
        foreach (['idx_th_name', 'idx_th_login', 'idx_th_matric', 'idx_th_points', 'idx_th_pct', 'idx_th_mark', 'idx_th_passed', 'idx_th_start', 'idx_th_finish', 'idx_th_files'] as $i => $key) {
            $html .= '<th onclick="sortBy(' . $i . ')">' . $e($t($key)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($summaries as $x) {
            $pct = $x['max_points'] > 0 ? round($x['points'] / $x['max_points'] * 100, 1) : '';
            $links = '<a href="' . $e($x['file']) . '.html">HTML</a>';
            if ($x['pdf']) {
                $links .= ' · <a href="' . $e($x['file']) . '.pdf">PDF</a>';
            }
            $html .= '<tr>'
                   . '<td>' . $e($x['name']) . '</td>'
                   . '<td>' . $e($x['login']) . '</td>'
                   . '<td>' . $e($x['matriculation']) . '</td>'
                   . '<td data-v="' . $e($x['points']) . '">' . $e($x['points'] . ' / ' . $x['max_points']) . '</td>'
                   . '<td data-v="' . $e($pct) . '">' . $e($pct !== '' ? $pct . ' %' : '—') . '</td>'
                   . '<td>' . $e($x['mark'] !== '' ? $x['mark'] : '—') . '</td>'
                   . '<td>' . ($x['passed'] === true ? $e($t('yes')) : ($x['passed'] === false ? $e($t('no')) : '—')) . '</td>'
                   . '<td>' . $e($x['started']) . '</td>'
                   . '<td>' . $e($x['finished']) . '</td>'
                   . '<td>' . $links . '</td>'
                   . '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= $this->indexJs();
        $html .= $this->footer($meta);
        $html .= '</div></body></html>';
        return $html;
    }

    // ─── CSV ─────────────────────────────────────────────────────────────────

    public function buildCsv(array $summaries): string
    {
        $t = fn(string $key) => $this->lng->txt($key);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($fh, [
            $t('csv_login'), $t('csv_matric'), $t('csv_lastname'), $t('csv_firstname'),
            $t('csv_points'), $t('csv_maxpoints'), $t('csv_pct'),
            $t('csv_mark_short'), $t('csv_mark_official'), $t('csv_passed'),
            $t('csv_scored_pass'), $t('csv_start'), $t('csv_finish'),
        ], ';');

        foreach ($summaries as $x) {
            $pct = $x['max_points'] > 0 ? round($x['points'] / $x['max_points'] * 100, 2) : '';
            fputcsv($fh, [
                $x['login'], $x['matriculation'], $x['lastname'], $x['firstname'],
                str_replace('.', ',', (string) $x['points']),
                str_replace('.', ',', (string) $x['max_points']),
                str_replace('.', ',', (string) $pct),
                $x['mark_short'], $x['mark'],
                $x['passed'] === true ? '1' : ($x['passed'] === false ? '0' : ''),
                $x['scored_pass'] !== null ? $x['scored_pass'] + 1 : '',
                $x['started'], $x['finished'],
            ], ';');
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    // ─── PDF ─────────────────────────────────────────────────────────────────

    public static function findWkhtmltopdf(): ?string
    {
        foreach (self::WKHTMLTOPDF_CANDIDATES as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }
        return null;
    }

    /**
     * Erzeugt aus einer HTML-Datei eine PDF-Datei. false, wenn kein wkhtmltopdf
     * verfügbar ist oder die Konvertierung fehlschlägt (Archiv bleibt dann HTML-only).
     */
    public function htmlToPdf(string $html_file, string $pdf_file): bool
    {
        $bin = self::findWkhtmltopdf();
        if ($bin === null || !function_exists('exec')) {
            return false;
        }

        $cmd = escapeshellcmd($bin)
             . ' --quiet --encoding utf-8'
             . ' --margin-top 15 --margin-bottom 15 --margin-left 15 --margin-right 15'
             . ' ' . escapeshellarg($html_file)
             . ' ' . escapeshellarg($pdf_file)
             . ' 2>&1';

        $out = [];
        $rc  = 1;
        exec($cmd, $out, $rc);

        if ($rc !== 0 || !is_file($pdf_file) || filesize($pdf_file) === 0) {
            error_log('[ExamArchiver] wkhtmltopdf rc=' . $rc . ': ' . implode(' | ', array_slice($out, 0, 5)));
            @unlink($pdf_file);
            return false;
        }
        return true;
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────────

    /**
     * Bettet lokale Bilder als data-URIs ein, damit das Archiv offline lesbar bleibt.
     * Externe URLs bleiben unverändert.
     */
    public function inlineImages(string $html): string
    {
        return preg_replace_callback(
            '/(<img\b[^>]*\bsrc=")([^"]+)(")/i',
            function (array $m): string {
                $src = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');

                if (str_starts_with($src, 'data:')) {
                    return $m[0];
                }

                $rel = null;
                if (str_starts_with($src, ILIAS_HTTP_PATH . '/')) {
                    $rel = substr($src, strlen(ILIAS_HTTP_PATH) + 1);
                } elseif (preg_match('#^https?://#i', $src)) {
                    return $m[0]; // fremde Hosts nicht anfassen
                } elseif (str_starts_with($src, '/')) {
                    $base = (string) parse_url(ILIAS_HTTP_PATH, PHP_URL_PATH);
                    if ($base !== '' && str_starts_with($src, $base . '/')) {
                        $rel = substr($src, strlen($base) + 1);
                    } else {
                        return $m[0];
                    }
                } else {
                    $rel = ltrim($src, './');
                }

                $rel = strtok($rel, '?'); // Query-String abschneiden
                if ($rel === false || $rel === '' || str_contains($rel, '..')) {
                    return $m[0];
                }

                // Webroot: ILIAS 10 = <root>/public/, ILIAS 9 = <root>/
                $path = ILIAS_ABSOLUTE_PATH . '/public/' . $rel;
                if (!is_file($path)) {
                    $path = ILIAS_ABSOLUTE_PATH . '/' . $rel;
                }
                if (!is_file($path) || filesize($path) > self::MAX_INLINE_IMAGE_BYTES) {
                    return $m[0];
                }

                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mime = [
                    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
                ][$ext] ?? null;
                if ($mime === null) {
                    return $m[0];
                }

                return $m[1] . 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path)) . $m[3];
            },
            $html
        ) ?? $html;
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function footer(array $meta): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        return '<footer>' . $e($this->lng->txt('gen_footer')) . ': '
             . 'ExamArchiver v' . $e($meta['module_version'] ?? '?')
             . ' · ' . $e($this->lng->txt('gen_archive')) . ' #' . $e($meta['archive_id'] ?? '?')
             . ' · ' . $e(date('d.m.Y H:i:s', (int) ($meta['created_at'] ?? time())))
             . ' · ' . $e($this->lng->txt('gen_by')) . ' ' . $e($meta['created_by_login'] ?? '?')
             . ' · ' . $e($meta['platform'] ?? '')
             . '</footer>';
    }

    private function htmlHead(string $title): string
    {
        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
             . '<meta name="viewport" content="width=device-width, initial-scale=1">'
             . '<title>' . $title . '</title>'
             . '<style>' . $this->css() . '</style></head>';
    }

    private function css(): string
    {
        return <<<CSS
* { box-sizing: border-box; }
body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; margin: 0; background: #f4f5f7; color: #222; }
.wrap { max-width: 1100px; margin: 0 auto; padding: 24px; }
header { border-bottom: 3px solid #16457c; margin-bottom: 20px; padding-bottom: 10px; }
h1 { font-size: 22px; margin: 0 0 4px; color: #16457c; }
h2 { font-size: 16px; margin: 0; font-weight: normal; color: #555; }
h3 { font-size: 15px; margin: 24px 0 8px; color: #16457c; }
.cols { display: flex; gap: 32px; flex-wrap: wrap; }
.cols > div { flex: 1 1 320px; }
table.kv { border-collapse: collapse; font-size: 13px; }
table.kv th { text-align: left; padding: 3px 14px 3px 0; color: #666; font-weight: 600; vertical-align: top; white-space: nowrap; }
table.kv td { padding: 3px 0; }
table.list { border-collapse: collapse; width: 100%; font-size: 13px; background: #fff; }
table.list th, table.list td { border: 1px solid #ddd; padding: 5px 8px; text-align: left; }
table.list th { background: #eef2f7; cursor: pointer; user-select: none; white-space: nowrap; }
table.raw { border-collapse: collapse; font-size: 12px; background: #fff; }
table.raw th, table.raw td { border: 1px solid #ddd; padding: 3px 6px; }
table.dist { border-collapse: collapse; font-size: 13px; width: 60%; min-width: 320px; }
table.dist th { text-align: right; padding-right: 10px; width: 90px; font-weight: 600; }
table.dist td { padding: 2px 0; }
table.dist td.cnt { padding-left: 8px; width: 40px; color: #555; }
.bar { background: #16457c; border-radius: 2px; min-width: 2px; height: 14px; }
#q { margin: 6px 0 10px; padding: 6px 10px; width: 320px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
.result { margin: 16px 0; padding: 10px 14px; border-radius: 5px; font-size: 14px; }
.result.passed  { background: #e6f4e6; border: 1px solid #9dc99d; }
.result.failed  { background: #fbeaea; border: 1px solid #dda6a6; }
.result.neutral { background: #f0f0f0; border: 1px solid #ccc; }
section.pass { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 14px 18px; margin: 18px 0; }
section.pass.scored { border-color: #16457c; box-shadow: 0 0 0 1px #16457c; }
.badge { background: #16457c; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 8px; vertical-align: middle; }
.passmeta { color: #666; font-size: 12px; margin: 4px 0 12px; }
.question { border-top: 1px solid #e5e5e5; padding: 12px 0; }
.qhead { display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; margin-bottom: 8px; }
.qnr { font-weight: 700; color: #16457c; white-space: nowrap; }
.qtitle { font-weight: 600; flex: 1; }
.qpoints { color: #444; font-size: 13px; white-space: nowrap; }
.qblock { margin: 8px 0 8px 12px; }
.qlabel { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #888; margin-bottom: 4px; }
.qblock.feedback { background: #fff8e1; border: 1px solid #e6d9a0; border-radius: 4px; padding: 8px 12px; }
details.best summary { font-size: 12px; color: #16457c; cursor: pointer; margin: 4px 0; }
.ilias-q { background: #fafafa; border: 1px solid #eee; border-radius: 4px; padding: 10px 12px; overflow-x: auto; }
.ilias-q img { max-width: 100%; height: auto; }
.muted { color: #999; font-size: 13px; }
footer { margin-top: 32px; padding-top: 10px; border-top: 1px solid #ccc; font-size: 11px; color: #888; }
@media print {
  body { background: #fff; }
  details.best { display: block; }
  details.best[open], details.best { open: true; }
  #q { display: none; }
}
CSS;
    }

    private function indexJs(): string
    {
        return <<<'JS'
<script>
function filterRows() {
    var q = document.getElementById('q').value.toLowerCase();
    var rows = document.querySelectorAll('#plist tbody tr');
    rows.forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none';
    });
}
var sortState = { col: -1, asc: true };
function sortBy(col) {
    var tbody = document.querySelector('#plist tbody');
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    sortState.asc = sortState.col === col ? !sortState.asc : true;
    sortState.col = col;
    rows.sort(function (a, b) {
        var ca = a.cells[col], cb = b.cells[col];
        var va = ca.getAttribute('data-v') !== null ? parseFloat(ca.getAttribute('data-v')) || 0 : ca.textContent.trim().toLowerCase();
        var vb = cb.getAttribute('data-v') !== null ? parseFloat(cb.getAttribute('data-v')) || 0 : cb.textContent.trim().toLowerCase();
        if (va < vb) return sortState.asc ? -1 : 1;
        if (va > vb) return sortState.asc ? 1 : -1;
        return 0;
    });
    rows.forEach(function (tr) { tbody.appendChild(tr); });
}
</script>
JS;
    }
}
