# ExamFullscreen — Entwicklerdokumentation

**Modul-ID:** `examfullscreen`  
**Speicherort:** `modules/examfullscreen/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examfullscreen/ExamFullscreen_v10.0.1.zip`
**Zuletzt aktualisiert:** 2026-07-07

---

## ILIAS-10-Port (Version 10.0.0, 2026-07-07)

Start der ILIAS-10-Linie (Branch `ilias10`; Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1).
module.json: `version` 10.0.0, `ilias_min`/`ilias_max` = 10.

- Keine Code-Änderungen nötig. Web-Asset-Pfade (addJavaScript/addCss) funktionieren über den zentralen Registry-Compat des Hauptplugins (ab 10.0.0: Handler erhalten den web-relativen Pfad via `getRelativeDirectory()`).

---

## Was ist ExamFullscreen?

Fordert Prüfungsteilnehmer beim Teststart auf, den **Browser-Vollbild-Modus** (F11
bzw. Ctrl+Cmd+F auf Mac) zu aktivieren. Überwacht während der gesamten Prüfung, ob
der Vollbild-Modus verlassen wird, und reagiert je nach Konfiguration mit einer
**Warnung** oder einer **Sperrung** des Tests.

**Wichtig (v2.0.0):** Das Modul nutzt bewusst den **Browser-Fullscreen (F11)** statt
der JavaScript Fullscreen-API (`requestFullscreen()`). Grund: Die Fullscreen-API wird
vom Browser bei jeder Seitennavigation beendet, was beim Fragenwechsel einen Flicker
verursacht hat. Browser-Fullscreen (F11) überlebt Navigationen und verhält sich so,
wie der Nutzer es erwartet.

### Erkennung

Da F11 nicht programmatisch ausgelöst werden kann, erkennt das Modul den
Vollbild-Zustand über den Vergleich von Fenstergröße und Bildschirmgröße:

```javascript
window.innerHeight >= screen.height - 5
&& window.innerWidth >= screen.width - 5
```

Toleranz von 5px für OS-Unterschiede (Taskbar-Pixel, Display-Scaling).

---

## Einschränkungen

- Nur im Testplayer aktiv — `isTestPlayerContext()` prüft `cmdClass` und URL-Pattern.
- Erkennung basiert auf Fenstergröße vs. Bildschirmgröße — funktioniert zuverlässig
  für F11 und maximierte Fenster auf Vollbildschirmen.
- Session-Key ist an die `ref_id` des Tests gebunden — verhindert Bluten zwischen Tests.
- Außerhalb des Test-Players wird der Session-Key aktiv aufgeräumt.

---

## Dateistruktur

```
modules/examfullscreen/
├── module.json                         ← Modul-Metadaten, require_files: []
├── class.ilExamFullscreenHandler.php   ← Handler (implements ilExamModuleInterface)
├── css/
│   └── examfullscreen.css              ← Styles für Start- und Warn-Overlay
├── js/
│   └── examfullscreen.js              ← Vollbild-Erkennung + Overlay-Logik
└── sql/
    ├── install.php                     ← Legt examfullscr_settings an
    └── uninstall.php                   ← Löscht examfullscr_settings
```

---

## module.json

```json
{
    "id": "examfullscreen",
    "label": "Vollbild-Modus",
    "description": "Fordert Browser-Vollbild (F11) beim Teststart an. Erkennt Vollbild-Verlassen und warnt oder sperrt den Test bis der Vollbild-Modus wiederhergestellt wird.",
    "version": "2.0.1",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamFullscreenHandler",
    "handler_file": "class.ilExamFullscreenHandler.php",
    "require_files": [],
    "tables": [
        "examfullscr_settings"
    ]
}
```

Kein `gui_file`/`gui_class` — ExamFullscreen hat keine eigene Verwaltungs-GUI.
`require_files` ist leer — keine Abhängigkeiten außer dem Handler selbst.

---

## Datenbank

### `examfullscr_settings`

Speichert Fullscreen-Konfiguration je Test. Primärschlüssel ist `test_id` — ein
Eintrag pro Test.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 1 = aktiv, 0 = inaktiv (Default: 0) |
| `mode` | text(10) | `'warn'` oder `'force'` (Default: `'warn'`) |

Schreiben via `$db->replace()` mit `test_id` als Unique-Key — upsert-Semantik.

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| *(nur via `ilObject::_lookupObjectId()`)* | `ref_id` → `obj_id` Auflösung |

---

## ilExamFullscreenHandler

Implementiert `ilExamModuleInterface`.

### Interface-Methoden

