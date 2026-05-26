# ExamConnGuard — Entwicklerdokumentation

**Modul-ID:** `examconnguard`  
**Speicherort:** `modules/examconnguard/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examconnguard/ExamConnGuard_v1.0.6.zip`  
**Zuletzt aktualisiert:** 2026-05-12

---

## Was ist ExamConnGuard?

Schützt Prüfungsteilnehmer vor Browser-Fehlerseiten ("Verbindung zurückgesetzt") bei
kurzen **WLAN-Ausfällen** im Test-Player. Gedacht für Hörsaal-Klausuren mit Chromebooks
in überlasteten WLANs.

Das Modul arbeitet **strikt reaktiv** — im Normalbetrieb läuft kein Hintergrund-Traffic.
Erst wenn ein Teilnehmer auf einen Button klickt oder einen Link folgt, wird eine schnelle
**Pre-Flight-Probe** gegen die mitgelieferte `probe.txt` abgesetzt. Bei Verbindungsverlust
erscheint ein Vollbild-Overlay (Shadow-DOM), die ausstehende Aktion wird gequeued und nach
erfolgreichem Reconnect automatisch ausgeführt.

**Wichtig:** Der `fetch()`-Wrapper für ILIAS-Autosaves triggert **nicht eigenständig** den
Outage-Modus — nur Submit/Klick-Events lösen die Probe aus. Das verhindert spurious-offline-
Zustände durch gecachte oder Service-Worker-Responses beim Autosave.

---

## Einschränkungen

- Nur im Testplayer aktiv — `isTestPlayerContext()` prüft `cmdClass` und URL-Pattern.
- **UX-Latenz** von 50–300 ms im Online-Fall pro Klick (Probe muss abgeschlossen sein).
  Im Offline-Fall bis zu 1200 ms Timeout. Betrifft alle Buttons + Anchors im Test-Player.
- Pre-Flight-Probe ist **keine 100%-Garantie**: Die Verbindung kann zwischen Probe (T+0)
  und tatsächlichem Submit (T+50 ms) wegfallen — das Race-Fenster ist klein, aber existent.
- Klick-Interceptor matched `<button>` und `<a href>`, **nicht** reine `<div onclick>`-Elemente.
  Im Standard-ILIAS-Test-Player nicht relevant.
- **Modifier-Klicks** (Strg+Klick, Cmd+Klick, Shift+Klick, Mittelklick) werden
  durchgelassen — Tabs in neuem Fenster funktionieren ungehindert.
- `fetch()`-Wrapper greift **nicht für XHR** — ILIAS-Autosaves via XHR werden nicht
  abgefangen (Autosave-Fehler bleiben stille Fehler).

---

## Dateistruktur

```
modules/examconnguard/
├── module.json                          ← Modul-Metadaten, require_files: []
├── class.ilExamConnGuardHandler.php    ← Handler (implements ilExamModuleInterface)
├── probe.txt                            ← 3-Byte-Probe-Datei ("ok"), Heartbeat-Ziel
├── css/
│   └── examconnguard.css               ← Fallback-Stile für Light-DOM-Rendering
├── js/
│   └── examconnguard.js                ← On-Demand-Probe, Interceptor, Overlay, fetch-Wrapper
└── sql/
    ├── install.php                      ← Legt examconnguard_settings an
    └── uninstall.php                    ← Löscht examconnguard_settings
```

---

## module.json

```json
{
    "id": "examconnguard",
    "label": "Verbindungs-Schutz",
    "description": "Fängt WLAN-Aussetzer im Test-Player ab. Pre-Flight-Probe bei jedem Submit/Klick verhindert Browser-Fehlerseiten. Bei Outage: Wartebild, queued Action, Auto-Replay nach Reconnect. Wrappt fetch() für Autosave-Retry.",
    "version": "1.0.6",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamConnGuardHandler",
    "handler_file": "class.ilExamConnGuardHandler.php",
    "require_files": [],
    "tables": [
        "examconnguard_settings"
    ]
}
```

