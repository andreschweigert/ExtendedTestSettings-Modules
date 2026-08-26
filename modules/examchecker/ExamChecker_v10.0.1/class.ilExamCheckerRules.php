<?php

/**
 * ExamChecker Rules - Lesen und Schreiben der konfigurierten Soll-Werte
 * Part of ExtendedTestSettings Plugin
 *
 * Tabelle examchecker_rules: je Prüfungstyp und Check eine Zeile mit
 *   enabled        wird dieser Check geprüft?
 *   expected_value Soll-Wert als Text; wie er zu lesen ist, sagt der 'type'
 *                  der Definition (nicht die Datenbank — sonst kann die
 *                  Typangabe vom Code abdriften).
 *
 * Fehlt eine Zeile, gilt der Default aus ilExamCheckerDefinitions. Dadurch
 * erscheinen Checks, die eine spätere Modulversion ergänzt, automatisch mit
 * ihrer Vorgabe, ohne dass die Tabelle nachgezogen werden muss.
 */

class ilExamCheckerRules
{
    public const TABLE = 'examchecker_rules';

    private ilDBInterface $db;

    public function __construct(?ilDBInterface $db = null)
    {
        if ($db === null) {
            global $DIC;
            $db = $DIC->database();
        }
        $this->db = $db;
    }

    /**
     * Konfiguration eines Prüfungstyps: Definition-Defaults, überlagert von
     * den Zeilen der Tabelle.
     *
     * @return array<string,array{enabled:bool,expected:mixed}>
     */
    public function forExamType(string $exam_type): array
    {
        $rules = ilExamCheckerDefinitions::defaultsFor($exam_type);

        try {
            if (!$this->db->tableExists(self::TABLE)) {
                return $rules;
            }

            $res = $this->db->queryF(
                'SELECT check_key, enabled, expected_value FROM ' . self::TABLE
                . ' WHERE exam_type = %s',
                ['text'],
                [$exam_type]
            );

            while ($row = $this->db->fetchAssoc($res)) {
                $key = (string) $row['check_key'];
                $def = ilExamCheckerDefinitions::get($key);

                // Zeile eines Checks, den es nicht mehr gibt: überspringen,
                // nicht als unbekannten Check durchreichen.
                if ($def === null || !isset($rules[$key])) {
                    continue;
                }

                $rules[$key] = [
                    'enabled'  => (int) $row['enabled'] === 1,
                    'expected' => $this->castExpected((string) $row['expected_value'], $def),
                ];
            }
        } catch (Exception $e) {
            error_log('[ExamChecker] Regeln konnten nicht gelesen werden: ' . $e->getMessage());
        }

        return $rules;
    }

    /**
     * Schreibt die Konfiguration eines Prüfungstyps. Erwartet dieselbe Struktur
     * wie forExamType() liefert; unbekannte Keys werden verworfen.
     *
     * @param array<string,array{enabled:bool,expected:mixed}> $rules
     * @throws Exception wenn die Tabelle fehlt — dann ist das Modul nicht sauber installiert
     */
    public function save(string $exam_type, array $rules): void
    {
        if (!$this->db->tableExists(self::TABLE)) {
            throw new RuntimeException('Tabelle ' . self::TABLE . ' fehlt — Modul neu installieren.');
        }

        foreach ($rules as $key => $rule) {
            $def = ilExamCheckerDefinitions::get($key);
            if ($def === null || !in_array($exam_type, $def['relevant_for'], true)) {
                continue;
            }

            $this->db->replace(
                self::TABLE,
                [
                    'exam_type' => ['text', $exam_type],
                    'check_key' => ['text', $key],
                ],
                [
                    'enabled'        => ['integer', !empty($rule['enabled']) ? 1 : 0],
                    'expected_value' => ['text', $this->stringifyExpected($rule['expected'] ?? null, $def)],
                    'updated_at'     => ['timestamp', date('Y-m-d H:i:s')],
                ]
            );
        }
    }

