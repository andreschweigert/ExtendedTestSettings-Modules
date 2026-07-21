# ExamGrouping

> **Entwicklungsstand:** Im Test

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Erlaubt es, die Fragen eines Tests zu **benannten Gruppen** (z.B. "Grundlagen", "Anwendung") zusammenzufassen und die Punkte je Gruppe aus den Testergebnissen auszuwerten. Für jede Gruppe ist ein Bestehensschwellenwert konfigurierbar (Prozent oder absolute Punkte).

Unterstützt sowohl **Tests mit fixer Fragenliste** als auch **Tests mit Fragenpools (Zufallstests)**.

## Features

- Gruppen anlegen, benennen, sortieren
- **Fixer Test:** Fragen per Checkbox den Gruppen zuordnen
- **Zufallstest:** Fragenpools per Checkbox den Gruppen zuordnen; Max-Punkte werden individuell je Teilnehmer aus den tatsächlich gezogenen Fragen berechnet
- Ergebnistabelle mit allen Teilnehmern und Durchläufen (inkl. Trendpfeile ▲▼→)
- Spalte "Nicht zugeordnet" für Fragen/Pools ohne Gruppe
- Bestehensschwellenwert pro Gruppe (Prozent oder Punkte)
- CSV-Export (Semikolon, UTF-8 mit BOM): eine Zeile pro Kandidat (letzter Durchlauf) mit Name, Matrikelnummer, Kennung, Punkte + % je Gruppe und Gesamtpunktzahl

## Konfiguration

Pro Test über die Verwaltungsoberfläche (Link in den Erweiterten Test-Einstellungen):

| Einstellung | Beschreibung |
|---|---|
| Gruppenname | Freitext |
| Schwellenwert-Typ | `percent` oder `points` |
| Schwellenwert-Wert | Zahl (Default: 50) |
| Fragen-/Pool-Zuweisung | Checkboxen je Gruppe (Test-Typ wird automatisch erkannt) |

## Datenbank

Eigene Tabellen: `examgrouping_groups`, `examgrouping_items`, `examgrp_pool_items`

| Tabelle | Inhalt |
|---|---|
| `examgrouping_groups` | Gruppen-Definitionen je Test (Name, Position, Schwellenwert) |
| `examgrouping_items` | Fragen-Zuordnung für fixe Tests (group_id → question_id) |
| `examgrp_pool_items` | Pool-Zuordnung für Zufallstests (group_id → pool_id) |

Gelesene ILIAS-Tabellen (read-only): `tst_tests`, `tst_test_question`, `qpl_questions`, `tst_rnd_quest_set_qpls`, `tst_test_rnd_qst`, `tst_active`, `tst_test_result`, `usr_data`

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
| 10.0.0 | `ExamGrouping_v10.0.0.zip` | 10 | Port auf ILIAS 10: keine Code-Änderungen im Modul nötig — Web-Asset-Pfade laufen über den zentralen Registry-Compat des Plugins (ab 10.0.0). Start der ILIAS-10-Linie (Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1); module.json: ilias_min/max = 10 |
| 1.2.2 | `archive/ExamGrouping_v1.2.2.zip` | 9 | CSV-Export: Name-Spalte enthält zusätzlich den Anmeldenamen, z.B. „Max Mustermann (mmustermann)" |
| 1.2.1 | `archive/ExamGrouping_v1.2.1.zip` | 9 | CSV-Export kompakt: eine Zeile pro Kandidat (letzter Durchlauf), Spalten Name/Matrikelnummer/Kennung, Punkte + % je Gruppe, Gesamtpunktzahl |
| 1.2.0 | `archive/ExamGrouping_v1.2.0.zip` | 9 | i18n DE/EN der Verwaltungs-GUI (Gruppen, Ergebnis-Tabellen Fix+Zufall, beide CSV-Exporte) |
| 1.1.0 | `archive/ExamGrouping_v1.1.0.zip` | 9 | Modul-i18n DE/EN (Handler) |
| 1.0.4 | `ExamGrouping_v1.0.4.zip` | 9 | Bugfix: Pass-Erkennung für Zufallstests via `tst_test_rnd_qst`; Pool-Matching über `original_id` |
| 1.0.3 | — | 9 | Zufallstest-Unterstützung: Pool-basierte Gruppierung, neue Tabelle `examgrp_pool_items` |
| 1.0.2 | — | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.0.1 | — | 9–12 | Proxy-Routing, Interface-Fix, Formular-Submit-Fix, `gui_file`/`gui_class` in module.json |
| 1.0.0 | — | 9–12 | Initiale Implementierung |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Routing-Kette, DB-Schema, alle GUI-Commands, Proxy-Methoden, Ergebnistabelle, CSV-Export):
→ [`/docs/modules/ExamGrouping.md`](../../docs/modules/ExamGrouping.md)
