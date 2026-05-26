<?php

class ilExamMessageProxyGUI
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
