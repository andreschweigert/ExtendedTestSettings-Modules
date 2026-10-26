# ExamPreflight — Entwicklerdokumentation

**Modul-ID:** `exampreflight`
**Speicherort:** `modules/exampreflight/` (externes Modul)
**Referenz-ZIP:** `modules-zip/modules/exampreflight/ExamPreflight_v1.2.3.zip`
**Zuletzt aktualisiert:** 2026-07-02

---

## Was ist ExamPreflight?

Geführter Probedurchlauf ("Preflight" wie beim Flugzeug) durch einen Test vor der
Prüfung. Während ExamChecker die **Einstellungen** gegen konfigurierte Soll-Werte prüft, prüft
ExamPreflight das tatsächliche **Rendering** jeder Frage: Bilder, Video/Audio,
Formeln, Layout — inklusive Screenshot pro Frage für den Prüfbericht.

**Architektur-Grundidee:** Der Testplayer läuft in einem **same-origin iframe**
innerhalb einer Cockpit-Seite (Modul-GUI). Das Cockpit-JS greift von außen auf das
iframe-DOM zu, führt die Checks aus, macht Screenshots über die Screen-Capture-API
und klickt selbst auf „Weiter". Dadurch:

- **kein** Code-Injection in den Testplayer (`isEnabled()` → immer `false`,
  `injectAssets()` no-op) — echte Prüfungsdurchläufe bleiben unberührt
- die Screen-Capture-Freigabe überlebt die Player-Navigation, weil die Cockpit-Seite
  (Inhaberin des MediaStream) nie neu lädt — bei Injection ins Player-Fenster würde
  jede Fragen-Navigation den Stream beenden

---

## Dateistruktur

```
modules/exampreflight/
├── module.json                        ← gui_file/gui_class gesetzt, 2 Tabellen
├── class.ilExamPreflightHandler.php   ← Handler: nur Buttons in den Test-Einstellungen
├── class.ilExamPreflightGUI.php       ← Cockpit + AJAX-Endpunkte + Prüfbericht
├── js/exampreflight.js                ← gesamte Durchlauf-Logik (Zustandsmaschine)
├── css/exampreflight.css              ← Cockpit + Berichts-Styles
├── lang/ilias_de.lang, ilias_en.lang
└── sql/install.php, uninstall.php
```

---

## Datenbank

### `exampreflight_runs` — ein Datensatz pro Durchlauf

| Spalte | Inhalt |
|---|---|
| `run_id` (PK, Sequence) | Lauf-ID |
| `test_obj_id`, `test_ref_id` | Test-Zuordnung |
| `user_id` | Prüfer |
| `status` | `running` / `completed` / `aborted` |
| `started_at`, `finished_at` | Unix-Timestamps |
| `questions_checked`, `error_count`, `warning_count` | Aggregation (bei finishRun serverseitig aus results berechnet) |
| `meta` (clob) | JSON: userAgent, viewport, screenshots ja/nein |

### `exampreflight_results` — ein Datensatz pro geprüfter Frage

| Spalte | Inhalt |
|---|---|
| `result_id` (PK, Sequence), `run_id` (Index) | Zuordnung |
| `position` | laufende Nummer im Durchlauf |
| `title`, `qtype` | Fragentitel (aus DOM), heuristischer Fragetyp |
| `status` | `ok` / `warn` / `fail` (serverseitig aus den Befunden abgeleitet) |
| `error_count`, `warning_count` | Befund-Zählung |
| `checks` (clob) | JSON-Array der Befunde `{check, level, message, detail}` |
| `page_url` | Player-URL der Frage |
| `shot_file`, `shot_w`, `shot_h` | Screenshot-Dateiname (serverseitig generiert: `qNNN.jpg`) + Maße |

### Screenshots (Dateisystem)

```
<CLIENT_DATA_DIR>/plugins/ExtendedTestSettings/exampreflight/<test_obj_id>/<run_id>/qNNN.jpg
```

Gelöscht bei „Lauf löschen" und bei Modul-Deinstallation (`sql/uninstall.php`).

---

## GUI-Kommandos (`class.ilExamPreflightGUI.php`)

Alle über den Proxy geroutet, **alle** erfordern Schreibrecht am Test
(kein `allow_student_access`):

