# ExamSymbols — Entwicklerdokumentation

**Modul-ID:** `examsymbols`  
**Speicherort:** (noch nicht installiert) — Quelle: `modules-zip/modules/examsymbols/ExamSymbols_v1.1.1.zip`  
**Referenz-ZIP:** `modules-zip/modules/examsymbols/ExamSymbols_v1.1.1.zip`  
**Zuletzt aktualisiert:** 2026-05-12

---

## Was ist ExamSymbols?

Stellt den Prüfungsteilnehmern während eines ILIAS-Tests eine **klickbare
Sonderzeichen-Palette** als schwebendes Overlay zur Verfügung. Die Palette fügt Zeichen
in das aktuell fokussierte Eingabefeld (inkl. TinyMCE/CKEditor) ein.

Acht vordefinierte **Symbol-Templates** stehen zur Auswahl: Mathematik, Griechisch,
Spanisch, Italienisch, Deutsch, Französisch, Chemie, Physik.

**Wichtig:** Das Modul schreibt ausschließlich in `examsymbols_settings`. Die
Symbol-Definitionen sind vollständig im PHP-Code (`ilExamSymbolsConfig`) und werden
als JSON an JavaScript übergeben — keine externe Datei, kein API-Aufruf.

---

## Einschränkungen

- Nur im Testplayer aktiv — `isTestPlayerContext()` prüft `cmdClass` und URL-Pattern.
- Template-Validierung in `saveSettings()` und `getSymbolTemplate()` — ungültige
  Template-Keys fallen auf `'math'` zurück.
- Symbol-Definitionen sind hardcodiert in `ilExamSymbolsConfig::getAvailableTemplates()`.
  Neue Templates müssen dort im PHP-Code ergänzt werden.

---

## Dateistruktur

```
modules/examsymbols/                        ← entsteht nach Installation
├── module.json                             ← Modul-Metadaten, require_files: Config-Klasse
├── class.ilExamSymbolsHandler.php          ← Handler (implements ilExamModuleInterface)
├── class.ilExamSymbolsConfig.php           ← Symbol-Templates + Hilfsmethoden
├── css/
│   └── examsymbols.css                     ← Styles für Zeichen-Palette-Overlay
├── js/
│   ├── symbols-manager.js                  ← Verwaltung der Zeichen-Palette
│   └── examsymbols-loader.js               ← Initialisierung + Floating-Button-Logik
├── templates/
│   └── symbols_overlay.html                ← HTML-Template für die Palette
└── sql/
    ├── install.php                         ← Legt examsymbols_settings an
    └── uninstall.php                       ← Löscht examsymbols_settings
```

---

## module.json

```json
{
    "id": "examsymbols",
    "label": "Sonderzeichen-Palette",
    "description": "Stellt eine klickbare Palette mit mathematischen und wissenschaftlichen Sonderzeichen bereit.",
    "version": "1.1.1",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamSymbolsHandler",
    "handler_file": "class.ilExamSymbolsHandler.php",
    "require_files": [
        "class.ilExamSymbolsConfig.php"
    ],
    "tables": [
        "examsymbols_settings"
    ]
}
```

Kein `gui_file`/`gui_class` — ExamSymbols hat keine eigene Verwaltungs-GUI.
`require_files` enthält `class.ilExamSymbolsConfig.php` damit sie beim Laden des
Handlers bereits verfügbar ist (der Handler instanziiert sie im Konstruktor).

---

## Datenbank

### `examsymbols_settings`

Speichert Sonderzeichen-Konfiguration je Test. Primärschlüssel ist `test_id` (kein
Auto-Increment) — ein Eintrag pro Test.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 1 = aktiv, 0 = inaktiv (Default: 0) |
| `template` | text(255) | Template-Key (Default: `'math'`) |
| `created_at` | timestamp | Erstellungszeitpunkt (nullable) |
| `updated_at` | timestamp | Letzter Speicherzeitpunkt (nullable) |

Schreiben via `$db->replace()` mit `test_id` als Unique-Key — upsert-Semantik.

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| *(nur via `ilObject::_lookupObjectId()`)* | `ref_id` → `obj_id` Auflösung |

---

## ilExamSymbolsHandler

Implementiert `ilExamModuleInterface`.

