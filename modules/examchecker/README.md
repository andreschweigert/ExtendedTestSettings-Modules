# ExamChecker

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Prüft die Einstellungen eines ILIAS-Tests gegen definierte **Prüfungsstandards** — separat für Präsenz- und Fernprüfungen. In den Test-Einstellungen erscheint ein Check-Button; das Ergebnis listet korrekte und abweichende Einstellungen farbkodiert auf, inkl. Warnung bei vorhandenen Vorbedingungen.

**Kein Testplayer-Widget** — läuft ausschließlich in der Test-Einstellungsseite.  
**Keine eigene Datenbanktabelle** — liest nur ILIAS-Kerntabellen.

## Geprüfte Einstellungen (Auswahl)

| Kategorie | Beispiele |
|---|---|
| Allgemein | Fragenpools, Datenschutz (mit Namen), Online-Status, Passwort |
| Durchführung | Testdurchläufe auf 1 begrenzt, Prüfungsansicht (Kiosk), Autosave 30s |
| Bewertung | Teilpunkte aktiviert, keine negativen Punkte, besten Durchlauf bewerten |
| Nur Fernprüfung | Eigenständigkeitserklärung, Bearbeitungsdauer begrenzt |
| Nur Präsenzprüfung | Weiterleitung zu `logout.php` (Kiosk-Mode 2) |
| Manuell | Start- und Endzeiten, Bearbeitungsdauer (Plausibilitätsprüfung) |
| Automatisch | Vorbedingungen, zeitlich begrenzte Verfügbarkeit |

Die aktiven Standards sind über `ilSetting` konfigurierbar (`examchecker_standards_praesenz` / `examchecker_standards_fern`). Ohne Konfiguration gelten hardcodierte Defaults (17+ Checks).

## Technische Besonderheit: AJAX via saveSettings

Der Check läuft als AJAX-Request — das JavaScript sendet einen POST mit `action=exam_check` an `cmd=saveSettings`. Der Handler erkennt diesen Parameter, führt den Check durch und antwortet mit `Content-Type: application/json` + `exit`. Kein separater API-Endpunkt nötig.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.2.0 | `ExamChecker_v1.2.0.zip` | 9 | Modul-i18n DE/EN inkl. AJAX-Ergebnis-Panel |
| 1.1.1 | `ExamChecker_v1.1.1.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.1.0 | `ExamChecker_v1.1.0.zip` | 9–12 | Migration aus Sonderfall zu externem Modul, `implements ilExamModuleInterface` |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Check-Pipeline, alle geprüften DB-Felder, AJAX-Mechanismus, Standards-Konfiguration):
→ [`/docs/modules/ExamChecker.md`](../../docs/modules/ExamChecker.md)
