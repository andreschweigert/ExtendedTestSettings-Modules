<?php

class ilExamMessageGUI
{
    public function executeCommand(): void
    {
        $cmd = strtolower($_GET['cmd'] ?? 'poll');
        if ($cmd === 'poll') {
            $this->poll();
        }
    }

    private function poll(): void
    {
        global $DIC;
        $db = $DIC->database();

        $ref_id = (int) ($_GET['ref_id'] ?? 0);

        if ($ref_id <= 0) {
            $this->jsonExit(['sent_at' => 0], 400);
        }

        $test_id = (int) ilObject::_lookupObjectId($ref_id);

        if ($test_id <= 0) {
            $this->jsonExit(['sent_at' => 0]);
        }

        try {
            $result = $db->queryF(
                "SELECT message_text, duration, sent_at, type FROM exammessage_settings WHERE test_id = %s",
                ['integer'],
                [$test_id]
            );

            $row = $db->fetchAssoc($result);

            if (!$row || (int) $row['sent_at'] <= 0) {
                $this->jsonExit(['sent_at' => 0]);
            }

            $sent_at = (int) $row['sent_at'];
            $expiry  = $sent_at + (int) $row['duration'];

            if ($expiry <= time()) {
                $this->jsonExit(['sent_at' => 0]);
            }

            // Erst jetzt – wenn wirklich eine gültige Nachricht ansteht – prüfen,
            // ob der Abrufende ein Teilnehmer mit offenem Testlauf ist.
            // (Im Normalfall "keine Nachricht" wird dieser Query gar nicht erreicht.)
            $user_id = (int) $DIC->user()->getId();
            if (!self::userHasActivePass($user_id, $test_id)) {
                $this->jsonExit(['sent_at' => 0]);
            }

            $this->jsonExit([
                'sent_at' => $sent_at,
                'expiry'  => $expiry,
                'message' => $row['message_text'],
                'type'    => (int) ($row['type'] ?? 0),
            ]);

        } catch (Exception $e) {
            error_log('[ExamMessage] poll() Fehler: ' . $e->getMessage());
            $this->jsonExit(['sent_at' => 0], 500);
        }
    }

    /**
     * Prüft, ob der Nutzer einen aktiven (nicht abgegebenen) Testlauf für das
     * Testobjekt hat. Eine indizierte Query auf die ILIAS-Kerntabellen.
     */
    public static function userHasActivePass(int $user_id, int $obj_id): bool
    {
        if ($user_id <= 0 || $obj_id <= 0) {
            return false;
        }

        global $DIC;
        $db = $DIC->database();

        try {
            $res = $db->queryF(
                "SELECT 1 FROM tst_active a"
                . " JOIN tst_tests t ON a.test_fi = t.test_id"
                . " WHERE a.user_fi = %s AND t.obj_fi = %s AND a.submitted = %s",
                ['integer', 'integer', 'integer'],
                [$user_id, $obj_id, 0]
            );
            return (bool) $db->fetchAssoc($res);
        } catch (Exception $e) {
            error_log('[ExamMessage] userHasActivePass() Fehler: ' . $e->getMessage());
            return false;
        }
    }

    private function jsonExit(array $data, int $status = 200): never
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