```
isEnabled(ref_id)         → liest examfullscr_settings.enabled
getConfig(ref_id)         → {enabled, mode} aus DB, Defaults: {false, 'warn'}
injectAssets(tpl, ref_id) → wenn enabled:
                            - Test-Player: CSS + JS + Config (inkl. ref_id) injizieren
                            - Nicht Test-Player: sessionStorage-Key aufräumen
saveSettings(ref_id, post)→ enabled + mode aus POST, db->replace()
getFormItems(ref_id)       → [ilCheckboxInputGUI(examfullscreen_enabled)]
                            Sub-[ilSelectInputGUI(examfullscreen_mode: warn|force)]
getInfo()                  → title, icon, description, version, features-Array
```

### Nicht-Interface-Methoden

```
isTestPlayerContext()      → prüft cmdClass (iltestplayer*, iltestoutputgui)
                            + URL-Pattern (cmd=startPlayer, cmd=resumePlayer)
```

### Session-Key-Management

Der Session-Key enthält die `ref_id` des Tests (`examfullscreen_{ref_id}`), damit
verschiedene Tests sich nicht gegenseitig beeinflussen.

**Im Test-Player:** Key wird gesetzt sobald F11 erkannt wird.  
**Außerhalb:** `injectAssets()` injiziert `sessionStorage.removeItem(...)` um den
Key aufzuräumen, damit ein neuer Teststart immer das Start-Overlay zeigt.

### Asset-Injection im Detail

```php
// Im Test-Player:
$config = $this->getConfig($ref_id);
$config['ref_id'] = $ref_id;

$tpl->addCss(".../modules/examfullscreen/css/examfullscreen.css");
$tpl->addOnLoadCode("
    window.ExamFullscreen = window.ExamFullscreen || {};
    window.ExamFullscreen.config = $config_json;
");
$tpl->addJavaScript(".../modules/examfullscreen/js/examfullscreen.js");

// Außerhalb Test-Player (Cleanup):
$tpl->addOnLoadCode("sessionStorage.removeItem('examfullscreen_{$ref_id}');");
```

---

## JavaScript-Logik (examfullscreen.js)

### Ablauf beim Teststart (erster Aufruf)

```
1. init() → sessionStorage hat keinen Key
2. showStartOverlay() → "Drücken Sie F11" (oder Ctrl+Cmd+F auf Mac)
3. Nutzer drückt F11 → resize-Event → isFullscreen() === true
4. sessionStorage.setItem(key, '1') + hideStartOverlay()
5. Test läuft im Vollbild
```

### Ablauf beim Fragenwechsel

```
1. Seite lädt neu → F11 bleibt aktiv (Browser-Fullscreen überlebt Navigation)
2. init() → isFullscreen() === true → sessionStorage.setItem(key, '1')
3. Kein Overlay, kein Flicker — alles bleibt im Vollbild
```

### Ablauf bei ESC (Vollbild verlassen)

```
1. Nutzer drückt ESC → Fenster verlässt Vollbild → resize-Event
2. isFullscreen() === false + sessionStorage hat Key → showOverlay()
3. Warn-Overlay: "Vollbild-Modus verlassen, drücken Sie F11"
4. Modus 'force': Test blockiert bis Vollbild wiederhergestellt
5. Modus 'warn': Dismiss-Button verfügbar
6. Nutzer drückt F11 → resize-Event → hideOverlay()
```

### OS-Erkennung

```javascript
getShortcutInfo() → prüft navigator.platform
  Mac → {key: 'Ctrl + Cmd + F', icon: '⌃⌘F'}
  Sonst → {key: 'F11', icon: 'F11'}
```

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen erscheint die Checkbox "Vollbild-Modus aktivieren"
   mit Modus-Auswahl (Warnen/Sperren)

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` läuft erneut
(durch Guard ohne Effekt wenn Tabelle bereits existiert).

---

## Changelog

| Datum | Was |
|---|---|
| 2026-05-12 | **v2.0.1:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026-04-10 | v1.0.0: Initiale Version mit requestFullscreen() API |
| 2026-04-10 | v1.0.1: Silent-Restore bei Fragenwechsel via User-Gesture |
| 2026-04-10 | v1.0.2–v1.0.4: Bugfixes Flicker, Overlay-Timing |
| 2026-04-13 | **v2.0.0:** Kompletter Umbau auf Browser-Fullscreen (F11) statt Fullscreen-API. Kein Flicker beim Fragenwechsel. OS-Erkennung für Tastenkombination. Session-Key pro Test (ref_id). Resize-basierte Erkennung. |
