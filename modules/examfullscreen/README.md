# ExamFullscreen

![Entwicklungsstand: Experimentell](https://img.shields.io/badge/Entwicklungsstand-Experimentell-orange)

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Fordert Prüfungsteilnehmer beim Teststart auf, den **Browser-Vollbild-Modus** (F11 bzw. Ctrl+Cmd+F auf Mac) zu aktivieren. Überwacht während der Prüfung, ob der Vollbild-Modus verlassen wird, und reagiert mit einer **Warnung** oder **Sperrung** des Tests.

Nutzt bewusst den Browser-Fullscreen (F11) statt der JavaScript Fullscreen-API — dadurch überlebt der Vollbild-Modus Seitennavigationen beim Fragenwechsel ohne Flicker.

## Konfiguration

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Vollbild-Modus aktivieren | `examfullscreen_enabled` | Checkbox |
| Verhalten beim Verlassen | `examfullscreen_mode` | `warn` (Hinweis, Test läuft weiter) / `force` (Test blockiert) |

## Datenbank

Eigene Tabelle: `examfullscr_settings`

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `mode` | `'warn'` oder `'force'` (Default: `'warn'`) |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `css/examfullscreen.css` | Styles für Start- und Warn-Overlay |
| `js/examfullscreen.js` | Vollbild-Erkennung (resize-Event) + Overlay-Logik |

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 10.0.1 | `ExamFullscreen_v10.0.1.zip` | 10 | AGPL-3.0-only: Lizenz- und Autorenhinweise im ZIP; Lizenzen mitgelieferter Fremdkomponenten ergänzt |
| 10.0.0 | `archive/ExamFullscreen_v10.0.0.zip` | 10 | Port auf ILIAS 10: keine Code-Änderungen im Modul nötig — Web-Asset-Pfade laufen über den zentralen Registry-Compat des Plugins (ab 10.0.0). Start der ILIAS-10-Linie (Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1); module.json: ilias_min/max = 10 |
| 2.1.0 | `archive/ExamFullscreen_v2.1.0.zip` | 9 | Modul-i18n DE/EN (ilModuleLanguage) |
| 2.0.1 | `ExamFullscreen_v2.0.1.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.0.0 | `ExamFullscreen_v1.0.0.zip` | 9–12 | Initiale Version mit requestFullscreen() API |
| 1.0.1 | `ExamFullscreen_v1.0.1.zip` | 9–12 | Silent-Restore bei Fragenwechsel |
| 2.0.0 | `ExamFullscreen_v2.0.0.zip` | 9–12 | Umbau auf Browser-Fullscreen (F11), kein Flicker, OS-Erkennung, Session-Key pro Test |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, JS-Ablauf, Handler-Methoden):
→ [`/docs/modules/ExamFullscreen.md`](../../docs/modules/ExamFullscreen.md)
