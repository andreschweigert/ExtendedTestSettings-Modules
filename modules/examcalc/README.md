# ExamCalc

![Entwicklungsstand: Prüfungserprobt](https://img.shields.io/badge/Entwicklungsstand-Pr%C3%BCfungserprobt-brightgreen)

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Stellt während einer Prüfung einen **Taschenrechner** als schwebendes Overlay zur Verfügung (Shortcut: `Alt + R`). Zwei Modi:

- **Einfach** — Grundrechenarten (empfohlen, stabil)
- **Wissenschaftlich** — sin, cos, tan, √, ^ usw. *(Beta, kann Fehler enthalten)*

Der Taschenrechner erscheint ausschließlich im Testplayer und ist pro Test individuell aktivierbar.

## Konfiguration

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Taschenrechner aktivieren | `examcalc_enabled` | Checkbox |
| Taschenrechner-Typ | `examcalc_mode` | `simple` / `advanced` |

## Datenbank

Eigene Tabelle: `examcalc_settings`

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `calculator_mode` | `simple` oder `advanced` |

## Geladene Assets im Testplayer

| Datei | Modus |
|---|---|
| `css/examcalc.css` | beide |
| `js/calculator-simple.js` | simple |
| `js/math.min.js` + `js/calculator.js` | advanced (math.js muss vor calculator.js geladen werden) |
| `js/examcalc-loader.js` | beide |
| `templates/calculator_simple.html` / `calculator_overlay.html` | je nach Modus |

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.2.0 | `ExamCalc_v1.2.0.zip` | 9 | Modul-i18n DE/EN (ilModuleLanguage), sichtbare Strings über lang-Dateien |
| 1.1.1 | `ExamCalc_v1.1.1.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.1.0 | `ExamCalc_v1.1.0.zip` | 9–12 | Migration aus Built-in zu externem Modul, Asset-Pfade angepasst |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, Handler-Methoden, Asset-Injection-Details):
→ [`/docs/modules/ExamCalc.md`](../../docs/modules/ExamCalc.md)
