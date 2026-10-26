<?php
/**
 * ExamKeymap Admin GUI - Globale Übersetzungstabelle mit virtuellem Keyboard-Editor
 * Part of ExtendedTestSettings Plugin
 *
 * Wird von ilExtendedTestSettingsConfigGUI als Tab eingebunden
 * (Muster: ExamNetQos/ExamLogger) — kein Proxy-Routing nötig.
 *
 * Commands (über ConfigGUI):
 *   showKeymap — Keyboard-Editor anzeigen
 *   saveKeymap — POST der Overrides (JSON im Feld keymap_overrides)
 */
class ilExamKeymapAdminGUI
{
    private const TABLE_CUSTOM = 'examkeymap_custom';

    // Alle editierbaren physischen Tasten (KeyboardEvent.code) — muss mit den
    // eingebauten Tabellen in js/examkeymap.js übereinstimmen
    private const ALLOWED_CODES = [
        'KeyA', 'KeyB', 'KeyC', 'KeyD', 'KeyE', 'KeyF', 'KeyG', 'KeyH', 'KeyI',
        'KeyJ', 'KeyK', 'KeyL', 'KeyM', 'KeyN', 'KeyO', 'KeyP', 'KeyQ', 'KeyR',
        'KeyS', 'KeyT', 'KeyU', 'KeyV', 'KeyW', 'KeyX', 'KeyY', 'KeyZ',
        'Digit1', 'Digit2', 'Digit3', 'Digit4', 'Digit5', 'Digit6', 'Digit7',
        'Digit8', 'Digit9', 'Digit0',
        'Minus', 'Equal', 'BracketLeft', 'BracketRight', 'Semicolon', 'Quote',
        'Backslash', 'Backquote', 'Comma', 'Period', 'Slash', 'IntlBackslash',
    ];

    private ilModuleLanguage $lng;

    private string $saveUrl = '';

