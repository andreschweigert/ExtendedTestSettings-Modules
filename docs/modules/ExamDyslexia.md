# ExamDyslexia — Entwicklerdokumentation

**Modul-ID:** `examdyslexia`
**Referenz-ZIP:** `modules-zip/modules/examdyslexia/ExamDyslexia_v1.2.3.zip`
**Zuletzt aktualisiert:** 2026-07-29

---

## Was ist ExamDyslexia?

Legasthenie-freundlicher Lesemodus im Testplayer: Lesehilfe-Schrift nach Wahl
(OpenDyslexic oder Atkinson Hyperlegible, lokal mitgeliefert, kein CDN),
konfigurierbarer Zeilen-, Zeichen- und Wortabstand, optionaler Pastell-Hintergrund
und eine abschaltbare Leseschiene, die der Maus folgt. Standardmäßig
schaltet der Studierende den Modus selbst über einen Toggle-Button ein (unten
mittig — ILIAS legt seine Testnavigation links und rechts ab, examcalc sitzt
unten rechts, exammagnifier unten links); alternativ kann der Modus per Test
erzwungen werden (`allow_toggle` aus → immer aktiv, kein Button).

Seit 1.2.0 sind die Dozentenwerte nur noch **Startwerte**: Über ein Zahnrad neben
dem Schalter öffnet der Studierende ein Panel und verstellt Schrift,
Hintergrundfarbe, Zeilen-, Zeichen- und Wortabstand sowie die Leseschiene selbst
(`allow_customize`, Default an). Der Dozent kann das pro Test abschalten — dann
gelten seine Werte unveränderlich wie bis 1.1.0.

## Dateistruktur

```
modules/examdyslexia/
├── module.json                          kein gui_file — reines Player-Modul
├── class.ilExamDyslexiaHandler.php      Handler (Settings, Asset-Injection, Formular)
├── js/examdyslexia.js                   Leiste, Panel, Leseschiene, sessionStorage
├── css/examdyslexia.css                 Klassen-basierte Styles + Leiste/Panel/Schiene
├── fonts/OpenDyslexic-{Regular,Bold}.{woff2,woff}
├── fonts/AtkinsonHyperlegible-{Regular,Bold,Italic,BoldItalic}.woff2
├── fonts/OFL-{OpenDyslexic,AtkinsonHyperlegible}.txt   Lizenztexte (OFL-Pflicht)
├── lang/ilias_de.lang / ilias_en.lang
└── sql/install.php / uninstall.php
```

## module.json

Kein `gui_file`/`gui_class`, kein `allow_student_access` — das Modul hat keine
eigene GUI-Seite und keine AJAX-Endpoints. `tables: ["examdyslexia_settings"]`.

## Datenbank

### `examdyslexia_settings` (PK: `test_id` = obj_id)

| Spalte | Typ | Default | Beschreibung |
|---|---|---|---|
| `test_id` | integer(4) | — | ILIAS obj_id des Tests |
| `enabled` | integer(1) | 0 | Modul für diesen Test aktiv |
| `allow_toggle` | integer(1) | 1 | 1 = Student schaltet selbst (Button); 0 = erzwungen, immer aktiv |
| `allow_customize` | integer(1) | 1 | 1 = Student verstellt Darstellung im Panel; 0 = Dozentenwerte fix (ab 1.2.0) |
| `font_family` | text(20) | `opendyslexic` | Schlüssel aus der FONTS-Registry: default / opendyslexic / atkinson (ab 1.2.2) |
| `use_font` | integer(1) | 1 | Altbestand vor 1.2.2; wird aus `font_family` mitgeschrieben, siehe unten |
| `line_height` | text(10) | `1.8` | Whitelist: 1.5 / 1.8 / 2.0 / 2.5 |
| `letter_spacing` | text(10) | `0.05em` | Whitelist: 0 / 0.05em / 0.12em / 0.2em |
| `word_spacing` | text(10) | `0` | Whitelist: 0 / 0.16em / 0.3em / 0.5em (ab 1.2.2) |
| `bg_color` | text(20) | `none` | Whitelist: none / #FEFAE0 (Creme) / #FFFDE7 (Gelb) / #E3F2FD (Blau) |
| `ruler` | integer(1) | 1 | Leseschiene anzeigen (ab 1.2.3); Default 1 hält das Verhalten bis 1.2.2 |
| `updated_at` | timestamp | null | letzte Änderung |

