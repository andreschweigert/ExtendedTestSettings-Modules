# ExamKeymap — Entwicklerdokumentation

**Modul-ID:** `examkeymap`  
**Speicherort:** `modules/examkeymap/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examkeymap/ExamKeymap_v1.0.0.zip`  
**Zuletzt aktualisiert:** 2026-06-10

---

## Was ist ExamKeymap?

Virtuelles Tastatur-Layout (DE/EN) für den Testplayer. Zielszenario: Prüfungen auf
**ChromeOS-Geräten im Kioskmodus** — dort wird die Eingabesprache nicht angezeigt
und kann von Studierenden nicht umgestellt werden. Das OS-Layout passt deshalb oft
nicht zur Erwartung (y/z vertauscht, Umlaute/Sonderzeichen falsch).

ExamKeymap löst das **clientseitig**: Ein fixierter Button unten rechts rotiert
durch **OS → DE → EN → OS**. Ist DE oder EN aktiv, fängt das Skript `keydown` ab
und schreibt das Zeichen um — Basis ist `KeyboardEvent.code` (die **physische
Taste**, unabhängig vom OS-Layout; das ist der Kern des Ansatzes) plus
Modifier-Status (Shift, AltGr/AltRight, CapsLock).

### Kern-Mechanik

```
keydown (Capture-Phase)
  → Layout 'os'? → durchlassen
  → Ctrl/Meta (ohne AltGr)? Alt ohne AltGr? → durchlassen
  → e.code nicht in Mapping-Tabelle? → durchlassen (F-Tasten, Pfeile, Backspace, …)
  → Ziel kein input[type=text]/textarea/contenteditable? → durchlassen
  → sonst: preventDefault() + Zeichen manuell einfügen + input-Event dispatchen
```

Das `input`-Event (mit `inputType: 'insertText'`) sorgt dafür, dass ILIAS-Autosave
und Zeichenzähler die Eingabe mitbekommen.

---

## Einschränkungen

