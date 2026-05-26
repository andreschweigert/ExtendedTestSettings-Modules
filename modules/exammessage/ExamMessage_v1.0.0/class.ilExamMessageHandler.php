<?php
/**
 * ExamMessage Handler - Administratornachrichten im Test-Player
 * Part of ExtendedTestSettings Plugin
 */

class ilExamMessageHandler implements ilExamModuleInterface
{
    private string $plugin_path;

    /** Erlaubte HTML-Tags in der Nachricht */
    private const ALLOWED_TAGS = '<strong><b><i><u><br>';

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;
    }

    public function isEnabled(int $ref_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            if ($test_id <= 0) {
                return false;
            }

            $result = $db->queryF(
                "SELECT enabled FROM exammessage_settings WHERE test_id = %s",
                ['integer'],
                [$test_id]
            );

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            return false;

        } catch (Exception $e) {
            error_log("[ExamMessage] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
            return false;
        }
    }

    private function getMessageConfig(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $default = ['enabled' => false, 'message_text' => ''];

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            if ($test_id <= 0) {
                return $default;
            }

            $result = $db->queryF(
                "SELECT enabled, message_text FROM exammessage_settings WHERE test_id = %s",
                ['integer'],
                [$test_id]
            );

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled'      => (bool) $row['enabled'],
                    'message_text' => $row['message_text'] ?? '',
                ];
            }

        } catch (Exception $e) {
            error_log("[ExamMessage] Fehler beim Laden der Konfiguration: " . $e->getMessage());
        }

        return $default;
    }

    private function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET['cmdClass'] ?? '');

        $player_classes = [
            'iltestplayer',
            'iltestplayergui',
            'iltestplayercommandsgui',
            'iltestoutputgui',
        ];

        foreach ($player_classes as $cls) {
            if (str_contains($cmd_class, $cls)) {
                return true;
            }
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($uri, 'cmd=startPlayer')
            || str_contains($uri, 'cmd=resumePlayer')
            || str_contains($uri, 'cmdClass=ilTestPlayer');
    }

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isTestPlayerContext()) {
            return;
        }

        $config = $this->getMessageConfig($ref_id);

        if (!$config['enabled'] || trim($config['message_text']) === '') {
            return;
        }

        $tpl->addCss($this->plugin_path . '/modules/exammessage/css/exammessage.css');
        $tpl->addJavaScript($this->plugin_path . '/modules/exammessage/js/exammessage.js');

        $config_json = json_encode([
            'message' => $config['message_text'],
        ]);

        $tpl->addOnLoadCode("
            window.ExamMessage = window.ExamMessage || {};
            window.ExamMessage.config = $config_json;
        ");
    }

    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            $enabled  = isset($post_data['exammessage_enabled']) ? 1 : 0;
            $message  = strip_tags(
                trim($post_data['exammessage_text'] ?? ''),
                self::ALLOWED_TAGS
            );

            // Länge begrenzen
            if (mb_strlen($message) > 2000) {
                $message = mb_substr($message, 0, 2000);
            }

            // Wenn kein Text, automatisch deaktivieren
            if ($message === '') {
                $enabled = 0;
            }

            $db->replace('exammessage_settings',
                ['test_id' => ['integer', $test_id]],
                [
                    'enabled'      => ['integer',   $enabled],
                    'message_text' => ['text',       $message],
                    'updated_at'   => ['timestamp',  date('Y-m-d H:i:s')],
                ]
            );

            return true;

        } catch (Exception $e) {
            error_log("[ExamMessage] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    public function getFormItems(int $ref_id): array
    {
        $config = $this->getMessageConfig($ref_id);

        $section = new ilFormSectionHeaderGUI();
        $section->setTitle('Prüfungs-Nachricht');

        $cb = new ilCheckboxInputGUI('Nachricht aktivieren', 'exammessage_enabled');
        $cb->setChecked($config['enabled']);
        $cb->setInfo('Zeigt allen Teilnehmern eine Nachricht im Test-Player an.');

        $ta = new ilTextAreaInputGUI('Nachrichtentext', 'exammessage_text');
        $ta->setValue($config['message_text']);
        $ta->setRows(5);
        $ta->setMaxLength(2000);
        $ta->setInfo('Erlaubte HTML-Tags: &lt;strong&gt;, &lt;b&gt;, &lt;i&gt;, &lt;u&gt;, &lt;br&gt;. Wird sofort für alle aktiven Teilnehmer sichtbar.');
        $cb->addSubItem($ta);

        return [$section, $cb];
    }
}
