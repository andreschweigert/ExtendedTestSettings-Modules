# ExamDyslexia

![Entwicklungsstand: Experimentell](https://img.shields.io/badge/Entwicklungsstand-Experimentell-orange)

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Bietet einen **Legasthenie-freundlichen Lesemodus** im ILIAS Test-Player. Studierende können den Modus per Toggle-Button selbst aktivieren und die Darstellung über ein Panel an ihre Bedürfnisse anpassen. Dozenten geben die Startwerte vor und entscheiden, was die Studierenden selbst verstellen dürfen.

## Features

- **OpenDyslexic Schrift** — lokal im Modul enthalten, kein CDN
- **Selbst anpassbare Darstellung** — Studierende verstellen Hintergrundfarbe, Zeilen- und Zeichenabstand direkt im Testplayer
- **Erhöhter Zeilen- und Zeichenabstand** — stufenweise konfigurierbar
- **Pastell-Hintergrundfarbe** — Creme, Hellgelb oder Hellblau (optional)
- **Leseschiene** — hebt die Zeile unter dem Mauszeiger hervor
- **Toggle-Button + Zahnrad** — `position: fixed; top: 80px; right: 16px` (kein Konflikt mit examcalc)
- **sessionStorage** — Zustand und gewählte Stufen überleben den Fragenwechsel (Page-Reload in ILIAS)
- **Erzwingungsmodus** — Dozent kann Toggle deaktivieren, Modus ist dann für alle aktiv
- **Default: deaktiviert** — Student muss bewusst einschalten

## Konfiguration (Dozent)

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Legasthenie-Modus aktivieren | `examdyslexia_enabled` | Checkbox |
| Student kann selbst ein-/ausschalten | `examdyslexia_allow_toggle` | Checkbox (Default: an) |
| Student kann die Darstellung selbst anpassen | `examdyslexia_allow_customize` | Checkbox (Default: an) |
| OpenDyslexic Schrift verwenden | `examdyslexia_use_font` | Checkbox (Default: an) |
| Hintergrundfarbe | `examdyslexia_bg_color` | `none` / `#FEFAE0` / `#FFFDE7` / `#E3F2FD` |
| Zeilenabstand | `examdyslexia_line_height` | `1.5` / `1.8` / `2.0` / `2.5` |
| Buchstabenabstand | `examdyslexia_letter_spacing` | `0` / `0.05em` / `0.12em` / `0.2em` |

**Hinweise:**

- Wenn `allow_toggle` deaktiviert ist, wird der Modus für **alle** Teilnehmer erzwungen — ohne Abschaltmöglichkeit.
- Wenn `allow_customize` aktiv ist, sind Hintergrundfarbe, Zeilen- und Buchstabenabstand nur noch **Startwerte**; der Studierende verstellt sie im Testplayer entlang derselben Stufen. Ist die Option aus, gelten die Dozentenwerte unveränderlich (Verhalten bis 1.1.0).

## Bedienung (Studierende)

Oben rechts im Testplayer erscheint die Leiste:

| Element | Wirkung |
|---|---|
| 👁 **Lesemodus: EIN/AUS** | schaltet den Lesemodus um (nur bei `allow_toggle`) |
| ⚙ | öffnet/schließt das Einstellungs-Panel (nur bei `allow_customize`) |

Im Panel:

| Zeile | Steuerung |
|---|---|
| Hintergrund | vier Farbfelder (ohne Farbe / Creme / Hellgelb / Hellblau) |
| Zeilenabstand | `−` / `+` über die Stufen 1.5 – 2.5 |
| Zeichenabstand | `−` / `+` über Normal / Leicht / Mittel / Stark |
| Zurücksetzen | stellt die vom Dozenten vorgegebenen Werte wieder her |

Jede Änderung im Panel schaltet den Lesemodus mit ein, damit die Einstellung sofort wirkt. Das Panel schließt per `Esc` oder Klick daneben.

## Datenbank

Eigene Tabelle: `examdyslexia_settings`

| Spalte | Typ | Default | Bedeutung |
|---|---|---|---|
| `test_id` (PK) | integer | — | ILIAS obj_id des Tests |
| `enabled` | integer 0/1 | 0 | Modul aktiviert |
| `allow_toggle` | integer 0/1 | 1 | Student darf selbst schalten |
| `allow_customize` | integer 0/1 | 1 | Student darf die Darstellung anpassen |
| `use_font` | integer 0/1 | 1 | OpenDyslexic verwenden |
| `line_height` | text | `1.8` | CSS line-height Startwert |
| `letter_spacing` | text | `0.05em` | CSS letter-spacing Startwert |
| `bg_color` | text | `none` | CSS Hintergrundfarbe oder `none` |
| `updated_at` | timestamp | — | Letzte Änderung |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `css/examdyslexia.css` | Styles für @font-face, Lesemodus-Scope, Leiste, Panel, Leseschiene |
| `js/examdyslexia.js` | Toggle, Panel, sessionStorage, CSS Custom Properties, Font-Fallback |
| `fonts/OpenDyslexic-Regular.woff2` | OpenDyslexic Regular (SIL OFL) |
| `fonts/OpenDyslexic-Regular.woff` | OpenDyslexic Regular Fallback |
| `fonts/OpenDyslexic-Bold.woff2` | OpenDyslexic Bold (SIL OFL) |
| `fonts/OpenDyslexic-Bold.woff` | OpenDyslexic Bold Fallback |

## Technische Details

### CSS-Strategie

- Scope-Anker `#tst_output` — stabile ILIAS Test-Player ID
- Aktivierungsklasse `body.examdyslexia-active` — gesetzt/entfernt per JS
- CSS Custom Properties (`--examdyslexia-line-height`, `--examdyslexia-letter-spacing`, `--examdyslexia-bg`) — vom JS via `document.documentElement.style.setProperty()` gesetzt; das Panel schreibt in dieselben Variablen, dadurch wirkt jede Änderung sofort
- Leiste und Panel hängen außerhalb von `#tst_output` und setzen Schrift/Abstände neutral — sie bleiben auch bei starken Werten lesbar
- `@font-face` in CSS + JS-Fallback mit absolutem Pfad (für den Fall dass ILIAS CSS-Pfade umschreibt)

### JS-Strategie

- Liest `window.ExamDyslexia.config` live über `cfg()` — nie beim IIFE-Init cachen, da `addJavaScript` vor `addOnLoadCode` läuft
- Sichtbare Texte kommen als `labels`-Block aus der Config (i18n über die Modul-lang-Dateien)
- Storage-Key: `examdyslexia_state_{active_id}` — JSON mit `{on, bg, lh, ls, open}`, eindeutig pro Prüfungsversuch
- Gewählte Stufen werden gegen dieselben Whitelisten geprüft wie im PHP-Handler; unbekannte Werte fallen auf den Dozentenwert zurück
- `allow_customize = false` → kein Zahnrad, kein Panel; gespeicherte Stufen werden ignoriert
- `allow_toggle = false` → Body-Klasse direkt setzen, kein Ein/Aus-Button

### Button-Position

```
top: 80px; right: 16px; z-index: 9000
```

- examcalc: `bottom: 20px; right: 20px` → kein Konflikt
- exammagnifier: `bottom: 20px; left: 20px` → kein Konflikt
- examfullscreen: eigener Button (andere Position) → kein Konflikt

### Lizenz der Fonts

OpenDyslexic ist unter der **SIL Open Font License 1.1** lizenziert — frei verwendbar, auch kommerziell, auch in geschlossenen Systemen.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.2.0 | `ExamDyslexia_v1.2.0.zip` | 9 | Studierende passen Hintergrund, Zeilen- und Zeichenabstand im Testplayer selbst an (Panel am Zahnrad); neue Dozenten-Option `allow_customize`; JS-Texte über lang-Dateien |
| 1.1.0 | `ExamDyslexia_v1.1.0.zip` | 9 | Modul-i18n DE/EN (ilModuleLanguage) |
| 1.0.1 | `ExamDyslexia_v1.0.1.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.0.0 | `ExamDyslexia_v1.0.0.zip` | 9–12 | Erstveröffentlichung |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

**Update auf 1.2.0:** zwingend über den ZIP-Upload in der Administration — `sql/install.php` ergänzt dabei die Spalte `allow_customize`.