| cmd | Art | Zweck |
|---|---|---|
| `show` | GET | Cockpit-Seite (Statusleiste + iframe) |
| `startRun` | POST (AJAX) | Lauf anlegen → `{runId}` |
| `report` | POST (AJAX, multipart) | Fragen-Ergebnis + Screenshot speichern |
| `finishRun` | POST (AJAX) | Lauf abschließen (`completed`/`aborted`), Aggregation |
| `showReport` | GET | Läufe-Tabelle + Fragen-Karten des gewählten Laufs |
| `screenshot` | GET | Screenshot ausliefern (`result_id`, Zugehörigkeit wird geprüft) |
| `deleteRun` | **POST** | Lauf + Screenshots löschen — CSRF-geschützt via Session-Token (`epf_token`) |

Sicherheitsdetails:
- `report`/`finishRun` binden den Lauf an `run_id + test_obj_id + user_id + status='running'`
  (`getOwnRunningRun()`) — fremde/abgeschlossene Läufe sind nicht beschreibbar
- Screenshot-Dateiname wird **serverseitig** erzeugt; Upload max. 8 MB, `is_uploaded_file`
- `screenshot`/`deleteRun` verifizieren, dass der Lauf zum per Proxy autorisierten Test gehört

---

## Cockpit-JS: Zustandsmaschine

```
idle → (Start-Klick: optional getDisplayMedia + startRun) → waiting
waiting → (iframe lädt Fragenseite: #taForm + .tst_question-container) → running
running → pro Frage: waitForMedia → runChecks → takeScreenshot → uploadResult → clickNext
running ⇄ paused   (Pause-Button; Resume auf bereits verarbeiteter Seite → nur clickNext)
running → done      (kein Weiter-Button mehr / Abschlussseite erreicht → finishRun)
running → aborted   (Stop-Button oder Schleifenerkennung)
```

Wichtige Mechanik:
- **Duplikat-/Schleifenschutz:** `pageKey` = `sequence`-Parameter + Titel. Gleiche Seite
  erneut geladen → einmal erneut „Weiter" versuchen, beim zweiten Mal Lauf abbrechen.
