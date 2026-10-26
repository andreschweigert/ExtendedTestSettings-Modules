# ExamGuard — Entwicklerdokumentation

**Modul-ID:** `examguard`  
**Speicherort:** `modules/examguard/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examguard/ExamGuard_v10.0.1.zip`
**Zuletzt aktualisiert:** 2026-07-07

---

## ILIAS-10-Port (Version 10.0.0, 2026-07-07)

Start der ILIAS-10-Linie (Branch `ilias10`; Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1).
module.json: `version` 10.0.0, `ilias_min`/`ilias_max` = 10.

- Keine Code-Änderungen nötig. Web-Asset-Pfade (addJavaScript/addCss) funktionieren über den zentralen Registry-Compat des Hauptplugins (ab 10.0.0: Handler erhalten den web-relativen Pfad via `getRelativeDirectory()`).

---

## Was ist ExamGuard?

Injiziert ein **JavaScript** in den Testplayer, das unfaires Verhalten während der
Prüfung erschwert: Copy&Paste, Rechtsklick, kritische Tastenkombinationen
(Ctrl+C/V/X/A/S) und DevTools-Shortcuts (F12) werden blockiert. Text-Selektion wird
verhindert. Läuft vollständig unsichtbar im Hintergrund.

**Wichtig:** Das Modul schreibt ausschließlich in `examguard_settings`. Die
Schutzmaßnahmen sind rein clientseitig (JavaScript) und können von technisch versierten
Nutzern umgangen werden — ExamGuard ist eine Erschwernis, keine Garantie.

---

## Einschränkungen

- Nur im Testplayer aktiv — `isTestPlayerContext()` prüft `cmdClass` und URL-Pattern.
- Die Spalte `exit_prevention` existiert in der DB-Tabelle (Default 1), wird aber im
  Handler-Code nicht gelesen oder geschrieben — für zukünftige Erweiterung reserviert.
- Keine eigene Konfigurationsklasse — der Handler ist vollständig selbstständig.

---

## Dateistruktur

```
modules/examguard/
├── module.json                         ← Modul-Metadaten, require_files: []
├── class.ilExamGuardHandler.php        ← Handler (implements ilExamModuleInterface)
├── js/
│   └── examguard.js                    ← Schutz-Logik (Copy-Paste, Rechtsklick, Shortcuts)
└── sql/
    ├── install.php                     ← Legt examguard_settings an
    └── uninstall.php                   ← Löscht examguard_settings
```

---

## module.json

```json
{
    "id": "examguard",
    "label": "Prüfungsschutz",
    "description": "Verhindert das versehentliche Verlassen des Tests (Browser-Back, Tab schließen, etc.).",
    "version": "1.1.1",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamGuardHandler",
    "handler_file": "class.ilExamGuardHandler.php",
    "require_files": [],
    "tables": [
        "examguard_settings"
    ]
}
```

Kein `gui_file`/`gui_class` — ExamGuard hat keine eigene Verwaltungs-GUI.
`require_files` ist leer — keine Abhängigkeiten außer dem Handler selbst.

---

## Datenbank

### `examguard_settings`

Speichert Prüfungsschutz-Konfiguration je Test. Primärschlüssel ist `test_id` — ein
Eintrag pro Test.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 1 = aktiv, 0 = inaktiv (Default: 0) |
| `exit_prevention` | integer(1) | Reserviert (Default: 1, aktuell nicht genutzt) |
| `created_at` | timestamp | Erstellungszeitpunkt (nullable) |
| `updated_at` | timestamp | Letzter Speicherzeitpunkt (nullable) |

Schreiben via `$db->replace()` mit `test_id` als Unique-Key — upsert-Semantik.
`exit_prevention` wird beim Speichern nicht gesetzt (Handler schreibt nur `enabled`),
behält also immer den Default-Wert 1.

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| *(nur via `ilObject::_lookupObjectId()`)* | `ref_id` → `obj_id` Auflösung |

---

## ilExamGuardHandler

Implementiert `ilExamModuleInterface`.

```
isEnabled(ref_id)         → liest examguard_settings.enabled für obj_id; false bei fehlendem Eintrag
injectAssets(tpl, ref_id) → nur wenn isEnabled() && isTestPlayerContext();
                            injiziert examguard.js via $tpl->addJavaScript()
saveSettings(ref_id, post)→ db->replace() mit examguard_enabled; gibt true zurück
getFormItems(ref_id)       → [ilCheckboxInputGUI('Prüfungsschutz aktivieren', 'examguard_enabled')]
getInfo()                  → title, icon, description, version, features-Array
```

### Nicht-Interface-Methoden

```
isTestPlayerContext()  → prüft cmdClass (iltestplayer*) + URL-Pattern (startPlayer/resumePlayer)
getSettingsHTML()      → raw Bootstrap-HTML (Legacy-Methode, nicht von Interface gefordert)
```

### Asset-Injection im Detail

```php
// Nur JS, kein CSS:
$tpl->addJavaScript(".../modules/examguard/js/examguard.js");
```

`examguard.js` registriert Event-Listener für:
- `copy`, `cut`, `paste` → verhindert Copy&Paste
- `contextmenu` → Rechtsklick blockiert
- `keydown` → Ctrl+C/V/X/A/S, F12 (DevTools), weitere Shortcuts blockiert
- Text-Selektion verhindert via CSS/JS

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen erscheint die Checkbox "Prüfungsschutz aktivieren"

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` läuft erneut
(durch `tableExists()`-Guard ohne Effekt wenn Tabelle bereits existiert).

---

## Changelog

| Datum | Was |
|---|---|
| 2026-05-12 | **v1.1.1:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026-04-10 | Initiale Version: Copy&Paste, Rechtsklick, Keyboard-Shortcuts blockieren |
| 2026-04-10 | In modulares System migriert (`modules/examguard/`), `module.json` hinzugefügt |
| 2026-04-10 | **v1.1.0:** `implements ilExamModuleInterface` vollständig, `getFormItems()` mit ILIAS-Formular-Element |
