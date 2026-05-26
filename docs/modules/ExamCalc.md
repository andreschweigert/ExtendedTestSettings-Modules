# ExamCalc — Entwicklerdokumentation

**Modul-ID:** `examcalc`  
**Speicherort:** `modules/examcalc/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `.dev/` → `modules-zip/modules/examcalc/ExamCalc_v1.1.1.zip`  
**Zuletzt aktualisiert:** 2026-05-12

---

## Was ist ExamCalc?

Stellt den Prüfungsteilnehmern während eines ILIAS-Tests einen **Taschenrechner** als
schwebendes Overlay zur Verfügung. Zwei Modi: **einfach** (Grundrechenarten) und
**wissenschaftlich** (sin, cos, tan, √ etc.).

**Wichtig:** Der Taschenrechner erscheint ausschließlich im Testplayer. Außerhalb des
Testplayer-Kontexts werden keine Assets injiziert. Der wissenschaftliche Modus ist als
"Work in Progress" markiert und kann Fehler enthalten.

---

## Einschränkungen

- Nur im Testplayer aktiv — `isTestPlayerContext()` prüft `cmdClass` und URL-Pattern.
- Wissenschaftlicher Modus benötigt `math.min.js` (lokal gebündelt, kein CDN — Prüfungskontext).
- `ilExamCalcConfig` ist als Dependency-Datei in `require_files` eingetragen, wird aber
  im Handler selbst nicht instanziiert — nur für zukünftige Erweiterungen vorgesehen.

---

## Dateistruktur

```
modules/examcalc/
├── module.json                         ← Modul-Metadaten, require_files: Config-Klasse
├── class.ilExamCalcHandler.php         ← Handler (implements ilExamModuleInterface)
├── class.ilExamCalcConfig.php          ← Wrapper um ilSetting (aktuell ungenutzt im Handler)
├── css/
│   └── examcalc.css                    ← Styles für Taschenrechner-Overlay
├── js/
│   ├── calculator.js                   ← Wissenschaftlicher Modus (Logik)
│   ├── calculator-simple.js            ← Einfacher Modus (Grundrechenarten)
│   ├── examcalc-loader.js              ← Initialisierung + Floating-Button-Logik
│   └── math.min.js                     ← math.js-Bibliothek (nur wissenschaftlicher Modus)
├── templates/
│   ├── calculator_overlay.html         ← HTML-Template wissenschaftlicher Modus
│   └── calculator_simple.html          ← HTML-Template einfacher Modus
└── sql/
    ├── install.php                     ← Legt examcalc_settings an
    └── uninstall.php                   ← Löscht examcalc_settings
```

---

## module.json

```json
{
    "id": "examcalc",
    "label": "Wissenschaftlicher Taschenrechner",
    "description": "Stellt einen Taschenrechner während der Prüfung zur Verfügung (einfach oder wissenschaftlich).",
    "version": "1.1.1",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamCalcHandler",
    "handler_file": "class.ilExamCalcHandler.php",
    "require_files": [
        "class.ilExamCalcConfig.php"
    ],
    "tables": [
        "examcalc_settings"
    ]
}
```

Kein `gui_file`/`gui_class` — ExamCalc hat keine eigene Verwaltungs-GUI.
`require_files` enthält `class.ilExamCalcConfig.php` damit die Klasse beim Laden des
Handlers bereits verfügbar ist (auch wenn der Handler sie aktuell nicht nutzt).

---

## Datenbank

### `examcalc_settings`

Speichert Taschenrechner-Konfiguration je Test. Primärschlüssel ist `test_id` (kein
Auto-Increment) — ein Eintrag pro Test.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 1 = aktiv, 0 = inaktiv (Default: 0) |
| `calculator_mode` | text(20) | `'simple'` oder `'advanced'` (Default: `'simple'`) |
| `created_at` | timestamp | Erstellungszeitpunkt (nullable) |
| `updated_at` | timestamp | Letzter Speicherzeitpunkt (nullable) |

Schreiben via `$db->replace()` mit `test_id` als Unique-Key — upsert-Semantik.

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| *(nur via `ilObject::_lookupObjectId()`)* | `ref_id` → `obj_id` Auflösung |

---

## ilExamCalcHandler

Implementiert `ilExamModuleInterface`.

```
isEnabled(ref_id)         → liest examcalc_settings.enabled für obj_id; false bei fehlendem Eintrag
injectAssets(tpl, ref_id) → nur wenn isEnabled() && isTestPlayerContext();
                            Mode 'simple': calculator-simple.js
                            Mode 'advanced': math.min.js → calculator.js
                            Immer: examcalc.css + examcalc-loader.js
                            window.ExamCalc.templatePath + window.ExamCalc.calculatorMode gesetzt
saveSettings(ref_id, post)→ db->replace() mit examcalc_enabled + examcalc_mode; gibt true zurück
getFormItems(ref_id)       → [ilCheckboxInputGUI(examcalc_enabled)] mit Sub-[ilSelectInputGUI(examcalc_mode)]
getInfo()                  → title, icon, description, version, features-Array
```

### Nicht-Interface-Methoden

```
getCalculatorMode(ref_id)   → liest calculator_mode aus DB; Default 'simple'
isTestPlayerContext()        → prüft cmdClass (iltestplayer*) + URL-Pattern (startPlayer/resumePlayer)
getSettingsHTML(ref_id)      → raw Bootstrap-HTML (Legacy-Methode, nicht von Interface gefordert)
```

### Asset-Injection im Detail

```php
// Modus 'advanced':
$tpl->addJavaScript(".../modules/examcalc/js/math.min.js");   // muss VOR calculator.js kommen
$tpl->addJavaScript(".../modules/examcalc/js/calculator.js");

// Modus 'simple':
$tpl->addJavaScript(".../modules/examcalc/js/calculator-simple.js");

// Beide Modi:
$tpl->addCss(".../modules/examcalc/css/examcalc.css");
$tpl->addJavaScript(".../modules/examcalc/js/examcalc-loader.js");

$tpl->addOnLoadCode("
    window.ExamCalc = window.ExamCalc || {};
    window.ExamCalc.templatePath = '...templates/calculator_overlay.html'; // oder _simple.html
    window.ExamCalc.calculatorMode = 'simple'; // oder 'advanced'
");
```

`examcalc-loader.js` liest `window.ExamCalc.templatePath` und `window.ExamCalc.calculatorMode`
und baut den Floating-Button + Overlay auf. Shortcut: **Alt + R**.

---

## ilExamCalcConfig

Einfacher Wrapper um `ilSetting`. Aktuell im Handler nicht instanziiert — in
`require_files` eingetragen für zukünftige Nutzung.

```
get(key, default) → $this->settings->get(key, default)
set(key, value)   → $this->settings->set(key, value)
```

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen erscheint die Checkbox "Taschenrechner aktivieren"

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` läuft erneut
(durch `tableExists()`-Guard ohne Effekt wenn Tabelle bereits existiert).

---

## Changelog

| Datum | Was |
|---|---|
| 2026-05-12 | **v1.1.1:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026-04-10 | Initiale Version: einfacher + wissenschaftlicher Modus, Testplayer-Integration |
| 2026-04-10 | In modulares System migriert (`modules/examcalc/`), `module.json` hinzugefügt |
| 2026-04-10 | **v1.1.0:** `implements ilExamModuleInterface` vollständig, `getFormItems()` mit ILIAS-Formular-Elementen |
