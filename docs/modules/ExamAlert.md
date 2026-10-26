# ExamAlert — Entwicklerdokumentation

**Modul-ID:** `examalert`  
**Speicherort:** `modules/examalert/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examalert/ExamAlert_v10.0.1.zip`
**Zuletzt aktualisiert:** 2026-07-07

---

## ILIAS-10-Port (Version 10.0.0, 2026-07-07)

Start der ILIAS-10-Linie (Branch `ilias10`; Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1).
module.json: `version` 10.0.0, `ilias_min`/`ilias_max` = 10.

- Tote Player-Kommandos `cmd=confirmFinish` und `cmd=outUserPassedQuestionSetFinish` aus `examalert.js` entfernt (existieren seit ILIAS ≤ 7 in keinem Core mehr, Check war wirkungslos).

---

## Was ist ExamAlert?

Zeigt den Prüfungsteilnehmern im Testplayer ein **Popup-Modal** an, wenn die
verbleibende Prüfungszeit einen konfigurierbaren Schwellenwert unterschreitet.
Die Nachricht ist frei editierbar (HTML erlaubt), eine zweisprachige Standardnachricht
(Deutsch + Englisch) ist vorkonfiguriert.

**Wichtig:** Das Modul hat eine **Auto-Aktivierungs-Logik** — bei neuen Tests ohne
existierenden DB-Eintrag wird automatisch ein Default-Eintrag angelegt, dessen
`enabled`-Wert von der globalen Plugin-Einstellung `examalert_auto_activate` abhängt
(Default: aktiv). Das unterscheidet ExamAlert von den anderen Modulen.

---

## Einschränkungen

- Nur im Testplayer aktiv — `isTestPlayerContext()` prüft `cmdClass` und URL-Pattern.
- Warnzeit-Validierung in `saveSettings()`: Minimum 5 Sekunden, Maximum 3600 Sekunden.
- Konfigurierbare HTML-Nachricht: nur `<strong>`, `<u>`, `<br>` werden empfohlen.
- Die Warnung wird clientseitig nur **einmal pro Testsitzung** angezeigt (JS-Logik in
  `examalert.js`).

---

## Dateistruktur

```
modules/examalert/
├── module.json                         ← Modul-Metadaten, require_files: []
├── class.ilExamAlertHandler.php        ← Handler (implements ilExamModuleInterface)
├── css/
│   └── examalert.css                   ← Styles für Alert-Popup
├── js/
│   └── examalert.js                    ← Countdown-Logik + Popup-Anzeige
└── sql/
    ├── install.php                     ← Legt examalert_settings an
    └── uninstall.php                   ← Löscht examalert_settings
```

---

## module.json

```json
{
    "id": "examalert",
    "label": "Zeit-Warnung",
    "description": "Zeigt eine Warnung an wenn die Prüfungszeit fast abgelaufen ist.",
    "version": "1.1.1",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamAlertHandler",
    "handler_file": "class.ilExamAlertHandler.php",
    "require_files": [],
    "tables": [
        "examalert_settings"
    ]
}
```

Kein `gui_file`/`gui_class` — ExamAlert hat keine eigene Verwaltungs-GUI.
`require_files` ist leer — keine Abhängigkeiten außer dem Handler selbst.

---

## Datenbank

### `examalert_settings`

Speichert Alert-Konfiguration je Test. Primärschlüssel ist `test_id` — ein Eintrag pro
Test.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 1 = aktiv, 0 = inaktiv (Default: 0 im Schema) |
| `alert_time` | integer(4) | Sekunden vor Testende (Default: 30) |
| `alert_message` | text(1000) | HTML-Nachricht (Default: `'Achtung: Der Test endet bald!'`) |
| `created_at` | timestamp | Erstellungszeitpunkt (nullable) |
| `updated_at` | timestamp | Letzter Speicherzeitpunkt (nullable) |

Schreiben via `$db->replace()` mit `test_id` als Unique-Key — upsert-Semantik.

> **Hinweis:** Der Schema-Default für `enabled` ist 0, aber `ensureDefaultSettings()`
> legt neue Einträge mit `enabled = $auto_activate` an — also ggf. 1, wenn die globale
> Auto-Aktivierungs-Einstellung aktiv ist.

### Globale Plugin-Einstellungen (read-only)

| Namespace | Key | Bedeutung |
|---|---|---|
| `extendedtestsettings` | `examalert_auto_activate` | `'1'` = neue Tests automatisch aktivieren (Default `'1'`) |

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| *(nur via `ilObject::_lookupObjectId()`)* | `ref_id` → `obj_id` Auflösung |

---

## ilExamAlertHandler

Implementiert `ilExamModuleInterface`.