Schreiben per `$db->replace()` (Upsert); alle Auswahlwerte werden im Handler
gegen `ALLOWED_*`-Whitelists bzw. die Schlüssel der FONTS-Registry validiert.

Nachträglich ergänzte Spalten (`allow_customize` mit 1.2.0, `font_family` und
`word_spacing` mit 1.2.2, `ruler` mit 1.2.3) legt `install.php` im `else`-Zweig per
`tableColumnExists()`-Guard nach — Updates müssen deshalb über den ZIP-Upload
laufen. `getConfig()` liest bewusst `SELECT *` und setzt für fehlende Spalten
Rückfallwerte, damit das Modul zwischen Datei- und Schema-Stand nicht bricht.

**Migration `use_font` → `font_family`:** Beim Anlegen der Spalte setzt
`install.php` alle Zeilen mit `use_font = 0` auf `default`; der Spaltendefault
`opendyslexic` deckt den Rest ab. Damit behalten bestehende Tests ihr Aussehen
ohne Zutun. `use_font` bleibt in der Tabelle und wird von `saveSettings()`
weiter konsistent gefüllt (`font_family = 'default'` → 0, sonst 1); `getConfig()`
wertet es nur noch aus, wenn `font_family` fehlt. So bleibt ein Downgrade auf
eine ältere Modulversion gefahrlos.

## Handler (ilExamDyslexiaHandler)

```
isEnabled(ref_id)          → examdyslexia_settings.enabled (defensiv, false bei Fehler)
injectAssets(tpl, ref_id)  → nur im Testplayer-Kontext (isTestPlayerContext()):
                             CSS+JS mit filemtime-Cache-Busting, Config via addOnLoadCode
                             in window.ExamDyslexia.config — inkl. font_path, der
                             Schriftliste (fonts, siehe Registry unten), der
                             Stufen-Whitelisten (bg_colors, line_heights,
                             letter_spacings, word_spacings) und aller sichtbaren
                             JS-Texte im labels-Block
saveSettings(ref_id, post) → Whitelist-Validierung, replace()
getFormItems(ref_id)       → Haupt-Checkbox mit SubItems: allow_toggle, allow_customize,
                             font_family, bg_color, line_height, letter_spacing,
                             word_spacing, ruler
```

`isTestPlayerContext()` ist der übliche bewusst duplizierte Helper (cmdClass-
Prüfung + REQUEST_URI-Fallback) — nicht in Plugin-classes extrahieren
(Self-contained-Regel).

Cache-Busting nutzt `@filemtime($css_file)` auf den **web-relativen** Pfad: bei
Web-Requests ist das CWD der Webroot (9 wie 10), während `ILIAS_ABSOLUTE_PATH`
in ILIAS 10 wegen `public/` ins Leere zeigen würde.

## Schrift-Registry

`ilExamDyslexiaHandler::FONTS` ist die einzige Stelle, an der Schriften stehen.
Jeder Eintrag hat `family` (Name fürs `@font-face`), `stack` (CSS-Wert, leer =
Standardschrift des Testplayers) und `faces` mit Datei, Gewicht, Schnitt und
Formaten. `getFontList()` macht daraus die Struktur, die als `config.fonts` ins
JavaScript geht; daraus entstehen die Panel-Auswahl **und** die
`@font-face`-Regeln. Die Dozenten-Auswahl im Formular wird ebenfalls über die
Registry-Schlüssel aufgebaut.

