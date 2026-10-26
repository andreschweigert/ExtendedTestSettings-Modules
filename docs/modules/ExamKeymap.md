# ExamKeymap — Entwicklerdokumentation

**Modul-ID:** `examkeymap`  
**Speicherort:** `modules/examkeymap/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examkeymap/ExamKeymap_v1.1.3.zip`
**Zuletzt aktualisiert:** 2026-10-23

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

Seit **1.1.0** zusätzlich:

- **Lupe-/Suchtaste als virtuelle Feststelltaste** (pro Test, `search_caps`):
  Die Chromebook-Lupe sitzt auf der CapsLock-Position und meldet `code "MetaLeft"`.
  Ein **Tap** (keydown→keyup ohne andere Taste dazwischen) toggelt den virtuellen
  CapsLock-Zustand; Kombis (Lupe+L etc.) toggeln nicht. Zustand in
  `sessionStorage` (`examkeymap_caps_<ref_id>`), Anzeige als ⇪ am Button.
  In `resolveChar()` wird die Shift-Inversion per **XOR** aus echter CapsLock
  (`getModifierState`) und virtueller Feststellung gebildet.
- **Globale Übersetzungstabelle** (`examkeymap_custom`): Overrides der eingebauten
  Layout-Tabellen, gepflegt über einen virtuellen Keyboard-Editor in der
  Plugin-Administration (Tab „Tastatur-Layout", eingebunden über die ConfigGUI
  des Haupt-Plugins). Die Overrides werden vom Handler in die Player-Config
  gelegt und im JS bei der Tastenauflösung bevorzugt
  (`custom[layout][code] || LAYOUTS[layout][code]`).

### Kern-Mechanik

```
keydown (Capture-Phase)
  → code 'AltRight'? → AltGr-Tracking, durchlassen
  → code 'MetaLeft'? → Tap-Tracking für virtuelle Feststelltaste, durchlassen
  → Layout 'os'? → durchlassen
  → Ctrl/Meta (ohne AltGr)? Alt ohne AltGr? → durchlassen
  → e.code nicht in (Override- + Mapping-)Tabelle? → durchlassen (F-Tasten, Pfeile, Backspace, …)
  → Ziel kein input[type=text]/textarea/contenteditable? → durchlassen
  → sonst: preventDefault() + Zeichen manuell einfügen + input-Event dispatchen
```

Das `input`-Event (mit `inputType: 'insertText'`) sorgt dafür, dass ILIAS-Autosave
und Zeichenzähler die Eingabe mitbekommen.

---

## Einschränkungen

