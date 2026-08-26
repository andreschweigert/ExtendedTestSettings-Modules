# ExamChecker — Entwicklerdokumentation

**Modul-ID:** `examchecker`
**Speicherort:** `modules/examchecker/` (externes Modul, nicht im Haupt-Repo)
**Referenz-ZIP:** `modules-zip/modules/examchecker/ExamChecker_v10.0.1.zip`
**Zuletzt aktualisiert:** 2026-08-26

---

## ILIAS-10-Port

Branch `ilias10`; Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1.
module.json: `ilias_min`/`ilias_max` = 10.

- **10.0.0 (2026-07-07):** Start der ILIAS-10-Linie. Keine Code-Änderungen nötig — Web-Asset-Pfade
  (addJavaScript/addCss) laufen über den zentralen Registry-Compat des Hauptplugins (Handler
  erhalten den web-relativen Pfad via `getRelativeDirectory()`).
- **10.0.1 (2026-08-26):** Port von 2.0.0. Ebenfalls ohne Code-Änderung — die geprüfte Kern-API ist
  in ILIAS 10 unverändert: `tst_tests` inkl. `conditions_checkbox_enabled`, `kiosk`, `autosave_ival`;
  `ScoreReportingTypes` mit denselben Werten 0–4; `kiosk` weiterhin Bitmaske (1/2/4);
  `qpl_questions.original_id`; `object_data.offline`; `crs_items` mit
  `ilObjectActivation::TIMINGS_ACTIVATION = 0`; `conditions.target_ref_id`.
  Setzt Plugin **10.1.1** voraus.

---

## Was ist ExamChecker?

Prüft die Einstellungen eines ILIAS-Tests gegen Soll-Werte, die in der
Plugin-Konfiguration je Prüfungstyp (`praesenz` / `fern`) festgelegt werden.

Der Prüfer wählt den Prüfungstyp und startet den Check. Das Ergebnis erscheint als
Tabelle je Themenbereich mit Ist- und Soll-Wert.

`isEnabled()` gibt immer `true` zurück — das Werkzeug ist unabhängig vom einzelnen
Test verfügbar. `injectAssets()` ist ein No-op: kein Testplayer-Widget.

---

## Aufbau

```
modules/examchecker/
├── module.json
├── class.ilExamCheckerDefinitions.php   ← Check-Katalog (Quelle der Wahrheit)
├── class.ilExamCheckerRules.php         ← Tabelle examchecker_rules lesen/schreiben
├── class.ilExamCheckerEvaluator.php     ← Ist-Werte lesen, gegen Soll vergleichen
├── class.ilExamCheckerToken.php         ← Sessiontoken für den AJAX-Endpunkt
├── class.ilExamCheckerHandler.php       ← Bedienblock + API für die ConfigGUI
├── class.ilExamCheckerGUI.php           ← Proxy-Endpunkt cmd=check, Ergebnis-HTML
├── css/examchecker.css
├── js/examchecker.js
├── lang/ilias_de.lang · ilias_en.lang
└── sql/install.php · uninstall.php
```

`require_files` lädt Definitions, Rules, Evaluator und Token — sowohl die Registry
(vor dem Handler) als auch der Proxy (vor der GUI) arbeiten diese Liste ab.

---

## Der Check-Katalog

`ilExamCheckerDefinitions::all()` ist die **einzige** Stelle, an der ein Check
beschrieben wird: Herkunft des Werts, Typ, Soll-Vorgabe, Prüfungstypen. Handler,
Evaluator und die Plugin-ConfigGUI bauen ausschließlich darauf auf.

```php
'passwort' => [
    'group'           => 'zugang',
    'source'          => 'tst_tests',   // oder 'custom'
    'column'          => 'password_enabled',
    'type'            => self::TYPE_BOOL,
    'default'         => true,          // Soll-Vorgabe
    'enabled_default' => true,
    'relevant_for'    => ['praesenz', 'fern'],
],
```

| Feld | Bedeutung |
|---|---|
| `group` | Abschnitt (`allgemein`, `zugang`, `durchfuehrung`, `auswertung`, `abschluss`) |
| `source` | `tst_tests` = Spalte der Testtabelle · `custom` = Evaluator-Methode |
| `column` / `evaluator` | Spaltenname bzw. Methodenname im Evaluator |
| `type` | `bool` · `int` · `enum` · `text` · `info` |
| `invert` | Ist-Wert negieren (DB speichert die Gegenrichtung, z. B. `anonymity`) |
| `options` | nur `enum`: `[DB-Wert => lang-Key]` |
| `factor` / `unit` | nur `int`: Soll-Wert × factor = DB-Wert (Sekunden → Millisekunden) |
| `default` | Soll-Vorgabe ohne Konfiguration |
| `relevant_for` | Prüfungstypen, für die der Check angeboten wird |

