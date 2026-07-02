<?php
/**
 * ExamArchiver Collector - Liest alle archivierungsrelevanten Testdaten aus der ILIAS-DB
 * Part of ExtendedTestSettings Plugin
 *
 * Reine Leseklasse: tst_tests, tst_mark, tst_active, tst_pass_result, tst_result_cache,
 * tst_test_result, tst_solutions, tst_manual_fb, tst_times, tst_test_question,
 * tst_test_rnd_qst, qpl_questions, usr_data.
 */

class ilExamArchiverCollector
{
    /**
     * Test-Stammdaten: tst_tests-Snapshot, Notenschlüssel, Fragenliste (bei festem Fragenset).
     */
    public function getTestData(int $obj_id, int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $res = $db->queryF("SELECT * FROM tst_tests WHERE obj_fi = %s", ['integer'], [$obj_id]);
        $test = $db->fetchAssoc($res);
        if (!$test) {
            throw new Exception('Test nicht gefunden (obj_id ' . $obj_id . ')');
        }

        $title = '';
        $descr = '';
        $res = $db->queryF("SELECT title, description FROM object_data WHERE obj_id = %s", ['integer'], [$obj_id]);
        if ($row = $db->fetchAssoc($res)) {
            $title = (string) $row['title'];
            $descr = (string) $row['description'];
        }

        // Notenschlüssel
        $marks = [];
        $res = $db->queryF(
            "SELECT short_name, official_name, minimum_level, passed
             FROM tst_mark WHERE test_fi = %s ORDER BY minimum_level DESC",
            ['integer'],
            [(int) $test['test_id']]
        );
        while ($row = $db->fetchAssoc($res)) {
            $marks[] = [
                'short_name'    => (string) $row['short_name'],
                'official_name' => (string) $row['official_name'],
                'minimum_level' => (float) $row['minimum_level'],
                'passed'        => (string) $row['passed'] === '1',
            ];
        }

        // Fragen des festen Fragensets (Zufallstests: Fragen kommen pro Teilnehmer aus tst_test_rnd_qst)
        $fixed_questions = [];
        $res = $db->queryF(
            "SELECT tq.sequence, tq.question_fi, q.title, q.points, qt.type_tag
             FROM tst_test_question tq
             JOIN qpl_questions q ON q.question_id = tq.question_fi
             LEFT JOIN qpl_qst_type qt ON qt.question_type_id = q.question_type_fi
             WHERE tq.test_fi = %s ORDER BY tq.sequence",
            ['integer'],
            [(int) $test['test_id']]
        );
        while ($row = $db->fetchAssoc($res)) {
            $fixed_questions[] = [
                'sequence'    => (int) $row['sequence'],
                'question_id' => (int) $row['question_fi'],
                'title'       => (string) $row['title'],
                'max_points'  => (float) $row['points'],
                'type'        => (string) ($row['type_tag'] ?? ''),
            ];
        }

        return [
            'obj_id'          => $obj_id,
            'ref_id'          => $ref_id,
            'test_id'         => (int) $test['test_id'],
            'title'           => $title,
            'description'     => $descr,
            'question_set'    => (string) ($test['question_set_type'] ?? 'FIXED_QUEST_SET'),
            'settings'        => $this->extractSettings($test),
            'marks'           => $marks,
            'fixed_questions' => $fixed_questions,
        ];
    }

    /**
     * Lesbarer Einstellungs-Snapshot aus der tst_tests-Zeile (Rohwerte zusätzlich im JSON).
     */
    private function extractSettings(array $t): array
    {
        return [
            'nr_of_tries'            => (int) $t['nr_of_tries'],
            'processing_time'        => (string) ($t['processing_time'] ?? ''),
            'enable_processing_time' => (string) ($t['enable_processing_time'] ?? '0') === '1',
            'starting_time'          => !empty($t['starting_time_enabled']) && !empty($t['starting_time'])
                                        ? (int) $t['starting_time'] : 0,
            'ending_time'            => !empty($t['ending_time_enabled']) && !empty($t['ending_time'])
                                        ? (int) $t['ending_time'] : 0,
            'shuffle_questions'      => (string) ($t['shuffle_questions'] ?? '0') === '1',
            'question_set_type'      => (string) ($t['question_set_type'] ?? ''),
            'pass_scoring'           => (string) ($t['pass_scoring'] ?? '0'), // 0=letzter, 1=bester Durchlauf
            'count_system'           => (string) ($t['count_system'] ?? '0'),
            'score_cutting'          => (string) ($t['score_cutting'] ?? '0'),
            'mc_scoring'             => (string) ($t['mc_scoring'] ?? '0'),
            'anonymity'              => (string) ($t['anonymity'] ?? '0') === '1',
            'fixed_participants'     => (string) ($t['fixed_participants'] ?? '0') === '1',
            'password_enabled'       => !empty($t['password_enabled']),
            'nr_of_tries_raw'        => $t['nr_of_tries'],
            'created'                => (int) ($t['created'] ?? 0),
        ];
    }