Eine weitere Schrift ergänzen heißt deshalb:

1. woff2 (optional zusätzlich woff) nach `fonts/`, Lizenztext daneben
2. Eintrag in `FONTS`
3. Labels `font_<id>` (Formular) und `js_font_<id>` (Panel) in beide lang-Dateien

CSS und JS bleiben unberührt. Die `@font-face`-Blöcke wurden mit 1.2.2 aus dem
Stylesheet entfernt: sie werden im JS erzeugt, weil nur dort der Pfad absolut
aufgelöst werden kann — und nur absolut funktionieren sie auch im
TinyMCE-iframe. Deklariert werden immer alle Schriften; geladen wird eine Datei
erst bei tatsächlicher Verwendung, sodass das Umschalten im Panel ohne
Nachlade-Logik auskommt.

Die Ersetzung im Stylesheet hängt an zwei Bedingungen: `body.examdyslexia-active`
**und** `body.examdyslexia-hasfont`. Die zweite Klasse setzt das JS nur, wenn der
Stack nicht leer ist — bei „Standard" bleibt die Schrift des Testplayers stehen,
während Abstände und Hintergrund trotzdem wirken.

## JavaScript

- Config **live** über `cfg()` lesen (addOnLoadCode läuft nach addJavaScript —
  nie beim IIFE-Init cachen). Sichtbare Texte über `txt(key)` aus `cfg().labels`.
- Zustand pro Teilnahme als JSON in `sessionStorage`
  (`examdyslexia_state_<active_id>`, Fallback `ref_id`):
  `{on, font, bg, lh, ls, ws, ruler, open}` — überlebt die Fragennavigation.
- Stufenwechsel laufen über die Indizes der Config-Listen; jeder geladene Wert
  wird mit `pick()` gegen dieselbe Liste geprüft. Bei `allow_customize = 0`
  werden gespeicherte Stufen verworfen und die Dozentenwerte gesetzt.
- Panel-Änderungen schalten den Lesemodus mit ein (`changed()`) — sonst bliebe
  die Einstellung ohne sichtbare Wirkung. `toggleRuler()` macht das ebenso,
  aber nur beim Einschalten; das Abschalten der Schiene darf den Modus nicht
  anwerfen.
- `@font-face` wird komplett per JS aus `config.fonts` erzeugt (siehe
  Schrift-Registry), mit über `document.baseURI` absolut aufgelöstem Pfad.
- Anwendung der Styles ausschließlich über CSS Custom Properties auf
  `documentElement` plus die Klasse `examdyslexia-active` auf `<body>`.
- Leseschiene (`#examdyslexia-ruler`): folgt dem Cursor innerhalb `#tst_output`,
  `pointer-events: none`, Höhe aus dem aktuellen Zeilenabstand; das Rect des
  Zielelements wird gecacht und nur bei `resize` verworfen.
  `applyRuler()` ist die einzige Stelle, die über Sichtbarkeit entscheidet
  (`state.on && state.ruler`); der `mousemove`-Handler prüft `state.ruler`
  zusätzlich, damit eine abgeschaltete Schiene nicht durch Mausbewegung
  wieder auftaucht. Nach dem Einschalten wird sie bewusst erst bei der
  nächsten Mausbewegung sichtbar — sonst erschiene sie an der veralteten
  Zeigerposition. Das Element bleibt beim Ausschalten im DOM.

### Zuordnungsfragen (assMatchingQuestion)

Klick auf einen Begriff markiert ihn und jede Stelle, an der er bereits
zugeordnet ist. ILIAS legt einen zugeordneten Begriff als **Kopie mit derselben
`data-id`** in die `.ilMatchingQuestionTerm`-Fläche der Definition — genau
darüber prüft auch ILIAS' eigenes JS die Zugehörigkeit
(`Modules/TestQuestionPool/js/ilMatchingQuestion.js`, `isDraggableToBeReactivated`).
Der Selektor ist deshalb `.draggable[data-type="term"][data-id="…"]`, auf den
Fragencontainer `[data-type="ilMatchingQuestion"]` begrenzt, damit mehrere
Fragen auf einer Seite sich nicht ins Gehege kommen. Die `data-id` wird vor dem
Einsetzen in den Selektor gegen `/^[\w-]+$/` geprüft.

