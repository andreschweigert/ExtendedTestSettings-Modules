# ExamResourceSheet — Entwicklerdokumentation

**Modul-ID:** `examresourcesheet`  
**Speicherort:** `modules/examresourcesheet/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examresourcesheet/ExamResourceSheet_v1.1.0.zip`  
**ILIAS-Kompatibilität:** 9  
**Zuletzt aktualisiert:** 2026-05-12

---

## Was ist ExamResourceSheet?

Stellt Prüfungsteilnehmern während eines ILIAS-Tests ein vom Dozenten hochgeladenes
**Hilfsmittelblatt** (PDF oder HTML, max. 10 MB) als seitliches Fixed-Panel bereit.
Ein linker Edge-Tab öffnet ein Panel, das von links ins Bild einfährt und die Seite nach
rechts schiebt (Push-Modus). PDFs werden über PDF.js gerendert — kein nativer Browser-Viewer,
kein Download-Button. Text im PDF ist markier- und kopierbar (PDF.js Text-Layer).

**Wichtig:** Das Modul erscheint ausschließlich im Testplayer. Die Datei liegt außerhalb
des Web-Roots und wird nur an eingeloggte Nutzer mit aktiver Test-Session (oder Schreibrecht)
ausgeliefert. Zugriffsschutz über Token-URL.

---

## Einschränkungen

- Nur im Testplayer aktiv — `isTestPlayerContext()` prüft `cmdClass` und URL-Pattern.
- Nur ILIAS 9 kompatibel.
- Upload-Limit: 10 MB; erlaubte Typen: PDF und HTML/HTM (serverseitig via `finfo` geprüft).
- Die Datei wird per `fetch()` als Blob geladen (`URL.createObjectURL`). PDFs werden an
  `pdfjsLib.getDocument()` übergeben; HTML-Dateien als `blob:`-URL in ein `<iframe>` gesetzt
  (umgeht Browser-seitige `X-Frame-Options`-Blockierungen).
- Dateiauslieferung geht über `ilExtendedTestSettingsModuleProxyGUI::serveResourceFile()`
  (bereits in ilCtrl registrierte Klasse) — kein Reload der Control-Structure erforderlich.
- Tabellennamen-Limit: ILIAS erlaubt max. 22 Zeichen → `examressheet_settings` (21 Zeichen).
- `getFormItems()` gibt `[$checkbox, $fileInput]` als **zwei separate Top-Level-Items** zurück,
  damit `ilPropertyFormGUI` `enctype="multipart/form-data"` setzt (Sub-Items verhindern das).

---

## Dateistruktur

```
modules/examresourcesheet/
├── module.json                              ← Modul-Metadaten
├── class.ilExamResourceSheetHandler.php    ← Handler (implements ilExamModuleInterface)
├── css/
│   └── examresourcesheet.css               ← Styles für Edge-Tab + Left-Panel + Text-Layer
├── js/
│   ├── examresourcesheet.js                ← Edge-Tab, Left-Panel, fetch→blob-Logik
│   ├── pdf.min.js                          ← PDF.js 3.11.174 (Bundle)
│   └── pdf.worker.min.js                   ← PDF.js Worker
└── sql/
    ├── install.php                          ← Legt examressheet_settings an
    └── uninstall.php                        ← Löscht Tabelle + alle gespeicherten Dateien
```

**Dateiauslieferungs-Endpoint** (in der Kern-Plugin-Klasse, nicht im Modul selbst):

```
classes/class.ilExtendedTestSettingsModuleProxyGUI.php
  └── serveResourceFile()   ← privat, via cmd=serveResourceFile erreichbar
```

---

## module.json

```json
{
    "id": "examresourcesheet",
    "label": "Hilfsmittelblatt",
    "description": "Stellt Studierenden ein vom Dozenten hochgeladenes Hilfsmittelblatt (PDF/HTML) als Overlay im Test-Player bereit.",
    "version": "1.1.0",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamResourceSheetHandler",
    "handler_file": "class.ilExamResourceSheetHandler.php",
    "require_files": [],
    "tables": ["examressheet_settings"]
}
```

