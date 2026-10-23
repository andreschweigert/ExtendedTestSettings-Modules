# ExamNetQos — Entwicklerdokumentation

**Modul-ID:** `examnetqos`  
**Speicherort:** `modules/examnetqos/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examnetqos/ExamNetQos_v1.2.1.zip`
**Zuletzt aktualisiert:** 2026-10-23

---

## Was ist ExamNetQos?

Passiver clientseitiger Netzwerk-Monitor für den ILIAS-Test-Player. Misst RTT und
optionalen Paketverlust im Hintergrund ohne Eingriff in die Test-Logik. Erkennt
Schwellwert-Überschreitungen automatisch als Ereignisse und speichert eine Zeitreihe
zur späteren Korrelation mit Teilnehmer-Beschwerden.

**Abgrenzung zu ExamConnGuard:** ExamConnGuard ist reaktiv (Probe nur bei Submit/Klick,
zeigt Overlay). ExamNetQos ist passiv (kontinuierlicher Hintergrund-Timer, kein UI).
Beide Module können gleichzeitig aktiv sein ohne sich zu stören.

---

## Dateistruktur

```
modules/examnetqos/
├── module.json                         ← Modul-Metadaten, allow_student_access: true
├── class.ilExamNetQosHandler.php      ← Handler (implements ilExamModuleInterface)
├── class.ilExamNetQosViewGUI.php      ← AJAX-Endpoint (report) + Admin-Chart-GUI
├── probe.txt                           ← Probe-Zieldatei ("ok"), wie bei ExamConnGuard
├── css/
│   └── examnetqos.css                  ← Platzhalter (kein Testplayer-UI)
├── js/
│   └── examnetqos.js                   ← Hintergrund-Mess-Loop, Batch-Reporting
└── sql/
    ├── install.php                     ← Legt examnetqos_settings + examnetqos_events an
    └── uninstall.php                   ← Löscht beide Tabellen
```

---

## module.json

```json
{
    "id": "examnetqos",
    "label": "Netzwerk-Qualität",
    "handler_class": "ilExamNetQosHandler",
    "handler_file": "class.ilExamNetQosHandler.php",
    "gui_file": "class.ilExamNetQosViewGUI.php",
    "gui_class": "ilExamNetQosViewGUI",
    "allow_student_access": true,
    "tables": ["examnetqos_settings", "examnetqos_events"]
}
```

**`allow_student_access: true`** ist nötig, damit Teilnehmer den `cmd=report`-Endpoint
über den `ModuleProxyGUI` erreichen können (nur Lese-Recht erforderlich).
Innerhalb von `handleReport()` wird zusätzlich geprüft, ob die Messung für den Test
aktiviert ist und der Teilnehmer einen offenen, nicht abgegebenen Testlauf hat.
Schreibberechtigte können weiterhin Testvorschau-Messwerte melden. Read-Recht
allein reicht nicht. Die Ansicht und der CSV-Export erzwingen für
Nicht-System-Admins die Test-`ref_id` aus dem autorisierten Proxy-Aufruf; ein
`filter_ref_id` kann diese Grenze nicht ersetzen. CSV-Zellen mit Formelanfang
werden als Text ausgegeben.

---

## Datenbank

### `examnetqos_settings`

Konfiguration je Test, upsert via `$db->replace()`.

| Spalte | Typ | Default | Beschreibung |
|---|---|---|---|
| `test_id` | integer(4) PK | — | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 0 | 1 = aktiv |
| `interval_seconds` | integer(2) | 10 | Mess-Intervall in Sekunden (5–60) |
| `rtt_warn_ms` | integer(4) | 500 | RTT-Warnschwelle für `degraded`-Event |
| `rtt_crit_ms` | integer(4) | 2000 | RTT-Schwelle für `critical`-Event |
| `loss_enabled` | integer(1) | 1 | 1 = Paketverlust-Messung aktiv |
| `loss_probes` | integer(1) | 3 | Anzahl paralleler Probes (2–10) |
| `updated_at` | timestamp | null | Audit-Timestamp |

### `examnetqos_events`

Zeitreihe aller Messwerte. Insert-only, kein Update/Delete im normalen Betrieb.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | integer(4) PK | Auto-Increment via `$db->nextId()` |
| `test_obj_id` | integer(4) | ILIAS `obj_id` des Tests |
| `test_ref_id` | integer(4) | ILIAS `ref_id` des Tests (für Joins ohne obj→ref Lookup) |
| `user_id` | integer(4) | Teilnehmer-ID |
| `session_key` | text(32) | Client-Key aus `sessionStorage`, stabil pro Browser-Tab + Test-Ref, gruppiert Messungen zu Sessions |
| `event_type` | text(16) | `measurement`, `degraded`, `critical`, `recovered`, `reconnect` |
| `rtt_ms` | integer(4) | RTT in ms; **0 = alle Probes timed out** |
| `loss_pct` | integer(2) | Paketverlust 0–100; **-1 = Messung deaktiviert** (single probe) |
| `conn_type` | text(16) | `navigator.connection.effectiveType` (z.B. `4g`, leer wenn API nicht verfügbar) |
| `ip_addr` | text(45) | Client-IP (X-Forwarded-For berücksichtigt) |
| `tstamp` | integer(4) | Unix-Timestamp; Client-Wert ±1h akzeptiert, sonst server-seitig |

