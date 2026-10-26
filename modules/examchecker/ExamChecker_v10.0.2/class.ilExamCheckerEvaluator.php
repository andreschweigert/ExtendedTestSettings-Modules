<?php

/**
 * ExamChecker Evaluator - Ist-Werte lesen und gegen die Soll-Werte prüfen
 * Part of ExtendedTestSettings Plugin
 *
 * Liest ausschließlich ILIAS-Kerntabellen. Jeder Check liefert eines von drei
 * Ergebnissen (Regel R10): ok, failed oder unknown. "unknown" heißt: der Wert war
 * technisch nicht ermittelbar — etwa weil die Spalte in dieser ILIAS-Version
 * fehlt. Ein solcher Fall darf nie als bestanden durchgehen.
 */

class ilExamCheckerEvaluator
{
    /** ilObjectActivation::TIMINGS_ACTIVATION — Verfügbarkeit ist zeitlich begrenzt. */
    private const TIMINGS_ACTIVATION = 0;

    private ilDBInterface $db;
    private ilModuleLanguage $lng;

    /** Details der gefundenen Vorbedingungen, für die Ergebnisdarstellung. */
    private array $condition_details = [];

    public function __construct(ilModuleLanguage $lng, ?ilDBInterface $db = null)
    {
        if ($db === null) {
            global $DIC;
            $db = $DIC->database();
        }
        $this->db  = $db;
        $this->lng = $lng;
    }

    /**
     * Führt alle konfigurierten Checks eines Prüfungstyps durch.
     *
     * @return array{
     *     exam_type: string,
     *     checks: array<string,array>,
     *     conditions: array,
     *     summary: array{ok:int,failed:int,unknown:int,info:int}
     * }
     * @throws RuntimeException wenn ref_id kein Test ist oder keine Testdaten existieren
     */
    public function run(int $ref_id, string $exam_type): array
    {
        $this->condition_details = [];

        $obj_id = ilObject::_lookupObjectId($ref_id);
        if ($obj_id <= 0 || ilObject::_lookupType($obj_id) !== 'tst') {
            throw new RuntimeException($this->lng->txt('err_not_a_test'));
        }

        $test_data = $this->loadTestRow($obj_id);
        if ($test_data === null) {
            throw new RuntimeException($this->lng->txt('err_no_test_data'));
        }

        $ctx = [
            'ref_id'    => $ref_id,
            'obj_id'    => $obj_id,
            'test_data' => $test_data,
        ];

        $rules   = (new ilExamCheckerRules($this->db))->forExamType($exam_type);
        $checks  = [];
        $summary = ['ok' => 0, 'failed' => 0, 'unknown' => 0, 'info' => 0];

        foreach (ilExamCheckerDefinitions::forExamType($exam_type) as $key => $def) {
            if (empty($rules[$key]['enabled'])) {
                continue;
            }

            $actual = $this->readActual($key, $def, $ctx);
            $is_info = $def['type'] === ilExamCheckerDefinitions::TYPE_INFO;

            if ($actual['value'] === null && !$is_info) {
                $status = ilExamCheckerDefinitions::RESULT_UNKNOWN;
            } elseif ($is_info) {
                $status = 'info';
            } else {
                $status = $this->compare($actual['value'], $rules[$key]['expected'], $def)
                    ? ilExamCheckerDefinitions::RESULT_OK
                    : ilExamCheckerDefinitions::RESULT_FAILED;
            }

            $checks[$key] = [
                'key'      => $key,
                'group'    => $def['group'],
                'title'    => $this->lng->txt('chk_' . $key),
                'hint'     => $this->lng->txt('chk_' . $key . '_info', ''),
                'status'   => $status,
                'actual'   => $actual['label'],
                'expected' => $is_info ? '' : $this->describe($rules[$key]['expected'], $def),
            ];

            $summary[$status]++;
        }

        return [
            'exam_type'  => $exam_type,
            'checks'     => $checks,
            'conditions' => $this->condition_details,
            'summary'    => $summary,
        ];
    }

    // ─── Ist-Wert ermitteln ──────────────────────────────────────────────────

