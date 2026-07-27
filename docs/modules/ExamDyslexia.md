# ExamDyslexia — Entwicklerdokumentation

**Modul-ID:** `examdyslexia`
**Referenz-ZIP:** `modules-zip/modules/examdyslexia/ExamDyslexia_v1.2.0.zip`
**Zuletzt aktualisiert:** 2026-07-27

---

## Was ist ExamDyslexia?

Legasthenie-freundlicher Lesemodus im Testplayer: OpenDyslexic-Schrift (lokal
mitgeliefert, kein CDN), konfigurierbarer Zeilen- und Zeichenabstand, optionaler
Pastell-Hintergrund und eine Leseschiene, die der Maus folgt. Standardmäßig
schaltet der Studierende den Modus selbst über einen Toggle-Button ein (oben
rechts, bewusst kein Konflikt mit dem examcalc-Button unten rechts und der
exammagnifier-Leiste unten links); alternativ kann der Modus per Test erzwungen
werden (`allow_toggle` aus → immer aktiv, kein Button).

Seit 1.2.0 sind die Dozentenwerte für Hintergrundfarbe, Zeilen- und
Zeichenabstand nur noch **Startwerte**: Über ein Zahnrad neben dem Schalter
öffnet der Studierende ein Panel und verstellt sie selbst (`allow_customize`,
Default an). Der Dozent kann das pro Test abschalten — dann gelten seine Werte
unveränderlich wie bis 1.1.0.

## Dateistruktur

```
modules/examdyslexia/
├── module.json                          kein gui_file — reines Player-Modul
├── class.ilExamDyslexiaHandler.php      Handler (Settings, Asset-Injection, Formular)
├── js/examdyslexia.js                   Leiste, Panel, Leseschiene, sessionStorage
├── css/examdyslexia.css                 Klassen-basierte Styles + Leiste/Panel/Schiene
├── fonts/OpenDyslexic-{Regular,Bold}.{woff2,woff}
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
| `use_font` | integer(1) | 1 | OpenDyslexic-Schrift verwenden |
| `line_height` | text(10) | `1.8` | Whitelist: 1.5 / 1.8 / 2.0 / 2.5 |
| `letter_spacing` | text(10) | `0.05em` | Whitelist: 0 / 0.05em / 0.12em / 0.2em |
| `bg_color` | text(20) | `none` | Whitelist: none / #FEFAE0 (Creme) / #FFFDE7 (Gelb) / #E3F2FD (Blau) |
| `updated_at` | timestamp | null | letzte Änderung |

Schreiben per `$db->replace()` (Upsert); alle Auswahlwerte werden im Handler
gegen `ALLOWED_*`-Whitelists validiert.

`allow_customize` kam mit 1.2.0 dazu — `install.php` legt sie im `else`-Zweig
per `tableColumnExists()`-Guard nach. Das Update muss deshalb über den
ZIP-Upload laufen, sonst fehlt die Spalte. `getConfig()` liest bewusst
`SELECT *` und fällt für `allow_customize` auf `1` zurück, damit das Modul
zwischen Datei- und Schema-Stand nicht bricht.

## Handler (ilExamDyslexiaHandler)

```
isEnabled(ref_id)          → examdyslexia_settings.enabled (defensiv, false bei Fehler)
injectAssets(tpl, ref_id)  → nur im Testplayer-Kontext (isTestPlayerContext()):
                             CSS+JS mit filemtime-Cache-Busting, Config via addOnLoadCode
                             in window.ExamDyslexia.config — inkl. font_path für den
                             @font-face-Fallback, der Stufen-Whitelisten (bg_colors,
                             line_heights, letter_spacings) und aller sichtbaren
                             JS-Texte im labels-Block
saveSettings(ref_id, post) → Whitelist-Validierung, replace()
getFormItems(ref_id)       → Haupt-Checkbox mit SubItems: allow_toggle, allow_customize,
                             use_font, bg_color, line_height, letter_spacing
```

`isTestPlayerContext()` ist der übliche bewusst duplizierte Helper (cmdClass-
Prüfung + REQUEST_URI-Fallback) — nicht in Plugin-classes extrahieren
(Self-contained-Regel).

Cache-Busting nutzt `@filemtime($css_file)` auf den **web-relativen** Pfad: bei
Web-Requests ist das CWD der Webroot (9 wie 10), während `ILIAS_ABSOLUTE_PATH`
in ILIAS 10 wegen `public/` ins Leere zeigen würde.

## JavaScript

- Config **live** über `cfg()` lesen (addOnLoadCode läuft nach addJavaScript —
  nie beim IIFE-Init cachen). Sichtbare Texte über `txt(key)` aus `cfg().labels`.
- Zustand pro Teilnahme als JSON in `sessionStorage`
  (`examdyslexia_state_<active_id>`, Fallback `ref_id`): `{on, bg, lh, ls, open}`
  — überlebt die Fragennavigation.
- Stufenwechsel laufen über die Indizes der Config-Listen; jeder geladene Wert
  wird mit `pick()` gegen dieselbe Liste geprüft. Bei `allow_customize = 0`
  werden gespeicherte Stufen verworfen und die Dozentenwerte gesetzt.
- Panel-Änderungen schalten den Lesemodus mit ein (`changed()`) — sonst bliebe
  die Einstellung ohne sichtbare Wirkung.
- `@font-face`-Fallback wird per JS injiziert (`font_path` aus der Config), falls
  das CSS die Fonts wegen relativer Pfade nicht findet.
- Anwendung der Styles ausschließlich über CSS Custom Properties auf
  `documentElement` plus die Klasse `examdyslexia-active` auf `<body>`.
- Leseschiene (`#examdyslexia-ruler`): folgt dem Cursor innerhalb `#tst_output`,
  `pointer-events: none`, Höhe aus dem aktuellen Zeilenabstand; das Rect des
  Zielelements wird gecacht und nur bei `resize` verworfen.

### DOM der Leiste

```
#examdyslexia-bar                  fixed, top 80 / right 16, z-index 9000
├── .examdyslexia-buttons
│   ├── #examdyslexia-toggle       nur bei allow_toggle
│   └── #examdyslexia-gear         nur bei allow_customize
└── #examdyslexia-panel            nur bei allow_customize, display per state.open
    ├── .examdyslexia-row × 3      Hintergrund (Farbfelder) / Zeilen- / Zeichenabstand (− Wert +)
    └── .examdyslexia-reset        zurück auf die Dozentenwerte
```

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
| 2026-07 | **v1.2.0:** Einstellungs-Panel für Studierende (Hintergrund, Zeilen-, Zeichenabstand), Dozenten-Option `allow_customize`, JS-Texte über lang-Dateien, Zustand als JSON im sessionStorage |
| 2026-06 | **v1.1.0:** Modul-i18n DE/EN (ilModuleLanguage), sichtbare Strings über lang-Dateien |
| 2026 | **v1.0.1:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026 | **v1.0.0:** Erstveröffentlichung (OpenDyslexic, Abstände, Pastell-Hintergrund, Toggle) |