`type = info` kennt keinen Soll-Wert — der Ist-Wert wird nur angezeigt, weil er sich
nicht allgemeingültig vorgeben lässt (Prüfungszeitraum, Bearbeitungsdauer).

Sprachschlüssel je Check: `chk_<key>` und `chk_<key>_info`, beide im Modul.

### Einen Check hinzufügen

1. Eintrag in `ilExamCheckerDefinitions::all()`
2. Bei `source = custom`: Evaluator-Methode in `ilExamCheckerEvaluator` ergänzen
3. `chk_<key>` und `chk_<key>_info` in **beide** Sprachdateien
4. Version bumpen, ZIP bauen

Weder die ConfigGUI noch das Formular müssen angefasst werden — beide leiten sich
aus dem Katalog ab. Die Tabelle muss ebenfalls nicht nachgezogen werden: fehlt eine
Zeile, gilt der Default aus dem Katalog.

---

## Datenbank

### Eigene Tabelle

```
examchecker_rules
  exam_type       text(20)     'praesenz' | 'fern'        ┐ Primärschlüssel
  check_key       text(64)     Schlüssel aus dem Katalog  ┘
  enabled         integer(1)   wird dieser Check geprüft?
  expected_value  text(255)    Soll-Wert als Text
  updated_at      timestamp
```

Rund 50 Zeilen für die gesamte Installation. Kein Personenbezug, deshalb keine
Aufbewahrungsfrist nötig; `sql/uninstall.php` löscht die Tabelle.

`expected_value` ist bewusst **Text für alle Typen** — wie er zu lesen ist, sagt der
`type` des Katalogs. Der Typ steht bewusst **nicht** in der Tabelle: was eine
Einstellung bedeutet, weiß der Code, und eine zweite Quelle könnte davon abdriften.

### Gelesene ILIAS-Kerntabellen

| Tabelle | Wofür |
|---|---|
| `tst_tests` | Die meisten Einstellungen, Zeile über `obj_fi` |
| `object_data` | Online-Status (`offline`), Titel/Typ von Vorbedingungsobjekten |
| `crs_items` | Zeitlich begrenzte Verfügbarkeit (`timing_type`) |
| `conditions` | Vorbedingungen, gefiltert über `target_ref_id` |
| `tst_test_question` + `qpl_questions` | Herkunft der Fragen (`original_id`) |

---

## Ablauf einer Prüfung

```
Klick auf "Prüfung starten"
  → js/examchecker.js liest window.ExamChecker.config (erst beim Klick!)
  → POST auf den Proxy: cmdClass=ilextendedtestsettingsmoduleproxygui
                        &module_id=examchecker&cmd=check&ref_id=<ref_id>
                        Body: examchecker_token, exam_type

Proxy  → Modul aktiv? ref_id ein Test? Schreibrecht?
GUI    → POST erzwungen, Token per hash_equals geprüft
       → ilExamCheckerEvaluator::run(ref_id, exam_type)
           · tst_tests-Zeile laden
           · Regeln aus examchecker_rules holen (Defaults als Unterlage)
           · je aktivem Check: Ist-Wert lesen → casten → vergleichen
       → Ergebnis-HTML rendern, als JSON zurückgeben

Browser → HTML in #examchecker_results einsetzen
```

### Dreiwertiges Ergebnis

| Status | Bedeutung |
|---|---|
| `ok` | Ist-Wert entspricht dem Soll-Wert |
| `failed` | Ist-Wert weicht ab |
| `unknown` | Wert war technisch nicht ermittelbar — etwa weil die Spalte in dieser ILIAS-Version fehlt |
| `info` | `type = info`: nur Anzeige, kein Vergleich |

`unknown` ist der Kern von Sicherheitsregel R10 (Finding M-10): Ein Freigabewerkzeug,
das bei technischen Fehlern grün meldet, ist schlechter als keines. Bis 1.2.0 gab
`checkZeitlichBegrenzteVerfuegbarkeit()` im `catch` ein `return true` zurück, und
fehlende Spalten wurden über `(int)null === 1` still zu „nicht erfüllt".