    /**
     * @return array{value:mixed,label:string}
     */
    private function readActual(string $key, array $def, array $ctx): array
    {
        try {
            if (($def['source'] ?? '') === 'custom') {
                $method = $def['evaluator'] ?? '';
                if ($method === '' || !method_exists($this, $method)) {
                    return $this->unknown();
                }
                return $this->{$method}($ctx);
            }

            $column = $def['column'] ?? '';

            // Spalte fehlt in dieser ILIAS-Version: nicht prüfbar statt "nicht erfüllt".
            if ($column === '' || !array_key_exists($column, $ctx['test_data'])) {
                return $this->unknown();
            }

            $raw   = $ctx['test_data'][$column];
            $value = $this->castRaw($raw, $def);

            return ['value' => $value, 'label' => $this->describe($value, $def)];
        } catch (Exception $e) {
            error_log("[ExamChecker] Check '$key' fehlgeschlagen: " . $e->getMessage());
            return $this->unknown();
        }
    }

    /**
     * Rohwert der Spalte in den Typ der Definition überführen. Die Spalten in
     * tst_tests sind uneinheitlich typisiert (varchar(1), tinyint, int), deshalb
     * wird hier konsequent gecastet statt verglichen.
     *
     * @return mixed
     */
    private function castRaw($raw, array $def)
    {
        switch ($def['type']) {
            case ilExamCheckerDefinitions::TYPE_BOOL:
                $value = ((int) $raw) === 1;
                return !empty($def['invert']) ? !$value : $value;

            case ilExamCheckerDefinitions::TYPE_INT:
            case ilExamCheckerDefinitions::TYPE_ENUM:
                return (int) $raw;

            default:
                return trim((string) $raw);
        }
    }

    /**
     * Ist- gegen Soll-Wert. Beide Seiten sind zu diesem Zeitpunkt typisiert.
     */
    private function compare($actual, $expected, array $def): bool
    {
        switch ($def['type']) {
            case ilExamCheckerDefinitions::TYPE_BOOL:
                return (bool) $actual === (bool) $expected;

            case ilExamCheckerDefinitions::TYPE_INT:
                // Der Soll-Wert steht in der Einheit der Konfiguration
                // (z. B. Sekunden), die Spalte in Millisekunden.
                $factor = (int) ($def['factor'] ?? 1);
                return (int) $actual === ((int) $expected * $factor);

            case ilExamCheckerDefinitions::TYPE_ENUM:
                return (int) $actual === (int) $expected;

            case ilExamCheckerDefinitions::TYPE_TEXT:
                return trim((string) $actual) === trim((string) $expected);

            default:
                return true;
        }
    }

    /**
     * Menschenlesbare Fassung eines Werts — für "Ist" wie für "Soll".
     */
    private function describe($value, array $def): string
    {
        if ($value === null) {
            return $this->lng->txt('val_unknown');
        }

        switch ($def['type']) {
            case ilExamCheckerDefinitions::TYPE_BOOL:
                return $this->lng->txt($value ? 'val_yes' : 'val_no');

            case ilExamCheckerDefinitions::TYPE_INT:
                $factor = (int) ($def['factor'] ?? 1);
                $shown  = $factor > 1 ? (int) round(((int) $value) / $factor) : (int) $value;
                $unit   = isset($def['unit']) ? ' ' . $this->lng->txt($def['unit']) : '';
                return $shown . $unit;

            case ilExamCheckerDefinitions::TYPE_ENUM:
                $opt = $def['options'][(int) $value] ?? null;
                return $opt !== null
                    ? $this->lng->txt($opt)
                    : sprintf($this->lng->txt('val_unknown_option'), (int) $value);

            case ilExamCheckerDefinitions::TYPE_TEXT:
                return ((string) $value) === ''
                    ? $this->lng->txt('val_empty')
                    : (string) $value;

            default:
                return (string) $value;
        }
    }

    /**
     * Ergebnis für einen Wert, der technisch nicht ermittelbar war — etwa weil
     * die Spalte in dieser ILIAS-Version fehlt. Führt zu "unknown", nie zu "ok".
     *
     * @return array{value:null,label:string}
     */
    private function unknown(): array
    {
        return ['value' => null, 'label' => $this->lng->txt('val_unknown')];
    }

    // ─── Evaluatoren für Werte außerhalb von tst_tests ───────────────────────

