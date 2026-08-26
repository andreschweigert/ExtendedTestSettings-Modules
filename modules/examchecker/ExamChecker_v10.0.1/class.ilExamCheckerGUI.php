<?php

/**
 * ExamChecker GUI - AJAX-Endpunkt für die Einstellungsprüfung
 * Part of ExtendedTestSettings Plugin
 *
 * Wird über ilExtendedTestSettingsModuleProxyGUI geroutet, NICHT direkt in
 * ilCtrl registriert.
 *
 * Kommandos:
 *   cmd=check   POST — führt die Prüfung durch, liefert HTML als JSON zurück
 *
 * Der Proxy hat zu diesem Zeitpunkt bereits sichergestellt: Modul aktiv,
 * ref_id ist ein Test, Aufrufer hat Schreibrecht darauf. Der Endpunkt selbst
 * erzwingt zusätzlich POST + Sessiontoken (Regel R4) und arbeitet
 * ausschließlich im ref_id-Scope (Regel R1).
 */

class ilExamCheckerGUI
{
    private ilModuleLanguage $lng;

    public function __construct()
    {
        require_once dirname(__DIR__, 2) . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    public function executeCommand(): void
    {
        $cmd = strtolower((string) ($_REQUEST['cmd'] ?? ''));

        if ($cmd === 'check') {
            $this->check();
            return;
        }

        $this->jsonOut(['error' => 'unknown_command'], 400);
    }

    // ─── cmd=check ───────────────────────────────────────────────────────────

    private function check(): void
    {
        $this->beginJson();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !ilExamCheckerToken::isValid((string) ($_POST['examchecker_token'] ?? ''))
        ) {
            $this->jsonOut(['error' => 'forbidden'], 403);
        }

        // Einziger Scope ist die vom Proxy autorisierte ref_id.
        $ref_id = (int) ($_GET['ref_id'] ?? 0);
        if ($ref_id <= 0) {
            $this->jsonOut(['error' => 'no_ref_id'], 400);
        }

        $exam_type = (string) ($_POST['exam_type'] ?? 'praesenz');
        if (!in_array($exam_type, ['praesenz', 'fern'], true)) {
            $exam_type = 'praesenz';
        }

        try {
            $result = (new ilExamCheckerEvaluator($this->lng))->run($ref_id, $exam_type);
            $this->jsonOut([
                'success' => true,
                'html'    => $this->renderResults($result),
            ]);
        } catch (Throwable $e) {
            error_log('[ExamChecker] Prüfung fehlgeschlagen: ' . $e->getMessage());
            $this->jsonOut([
                'success' => false,
                'html'    => $this->renderError($e->getMessage()),
            ]);
        }
    }

    // ─── Ergebnisdarstellung ─────────────────────────────────────────────────

    private function renderResults(array $result): string
    {
        $summary = $result['summary'];
        $graded  = $summary['ok'] + $summary['failed'] + $summary['unknown'];

        $html  = '<div class="examchecker-results">';
        $html .= $this->renderSummary($summary, $graded);

        if ($graded === 0 && $summary['info'] === 0) {
            $html .= '<div class="ec-box ec-box-warn">'
                . '<strong>' . $this->t('no_standards_title') . '</strong>'
                . '<p>' . $this->t('no_standards_text') . '</p>'
                . '</div></div>';
            return $html;
        }

        $by_group = [];
        foreach ($result['checks'] as $check) {
            $by_group[$check['group']][] = $check;
        }

        foreach (ilExamCheckerDefinitions::GROUPS as $group) {
            if (empty($by_group[$group])) {
                continue;
            }

            $html .= '<div class="ec-group">';
            $html .= '<h4>' . $this->esc($this->lng->txt('grp_' . $group)) . '</h4>';
            $html .= '<table class="ec-table"><thead><tr>'
                . '<th class="ec-col-status">&nbsp;</th>'
                . '<th>' . $this->t('col_setting') . '</th>'
                . '<th>' . $this->t('col_actual') . '</th>'
                . '<th>' . $this->t('col_expected') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($by_group[$group] as $check) {
                $html .= $this->renderRow($check);
            }

            $html .= '</tbody></table></div>';
        }

        if (!empty($result['conditions'])) {
            $html .= $this->renderConditions($result['conditions']);
        }

        $html .= '</div>';
        return $html;
    }