Kein `gui_file`/`gui_class` — die Dateiauslieferung läuft über den bereits registrierten
`ilExtendedTestSettingsModuleProxyGUI`, nicht über eine eigene Modul-GUI.

---

## Datenbank

### `examressheet_settings`

Speichert Konfiguration und Datei-Metadaten je Test. Primärschlüssel ist `test_id`.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 1 = aktiv, 0 = inaktiv (Default: 0) |
| `label` | text(100) | Button-Beschriftung, z.B. „Formelblatt" (Default: „Hilfsmittel") |
| `filename` | text(255) | Originaler Dateiname (für Anzeige), nullable |
| `stored_name` | text(255) | Physischer Dateiname `{test_id}_{token}.{ext}`, nullable |
| `mimetype` | text(100) | `application/pdf` oder `text/html`, nullable |
| `filesize` | integer(8) | Dateigröße in Bytes, nullable |
| `updated_at` | timestamp | Letzter Speicherzeitpunkt, nullable |

Schreiben via **explizitem** `$db->insert()` (Ersteintrag) bzw. `$db->update()` (Folge-
speicherungen) — bewusst **kein** `db->replace()`, da REPLACE INTO alle nicht genannten
Spalten auf NULL setzt und so `filename`/`stored_name` löschen würde.

### Dateispeicherung (außerhalb Web-Root)

```
{CLIENT_DATA_DIR}/plugins/ExtendedTestSettings/resourcesheets/{stored_name}
```

Format von `stored_name`: `{test_id}_{32-char-hex-token}.{ext}`

Beim Ersetzen einer Datei wird die alte Datei automatisch gelöscht (`@unlink`).

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| *(via `ilObject::_lookupObjectId()`)* | `ref_id` → `obj_id` Auflösung |
| `tst_active` | Prüfung auf aktive Test-Session (`hasActiveTestSession`) |

---

## ilExamResourceSheetHandler

Implementiert `ilExamModuleInterface`.

```
isEnabled(ref_id)         → liest examressheet_settings.enabled; false bei fehlendem Eintrag
injectAssets(tpl, ref_id) → nur wenn isEnabled() && isTestPlayerContext();
                            Token aus stored_name extrahieren → file_url aufbauen
                            examresourcesheet.css + pdf.min.js (bei PDF) + examresourcesheet.js injizieren
                            window.ExamResourceSheet.config als JSON via addOnLoadCode setzen
saveSettings(ref_id, post)→ Checkbox/Label aus $_POST (multipart-Fallback)
                            Upload aus $_FILES: MIME-Check via finfo + Extension-Whitelist
                            INSERT wenn kein Eintrag, UPDATE sonst
getFormItems(ref_id)       → [$checkbox_mit_label_subitem, $fileInput] — zwei Top-Level-Items
getInfo()                  → title, icon, description, version, ilias_min, ilias_max, features-Array
```

### Nicht-Interface-Methoden

```
ensureTableExists()          → Konstruktor-Aufruf; legt Tabelle an wenn nicht vorhanden
                               (notwendig bei manuellem Ablegen ohne ZIP-Installer)
loadSettings(ref_id)         → SELECT * für Formular-Befüllung
getStorageDir()              → {CLIENT_DATA_DIR}/plugins/ExtendedTestSettings/resourcesheets/
detectMimeType(tmp_path)     → finfo_file(); Fallback: Magic-Bytes (%PDF)
isTestPlayerContext()        → prüft cmdClass (iltestplayer*) + URL-Pattern
```

### Asset-Injection im Detail

