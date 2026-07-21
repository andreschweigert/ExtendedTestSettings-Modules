# ExamNetQos

![Entwicklungsstand: Experimentell](https://img.shields.io/badge/Entwicklungsstand-Experimentell-orange)

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Misst **Latenz (RTT) und Paketverlust** clientseitig im Hintergrund während der Prüfung —
ohne sichtbaren Eingriff in den Test-Player. Schwellwert-Überschreitungen werden automatisch
als Ereignisse gespeichert und können in einer Admin-Zeitstrahl-Ansicht mit Teilnehmer-
Beschwerden korreliert werden.

## Anwendungsfall

Ein Teilnehmer meldet nach der Prüfung: *"Die Seite hat sich nicht geladen, ich konnte eine
Frage nicht speichern."*  
Mit ExamNetQos kann der Admin sofort prüfen: Gab es zu diesem Zeitpunkt tatsächlich einen
RTT-Spike oder Paketverlust — oder lief das Netz einwandfrei?

## Funktionsweise

1. **Hintergrund-Messung** (konfigurierbar, Standard 10 s):  
   HEAD-Request gegen `probe.txt` im Modul → RTT-Messung durch Timing.
2. **Paketverlust** (optional): N parallele HEAD-Requests → Anteil fehlgeschlagener Probes.
3. **`navigator.connection`** wird opportunistisch ausgelesen (kein Extra-Request).
4. **Ereignis-Erkennung**: RTT > Warnschwelle → `degraded`, RTT > Kritisch-Schwelle → `critical`,
   Erholung → `recovered`. Jeder Statuswechsel wird separat gespeichert.
5. **Batch-Reporting**: Messwerte werden gesammelt und alle 5 Messungen (oder spätestens nach 30 s)
   per `fetch()` POST an den PHP-Endpoint gesendet. Beim Seitenverlassen: `sendBeacon()`.
6. **Kein Eingriff in Submit/Navigation** — passiver Monitor, der ExamConnGuard nicht beeinflusst.

## Admin-Ansicht

`Administration → Plugins → ExtendedTestSettings → Module → examnetqos`

- Übersichtstabelle aller Sessions (User, Zeit, Ø RTT, Max RTT, Ereignisse, Miniatur-Sparkline)
- Detailansicht pro Session: SVG-Zeitstrahl mit farbiger RTT-Kurve und Ereignis-Markierungen
- CSV-Export aller Messwerte

## Konfiguration pro Test

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | POST-Key | Default |
|---|---|---|
| Netzwerk-Qualitätsmessung aktivieren | `examnetqos_enabled` | — |
| Messintervall (s) | `examnetqos_interval` | 10 s |
| RTT Warnschwelle (ms) | `examnetqos_rtt_warn` | 500 ms |
| RTT Kritisch-Schwelle (ms) | `examnetqos_rtt_crit` | 2000 ms |
| Paketverlust-Messung | `examnetqos_loss_enabled` | aktiviert |
| Anzahl Parallel-Probes | `examnetqos_loss_probes` | 3 |

## Datenbank

Eigene Tabellen: `examnetqos_settings`, `examnetqos_events`

**`examnetqos_settings`** — Konfiguration je Test:

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `interval_seconds` | Mess-Intervall in Sekunden (5–60) |
| `rtt_warn_ms` | RTT-Warnschwelle in ms |
| `rtt_crit_ms` | RTT-Kritisch-Schwelle in ms |
| `loss_enabled` | 1 = Paketverlust-Messung aktiv |
| `loss_probes` | Anzahl paralleler Probes (2–10) |

**`examnetqos_events`** — Zeitreihe der Messwerte:

| Spalte | Bedeutung |
|---|---|
| `id` (PK) | Auto-Increment |
| `test_obj_id` | ILIAS obj_id des Tests |
| `test_ref_id` | ILIAS ref_id des Tests |
| `user_id` | Teilnehmer-ID |
| `session_key` | Client-Key aus `sessionStorage`, stabil pro Browser-Tab + Test (gruppiert Messungen einer Prüfungs-Sitzung) |
| `event_type` | `measurement`, `degraded`, `critical`, `recovered`, `reconnect` |
| `rtt_ms` | RTT in ms; 0 = Probe-Timeout |
| `loss_pct` | Paketverlust 0–100; -1 = Messung deaktiviert |
| `conn_type` | `navigator.connection.effectiveType` (z.B. `4g`, `3g`) |
| `ip_addr` | Client-IP |
| `tstamp` | Unix-Timestamp |

## Ereignis-Typen

| Typ | Anzeige-Label | Bedeutung |
|---|---|---|
| `measurement` | Messung | Reguläre periodische Messung (immer vorhanden) |
| `degraded` | Erhöht | RTT hat Warnschwelle überschritten (Statuswechsel, in beide Richtungen) |
| `critical` | Kritisch | RTT hat Kritisch-Schwelle überschritten oder Probe-Timeout |
| `recovered` | Wiederhergestellt | RTT wieder unterhalb der Warnschwelle |
| `reconnect` | Reconnect (ConnGuard) | Signal von ExamConnGuard ab v1.0.7 (wenn beide Module aktiv) |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `js/examnetqos.js` | Hintergrund-Mess-Loop, Batch-Reporting, Ereignis-Erkennung |
| `css/examnetqos.css` | Platzhalter (kein sichtbares UI im Testplayer) |
| `probe.txt` | Probe-Zieldatei für HEAD-Requests |

Assets werden nur injiziert wenn `isEnabled() && isTestPlayerContext()`.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.2.0 | `ExamNetQos_v1.2.0.zip` | 9 | i18n DE/EN von Handler und ViewGUI (Chart, Sessions-Tabelle, CSV) |
| 1.1.0 | `archive/ExamNetQos_v1.1.0.zip` | 9 | Modul-i18n DE/EN (Handler) |
| 1.0.1 | `ExamNetQos_v1.0.1.zip` | 9 | Detail-Link Bug fix (fehlender `cmd`-Parameter). Session-Key via `sessionStorage` (verhindert Aufsplittung in viele Kurz-Sessions bei ILIAS-Fragennavigation). SVG-Event-Labels durch Kreise + Hover-Tooltip ersetzt. `degraded`-Label: 'Verschlechterung' → 'Erhöht'. |
| 1.0.0 | `ExamNetQos_v1.0.0.zip` | 9 | Erstveröffentlichung |

## Installation

ZIP über die ExtendedTestSettings-Admin-UI hochladen, Modul aktivieren,
dann pro Test in den Erweiterten Test-Einstellungen anschalten.

## Test-Anleitung

1. Modul installieren und aktivieren, in einem Test-Objekt anschalten.
2. Test-Player starten (Studierenden-Account oder Vorschau).
3. Browser-Konsole: `window.ExamNetQos.config.debug = true` — verbose Logs erscheinen.
4. Nach 3 s erste Messung in der Konsole: `[ExamNetQos] measured rtt=Xms loss=Y%`.
5. Im Admin: Module-GUI öffnen, Test filtern → Sessions erscheinen nach der ersten Messung.
6. Netzwerk-Simulation: In DevTools → Network → Throttling auf "Slow 3G" stellen.
   Nächste Messung sollte `degraded` oder `critical` auslösen.
7. Throttling deaktivieren → `recovered` erscheint in der Admin-Ansicht.

## Entwickler-Dokumentation

→ [`/docs/modules/ExamNetQos.md`](../../docs/modules/ExamNetQos.md)
