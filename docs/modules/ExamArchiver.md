# ExamArchiver — Entwicklerdokumentation

**Modul-ID:** `examarchiver`
**Referenz-ZIP:** `modules-zip/modules/examarchiver/ExamArchiver_v10.0.0.zip`
**Zuletzt aktualisiert:** 2026-07-07

---

## ILIAS-10-Port (Version 10.0.0, 2026-07-07)

Start der ILIAS-10-Linie (Branch `ilias10`; Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1).
module.json: `version` 10.0.0, `ilias_min`/`ilias_max` = 10.

- Null-Check nach `assQuestion::instantiateQuestionGUI()` — die Methode ist in ILIAS 10 nullable (z. B. gelöschte Frage); vorher fing der `catch (Throwable)` den Error ab, jetzt sauberer Fallback auf die Roh-Antworten-Tabelle.
- `getSolutionOutput()` erhält explizit `show_inline_feedback = false` (10. Parameter, neu in ILIAS 10, Default `true`; in ILIAS 9 wird das überzählige Argument ignoriert). Verhalten beim ersten 10er-Archivlauf prüfen.
- Bild-Inlining (`inlineImages`): Webdateien liegen in ILIAS 10 unter `ILIAS_ABSOLUTE_PATH/public/` — Auflösung probiert erst `public/`, dann Fallback ohne (ILIAS 9).

---

## Was ist ExamArchiver?

Erzeugt pro Test ein selbsttragendes, integritätsgesichertes Archiv-ZIP
(HTML + PDF + JSON/CSV + SHA-256-Manifest). Rein serverseitig, kein JS im
Testplayer, kein `allow_student_access` — alle Commands laufen über den
ModuleProxyGUI mit **write**-Recht auf den jeweiligen Test.

## Dateistruktur

```
modules/examarchiver/
├── module.json                          gui_file/gui_class gesetzt, require_files: Collector+Renderer
├── class.ilExamArchiverHandler.php      Handler: nur Status-Abschnitt in den Test-Einstellungen
├── class.ilExamArchiverCollector.php    Reine Lese-Klasse für alle tst_*-Daten
├── class.ilExamArchiverRenderer.php     HTML/CSV-Erzeugung, Bild-Inlining, wkhtmltopdf-Wrapper
├── class.ilExamArchiverGUI.php          Routing, Ansichten, AJAX-Endpoints, ZIP/Manifest, Log
├── lang/ilias_de.lang / ilias_en.lang
└── sql/install.php / uninstall.php
```

## Datenfluss der Archiv-Erzeugung (chunked)

Client-JS (inline, `archiveJs()`) ruft sequenziell pro Test:

1. **`cmd=startArchive`** — legt `examarchiver_archives`-Zeile (status `running`) und
   Arbeitsverzeichnis `<base>/<obj_id>/work_<id>/` an, schreibt `data/test.json`
   (festgeschriebener Test-Snapshot; alle Chunks lesen diesen Stand).
2. **`cmd=processChunk`** (Schleife) — verarbeitet `CHUNK_SIZE=5` Teilnehmer pro Request:
   `Collector::getParticipantData()` → `Renderer::renderParticipantHtml()` →
   `teilnehmer/<login>_<active_id>.html` (+ `.pdf` via wkhtmltopdf). Zusammenfassung +
   Volldaten werden zeilenweise nach `data/participants.jsonl` gepuffert
   (zustandslos: Fortschritt = `done_count`, Teilnehmer-Slice via `ORDER BY active_id`).
3. **`cmd=finalizeArchive`** — `index.html`/`index.pdf`, `data/archive.json`,
   `data/ergebnisse.csv`, `manifest.json` (SHA-256 aller Dateien), ZIP mit
   Wrapper-Ordner `ExamArchive_<obj_id>_<datum>_<id>`, Arbeitsverzeichnis löschen,
   DB-Update (status `done`, `zip_sha256`, `manifest_sha256`), Log `created`.

Fehler in Chunk/Finalize → status `failed` + `error`-Spalte + Log `failed`.

## Wichtige Implementierungsdetails

- **Antwort-Rendering:** `assQuestion::instantiateQuestionGUI($qid)->getSolutionOutput(
  $active_id, $pass, false, false, true, false, $best, false, !$best)`.
  Fallback bei Throwable: Roh-Tabelle aus `tst_solutions` (nur authorized=1).
- **Zufallstests:** Fragenreihenfolge aus `tst_test_rnd_qst` (pro active_id+pass),
  sonst `tst_test_question`. Erkennung: `question_set_type` beginnt mit `RANDOM`.
- **Manuelles Feedback:** Tabelle heißt `tst_manual_fb` (nicht tst_manual_feedback!).
- **Gewerteter Durchlauf:** `tst_result_cache.pass`; Note aus `mark_official`/`mark_short`.
- **Bild-Inlining:** `Renderer::inlineImages()` bettet lokale Bilder (≤2 MB) als data-URIs
  ein; auflösbar sind ILIAS_HTTP_PATH-, absolute-Pfad- und relative URLs. Externe Hosts
  bleiben unangetastet.
- **PDF:** `wkhtmltopdf` (Suchpfade `/usr/bin`, `/usr/local/bin`); fehlt es, wird
  `pdf_enabled=0` gesetzt und das Archiv ist HTML-only (Warnhinweis in der Admin-Ansicht).
- **Ablage:** `CLIENT_DATA_DIR/plugins/ExtendedTestSettings/examarchiver/<obj_id>/`.
  Download streamt mit ob_end_clean-Schleife (Muster serveResourceFile im Proxy).
- **Löschen = Soft-Delete:** ZIP wird entfernt, DB-Zeile bleibt (`status=deleted`) —
  Nachweiskette. `verifyArchive` nutzt `hash_equals()` gegen `zip_sha256`.
- **Tabellennamen ≤ 22 Zeichen** (ILIAS-Limit): `examarchiver_archives` (21),
  `examarchiver_log` (16).

## GUI-Einstiege

| Kontext | Weg |
|---|---|
| Global | ConfigGUI-Tab „Archiv" (`showArchiver` → `renderAdminView()`) — Haupt-Repo-Commit |
| Per Test | Proxy `cmd=showArchives` (Link aus dem Handler-Formularabschnitt) |
| AJAX | `startArchive`, `processChunk`, `finalizeArchive`, `verifyArchive`, `deleteArchive` (POST, JSON) |
| Download | `downloadArchive` (GET, geloggt) |

Alle AJAX-URLs werden serverseitig gebaut (`proxyUrl()`, lowercase `cmdClass`) und als
`data-url` ins Markup gelegt — auch die globale Admin-Ansicht spricht pro Test den Proxy an,
dadurch braucht die ConfigGUI nur einen einzigen Command (`showArchiver`).

## Änderungen im Haupt-Repo (separater Commit)

- `classes/class.ilExtendedTestSettingsConfigGUI.php`: Tab `archiver`, Command
  `showArchiver`, `isExamArchiverInstalled()` + `showArchiver()` (Muster ExamKeymap/Logger).
- `lang/ilias_de|en.lang`: `tab_archiver`, `module_examarchiver_missing`.

## Debugging

- Error-Log-Prefix `[ExamArchiver]` (wkhtmltopdf-rc, getSolutionOutput-Fehler, markFailed).
- Hängengebliebene Läufe (`running`/`failed`): „Löschen" räumt auch das Arbeitsverzeichnis weg.
- `examarchiver_log` enthält jede Aktion mit User, IP, Zeitstempel.

## Changelog

| Datum | Was |
|---|---|
| 2026-07-02 | **v1.0.0:** Initiale Implementierung |