- **Modal-Handling:** Wenn ILIAS statt zu navigieren einen Bestätigungs-Modal zeigt
  (z.B. „Antwort festschreiben"), wird nach 2 s dessen Primär-Button geklickt.
  Passiert 8 s gar nichts → Pause statt Hängen.
- **Der Test wird nie automatisch abgegeben** — nach der letzten Frage endet nur der Lauf.

### Checks pro Frage

| check | Level | Bedingung |
|---|---|---|
| `broken_image` | error | `img.complete && naturalWidth === 0` |
| `slow_image` | warning | Bild nach Wartezeit (10 s Cap) nicht fertig |
| `broken_media` | error | `media.error` oder `NETWORK_NO_SOURCE` |
| `slow_media` | warning | `readyState === 0` nach Wartezeit |
| `empty_video` | error | Metadaten da, aber `videoWidth/Height === 0` |
| `empty_iframe` / `external_iframe` | warning / info | leerer bzw. cross-origin Frame — nur sichtbare Frames (>10×10 px) mit echter `src` (unsichtbare Hilfs-iframes wie TinyMCE werden ignoriert) |
| `math_error` | error | `merror, .MathJax_Error, mjx-merror` vorhanden |
| `raw_tex` | error | unverarbeiteter Formel-Code (`[tex]`, `\begin{`, `\(…\)`) im sichtbaren Fließtext (`proseText()` — ohne `<pre>`/`<code>`/`<textarea>`) |
| `broken_bg_image` | error/warning | CSS-`background-image` liefert HTTP ≥ 400 bzw. ist nicht ladbar (HEAD-Request, same-origin, max. 10 URLs/Frage) |
| `sparse_options` | warning | `<select>` mit < 2 Optionen oder Radio-Gruppe mit nur einer Option (Import-Artefakt) |
| `encoding_error` | warning | Mojibake/Ersatzzeichen im Fließtext (`Ã¤`, `â€“`, `�` …) |
| `raw_html` | warning | HTML-Tags/Entities als sichtbarer Text (doppelt escaped), ohne Code-Blöcke |
| `tiny_font` | warning | sichtbare Elemente mit `font-size` < 9 px und eigenem Text |
| `low_contrast` | warning | WCAG-Kontrast Text/wirksamer Hintergrund < 1.5:1 (Eltern-Kette, bei Bild-Hintergrund übersprungen) |
| `h_overflow` | warning | `scrollWidth > clientWidth` am Delos-Scroll-Container |
| `protruding` | warning | Elemente ragen > 12 px aus `.tst_question-container` |
| `empty_question` | error | Fragenbereich ohne Text und ohne interaktive Elemente |
| `missing_questiontext` | error | `.ilc_qtitle_Title` (Formelfrage: `.ilc_question_FormulaQuestion > .ilc_qanswer_Answer`) leer und ohne Bild/Formel |
| `short_questiontext` | warning | Fragentext unter 5 Wörtern (ohne Medien) — evtl. unvollständig |
| `http_error` | error | `PerformanceResourceTiming.responseStatus >= 400` (nur neuere Browser) |
| `js_error` | warning | `window.onerror` im iframe (ab Seiten-Load) |

### Screenshot-Mechanik (Screen-Capture-API)

- `getDisplayMedia({preferCurrentTab: true, selfBrowserSurface: 'include'})` — ein
  Freigabe-Dialog beim Start (Chrome/Edge schlagen den eigenen Tab vor). Ablehnung
  → Lauf läuft ohne Screenshots weiter.
- **Delos-Detail:** ILIAS 9 scrollt nicht im `document`, sondern in
  `.il-layout-page-content`. Die Segmentierung scrollt daher **diesen Container** im
  iframe durch (`getScroller()`), erfasst pro Segment den sichtbaren Container-Bereich
  aus dem Video-Frame und setzt die Segmente auf einem Canvas zur vollen Fragenhöhe
  zusammen (max. `maxSegments` = 5). Koordinaten-Mapping:
  `scale = videoWidth / window.innerWidth` (≈ devicePixelRatio bei Tab-Capture).
- Ausgabe: JPEG (Qualität 0.85) via `canvas.toBlob`, Upload als multipart FormData.

---

## Grenzen / bewusste Entscheidungen

- **Testlauf-Daten:** Der Durchlauf erzeugt einen echten Testlauf (`tst_active`) für den
  Prüfer. Bewusst kein automatisches Löschen (keine Schreibzugriffe auf ILIAS-Kerntabellen
  per Konvention) — Hinweis mit Handlungsanweisung erscheint im Bericht und in den
  Einstellungen.
- **Screenshots in Firefox:** kein `preferCurrentTab` — Nutzer muss im Picker das
  Browserfenster wählen; Mapping stimmt dann nur näherungsweise. Empfehlung: Chrome/Edge.
- **`http_error`-Check** ist opportunistisch (`responseStatus` erst ab Chrome 109+).
- **JS-Fehler beim Seitenaufbau** (vor dem `load`-Event) werden nicht erfasst — der
  Listener wird erst nach dem Load angehängt.
- Zwischenseiten (Passwort, Einleitung, Zugangscode) werden nicht geprüft — das Cockpit
  wartet, bis der Prüfer sie durchklickt.
- Fragentyp-Erkennung ist eine grobe DOM-Heuristik (radio→SingleChoice etc.), rein
  informativ.

---

## Changelog

| Datum | Was |
|---|---|
| 2026-07-02 | **v1.2.2:** Härtung nach zwei Code-Reviews. **HOCH:** `clickNext`-Modal-Fallback prüft jetzt echte Sichtbarkeit (`getComputedStyle`/`dialog.open`, nicht nur Inline-Style) und ignoriert Buttons mit `finish/suspend/submit test` → kein versehentliches Test-Beenden mehr. **JS-Logik:** Zombie-Lauf nach fehlgeschlagenem `startRun` (runId=0+stopCapture im catch); Resume auf Nicht-Fragenseite ruft `onFrameLoad`; pageKey neu berechnet nach `waitForMedia` (stale-doc/Doppel-Upload) + Sequence aus Form-Action/DOM-Fallback (Kollision); Lazy-Offscreen-Bilder nicht als `slow_image`; Pause während Verarbeitung respektiert; `uploadResult`-Fehler → `warn`+Hinweis; Restart erst nach `deleteOwnAttempt`; Screenshot-Ränder (srcLeft/effWidth statt schwarzem Balken); eigener Cap `summaryJumps` für Fragenübersicht. **Check-FPs:** `raw_html`-Regex (Ungleichungen `a<b`), `checkOptions` (Long-Menu/Kprim), `checkBgImages` GET+Range statt HEAD, `checkQuestionText` akzeptiert iframe/object/embed. **Security:** CSRF-Token auf `startRun`/`report`/`finishRun` (vorher nur delete\*); Screenshot-Upload nur echte JPEGs (`getimagesize`+`IMAGETYPE_JPEG`); `X-Content-Type-Options: nosniff` + `Content-Disposition` bei Auslieferung und JSON; `Cache-Control: no-store` auf der Cockpit-Seite (Passwort im HTML); Debug-`error_log` (Header-/Cookie-Leak) im Proxy entfernt (Haupt-Repo) |
| 2026-07-02 | **v1.2.1:** Auto-Start-Fixes nach Live-Test: Launcher-Selektor `.c-launcher` (nicht `.il-launcher`); Start-Modal-Formular wird DIREKT befüllt+submitted (nur `.c-launcher form` — ein breiterer Selektor hatte das „Test beenden"-Modal erwischt und den Test abgegeben!); harte Player-Sperre in `tryAutoAdvance()` (auf `iltestoutput`/`iltestplayer`-Seiten keine Auto-Aktionen, einzige Ausnahme: Fragenübersicht → GET-Link `cmd=showQuestion`); Zyklus-Erkennung via `seenPages` (Fragenübersicht am Ende → Sprung zu Frage 1 → Lauf wird als completed beendet statt Endlosschleife). Neue Checks: `missing_questiontext` (error) + `short_questiontext` (< 5 Wörter, warning) |
| 2026-07-02 | **v1.2.0:** Auto-Start via `tryAutoAdvance()` — klickt Start-/Resume-Links (`cmd=startTest/startPlayer/resumePlayer`), bedient den ILIAS-9-TestScreen-Modal-Launcher (`launcher_id=exam_modal`: Passwort-Autofill aus `tst_tests.password`, Pflicht-Checkboxen wie `exam_conditions`) und die klassische Passwort-Seite (`input[name=password]`); Caps: 5 Auto-Start-Versuche, 2 Passwort-Versuche. Neu: `cmd=deleteOwnAttempt` (POST + `epf_token`) löscht NUR den Testlauf des aktuellen Nutzers via `ilObjTest::removeTestResultsByUserIds()` + `removeTestActives()` (gleiche API wie „Teilnehmerdaten löschen"); Cockpit-Checkbox „Testlauf danach löschen" (Default an) |
| 2026-07-02 | **v1.1.1:** `setupNavigation()` — `setBackTarget('← <Test>', ilRepositoryGUI)` + Tabs `epf_cockpit`/`epf_report` (Muster: ExamGrouping); Capture-Hinweis unterscheidet „abgelehnt" von „nicht verfügbar (kein HTTPS/Secure Context)" |
| 2026-07-02 | **v1.1.0:** 7 neue Check-Typen: `raw_tex`, `broken_bg_image` (async HEAD → `runChecks()` gibt jetzt Promise zurück), `sparse_options`, `encoding_error`, `raw_html`, `tiny_font`, `low_contrast`; Text-Checks laufen über `proseText()` (ignoriert `<pre>`/`<code>`/`<textarea>` gegen False Positives bei Programmier-Fragen) |
| 2026-07-02 | **v1.0.1:** `empty_iframe`-Check nur noch für sichtbare Frames (>10×10 px) mit echter `src` (nicht leer, nicht `about:blank`) — vorher False Positive auf jeder Fragenseite durch unsichtbare Hilfs-iframes (TinyMCE bzw. andere Module) |
| 2026-07-02 | **v1.0.0:** Initiale Version — Cockpit mit iframe-Durchlauf, 12 Check-Typen, segmentierte Screenshots via Screen-Capture-API, Prüfbericht mit Fragen-Karten, CSRF-geschütztes Lauf-Löschen |
