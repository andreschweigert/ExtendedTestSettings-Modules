# ExamConnGuard

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Fängt WLAN-Aussetzer im Test-Player ab und verhindert Browser-Fehlerseiten ("Verbindung zurückgesetzt"). Gedacht für Hörsaal-Klausuren mit Chromebooks im überlasteten WLAN.

## Funktionsweise (ab 1.0.1: On-Demand-Probe)

1. **Im Normalbetrieb läuft kein Hintergrund-Heartbeat** — null Dauertraffic.
2. **Bei jedem Submit oder Anchor-Klick**: Capture-Phase → `preventDefault()` → schnelle Pre-Flight-Probe (HEAD auf `/<plugin-path>/modules/examconnguard/probe.txt` mit Cache-Bust, Timeout 1200 ms).
   - Probe **ok** → Action wird sofort durchgelassen (replay über `inReplay`-Flag, vermeidet Listener-Loop).
   - Probe **fehlschlägt** → Outage-Modus aktivieren: Vollbild-Overlay (Shadow-DOM) zeigen, Action queuen.
3. **Im Outage-Modus** läuft ein Reconnect-Heartbeat alle 2 s. Sobald eine Probe ok ist + 800 ms Stabilitätsfenster mit zweiter bestätigter Probe → Overlay weg, queued Action automatisch ausführen.
4. **`fetch()` global gewrappt** mit Exponential-Backoff (max 6 Versuche, Cap 30 s) für ILIAS-Autosaves. Wrapper triggert **nicht eigenständig** den Outage-Modus — das war der spurious-offline-Bug in 1.0.0.
5. **`beforeunload`-Schutz** während Outage: F5/Strg+R löst Browser-Bestätigungsdialog aus.
6. **Browser `offline`-Event** als zusätzlicher Hint — wenn der Browser hart "offline" sagt, sofort Outage rauf ohne Probe.

## Konfiguration

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Verbindungs-Schutz aktivieren | `examconnguard_enabled` | Checkbox |

JS-Defaults sind im Handler hardcoded und werden per `addOnLoadCode()` als `window.ExamConnGuard.config` gesetzt:

| Key | Default | Bedeutung |
|---|---|---|
| `heartbeatUrl` | `<plugin>/modules/examconnguard/probe.txt` | Probe-URL — Datei wird mit dem ZIP ausgeliefert, immer vorhanden |
| `preflightTimeout` | `1200` | Pre-Flight-Probe-Timeout (ms) — bestimmt UX-Latenz im Offline-Fall |
| `reconnectIntervalMs` | `2000` | Reconnect-Heartbeat-Intervall (nur während Outage) |
| `reconnectGraceMs` | `800` | Stabilitätsfenster nach Reconnect (zweite Probe muss ebenfalls ok sein) |
| `debug` | `false` | Verbose Logging in der Konsole |

Im Browser zur Laufzeit umstellbar via `window.ExamConnGuard.config.debug = true` + Reload.

## Datenbank

Eigene Tabelle: `examconnguard_settings`

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `updated_at` | Audit-Timestamp (nullable) |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `js/examconnguard.js` | On-Demand-Probe, Submit/Click-Interceptor, Overlay, fetch-Wrapper |
| `css/examconnguard.css` | Fallback-Stile, falls Browser keine Shadow-DOM unterstützt |
| `probe.txt` | 3-Byte-Datei für die Probe-URL |