- **Dead Keys** (`^` `´` `` ` ``): vereinfachte Behandlung — Literal-Zeichen wird
  direkt eingefügt, **kein Kombinieren** mit dem Folgezeichen (kein `^`+`a` → `â`).
- Numpad wird nicht angefasst (Ziffern layoutunabhängig; `NumpadDecimal` nicht angepasst).
- Cross-Origin-iframes nicht erreichbar (TinyMCE ist same-origin → kein Problem).
- Nur im Testplayer aktiv — `isTestPlayerContext()` prüft `cmdClass` und URL-Pattern.

---

## Dateistruktur

```
modules/examkeymap/
├── module.json                       ← Modul-Metadaten, require_files: []
├── class.ilExamKeymapHandler.php     ← Handler (implements ilExamModuleInterface)
├── css/
│   └── examkeymap.css                ← Styles für den Umschalt-Button
├── js/
│   └── examkeymap.js                 ← Mapping-Tabellen, Interception, Button, iframe-Hooks
├── lang/
│   ├── ilias_de.lang
│   └── ilias_en.lang
└── sql/
    ├── install.php                   ← Legt examkeymap_settings an
    └── uninstall.php                 ← Löscht examkeymap_settings
```

Kein `gui_file`/`gui_class`, kein AJAX, kein `allow_student_access` —
reines Handler+JS-Modul ohne eigene GUI.

---

## Datenbank

### `examkeymap_settings`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 1 = aktiv, 0 = inaktiv (Default: 0) |
| `default_layout` | text(8) | `'off'`, `'de'`, `'en'` (Default: `'off'`) |
| `created_at` | timestamp | erste Speicherung (wird bei Updates erhalten) |
| `updated_at` | timestamp | letzte Speicherung |

Schreiben via `$db->replace()` mit `test_id` als Unique-Key — upsert-Semantik.
`created_at` wird vor dem `replace()` aus der bestehenden Zeile gelesen und
wieder mitgeschrieben (replace überschreibt alle Spalten).

---

## ilExamKeymapHandler

Implementiert `ilExamModuleInterface`.

### Interface-Methoden

```
isEnabled(ref_id)          → liest examkeymap_settings.enabled (defensiv, try/catch)
injectAssets(tpl, ref_id)  → wenn enabled UND Testplayer-Kontext:
                             CSS + Config (addOnLoadCode) + JS injizieren
saveSettings(ref_id, post) → enabled + default_layout aus POST, db->replace()
getFormItems(ref_id)       → [ilCheckboxInputGUI(examkeymap_enabled)]
                             Sub-[ilSelectInputGUI(examkeymap_default: off|de|en)]
```

### Nicht-Interface-Methoden

```
getConfig(ref_id)          → {enabled, default_layout} aus DB, Defaults: {false, 'off'}
isTestPlayerContext()      → prüft cmdClass (iltestplayer*, iltestoutputgui)
                             + URL-Pattern (cmd=startPlayer, cmd=resumePlayer)
getInfo()                  → title, icon, description, version, features-Array
```

### Config-Übergabe ans JS

```php
$config = $this->getConfig($ref_id);
$config['ref_id'] = $ref_id;
$config['txt']    = ['tooltip' => …, 'os' => …, 'de' => …, 'en' => …]; // i18n für den Button

$tpl->addCss(".../modules/examkeymap/css/examkeymap.css");
$tpl->addOnLoadCode("window.ExamKeymap = window.ExamKeymap || {}; window.ExamKeymap.config = {$config_json};");
$tpl->addJavaScript(".../modules/examkeymap/js/examkeymap.js");
```

**Wichtig:** `addJavaScript` lädt das Script **vor** `addOnLoadCode` — das JS liest
die Config deshalb **live** über `cfg()` und cached sie nie beim IIFE-Init.

---

## JavaScript-Logik (examkeymap.js)

### Mapping-Tabellen

Zwei Tabellen (`DE`, `EN`), Schlüssel ist `KeyboardEvent.code` (physische Taste in
US-QWERTY-Bezeichnung). Eintrag: `{b: Basis, s: Shift, g: AltGr}`.

Besonderheiten DE (ISO QWERTZ):

| Physische Taste (code) | Basis | Shift | AltGr |
|---|---|---|---|
| `KeyY` / `KeyZ` | z / y | Z / Y | — (y/z-Tausch) |
| `Digit2` / `Digit3` | 2 / 3 | " / § | ² / ³ |
| `Digit7`–`Digit0` | 7890 | / ( ) = | { [ ] } |
| `Minus` | ß | ? | \ |
| `Equal` | ´ (literal) | ` (literal) | — |
| `BracketLeft` | ü | Ü | — |
| `BracketRight` | + | * | ~ |
| `Semicolon` / `Quote` | ö / ä | Ö / Ä | — |
| `Backslash` | # | ' | — |
| `Backquote` | ^ (literal) | ° | — |
| `Comma` / `Period` / `Slash` | , . - | ; : _ | — |
| `IntlBackslash` | < | > | \| |
| `KeyQ` / `KeyE` / `KeyM` | q e m | Q E M | @ € µ |

EN-US (QWERTY) ist die Standardbelegung ohne AltGr-Ebene.

### Modifier-Behandlung

- **AltGr-Erkennung dreistufig:** manuell verfolgter `AltRight`-Status (keydown/keyup,
  Reset bei window-blur) ODER `getModifierState('AltGraph')` ODER `ctrlKey && altKey`.
  Der manuelle AltRight-Track ist nötig, weil auf einem EN-OS-Layout die rechte
  Alt-Taste kein `AltGraph` meldet.
- **CapsLock** invertiert Shift nur bei Buchstaben (inkl. Umlauten), erkannt über
  `toLowerCase() !== toUpperCase()`.
- AltGr gedrückt, aber kein `g`-Eintrag → Pass-through (nicht schlucken).

### Einfügen an der Cursor-Position

- `input`/`textarea`: `el.setRangeText(ch, start, end, 'end')` — ersetzt die
  Selection und zieht den Cursor nach.
- contenteditable (TinyMCE): `Selection`/`Range`-API des **iframe-Dokuments**
  (`range.deleteContents()` + TextNode einfügen + Cursor dahinter setzen).
- Danach jeweils `dispatchEvent(new InputEvent('input', {inputType: 'insertText', …}))`.

### TinyMCE / iframe-Hooks

TinyMCE initialisiert nach Page-Load und kann seine iframes neu erzeugen. Deshalb:

- Selbst-reschedulendes `setTimeout`-Tick (1 s) scannt `document.querySelectorAll('iframe')`.
- Pro `contentDocument` wird ein Flag (`__examkeymapHooked`) gesetzt; erzeugt TinyMCE
  das Dokument neu, fehlt das Flag → automatische Nachregistrierung.
- Listener in der **Capture-Phase**, damit sie vor TinyMCE-eigenen Handlern laufen.
- Cross-Origin-iframes werfen beim `contentDocument`-Zugriff → try/catch, ignorieren.

### Session-Persistenz

`sessionStorage`-Key `examkeymap_<ref_id>` speichert `'os' | 'de' | 'en'`.
**Nie pro Pageload neu würfeln** — ILIAS lädt bei jeder Fragennavigation die Seite
komplett neu; der Key sorgt dafür, dass die Wahl erhalten bleibt. Ohne gespeicherten
Wert gilt `default_layout` aus der Test-Konfiguration (`off` → Zustand `os`).

### Button

Wird komplett im JS erzeugt (`#examkeymap-btn`), Styles ausschließlich im CSS.
Fixiert unten rechts, `z-index: 999990` (über dem Testplayer, unter den
Overlays anderer Module wie ExamFullscreen mit 999999). Beschriftung
`⌨ OS|DE|EN` + Tooltip kommen i18n-übersetzt aus `config.txt`.

---

## Testplan (Abnahmekriterien)

1. Test mit aktiviertem Modul, Freitextfrage (TinyMCE) und Lückentext (input):
   Button erscheint **nur im Testplayer**, nicht in anderen ILIAS-Ansichten.
2. Layout DE, OS-Layout EN: physisches „Z" (unten links) ergibt **y**,
   AltGr+Q ergibt **@**, Shift+7 ergibt **/**, Umlaut-Tasten ergeben **ä ö ü**.
3. Layout EN: y/z wie QWERTY, Shift+2 ergibt **@**, Semicolon-Taste ergibt **;**.
4. Zustand „OS": keinerlei Eingriff (Referenzverhalten).
5. Ctrl+C/Ctrl+V/Backspace/Pfeiltasten funktionieren in allen Modi unverändert.
6. Nach Fragennavigation (komplette Seitenneuladung) bleibt die Layout-Wahl erhalten.
7. ILIAS-Autosave speichert die umgeschriebenen Zeichen (input-Event kommt an).

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen erscheint die Checkbox
   „Virtuelles Tastatur-Layout aktivieren" mit Standard-Layout-Auswahl (Aus/DE/EN)

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` läuft erneut
(durch Guard ohne Effekt wenn Tabelle bereits existiert).

---

## Changelog

| Datum | Was |
|---|---|
| 2026-06-10 | **v1.0.0:** Initiale Version. Layouts DE (ISO QWERTZ) + EN-US (QWERTY), Drei-Zustände-Button (OS → DE → EN), KeyboardEvent.code-basierte Interception mit Shift/AltGr/CapsLock, TinyMCE-Support via iframe-Hooks, sessionStorage-Persistenz pro Test, Dead Keys als Literal. |