Der Klick-Handler ist delegiert auf `document` und prüft `state.on` — ohne
aktiven Lesemodus passiert nichts. Es gibt bewusst **keine** eigene Einstellung
dafür; die Hervorhebung gehört zum Lesemodus und wird mit ihm abgeräumt
(`applyActive()`).

Zur Spezifität im CSS: Die allgemeine Hintergrund-Regel
(`#tst_output div:not(…)`) ist mit fünf `:not()`-Argumenten spezifischer als ein
schlichter `.examdyslexia-match`-Selektor und würde die Markierungsfarbe
überschreiben. Deshalb zwei Kunstgriffe: die Klasse wird in jener Regel per
`:not(.examdyslexia-match)` ausgeklammert, und die Rücknahme für die
Kindelemente trägt dieselbe `:not()`-Kette, um sie zu übertreffen.

### Medienplayer (Video-/Audiofragen)

ILIAS 9 nutzt MediaElement.js 4.2 — sämtliche Elemente tragen das Klassenpräfix
`mejs__` (`mejs__container`, `mejs__controls`, `mejs__button`, `mejs__time` …).
Der Player zeichnet helle Symbole auf dunklen Grund. Bis 1.2.0 traf ihn die
Regel `#tst_output div:not(...)`, wodurch Container und Steuerleiste
pastellfarben wurden und Play, Zeitleiste und Lautstärke praktisch verschwanden.

Behandlung seit 1.2.1, rein in CSS:

- **Hintergrund:** `:not([class*="mejs"])` in der div-Regel — nicht überschreiben
  statt zurücksetzen. Ein Rücksetzen ginge nicht sinnvoll, weil die Farbwerte aus
  dem Player-CSS stammen; wird die Regel gar nicht erst angewendet, greift dieses
  wieder. In MEJS 4 trägt jedes Container-Element eine `mejs__`-Klasse, der
  Ausschluss deckt den Teilbaum also vollständig ab.
- **Schrift und Zeichenabstand:** eigene Rücksetz-Regel auf
  `[class*="mejs"]` samt Nachfahren. Nötig, weil beide von `#tst_output` vererbt
  werden — vererbte Werte lassen sich **nicht** per `:not()` ausschließen. Ohne
  das zerläuft die Zeitanzeige durch das `letter-spacing`.
- `line-height` wird dort bewusst **nicht** angefasst: MEJS setzt für seine
  Controls feste Werte, ein `!important` darauf würde deren Layout brechen.

### TinyMCE (Freitextfragen)

ILIAS 9 nutzt TinyMCE 5. Der Editor rendert in einem eigenen iframe-Dokument —
Seiten-CSS greift dort **nicht**, die Eingabe der Studierenden bliebe sonst in
der Standardschrift. `syncEditorFrames()` injiziert deshalb einen `<style>` mit
der ID `examdyslexia-frame-style` in den `<head>` jedes Editor-iframes
(`iframe.tox-edit-area__iframe`, Fallback `iframe[id$="_ifr"]`) und aktualisiert
ihn aus `applyStyles()` und `applyActive()`, also bei jeder Stufenänderung und
beim Ein-/Ausschalten (aus → leerer Style).

Zwei Fallstricke, die der Code abfängt:

- **Font-Pfad:** im iframe ist die Basis-URL nicht der Webroot; `absFontPath()`
  löst `config.font_path` über `document.baseURI` absolut auf. Derselbe absolute
  Pfad wird auch für den `@font-face`-Fallback im Hauptdokument verwendet.