```
isEnabled(ref_id)         → liest examsymbols_settings.enabled für obj_id; false bei fehlendem Eintrag
injectAssets(tpl, ref_id) → nur wenn isEnabled() && isTestPlayerContext();
                            examsymbols.css + symbols-manager.js + examsymbols-loader.js
                            window.ExamSymbols.templatePath + window.ExamSymbols.config gesetzt
saveSettings(ref_id, post)→ validiert template via config->templateExists(), dann db->replace();
                            keys: examsymbols_enabled, examsymbols_template; gibt true zurück
getFormItems(ref_id)       → [ilCheckboxInputGUI(examsymbols_enabled)]
                            Sub-[ilSelectInputGUI(examsymbols_template)] mit Optionen aus Config
getInfo()                  → dynamisch: template_count + total_symbols aus Config berechnet
```

### Nicht-Interface-Methoden

```
getSymbolTemplate(ref_id)  → liest template aus DB, validiert via config->templateExists();
                             Default 'math'
isTestPlayerContext()       → prüft cmdClass (iltestplayer*) + URL-Pattern
getSettingsHTML(ref_id)     → raw Bootstrap-HTML (Legacy-Methode, nicht von Interface gefordert)
```

### Asset-Injection im Detail

```php
$tpl->addCss(".../modules/examsymbols/css/examsymbols.css");
$tpl->addJavaScript(".../modules/examsymbols/js/symbols-manager.js");
$tpl->addJavaScript(".../modules/examsymbols/js/examsymbols-loader.js");

$tpl->addOnLoadCode("
    window.ExamSymbols = window.ExamSymbols || {};
    window.ExamSymbols.templatePath = '.../templates/symbols_overlay.html';
    window.ExamSymbols.config = {
        template: 'math',            // gewähltes Template
        refId: 123,
        symbols: [...],              // Array der Zeichen-Strings (aus Config)
        availableTemplates: {...}    // alle Templates mit label+description+symbols
    };
");
```

`examsymbols-loader.js` liest `window.ExamSymbols.config` und rendert die Palette.
Shortcut: **Alt + S**.

---

## ilExamSymbolsConfig

Enthält alle Symbol-Template-Definitionen und Hilfsmethoden.

```
getAvailableTemplates()          → assoziatives Array aller Templates (key → {label, description, symbols[]})
getSymbolsForTemplate(template)  → symbols-Array für ein Template; [] bei unbekanntem Key
getTemplateLabel(template)       → Anzeigename des Templates
getTemplateDescription(template) → Beschreibungstext des Templates
templateExists(template)         → true wenn Template-Key vorhanden
getTemplateStats()               → [{label, symbol_count, description}] für alle Templates
get(key, default) / set(key, v)  → ilSetting-Wrapper (aktuell ungenutzt)
```

### Verfügbare Templates

| Template-Key | Label | Anzahl Symbole |
|---|---|---|
| `math` | Mathematik/Wissenschaft | 68 (math. Symbole + gr. Buchstaben klein + groß) |
| `spanish` | Spanisch | 14 |
| `italian` | Italienisch | 16 |
| `german` | Deutsch | 7 |
| `french` | Französisch | 32 (Akzente + Ligaturen) |
| `greek` | Griechisch | 49 (vollständiges Alphabet klein + groß) |
| `chemistry` | Chemie | 24 (Pfeile, Bindungen, Ladungen, gr. Buchstaben) |
| `physics` | Physik | 38 (Operatoren, Integrale, Vektoren, gr. Buchstaben) |

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen erscheint die Checkbox "Sonderzeichen-Palette aktivieren"
   mit Template-Auswahl

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` läuft erneut
(durch `tableExists()`-Guard ohne Effekt wenn Tabelle bereits existiert).

---

## Changelog

| Datum | Was |
|---|---|
| 2026-05-12 | **v1.1.1:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026-04-10 | Initiale Version: 8 Symbol-Templates, Floating-Button, Focus-Tracking, TinyMCE/CKEditor-Support |
| 2026-04-10 | In modulares System migriert (`modules/examsymbols/`), `module.json` hinzugefügt |
| 2026-04-10 | **v1.1.0:** Config-Klasse ausgelagert (`ilExamSymbolsConfig`), Handler bereinigt (`getAvailableTemplates()` + `getSymbolsForTemplate()` entfernt) |
