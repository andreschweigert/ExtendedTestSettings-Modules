<?php

/**
 * ExamChecker Definitions - Katalog aller prüfbaren Einstellungen
 * Part of ExtendedTestSettings Plugin
 *
 * Einzige Quelle der Wahrheit für die Checks: Woher der Ist-Wert kommt, wie er zu
 * lesen ist und welcher Soll-Wert vorgegeben ist. Sowohl der Handler (Auswertung)
 * als auch die Plugin-ConfigGUI (Formular) bauen ausschließlich hierauf auf.
 *
 * Struktur eines Eintrags:
 *   group           Abschnitt in der Konfigurationsseite (lang-Key: grp_<group>)
 *   source          'tst_tests' = Spalte der Tabelle | 'custom' = Evaluator im Handler
 *   column          Spaltenname bei source = 'tst_tests'
 *   evaluator       Methodenname im Handler bei source = 'custom'
 *   type            bool | int | enum | text | info
 *   invert          true = der Ist-Wert wird negiert (DB speichert die Gegenrichtung)
 *   options         nur bei type = enum: [DB-Wert => lang-Key]
 *   factor          nur bei type = int: Soll-Wert * factor = DB-Wert (z. B. Sek. -> Millisek.)
 *   unit            nur bei type = int: lang-Key der Einheit
 *   default         vorgegebener Soll-Wert
 *   enabled_default ob der Check ohne Konfiguration geprüft wird
 *   relevant_for    Prüfungstypen, für die der Check angeboten wird
 *
 * type = info kennt keinen Soll-Wert: Der Ist-Wert wird nur angezeigt, weil er sich
 * nicht allgemeingültig vorgeben lässt (Prüfungszeitraum, Bearbeitungsdauer).
 *
 * Sprachschlüssel je Check: chk_<key> (Titel) und chk_<key>_info (Erläuterung).
 */

class ilExamCheckerDefinitions
{
    public const TYPE_BOOL = 'bool';
    public const TYPE_INT  = 'int';
    public const TYPE_ENUM = 'enum';
    public const TYPE_TEXT = 'text';
    public const TYPE_INFO = 'info';

    /** Dreiwertiges Prüfergebnis — ein technischer Fehler ist NIE "bestanden" (Regel R10). */
    public const RESULT_OK      = 'ok';
    public const RESULT_FAILED  = 'failed';
    public const RESULT_UNKNOWN = 'unknown';

    /** Reihenfolge der Abschnitte in der Konfigurationsseite und im Ergebnis. */
    public const GROUPS = ['allgemein', 'zugang', 'durchfuehrung', 'auswertung', 'abschluss'];

