# ExamGrouping

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Erlaubt es, die Fragen eines Tests zu **benannten Gruppen** (z.B. "Grundlagen", "Anwendung") zusammenzufassen und die Punkte je Gruppe aus den Testergebnissen auszuwerten. Für jede Gruppe ist ein Bestehensschwellenwert konfigurierbar (Prozent oder absolute Punkte).

> **Einschränkung:** Nur bei Tests mit **fixer Fragenliste** (`FIXED_QUEST_SET`). Bei Random-Question-Sets wird die Funktion verweigert.

## Features

- Gruppen anlegen, benennen, sortieren
- Fragen per Checkbox den Gruppen zuordnen
- Ergebnistabelle mit allen Teilnehmern und Durchläufen (inkl. Trendpfeile ▲▼→)
- Spalte "Nicht zugeordnet" für Fragen ohne Gruppe
- Bestehensschwellenwert pro Gruppe (Prozent oder Punkte)
- CSV-Export (Semikolon, UTF-8 mit BOM, alle Durchläufe)

## Konfiguration

Pro Test über die Verwaltungsoberfläche (Link in den Erweiterten Test-Einstellungen):

| Einstellung | Beschreibung |
|---|---|
| Gruppenname | Freitext |
| Schwellenwert-Typ | `percent` oder `points` |
| Schwellenwert-Wert | Zahl (Default: 50) |
| Fragen-Zuweisung | Checkboxen je Gruppe |

## Datenbank

Eigene Tabellen: `examgrouping_groups`, `examgrouping_items`

| Tabelle | Inhalt |
|---|---|
| `examgrouping_groups` | Gruppen-Definitionen je Test (Name, Position, Schwellenwert) |
| `examgrouping_items` | Fragen-Zuordnung (group_id → question_id) |

Gelesene ILIAS-Tabellen (read-only): `tst_tests`, `tst_test_question`, `qpl_questions`, `tst_active`, `tst_test_result`, `usr_data`

## Routing

```
ilUIPluginRouterGUI
  └── ilExtendedTestSettingsModuleProxyGUI  (?module_id=examgrouping&cmd=show&ref_id=...)
        └── ilExamGroupingGUI::executeCommand()
```

Alle URL/Redirect-Generierungen laufen über `proxyLink()` / `proxyFormAction()` / `proxyRedirect()` — niemals `$ctrl->...($this, ...)`.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.0.1 | `ExamGrouping_v1.0.1.zip` | 9–12 | Proxy-Routing, Interface-Fix, Formular-Submit-Fix, `gui_file`/`gui_class` in module.json |
| 1.0.0 | — | 9–12 | Initiale Implementierung |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Routing-Kette, DB-Schema, alle GUI-Commands, Proxy-Methoden, Ergebnistabelle, CSV-Export):
→ [`/docs/modules/ExamGrouping.md`](../../docs/modules/ExamGrouping.md)
