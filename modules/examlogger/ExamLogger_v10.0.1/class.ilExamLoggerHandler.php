<?php

require_once __DIR__ . '/../../classes/Contracts/ilExamModuleInterface.php';
require_once __DIR__ . '/../../classes/Module/ilModuleLanguage.php';

class ilExamLoggerHandler implements ilExamModuleInterface
{
    private const TABLE = 'examlogger_events';

    private ilModuleLanguage $lng;

    public function __construct()
    {
        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    // Testplayer-Commands → Event-Typ
    private const CMD_EVENT_MAP = [
        'startPlayer'      => 'test_start',
        'resumePlayer'     => 'test_start',
        'startTest'           => 'test_start',   // zweiter+ Durchlauf
        'outQuestionSummary'  => 'test_overview',
        'nextQuestion'        => 'navigate_next',
        'previousQuestion' => 'navigate_prev',
        'showQuestion'     => 'navigate_jump',
        'submitTest'       => 'test_submit',
        // finishTest: GET = Bestätigungsseite anzeigen, POST = tatsächlich bestätigen
        'finishTest'       => 'test_submit',
    ];

    // ILIAS-native Test-Einstellungen: diese cmds sind Speicher-Aktionen
    // Hinweis: Da ILIAS nach POST sofort redirectet, werden diese nur bei
    // "Formular mit Fehler re-rendern" zuverlässig erfasst.
    // Zuverlässig erfasst wird stattdessen das Öffnen der Einstellungsseite (test_settings_viewed).
    private const NATIVE_SETTINGS_CMDS = [
        'saveGeneralSettings',
        'saveProperties',
        'saveMarkSchema',
        'saveScoring',
        'saveForm',
        'save',
    ];

    public function getInfo(): array
    {
        return [
            'name'        => 'ExamLogger',
            'title'       => $this->lng->txt('title'),
            'icon'        => '',
            'description' => $this->lng->txt('description'),
            'version'     => '10.0.1',
        ];
    }

    public function isEnabled(int $ref_id): bool
    {
        return true;
    }

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        // Kein JS/CSS — Logging erfolgt serverseitig in onRequest()
    }

    /**
     * Wird von saveAllModuleSettings() aufgerufen wenn die Plugin-Einstellungsseite gespeichert wird.
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        $this->writeLog($ref_id, 'plugin_settings_saved');
        return true;
    }

    public function getFormItems(int $ref_id): array
    {
        return [];
    }

    /**
     * Optionale Methode — wird von UIHookGUI::dispatchRequestToModules() via method_exists() aufgerufen.
     * Nicht im Interface, da nicht alle Module diese Methode benötigen.
     */
    public function onRequest(int $ref_id): void
    {
        $cmd      = (string)($_REQUEST['cmd'] ?? '');
        $cmdClass = strtolower((string)($_REQUEST['cmdClass'] ?? ''));

        // Testplayer-Events
        if ($this->isPlayerClass($cmdClass) && isset(self::CMD_EVENT_MAP[$cmd])) {
            $sequence = (int)($_REQUEST['sequence'] ?? $_REQUEST['question_id'] ?? 0);
            $event    = self::CMD_EVENT_MAP[$cmd];

            // finishTest per HTTP-Methode unterscheiden:
            // GET = Student sieht Bestätigungsseite (= "Test abgegeben" / erster Klick)
            // POST = Student hat bestätigt (= "Abgabe bestätigt" / zweiter Klick)
            if ($cmd === 'finishTest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $event = 'test_submit_confirmed';
            }

            if ($event === 'test_submit') {
                $details = $this->buildAllAnswersSummary($ref_id);
            } elseif ($event === 'test_submit_confirmed') {
                $details = null; // Antworten bereits in test_submit geloggt
            } elseif ($sequence > 0 && in_array($event, ['navigate_next', 'navigate_prev'], true)) {
                $details = $this->buildAnswerDetails($ref_id, $sequence);
            } elseif ($event === 'navigate_jump' && $sequence > 0) {
                $details = $this->buildJumpDetails($ref_id, $sequence);
            } else {
                $details = null;
            }

            $this->writeLog($ref_id, $event, $sequence, $details);
            return;
        }

        // Zeitverlängerung: ILIAS macht POST→Redirect, modifyGUI feuert erst beim folgenden GET.
        // Deshalb change-detection auf tst_addtime beim nächsten GET auf einer Supervisor-Seite.
        if (str_contains($cmdClass, 'iltestparticipants')
            && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->detectAndLogTimeExtensions($ref_id);
            return;
        }

        // ILIAS-native Einstellungsseite (9er- und namespaced 10er-cmdClasses)
        $settings_classes = [
            'ilobjtestsettingsmaingui',
            'iltestscoringgui',
            'ilias\\test\\settings\\mainsettings\\settingsmaingui',
            'ilias\\test\\settings\\scorereporting\\settingsscoringgui',
            'ilias\\test\\scoring\\manual\\testscoringbyquestiongui',
            'ilias\\test\\scoring\\manual\\testscoringbyparticipantgui',
        ];
        if (in_array($cmdClass, $settings_classes, true)) {
            if (in_array($cmd, self::NATIVE_SETTINGS_CMDS, true)) {
                $obj_id = (int)ilObject::_lookupObjectId($ref_id);
                $this->writeLog($ref_id, 'test_settings_saved', 0, $this->snapshotTestSettings($obj_id));
            } else {
                $this->writeLog($ref_id, 'test_settings_viewed');
            }
        }
    }