**Sentinel-Werte:** `rtt_ms = 0` bedeutet Probe-Timeout (kein echter 0ms-RTT möglich).
`loss_pct = -1` bedeutet Messung nicht durchgeführt (loss_enabled = false oder single probe).

**Indizes:** `test_ref_id` (i1), `user_id` (i2), `session_key` (i3), `tstamp` (i4), `event_type` (i5).

---

## ilExamNetQosHandler

Implementiert `ilExamModuleInterface`.

### Interface-Methoden

```
isEnabled(ref_id)          → liest examnetqos_settings.enabled;
                             bei fehlendem Eintrag: false
injectAssets(tpl, ref_id) → nur wenn isEnabled() && isTestPlayerContext():
                             - addOnLoadCode: window.ExamNetQos.config (JSON)
                             - addCss: examnetqos.css
                             - addJavaScript: examnetqos.js
saveSettings(ref_id, post) → liest examnetqos_enabled, examnetqos_interval,
                             examnetqos_rtt_warn, examnetqos_rtt_crit,
                             examnetqos_loss_enabled, examnetqos_loss_probes aus POST;
                             db->replace() mit allen Settings + updated_at
getFormItems(ref_id)       → [ilCheckboxInputGUI] mit Sub-Items:
                             ilSelectInputGUI (interval), ilNumberInputGUI (rtt_warn),
                             ilNumberInputGUI (rtt_crit), ilCheckboxInputGUI (loss_enabled)
                             mit Sub-Item ilNumberInputGUI (loss_probes)
getInfo()                  → name, title, icon, description, version, features-Array
```

### Nicht-Interface-Methoden

```
getSettings(ref_id)        → liest examnetqos_settings, gibt Defaults zurück wenn nicht vorhanden
isTestPlayerContext()      → prüft cmdClass (iltestplayer*, iltestoutputgui)
                             + URL-Pattern (cmd=startPlayer, cmd=resumePlayer)
```

### Injizierte Config-Keys

| Key | Quelle | Bedeutung |
|---|---|---|
| `refId` | `$ref_id` | ILIAS ref_id |
| `probeUrl` | `plugin_path + /modules/examnetqos/probe.txt` | HEAD-Probe-Ziel |
| `reportUrl` | `ilias.php?baseClass=ilUIPluginRouterGUI&cmdClass=ilExtendedTestSettingsModuleProxyGUI&module_id=examnetqos&cmd=report&ref_id=X` | AJAX-Endpoint |
| `intervalSeconds` | Settings | Mess-Intervall |
| `rttWarnMs` | Settings | Warnschwelle |
| `rttCritMs` | Settings | Kritisch-Schwelle |
| `lossEnabled` | Settings | Paketverlust-Messung an/aus |
| `lossProbes` | Settings | Anzahl parallele Probes |
| `debug` | `false` (hardcoded) | Verbose Console-Logging |

> **Reihenfolge-Pflicht:** `addOnLoadCode` (Config) muss vor `addJavaScript` (JS) stehen,
> weil ILIAS `addJavaScript` zuerst in den `<head>` schreibt. Das JS liest die Config
> per `cfg()`-Funktion live — niemals beim IIFE-Init cachen.

---

## ilExamNetQosViewGUI

Zentraler Dispatcher: AJAX-Endpoint für Teilnehmer + Admin-GUI.

### Command-Routing

```
executeCommand()
├── cmd=report    → handleReport()   — POST, Teilnehmer, JSON-Response, exit
├── cmd=exportcsv → exportCsv()      — GET, Admin, CSV, exit
└── *             → showChart()      — GET, Admin, ILIAS-Template
```

Auth-Check in `executeCommand()`:
- `cmd=report`: kein zusätzlicher Check hier (ModuleProxyGUI + `allow_student_access`
  hat bereits Read-Recht geprüft). `handleReport()` prüft intern aktive Test-Session.
- Alle anderen cmds: `$rbacsystem->checkAccess('write', $ref_id)`.

### handleReport()

AJAX-Endpoint für clientseitige Mess-Batches.

**Eingangsvalidierung:**
- `user_id > 0 && ref_id > 0` (Basis-Sanity)
- `canReport()`: Write-Recht ODER aktive `tst_active`-Session für den Test
- Body muss gültiges JSON mit `events`-Array sein
- Max. 20 Events pro Batch (Rest ignoriert)

