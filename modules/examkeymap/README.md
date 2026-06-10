# ExamKeymap

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Stellt im Testplayer ein **virtuelles Tastatur-Layout (DE/EN)** bereit — für Prüfungen auf ChromeOS-Geräten im Kioskmodus, wo die Eingabesprache weder angezeigt noch umgestellt werden kann. Ein kleiner, fixierter Button unten rechts rotiert durch drei Zustände: **OS → DE → EN → OS**. Ist DE oder EN aktiv, fängt das Skript Tastendrücke ab und schreibt sie anhand der **physischen Taste** (`KeyboardEvent.code`, layoutunabhängig) in das gewählte Layout um — inklusive y/z-Tausch, Shift-Belegung der Ziffernreihe, Umlauten (ä ö ü ß) und AltGr-Kombinationen (@ € { } [ ] \ ~ | µ ² ³).

Funktioniert in `input[type=text]`, `textarea` und im TinyMCE-Editor der Freitextfragen (contenteditable im iframe, wird laufend nachregistriert). Die Layout-Wahl wird pro Browser-Tab gemerkt (`sessionStorage`) und überlebt die Fragennavigation.

## Konfiguration

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Virtuelles Tastatur-Layout aktivieren | `examkeymap_enabled` | Checkbox |
| Standard-Layout beim ersten Laden | `examkeymap_default` | `off` (OS-Layout, kein Umschreiben) / `de` (QWERTZ) / `en` (QWERTY EN-US) |

## Datenbank

Eigene Tabelle: `examkeymap_settings`

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `default_layout` | `'off'`, `'de'` oder `'en'` (Default: `'off'`) |
| `created_at` | Zeitpunkt der ersten Speicherung |
| `updated_at` | Zeitpunkt der letzten Speicherung |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `css/examkeymap.css` | Styles für den Umschalt-Button |
| `js/examkeymap.js` | Mapping-Tabellen DE/EN, Keydown-Interception, Button, iframe-Hooks |

## Was NICHT umgeschrieben wird

- Ctrl-/Meta-Kombinationen (Copy/Paste etc.) — außer echtem AltGr (Ctrl+Alt bzw. AltRight)
- Funktions-, Navigations- und Editiertasten (F1–F12, Pfeile, Home/End, Backspace, Delete, Tab, Enter, Escape)
- Alles im Zustand „OS" (Pass-through, Referenzverhalten)
- Ziffernblock (Numpad) — Ziffern sind layoutunabhängig; `NumpadDecimal` wird nicht angepasst

## Einschränkungen

- **Dead Keys** (`^` `´` `` ` ``) werden vereinfacht behandelt: Das Literal-Zeichen wird
  direkt eingefügt, es findet **kein Kombinieren** mit dem Folgezeichen statt
  (also kein `^` + `a` → `â`).
- Cross-Origin-iframes können nicht erreicht werden (betrifft TinyMCE nicht, dessen
  iframe ist same-origin).

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.0.0 | `ExamKeymap_v1.0.0.zip` | 9 | Initiale Version: Layouts DE (ISO QWERTZ) + EN-US (QWERTY), Drei-Zustände-Button, TinyMCE-Support, sessionStorage-Persistenz |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, JS-Ablauf, Handler-Methoden):
→ [`/docs/modules/ExamKeymap.md`](../../docs/modules/ExamKeymap.md)