    private function isPlayerClass(string $cmdClass): bool
    {
        foreach (['iltestoutputgui', 'iltestplayerfixedquestionsetgui', 'iltestplayerrandomquestionsetgui', 'iltestplayergui'] as $cls) {
            if (str_contains($cmdClass, $cls)) {
                return true;
            }
        }
        return false;
    }

    private function writeLog(int $ref_id, string $event_type, int $question_id = 0, ?string $details = null): void
    {
        global $DIC;

        try {
            $user_id = $DIC->user()->getId();
            if ($user_id <= 0) {
                return;
            }

            $obj_id = (int) ilObject::_lookupObjectId($ref_id);
            if ($obj_id <= 0) {
                return;
            }

            $db = $DIC->database();
            $db->insert(self::TABLE, [
                'id'          => ['integer', $db->nextId(self::TABLE)],
                'test_obj_id' => ['integer', $obj_id],
                'test_ref_id' => ['integer', $ref_id],
                'user_id'     => ['integer', $user_id],
                'event_type'  => ['text',    $event_type],
                'question_id' => ['integer', $question_id],
                'ip_addr'     => ['text',    $this->getClientIp()],
                'tstamp'      => ['integer', time()],
                'details'     => ['clob',    $details],
            ]);
        } catch (Exception $e) {
            error_log('[ExamLogger] Fehler beim Schreiben: ' . $e->getMessage());
        }
    }

    private function getTestIdAndActiveId(int $ref_id): ?array
    {
        global $DIC;
        $db      = $DIC->database();
        $user_id = $DIC->user()->getId();
        $obj_id  = (int)ilObject::_lookupObjectId($ref_id);

        $res = $db->queryF("SELECT test_id FROM tst_tests WHERE obj_fi = %s", ['integer'], [$obj_id]);
        $row = $db->fetchAssoc($res);
        if (!$row) {
            return null;
        }
        $test_id = (int)$row['test_id'];

        $res = $db->queryF(
            "SELECT active_id, last_started_pass FROM tst_active WHERE test_fi = %s AND user_fi = %s",
            ['integer', 'integer'],
            [$test_id, $user_id]
        );
        $row = $db->fetchAssoc($res);
        if (!$row) {
            return null;
        }

        return [
            'test_id'   => $test_id,
            'active_id' => (int)$row['active_id'],
            'pass'      => (int)($row['last_started_pass'] ?? 0),
        ];
    }

    private function buildAnswerDetails(int $ref_id, int $sequence): ?string
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $ctx = $this->getTestIdAndActiveId($ref_id);
            if (!$ctx) {
                return null;
            }

            $res = $db->queryF(
                "SELECT question_fi FROM tst_test_question WHERE test_fi = %s AND sequence = %s",
                ['integer', 'integer'],
                [$ctx['test_id'], $sequence]
            );
            $row = $db->fetchAssoc($res);
            if (!$row) {
                return null;
            }
            $question_fi = (int)$row['question_fi'];

            $answer = $this->fetchFormattedAnswer($question_fi, $ctx['active_id'], $ctx['pass'], $db);
            return $answer !== null ? 'Studentische Eingabe: ' . $answer : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function buildJumpDetails(int $ref_id, int $sequence): ?string
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $ctx = $this->getTestIdAndActiveId($ref_id);
            if (!$ctx) {
                return null;
            }