    private function renderSummary(array $summary, int $graded): string
    {
        $percentage = $graded > 0 ? (int) round(($summary['ok'] / $graded) * 100) : 0;

        if ($summary['unknown'] > 0 || $percentage < 70) {
            $level = 'bad';
        } elseif ($percentage < 100) {
            $level = 'warn';
        } else {
            $level = 'good';
        }

        // Unescaped zusammenbauen, einmal am Ende escapen — sonst doppelt kodiert.
        $parts = [
            sprintf($this->lng->txt('res_ok'), $summary['ok']),
            sprintf($this->lng->txt('res_failed'), $summary['failed']),
        ];
        if ($summary['unknown'] > 0) {
            $parts[] = sprintf($this->lng->txt('res_unknown'), $summary['unknown']);
        }
        if ($summary['info'] > 0) {
            $parts[] = sprintf($this->lng->txt('res_info'), $summary['info']);
        }

        return '<div class="ec-summary ec-' . $level . '">'
            . '<span class="ec-summary-value">' . $percentage . '%</span>'
            . '<span class="ec-summary-text">' . $this->esc(implode(' · ', $parts)) . '</span>'
            . '</div>';
    }

    private function renderRow(array $check): string
    {
        $icons = [
            'ok'      => '✓',
            'failed'  => '✗',
            'unknown' => '?',
            'info'    => 'i',
        ];

        $status = (string) $check['status'];
        $title  = '<strong>' . $this->esc($check['title']) . '</strong>';

        if ($check['hint'] !== '') {
            $title .= '<br><span class="ec-hint">' . $this->esc($check['hint']) . '</span>';
        }

        return '<tr class="ec-row ec-row-' . $this->esc($status) . '">'
            . '<td class="ec-col-status"><span class="ec-badge ec-badge-' . $this->esc($status) . '"'
            . ' title="' . $this->esc($this->lng->txt('status_' . $status)) . '">'
            . ($icons[$status] ?? '?') . '</span></td>'
            . '<td>' . $title . '</td>'
            . '<td>' . $this->esc($check['actual']) . '</td>'
            . '<td>' . ($check['expected'] === '' ? '—' : $this->esc($check['expected'])) . '</td>'
            . '</tr>';
    }

    private function renderConditions(array $conditions): string
    {
        $html  = '<div class="ec-box ec-box-warn">';
        $html .= '<strong>' . $this->t('cond_found') . '</strong>';
        $html .= '<p>' . sprintf($this->t('cond_count'), count($conditions)) . '</p>';
        $html .= '<ul>';
        foreach ($conditions as $description) {
            $html .= '<li>' . $this->esc((string) $description) . '</li>';
        }
        $html .= '</ul>';
        $html .= '<p class="ec-advice">' . $this->t('cond_advice') . '</p>';
        $html .= '</div>';

        return $html;
    }

    private function renderError(string $message): string
    {
        return '<div class="ec-box ec-box-error">'
            . '<strong>' . $this->t('err_label') . '</strong> '
            . $this->esc($message)
            . '</div>';
    }

    // ─── Hilfsfunktionen ─────────────────────────────────────────────────────

    private function beginJson(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
    }

    /** @return never */
    private function jsonOut(array $payload, int $http_code = 200): void
    {
        http_response_code($http_code);
        echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    /** Übersetzt und escaped in einem Schritt — für festen Text ohne Nutzeranteil. */
    private function t(string $key): string
    {
        return $this->esc($this->lng->txt($key));
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