Kein `gui_file`/`gui_class` — ExamConnGuard hat keine eigene Verwaltungs-GUI.
`require_files` ist leer — keine Abhängigkeiten außer dem Handler selbst.

---

## Datenbank

### `examconnguard_settings`

Speichert Verbindungsschutz-Konfiguration je Test. Primärschlüssel ist `test_id` — ein
Eintrag pro Test.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `enabled` | integer(1) | 1 = aktiv, 0 = inaktiv (Default: 0) |
| `updated_at` | timestamp | Audit-Timestamp, nullable |

Schreiben via `$db->replace()` mit `test_id` als Unique-Key — upsert-Semantik.
Kein automatischer Default-Eintrag bei neuen Tests (kein `ensureDefaultSettings()`).

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| *(nur via `ilObject::_lookupObjectId()`)* | `ref_id` → `obj_id` Auflösung |

---

## ilExamConnGuardHandler

Implementiert `ilExamModuleInterface`.

### Interface-Methoden

```
isEnabled(ref_id)         → liest examconnguard_settings.enabled;
                            bei fehlendem Eintrag oder Fehler: false
injectAssets(tpl, ref_id) → nur wenn isEnabled() && isTestPlayerContext():
                            - addOnLoadCode: window.ExamConnGuard.config (JSON) setzen
                            - addCss: examconnguard.css
                            - addJavaScript: examconnguard.js
saveSettings(ref_id, post)→ examconnguard_enabled aus POST (Checkbox),
                            db->replace() mit enabled + updated_at; gibt true zurück
getFormItems(ref_id)       → [ilCheckboxInputGUI(examconnguard_enabled)]
getInfo()                  → name, title, icon, description, version, features-Array
```

### Nicht-Interface-Methoden

```
isTestPlayerContext()      → prüft cmdClass (iltestplayer*, iltestoutputgui)
                            + URL-Pattern (cmd=startPlayer, cmd=resumePlayer,
                              cmdClass=ilTestPlayer)
```

### Asset-Injection im Detail

```php
// Reihenfolge ist entscheidend: Config VOR JS (addJavaScript läuft vor addOnLoadCode)
$tpl->addOnLoadCode("
    window.ExamConnGuard = window.ExamConnGuard || {};
    window.ExamConnGuard.config = {$config_json};
");

$tpl->addCss(".../modules/examconnguard/css/examconnguard.css");
$tpl->addJavaScript(".../modules/examconnguard/js/examconnguard.js");
```

> **Wichtig:** `addJavaScript` läuft serverseitig vor `addOnLoadCode` — deshalb muss
> die Config per `addOnLoadCode` gesetzt werden und darf im JS **nicht beim IIFE-Init
> gecacht** werden. `cfg()` im JS liest live aus `window.ExamConnGuard.config`.

### Injizierte Config-Keys

| Key | Wert (Konstante) | Bedeutung |
|---|---|---|
| `refId` | `$ref_id` | ILIAS ref_id des Tests |
| `heartbeatUrl` | `PROBE_PATH = '/modules/examconnguard/probe.txt'` | Probe-URL, immer vorhanden |
| `preflightTimeout` | `PREFLIGHT_TIMEOUT_MS = 1200` | Max. Wartezeit der Probe in ms |
| `reconnectIntervalMs` | `RECONNECT_INTERVAL_MS = 2000` | Heartbeat-Takt während Outage in ms |
| `reconnectGraceMs` | `RECONNECT_GRACE_MS = 800` | Stabilitätsfenster nach Reconnect in ms |
| `debug` | `false` | Verbose Logging in der Browser-Konsole |

---

## JavaScript-Logik (examconnguard.js)

### Ablauf im Normalbetrieb (Online)

```
1. Teilnehmer klickt Submit-Button oder Anchor-Link
2. Capture-Phase → preventDefault() + stopPropagation()
3. Pre-Flight-Probe: GET probe.txt mit Cache-Bust, Timeout 1200 ms
   + navigator.onLine-Veto (false → sofort offline, kein Fetch)
   + Body-Check: Response-Body muss "ok" sein
4. Probe ok → inReplay-Flag setzen → element.click() re-feuern
5. ILIAS-Handler läuft normal, keine wahrnehmbare Änderung für Teilnehmer
```