    /**
     * Vollständiger Katalog.
     *
     * @return array<string,array>
     */
    public static function all(): array
    {
        return [

            // ── Allgemeine Einstellungen ──────────────────────────────────────

            'fragenpools_verwenden' => [
                'group'           => 'allgemein',
                'source'          => 'custom',
                'evaluator'       => 'evalUsesQuestionPools',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // anonymity = 1 bedeutet "anonym"; geprüft wird die Leserichtung des
            // Titels ("mit Namen"), deshalb invert.
            'datenschutz' => [
                'group'           => 'allgemein',
                'source'          => 'tst_tests',
                'column'          => 'anonymity',
                'type'            => self::TYPE_BOOL,
                'invert'          => true,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // Der Online-Status liegt seit ILIAS 9 in object_data.offline,
            // NICHT mehr in tst_tests.online_status (dort tote Spalte).
            'online' => [
                'group'           => 'allgemein',
                'source'          => 'custom',
                'evaluator'       => 'evalIsOnline',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'zeitlich_begrenzte_verfuegbarkeit' => [
                'group'           => 'allgemein',
                'source'          => 'custom',
                'evaluator'       => 'evalActivationLimited',
                'type'            => self::TYPE_BOOL,
                'default'         => false,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // ── Zugang ────────────────────────────────────────────────────────

            'passwort' => [
                'group'           => 'zugang',
                'source'          => 'tst_tests',
                'column'          => 'password_enabled',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'nur_ausgewaehlte_teilnehmer' => [
                'group'           => 'zugang',
                'source'          => 'tst_tests',
                'column'          => 'fixed_participants',
                'type'            => self::TYPE_BOOL,
                'default'         => false,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'vorbedingungen' => [
                'group'           => 'zugang',
                'source'          => 'custom',
                'evaluator'       => 'evalHasConditions',
                'type'            => self::TYPE_BOOL,
                'default'         => false,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'startzeit_aktiviert' => [
                'group'           => 'zugang',
                'source'          => 'tst_tests',
                'column'          => 'starting_time_enabled',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'endzeit_aktiviert' => [
                'group'           => 'zugang',
                'source'          => 'tst_tests',
                'column'          => 'ending_time_enabled',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // Kein Soll-Wert: der Zeitraum ist je Prüfung anders und wird
            // zur Sichtprüfung angezeigt.
            'pruefungszeitraum' => [
                'group'           => 'zugang',
                'source'          => 'custom',
                'evaluator'       => 'evalExamPeriod',
                'type'            => self::TYPE_INFO,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // ── Durchführung ──────────────────────────────────────────────────

            'testdurchlaeufe_begrenzen' => [
                'group'           => 'durchfuehrung',
                'source'          => 'tst_tests',
                'column'          => 'nr_of_tries',
                'type'            => self::TYPE_INT,
                'default'         => 1,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'wartezeit_erzwingen' => [
                'group'           => 'durchfuehrung',
                'source'          => 'custom',
                'evaluator'       => 'evalPassWaiting',
                'type'            => self::TYPE_BOOL,
                'default'         => false,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // kiosk ist eine Bitmaske (1 = an, +2 Titel, +4 Teilnehmername),
            // deshalb kein direkter Spaltenvergleich.
            'pruefungsansicht' => [
                'group'           => 'durchfuehrung',
                'source'          => 'custom',
                'evaluator'       => 'evalKioskEnabled',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'automatisches_speichern' => [
                'group'           => 'durchfuehrung',
                'source'          => 'tst_tests',
                'column'          => 'autosave',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // ILIAS speichert Millisekunden, konfiguriert und angezeigt werden Sekunden.
            'autosave_intervall' => [
                'group'           => 'durchfuehrung',
                'source'          => 'tst_tests',
                'column'          => 'autosave_ival',
                'type'            => self::TYPE_INT,
                'factor'          => 1000,
                'unit'            => 'unit_seconds',
                'default'         => 30,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'direkte_rueckmeldung' => [
                'group'           => 'durchfuehrung',
                'source'          => 'tst_tests',
                'column'          => 'instant_verification',
                'type'            => self::TYPE_BOOL,
                'default'         => false,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'fragen_markieren' => [
                'group'           => 'durchfuehrung',
                'source'          => 'tst_tests',
                'column'          => 'show_marker',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // ILIAS 9 kennt keine "Eigenständigkeitserklärung"; die fachlich
            // entsprechende Einstellung heißt "Prüfungsbedingungen".
            'eigenstaendigkeitserklaerung' => [
                'group'           => 'durchfuehrung',
                'source'          => 'tst_tests',
                'column'          => 'conditions_checkbox_enabled',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['fern'],
            ],

            'bearbeitungsdauer' => [
                'group'           => 'durchfuehrung',
                'source'          => 'tst_tests',
                'column'          => 'enable_processing_time',
                'type'            => self::TYPE_BOOL,
                'default'         => true,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // processing_time ist "HH:MM:SS" — kein sinnvoller globaler Soll-Wert.
            'bearbeitungsdauer_minuten' => [
                'group'           => 'durchfuehrung',
                'source'          => 'custom',
                'evaluator'       => 'evalProcessingMinutes',
                'type'            => self::TYPE_INFO,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // ── Auswertung ────────────────────────────────────────────────────

            'bewertungssystem' => [
                'group'           => 'auswertung',
                'source'          => 'tst_tests',
                'column'          => 'count_system',
                'type'            => self::TYPE_ENUM,
                'options'         => [
                    0 => 'opt_count_partial',
                    1 => 'opt_count_full',
                ],
                'default'         => 0,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'negative_punkte' => [
                'group'           => 'auswertung',
                'source'          => 'tst_tests',
                'column'          => 'score_cutting',
                'type'            => self::TYPE_ENUM,
                'options'         => [
                    0 => 'opt_cut_question',
                    1 => 'opt_cut_test',
                ],
                'default'         => 0,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'besten_durchlauf_bewerten' => [
                'group'           => 'auswertung',
                'source'          => 'tst_tests',
                'column'          => 'pass_scoring',
                'type'            => self::TYPE_ENUM,
                'options'         => [
                    0 => 'opt_pass_last',
                    1 => 'opt_pass_best',
                ],
                'default'         => 1,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            'direkte_notenuebermittlung' => [
                'group'           => 'auswertung',
                'source'          => 'tst_tests',
                'column'          => 'score_reporting',
                'type'            => self::TYPE_ENUM,
                'options'         => [
                    0 => 'opt_report_never',
                    1 => 'opt_report_finished',
                    2 => 'opt_report_always',
                    3 => 'opt_report_date',
                    4 => 'opt_report_passed',
                ],
                'default'         => 0,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz', 'fern'],
            ],

            // ── Abschluss ─────────────────────────────────────────────────────

            'weiterleitungsmodus' => [
                'group'           => 'abschluss',
                'source'          => 'tst_tests',
                'column'          => 'redirection_mode',
                'type'            => self::TYPE_ENUM,
                'options'         => [
                    0 => 'opt_redirect_none',
                    1 => 'opt_redirect_always',
                    2 => 'opt_redirect_kiosk',
                    3 => 'opt_redirect_logout',
                ],
                'default'         => 2,
                'enabled_default' => true,
                'relevant_for'    => ['praesenz'],
            ],

            'weiterleitungsziel' => [
                'group'           => 'abschluss',
                'source'          => 'tst_tests',
                'column'          => 'redirection_url',
                'type'            => self::TYPE_TEXT,
                'default'         => 'logout.php',
                'enabled_default' => true,
                'relevant_for'    => ['praesenz'],
            ],
        ];
    }

    /**
     * Definitionen eines Prüfungstyps, in Gruppenreihenfolge.
     *
     * @return array<string,array>
     */
    public static function forExamType(string $exam_type): array
    {
        $out = [];
        foreach (self::GROUPS as $group) {
            foreach (self::all() as $key => $def) {
                if ($def['group'] === $group
                    && in_array($exam_type, $def['relevant_for'], true)
                ) {
                    $out[$key] = $def;
                }
            }
        }
        return $out;
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Soll-Werte und Aktivierung, wie sie ohne jede Konfiguration gelten.
     *
     * @return array<string,array{enabled:bool,expected:mixed}>
     */
    public static function defaultsFor(string $exam_type): array
    {
        $out = [];
        foreach (self::forExamType($exam_type) as $key => $def) {
            $out[$key] = [
                'enabled'  => (bool) ($def['enabled_default'] ?? true),
                'expected' => $def['default'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Zuordnung der Check-Keys aus Version 1.x auf die heutigen Keys.
     * Wird einmalig bei der Migration der alten ilSetting-Listen gebraucht;
     * nicht aufgeführte Keys heißen unverändert.
     *
     * @return array<string,string[]>
     */
    public static function legacyKeyMap(): array
    {
        return [
            'keine_vorbedingungen'     => ['vorbedingungen'],
            'weiterleitung'            => ['weiterleitungsmodus', 'weiterleitungsziel'],
            'automatisches_speichern'  => ['automatisches_speichern', 'autosave_intervall'],
            'start_und_ende'           => ['startzeit_aktiviert', 'endzeit_aktiviert'],
        ];
    }
}
