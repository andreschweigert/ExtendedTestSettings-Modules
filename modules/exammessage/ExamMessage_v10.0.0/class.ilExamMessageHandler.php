<?php

class ilExamMessageHandler implements ilExamModuleInterface
{
    private string $plugin_path;
    private const ALLOWED_TAGS = '<strong><b><i><u><br>';
    private const DURATION_OPTIONS = [
        30   => '30 Sekunden',
        60   => '1 Minute',
        120  => '2 Minuten',
        300  => '5 Minuten',
        600  => '10 Minuten',
        1800 => '30 Minuten',
    ];
    private const TYPE_OPTIONS = [
        0 => 'Info (blau)',
        1 => 'Warnung (orange)',
        2 => 'Dringend (rot)',
    ];
    private static bool $tables_checked = false;
    private ilModuleLanguage $lng;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);

        $this->ensureTables();
    }

    // -------------------------------------------------------------------------
    // Interface-Methoden
    // -------------------------------------------------------------------------

    public function isEnabled(int $ref_id): bool
    {
        // Polling-JS muss immer geladen werden, damit Studierende
        // Nachrichten sehen die NACH ihrem Test-Einstieg gesendet werden.
        return true;
    }

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isTestPlayerContext()) {
            return;
        }

        require_once __DIR__ . '/class.ilExamMessageGUI.php';

        $tpl->addCss($this->plugin_path . '/modules/exammessage/css/exammessage.css');
        $tpl->addJavaScript($this->plugin_path . '/modules/exammessage/js/exammessage.js');

        $poll_url = ILIAS_HTTP_PATH . '/ilias.php?baseClass=ilUIPluginRouterGUI'
                  . '&cmdClass=ilextendedtestsettingsmoduleproxygui'
                  . '&cmd=poll&module_id=exammessage&ref_id=' . $ref_id;

        global $DIC;
        $user_id = (int) $DIC->user()->getId();

        $cfg        = $this->getCurrentConfig($ref_id);
        $now        = time();
        $sent_at    = 0;
        $expiry     = 0;
        $message    = null;
        $type       = 0;

        if ($cfg && (int) $cfg['sent_at'] > 0) {
            $exp = $cfg['sent_at'] + $cfg['duration'];
            // Nachricht nur einbetten, wenn sie gültig ist UND der Nutzer ein
            // Teilnehmer mit offenem Testlauf ist. Die Teilnehmer-Prüfung läuft
            // nur, wenn überhaupt eine aktive Nachricht vorliegt.
            if ($exp > $now && trim($cfg['message_text']) !== ''
                && ilExamMessageGUI::userHasActivePass($user_id, (int) ilObject::_lookupObjectId($ref_id))) {
                $sent_at = $cfg['sent_at'];
                $expiry  = $exp;
                $message = $cfg['message_text'];
                $type    = $cfg['type'];
            }
        }

        $tpl->addOnLoadCode(
            'window.ExamMessage = window.ExamMessage || {};' .
            'window.ExamMessage.config = ' . json_encode([
                'poll_url' => $poll_url,
                'ref_id'   => $ref_id,
                'user_id'  => $user_id,
                'sent_at'  => $sent_at,
                'expiry'   => $expiry,
                'message'  => $message,
                'type'     => $type,
            ]) . ';'
        );
    }

    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            $force_send = !empty($post_data['exammessage_send_now']);

            $new_text = strip_tags(trim($post_data['exammessage_text'] ?? ''), self::ALLOWED_TAGS);
            if (mb_strlen($new_text) > 2000) {
                $new_text = mb_substr($new_text, 0, 2000);
            }

            $duration = (int) ($post_data['exammessage_duration'] ?? 60);
            if (!array_key_exists($duration, self::DURATION_OPTIONS)) {
                $duration = 60;
            }

            $type = (int) ($post_data['exammessage_type'] ?? 0);
            if (!array_key_exists($type, self::TYPE_OPTIONS)) {
                $type = 0;
            }

            if ($new_text === '') {
                $db->replace('exammessage_settings',
                    ['test_id' => ['integer', $test_id]],
                    [
                        'message_text' => ['text',    ''],
                        'duration'     => ['integer', $duration],
                        'sent_at'      => ['integer', 0],
                        'sent_by'      => ['text',    ''],
                        'type'         => ['integer', $type],
                    ]
                );
                return true;
            }

            $current  = $this->getCurrentConfig($ref_id);
            $cur_text = $current ? trim($current['message_text']) : '';
            $cur_dur  = $current ? (int) $current['duration'] : 60;
            $cur_type = $current ? (int) $current['type'] : 0;

            if ($force_send || $new_text !== $cur_text) {
                $sent_at = time();
                $sent_by = $DIC->user()->getLogin();

                $db->replace('exammessage_settings',
                    ['test_id' => ['integer', $test_id]],
                    [
                        'message_text' => ['text',    $new_text],
                        'duration'     => ['integer', $duration],
                        'sent_at'      => ['integer', $sent_at],
                        'sent_by'      => ['text',    $sent_by],
                        'type'         => ['integer', $type],
                    ]
                );

                $log_id = $db->nextId('exammessage_log');
                $db->insert('exammessage_log', [
                    'id'           => ['integer', $log_id],
                    'test_id'      => ['integer', $test_id],
                    'message_text' => ['text',    $new_text],
                    'duration'     => ['integer', $duration],
                    'sent_at'      => ['integer', $sent_at],
                    'sent_by'      => ['text',    $sent_by],
                    'type'         => ['integer', $type],
                ]);
            } elseif ($duration !== $cur_dur || $type !== $cur_type) {
                $db->update('exammessage_settings',
                    [
                        'duration' => ['integer', $duration],
                        'type'     => ['integer', $type],
                    ],
                    ['test_id' => ['integer', $test_id]]
                );
            }

            return true;

        } catch (Exception $e) {
            error_log("[ExamMessage] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    public function getFormItems(int $ref_id): array
    {
        $cfg     = $this->getCurrentConfig($ref_id);
        $test_id = ilObject::_lookupObjectId($ref_id);

        $items = [];

        $status = new ilCustomInputGUI($this->lng->txt('status_title'), '');
        $status->setHtml($this->buildStatusHtml($cfg));
        $items[] = $status;

        $ta = new ilTextAreaInputGUI($this->lng->txt('message_label'), 'exammessage_text');
        $ta->setValue($cfg ? $cfg['message_text'] : '');
        $ta->setRows(4);
        $ta->setInfo($this->lng->txt('message_info'));
        $items[] = $ta;

        $type_sel = new ilSelectInputGUI($this->lng->txt('type_label'), 'exammessage_type');
        $type_sel->setOptions([
            '0' => $this->lng->txt('type_0'),
            '1' => $this->lng->txt('type_1'),
            '2' => $this->lng->txt('type_2'),
        ]);
        $type_sel->setValue((string) ($cfg ? ($cfg['type'] ?? 0) : 0));
        $items[] = $type_sel;

        $dur_sel = new ilSelectInputGUI($this->lng->txt('duration_label'), 'exammessage_duration');
        $dur_opts = [];
        foreach (array_keys(self::DURATION_OPTIONS) as $sec) {
            $dur_opts[(string) $sec] = $this->lng->txt('dur_' . $sec);
        }
        $dur_sel->setOptions($dur_opts);
        $dur_sel->setValue((string) ($cfg ? $cfg['duration'] : 60));
        $items[] = $dur_sel;

        $send_btn = new ilCustomInputGUI('', '');
        $send_btn->setHtml(
            '<button type="submit" name="exammessage_send_now" value="1" class="btn btn-default btn-sm">'
            . '&#x1F4E4;&nbsp;' . $this->lng->txt('send_now_btn') . '</button>'
            . '<span style="margin-left:10px;font-size:0.85em;color:#6c757d;">'
            . $this->lng->txt('send_now_info') . '</span>'
        );
        $items[] = $send_btn;

        $log_html = $this->buildLogHtml($test_id);
        if ($log_html !== '') {
            $log = new ilCustomInputGUI($this->lng->txt('log_title'), '');
            $log->setHtml($log_html);
            $items[] = $log;
        }

        return $items;
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'ExamMessage',
            'title'       => $this->lng->txt('title'),
            'icon'        => '📢',
            'description' => $this->lng->txt('description'),
            'version'     => '1.5.0',
        ];
    }

    // -------------------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------------------

    private function getCurrentConfig(int $ref_id): ?array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            if ($test_id <= 0) {
                return null;
            }

            $result = $db->queryF(
                "SELECT message_text, duration, sent_at, sent_by, type FROM exammessage_settings WHERE test_id = %s",
                ['integer'],
                [$test_id]
            );

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'message_text' => $row['message_text'] ?? '',
                    'duration'     => (int) ($row['duration'] ?? 60),
                    'sent_at'      => (int) ($row['sent_at'] ?? 0),
                    'sent_by'      => $row['sent_by'] ?? '',
                    'type'         => (int) ($row['type'] ?? 0),
                ];
            }
        } catch (Exception $e) {
            error_log("[ExamMessage] Fehler beim Laden: " . $e->getMessage());
        }

        return null;
    }

    private function buildStatusHtml(?array $cfg): string
    {
        if (!$cfg || trim($cfg['message_text']) === '' || $cfg['sent_at'] <= 0) {
            return '<div style="padding:8px 12px;background:#f8f9fa;border-radius:6px;color:#6c757d;font-size:0.9em;">' . $this->lng->txt('status_none') . '</div>';
        }

        $expiry    = $cfg['sent_at'] + $cfg['duration'];
        $remaining = $expiry - time();
        $preview   = htmlspecialchars(mb_substr(strip_tags($cfg['message_text']), 0, 80), ENT_QUOTES);
        if (mb_strlen(strip_tags($cfg['message_text'])) > 80) {
            $preview .= '…';
        }

        if ($remaining <= 0) {
            $sent_str = date('d.m.Y H:i', $cfg['sent_at']);
            return '<div style="padding:8px 12px;background:#f8f9fa;border-radius:6px;color:#6c757d;font-size:0.9em;">'
                 . sprintf($this->lng->txt('status_expired'), $sent_str) . '<br>'
                 . '<em>' . $preview . '</em></div>';
        }

        $type       = (int) ($cfg['type'] ?? 0);
        $colors     = [0 => '#1a6fac', 1 => '#e8890c', 2 => '#c0392b'];
        $bg_colors  = [0 => '#e8f4fd', 1 => '#fff3e0', 2 => '#fde8e8'];
        $bdr_colors = [0 => '#bee3f8', 1 => '#ffcc80', 2 => '#f5a3a3'];
        $type_label = $this->lng->txt('type_' . $type);
        $color      = $colors[$type]     ?? $colors[0];
        $bg         = $bg_colors[$type]  ?? $bg_colors[0];
        $border     = $bdr_colors[$type] ?? $bdr_colors[0];

        $remaining_str = $remaining >= 60
            ? floor($remaining / 60) . ' ' . $this->lng->txt('rem_min') . ' ' . ($remaining % 60) . ' ' . $this->lng->txt('rem_sec')
            : $remaining . ' ' . $this->lng->txt('rem_seconds');

        return '<div style="padding:10px 14px;background:' . $bg . ';border:1px solid ' . $border . ';border-radius:6px;font-size:0.9em;">'
             . '<strong style="color:' . $color . ';">&#x1F4E2; ' . sprintf($this->lng->txt('status_active'), htmlspecialchars($type_label)) . '</strong>'
             . ' — ' . $this->lng->txt('status_running') . ' <strong>' . $remaining_str . '</strong><br>'
             . '<span style="color:#555;">' . $preview . '</span>'
             . '</div>';
    }

    private function buildLogHtml(int $test_id): string
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $result = $db->queryF(
                "SELECT message_text, duration, sent_at, sent_by, type FROM exammessage_log" .
                " WHERE test_id = %s ORDER BY sent_at DESC",
                ['integer'],
                [$test_id]
            );

            $rows = [];
            while ($row = $db->fetchAssoc($result)) {
                $rows[] = $row;
            }

            if (empty($rows)) {
                return '';
            }

            $type_icons = [0 => '&#x1F535;', 1 => '&#x1F7E0;', 2 => '&#x1F534;'];

            $html = '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;margin-top:4px;">'
                  . '<thead><tr style="background:#f1f3f5;">'
                  . '<th style="text-align:left;padding:5px 8px;border-bottom:1px solid #dee2e6;">' . $this->lng->txt('log_th_type') . '</th>'
                  . '<th style="text-align:left;padding:5px 8px;border-bottom:1px solid #dee2e6;">' . $this->lng->txt('log_th_message') . '</th>'
                  . '<th style="text-align:left;padding:5px 8px;border-bottom:1px solid #dee2e6;white-space:nowrap;">' . $this->lng->txt('log_th_sent') . '</th>'
                  . '<th style="text-align:left;padding:5px 8px;border-bottom:1px solid #dee2e6;">' . $this->lng->txt('log_th_duration') . '</th>'
                  . '<th style="text-align:left;padding:5px 8px;border-bottom:1px solid #dee2e6;">' . $this->lng->txt('log_th_by') . '</th>'
                  . '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $preview  = htmlspecialchars(mb_substr(strip_tags($row['message_text']), 0, 55), ENT_QUOTES);
                if (mb_strlen(strip_tags($row['message_text'])) > 55) {
                    $preview .= '…';
                }
                $sent_str  = $row['sent_at'] ? date('d.m. H:i:s', (int) $row['sent_at']) : '—';
                $dur_str   = $this->formatDuration((int) $row['duration']);
                $by        = htmlspecialchars($row['sent_by'] ?? '—', ENT_QUOTES);
                $type_icon = $type_icons[(int) ($row['type'] ?? 0)] ?? $type_icons[0];

                $html .= '<tr style="border-bottom:1px solid #f1f3f5;">'
                       . '<td style="padding:5px 8px;">' . $type_icon . '</td>'
                       . '<td style="padding:5px 8px;">' . $preview . '</td>'
                       . '<td style="padding:5px 8px;white-space:nowrap;">' . $sent_str . '</td>'
                       . '<td style="padding:5px 8px;">' . $dur_str . '</td>'
                       . '<td style="padding:5px 8px;">' . $by . '</td>'
                       . '</tr>';
            }

            return $html . '</tbody></table>';

        } catch (Exception $e) {
            return '';
        }
    }

    private function formatDuration(int $seconds): string
    {
        return $seconds < 60 ? $seconds . 's' : floor($seconds / 60) . ' Min';
    }

    private function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET['cmdClass'] ?? '');
        foreach (['iltestplayer', 'iltestplayerfixedquestionsetgui', 'iltestplayerrandquestionsetgui', 'iltestplayergui', 'iltestplayercommandsgui', 'iltestoutputgui'] as $cls) {
            if (str_contains($cmd_class, $cls)) {
                return true;
            }
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($uri, 'cmd=startPlayer')
            || str_contains($uri, 'cmd=resumePlayer')
            || str_contains($uri, 'cmd=showQuestion')
            || str_contains($uri, 'cmd=nextQuestion')
            || str_contains($uri, 'cmdClass=ilTestPlayer');
    }

    private function ensureTables(): void
    {
        if (self::$tables_checked) {
            return;
        }
        self::$tables_checked = true;

        global $DIC;
        $db = $DIC->database();

        try {
            if (!$db->tableExists('exammessage_settings')
                || !$db->tableColumnExists('exammessage_settings', 'type')
                || !$db->tableExists('exammessage_log')
                || !$db->tableColumnExists('exammessage_log', 'type')) {
                $install = __DIR__ . '/sql/install.php';
                if (file_exists($install)) {
                    require $install;
                }
            }
        } catch (Exception $e) {
            error_log("[ExamMessage] ensureTables Fehler: " . $e->getMessage());
        }
    }
}