**Pro Event:**
- `event_type` muss in Whitelist `[measurement, degraded, critical, recovered, reconnect]`
- `rtt_ms`: `max(0, min(99999, int))`
- `loss_pct`: `max(-1, min(100, int))`
- `conn_type`: nur `[a-z0-9-]`, max 16 Zeichen
- `ts` (Client-Timestamp): akzeptiert wenn innerhalb `[now-3600, now+60]`, sonst `time()`
- `session_key`: `[a-z0-9]`, max 32 Zeichen

Antwortet mit `{"ok": true, "saved": N}` oder HTTP 400/403 + JSON-Error.

### showChart() — Übersichts-Ansicht

1. Events per SQL (max 3000) laden, nach (user_id, session_key, test_ref_id) gruppieren
2. Übersichts-Tabelle: eine Zeile pro Session mit Miniatur-Sparkline (160×36px SVG)
3. Detail-Link: fügt `filter_session_key` zur URL hinzu

### showChart() — Detail-Ansicht (wenn filter_session_key gesetzt)

1. Alle Events der Session laden
2. Per-Test-Settings für Schwellwerte laden
3. Vollständiger SVG-Zeitstrahl (900×200px viewBox):
   - Farbige Threshold-Bänder (grün/gelb/rot)
   - RTT-Polyline als farbige Segmente (Farbe = Avg beider Endpunkte vs. Schwellwerte)
   - Messpunkte als Kreise mit `<title>`-Tooltip (Zeit + RTT)
   - Nicht-Measurement-Events: gestrichelte farbige Linie + kleiner Kreis (r=4) am Kopf
     mit `<title>`-Tooltip (Zeit + Label) — kein Text im SVG (verhindert Label-Überlappung)
4. Roh-Ereignis-Tabelle darunter
5. Zurück-Link (entfernt `filter_session_key` aus URL)

---

## JavaScript-Logik (examnetqos.js)

### Session-Key

Stabiler 16-Zeichen-String pro Browser-Tab und Test, gespeichert in `sessionStorage`:

```javascript
var STORAGE_KEY = 'examnetqos_sk_' + _refFromUrl;   // _refFromUrl aus URL-Parameter ref_id
var sessionKey = sessionStorage.getItem(STORAGE_KEY) || generateAndStore();
```

`sessionStorage` überlebt Seiten-Navigationen innerhalb desselben Browser-Tabs
(ILIAS-Tests laden die Seite bei jeder Fragennavigation neu) und wird bei neuem Tab/Fenster
oder Browser-Neustart geleert. Der `ref_id`-Suffix im Storage-Key verhindert Key-Kollisionen
wenn zwei Tests im selben Tab geöffnet werden. Fallback auf `Math.random()` wenn
`sessionStorage` blockiert ist (z.B. Browser-Privacy-Modus).

Wird mit jedem Batch-POST mitgesendet und serverseitig in `examnetqos_events.session_key`
gespeichert. Gruppiert alle Messungen einer Prüfungs-Sitzung zu einer Session in der Admin-Ansicht.

### Mess-Ablauf

```
init → setTimeout(measure, 3000)   ← Erst nach 3 s (Seite soll fertig laden)
     → setInterval(measure, interval_s * 1000)

measure():
  n = lossEnabled ? lossProbes : 1
  für jede Probe i in [0, n):
    t0 = Date.now()
    HEAD probe.txt?_nqos=<ts>&_p=i  (Timeout = max(rttCritMs*2, 5000) ms)
    bei ok:  resolve(Date.now() - t0)   ← RTT in ms
    bei err: resolve(-1)                ← Fehler-Sentinel

  Promise.all(alle Probes):
    successful = results wo r >= 0
    rtt      = avg(successful) oder 0  (0 = alle gefailt)
    loss_pct = round((n - successful.length) / n * 100) oder -1 (single probe)
    → onMeasured(rtt, loss_pct)
```

### Status-Tracking und Ereignis-Erkennung

```
lastStatus: 'ok' | 'degraded' | 'critical'

onMeasured(rtt, loss_pct):
  newStatus = rtt===0 → 'critical'
            | rtt>=rttCritMs → 'critical'
            | rtt>=rttWarnMs → 'degraded'
            | else → 'ok'

  pushEvent('measurement', rtt, loss_pct)        ← immer

  if newStatus !== lastStatus:
    newStatus === 'ok'       → pushEvent('recovered', ...)
    newStatus === 'degraded' → pushEvent('degraded', ...)
    newStatus === 'critical' → pushEvent('critical', ...)
    lastStatus = newStatus

  if pendingEvents.length >= 5 OR seit 30 s kein Flush:
    flush()
```

### Fetch-Strategie