    /**
     * Fragenpools: Bei Zufallstests immer erfüllt. Bei festem Fragenset zählt,
     * ob alle Fragen aus einem Pool kopiert wurden (qpl_questions.original_id).
     * Die Spalte tst_tests.pool_usage wird von ILIAS 9 nicht mehr gepflegt.
     */
    private function evalUsesQuestionPools(array $ctx): array
    {
        $set_type = (string) ($ctx['test_data']['question_set_type'] ?? '');
        if ($set_type === 'RANDOM_QUEST_SET') {
            return ['value' => true, 'label' => $this->lng->txt('val_random_pool')];
        }

        $res = $this->db->queryF(
            'SELECT COUNT(q.question_id) AS total,'
            . ' SUM(CASE WHEN q.original_id IS NULL THEN 0 ELSE 1 END) AS from_pool'
            . ' FROM tst_test_question tq'
            . ' JOIN qpl_questions q ON q.question_id = tq.question_fi'
            . ' WHERE tq.test_fi = %s',
            ['integer'],
            [(int) ($ctx['test_data']['test_id'] ?? 0)]
        );

        $row       = $this->db->fetchAssoc($res);
        $total     = (int) ($row['total'] ?? 0);
        $from_pool = (int) ($row['from_pool'] ?? 0);

        if ($total === 0) {
            return ['value' => null, 'label' => $this->lng->txt('val_no_questions')];
        }

        return [
            'value' => $from_pool === $total,
            'label' => sprintf($this->lng->txt('val_pool_ratio'), $from_pool, $total),
        ];
    }

    /**
     * Online-Status: seit ILIAS 9 in object_data.offline (invertiert).
     * tst_tests.online_status wird nicht mehr gepflegt.
     */
    private function evalIsOnline(array $ctx): array
    {
        $res = $this->db->queryF(
            'SELECT offline FROM object_data WHERE obj_id = %s',
            ['integer'],
            [$ctx['obj_id']]
        );

        $row = $this->db->fetchAssoc($res);
        if ($row === null || $row === false) {
            return $this->unknown();
        }

        $online = ((int) $row['offline']) === 0;
        return ['value' => $online, 'label' => $this->lng->txt($online ? 'val_yes' : 'val_no')];
    }

    /**
     * Zeitlich begrenzte Verfügbarkeit (ilObjectActivation, Tabelle crs_items).
     * Maßgeblich ist timing_type — timing_start/timing_end bleiben als Altwerte
     * stehen, auch wenn die Begrenzung längst deaktiviert ist.
     */
    private function evalActivationLimited(array $ctx): array
    {
        $res = $this->db->queryF(
            'SELECT timing_type, timing_start, timing_end FROM crs_items WHERE obj_id = %s',
            ['integer'],
            [$ctx['ref_id']]
        );

        while ($row = $this->db->fetchAssoc($res)) {
            if ((int) $row['timing_type'] !== self::TIMINGS_ACTIVATION) {
                continue;
            }

            return [
                'value' => true,
                'label' => sprintf(
                    $this->lng->txt('val_period'),
                    $this->formatTs((int) $row['timing_start']),
                    $this->formatTs((int) $row['timing_end'])
                ),
            ];
        }

        return ['value' => false, 'label' => $this->lng->txt('val_not_limited')];
    }

    /**
     * Vorbedingungen für den Testzugang. Gefiltert wird über die konkrete
     * target_ref_id — genau so schlägt ILIAS Vorbedingungen selbst nach.
     */
    private function evalHasConditions(array $ctx): array
    {
        $res = $this->db->queryF(
            'SELECT c.trigger_obj_id, c.operator, c.value, od.title AS trigger_title,'
            . ' od.type AS trigger_type'
            . ' FROM conditions c'
            . ' LEFT JOIN object_data od ON od.obj_id = c.trigger_obj_id'
            . ' WHERE c.target_ref_id = %s',
            ['integer'],
            [$ctx['ref_id']]
        );

        while ($row = $this->db->fetchAssoc($res)) {
            $this->condition_details[] = $this->formatCondition($row);
        }

        $count = count($this->condition_details);

        return [
            'value' => $count > 0,
            'label' => $count > 0
                ? sprintf($this->lng->txt('val_conditions_count'), $count)
                : $this->lng->txt('val_no_conditions'),
        ];
    }

    /**
     * Prüfungsansicht: kiosk ist eine Bitmaske
     * (1 = aktiv, +2 Testtitel, +4 Teilnehmername).
     */
    private function evalKioskEnabled(array $ctx): array
    {
        if (!array_key_exists('kiosk', $ctx['test_data'])) {
            return $this->unknown();
        }

        $kiosk   = (int) $ctx['test_data']['kiosk'];
        $enabled = ($kiosk & 1) === 1;

        if (!$enabled) {
            return ['value' => false, 'label' => $this->lng->txt('val_no')];
        }

        $extras = [];
        if (($kiosk & 2) > 0) {
            $extras[] = $this->lng->txt('val_kiosk_title');
        }
        if (($kiosk & 4) > 0) {
            $extras[] = $this->lng->txt('val_kiosk_participant');
        }

        return [
            'value' => true,
            'label' => $this->lng->txt('val_yes')
                . ($extras ? ' (' . implode(', ', $extras) . ')' : ''),
        ];
    }

