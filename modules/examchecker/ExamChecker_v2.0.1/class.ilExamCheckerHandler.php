<?php

/**
 * ExamChecker Handler - Prüfung von Test-Einstellungen gegen konfigurierte Soll-Werte
 * Part of ExtendedTestSettings Plugin
 *
 * Der Handler rendert nur den Bedienblock in den erweiterten Test-Einstellungen.
 * Die Prüfung selbst läuft über ilExamCheckerGUI (Proxy-Endpunkt, cmd=check) —
 * bis Version 1.x wurde dafür cmd=saveSettings gekapert, was die saveSettings()
 * aller anderen Module mit einem fremden POST auslöste.
 *
 * Für die Plugin-Konfigurationsseite stellt der Handler den Check-Katalog und die
 * Regel-Verwaltung bereit (getCheckCatalogue/getRules/saveRules).
 */

class ilExamCheckerHandler implements ilExamModuleInterface
{
    private const MODULE_WEB_PATH =
        'Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/modules/examchecker';

    private string $plugin_path;
    private ilModuleLanguage $lng;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    // ─── Interface ───────────────────────────────────────────────────────────

    /**
     * ExamChecker ist ein Werkzeug, kein Testplayer-Feature — immer verfügbar.
     */
    public function isEnabled(int $ref_id): bool
    {
        return true;
    }

    /**
     * Kein Testplayer-Widget.
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
    }

    /**
     * ExamChecker speichert nichts pro Test. Seit 2.0.0 wird hier auch kein
     * AJAX-Aufruf mehr abgefangen.
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        return true;
    }

    public function getFormItems(int $ref_id): array
    {
        $this->registerAssets($ref_id);

        $item = new ilCustomInputGUI($this->lng->txt('check_title'), '');
        $item->setHTML($this->renderPanel());

        return [$item];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'ExamChecker',
            'title'       => $this->lng->txt('title'),
            'icon'        => '🔍',
            'description' => $this->lng->txt('description'),
            'version'     => '2.0.1',
            'features'    => [],
        ];
    }

    // ─── Bedienblock ─────────────────────────────────────────────────────────

    private function registerAssets(int $ref_id): void
    {
        global $DIC;

        $tpl = $DIC->ui()->mainTemplate();

        $config = [
            'refId'    => $ref_id,
            'token'    => ilExamCheckerToken::get(),
            'checkUrl' => ILIAS_HTTP_PATH . '/ilias.php?baseClass=ilUIPluginRouterGUI'
                . '&cmdClass=ilextendedtestsettingsmoduleproxygui'
                . '&module_id=examchecker&cmd=check&ref_id=' . $ref_id,
            'i18n'     => [
                'running'   => $this->lng->txt('checking'),
                'runningBtn' => $this->lng->txt('checking_btn'),
                'start'     => $this->lng->txt('start_check'),
                'error'     => $this->lng->txt('err_label'),
            ],
        ];

        // Regel R5: ohne HEX-Flags könnte ein Wert aus dem Script-Kontext ausbrechen.
        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        // Reihenfolge beachten: addJavaScript lädt vor addOnLoadCode, das JS
        // liest die Config deshalb erst beim Klick (siehe js/examchecker.js).
        $tpl->addOnLoadCode(
            'window.ExamChecker = window.ExamChecker || {};'
            . ' window.ExamChecker.config = ' . $json . ';'
        );
        $tpl->addJavaScript(self::MODULE_WEB_PATH . '/js/examchecker.js');
        $tpl->addCss(self::MODULE_WEB_PATH . '/css/examchecker.css');
    }

    private function renderPanel(): string
    {
        $options = '';
        foreach ($this->getExamTypes() as $key => $label) {
            $options .= '<option value="' . $this->esc($key) . '">' . $this->esc($label) . '</option>';
        }

        return '<div class="examchecker-panel">'
            . '<div class="examchecker-controls">'
            . '<label for="examchecker_type">' . $this->esc($this->lng->txt('exam_type_label')) . '</label>'
            . '<select id="examchecker_type" class="form-control">' . $options . '</select>'
            . '<button type="button" id="examchecker_start" class="btn btn-default">'
            . $this->esc($this->lng->txt('start_check'))
            . '</button>'
            . '</div>'
            . '<p class="examchecker-hint">' . $this->esc($this->lng->txt('panel_hint')) . '</p>'
            . '<div id="examchecker_status" class="examchecker-status" hidden></div>'
            . '<div id="examchecker_results"></div>'
            . '</div>';
    }

    // ─── API für die Plugin-Konfigurationsseite ──────────────────────────────

    /**
     * Prüfungstypen mit übersetztem Namen.
     *
     * @return array<string,string>
     */
    public function getExamTypes(): array
    {
        return [
            'praesenz' => $this->lng->txt('type_praesenz'),
            'fern'     => $this->lng->txt('type_fern'),
        ];
    }

    /**
     * Übersetzte Abschnittsnamen der Konfigurationsseite.
     *
     * @return array<string,string>
     */
    public function getGroupLabels(): array
    {
        $out = [];
        foreach (ilExamCheckerDefinitions::GROUPS as $group) {
            $out[$group] = $this->lng->txt('grp_' . $group);
        }
        return $out;
    }

    /**
     * Check-Katalog eines Prüfungstyps, aufbereitet für das Konfigurationsformular:
     * übersetzte Titel, Erläuterungen, Enum-Optionen und Einheiten.
     *
     * Die ConfigGUI muss dadurch weder die Sprachschlüssel noch den Aufbau der
     * Definitionen kennen.
     *
     * @return array<string,array>
     */
    public function getCheckCatalogue(string $exam_type): array
    {
        $out = [];

        foreach (ilExamCheckerDefinitions::forExamType($exam_type) as $key => $def) {
            $entry = [
                'key'         => $key,
                'group'       => $def['group'],
                'title'       => $this->lng->txt('chk_' . $key),
                'info'        => $this->lng->txt('chk_' . $key . '_info', ''),
                'type'        => $def['type'],
                'recommended' => $def['default'] ?? null,
            ];

            if ($def['type'] === ilExamCheckerDefinitions::TYPE_ENUM) {
                $entry['options'] = [];
                foreach ($def['options'] as $value => $lang_key) {
                    $entry['options'][$value] = $this->lng->txt($lang_key);
                }
            }

            if ($def['type'] === ilExamCheckerDefinitions::TYPE_INT && isset($def['unit'])) {
                $entry['unit'] = $this->lng->txt($def['unit']);
            }

            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * Aktuelle Regeln (Aktivierung + Soll-Wert) eines Prüfungstyps.
     *
     * @return array<string,array{enabled:bool,expected:mixed}>
     */
    public function getRules(string $exam_type): array
    {
        return (new ilExamCheckerRules())->forExamType($exam_type);
    }

    /**
     * Regeln eines Prüfungstyps speichern.
     *
     * @param array<string,array{enabled:bool,expected:mixed}> $rules
     * @throws RuntimeException wenn die Modultabelle fehlt
     */
    public function saveRules(string $exam_type, array $rules): void
    {
        (new ilExamCheckerRules())->save($exam_type, $rules);
    }

    /**
     * Übersetzt einen Sprachschlüssel des Moduls — die ConfigGUI nutzt das für
     * Überschriften und Meldungen des ExamChecker-Tabs.
     */
    public function txt(string $key, ?string $default = null): string
    {
        return $this->lng->txt($key, $default);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
