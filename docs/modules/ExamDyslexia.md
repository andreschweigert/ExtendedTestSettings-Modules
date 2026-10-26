# ExamDyslexia — Entwicklerdokumentation

**Modul-ID:** `examdyslexia`
**Referenz-ZIP:** `modules-zip/modules/examdyslexia/ExamDyslexia_v10.0.1.zip`
**Zuletzt aktualisiert:** 2026-07-07

---

## ILIAS-10-Port (Version 10.0.0, 2026-07-07)

Start der ILIAS-10-Linie (Branch `ilias10`; Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1).
module.json: `version` 10.0.0, `ilias_min`/`ilias_max` = 10.

- Cache-Busting: `filemtime()` nutzt direkt den web-relativen Pfad (CWD bei Web-Requests = Webroot, in 9 wie 10) — `ILIAS_ABSOLUTE_PATH . '/' . $webrel` zeigt in ILIAS 10 am Webroot (`public/`) vorbei, der Fallback degradierte still zu `time()`.

---

## Was ist ExamDyslexia?

Legasthenie-freundlicher Lesemodus im Testplayer: OpenDyslexic-Schrift (lokal
mitgeliefert, kein CDN), konfigurierbarer Zeilen- und Buchstabenabstand sowie
optionaler Pastell-Hintergrund. Standardmäßig schaltet der Studierende den Modus
selbst über einen Toggle-Button ein (oben rechts, bewusst kein Konflikt mit dem
examcalc-Button); alternativ kann der Modus per Test erzwungen werden
(`allow_toggle` aus → immer aktiv, kein Button).

## Dateistruktur

```
modules/examdyslexia/
├── module.json                          kein gui_file — reines Player-Modul
├── class.ilExamDyslexiaHandler.php      Handler (Settings, Asset-Injection, Formular)
├── js/examdyslexia.js                   Toggle, Styles, sessionStorage, TinyMCE-Behandlung
├── css/examdyslexia.css                 Klassen-basierte Styles + Button
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
| `use_font` | integer(1) | 1 | OpenDyslexic-Schrift verwenden |
| `line_height` | text(10) | `1.8` | Whitelist: 1.5 / 1.8 / 2.0 / 2.5 |
| `letter_spacing` | text(10) | `0.05em` | Whitelist: 0 / 0.05em / 0.12em / 0.2em |
| `bg_color` | text(20) | `none` | Whitelist: none / #FEFAE0 (Creme) / #FFFDE7 (Gelb) / #E3F2FD (Blau) |
| `updated_at` | timestamp | null | letzte Änderung |

Schreiben per `$db->replace()` (Upsert); alle Auswahlwerte werden im Handler
gegen `ALLOWED_*`-Whitelists validiert.

## Handler (ilExamDyslexiaHandler)

```
isEnabled(ref_id)          → examdyslexia_settings.enabled (defensiv, false bei Fehler)
injectAssets(tpl, ref_id)  → nur im Testplayer-Kontext (isTestPlayerContext()):
                             CSS+JS mit filemtime-Cache-Busting, Config via addOnLoadCode
                             in window.ExamDyslexia.config (inkl. font_path für den
                             @font-face-Fallback im JS)
saveSettings(ref_id, post) → Whitelist-Validierung, replace()
getFormItems(ref_id)       → Haupt-Checkbox mit SubItems: allow_toggle, use_font,
                             bg_color, line_height, letter_spacing
```

`isTestPlayerContext()` ist der übliche bewusst duplizierte Helper (cmdClass-
Prüfung + REQUEST_URI-Fallback) — nicht in Plugin-classes extrahieren
(Self-contained-Regel).

## JavaScript

- Config **live** über `window.ExamDyslexia.config` lesen (addOnLoadCode läuft nach
  addJavaScript — nie beim IIFE-Init cachen).
- Aktivierungszustand pro Teilnahme in `sessionStorage`
  (`examdyslexia_active_<active_id>`) — überlebt die Fragennavigation.
- `@font-face`-Fallback wird per JS injiziert (`font_path` aus der Config), falls
  das CSS die Fonts wegen relativer Pfade nicht findet.
- Anwendung der Styles über eine Klasse auf `<body>`; TinyMCE-iframes (Freitext)
  werden gesondert nachbehandelt.
- Bei `allow_toggle=0` wird kein Button gerendert und der Modus direkt aktiviert.

## Installation / Update

Standard: ZIP über Administration → Plugins → ExtendedTestSettings → Module
hochladen, aktivieren (`install.php` legt `examdyslexia_settings` an), dann pro
Test unter „Erweiterte Test-Einstellungen" konfigurieren.

## Changelog

| Datum | Was |
|---|---|
| 2026-06 | **v1.1.0:** Modul-i18n DE/EN (ilModuleLanguage), sichtbare Strings über lang-Dateien |
| 2026 | **v1.0.1:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026 | **v1.0.0:** Erstveröffentlichung (OpenDyslexic, Abstände, Pastell-Hintergrund, Toggle) |