```
isEnabled(ref_id)         → liest examalert_settings.enabled;
                            bei fehlendem Eintrag: ensureDefaultSettings() aufrufen,
                            return-Wert = examalert_auto_activate-Einstellung
injectAssets(tpl, ref_id) → nur wenn isEnabled() && isTestPlayerContext() && config['enabled'];
                            examalert.css + examalert.js
                            window.ExamAlert.config (JSON) + window.ExamAlert.refId gesetzt
saveSettings(ref_id, post)→ validiert alert_time (5–3600), füllt leere Nachricht mit DEFAULT_MESSAGE,
                            db->replace(); gibt true zurück
getFormItems(ref_id)       → [ilCheckboxInputGUI(examalert_enabled)]
                            Sub-[ilNumberInputGUI(examalert_time, min=5, max=3600, suffix="Sekunden vor Testende")]
                            Sub-[ilTextAreaInputGUI(examalert_message, rows=6, HTML erlaubt)]
getInfo()                  → title, icon, description, version, features-Array
```

### Nicht-Interface-Methoden

```
getAlertConfig(ref_id)              → array {enabled, alert_time, alert_message};
                                      bei fehlendem Eintrag: ensureDefaultSettings() + Defaults zurückgeben;
                                      bei deaktiviertem Eintrag: {enabled: false, alert_time: 30, alert_message: ''}
ensureDefaultSettings(test_id, bool)→ legt DB-Eintrag nur wenn noch nicht vorhanden
                                      (alert_time=30, DEFAULT_MESSAGE, enabled=Parameter)
isTestPlayerContext()                → prüft cmdClass (iltestplayer*) + URL-Pattern
getSettingsHTML(ref_id)              → raw Bootstrap-HTML (Legacy-Methode)
```

### Auto-Aktivierungs-Logik

```
1. isEnabled($ref_id) aufgerufen
2. kein Eintrag in examalert_settings?
3. ilSetting('extendedtestsettings')->get('examalert_auto_activate', '1') lesen
4. ensureDefaultSettings($test_id, $auto_activate) → INSERT wenn noch kein Eintrag
5. return $auto_activate
```

Das bedeutet: Wenn ein Test zum ersten Mal die "Erweiterte Test-Einstellungen"-Seite
öffnet, wird automatisch ein Eintrag angelegt — ohne explizites Speichern durch den Nutzer.

### Asset-Injection im Detail

```php
$tpl->addCss(".../modules/examalert/css/examalert.css");
$tpl->addJavaScript(".../modules/examalert/js/examalert.js");

$config_json = json_encode([
    'enabled'       => true,
    'alert_time'    => 30,       // Sekunden
    'alert_message' => '...'     // HTML-String
]);

$tpl->addOnLoadCode("
    window.ExamAlert = window.ExamAlert || {};
    window.ExamAlert.config = $config_json;
    window.ExamAlert.refId = $ref_id;
");
```

`examalert.js` liest `window.ExamAlert.config.alert_time`, überwacht den ILIAS-Timer
und zeigt das Popup an. Das Popup hat einen Live-Countdown und bleibt bis zum manuellen
Schließen sichtbar. Die Warnung wird pro Sitzung nur einmal gezeigt.

---

## Standardnachricht (DEFAULT_MESSAGE)

```
Ihr Test endet in 30 Sekunden! Klicken Sie <strong>vor Ablauf der Zeit</strong> auf <strong>„Speichern und Test beenden"</strong> , um Ihre Abgabe zu autorisieren und abzugeben.
Your test will end in 30 seconds! Click on <strong>"Save Answers and Finish the Test"</strong> to authorise and submit your test <strong>before the time runs out.</strong>
```

Zweisprachig (DE/EN). Wird als Fallback in `saveSettings()` verwendet wenn die
Nachricht leer bleibt, und in `ensureDefaultSettings()` als initialer Wert.

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen erscheint die Checkbox "Zeit-Warnung aktivieren"
   mit Warnzeit- und Nachrichtenfeld
4. Optional: globale Auto-Aktivierungs-Einstellung konfigurieren
   (`examalert_auto_activate` in ilSettings, Namespace `extendedtestsettings`)

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` läuft erneut
(durch `tableExists()`-Guard ohne Effekt wenn Tabelle bereits existiert).

---

## Changelog

| Datum | Was |
|---|---|
| 2026-06-10 | **v1.2.1:** Sicherheitsfix XSS — `alert_message` wird in `showAlert()` jetzt über die (bereits vorhandene, zuvor ungenutzte) `escapeHtml()`-Funktion escaped, bevor sie per `insertAdjacentHTML` ins DOM kommt. Whitelist-Tags `<strong> <b> <i> <u> <br>` bleiben funktional |
| 2026-06-01 | **v1.2.0:** Modul-eigene Sprachdateien `lang/ilias_de\|en.lang`, Formular-Texte über ilModuleLanguage |
| 2026-05-12 | **v1.1.1:** ILIAS-Kompatibilität auf 9 beschränkt; Standardnachricht überarbeitet |
| 2026-04-10 | Initiale Version: konfig. Warnzeit, zweisprachige Standardnachricht, Popup mit Countdown |
| 2026-04-10 | Auto-Aktivierungs-Logik: neuer Test erhält automatisch Default-Eintrag |
| 2026-04-10 | In modulares System migriert (`modules/examalert/`), `module.json` hinzugefügt |
| 2026-04-10 | **v1.1.0:** `implements ilExamModuleInterface` vollständig, `getFormItems()` mit ilNumberInputGUI + ilTextAreaInputGUI |
