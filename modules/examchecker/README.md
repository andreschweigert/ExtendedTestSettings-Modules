# ExamChecker

![Entwicklungsstand: In Entwicklung](https://img.shields.io/badge/Entwicklungsstand-In%20Entwicklung-yellow)

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Prüft die Einstellungen eines ILIAS-Tests gegen **konfigurierbare Soll-Werte** — getrennt für Präsenz- und Fernprüfungen. In den erweiterten Test-Einstellungen erscheint ein Bedienblock mit Prüfungstyp-Auswahl und Startknopf; das Ergebnis erscheint als Tabelle je Themenbereich mit Ist- und Soll-Wert.

**Kein Testplayer-Widget** — läuft ausschließlich auf der Test-Einstellungsseite.

## Was seit 2.0.0 anders ist

Bis 1.2.0 waren die Soll-Werte im Code festverdrahtet; konfigurierbar war nur, *ob* ein Wert geprüft wird. Seit 2.0.0 legt die Plugin-Konfiguration beides fest: **welche** Einstellungen geprüft werden und **welchen Wert** sie haben sollen.

| Werttyp | Soll-Wert in der Konfiguration | Beispiel |
|---|---|---|
| Ja/Nein | Auswahl Ja oder Nein | Testpasswort gesetzt |
| Zahl | Eingabefeld | Anzahl Testdurchläufe, Autosave-Intervall |
| Auswahl | Liste mit den ILIAS-Bezeichnungen | Zugriff auf die Testergebnisse |
| Text | Eingabefeld | Ziel der Weiterleitung |
| Sichtprüfung | kein Soll-Wert, nur Anzeige | Prüfungszeitraum, Bearbeitungsdauer |

Neben jedem Feld steht die Empfehlung, die den Prüfungsvorgaben der FAU entspricht. Ohne Konfiguration gelten genau diese Empfehlungen.

## Geprüfte Einstellungen

26 Checks in fünf Bereichen:

| Bereich | Checks |
|---|---|
| Allgemeine Einstellungen | Fragen aus Fragenpools, Ergebnisse mit Namen, Online-Status, zeitlich begrenzte Verfügbarkeit |
| Zugang | Testpasswort, nur ausgewählte Teilnehmer, Vorbedingungen, Start-/Endzeitpunkt, Prüfungszeitraum |
| Durchführung | Anzahl Durchläufe, Wartezeit, Prüfungsansicht, automatisches Speichern samt Intervall, direkte Rückmeldung, Fragen markieren, Prüfungsbedingungen (Fernprüfung), Bearbeitungsdauer |
| Auswertung | Teilpunkte, Verrechnung negativer Punkte, bewerteter Durchlauf, Zugriff auf die Ergebnisse |
| Abschluss | Weiterleitung nach Testende und deren Ziel (Präsenzprüfung) |

Ein Check hat drei mögliche Ergebnisse: **erfüllt**, **abweichend** oder **nicht prüfbar**. Der dritte Fall tritt ein, wenn ein Wert technisch nicht ermittelbar war — etwa weil die Spalte in dieser ILIAS-Version fehlt. Ein solcher Fall gilt ausdrücklich nicht als bestanden.

## Datenbank

| Tabelle | Zweck |
|---|---|
| `examchecker_rules` | Konfiguration: je Prüfungstyp und Check eine Zeile mit `enabled` und `expected_value` (rund 50 Zeilen für die gesamte Installation). Kein Personenbezug. Wird bei der Deinstallation gelöscht. |

Gelesen werden außerdem die ILIAS-Kerntabellen `tst_tests`, `object_data`, `crs_items`, `conditions`, `tst_test_question` und `qpl_questions` — ausschließlich lesend.

Beim ersten Einspielen von 2.0.0 übernimmt `sql/install.php` eine vorhandene 1.x-Konfiguration aus `ilSetting` (`examchecker_standards_praesenz` / `_fern`). Die alten Schlüssel bleiben unangetastet.

## Konfiguration

Administration → Plugins → ExtendedTestSettings → Reiter **ExamChecker**.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 2.0.0 | `ExamChecker_v2.0.0.zip` | 9 | Konfigurierbare Soll-Werte je Check (eigene Tabelle `examchecker_rules`); Prüflauf über eigenen Proxy-Endpunkt statt `cmd=saveSettings`; dreiwertiges Ergebnis; Korrektur der Prüfungen gegen Online-Status, Prüfungsansicht, Verfügbarkeit, Fragenpools und Prüfungsbedingungen |
| 1.2.0 | `archive/ExamChecker_v1.2.0.zip` | 9 | Modul-i18n DE/EN inkl. AJAX-Ergebnis-Panel |
| 1.1.1 | `archive/ExamChecker_v1.1.1.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.1.0 | `archive/ExamChecker_v1.1.0.zip` | 9–12 | Migration aus Sonderfall zu externem Modul, `implements ilExamModuleInterface` |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

> **Wichtig:** 2.0.0 legt eine Datenbanktabelle an. Das Update muss deshalb über den ZIP-Upload in der Administration laufen — nur dort wird `sql/install.php` ausgeführt.

## Entwickler-Dokumentation

Vollständige Doku (Check-Katalog, Datenfluss, Endpunkt, Migration):
→ [`/docs/modules/ExamChecker.md`](../../docs/modules/ExamChecker.md)