```php
// Token aus stored_name extrahieren:
// stored_name = "{test_id}_{token}.{ext}" → token = 32-char hex
$basename = pathinfo($row['stored_name'], PATHINFO_FILENAME); // "{test_id}_{token}"
$token    = substr($basename, strpos($basename, '_') + 1);

// URL über registrierten Proxy aufbauen (kein eigener ilCtrl-Eintrag nötig):
$file_url = ILIAS_HTTP_PATH . '/ilias.php'
    . '?baseClass=ilUIPluginRouterGUI'
    . '&cmdClass=ilextendedtestsettingsmoduleproxygui'
    . '&cmd=serveResourceFile'
    . '&ref_id=' . $ref_id
    . '&token=' . urlencode($token);

$tpl->addCss(".../modules/examresourcesheet/css/examresourcesheet.css");

// PDF.js nur bei PDF-Dateien laden (spart Bandbreite bei HTML):
if ($row['mimetype'] === 'application/pdf') {
    $tpl->addJavaScript(".../modules/examresourcesheet/js/pdf.min.js");
    $config['pdfWorkerSrc'] = ILIAS_HTTP_PATH . '/.../js/pdf.worker.min.js';
}

$tpl->addJavaScript(".../modules/examresourcesheet/js/examresourcesheet.js");

$tpl->addOnLoadCode(
    "window.ExamResourceSheet = window.ExamResourceSheet || {};" .
    "window.ExamResourceSheet.config = $config_json;"
    // config: { enabled, label, mimetype, filename, fileUrl, pdfWorkerSrc }
);
```

Das JS **pollt** bis `window.ExamResourceSheet.config` verfügbar ist
(`waitForConfig(30)` × 100 ms), weil `addJavaScript` vor `addOnLoadCode` ausgeführt wird.

---

## examresourcesheet.js

```
waitForConfig(retries)  → Polling 30×100ms auf window.ExamResourceSheet.config
maxPanelWidth()         → Math.round(window.innerWidth * 0.92) — zentraler Max-Breiten-Helper
buildUI(cfg)            → Edge-Tab (links) + Left-Panel + Header-Buttons erstellen
openPanel()             → panel.style.left = '0'; body.marginLeft = panelWidth
                          ers-panel-active-Klasse für CSS-Transition
closePanel()            → Panel ausblenden; body.marginLeft = 0; Fokus → Tab
applyWidth(newW)        → Breiten-Buttons [−]/[+]; Panel + body.marginLeft synchronisieren
rerenderPdf()           → Fit-to-Width-Scale berechnen; alle Seiten neu rendern
                          Für jede Seite: Canvas + Text-Layer (--scale-factor setzen!)
Resize-Drag             → mousedown/mousemove/mouseup auf resizeHandle
                          ers-resizing-Klasse verhindert CSS-Transition während Drag
loadFile()              → fetch(fileUrl) → blob → URL.createObjectURL
                          PDF: pdfjsLib.getDocument() → rerenderPdf()
                          HTML: iframe.src = blobUrl
SessionStorage          → saveState(open, width) / loadState() via 'examresourcesheet_state'
Escape-Key-Handler      → Panel schließen, Fokus zurück auf Tab
```

Die Datei wird **lazy** beim ersten Öffnen geladen und das `blobUrl` gecacht —
wiederholtes Öffnen/Schließen macht keinen erneuten Netzwerk-Request.

### Kritische Implementierungsdetails

- **`--scale-factor` CSS-Variable**: Muss auf dem Text-Layer-Div gesetzt werden (`textLayerDiv.style.setProperty('--scale-factor', vp.scale)`), sonst wirft PDF.js einen Fehler und der Text-Layer rendert nicht.
- **`page.streamTextContent()`** statt `page.getTextContent().then()`: In PDF.js 3.x bevorzugte API für `renderTextLayer`.
- **`var`-Deklarationen am Anfang von `buildUI()`**: JS-Hoisting-Bug vermieden — alle Variablen werden vor dem State-Restore-Block deklariert.
- **Reflow-Trick**: `panel.getBoundingClientRect()` zwischen `transition: none` setzen und entfernen, damit der Browser den initialen State ohne Animation übernimmt.

---

## Dateiauslieferung: `serveResourceFile()`