---

## Was an den Prüfungen falsch war (behoben in 10.0.1 / 2.0.0 der 9er-Linie)

Alles gegen ILIAS 9.x und eine echte Installation verifiziert.

| Check | Bis 1.2.0 | Problem | Jetzt |
|---|---|---|---|
| Fragenpools | `tst_tests.pool_usage = 1` | Spalte wird von ILIAS 9 nicht mehr gepflegt (nur noch Survey nutzt sie), Wert immer `NULL` → Check schlug **immer** fehl | `question_set_type` bzw. `qpl_questions.original_id` |
| Online-Status | `tst_tests.online_status = 1` | Tote Spalte; der Status liegt seit ILIAS 9 in `object_data.offline`. Auch bei online geschalteten Tests stand dort `0` → Check schlug **immer** fehl | `object_data.offline = 0` |
| Eigenständigkeitserklärung | `require_authorship_statement` | Spalte existiert im gesamten ILIAS-9-Baum nicht → PHP-Warnung, Check schlug **immer** fehl | `conditions_checkbox_enabled` („Prüfungsbedingungen") |
| Prüfungsansicht | `kiosk = 1` | `kiosk` ist eine Bitmaske (1 = an, +2 Titel, +4 Teilnehmername); bei eingeblendetem Titel steht dort 3, 5 oder 7 | `(kiosk & 1) === 1` |
| Zeitlich begrenzte Verfügbarkeit | `timing_start = 0 && timing_end = 0` | `timing_start`/`timing_end` bleiben als Altwerte stehen; maßgeblich ist `timing_type` (0 = begrenzt, 1 = aus) | `timing_type === 0` |
| Bearbeitungsdauer | `processing_time > 0`, Anzeige „… Minuten" | `processing_time` ist `HH:MM:SS`; der Vergleich war ein zufällig funktionierender String-Vergleich, die Anzeige schlicht falsch | Format geparst, Anzeige in Minuten |
| Wartezeit | `empty() \|\| === '0'` | Ein gespeichertes `"0:0:0"` bedeutet keine Wartezeit, galt aber als gesetzt | Alle Komponenten auf 0 geprüft |
| Vorbedingungen | `target_obj_id = ? OR target_ref_id = ?` | `OR` über zwei ID-Räume; die `ref_id` wurde zudem per `LIMIT 1` neu hergeleitet statt der bekannten benutzt | `target_ref_id = <echte ref_id>` |

Weiter entfielen: die globale Variable `$examchecker_conditions_details`,
`checkAuswertung()` (gab immer `[]` zurück), die Magic-Daten in `isDefaultDateTime()`
(`01.04.2022`, `01.12.2022`) und die ungenutzte Klasse `ilExamCheckerConfig`.

---

## Endpunkt statt gekapertem saveSettings

Bis 1.2.0 ersetzte das JavaScript in der URL `cmd=show` durch `cmd=saveSettings` und
schickte dorthin seinen AJAX-POST. `ilExtendedTestSettingsAdvancedGUI::saveSettings()`
ruft aber `saveSettings()` **aller aktiven Module** auf. Module, die ihren Zustand aus
`isset($post_data['<modul>_enabled'])` ableiten — ExamAlert, ExamBattery, ExamCalc —
sahen den Schlüssel im fremden POST nicht und **deaktivierten sich für den Test**.
Das anschließende `exit` in `handleExamCheck()` brach die Schleife ab, sodass alle
alphabetisch späteren Module gar nicht mehr gespeichert wurden.

Seit 10.0.1 läuft der Check über den regulären Proxy (`cmd=check`); `saveSettings()`
gibt nur noch `true` zurück.

---

## API für die Plugin-ConfigGUI

Der Handler stellt bereit, was der ExamChecker-Reiter braucht. Der Plugin-Kern kennt
dadurch weder die geprüften Spalten noch die Sprachschlüssel des Moduls.

```php
getExamTypes(): array                       // ['praesenz' => 'Präsenzprüfung', …]
getGroupLabels(): array                     // ['zugang' => 'Zugang', …]
getCheckCatalogue(string $exam_type): array // übersetzte Titel, Optionen, Einheiten
getRules(string $exam_type): array          // ['passwort' => ['enabled'=>…, 'expected'=>…]]
saveRules(string $exam_type, array $rules): void
txt(string $key, ?string $default = null): string
```

Die ConfigGUI prüft diese Methoden per `method_exists()`. Fehlen sie (ExamChecker
vor 10.0.1), zeigt der Reiter einen Hinweis statt eines halb funktionierenden
Formulars. Voraussetzung im Kern: **Plugin 10.1.1**.

Aus `type` leitet die ConfigGUI das Soll-Wert-Feld ab: `bool` → Radio Ja/Nein,
`int` → Zahlenfeld, `enum` → Auswahlliste, `text` → Textfeld, `info` → keins.

---

## Migration aus 1.x

`sql/install.php` legt die Tabelle an und ruft **nur bei der Erstanlage**
`ilExamCheckerRules::seedFromLegacy()` auf:

- vorhandene `examchecker_standards_<typ>`-Liste aus `ilSetting` lesen
- Schlüssel über `legacyKeyMap()` übersetzen
  (`keine_vorbedingungen` → `vorbedingungen`, `weiterleitung` → `weiterleitungsmodus`
  + `weiterleitungsziel`, `automatisches_speichern` → + `autosave_intervall`,
  `start_und_ende` → `startzeit_aktiviert` + `endzeit_aktiviert`)
- die alte Liste bestimmt, **was** geprüft wird; Soll-Werte kommen aus dem Katalog
- Checks ohne Vorgänger in 1.x behalten ihre Vorgabe
- ohne Altkonfiguration gelten überall die Katalogvorgaben

Die alten `ilSetting`-Schlüssel bleiben unangetastet — ein Downgrade auf 1.x ist
dadurch folgenlos.

---

## Sicherheitsregeln

| Regel | Umsetzung |
|---|---|
| R1 | Einziger Scope ist die vom Proxy autorisierte `ref_id`; keine eigenen Filterparameter |
| R2 | Objekttyp `tst` wird im Proxy und nochmals im Evaluator geprüft |
| R3 | Modulauflösung nur über die Registry (Proxy), ConfigGUI über `getRegistry()->getHandler()` |
| R4 | `cmd=check` erzwingt POST und prüft den Sessiontoken per `hash_equals()`; `saveExamChecker` läuft über `getFormAction()` + `requirePost()` |
| R5 | `json_encode()` für die JS-Config mit `JSON_HEX_TAG\|AMP\|APOS\|QUOT` |
| R6 | Jeder dynamische Wert im Ergebnis-HTML durch `htmlspecialchars(…, ENT_QUOTES)` |
| R10 | Kein `return true` im `catch`; dreiwertiges Ergebnis, Fehler werden zu `unknown` |
| R12 | Keine personenbezogenen Daten — die Tabelle enthält nur Konfiguration |
| F-010 | Beim Speichern wird über den Katalog iteriert, nie über die POST-Schlüssel |

---

## Debugging

- Fehler landen im PHP-Errorlog mit Prefix `[ExamChecker]`
- Endpunkt direkt testen: der Proxy antwortet ohne POST/Token mit `403 {"error":"forbidden"}`
- Fehlt die Tabelle, liefert `forExamType()` die Katalogvorgaben und `save()` wirft —
  in dem Fall wurde das Modul nicht über den ZIP-Upload installiert
- Zeigt der ConfigGUI-Reiter nur einen Hinweis, ist eine Modulversion vor 10.0.1 aktiv

---

## Changelog

| Datum | Was |
|---|---|
| 2026-08-26 | **v10.0.1** (Port von 2.0.0): Soll-Werte je Check konfigurierbar (Tabelle `examchecker_rules`), Check-Katalog als Quelle der Wahrheit, eigener Proxy-Endpunkt statt gekapertem `saveSettings`, dreiwertiges Ergebnis, Korrektur von acht fehlerhaften Prüfungen, `ilExamCheckerConfig` entfernt |
| 2026-07-07 | **v10.0.0:** Start der ILIAS-10-Linie (Port von 1.2.0), keine Code-Änderungen nötig |
| 2026-05-12 | **v1.2.0:** Modul-i18n DE/EN inkl. AJAX-Ergebnis-Panel |
| 2026-05-12 | **v1.1.1:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026-04-10 | **v1.1.0:** `implements ilExamModuleInterface`, konfigurierbare Standards via `ilSetting` |
| 2026-04-10 | Initiale Version: Präsenz-/Fernprüfungsstandards, AJAX-Check, Vorbedingungs-Check |