- **Dead Keys** (`^` `´` `` ` ``): vereinfachte Behandlung — Literal-Zeichen wird
  direkt eingefügt, **kein Kombinieren** mit dem Folgezeichen (kein `^`+`a` → `â`).
- **Lupe-als-Feststelltaste:** Außerhalb des Kioskmodus öffnet die Lupe den
  ChromeOS-Launcher (nicht per `preventDefault()` unterdrückbar). Im Kioskmodus
  i. d. R. unkritisch — auf dem Zielgerät verifizieren. Auf Nicht-ChromeOS ist
  `MetaLeft` die Windows-Taste.
- Numpad wird nicht angefasst (Ziffern layoutunabhängig; `NumpadDecimal` nicht angepasst).
- Cross-Origin-iframes nicht erreichbar (TinyMCE ist same-origin → kein Problem).
- Nur im Testplayer aktiv — `isTestPlayerContext()` prüft `cmdClass` und URL-Pattern.

---

## Dateistruktur

```
modules/examkeymap/
├── module.json                       ← Modul-Metadaten, require_files: []
├── class.ilExamKeymapHandler.php     ← Handler (implements ilExamModuleInterface)
├── class.ilExamKeymapAdminGUI.php    ← Admin-Editor (eingebunden via ConfigGUI-Tab)
├── css/
│   ├── examkeymap.css                ← Styles für den Umschalt-Button (Player)
│   └── examkeymap_admin.css          ← Styles für den Keyboard-Editor (Admin)
├── js/
│   ├── examkeymap.js                 ← Tabellen, Interception, Button, iframe-Hooks
│   └── examkeymap_admin.js           ← virtueller Keyboard-Editor (Admin)
├── lang/
│   ├── ilias_de.lang
│   └── ilias_en.lang
└── sql/
    ├── install.php                   ← Legt examkeymap_settings + examkeymap_custom an
    └── uninstall.php                 ← Löscht beide Tabellen
```

Kein `gui_file`/`gui_class` in module.json, kein Proxy-Routing: Die Admin-GUI ist
**global** (nicht test-bezogen) und wird wie bei ExamNetQos/ExamLogger direkt von
`classes/class.ilExtendedTestSettingsConfigGUI.php` (Haupt-Repo) als Tab geladen.

---

## Datenbank

### `examkeymap_settings` (pro Test)

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 1 = aktiv, 0 = inaktiv (Default: 0) |
| `default_layout` | text(8) | `'off'`, `'de'`, `'en'` (Default: `'off'`) |
| `search_caps` | integer(1) | 1 = Lupe-Taste als Feststelltaste (Default: 0) |
| `created_at` | timestamp | erste Speicherung (wird bei Updates erhalten) |
| `updated_at` | timestamp | letzte Speicherung |

Schreiben via `$db->replace()` mit `test_id` als Unique-Key — upsert-Semantik.
`created_at` wird vor dem `replace()` aus der bestehenden Zeile gelesen und
wieder mitgeschrieben (replace überschreibt alle Spalten).

`install.php` rüstet `search_caps` bei Updates von 1.0.0 per
`tableColumnExists()`-Guard nach.

### `examkeymap_custom` (global, Übersetzungstabelle)

| Spalte | Typ | Beschreibung |
|---|---|---|
| `layout` | text(8) PK | `'de'` oder `'en'` |
| `keycode` | text(32) PK | physische Taste (`KeyboardEvent.code`) |
| `base_char` | text(8) | Basis-Ebene; leer = nicht belegt |
| `shift_char` | text(8) | Shift-Ebene; leer = nicht belegt |
| `altgr_char` | text(8) | AltGr-Ebene; leer = nicht belegt |

Ein Override **ersetzt den kompletten Eintrag** der Taste (alle drei Ebenen).
Alle Ebenen leer = Taste deaktiviert (Pass-through). Speichern =
Komplettabgleich (DELETE + INSERT aller Overrides).

---

## ilExamKeymapHandler

Implementiert `ilExamModuleInterface`.

### Interface-Methoden

```
isEnabled(ref_id)          → liest examkeymap_settings.enabled (defensiv, try/catch)
injectAssets(tpl, ref_id)  → wenn enabled UND Testplayer-Kontext:
                             CSS + Config (addOnLoadCode) + JS injizieren
saveSettings(ref_id, post) → enabled + default_layout + search_caps, db->replace()
getFormItems(ref_id)       → [ilCheckboxInputGUI(examkeymap_enabled)]
                             Sub-[ilSelectInputGUI(examkeymap_default: off|de|en)]
                             Sub-[ilCheckboxInputGUI(examkeymap_search_caps)]
```

### Nicht-Interface-Methoden

```
getConfig(ref_id)          → {enabled, default_layout, search_caps}, Defaults defensiv
getCustomMap()             → Overrides aus examkeymap_custom als
                             ['de'=>[code=>['b','s','g']], 'en'=>…]; Tabelle fehlt → leer
isTestPlayerContext()      → prüft cmdClass (iltestplayer*, iltestoutputgui)
                             + URL-Pattern (cmd=startPlayer, cmd=resumePlayer)
getInfo()                  → title, icon, description, version, features-Array
```

### Config-Übergabe ans JS

```php
$config = $this->getConfig($ref_id);
$config['ref_id'] = $ref_id;
$config['player'] = true;                  // schaltet die Player-Logik im JS frei
$config['custom'] = $this->getCustomMap(); // globale Übersetzungstabelle
$config['txt']    = ['tooltip' => …, 'os' => …, 'de' => …, 'en' => …];

$tpl->addCss(".../modules/examkeymap/css/examkeymap.css");
$tpl->addOnLoadCode("window.ExamKeymap = window.ExamKeymap || {}; window.ExamKeymap.config = {$config_json};");
$tpl->addJavaScript(".../modules/examkeymap/js/examkeymap.js");
```

**Wichtig:** `addJavaScript` lädt das Script **vor** `addOnLoadCode` — das JS liest
die Config deshalb **live** über `cfg()` und cached sie nie beim IIFE-Init.
Das `player`-Flag verhindert, dass die Interception/der Button auf der
Admin-Seite aktiv wird (dort wird examkeymap.js nur für die Tabellen geladen).

---

## ilExamKeymapAdminGUI (Admin-Editor)

Wird von der ConfigGUI als Tab „Tastatur-Layout" eingebunden (Muster
ExamNetQos/ExamLogger): `showKeymap` → `showEditor()`, `saveKeymap` → `handleSave()`
mit `[msg, type]`-Rückgabe.

- `showEditor()`: lädt Overrides, injiziert `window.ExamKeymapAdmin.config`
  (Overrides + i18n-Strings), lädt `examkeymap.js` (Tabellen) +
  `examkeymap_admin.js` + `examkeymap_admin.css`, rendert Container
  `#examkeymap-admin-root` + POST-Formular mit Hidden-Feld `keymap_overrides`.
- `handleSave()`: validiert das JSON serverseitig — nur Layouts `de`/`en`, nur
  Codes aus der `ALLOWED_CODES`-Whitelist (muss mit den JS-Tabellen
  übereinstimmen), Zeichen ohne Steuerzeichen, max. 4 Zeichen — und schreibt
  per Komplettabgleich (DELETE + INSERT).
- Asset-Pfade über `getModuleWebPath()` (Ableitung aus `__DIR__` relativ zu
  `ILIAS_ABSOLUTE_PATH`, Fallback: fester Plugin-Pfad).
- Form-Action über `setSaveUrl()` aus der Plugin-ConfigGUI (`getFormAction()`
  mit CSRF-Token); ohne diese URL wird der Editor nicht angezeigt.

### Keyboard-Editor (examkeymap_admin.js)

- Zeichnet ein ISO-Keyboard (5 Reihen) aus `window.ExamKeymap.tables` + Overrides;
  pro Taste drei Beschriftungen: Shift (oben links), Basis (unten links, fett),
  AltGr (unten rechts, blau). Inaktive Tasten (Tab, Enter, Shift, Lupe/⇪, …) grau.
- Gelb = Override, Rot = deaktivierte Taste, Auswahlrahmen = selektiert.
- Klick auf Taste → Editor-Panel (3 Inputs Basis/Shift/AltGr, „Übernehmen",
  bei Override zusätzlich „Auf Standard zurücksetzen"). Eingaben identisch zur
  eingebauten Belegung entfernen den Override automatisch.
- Layout-Umschalter DE/EN; Overrides beider Layouts werden gemeinsam als ein
  JSON (`{de: {…}, en: {…}}`) ins Hidden-Feld serialisiert und per normalem
  POST gespeichert.
- Init mit Retry (`setTimeout`), da `addOnLoadCode` nach `addJavaScript` läuft.

---

## JavaScript-Logik (examkeymap.js)

### Mapping-Tabellen

Zwei Tabellen (`DE`, `EN`), Schlüssel ist `KeyboardEvent.code` (physische Taste in
US-QWERTY-Bezeichnung). Eintrag: `{b: Basis, s: Shift, g: AltGr}`. Exponiert als
`window.ExamKeymap.tables` (für den Admin-Editor).

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
  `toLowerCase() !== toUpperCase()`. Effektive Feststellung = XOR aus echter
  CapsLock und virtueller Lupe-Feststellung.
- AltGr gedrückt, aber kein `g`-Eintrag (oder leer) → Pass-through (nicht schlucken).

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

`sessionStorage`-Key `examkeymap_<ref_id>` speichert `'os' | 'de' | 'en'`,
`examkeymap_caps_<ref_id>` die virtuelle Feststellung.
**Nie pro Pageload neu würfeln** — ILIAS lädt bei jeder Fragennavigation die Seite
komplett neu; die Keys sorgen dafür, dass die Wahl erhalten bleibt. Ohne gespeicherten
Wert gilt `default_layout` aus der Test-Konfiguration (`off` → Zustand `os`).

### Button

Wird komplett im JS erzeugt (`#examkeymap-btn`), Styles ausschließlich im CSS.
Fixiert unten rechts, `z-index: 999990` (über dem Testplayer, unter den
Overlays anderer Module wie ExamFullscreen mit 999999). Beschriftung
`⌨ OS|DE|EN` (+ ⇪ bei virtueller Feststellung) + Tooltip kommen i18n-übersetzt
aus `config.txt`. Wird nur bei `config.player` erzeugt.

---

## Änderungen im Haupt-Repo (ConfigGUI)

Für den Admin-Tab waren folgende Änderungen am Haupt-Plugin nötig
(eigener Commit im Haupt-Repo, Muster ExamNetQos):

- `classes/class.ilExtendedTestSettingsConfigGUI.php`:
  - `setTabs()`: Tab `keymap` („Tastatur-Layout"), nur wenn Modul global aktiv
  - `performCommand()`: Cases `showKeymap` / `saveKeymap`
  - Methoden `isExamKeymapInstalled()`, `showKeymap()`, `saveKeymap()` —
    laden `modules/examkeymap/class.ilExamKeymapAdminGUI.php` per `require_once`
- `lang/ilias_de.lang` / `lang/ilias_en.lang`: Keys `tab_keymap`,
  `module_examkeymap_missing`

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
8. **(1.1.0)** `search_caps` aktiv: Tap auf die Lupe toggelt ⇪ am Button,
   Buchstaben kommen groß; Lupe+andere-Taste toggelt **nicht**; nach
   Fragennavigation bleibt ⇪ erhalten.
9. **(1.1.0)** Admin-Tab: Override setzen (z. B. `KeyQ` AltGr → „#"), speichern,
   im Testplayer prüfen; „Auf Standard zurücksetzen" entfernt den Override;
   Taste mit drei leeren Ebenen wird durchgereicht.

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen erscheint die Checkbox
   „Virtuelles Tastatur-Layout aktivieren" mit Standard-Layout-Auswahl und
   Lupe-Option; in der Plugin-Administration der Tab „Tastatur-Layout"
   (setzt aktuellen Plugin-Kern voraus, siehe oben)

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` läuft erneut
(Guards: `tableExists()` / `tableColumnExists()`), Daten bleiben erhalten.

---

## Changelog

| Datum | Was |
|---|---|
| 2026-06-10 | **v1.1.0:** Lupe-/Suchtaste (MetaLeft) als virtuelle Feststelltaste mit Tap-Erkennung (pro Test, Spalte `search_caps`). Globale Übersetzungstabelle `examkeymap_custom` mit virtuellem Keyboard-Editor in der Administration (ConfigGUI-Tab „Tastatur-Layout"). Tabellen als `window.ExamKeymap.tables` exponiert, `player`-Flag in der Config. |
| 2026-06-10 | **v1.0.0:** Initiale Version. Layouts DE (ISO QWERTZ) + EN-US (QWERTY), Drei-Zustände-Button (OS → DE → EN), KeyboardEvent.code-basierte Interception mit Shift/AltGr/CapsLock, TinyMCE-Support via iframe-Hooks, sessionStorage-Persistenz pro Test, Dead Keys als Literal. |