    public function __construct()
    {
        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once __DIR__ . '/../../classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    public function setSaveCmd(string $cmd): void
    {
        // Kompatibilität mit der ConfigGUI; die Action kommt über setSaveUrl().
    }

    public function setSaveUrl(string $url): void
    {
        $this->saveUrl = $url;
    }

    // ─── Editor-Ansicht ──────────────────────────────────────────────────────

    public function showEditor(): void
    {
        if ($this->saveUrl === '') {
            throw new LogicException('ExamKeymap: CSRF-geschützte Formular-URL fehlt');
        }

        global $DIC;
        $tpl = $DIC['tpl'];

        $web = $this->getModuleWebPath();

        $tpl->addCss($web . '/css/examkeymap_admin.css');

        $config = [
            'overrides' => $this->loadOverrides(),
            'txt'       => [
                'layout_de'    => $this->lng->txt('adm_layout_de'),
                'layout_en'    => $this->lng->txt('adm_layout_en'),
                'base'         => $this->lng->txt('adm_base'),
                'shift'        => $this->lng->txt('adm_shift'),
                'altgr'        => $this->lng->txt('adm_altgr'),
                'apply'        => $this->lng->txt('adm_apply'),
                'remove'       => $this->lng->txt('adm_remove'),
                'select_hint'  => $this->lng->txt('adm_select_hint'),
                'override'     => $this->lng->txt('adm_override'),
                'disabled'     => $this->lng->txt('adm_key_disabled'),
                'empty_hint'   => $this->lng->txt('adm_empty_hint'),
                'caps_hint'    => $this->lng->txt('adm_caps_hint'),
                'dirty_hint'   => $this->lng->txt('adm_dirty_hint'),
            ],
        ];

        $config_json = json_encode($config);
        $tpl->addOnLoadCode("
            window.ExamKeymapAdmin = window.ExamKeymapAdmin || {};
            window.ExamKeymapAdmin.config = {$config_json};
        ");

        // examkeymap.js liefert die eingebauten Tabellen (window.ExamKeymap.tables);
        // ohne config.player bleibt die Player-Logik dort inaktiv
        $tpl->addJavaScript($web . '/js/examkeymap.js');
        $tpl->addJavaScript($web . '/js/examkeymap_admin.js');

        $intro  = '<p>' . $this->lng->txt('adm_intro') . '</p>';
        $action = htmlspecialchars($this->saveUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<div class="examkeymap-admin">'
            . $intro
            . '<div id="examkeymap-admin-root"></div>'
            . '<form method="post" action="' . $action . '" id="examkeymap-admin-form">'
            . '<input type="hidden" name="keymap_overrides" id="examkeymap-overrides" value="">'
            . '<button type="submit" class="btn btn-primary">' . $this->lng->txt('adm_save') . '</button>'
            . '</form>'
            . '</div>';

        $tpl->setContent($html);
    }

    // ─── Speichern ───────────────────────────────────────────────────────────

    /**
     * Verarbeitet den POST des Editors.
     * Rückgabe: [Meldung, Meldungstyp] für setOnScreenMessage().
     */
    public function handleSave(): array
    {
        global $DIC;
        $db = $DIC->database();

        $raw  = (string) ($_POST['keymap_overrides'] ?? '');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [$this->lng->txt('adm_error_json'), 'failure'];
        }

        $rows = [];
        foreach (['de', 'en'] as $layout) {
            if (!isset($data[$layout]) || !is_array($data[$layout])) {
                continue;
            }
            foreach ($data[$layout] as $code => $def) {
                if (!in_array($code, self::ALLOWED_CODES, true) || !is_array($def)) {
                    continue;
                }
                $rows[] = [
                    'layout'     => $layout,
                    'keycode'    => $code,
                    'base_char'  => $this->sanitizeChar($def['b'] ?? ''),
                    'shift_char' => $this->sanitizeChar($def['s'] ?? ''),
                    'altgr_char' => $this->sanitizeChar($def['g'] ?? ''),
                ];
            }
        }

        try {
            // Komplettabgleich: alte Overrides löschen, aktuelle einfügen
            $db->manipulate('DELETE FROM ' . self::TABLE_CUSTOM);

            foreach ($rows as $row) {
                $db->insert(self::TABLE_CUSTOM, [
                    'layout'     => ['text', $row['layout']],
                    'keycode'    => ['text', $row['keycode']],
                    'base_char'  => ['text', $row['base_char']],
                    'shift_char' => ['text', $row['shift_char']],
                    'altgr_char' => ['text', $row['altgr_char']],
                ]);
            }
        } catch (Exception $e) {
            error_log('[ExamKeymap] Fehler beim Speichern der Übersetzungstabelle: ' . $e->getMessage());
            return [$this->lng->txt('adm_error_save'), 'failure'];
        }

        return [sprintf($this->lng->txt('adm_saved'), count($rows)), 'success'];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Lädt die gespeicherten Overrides für den Editor.
     */
    private function loadOverrides(): array
    {
        global $DIC;
        $db = $DIC->database();

        $overrides = ['de' => new stdClass(), 'en' => new stdClass()];

        try {
            $result = $db->query(
                'SELECT layout, keycode, base_char, shift_char, altgr_char FROM ' . self::TABLE_CUSTOM
            );
            while ($row = $db->fetchAssoc($result)) {
                $layout = $row['layout'];
                if (!isset($overrides[$layout])) {
                    continue;
                }
                $overrides[$layout]->{$row['keycode']} = [
                    'b' => (string) ($row['base_char'] ?? ''),
                    's' => (string) ($row['shift_char'] ?? ''),
                    'g' => (string) ($row['altgr_char'] ?? ''),
                ];
            }
        } catch (Exception $e) {
            error_log('[ExamKeymap] Fehler beim Laden der Übersetzungstabelle: ' . $e->getMessage());
        }

        return $overrides;
    }

    /**
     * Bereinigt ein Mapping-Zeichen: keine Steuerzeichen, max. 4 Zeichen.
     */
    private function sanitizeChar($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return mb_substr($value, 0, 4);
    }

    /**
     * Web-relativer Pfad des Modulordners (für addCss/addJavaScript).
     */
    private function getModuleWebPath(): string
    {
        $dir = str_replace('\\', '/', __DIR__);
        if (defined('ILIAS_ABSOLUTE_PATH')) {
            $root = rtrim(str_replace('\\', '/', ILIAS_ABSOLUTE_PATH), '/') . '/';
            if (str_starts_with($dir, $root)) {
                return substr($dir, strlen($root));
            }
        }
        return 'Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/modules/examkeymap';
    }

}
