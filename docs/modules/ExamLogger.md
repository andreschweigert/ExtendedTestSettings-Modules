# ExamLogger — Entwicklerdokumentation

**Modul-ID:** `examlogger`  
**Speicherort:** `modules/examlogger/` (direkt im Plugin-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examlogger/ExamLogger_v10.0.0.zip`  
**Zuletzt aktualisiert:** 2026-07-07

---

## ILIAS-10-Port (Version 10.0.0, 2026-07-07)

Start der ILIAS-10-Linie (Branch `ilias10`; Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1).
module.json: `version` 10.0.0, `ilias_min`/`ilias_max` = 10.

- Settings-Events: Erkennung um die namespaced 10er-cmdClasses erweitert (`ilias\test\settings\mainsettings\settingsmaingui`, `…scorereporting\settingsscoringgui`, `…scoring\manual\testscoringby*gui`); 9er-Strings bleiben.
- Bugfix: `isPlayerClass()` prüfte `iltestplayerrandquestionsetgui` — die Klasse heißt (in 9 wie 10) `iltestplayerrandomquestionsetgui`. Folge: Player-Events von Random-Question-Set-Tests wurden nie geloggt.

---

## Was ist ExamLogger?

Protokolliert Test-Ereignisse **serverseitig in einer eigenen DB-Tabelle** —
ohne ILIAS-Kernanpassungen, ohne zusätzliches JavaScript beim Nutzer.

Das Modul nutzt zwei Einstiegspunkte:

1. **`onRequest(int $ref_id)`** — optionale Methode, die von `UIHookGUI::dispatchRequestToModules()`
   bei jedem test-relevanten GET-Request aufgerufen wird. Erkennt den aktuellen Kontext
   via `$_REQUEST['cmd']` und `$_REQUEST['cmdClass']`.
2. **`saveSettings(int $ref_id, array $post_data)`** — wird von `saveAllModuleSettings()`
   aufgerufen wenn die Plugin-Einstellungsseite gespeichert wird.

**Kein eigenes JS** — alle Logging-Aktionen laufen im PHP-Request.  
**Keine Abhängigkeit von ILIAS Assessment-Logging** — eigene Tabelle, eigene UI.

---

## Technische Einschränkungen

ILIAS verwendet für Speicher-Aktionen i.d.R. **POST → Redirect → GET**. Das bedeutet:

| Ereignis | Erfassung | Grund |
|---|---|---|
| `test_start` (startPlayer, resumePlayer) | ✅ zuverlässig | GET-Request → modifyGUI → onRequest() |
| Navigation (next, prev, jump) | ✅ zuverlässig | GET-Request |
| `test_settings_viewed` | ✅ zuverlässig | GET-Request bei Aufruf der Einstellungsseite |
| `plugin_settings_saved` | ✅ zuverlässig | saveSettings() synchron vor Redirect |
| `test_submit` | ✅ zuverlässig | `finishTest` GET-Request (Bestätigungsseite) |
| `test_submit_confirmed` | ✅ zuverlässig | `finishTest` POST-Request (tatsächliche Bestätigung) |
| `test_settings_saved` (ILIAS-nativ) | ⚠️ situationsabhängig | gleiche Einschränkung |

---

## Dateistruktur

```
modules/examlogger/
├── module.json                          ← Modul-Metadaten (mit gui_file + gui_class)
├── class.ilExamLoggerHandler.php        ← Handler (implements ilExamModuleInterface + onRequest)
├── class.ilExamLoggerViewGUI.php        ← Protokoll-Ansicht mit Filter + CSV-Export
└── sql/
    ├── install.php                      ← Legt examlogger_events an (inkl. Sequenz + Indizes)
    └── uninstall.php                    ← Löscht Tabelle und Sequenz
```

---

## module.json

```json
{
    "id": "examlogger",
    "label": "Prüfungs-Logging",
    "description": "Protokolliert Test-Ereignisse (Start, Navigation, Abgabe, Einstellungsänderungen) in einer eigenen Tabelle ohne ILIAS-Kernanpassungen.",
    "version": "1.0.0",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamLoggerHandler",
    "handler_file": "class.ilExamLoggerHandler.php",
    "gui_file":  "class.ilExamLoggerViewGUI.php",
    "gui_class": "ilExamLoggerViewGUI",
    "require_files": [],
    "tables": ["examlogger_events"]
}
```

`gui_file`/`gui_class` gesetzt — ExamLogger hat eine eigene Protokoll-Ansicht über den
ModuleProxyGUI-Mechanismus.

---

## Datenbank

### `examlogger_events`

Zentrale Log-Tabelle. Ein Eintrag pro Ereignis, unbegrenzt viele Einträge pro Test/User.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | integer(4) PK | Auto-Increment via `$db->nextId()` |
| `test_obj_id` | integer(4) | ILIAS `obj_id` des Tests |
| `test_ref_id` | integer(4) | ILIAS `ref_id` des Tests |
| `user_id` | integer(4) | ID des auslösenden Nutzers |
| `event_type` | text(64) | Ereignis-Typ (siehe Tabelle unten) |
| `question_id` | integer(4) | Frage-ID bei Navigation (sonst 0) |
| `ip_addr` | text(45) | Client-IP (IPv4 oder IPv6, aus X-Forwarded-For bzw. REMOTE_ADDR) |
| `tstamp` | integer(4) | Unix-Timestamp |

**Indizes:** `test_ref_id`, `user_id`, `event_type`, `tstamp` — für typische Filter-Queries.

Schreiben via `$db->insert()` mit `$db->nextId('examlogger_events')` als PK.

### `etest_hosts` (Plugin-Tabelle, ab Modul 1.4.0 / Plugin 2.0.1)

IP→Gerät-Übersetzung — **zentrales Geräte-Inventar des Plugins** (angelegt vom
Plugin-dbupdate, gepflegt im ConfigGUI-Tab „Geräte" per CSV-Import). Das Modul liest
nur (LEFT JOIN über die IP), alle Zugriffe mit `tableExists()`-Guard, damit das Modul
auch unter einem noch nicht aktualisierten Plugin lauffähig bleibt (Geräte-Spalten
dann leer). Bis 1.3.0 hieß die Tabelle `examlogger_hosts` und gehörte dem Modul;
das 1.4.0-Update droppt sie (Geräteliste einmalig neu importieren) —
**Update-Reihenfolge: erst Plugin, dann Modul.**

| Spalte | Typ | Beschreibung |
|---|---|---|
| `ip_addr` | text(45) PK | IP-Adresse des Geräts |
| `hostname` | text(255) | FQDN / Hostname |
| `ili_nr` | text(20) | ILI-Nummer (z.B. `ILI-042`) |
| `koffer` | text(20) | Koffer-Bezeichnung (z.B. `K03`) |
| `geraet_nr` | text(20) | Gerät-Nummer (z.B. `3/09`) |
| `import_note` | text(255) | Freitext-Hinweis aus der CSV |

**Indizes:** `ili_nr`, `koffer`.

### Gelesene ILIAS-Tabellen (read-only, nur in ViewGUI)

| Tabelle | Wozu |
|---|---|
| `usr_data` | Login + Name für die Anzeige |
| `object_data` | Test-Titel für die Anzeige |
| `qpl_questions` | Fragenname zu `question_id` |

---

## Event-Typen

| `event_type` | Auslöser | Quelle |
|---|---|---|
| `test_start` | `cmd=startPlayer` oder `cmd=resumePlayer` | `onRequest()` |
| `navigate_next` | `cmd=nextQuestion` | `onRequest()` |
| `navigate_prev` | `cmd=previousQuestion` | `onRequest()` |
| `navigate_jump` | `cmd=showQuestion` (mit question_id) | `onRequest()` |
| `test_submit` | `cmd=finishTest` (GET — Bestätigungsseite) | `onRequest()` ✅ |
| `test_submit_confirmed` | `cmd=finishTest` (POST — tatsächliche Bestätigung) | `onRequest()` ✅ |
| `test_settings_viewed` | `cmdClass=ilobjtestsettingsmaingui` (GET) | `onRequest()` |
| `test_settings_saved` | `cmdClass=ilobjtestsettingsmaingui` + Save-cmd | `onRequest()` ⚠️ |
| `plugin_settings_saved` | `saveSettings()` aufgerufen | `saveSettings()` ✅ |

---

## ilExamLoggerHandler

Implementiert `ilExamModuleInterface` + optionales `onRequest()`.

```
isEnabled(ref_id)          → gibt immer true zurück — Logging aktiv wenn Modul aktiv
injectAssets(tpl, ref_id)  → leer (kein JS/CSS)
saveSettings(ref_id, post) → schreibt plugin_settings_saved ins Log; gibt true zurück
getFormItems(ref_id)       → [ilFormSectionHeaderGUI, ilCustomInputGUI(Link zur ViewGUI)]
onRequest(ref_id)          → OPTIONAL (nicht im Interface); prüft cmd+cmdClass, schreibt Log
```

---

## ilExamLoggerViewGUI

Zugang über zwei Wege:

**Global (alle Tests):**  
`Administration → Plugins → ExtendedTestSettings → Tab "Protokoll"`

**Per Test:**  
Test-Einstellungen → Erweiterte Test-Einstellungen → Abschnitt "Prüfungs-Protokoll"

### Befehle

| `cmd` | Kontext | Beschreibung |
|---|---|---|
| `showLog` | ModuleProxyGUI (per Test) | Tabellenansicht mit Filter |
| `exportCsv` | ModuleProxyGUI (per Test) | CSV-Download |
| `showProtocol` | ConfigGUI (global) | Tabellenansicht mit Filter |
| `exportProtocolCsv` | ConfigGUI (global) | CSV-Download |

### Filter

| Parameter | Beschreibung |
|---|---|
| `filter_event` | Event-Typ (validiert: `^[a-z_]{1,64}$`) |
| `filter_from` | Von-Datum (Format: `YYYY-MM-DD`) |
| `filter_to` | Bis-Datum (inkl. 23:59:59) |
| `ref_id` | Automatisch aus URL — filtert auf diesen Test (nur per-Test-Ansicht) |

Limit: 1000 Zeilen in der Tabellenansicht, kein Limit beim CSV-Export.

### CSV-Format

Semikolon-getrennt, UTF-8 mit BOM. Spalten:  
`Datum ; Uhrzeit ; Login ; Vorname ; Nachname ; Test ; Ref-ID ; Ereignis ; Details ; Frage-ID ; Fragenname ; Koffer ; Gerät-Nr ; ILI-Nr ; IP-Adresse`

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. `sql/install.php` legt `examlogger_events` + Sequenz + Indizes an
4. Tab "Protokoll" erscheint in der Plugin-Konfiguration
5. In den Erweiterten Test-Einstellungen erscheint der Abschnitt "Prüfungs-Protokoll"

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` läuft erneut
(durch `tableExists()`-Guard ohne Effekt wenn Tabelle bereits existiert).

---

## Changelog

| Datum | Was |
|---|---|
| 2026-05-21 | **v1.1.0:** Fragenname (JOIN qpl_questions) in Protokoll-Spalte und CSV; Geräteverwaltung via examlogger_hosts (CSV-Import, Koffer-Filter, Gerät-Spalte mit Tooltip); `test_submit_confirmed` als eigener Event-Typ (GET vs. POST auf finishTest) |
| 2026-05-21 | **v1.0.0:** Initiale Implementierung; onRequest()-Pattern; ViewGUI mit Filter + CSV-Export; Admin-Tab in ConfigGUI |