            $res = $db->queryF(
                "SELECT q.title FROM tst_test_question tq
                 JOIN qpl_questions q ON q.question_id = tq.question_fi
                 WHERE tq.test_fi = %s AND tq.sequence = %s",
                ['integer', 'integer'],
                [$ctx['test_id'], $sequence]
            );
            $row = $db->fetchAssoc($res);
            return $row ? 'Ziel: ' . $row['title'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function buildAllAnswersSummary(int $ref_id): ?string
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $ctx = $this->getTestIdAndActiveId($ref_id);
            if (!$ctx) {
                return null;
            }

            $res = $db->queryF(
                "SELECT tq.sequence, tq.question_fi, q.title
                 FROM tst_test_question tq
                 JOIN qpl_questions q ON q.question_id = tq.question_fi
                 WHERE tq.test_fi = %s
                 ORDER BY tq.sequence",
                ['integer'],
                [$ctx['test_id']]
            );

            $parts = [];
            while ($row = $db->fetchAssoc($res)) {
                $qfi   = (int)$row['question_fi'];
                $label = 'F' . $row['sequence'];
                $ans   = $this->fetchFormattedAnswer($qfi, $ctx['active_id'], $ctx['pass'], $db) ?? '—';
                $parts[] = $label . ': ' . $ans;
            }

            return $parts ? implode(' | ', $parts) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function fetchFormattedAnswer(int $question_fi, int $active_id, int $pass, $db): ?string
    {
        try {
            $res = $db->queryF(
                "SELECT qt.type_tag FROM qpl_questions q
                 JOIN qpl_qst_type qt ON qt.question_type_id = q.question_type_fi
                 WHERE q.question_id = %s",
                ['integer'],
                [$question_fi]
            );
            $row = $db->fetchAssoc($res);
            if (!$row) {
                return null;
            }
            $type_tag = $row['type_tag'];

            $res = $db->queryF(
                "SELECT value1, value2 FROM tst_solutions
                 WHERE active_fi = %s AND question_fi = %s AND pass = %s
                 ORDER BY tstamp DESC",
                ['integer', 'integer', 'integer'],
                [$active_id, $question_fi, $pass]
            );
            $solutions = [];
            while ($sol = $db->fetchAssoc($res)) {
                $solutions[] = $sol;
            }

            if (empty($solutions)) {
                return 'nicht beantwortet';
            }

            return $this->formatAnswer($question_fi, $type_tag, $solutions, $db);
        } catch (Exception $e) {
            return null;
        }
    }

    private function formatAnswer(int $question_fi, string $type_tag, array $solutions, $db): string
    {
        switch ($type_tag) {
            case 'assSingleChoice':
                $aorder = (int)$solutions[0]['value1'];
                $res = $db->queryF(
                    "SELECT answertext, points FROM qpl_a_sc WHERE question_fi = %s AND aorder = %s",
                    ['integer', 'integer'],
                    [$question_fi, $aorder]
                );
                $row = $db->fetchAssoc($res);
                if ($row) {
                    $text = html_entity_decode(strip_tags((string)$row['answertext']), ENT_QUOTES, 'UTF-8');
                    return mb_substr($text, 0, 100) . ($row['points'] > 0 ? ' [richtig]' : '');
                }
                return 'Option ' . ($aorder + 1);

            case 'assMultipleChoice':
                $texts = [];
                foreach ($solutions as $sol) {
                    if ((int)$sol['value2'] <= 0) {
                        continue;
                    }
                    $res = $db->queryF(
                        "SELECT answertext FROM qpl_a_mc WHERE question_fi = %s AND aorder = %s",
                        ['integer', 'integer'],
                        [$question_fi, (int)$sol['value1']]
                    );
                    $row = $db->fetchAssoc($res);
                    $texts[] = $row
                        ? mb_substr(html_entity_decode(strip_tags((string)$row['answertext']), ENT_QUOTES, 'UTF-8'), 0, 60)
                        : 'Option ' . ((int)$sol['value1'] + 1);
                }
                return $texts ? implode(', ', $texts) : 'keine Auswahl';

            case 'assTextQuestion':
            case 'assTextSubset':
            case 'assEssay':
                $text = trim((string)($solutions[0]['value1'] ?? ''));
                return $text !== ''
                    ? '"' . mb_substr($text, 0, 150) . (mb_strlen($text) > 150 ? '…' : '') . '"'
                    : 'nicht beantwortet';

            default:
                $val = trim((string)($solutions[0]['value1'] ?? ''));
                return $val !== '' ? $val : 'beantwortet';
        }
    }

    private function snapshotTestSettings(int $obj_id): ?string
    {
        global $DIC;

        try {
            $db = $DIC->database();
            $res = $db->queryF(
                "SELECT nr_of_tries, enable_processing_time, processing_time,
                        shuffle_questions, question_set_type, pass_scoring,
                        starting_time_enabled, starting_time,
                        ending_time_enabled, ending_time,
                        fixed_participants, anonymity, password_enabled
                 FROM tst_tests WHERE obj_fi = %s",
                ['integer'],
                [$obj_id]
            );
            $row = $db->fetchAssoc($res);
            if (!$row) {
                return null;
            }

            $parts = [];

            $parts[] = 'Versuche: ' . ((int)$row['nr_of_tries'] === 0 ? 'unbegrenzt' : (int)$row['nr_of_tries']);

            if ($row['enable_processing_time']) {
                $parts[] = 'Zeitlimit: ' . $row['processing_time'];
            } else {
                $parts[] = 'Zeitlimit: keins';
            }

            $parts[] = 'Fragenmischung: ' . ($row['shuffle_questions'] ? 'ja' : 'nein');
            $parts[] = 'Fragenset: ' . ($row['question_set_type'] === 'RANDOM' ? 'Zufällig' : 'Fest');

            if ($row['starting_time_enabled'] && $row['starting_time']) {
                $parts[] = 'Start: ' . date('d.m.Y H:i', (int)$row['starting_time']);
            }
            if ($row['ending_time_enabled'] && $row['ending_time']) {
                $parts[] = 'Ende: ' . date('d.m.Y H:i', (int)$row['ending_time']);
            }
            if ($row['fixed_participants']) {
                $parts[] = 'Feste Teilnehmer: ja';
            }
            if ($row['anonymity']) {
                $parts[] = 'Anonymität: ja';
            }
            if ($row['password_enabled']) {
                $parts[] = 'Kennwort: gesetzt';
            }

            return implode(' | ', $parts);
        } catch (Exception $e) {
            return null;
        }
    }

    private function detectAndLogTimeExtensions(int $ref_id): void
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = (int)ilObject::_lookupObjectId($ref_id);
            if ($obj_id <= 0) return;

            $res = $db->queryF("SELECT test_id FROM tst_tests WHERE obj_fi = %s", ['integer'], [$obj_id]);
            $row = $db->fetchAssoc($res);
            if (!$row) return;
            $test_id = (int)$row['test_id'];

            // Alle aktuellen Zeitverlängerungen für diesen Test
            $res = $db->queryF(
                "SELECT a.active_fi, a.additionaltime, ta.user_fi,
                        u.login, u.firstname, u.lastname
                 FROM tst_addtime a
                 JOIN tst_active ta ON ta.active_id = a.active_fi
                 JOIN usr_data u ON u.usr_id = ta.user_fi
                 WHERE ta.test_fi = %s AND a.additionaltime > 0",
                ['integer'],
                [$test_id]
            );

            while ($entry = $db->fetchAssoc($res)) {
                $student_id = (int)$entry['user_fi'];
                $total      = (int)$entry['additionaltime'];

                // Letzten geloggten Gesamtwert für diesen Teilnehmer lesen
                $last = $db->queryF(
                    "SELECT details FROM " . self::TABLE . "
                     WHERE test_obj_id = %s AND user_id = %s AND event_type = %s
                     ORDER BY tstamp DESC, id DESC LIMIT 1",
                    ['integer', 'integer', 'text'],
                    [$obj_id, $student_id, 'time_extension']
                );
                $lastRow    = $db->fetchAssoc($last);
                $lastTotal  = 0;
                if ($lastRow && preg_match('/Gesamt: (\d+) Min\./', $lastRow['details'], $m)) {
                    $lastTotal = (int)$m[1];
                }

                if ($total === $lastTotal) {
                    continue; // keine Änderung
                }

                $diff = $total - $lastTotal;
                $name = trim($entry['firstname'] . ' ' . $entry['lastname'])
                      . ' (' . $entry['login'] . ')';
                $details = 'Teilnehmer: ' . $name
                         . ' | Gesamt: ' . $total . ' Min.'
                         . ' | Änderung: ' . ($diff > 0 ? '+' : '') . $diff . ' Min.';

                // Als aktueller Supervisor-User loggen
                $this->writeLog($ref_id, 'time_extension', 0, $details);
            }
        } catch (Exception $e) {
            error_log('[ExamLogger] detectAndLogTimeExtensions Fehler: ' . $e->getMessage());
        }
    }

    private function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        return trim(explode(',', $ip)[0]);
    }
}