Assets werden nur injiziert, wenn `isEnabled() && isTestPlayerContext()` — kein Performance-Impact außerhalb des Test-Players.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.0.6 | `ExamConnGuard_v1.0.6.zip` | 9 | ILIAS-Kompatibilität auf 9 beschränkt |
| 1.0.0 | `ExamConnGuard_v1.0.0.zip` | 9–12 | Initiale Version mit Background-Heartbeat alle 8 s gegen `/favicon.ico`. Bekannte Bugs: spurious offline durch fetch-Wrapper, kaputt wenn Server kein favicon hat. |
| 1.0.1 | `ExamConnGuard_v1.0.1.zip` | 9–12 | **On-Demand-Probe** statt Background-Heartbeat. Eigene `probe.txt` im Modul (kein Server-Default mehr). fetch-Wrapper triggert nicht mehr eigenständig Offline-State (Fix 2-min-Bug). Klick-Interceptor nur noch für `<a href>` (Submits gehen über submit-Event, kein Doppel-Queue). |
| 1.0.2 | `ExamConnGuard_v1.0.2.zip` | 9–12 | Klick-Interceptor erweitert auf **alle Buttons + Anchors**. Notwendig weil ILIAS-Test-Player-Buttons mit JS-onclick `window.location.href = …` setzen (CSRF-Token in URL) — kein submit-Event, kein `assign()`/`replace()`. Bei Probe-OK wird der Original-Click via `element.click()` mit `inReplay`-Flag re-feuert; ILIAS-Handler läuft normal. UI-Buttons (Frage markieren etc.) bekommen dadurch ebenfalls 50–300 ms Pre-Flight-Latenz. |
| 1.0.3 | `ExamConnGuard_v1.0.3.zip` | 9–12 | Probe gehärtet gegen falsche "online"-Antworten. **`navigator.onLine`-Veto**: probe gibt sofort `false` zurück, wenn der Browser explizit offline meldet — verhindert spurious exit-outage durch cached/Service-Worker-Responses. **GET + Body-Check** statt HEAD: probe verifiziert dass Response-Body `ok` ist, fängt cached Responses mit fremdem Body ab. |
| 1.0.4 | `ExamConnGuard_v1.0.4.zip` | 9–12 | **Root-Cause-Fix für 1.0.0–1.0.3 spurious exit.** ILIAS rendert `addJavaScript` vor `addOnLoadCode` — `cfg` wurde beim IIFE-Init mit leerer `heartbeatUrl` gecached, später kam die echte Config nicht mehr an. Probe-Default bei leerer URL war `resolve(true)` → Reconnect-Heartbeat hat nach 2.8 s exit-outage gerufen, ohne je eine echte Probe abgesetzt zu haben. **Fix**: `cfg()` ist jetzt eine Funktion, liest live aus `window.ExamConnGuard.config`. Probe-Default bei leerer URL ist nun `resolve(false)` (sicher). **Plus Watchdog**: alle 500 ms wird geprüft ob das Overlay-Element noch im DOM ist — falls nicht, wird es neu angehängt (Sicherheitsnetz gegen ILIAS-DOM-Updates die unser Element raushauen). **Plus Live-Debug**: `window.ExamConnGuard.config.debug = true` zur Laufzeit greift jetzt sofort. |
| 1.0.5 | `ExamConnGuard_v1.0.5.zip` | 9–12 | **`window.offline`-Listener entfernt.** Vorher zeigte das Overlay sofort beim Verbindungsverlust, auch wenn der Studi nur tippte. Jetzt strict reaktiv: Overlay erscheint ausschließlich beim Submit/Klick-Versuch. Während der Eingabephase ist die Verbindung egal — sie wird nur beim Verlassen der Frage gebraucht. `dispatchAction` hat einen `navigator.onLine === false`-Shortcut für sofortige Outage ohne Probe-Latenz, falls der Browser explizit offline meldet. |

## Bekannte Einschränkungen

- **UX-Latenz pro Klick** (ab 1.0.2): 50–300 ms im Online-Fall, bis 1200 ms im Offline-Fall. Trifft **alle Buttons + Anchors** im Test-Player, auch reine UI-Buttons (Frage markieren etc.). Bei 30–40 Klicks/Klausur summiert sich das auf wenige Sekunden — vertretbar.
- **Pre-Flight ist keine 100%-Garantie**: WLAN kann zwischen Probe (T+0) und tatsächlichem Submit (T+50 ms) wegfallen. Verkleinert das Race-Fenster, schließt es nicht vollständig.
- **Klick-Interceptor matched Buttons + Anchors**, nicht aber reine `<div onclick>`-Custom-Elemente. Im Standard-ILIAS-Test-Player nicht relevant.
- **Modifier-Klicks ignoriert**: Strg+Klick, Cmd+Klick, Shift+Klick, Mittelklick werden durchgelassen (für Studis die einen Link in neuem Tab öffnen wollen).
- **fetch()-Wrapper greift nicht für XHR**. ILIAS-Autosaves laufen historisch über XHR; falls Autosaves trotzdem failen sollen sie das tun. Submit-/Click-Queue + Overlay decken den kritischen Pfad ab.

## Stil-Abweichungen vom Briefing (zugunsten Plugin-Konvention)

| Briefing | Plugin-Konvention (übernommen) | Begründung |
|---|---|---|
| `js/exam-connection-guard.js` (Bindestriche) | `js/examconnguard.js` (token-id) | Alle bestehenden Module nutzen `js/<id>.js` ohne Bindestriche. |
| `css/exam-connection-guard.css` | `css/examconnguard.css` | Wie oben. |
| `window.ExamConnGuardConfig` | `window.ExamConnGuard.config` | `examfullscreen` nutzt `window.ExamFullscreen.config` — gleiches Pattern. |
| ZIP nach `modules-zip/<Name>_v<v>.zip` | `modules-zip/modules/<id>/<Name>_v<v>.zip` | Repo-Layout gruppiert ZIPs nach Modul-Unterordner; `README.md` und Kopie von `module.json` liegen daneben. |

## Installation

ZIP über die ExtendedTestSettings-Admin-UI hochladen, Modul aktivieren, dann pro Test in den Erweiterten Test-Einstellungen anschalten.

## Test-Anleitung

1. Modul installieren und aktivieren, in einem Test-Objekt anschalten.
2. Test-Player starten (Studierenden-Account oder Vorschau).
3. Browser-Konsole öffnen, optional `window.ExamConnGuard.config.debug = true` setzen für verbose Logs.
4. Verifikation Online-Fall: "Weiter"-Button klicken → kurzer Lag (~50–300 ms für Probe), dann normaler Submit.
5. Verifikation Offline-Fall: WLAN aus → "Weiter" klicken → ~1.2 s Probe-Timeout → Overlay erscheint, Action ist gequeued.
6. WLAN wieder an → max ~3 s später (Reconnect-Probe + Grace) verschwindet das Overlay und der queued Submit wird ausgeführt.
7. Während Outage F5 drücken → Browser-Bestätigungsdialog erscheint.