    /**
     * Überträgt eine vorhandene 1.x-Konfiguration (kommagetrennte Check-Keys in
     * ilSetting) in die Tabelle. Wird aus sql/install.php aufgerufen, nachdem die
     * Tabelle neu angelegt wurde. Die alten Settings-Keys bleiben unangetastet,
     * damit ein Downgrade auf 1.x folgenlos bleibt.
     */
    public function seedFromLegacy(): void
    {
        $legacy_map = ilExamCheckerDefinitions::legacyKeyMap();

        foreach (['praesenz', 'fern'] as $exam_type) {
            $rules = ilExamCheckerDefinitions::defaultsFor($exam_type);

            $old = $this->readLegacySetting($exam_type);
            if ($old !== null) {
                // Alte Konfiguration vorhanden: sie entscheidet, was geprüft wird.
                // Checks ohne Vorgänger behalten ihre Vorgabe.
                $migrated = [];
                foreach ($old as $old_key) {
                    foreach ($legacy_map[$old_key] ?? [$old_key] as $new_key) {
                        $migrated[$new_key] = true;
                    }
                }

                $had_predecessor = $this->keysWithPredecessor($legacy_map);
                foreach ($rules as $key => $rule) {
                    if (isset($had_predecessor[$key])) {
                        $rules[$key]['enabled'] = isset($migrated[$key]);
                    }
                }
            }

            $this->save($exam_type, $rules);
        }
    }

    /**
     * Liest die 1.x-Liste; null bedeutet "nie konfiguriert" (dann gelten die Vorgaben).
     *
     * @return string[]|null
     */
    private function readLegacySetting(string $exam_type): ?array
    {
        try {
            $settings = new ilSetting('extendedtestsettings');
            $raw = (string) $settings->get('examchecker_standards_' . $exam_type, '');
        } catch (Exception $e) {
            return null;
        }

        if (trim($raw) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Check-Keys, die in 1.x ein Gegenstück hatten. Nur für diese darf die alte
     * Konfiguration die Aktivierung bestimmen — neue Checks behalten ihre Vorgabe.
     *
     * @param array<string,string[]> $legacy_map
     * @return array<string,true>
     */
    private function keysWithPredecessor(array $legacy_map): array
    {
        $legacy_keys = [
            'fragenpools_verwenden', 'datenschutz', 'online', 'passwort',
            'nur_ausgewaehlte_teilnehmer', 'testdurchlaeufe_begrenzen',
            'wartezeit_erzwingen', 'pruefungsansicht', 'automatisches_speichern',
            'direkte_rueckmeldung', 'fragen_markieren', 'eigenstaendigkeitserklaerung',
            'bearbeitungsdauer', 'weiterleitung', 'bewertungssystem', 'negative_punkte',
            'besten_durchlauf_bewerten', 'keine_vorbedingungen',
            'direkte_notenuebermittlung', 'zeitlich_begrenzte_verfuegbarkeit',
            'start_und_ende',
        ];

        $out = [];
        foreach ($legacy_keys as $old_key) {
            foreach ($legacy_map[$old_key] ?? [$old_key] as $new_key) {
                $out[$new_key] = true;
            }
        }
        return $out;
    }

    // ─── Typkonvertierung ────────────────────────────────────────────────────

    /**
     * Text aus der Tabelle -> typisierter Soll-Wert.
     *
     * @return mixed
     */
    private function castExpected(string $raw, array $def)
    {
        switch ($def['type']) {
            case ilExamCheckerDefinitions::TYPE_BOOL:
                return $raw === '1';

            case ilExamCheckerDefinitions::TYPE_INT:
            case ilExamCheckerDefinitions::TYPE_ENUM:
                return (int) $raw;

            case ilExamCheckerDefinitions::TYPE_TEXT:
                return $raw;

            default: // TYPE_INFO kennt keinen Soll-Wert
                return null;
        }
    }

    /**
     * Typisierter Soll-Wert -> Text für die Tabelle.
     */
    private function stringifyExpected($value, array $def): string
    {
        switch ($def['type']) {
            case ilExamCheckerDefinitions::TYPE_BOOL:
                return !empty($value) ? '1' : '0';

            case ilExamCheckerDefinitions::TYPE_INT:
            case ilExamCheckerDefinitions::TYPE_ENUM:
                return (string) (int) $value;

            case ilExamCheckerDefinitions::TYPE_TEXT:
                return mb_substr((string) $value, 0, 255);

            default:
                return '';
        }
    }
}
