# ExamChecker — Entwicklerdokumentation

**Modul-ID:** `examchecker`  
**Speicherort:** `modules/examchecker/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examchecker/ExamChecker_v10.0.0.zip`  
**Zuletzt aktualisiert:** 2026-07-07

---

## ILIAS-10-Port (Version 10.0.0, 2026-07-07)

Start der ILIAS-10-Linie (Branch `ilias10`; Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1).
module.json: `version` 10.0.0, `ilias_min`/`ilias_max` = 10.

- Keine Code-Änderungen nötig. Web-Asset-Pfade (addJavaScript/addCss) funktionieren über den zentralen Registry-Compat des Hauptplugins (ab 10.0.0: Handler erhalten den web-relativen Pfad via `getRelativeDirectory()`).

---

## Was ist ExamChecker?

Ermöglicht es, die ILIAS-Einstellungen eines Tests gegen definierte **Prüfungsstandards**
zu prüfen — für **Präsenzprüfungen** und **Fernprüfungen** jeweils separat konfigurierbar.

Der Prüfer wählt den Prüfungstyp und startet den Check per Button. Das Ergebnis wird
als farbkodierte Liste (grün = korrekt, rot = abweichend) direkt in der Einstellungsseite
angezeigt, inklusive Warnung bei vorhandenen Vorbedingungen und manuell zu prüfenden
Feldern (Zeiten, Bearbeitungsdauer).

**Wichtig:** ExamChecker hat **keine eigenen DB-Tabellen**. Es werden ausschließlich
ILIAS-Kerntabellen gelesen. `isEnabled()` gibt immer `true` zurück — das Tool ist immer
verfügbar, unabhängig vom Test.

---

## Einschränkungen

- Kein Testplayer-Widget: `injectAssets()` ist ein no-op.
- Der Check läuft als **AJAX-Request** via `saveSettings()` — ein ungewöhnliches Muster
  (Details siehe "AJAX-Mechanismus" unten).
- Benutzt eine **globale PHP-Variable** `$examchecker_conditions_details` um Vorbedingungs-
  Details aus `checkKeineVorbedingungen()` an `generateCheckResultsHTML()` weiterzugeben.
- Standards sind in `ilSetting` konfigurierbar, Fallback auf hardcodierte Defaults.
- `checkAuswertung()` gibt immer `[]` zurück — Auswertungs-Checks sind in
  `performAllChecks()` integriert.

---

## Dateistruktur

```
modules/examchecker/
├── module.json                         ← Modul-Metadaten, require_files: Config-Klasse, tables: []
├── class.ilExamCheckerHandler.php      ← Handler (implements ilExamModuleInterface)
├── class.ilExamCheckerConfig.php       ← Konfigurationsklasse + E-Mail-Textbausteine
└── sql/
    ├── install.php                     ← no-op (keine eigenen Tabellen)
    └── uninstall.php                   ← no-op (keine eigenen Tabellen)
```

---

## module.json

```json
{
    "id": "examchecker",
    "label": "Prüfungs-Checker",
    "description": "Überprüft Test-Einstellungen gegen definierte Standards für Präsenz- und Fernprüfungen.",
    "version": "1.1.1",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamCheckerHandler",
    "handler_file": "class.ilExamCheckerHandler.php",
    "require_files": [
        "class.ilExamCheckerConfig.php"
    ],
    "tables": []
}
```

Kein `gui_file`/`gui_class` — ExamChecker hat keine eigene Verwaltungs-GUI.
`tables: []` — keine eigenen Datenbanktabellen.

---

## Datenbank

### Keine eigenen Tabellen

ExamChecker legt keine eigenen Tabellen an. `sql/install.php` und `sql/uninstall.php`
sind bewusst leer (no-op).

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| `tst_tests` | Test-Einstellungen (`pool_usage`, `anonymity`, `online_status`, `password_enabled`, `nr_of_tries`, `kiosk`, `autosave`, `autosave_ival`, `instant_verification`, `show_marker`, `count_system`, `score_cutting`, `pass_scoring`, `redirection_mode`, `redirection_url`, `require_authorship_statement`, `enable_processing_time`, `processing_time`, `starting_time`, `ending_time`, `starting_time_enabled`, `ending_time_enabled`, `fixed_participants`, `pass_waiting`, `score_reporting`) |
| `object_reference` | `ref_id` → `obj_id` Auflösung (für Vorbedingungen + Timing) |
| `crs_items` | Kurs-Item-Timing (`timing_start`, `timing_end`) |
| `conditions` | Vorbedingungen für den Test (`trigger_obj_id`, `trigger_ref_id`, `operator`, `value`) |
| `object_data` | Titel und Typ des Vorbedingungsobjekts |