- **Zeitpunkt:** TinyMCE baut den Editor asynchron auf, teils lange nach
  DOM-Ready. `observeEditorFrames()` hängt einen MutationObserver (debounced,
  200 ms) an `document.body`; zusätzlich bekommt jeder iframe einmalig einen
  `load`-Handler (Marker `dataset.examdyslexiaBound`), weil das Element vor
  seinem Inhalt eingehängt wird.

### DOM der Leiste

```
#examdyslexia-bar                  fixed, bottom 16 / horizontal zentriert, z-index 9000
├── .examdyslexia-buttons          (unten dargestellt)
│   ├── #examdyslexia-toggle       nur bei allow_toggle
│   └── #examdyslexia-gear         nur bei allow_customize
└── #examdyslexia-panel            nur bei allow_customize, display per state.open
    ├── .examdyslexia-row × 6      Schrift (Auswahlfeld), Hintergrund (Farbfelder),
    │                              Zeilen-, Zeichen-, Wortabstand (− Wert +),
    │                              Leseschiene (Ein/Aus-Schalter)
    └── .examdyslexia-reset        zurück auf die Dozentenwerte
```

Die Leiste hängt direkt unter `<html>` (nicht unter `<body>`), damit sie vom
`transform` unberührt bleibt, mit dem exammagnifier den `<body>` zoomt — dieselbe
Regel wie dort. Das Panel steht im DOM **nach** den Buttons;
`flex-direction: column-reverse` dreht nur die Darstellung um, sodass es am
unteren Bildschirmrand nach oben aufklappt statt aus dem Viewport zu laufen.

Sind beide Optionen aus, wird gar keine Leiste gebaut und der Modus läuft
erzwungen im Hintergrund.

## Installation / Update

Standard: ZIP über Administration → Plugins → ExtendedTestSettings → Module
hochladen, aktivieren (`install.php` legt `examdyslexia_settings` an bzw. ergänzt
fehlende Spalten), dann pro Test unter „Erweiterte Test-Einstellungen"
konfigurieren.

## Changelog

| Datum | Was |
|---|---|
| 2026-07 | **v1.2.3:** Leseschiene über einen Schalter im Panel ein-/ausschaltbar, neue Spalte `ruler` als Dozenten-Startwert (Default 1 = Verhalten wie bisher); Zuordnungsfragen: Klick auf einen Begriff hebt ihn und seine Zuordnungen hervor |
| 2026-07 | **v1.2.2:** Atkinson Hyperlegible als zweite Schrift; Schriftauswahl über eine Registry statt fester OpenDyslexic-Checkbox (Migration aus `use_font`); Wortabstand als weitere Stufe; `@font-face` wandert aus dem CSS ins JS, damit die Pfade absolut aufgelöst werden |
| 2026-07 | **v1.2.1:** Video- und Audioplayer (MediaElement.js) von der Hintergrund-Einfärbung ausgenommen, Schrift/Zeichenabstand im Player-Teilbaum zurückgesetzt — die Steuerelemente waren auf der Pastellfarbe kaum noch erkennbar |
| 2026-07 | **v1.2.0:** Einstellungs-Panel für Studierende (Hintergrund, Zeilen-, Zeichenabstand), Dozenten-Option `allow_customize`, JS-Texte über lang-Dateien, Zustand als JSON im sessionStorage; Leiste von oben rechts (lag auf ILIAS' „Weiter") nach unten mittig verlegt und unter `<html>` gehängt; Styles wirken jetzt auch im TinyMCE-iframe der Freitextfragen |
| 2026-06 | **v1.1.0:** Modul-i18n DE/EN (ilModuleLanguage), sichtbare Strings über lang-Dateien |
| 2026 | **v1.0.1:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026 | **v1.0.0:** Erstveröffentlichung (OpenDyslexic, Abstände, Pastell-Hintergrund, Toggle) |
