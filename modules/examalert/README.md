# ExamAlert

![Entwicklungsstand: Prüfungserprobt](https://img.shields.io/badge/Entwicklungsstand-Pr%C3%BCfungserprobt-brightgreen)

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Zeigt den Prüfungsteilnehmern ein **Popup-Modal mit Countdown**, wenn die verbleibende Testzeit unter einen konfigurierbaren Schwellenwert fällt. Warnzeit und Nachricht sind pro Test einstellbar. Die mitgelieferte Standardnachricht ist zweisprachig (Deutsch/Englisch).

Besonderheit: Neue Tests werden **automatisch aktiviert**, sofern die globale Plugin-Einstellung `examalert_auto_activate` gesetzt ist (Default: aktiv).

## Konfiguration

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Zeit-Warnung aktivieren | `examalert_enabled` | Checkbox |
| Warnung anzeigen X Sekunden vor Testende | `examalert_time` | 5–3600 (Default: 30) |
| Nachricht (HTML erlaubt) | `examalert_message` | Freitext (`<strong>`, `<u>`, `<br>` empfohlen) |

Globale Einstellung (Plugin-Administration):

| ilSetting-Key | Namespace | Bedeutung |
|---|---|---|
| `examalert_auto_activate` | `extendedtestsettings` | `1` = neue Tests automatisch aktivieren |

## Datenbank

Eigene Tabelle: `examalert_settings`

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `alert_time` | Sekunden vor Testende (Default: 30) |
| `alert_message` | HTML-Nachricht (max. 1000 Zeichen) |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `css/examalert.css` | Styles für Alert-Popup |
| `js/examalert.js` | Countdown-Logik + Popup-Anzeige (einmalig pro Sitzung) |

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.2.2 | `ExamAlert_v1.2.2.zip` | 9 | AGPL-3.0-only: Lizenz- und Autorenhinweise im ZIP; Lizenzen mitgelieferter Fremdkomponenten ergänzt |
| 1.2.1 | `archive/ExamAlert_v1.2.1.zip` | 9 | Sicherheitsfix: Warnmeldung wird vor der Anzeige escaped (XSS). Erlaubte Format-Tags (`<strong>`, `<b>`, `<i>`, `<u>`, `<br>`) bleiben erhalten, anderes HTML wird als Text angezeigt |
| 1.2.0 | `archive/ExamAlert_v1.2.0.zip` | 9 | Modul-eigene Sprachdateien DE/EN, Formular-Texte über ilModuleLanguage |
| 1.1.1 | `ExamAlert_v1.1.1.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.1.0 | `ExamAlert_v1.1.0.zip` | 9–12 | Migration aus Built-in zu externem Modul, Asset-Pfade angepasst |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, Auto-Aktivierungs-Logik, Handler-Methoden):
→ [`/docs/modules/ExamAlert.md`](../../docs/modules/ExamAlert.md)
