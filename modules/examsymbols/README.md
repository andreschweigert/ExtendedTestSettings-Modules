# ExamSymbols

> **Entwicklungsstand:** Prüfungserprobt

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Stellt während einer Prüfung eine **klickbare Sonderzeichen-Palette** als schwebendes Overlay zur Verfügung (Shortcut: `Alt + S`). Zeichen werden in das aktuell fokussierte Eingabefeld eingefügt — inkl. Rich-Text-Editoren (TinyMCE, CKEditor).

Acht vordefinierte **Symbol-Templates** stehen zur Auswahl, pro Test konfigurierbar.

## Symbol-Templates

| Template-Key | Bezeichnung | Inhalt |
|---|---|---|
| `math` | Mathematik/Wissenschaft | Grundsymbole, griechische Buchstaben (klein + groß) |
| `greek` | Griechisch | Vollständiges Alphabet (klein + groß) |
| `chemistry` | Chemie | Reaktionspfeile, Bindungen, Ladungen, griechische Symbole |
| `physics` | Physik | Operatoren, Integrale, Vektoren, griechische Buchstaben |
| `spanish` | Spanisch | Akzente, Ñ, ¿, ¡ |
| `italian` | Italienisch | Grave- und Acute-Akzente |
| `french` | Französisch | Akzente + Ligaturen (æ, œ) |
| `german` | Deutsch | Umlaute + Eszett |

## Konfiguration

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Sonderzeichen-Palette aktivieren | `examsymbols_enabled` | Checkbox |
| Symbol-Vorlage | `examsymbols_template` | Template-Key (Default: `math`) |

## Datenbank

Eigene Tabelle: `examsymbols_settings`

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `template` | gewählter Template-Key (Default: `math`) |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `css/examsymbols.css` | Styles für Palette-Overlay |
| `js/symbols-manager.js` | Palette-Verwaltung + Zeichen-Einfüge-Logik |
| `js/examsymbols-loader.js` | Initialisierung + Floating-Button |
| `templates/symbols_overlay.html` | HTML-Template der Palette |

Symbol-Definitionen werden als JSON via `window.ExamSymbols.config` an JavaScript übergeben — kein API-Aufruf, keine externe Datei.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.1.1 | `ExamSymbols_v1.1.1.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.1.0 | `ExamSymbols_v1.1.0.zip` | 9–12 | Migration aus Built-in zu externem Modul, Config-Klasse ausgelagert |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, alle Templates mit Symbolanzahl, Handler- und Config-Methoden):
→ [`/docs/modules/ExamSymbols.md`](../../docs/modules/ExamSymbols.md)
