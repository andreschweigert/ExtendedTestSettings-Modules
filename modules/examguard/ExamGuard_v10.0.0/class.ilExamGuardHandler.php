<?php
/**
 * ExamGuard Handler - Prüfungsschutz-Integration
 * Part of ExtendedTestSettings Plugin
 */

class ilExamGuardHandler implements ilExamModuleInterface
{
    private string $plugin_path;
    private ilModuleLanguage $lng;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    /**
     * Prüft ob ExamGuard für den Test aktiviert ist
     */
    public function isEnabled(int $ref_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            if ($test_id <= 0) {
                return false;
            }

            $query = "SELECT enabled FROM examguard_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            return false;

        } catch (Exception $e) {
            error_log("[ExamGuard] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Prüft ob aktueller Kontext ein Testplayer ist
     */
    public function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET["cmdClass"] ?? "");

        // Testplayer-Klassen
        $test_player_classes = [
            'iltestplayer',
            'iltestplayergui',
            'iltestplayercommandsgui',
            'iltestoutputgui'
        ];

        foreach ($test_player_classes as $test_class) {
            if (str_contains($cmd_class, $test_class)) {
                return true;
            }
        }

        // URL-Pattern prüfen
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($request_uri, 'cmd=startPlayer') ||
            str_contains($request_uri, 'cmd=resumePlayer') ||
            str_contains($request_uri, 'cmdClass=ilTestPlayer')) {
            return true;
        }

        return false;
    }

    /**
     * Injiziert alle nötigen Assets (JS) in Template
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        $js_guard_path = $this->plugin_path . "/modules/examguard/js/examguard.js";
        $tpl->addJavaScript($js_guard_path);
    }

    /**
     * Speichert ExamGuard-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examguard_enabled']) ? 1 : 0;

            $db->replace('examguard_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled]
            ]);

            return true;

        } catch (Exception $e) {
            error_log("[ExamGuard] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt ILIAS-Formular-Elemente zurück (kein HTML)
     */
    public function getFormItems(int $ref_id): array
    {
        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'examguard_enabled');
        $cb->setChecked($this->isEnabled($ref_id));
        $cb->setInfo($this->lng->txt('enable_info'));

        return [$cb];
    }

    /**
     * Generiert HTML für Einstellungs-Formular
     */
    public function getSettingsHTML(int $ref_id): string
    {
        $enabled = $this->isEnabled($ref_id) ? 'checked' : '';

        $t_enable      = $this->lng->txt('enable');
        $t_enable_info = $this->lng->txt('enable_info');

        return <<<HTML
<div class="mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="examguard_enabled" value="1" id="examguard_enabled" $enabled>
        <label class="form-check-label fw-bold" for="examguard_enabled">$t_enable</label>
    </div>
    <div class="form-text">$t_enable_info</div>
</div>
HTML;
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return [
            'name' => 'ExamGuard',
            'title' => $this->lng->txt('title'),
            'icon' => '🔒',
            'description' => $this->lng->txt('description'),
            'version' => '1.2.0',
            'features' => [
                'Copy&Paste blockieren',
                'Rechtsklick verhindern',
                'Shortcuts unterbinden (Ctrl+C/V/X/A/S)',
                'DevTools-Shortcuts blockieren (F12)',
                'Text-Selection verhindern'
            ]
        ];
    }
}
