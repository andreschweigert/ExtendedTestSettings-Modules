# ExamKeymap

> **Entwicklungsstand:** Experimentell

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Stellt im Testplayer ein **virtuelles Tastatur-Layout (DE/EN)** bereit — für Prüfungen auf ChromeOS-Geräten im Kioskmodus, wo die Eingabesprache weder angezeigt noch umgestellt werden kann. Ein kleiner, fixierter Button unten rechts rotiert durch drei Zustände: **OS → DE → EN → OS**. Ist DE oder EN aktiv, fängt das Skript Tastendrücke ab und schreibt sie anhand der **physischen Taste** (`KeyboardEvent.code`, layoutunabhängig) in das gewählte Layout um — inklusive y/z-Tausch, Shift-Belegung der Ziffernreihe, Umlauten (ä ö ü ß) und AltGr-Kombinationen (@ € { } [ ] \ ~ | µ ² ³).

Funktioniert in `input[type=text]`, `textarea` und im TinyMCE-Editor der Freitextfragen (contenteditable im iframe, wird laufend nachregistriert). Die Layout-Wahl wird pro Browser-Tab gemerkt (`sessionStorage`) und überlebt die Fragennavigation.

**Seit 1.1.0/1.1.1:**

- **Virtuelle Feststelltaste** (pro Test): Ein ⇪-Button über dem Layout-Button wirkt als Feststelltaste (nur bei aktivem Layout DE/EN). Zusätzlich toggelt die Lupe-/Suchtaste (CapsLock-Position auf Chromebooks), sofern das Gerät den Tastendruck durchreicht — im ChromeOS-Kioskmodus schluckt das System den Einzeldruck meist, dort ist der ⇪-Button der verlässliche Weg (Grund für 1.1.1).
- **Globale Übersetzungstabelle** in der Plugin-Administration (Tab „Tastatur-Layout"): Ein gezeichnetes virtuelles ISO-Keyboard zeigt die Belegungen beider Layouts; per Klick auf eine Taste lassen sich Basis/Shift/AltGr überschreiben oder Tasten deaktivieren. Die Overrides gelten für alle Tests.

## Konfiguration

Pro Test, in den Erweiterten Test-Einstellungen:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Virtuelles Tastatur-Layout aktivieren | `examkeymap_enabled` | Checkbox |
| Standard-Layout beim ersten Laden | `examkeymap_default` | `off` (OS-Layout, kein Umschreiben) / `de` (QWERTZ) / `en` (QWERTY EN-US) |
| Lupe-/Suchtaste als Feststelltaste | `examkeymap_search_caps` | Checkbox |

Global, unter Administration → Plugins → ExtendedTestSettings → Tab „Tastatur-Layout":
Übersetzungstabelle (Overrides der eingebauten Layouts) mit virtuellem Keyboard-Editor.

## Datenbank

Tabelle `examkeymap_settings` (pro Test):

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `default_layout` | `'off'`, `'de'` oder `'en'` (Default: `'off'`) |
| `search_caps` | 1 = Lupe-Taste als Feststelltaste |
| `created_at` | Zeitpunkt der ersten Speicherung |
| `updated_at` | Zeitpunkt der letzten Speicherung |

Tabelle `examkeymap_custom` (global, Übersetzungstabelle):

| Spalte | Bedeutung |
|---|---|
| `layout` (PK) | `'de'` oder `'en'` |
| `keycode` (PK) | physische Taste (`KeyboardEvent.code`, z. B. `KeyQ`) |
| `base_char` / `shift_char` / `altgr_char` | Belegung der drei Ebenen; leer = Ebene nicht belegt (Pass-through) |

## Geladene Assets

| Datei | Kontext | Beschreibung |
|---|---|---|
| `css/examkeymap.css` | Testplayer | Styles für den Umschalt-Button |
| `js/examkeymap.js` | Testplayer + Admin | Mapping-Tabellen DE/EN, Keydown-Interception, Button, iframe-Hooks; exponiert `window.ExamKeymap.tables` |
| `js/examkeymap_admin.js` | Administration | Virtueller Keyboard-Editor für die Übersetzungstabelle |
| `css/examkeymap_admin.css` | Administration | Styles für den Keyboard-Editor |

## Was NICHT umgeschrieben wird

- Ctrl-/Meta-Kombinationen (Copy/Paste etc.) — außer echtem AltGr (Ctrl+Alt bzw. AltRight)
- Funktions-, Navigations- und Editiertasten (F1–F12, Pfeile, Home/End, Backspace, Delete, Tab, Enter, Escape)
- Alles im Zustand „OS" (Pass-through, Referenzverhalten)
- Ziffernblock (Numpad) — Ziffern sind layoutunabhängig; `NumpadDecimal` wird nicht angepasst

## Einschränkungen

- **Dead Keys** (`^` `´` `` ` ``) werden vereinfacht behandelt: Das Literal-Zeichen wird
  direkt eingefügt, es findet **kein Kombinieren** mit dem Folgezeichen statt
  (also kein `^` + `a` → `â`).
- **Lupe-als-Feststelltaste:** Im normalen ChromeOS (außerhalb Kiosk) öffnet die Lupe
  zusätzlich den Launcher — das kann eine Webseite nicht unterdrücken. Im Kioskmodus
  ist der Launcher i. d. R. nicht verfügbar; auf dem Zielgerät verifizieren.
  Auf Nicht-ChromeOS-Tastaturen entspricht `MetaLeft` der Windows-Taste.
- Cross-Origin-iframes können nicht erreicht werden (betrifft TinyMCE nicht, dessen
  iframe ist same-origin).

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 10.0.0 | `ExamKeymap_v10.0.0.zip` | 10 | Port auf ILIAS 10: `getModuleWebPath()` ohne falsches `public/`-Präfix (Marker-Ableitung statt ILIAS_ABSOLUTE_PATH-Strip). Start der ILIAS-10-Linie (Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1); module.json: ilias_min/max = 10 |
| 1.1.1 | `archive/ExamKeymap_v1.1.1.zip` | 9 | Virtueller ⇪-Feststelltasten-Button im Testplayer (ChromeOS-Kioskmodus schluckt den Lupe-Einzeldruck); Lupe-Toggle bleibt als Fallback |
| 1.1.0 | `archive/ExamKeymap_v1.1.0.zip` | 9 | Lupe-/Suchtaste als Feststelltaste (pro Test), globale Übersetzungstabelle mit virtuellem Keyboard-Editor in der Administration (benötigt Plugin-Update mit Tab „Tastatur-Layout") |
| 1.0.0 | `archive/ExamKeymap_v1.0.0.zip` | 9 | Initiale Version: Layouts DE (ISO QWERTZ) + EN-US (QWERTY), Drei-Zustände-Button, TinyMCE-Support, sessionStorage-Persistenz |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md). **Hinweis:** Der Admin-Tab
„Tastatur-Layout" kommt aus dem Plugin-Kern (ConfigGUI) — Haupt-Plugin auf dem
Stand mit ExamKeymap-Tab halten.

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, JS-Ablauf, Handler-Methoden):
→ [`/docs/modules/ExamKeymap.md`](../../docs/modules/ExamKeymap.md)