### Ablauf bei Verbindungsverlust

```
1. Probe fehlschlägt (Timeout, Netzwerkfehler oder Body ≠ "ok")
2. Outage-Modus: Vollbild-Overlay aufbauen (Shadow-DOM, Fallback Light-DOM)
   - Overlay-Watchdog: alle 500 ms prüfen ob Overlay noch im DOM
3. Aktion in pendingAction queuen
4. Reconnect-Heartbeat alle 2 s (nur jetzt aktiv)
5. Probe ok + 800 ms Stabilitätsfenster (zweite Probe bestätigt)
6. Overlay entfernen → pendingAction automatisch ausführen
```

### Ablauf bei hartem Browser-Offline-Event

```
1. navigator.onLine === false beim nächsten Submit/Klick
2. Sofortiger Outage-Eintritt ohne Probe-Latenz (kein 1200-ms-Warten)
3. Dann gleicher Reconnect-Ablauf wie oben
```

### fetch()-Wrapper

Überschreibt `window.fetch` global. Bei Netzwerkfehler (kein HTTP-Fehler):
- Exponential-Backoff: 1 s, 2 s, 4 s, 8 s, 16 s, 30 s (Cap), max 6 Versuche
- Triggert **nicht** eigenständig den Outage-Modus — nur Submit/Klick tun das

### Overlay-Watchdog

Alle 500 ms prüft ein `setInterval` ob das Overlay-Element noch im DOM vorhanden ist.
Falls ILIAS-DOM-Updates das Element entfernen, wird es neu angehängt.

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv nach Installation)
3. In den Erweiterten Test-Einstellungen eines Tests erscheint die Checkbox
   "Verbindungs-Schutz aktivieren"

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — `sql/install.php` läuft erneut
(durch `tableExists()`-Guard ohne Effekt wenn Tabelle bereits existiert).

### Verifikation

1. Modul aktivieren, in einem Test-Objekt anschalten
2. Test-Player starten (Studierenden-Account oder Vorschau)
3. `window.ExamConnGuard.config.debug = true` in der Konsole — verboses Logging aktiv
4. **Online-Fall:** Button klicken → 50–300 ms Lag (Probe), normaler Submit
5. **Offline-Fall:** WLAN aus → Button klicken → ~1,2 s Timeout → Overlay erscheint
6. **Reconnect:** WLAN an → max ~3 s bis Overlay weg und queued Submit ausgeführt
7. **beforeunload:** Während Outage F5 drücken → Browser-Bestätigungsdialog

---

## Changelog

| Datum | Was |
|---|---|
| 2026-05-12 | **v1.0.6:** ILIAS-Kompatibilität auf 9 beschränkt |
| 2026-05-11 | v1.0.5: `window.offline`-Listener entfernt. Overlay erscheint jetzt ausschließlich beim Submit/Klick-Versuch (strikt reaktiv). `navigator.onLine === false`-Shortcut in `dispatchAction` für sofortigen Outage ohne Probe-Latenz. |
| 2026-05-11 | v1.0.4: Root-Cause-Fix für spurious exit-outage: `cfg()` liest live aus `window.ExamConnGuard.config` (kein Caching beim IIFE-Init). Probe-Default bei leerer URL ist jetzt `resolve(false)`. Overlay-Watchdog alle 500 ms. Live-Debug via `config.debug` zur Laufzeit. |
| 2026-05-11 | v1.0.3: Probe gehärtet: `navigator.onLine`-Veto + GET mit Body-Check statt HEAD. |
| 2026-05-11 | v1.0.2: Klick-Interceptor auf alle Buttons + Anchors erweitert (ILIAS setzt `window.location.href` per onclick, kein submit-Event). |
| 2026-05-11 | v1.0.1: On-Demand-Probe statt Background-Heartbeat. Eigene `probe.txt`. fetch-Wrapper triggert nicht mehr eigenständig Offline-State. |
| 2026-05-11 | v1.0.0: Initiale Version mit Background-Heartbeat alle 8 s. |