    /**
     * Alle Teilnehmer (tst_active) eines Tests, deterministisch sortiert.
     * @return int[] active_ids
     */
    public function getActiveIds(int $test_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $ids = [];
        $res = $db->queryF(
            "SELECT active_id FROM tst_active WHERE test_fi = %s ORDER BY active_id",
            ['integer'],
            [$test_id]
        );
        while ($row = $db->fetchAssoc($res)) {
            $ids[] = (int) $row['active_id'];
        }
        return $ids;
    }

    /**
     * Kompletter Datensatz eines Teilnehmers: Nutzer, Durchläufe, Ergebnisse, Roh-Antworten.
     */
    public function getParticipantData(array $test, int $active_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $res = $db->queryF(
            "SELECT a.*, u.login, u.firstname, u.lastname, u.matriculation, u.email
             FROM tst_active a
             LEFT JOIN usr_data u ON u.usr_id = a.user_fi
             WHERE a.active_id = %s",
            ['integer'],
            [$active_id]
        );
        $active = $db->fetchAssoc($res);
        if (!$active) {
            throw new Exception('active_id ' . $active_id . ' nicht gefunden');
        }

        // Gewertetes Endergebnis (tst_result_cache; enthält auch den gewerteten Durchlauf)
        $result = null;
        $res = $db->queryF(
            "SELECT pass, max_points, reached_points, mark_short, mark_official, passed
             FROM tst_result_cache WHERE active_fi = %s",
            ['integer'],
            [$active_id]
        );
        if ($row = $db->fetchAssoc($res)) {
            $result = [
                'scored_pass'    => (int) $row['pass'],
                'max_points'     => (float) $row['max_points'],
                'reached_points' => (float) $row['reached_points'],
                'mark_short'     => (string) $row['mark_short'],
                'mark_official'  => (string) $row['mark_official'],
                'passed'         => (int) $row['passed'] === 1,
            ];
        }

        // Durchläufe
        $passes = [];
        $res = $db->queryF(
            "SELECT pass, points, maxpoints, questioncount, answeredquestions, workingtime, tstamp
             FROM tst_pass_result WHERE active_fi = %s ORDER BY pass",
            ['integer'],
            [$active_id]
        );
        while ($row = $db->fetchAssoc($res)) {
            $passes[(int) $row['pass']] = [
                'pass'          => (int) $row['pass'],
                'points'        => (float) $row['points'],
                'max_points'    => (float) $row['maxpoints'],
                'questions'     => (int) $row['questioncount'],
                'answered'      => (int) $row['answeredquestions'],
                'workingtime_s' => (int) $row['workingtime'],
                'tstamp'        => (int) $row['tstamp'],
                'started'       => '',
                'finished'      => '',
                'question_data' => [],
            ];
        }

        // Bearbeitungszeitraum je Durchlauf (tst_times)
        $res = $db->queryF(
            "SELECT pass, MIN(started) AS started, MAX(finished) AS finished
             FROM tst_times WHERE active_fi = %s GROUP BY pass",
            ['integer'],
            [$active_id]
        );
        while ($row = $db->fetchAssoc($res)) {
            $p = (int) $row['pass'];
            if (isset($passes[$p])) {
                $passes[$p]['started']  = (string) ($row['started'] ?? '');
                $passes[$p]['finished'] = (string) ($row['finished'] ?? '');
            }
        }

        // Fragen + Punkte + Roh-Antworten + manuelles Feedback je Durchlauf
        foreach ($passes as $p => $_) {
            $passes[$p]['question_data'] = $this->getPassQuestions($test, $active_id, $p);
        }

        return [
            'active_id'     => $active_id,
            'user_id'       => (int) $active['user_fi'],
            'login'         => (string) ($active['login'] ?? ''),
            'firstname'     => (string) ($active['firstname'] ?? ''),
            'lastname'      => (string) ($active['lastname'] ?? ''),
            'matriculation' => (string) ($active['matriculation'] ?? ''),
            'email'         => (string) ($active['email'] ?? ''),
            'anonymous_id'  => (string) ($active['anonymous_id'] ?? ''),
            'tries'         => (int) $active['tries'],
            'submitted'     => (int) $active['submitted'] === 1,
            'submit_time'   => (string) ($active['submittimestamp'] ?? ''),
            'last_pass'     => (int) ($active['last_finished_pass'] ?? -1),
            'result'        => $result,
            'passes'        => array_values($passes),
        ];
    }

