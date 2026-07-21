# ExamDyslexia

> **Entwicklungsstand:** Experimentell

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Bietet einen **Legasthenie-freundlichen Lesemodus** im ILIAS Test-Player. Studierende können den Modus per Toggle-Button selbst aktivieren oder deaktivieren. Dozenten konfigurieren, welche Optionen erlaubt sind.

## Features

- **OpenDyslexic Schrift** — lokal im Modul enthalten, kein CDN
- **Erhöhter Zeilen- und Buchstabenabstand** — stufenweise konfigurierbar
- **Pastell-Hintergrundfarbe** — Creme, Hellgelb oder Hellblau (optional)
- **Toggle-Button** — `position: fixed; top: 80px; right: 16px` (kein Konflikt mit examcalc)
- **sessionStorage** — Zustand überlebt Fragenwechsel (Page-Reload in ILIAS)
- **Erzwingungsmodus** — Dozent kann Toggle deaktivieren, Modus ist dann für alle aktiv
- **Default: deaktiviert** — Student muss bewusst einschalten

## Konfiguration (Dozent)

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Legasthenie-Modus aktivieren | `examdyslexia_enabled` | Checkbox |
| Student kann selbst ein-/ausschalten | `examdyslexia_allow_toggle` | Checkbox (Default: an) |
| OpenDyslexic Schrift verwenden | `examdyslexia_use_font` | Checkbox (Default: an) |
| Hintergrundfarbe | `examdyslexia_bg_color` | `none` / `#FEFAE0` / `#FFFDE7` / `#E3F2FD` |
| Zeilenabstand | `examdyslexia_line_height` | `1.5` / `1.8` / `2.0` / `2.5` |
| Buchstabenabstand | `examdyslexia_letter_spacing` | `0` / `0.05em` / `0.12em` / `0.2em` |

**Hinweis:** Wenn `allow_toggle` deaktiviert ist, wird der Modus für **alle** Teilnehmer erzwungen — ohne Abschaltmöglichkeit.

## Datenbank

Eigene Tabelle: `examdyslexia_settings`

| Spalte | Typ | Default | Bedeutung |
|---|---|---|---|
| `test_id` (PK) | integer | — | ILIAS obj_id des Tests |
| `enabled` | integer 0/1 | 0 | Modul aktiviert |
| `allow_toggle` | integer 0/1 | 1 | Student darf selbst schalten |
| `use_font` | integer 0/1 | 1 | OpenDyslexic verwenden |
| `line_height` | text | `1.8` | CSS line-height Wert |
| `letter_spacing` | text | `0.05em` | CSS letter-spacing Wert |
| `bg_color` | text | `none` | CSS Hintergrundfarbe oder `none` |
| `updated_at` | timestamp | — | Letzte Änderung |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `css/examdyslexia.css` | Styles für @font-face, Lesemodus-Scope, Toggle-Button |
| `js/examdyslexia.js` | Toggle-Logik, sessionStorage, CSS Custom Properties, Font-Fallback |
| `fonts/OpenDyslexic-Regular.woff2` | OpenDyslexic Regular (SIL OFL) |
| `fonts/OpenDyslexic-Regular.woff` | OpenDyslexic Regular Fallback |
| `fonts/OpenDyslexic-Bold.woff2` | OpenDyslexic Bold (SIL OFL) |
| `fonts/OpenDyslexic-Bold.woff` | OpenDyslexic Bold Fallback |

## Technische Details

### CSS-Strategie

- Scope-Anker `#tst_output` — stabile ILIAS Test-Player ID
- Aktivierungsklasse `body.examdyslexia-active` — gesetzt/entfernt per JS
- CSS Custom Properties (`--examdyslexia-line-height`, `--examdyslexia-letter-spacing`, `--examdyslexia-bg`) — vom JS via `document.documentElement.style.setProperty()` gesetzt
- `@font-face` in CSS + JS-Fallback mit absolutem Pfad (für den Fall dass ILIAS CSS-Pfade umschreibt)

### JS-Strategie

- Liest `window.ExamDyslexia.config` bei DOM-Ready (gesetzt von PHP via `addOnLoadCode`)
- Config wird **nicht** im IIFE-Init gecacht — `addJavaScript` läuft vor `addOnLoadCode`
- Storage-Key: `examdyslexia_active_{active_id}` — eindeutig pro Prüfungsversuch
- `allow_toggle = false` → Body-Klasse direkt setzen, kein Button, kein sessionStorage

### Button-Position

```
top: 80px; right: 16px; z-index: 9000
```

- examcalc: `bottom: 20px; right: 20px` → kein Konflikt
- examfullscreen: eigener Button (andere Position) → kein Konflikt

### Lizenz der Fonts

OpenDyslexic ist unter der **SIL Open Font License 1.1** lizenziert — frei verwendbar, auch kommerziell, auch in geschlossenen Systemen.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 10.0.0 | `ExamDyslexia_v10.0.0.zip` | 10 | Port auf ILIAS 10: Cache-Busting (`filemtime`) nutzt den web-relativen Pfad — `ILIAS_ABSOLUTE_PATH` zeigt in 10 am Webroot (`public/`) vorbei. Start der ILIAS-10-Linie (Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1); module.json: ilias_min/max = 10 |
| 1.1.0 | `archive/ExamDyslexia_v1.1.0.zip` | 9 | Modul-i18n DE/EN (ilModuleLanguage) |
| 1.0.1 | `ExamDyslexia_v1.0.1.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.0.0 | `ExamDyslexia_v1.0.0.zip` | 9–12 | Erstveröffentlichung |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).