Lebt in `classes/class.ilExtendedTestSettingsModuleProxyGUI.php` (bereits in ilCtrl
registriert via `@ilCtrl_isCalledBy`). Wird via `cmd=serveResourceFile` erreicht.

**Zugriffsprüfung:**
1. Eingeloggt + nicht anonym (sonst → Login-Redirect durch `executeCommand()`)
2. `ref_id` vorhanden + Token genau 32 Hex-Zeichen
3. `test_id` ermittelbar via `ilObject::_lookupObjectId()`
4. Aktive Test-Session (`tst_active` ohne `last_finished_pass`) **ODER** Schreibrecht auf `ref_id`
5. `stored_name` aus DB muss exakt `{test_id}_{token}.{ext}` entsprechen (Token-Validierung)
6. MIME-Type muss `application/pdf` oder `text/html` sein

**Auslieferung:**
```php
while (ob_get_level()) { ob_end_clean(); }   // ILIAS-Outputbuffer verwerfen
header_remove('X-Frame-Options');             // PHP-gesetzte Header entfernen
header_remove('Content-Security-Policy');
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="..."');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: private, max-age=3600');
readfile($file_path);
exit;
```

*(Der `ob_end_clean()`-Loop ist defensiv — in der Praxis lädt das JS die Datei ohnehin
per `fetch()` als Blob, sodass X-Frame-Options irrelevant ist.)*

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen erscheint die Checkbox „Hilfsmittelblatt aktivieren"

### Manuelle Ablage (Entwicklung)

Dateien direkt unter `modules/examresourcesheet/` ablegen. Der Konstruktor des Handlers
ruft `ensureTableExists()` auf — die Tabelle `examressheet_settings` wird beim ersten
Seitenaufruf automatisch angelegt.

### Update von 1.0.0 → 1.1.0

Gleiche Dateien ersetzen. Die DB-Tabelle `examressheet_settings` bleibt unverändert —
`sql/install.php` hat einen `tableExists()`-Guard. Kein Datenverlust.

---

## Besondere Implementierungsentscheidungen

| Entscheidung | Begründung |
|---|---|
| `db->insert()` + `db->update()` statt `db->replace()` | `REPLACE INTO` löscht den Datensatz (DELETE+INSERT) → nicht hochgeladene Felder werden NULL |
| `getFormItems()` gibt FileInput als Top-Level-Item zurück | `ilPropertyFormGUI` setzt `enctype="multipart/form-data"` nur für Top-Level-`ilFileInputGUI` |
| Dateiauslieferung über `ilExtendedTestSettingsModuleProxyGUI` | Eigene GUI-Klasse wäre erst nach Reload der Control-Structure erreichbar; Proxy ist bereits registriert |
| `fetch()` + Blob-URL statt direktem `iframe.src` | Umgeht `X-Frame-Options`/CSP-Blockierung durch Browser |
| Token im URL statt Dateiname | Verhindert Enumeration; Token ist 32-char SHA-256-Anteil, gebunden an `test_id` |
| `ensureTableExists()` im Konstruktor | ZIP-Installer läuft nur bei Install-via-GUI — bei manueller Ablage sonst keine Tabelle |
| PDF.js statt nativem Browser-PDF-Viewer | Kein Download-Button, kein Print-Dialog; Text-Layer ermöglicht Markieren/Kopieren |
| `maxPanelWidth()` als Hilfsfunktion | `Math.round(window.innerWidth * 0.92)` wurde 5× benötigt; liest `window.innerWidth` live (korrekt bei Fenstergrößenänderung) |

---

## Changelog

| Datum | Version | Was |
|---|---|---|
| 2026-05-12 | 1.1.0 | PDF.js-Rendering (kein nativer Viewer), Text-Layer (Markieren/Kopieren), linker Edge-Tab, Push-Modus (body margin-left), Drag-Resize + [−]/[+]-Buttons, SessionStorage-State, ILIAS 9-only |
| 2026-05-12 | 1.0.0 | Initiale Version: PDF/HTML-Upload, Token-URL, Overlay-Panel, Blob-Fetch-Workaround |
