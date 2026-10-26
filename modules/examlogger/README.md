# ExamLogger

![Entwicklungsstand: Experimentell](https://img.shields.io/badge/Entwicklungsstand-Experimentell-orange)

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Protokolliert Test-Ereignisse **serverseitig** in einer eigenen Datenbanktabelle —
ohne ILIAS-Kernanpassungen, ohne zusätzliches JavaScript beim Nutzer.

Geloggte Ereignisse:
- Test gestartet / fortgesetzt
- Navigation (nächste Frage, vorherige Frage, Sprung zu Frage)
- Test abgegeben (Bestätigungsseite aufgerufen)
- Abgabe bestätigt (tatsächliche Einreichung)
- Plugin-Einstellungen gespeichert
- ILIAS-Einstellungsseite aufgerufen

## Protokoll-Ansicht

**Global (alle Tests):**  
`Administration → Plugins → ExtendedTestSettings → Tab "Protokoll"`

**Per Test:**  
Test-Einstellungen → Erweiterte Test-Einstellungen → Abschnitt "Prüfungs-Protokoll"

Beide Ansichten bieten Filter nach Ereignis-Typ und Datumsbereich sowie CSV-Export.

## Datenbank

Eigene Tabelle: `examlogger_events`

Die IP→Gerät-Übersetzung liest das Modul aus dem **zentralen Geräte-Inventar des
Plugins** (`etest_hosts`, ab Plugin 2.0.1) — gepflegt über Administration → Plugins →
ExtendedTestSettings → Tab „Geräte" (CSV-Import). Bis 1.3.0 lag sie als Modul-Tabelle
`examlogger_hosts` hier; das Update auf 1.4.0 entfernt die alte Tabelle
(Geräteliste danach einmalig neu importieren). **Deshalb: erst das Plugin auf 2.0.1
aktualisieren, dann dieses Modul.**

**`examlogger_events`** — zentrale Log-Tabelle:

| Spalte | Bedeutung |
|---|---|
| `id` (PK) | Auto-Increment |
| `test_obj_id` | ILIAS obj_id des Tests |
| `test_ref_id` | ILIAS ref_id des Tests |
| `user_id` | Nutzer-ID |
| `event_type` | Ereignis-Typ (z.B. `test_start`, `navigate_next`) |
| `question_id` | Frage-ID bei Navigation (sonst 0) |
| `ip_addr` | Client-IP-Adresse |
| `tstamp` | Unix-Timestamp |
| `details` | Freitext (Antworten-Snapshot, Einstellungs-Snapshot) |

**`etest_hosts`** (Plugin-Tabelle, nur lesend) — IP→Gerät-Übersetzung:

| Spalte | Bedeutung |
|---|---|
| `ip_addr` (PK) | IP-Adresse |
| `hostname` | Hostname |
| `ili_nr` | ILI-Nummer |
| `koffer` | Koffer-Bezeichnung (z.B. K01) |
| `geraet_nr` | Gerät-Nummer (z.B. 1/01) |
| `import_note` | Freitext-Hinweis |

## Einschränkung: POST+Redirect

Ereignisse die ILIAS intern mit direktem Redirect verarbeitet (z.B. Formular-Speicherungen)
können nicht über den UIHook erfasst werden. Zuverlässig sind: Seitenaufrufe im Testplayer
und Navigation zwischen Fragen.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 10.0.2 | `ExamLogger_v10.0.2.zip` | 10 | AGPL-3.0-only: Lizenz- und Autorenhinweise im ZIP; Lizenzen mitgelieferter Fremdkomponenten ergänzt |
| 10.0.1 | `archive/ExamLogger_v10.0.1.zip` | 10 | Testbezogene Zugriffsprüfung und Formel-Neutralisierung im CSV-Export |
| 10.0.0 | `archive/ExamLogger_v10.0.0.zip` | 10 | Port auf ILIAS 10: Settings-Events erkennen auch die namespaced 10er-cmdClasses; Bugfix Player-Erkennung für Random-Question-Set-Tests (falscher Klassenname, Events wurden nicht geloggt). Start der ILIAS-10-Linie (Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1); module.json: ilias_min/max = 10 |
| 1.4.0 | `archive/ExamLogger_v1.4.0.zip` | 9 | Geräte-Inventar ins Plugin verlagert (`etest_hosts`, Plugin ≥ 2.0.1): Modul liest nur noch, `examlogger_hosts` wird beim Update entfernt; JOINs defensiv (Guard bei fehlender Tabelle) |
| 1.3.0 | `archive/ExamLogger_v1.3.0.zip` | 9 | i18n DE/EN der ViewGUI (Protokoll, Geräte, CSV-Export, Geräte-Import) |
| 1.2.0 | `archive/ExamLogger_v1.2.0.zip` | 9 | Modul-i18n DE/EN (Handler/getInfo) |
| 1.1.0 | `ExamLogger_v1.1.0.zip` | 9 | Frage-ID + Fragenname in Protokoll/CSV; Geräteverwaltung (examlogger_hosts); `test_submit` / `test_submit_confirmed` differenziert (GET vs. POST) |
| 1.0.0 | `archive/ExamLogger_v1.0.0.zip` | 9 | Erstveröffentlichung |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, Event-Typen, onRequest()-Pattern):
→ [`/docs/modules/ExamLogger.md`](../../docs/modules/ExamLogger.md)