    /**
     * Fragenreihenfolge eines Durchlaufs (fest oder pro Teilnehmer gezogen) inkl.
     * erreichter Punkte, Roh-Antworten (tst_solutions) und manuellem Feedback.
     */
    private function getPassQuestions(array $test, int $active_id, int $pass): array
    {
        global $DIC;
        $db = $DIC->database();

        // Fragenreihenfolge bestimmen
        $questions = [];
        if (str_starts_with((string) $test['question_set'], 'RANDOM')) {
            $res = $db->queryF(
                "SELECT r.sequence, r.question_fi, q.title, q.points, qt.type_tag
                 FROM tst_test_rnd_qst r
                 JOIN qpl_questions q ON q.question_id = r.question_fi
                 LEFT JOIN qpl_qst_type qt ON qt.question_type_id = q.question_type_fi
                 WHERE r.active_fi = %s AND r.pass = %s ORDER BY r.sequence",
                ['integer', 'integer'],
                [$active_id, $pass]
            );
        } else {
            $res = $db->queryF(
                "SELECT tq.sequence, tq.question_fi, q.title, q.points, qt.type_tag
                 FROM tst_test_question tq
                 JOIN qpl_questions q ON q.question_id = tq.question_fi
                 LEFT JOIN qpl_qst_type qt ON qt.question_type_id = q.question_type_fi
                 WHERE tq.test_fi = %s ORDER BY tq.sequence",
                ['integer'],
                [(int) $test['test_id']]
            );
        }
        while ($row = $db->fetchAssoc($res)) {
            $questions[] = [
                'sequence'    => (int) $row['sequence'],
                'question_id' => (int) $row['question_fi'],
                'title'       => (string) $row['title'],
                'max_points'  => (float) $row['points'],
                'type'        => (string) ($row['type_tag'] ?? ''),
            ];
        }

        // Erreichte Punkte je Frage
        $points = [];
        $res = $db->queryF(
            "SELECT question_fi, points, manual, answered, tstamp
             FROM tst_test_result WHERE active_fi = %s AND pass = %s",
            ['integer', 'integer'],
            [$active_id, $pass]
        );
        while ($row = $db->fetchAssoc($res)) {
            $points[(int) $row['question_fi']] = [
                'points'   => (float) $row['points'],
                'manual'   => (int) $row['manual'] === 1,
                'answered' => (int) $row['answered'] === 1,
                'tstamp'   => (int) $row['tstamp'],
            ];
        }

        // Manuelles Feedback der Korrektoren
        $feedback = [];
        $res = $db->queryF(
            "SELECT f.question_fi, f.feedback, f.finalized_evaluation, u.login AS finalized_by
             FROM tst_manual_fb f
             LEFT JOIN usr_data u ON u.usr_id = f.finalized_by_usr_id
             WHERE f.active_fi = %s AND f.pass = %s",
            ['integer', 'integer'],
            [$active_id, $pass]
        );
        while ($row = $db->fetchAssoc($res)) {
            $feedback[(int) $row['question_fi']] = [
                'feedback'     => (string) ($row['feedback'] ?? ''),
                'finalized'    => (int) ($row['finalized_evaluation'] ?? 0) === 1,
                'finalized_by' => (string) ($row['finalized_by'] ?? ''),
            ];
        }

        // Roh-Antworten (autorisierte Lösungen)
        foreach ($questions as $i => $q) {
            $qid = $q['question_id'];

            $solutions = [];
            $res = $db->queryF(
                "SELECT value1, value2, points, tstamp, authorized
                 FROM tst_solutions
                 WHERE active_fi = %s AND question_fi = %s AND pass = %s AND authorized = 1
                 ORDER BY solution_id",
                ['integer', 'integer', 'integer'],
                [$active_id, $qid, $pass]
            );
            while ($row = $db->fetchAssoc($res)) {
                $solutions[] = [
                    'value1' => $row['value1'],
                    'value2' => $row['value2'],
                    'tstamp' => (int) $row['tstamp'],
                ];
            }

            $questions[$i]['reached_points'] = $points[$qid]['points'] ?? 0.0;
            $questions[$i]['manual_scored']  = $points[$qid]['manual'] ?? false;
            $questions[$i]['answered']       = $points[$qid]['answered'] ?? !empty($solutions);
            $questions[$i]['scored_at']      = $points[$qid]['tstamp'] ?? 0;
            $questions[$i]['feedback']       = $feedback[$qid] ?? null;
            $questions[$i]['raw_solutions']  = $solutions;
        }

        return $questions;
    }
}