    /**
     * Wartezeit zwischen Durchläufen. pass_waiting ist "Tage:Stunden:Minuten"
     * (ältere Stände zusätzlich mit führenden Monaten); NULL bedeutet "aus".
     * Ein gespeichertes "0:0:0" ist ebenfalls keine Wartezeit.
     */
    private function evalPassWaiting(array $ctx): array
    {
        $raw = trim((string) ($ctx['test_data']['pass_waiting'] ?? ''));

        if ($raw === '') {
            return ['value' => false, 'label' => $this->lng->txt('val_no')];
        }

        $parts = array_map('intval', explode(':', $raw));
        if (array_sum($parts) === 0) {
            return ['value' => false, 'label' => $this->lng->txt('val_no')];
        }

        return ['value' => true, 'label' => $raw];
    }

    /**
     * Prüfungszeitraum — nur zur Anzeige, kein Soll-Wert.
     */
    private function evalExamPeriod(array $ctx): array
    {
        $data  = $ctx['test_data'];
        $start = ((int) ($data['starting_time_enabled'] ?? 0)) === 1
            ? $this->formatTs((int) ($data['starting_time'] ?? 0))
            : $this->lng->txt('val_not_set');
        $end   = ((int) ($data['ending_time_enabled'] ?? 0)) === 1
            ? $this->formatTs((int) ($data['ending_time'] ?? 0))
            : $this->lng->txt('val_not_set');

        return [
            'value' => true,
            'label' => sprintf($this->lng->txt('val_period'), $start, $end),
        ];
    }

    /**
     * Bearbeitungsdauer in Minuten — nur zur Anzeige.
     * processing_time ist "HH:MM:SS", nicht etwa eine Minutenzahl.
     */
    private function evalProcessingMinutes(array $ctx): array
    {
        $data = $ctx['test_data'];

        if (((int) ($data['enable_processing_time'] ?? 0)) !== 1) {
            return ['value' => true, 'label' => $this->lng->txt('val_not_limited')];
        }

        $minutes = $this->processingTimeToMinutes((string) ($data['processing_time'] ?? ''));
        if ($minutes === null) {
            return ['value' => true, 'label' => $this->lng->txt('val_unknown')];
        }

        return [
            'value' => true,
            'label' => $minutes . ' ' . $this->lng->txt('unit_minutes'),
        ];
    }

    // ─── Hilfsfunktionen ─────────────────────────────────────────────────────

    private function loadTestRow(int $obj_id): ?array
    {
        $res = $this->db->queryF(
            'SELECT * FROM tst_tests WHERE obj_fi = %s',
            ['integer'],
            [$obj_id]
        );

        $row = $this->db->fetchAssoc($res);
        return ($row === null || $row === false) ? null : $row;
    }

    /** "HH:MM:SS" -> Minuten; null wenn das Format nicht passt. */
    private function processingTimeToMinutes(string $raw): ?int
    {
        if (!preg_match('/^(\d{1,3}):(\d{2}):(\d{2})$/', trim($raw), $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    private function formatTs(int $timestamp): string
    {
        return $timestamp > 0
            ? date('d.m.Y H:i', $timestamp)
            : $this->lng->txt('val_not_set');
    }

    private function formatCondition(array $row): string
    {
        $type_key = 'ctype_' . preg_replace('/[^a-z]/', '', strtolower((string) ($row['trigger_type'] ?? '')));
        $type     = $this->lng->txt($type_key, (string) ($row['trigger_type'] ?? ''));
        $title    = (string) ($row['trigger_title'] ?? $this->lng->txt('cond_unknown'));
        $operator = (string) ($row['operator'] ?? '');
        $value    = (string) ($row['value'] ?? '');

        if ($operator === 'passed') {
            return sprintf($this->lng->txt('cond_must_pass'), $type, $title);
        }
        if ($operator === 'finished') {
            return sprintf($this->lng->txt('cond_must_finish'), $type, $title);
        }
        if ($operator !== '' && $value !== '') {
            return sprintf($this->lng->txt('cond_op_value'), $type, $title, $operator, $value);
        }
        return sprintf($this->lng->txt('cond_op_only'), $type, $title, $operator);
    }
}