**Probes:** `getOrigFetch()` — benutzt `window.ExamConnGuard._origFetch` wenn verfügbar,
sonst `window.fetch`. Bypass des ExamConnGuard-Wrappers, damit Probe-Timeouts sofort
zurückkommen (kein Backoff-Retry).

**Report-POST:** `window.fetch` — der ExamConnGuard-Wrapper ist erwünscht, da Backoff
bei kurzem Netzausfall für Report-Batches sinnvoll ist.

**beforeunload:** `navigator.sendBeacon(url, new Blob([json], {type: 'application/json'}))` —
zuverlässige Übertragung des letzten Batches beim Seitenverlassen, auch beim Tab-Schließen.

### ExamConnGuard-Integration

```javascript
document.addEventListener('examconnguard:reconnect', function() {
    pushEvent('reconnect', 0, -1);
    flush();
});
```

Ab ExamConnGuard **v1.0.7** dispatcht `exitOutage()` dieses CustomEvent direkt vor
`replayQueued()`. Der Flush passiert vor der Seiten-Navigation, sodass der Reconnect-Zeitpunkt
korrekt in der DB landet. Wenn beide Module aktiv sind, erscheinen `reconnect`-Events
im Admin-Zeitstrahl als blaue Markierungen.

---

## AJAX-Endpoint-URL

Konstruiert in `ilExamNetQosHandler::injectAssets()`:

```php
'reportUrl' => 'ilias.php?' . http_build_query([
    'baseClass'  => 'ilUIPluginRouterGUI',
    'cmdClass'   => 'ilExtendedTestSettingsModuleProxyGUI',
    'module_id'  => 'examnetqos',
    'cmd'        => 'report',
    'ref_id'     => $ref_id,
])
```

Relativer URL — funktioniert, weil der Test-Player unter `ilias.php?...` liegt (gleiche
Basis). `ModuleProxyGUI` leitet an `ilExamNetQosViewGUI::executeCommand()` weiter.

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren
3. In den Erweiterten Test-Einstellungen erscheint die Sektion „Netzwerk-Qualitätsmessung"

### Update

Gleicher Vorgang — `sql/install.php` läuft erneut, `tableExists()`-Guards verhindern
doppelte Tabellen-Erstellung.

---

## Debugging

```javascript
// In der Browser-Konsole während der Prüfung:
window.ExamNetQos.config.debug = true   // Live-Logging aktivieren
window.ExamNetQos.config                 // Aktuelle Config anzeigen
```

Mit `debug = true` erscheinen Logs der Form:
```
[ExamNetQos] started interval=10s warn=500ms crit=2000ms
[ExamNetQos] measured rtt=42ms loss=0% status=ok
[ExamNetQos] degraded
[ExamNetQos] measured rtt=1823ms loss=33% status=degraded
[ExamNetQos] critical
[ExamNetQos] flush failed, 3 events re-queued
```

---

## Bekannte Einschränkungen

- **Probe-Granularität:** Eine Messung alle 10 s bedeutet, kurze Aussetzer unter 10 s
  können verpasst werden. Für feinere Auflösung Intervall auf 5 s setzen (mehr Last).
- **RTT misst Roundtrip zum Plugin-Server**, nicht zum ILIAS-Server selbst. In
  Standard-Setups identisch; bei CDN oder Edge-Caching kann es abweichen.
- **Paketverlust ist simuliert:** Parallele HEAD-Requests auf dieselbe URL messen, ob
  TCP-Verbindungen aufgebaut werden — kein echter ICMP-Paketverlust.
- **`navigator.connection`** wird nur von Chrome/Edge unterstützt (Chromium-Basis).
  Firefox/Safari liefern keine `effectiveType`-Information.
- **Client-Timestamps:** Wenn die Systemuhr des Teilnehmers stark abweicht,
  werden Server-Timestamps verwendet (±1h-Toleranz).
- **sendBeacon + `application/json`:** Einige ältere Browser (Firefox < 85, Safari < 14)
  ignorieren den Content-Type des Blobs bei `sendBeacon` — der PHP-Endpoint liest
  `php://input` direkt, was für alle Browsers funktioniert.

---

## Changelog

| Datum | Was |
|---|---|
| 2026-05-28 | **v1.0.1:** Detail-Link hatte fehlendes `cmd`-Parameter → Seite landete bei Default-Route. `sessionKey` via `sessionStorage` statt `Math.random()` pro Seitenaufruf (ILIAS lädt bei jeder Frage neu → viele Pseudo-Sessions). SVG-Event-Labels entfernt (Überlappung bei dichten Events), ersetzt durch farbige Kreise + `<title>`-Tooltip. `'degraded'`-Label von 'Verschlechterung' in 'Erhöht' umbenannt (semantisch korrekt für beide Übergänge ok→degraded und critical→degraded). |
| 2026-05-28 | **v1.0.0:** Erstveröffentlichung |