---

## ilExamCheckerHandler

Implementiert `ilExamModuleInterface`.

### Interface-Methoden

```
isEnabled(ref_id)         → gibt immer true zurück (Tool ist immer verfügbar)
injectAssets(tpl, ref_id) → no-op (ExamChecker läuft nur in Test-Einstellungen)
getFormItems(ref_id)       → [ilCustomInputGUI] mit getSettingsHTML() als HTML-Inhalt
saveSettings(ref_id, post)→ prüft ob post['action'] === 'exam_check':
                            ja: handleExamCheck() → JSON-Response + exit
                            nein: gibt true zurück (normales Speichern, keine Daten)
getInfo()                  → title, icon, description, version
```

### Private Methoden (Check-Pipeline)

```
getSettingsHTML(ref_id)          → HTML: Prüfungstyp-Select + Check-Button + Result-Div
                                   + getJavaScriptCode(ref_id) eingebettet
getJavaScriptCode(ref_id)        → inline <script> mit AJAX-Fetch-Logik
handleExamCheck(ref_id, post)    → performExamCheck() → generateCheckResultsHTML()
                                   → header('Content-Type: application/json') + exit
performExamCheck(ref_id, type)   → ruft getTestDataFromDB() + checkEinstellungen()
                                   + checkManuellePrüfungen(); gibt {einstellungen, auswertung, manuell_check}
getTestDataFromDB(ref_id)        → SELECT * FROM tst_tests WHERE obj_fi = obj_id;
                                   gibt null wenn kein Test gefunden
checkEinstellungen(data, type)   → filtert performAllChecks() via getConfiguredStandards()
getConfiguredStandards(type)     → liest ilSetting('extendedtestsettings').'examchecker_standards_<type>';
                                   Fallback: getHardcodedDefaults()
getHardcodedDefaults(type)       → hardcodierte Standard-Liste (17 Checks + typ-spezifische)
performAllChecks(data, type)     → prüft alle DB-Felder, gibt [key → bool] zurück
checkAuswertung(data, type)      → gibt [] zurück (in performAllChecks integriert)
checkManuellePrüfungen(data,type)→ prüft Start/Endzeit + Bearbeitungsdauer
checkKeineVorbedingungen(data)   → liest conditions-Tabelle, speichert Details in
                                   global $examchecker_conditions_details
checkZeitlichBegrenzteVerfuegbarkeit(data) → prüft crs_items.timing_start/timing_end
formatConditionDescription(cond) → formatiert Bedingungsbeschreibung für Anzeige
generateCheckResultsHTML(results, type) → erzeugt HTML-Ergebnisblock (Zusammenfassung,
                                   grün/rot-Listen, Vorbedingungwarnung, manuelle Checks)
getSettingDescriptions()         → [key → DE-Beschreibung] für alle Check-Keys
isDefaultDateTime(timestamp)     → erkennt Default-Timestamps (01.04.2022, 01.12.2022, 01.01.1970)
```

---

## AJAX-Mechanismus

ExamChecker nutzt ein ungewöhnliches Muster: Der Check wird als AJAX-POST-Request an
die URL der Einstellungsseite gesendet, wobei `cmd=show` durch `cmd=saveSettings` ersetzt
wird.

```
Browser: "Exam-Prüfungs-Check starten" Button geklickt
  → JavaScript liest window.location.href
  → ersetzt "cmd=show" durch "cmd=saveSettings"
  → POST-Request: action=exam_check&ref_id=...&exam_type=praesenz|fern
  
Server: saveSettings() empfängt POST
  → post_data['action'] === 'exam_check'?
  → handleExamCheck() → performExamCheck() → generateCheckResultsHTML()
  → header('Content-Type: application/json')
  → echo json_encode(['html' => $html, 'success' => true])
  → exit  ← wichtig: verhindert reguläres ILIAS-Seiten-Rendering

Browser: JSON-Response empfangen
  → data.html in #check-results einfügen
  → Ergebnisblock einblenden
```

> **Warum `saveSettings` statt einer eigenen AJAX-URL?** ExamChecker hat keine eigene
> GUI und keinen eigenen ilCtrl-Endpunkt. Der Umweg über `saveSettings` mit
> `action=exam_check` ist der einfachste Weg, einen AJAX-Handler in die bestehende
> ILIAS-Routing-Kette einzuhängen ohne eine neue GUI-Klasse zu registrieren.

---

## Check-Standards

### Hardcodierte Defaults (gemeinsam für beide Typen)

