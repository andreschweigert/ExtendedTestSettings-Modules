<?php
/**
 * ExamSymbols Handler - Komplette Logic für Sonderzeichen-Integration
 * Part of ExtendedTestSettings Plugin
 * BEREINIGT: Templates nur noch aus Config-Klasse laden
 */

class ilExamSymbolsHandler implements ilExamModuleInterface
{
    private string $plugin_path;
    private ilExamSymbolsConfig $config;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;
        $this->config = new ilExamSymbolsConfig('examsymbols');
    }

    /**
     * Prüft ob ExamSymbols für den Test aktiviert ist
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

            $query = "SELECT enabled FROM examsymbols_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            return false;
        } catch (Exception $e) {
            error_log("[ExamSymbols] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Prüft ob aktueller Kontext ein Testplayer ist
     */
    public function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET["cmdClass"] ?? "");

        // Testplayer-Klassen (identisch zu ExamCalc)
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
        if (
            str_contains($request_uri, 'cmd=startPlayer') ||
            str_contains($request_uri, 'cmd=resumePlayer') ||
            str_contains($request_uri, 'cmdClass=ilTestPlayer')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Injiziert alle nötigen Assets (CSS, JS, HTML) in Template
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        $template = $this->getSymbolTemplate($ref_id);
        $symbols = $this->config->getSymbolsForTemplate($template);

        $css_path = $this->plugin_path . "/modules/examsymbols/css/examsymbols.css";
        $tpl->addCss($css_path);

        $js_symbols_path = $this->plugin_path . "/modules/examsymbols/js/symbols-manager.js";
        $js_loader_path = $this->plugin_path . "/modules/examsymbols/js/examsymbols-loader.js";

        $tpl->addJavaScript($js_symbols_path);
        $tpl->addJavaScript($js_loader_path);

        $template_path = $this->plugin_path . "/modules/examsymbols/templates/symbols_overlay.html";
        $json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $symbols_json = json_encode($symbols, $json_flags);
        $templates_json = json_encode($this->config->getAvailableTemplates(), $json_flags);

        $tpl->addOnLoadCode("
            window.ExamSymbols = window.ExamSymbols || {};
            window.ExamSymbols.templatePath = '$template_path';
            window.ExamSymbols.config = {
                template: '$template',
                refId: $ref_id,
                symbols: $symbols_json,
                availableTemplates: $templates_json
            };
        ");
    }

    /**
     * Speichert ExamSymbols-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examsymbols_enabled']) ? 1 : 0;
            $template = $post_data['examsymbols_template'] ?? 'math';

            // Template validieren
            if (!$this->config->templateExists($template)) {
                $template = 'math'; // Fallback
            }

            $db->replace('examsymbols_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled],
                'template' => ['text', $template]
            ]);

            return true;
        } catch (Exception $e) {
            error_log("[ExamSymbols] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generiert HTML für Einstellungs-Formular
     */
    public function getSettingsHTML(int $ref_id): string
    {
        $enabled = $this->isEnabled($ref_id) ? 'checked' : '';
        $current_template = $this->getSymbolTemplate($ref_id);
        $options = '';

        // Templates aus Config-Klasse laden
        foreach ($this->config->getAvailableTemplates() as $key => $data) {
            $selected = $key === $current_template ? 'selected' : '';
            $label = htmlspecialchars($data['label'], ENT_QUOTES);
            $description = htmlspecialchars($data['description'], ENT_QUOTES);
            $options .= "<option value=\"$key\" $selected title=\"$description\">$label</option>";
        }

        return <<<HTML
<div class="mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="examsymbols_enabled" value="1" id="examsymbols_enabled" $enabled>
        <label class="form-check-label fw-bold" for="examsymbols_enabled">Sonderzeichen-Palette aktivieren</label>
    </div>
    <div class="form-text">Aktiviert die Spezialzeichen-Auswahl für diesen Test. <em>Shortcut: Alt + S</em></div>
</div>
<div class="mb-3">
    <label for="examsymbols_template" class="form-label fw-semibold">Symbol-Vorlage wählen:</label>
    <select name="examsymbols_template" id="examsymbols_template" class="form-select">
        $options
    </select>
    <div class="form-text">Wähle eine Vorlage für die Spezialzeichen, die im Test eingeblendet werden sollen.</div>
</div>
HTML;
    }

    /**
     * Gibt ILIAS-Formular-Elemente zurück (kein HTML)
     */
    public function getFormItems(int $ref_id): array
    {
        $cb = new ilCheckboxInputGUI('Sonderzeichen-Palette aktivieren', 'examsymbols_enabled');
        $cb->setChecked($this->isEnabled($ref_id));
        $cb->setInfo('Aktiviert die Spezialzeichen-Auswahl für diesen Test. Shortcut: Alt + S');

        $options = [];
        foreach ($this->config->getAvailableTemplates() as $key => $data) {
            $options[$key] = $data['label'];
        }
        $sel = new ilSelectInputGUI('Symbol-Vorlage', 'examsymbols_template');
        $sel->setOptions($options);
        $sel->setValue($this->getSymbolTemplate($ref_id));
        $sel->setInfo('Wähle eine Vorlage für die Spezialzeichen, die im Test eingeblendet werden sollen.');
        $cb->addSubItem($sel);

        return [$cb];
    }

    /**
     * Lädt das Symbol-Template für einen Test
     */
    private function getSymbolTemplate(int $ref_id): string
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            $query = "SELECT template FROM examsymbols_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                $template = $row['template'] ?? 'math';

                // Template validieren
                if ($this->config->templateExists($template)) {
                    return $template;
                }
            }
        } catch (Exception $e) {
            error_log("[ExamSymbols] Fehler beim Laden des Templates: " . $e->getMessage());
        }

        return 'math'; // Default
    }

    /**
     * ENTFERNT: getAvailableTemplates() - Verwende Config-Klasse
     * ENTFERNT: getSymbolsForTemplate() - Verwende Config-Klasse
     */

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        $stats = $this->config->getTemplateStats();
        $template_count = count($stats);
        $total_symbols = array_sum(array_column($stats, 'symbol_count'));

        return [
            'name' => 'ExamSymbols',
            'title' => 'Sonderzeichen-Palette',
            'icon' => '📝',
            'description' => "Palette mit Sonderzeichen für verschiedene Fachbereiche ($template_count Templates, $total_symbols Symbole)",
            'version' => '1.1.3',
            'features' => [
                'Floating Button (Alt + S)',
                "Multiple Templates ($template_count verfügbar)",
                'Rich-Text-Editor Support (TinyMCE, CKEditor)',
                'Focus-Tracking System',
                'Mobile Responsive'
            ]
        ];
    }
}
