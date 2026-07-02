# ExamGuard

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Injiziert ein JavaScript in den Testplayer, das unfaires Verhalten während der Prüfung erschwert. Läuft **vollständig unsichtbar im Hintergrund** — keine Benutzeroberfläche.

Blockiert clientseitig:
- Copy & Paste (`Ctrl+C`, `Ctrl+V`, `Ctrl+X`)
- Rechtsklick-Kontextmenü
- Keyboard-Shortcuts: `Ctrl+A`, `Ctrl+S`, `F12` (DevTools)
- Text-Selektion

> **Hinweis:** Die Schutzmaßnahmen sind rein clientseitig und können von technisch
> versierten Nutzern umgangen werden. ExamGuard ist eine Erschwernis, keine Garantie.

## Konfiguration

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Prüfungsschutz aktivieren | `examguard_enabled` | Checkbox |

## Datenbank

Eigene Tabelle: `examguard_settings`

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `exit_prevention` | Reserviert (Default 1, aktuell ungenutzt) |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `js/examguard.js` | Schutz-Logik (Event-Listener für Copy/Paste, Rechtsklick, Shortcuts) |

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.2.0 | `ExamGuard_v1.2.0.zip` | 9 | Modul-i18n DE/EN (ilModuleLanguage) |
| 1.1.1 | `ExamGuard_v1.1.1.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.1.0 | `ExamGuard_v1.1.0.zip` | 9–12 | Migration aus Built-in zu externem Modul, Asset-Pfade angepasst |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, Handler-Methoden, Hinweis zu `exit_prevention`):
→ [`/docs/modules/ExamGuard.md`](../../docs/modules/ExamGuard.md)