| Check-Key | DB-Feld | Erwarteter Wert |
|---|---|---|
| `fragenpools_verwenden` | `pool_usage` | `1` |
| `datenschutz` | `anonymity` | `0` (mit Namen) |
| `online` | `online_status` | `1` |
| `passwort` | `password_enabled` | `1` |
| `nur_ausgewaehlte_teilnehmer` | `fixed_participants` | `0` (negiert!) |
| `testdurchlaeufe_begrenzen` | `nr_of_tries` | `1` |
| `wartezeit_erzwingen` | `pass_waiting` | leer oder `'0'` |
| `pruefungsansicht` | `kiosk` | `1` |
| `automatisches_speichern` | `autosave=1` + `autosave_ival=30000` | beide Bedingungen |
| `direkte_rueckmeldung` | `instant_verification` | `0` (soll AUS sein) |
| `fragen_markieren` | `show_marker` | `1` |
| `keine_vorbedingungen` | `conditions`-Tabelle | keine Einträge |
| `direkte_notenuebermittlung` | `score_reporting` | `0` |
| `zeitlich_begrenzte_verfuegbarkeit` | `crs_items.timing_start/end` | beide `0` |
| `bewertungssystem` | `count_system` | `0` |
| `negative_punkte` | `score_cutting` | `0` |
| `besten_durchlauf_bewerten` | `pass_scoring` | `1` |

### Nur bei Fernprüfung (`fern`)

| Check-Key | DB-Feld | Erwarteter Wert |
|---|---|---|
| `eigenstaendigkeitserklaerung` | `require_authorship_statement` | `1` |
| `bearbeitungsdauer` | `enable_processing_time` | `1` |

### Nur bei Präsenzprüfung (`praesenz`)

| Check-Key | DB-Feld | Erwarteter Wert |
|---|---|---|
| `weiterleitung` | `redirection_mode=2` + `redirection_url='logout.php'` | beide Bedingungen |

### Konfigurierbare Standards

Über `ilSetting('extendedtestsettings')`, Key `examchecker_standards_praesenz` bzw.
`examchecker_standards_fern` — kommagetrennte Liste von Check-Keys. Wenn leer:
Fallback auf hardcodierte Defaults.

---

## ilExamCheckerConfig

Enthält Prüfungstyp-Standards und E-Mail-Textbausteine.

```
get(key, default) / set(key, v)  → ilSetting-Wrapper (Namespace 'examchecker')
getExamStandards(type)           → [required_settings: {key → bool}, manual_checks: [...]]
                                   für 'fern' und 'praesenz' (aktuell ungenutzt im Handler,
                                   der liest ilSetting direkt)
getEmailBausteine()              → [startText, endText, startAllgemeineEinstellungen,
                                   startAuswertungEinstellungen, + je Einstellung DE-Text]
                                   (für Report-Generierung — aktuell ungenutzt)
```

> `getExamStandards()` und `getEmailBausteine()` sind in der Config-Klasse definiert
> aber werden vom Handler **nicht** aufgerufen — der Handler verwendet
> `getConfiguredStandards()` + `getHardcodedDefaults()` direkt. Die Config-Klasse ist
> für zukünftige Erweiterungen (z.B. UI für konfigurierbare Standards) vorbereitet.

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen erscheint der Block "Prüfungs-Einstellungen prüfen"
   mit Prüfungstyp-Auswahl und Check-Button

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` ist leer, daher kein
DB-Effekt.

### Standards konfigurieren (optional)

```php
// Beispiel: Standards für Präsenzprüfung setzen
$settings = new ilSetting('extendedtestsettings');
$settings->set('examchecker_standards_praesenz',
    'fragenpools_verwenden,datenschutz,online,passwort,pruefungsansicht');
```

Ohne Konfiguration werden die hardcodierten Defaults aus `getHardcodedDefaults()`
verwendet.

---

## Changelog

| Datum | Was |
|---|---|
| 2026-05-12 | **v1.1.1:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026-04-10 | Initiale Version: Präsenz- und Fernprüfungs-Standards, AJAX-Check, HTML-Ergebnisdarstellung |
| 2026-04-10 | Vorbedingungs-Check mit Detail-Anzeige (Objekte + Typen) |
| 2026-04-10 | Manuelle Checks: Start/Endzeiten + Bearbeitungsdauer |
| 2026-04-10 | In modulares System migriert (`modules/examchecker/`), `module.json` hinzugefügt |
| 2026-04-10 | **v1.1.0:** `implements ilExamModuleInterface` vollständig, konfigurierbare Standards via ilSetting |
