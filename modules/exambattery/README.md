# ExamBattery

![Entwicklungsstand: Im Test](https://img.shields.io/badge/Entwicklungsstand-Im%20Test-blue)

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

**Stiller Akku-Monitor für Prüfungsgeräte:** meldet Akkustände der Geräte im Testplayer
still an den Server und zeigt sie der Aufsicht in einer Live-Tabelle. **Beim Studierenden
erscheint niemals eine Meldung oder ein UI-Element** — das ist eine bewusste
Design-Entscheidung: die Aufsicht erkennt kritische Geräte und entscheidet selbst, ob sie
eingreift (Ladegerät bringen, Gerät tauschen), ohne Studierende während der Prüfung zu
beunruhigen.

## Anwendungsfall

Prüfungen im Hörsaal mit ChromeOS-Koffergeräten, die ohne Steckdose auf Akku laufen.
Ein leerlaufender Akku mitten in der Prüfung ist ein echter Prüfungsvorfall — bisher
merkte es niemand, bevor das Gerät ausging. Mit ExamBattery sieht die Aufsicht kritische
Geräte, bevor sie ausfallen.

## Funktionsweise

1. **Battery Status API** (`navigator.getBattery()`): Das JS liest den Akkustand rein
   passiv aus. Gemeldet wird **gedrosselt** — nur wenn der Akkustand gefallen ist,
   der Ladezustand gewechselt hat oder der Heartbeat fällig ist (höchstens alle
   5 Minuten). Der letzte Report-Stand liegt im `sessionStorage` und überlebt die
   Seitenneuladungen bei der Fragennavigation — Fragenwechsel erzeugen keine
   eigenen Reports.
2. **Report**: `fetch` POST an den Modul-Proxy (`cmd=report`), Payload
   `{level: 0–100, charging: 0|1, remaining: Minuten|-1}`. Fehlgeschlagene Reports
   werden still verworfen (kein Retry-Sturm — der nächste Heartbeat kommt ohnehin).
3. **Upsert**: Pro (Test, Teilnehmer) zählt nur der letzte Stand — keine Historie.
4. **Aufsichts-Ansicht** (`cmd=showStatus`, Schreibrecht): Tabelle aller gemeldeten
   Geräte, aufsteigend nach Akkustand (kritische oben), farbig nach den Test-Schwellen
   (grün/gelb/rot), mit Restzeit, Ladezustand, Gerät, IP-Adresse und Zeitpunkt der
   letzten Meldung; Spalten per Klick sortierbar. Aktualisierung **per Button**
   (mit „Stand: hh:mm:ss"-Anzeige); Meldungen älter als 10 Minuten werden ausgegraut
   (Gerät evtl. aus oder Teilnehmer/in fertig).
5. **Geräte-Spalte**: Ist im zentralen Geräte-Inventar des Plugins (`etest_hosts`,
   Administration → Plugins → ExtendedTestSettings → Tab „Geräte", ab Plugin 2.0.1)
   eine Zuordnung zur IP gepflegt, zeigt die Spalte „Koffer · Gerät-Nr" (Tooltip:
   ILI-Nr + IP) — sonst „—".

## Voraussetzungen & bewusste Grenzen

- **HTTPS erforderlich:** `navigator.getBattery()` gibt es nur im Secure Context.
  Auf der HTTP-Dev-Instanz liefert die API nichts — das Modul bleibt dort stumm
  (keine Fehler, keine Requests). Zum Testen dort `simulate()` verwenden (s. u.).
- **Nur Chromium-Browser** (Chrome, Edge, ChromeOS): Firefox hat die API entfernt —
  das Modul bleibt dort komplett stumm.
- **Desktop-Erkennung:** Geräte ohne Akku melden `level = 100 %` und „lädt" — solche
  Stände werden clientseitig verworfen. Bei normalen Tests am Arbeitsplatz-PC entfaltet
  das Modul keinerlei Aktivität.
- **Keinerlei Anzeige für Studierende:** kein Banner, kein Button, kein DOM-Element —
  auch nicht bei 5 % Akku. Warnungen an Studierende sind bewusst nicht vorgesehen.

## Entwickler-Helfer: simulate()

Für Tests ohne echtes Akku-Gerät steht in der Browser-Konsole des Testplayers bereit:

```js
window.ExamBattery.simulate(15, false);      // meldet 15 %, lädt nicht → gelb
window.ExamBattery.simulate(8, false);       // meldet 8 %  → rot, ganz oben
window.ExamBattery.simulate(80, true);       // meldet 80 %, lädt → grün
window.ExamBattery.simulate(25, false, 45);  // 25 %, Restzeit ~45 min (≤ 30 min → rot)
```

`simulate()` löst einen echten Report mit den angegebenen Werten aus und umgeht die
Desktop-Erkennung. Das dritte Argument (Restzeit in Minuten) ist optional, Default -1
(unbekannt).

## Konfiguration pro Test

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | POST-Key | Default |
|---|---|---|
| Akku-Monitor aktivieren | `exambattery_enabled` | — |
| Gelb-Schwelle (%) | `exambattery_warn_pct` | 20 (5–50) |
| Rot-Schwelle (%) | `exambattery_crit_pct` | 10 (5–30) |

Die Schwellen steuern **nur die Einfärbung der Aufsichts-Ansicht**. Beim Speichern wird
erzwungen: Rot-Schwelle < Gelb-Schwelle, sonst fallen beide auf die Defaults zurück.

Der Formular-Abschnitt enthält außerdem den Link **„Gerätestände anzeigen"** zur
Aufsichts-Ansicht.

## Datenbank

Eigene Tabellen: `exambattery_settings`, `exambattery_status`

**`exambattery_settings`** — Konfiguration je Test:

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `warn_pct` | Gelb-Schwelle in % (Anzeige) |
| `crit_pct` | Rot-Schwelle in % (Anzeige) |
| `updated_at` | letzte Änderung |

**`exambattery_status`** — letzter gemeldeter Stand je Gerät (Upsert, keine Historie):

| Spalte | Bedeutung |
|---|---|
| `test_obj_id` (PK) | ILIAS obj_id des Tests |
| `user_id` (PK) | Teilnehmer/in |
| `level_pct` | Akkustand 0–100 |
| `charging` | 1 = lädt gerade |
| `discharging_min` | Geschätzte Restzeit in Minuten (Battery-API), -1 = unbekannt |
| `ref_level` / `ref_tstamp` | Referenzpunkt (Akkustand + Zeit) beim Beginn des Entladens — Basis für den serverseitigen Restzeit-Fallback |
| `ip_addr` | Client-IP |
| `test_ref_id` | ref_id des Tests |
| `tstamp` | Zeitpunkt der letzten Meldung (Unix) |

## Sicherheit

- `cmd=report` (POST, `allow_student_access`): zusätzlich zur Proxy-Prüfung wird eine
  aktive Test-Session (`tst_active`) oder Schreibrecht verlangt. Payload wird hart
  validiert: `level` ganzzahlig 0–100, `charging` exakt 0/1 — alles andere → HTTP 400.
- `cmd=showStatus`: nur mit Schreibrecht auf den Test erreichbar.

## Versionshistorie

| Version | Datum | Änderung |
|---|---|---|
| 1.3.3 | 2026-10-26 | AGPL-3.0-only: Lizenz- und Autorenhinweise im ZIP; Fremdkomponenten gesondert ausgewiesen |
| 1.3.2 | 2026-07-03 | Restzeit-Fallback: liefert die Battery-API keine Schätzung (viele Geräte melden dauerhaft Infinity), wird die Restzeit serverseitig aus der bisherigen Entladerate gerechnet (Referenzpunkt `ref_level`/`ref_tstamp`, greift nach ≥ 5 min und ≥ 2 % Abfall; mit * und Tooltip markiert) |
| 1.3.1 | 2026-07-03 | Reports gedrosselt: nur bei fallendem Akkustand, Ladezustand-Wechsel oder 5-min-Heartbeat (sessionStorage überlebt Fragennavigation — kein Report mehr pro Fragenwechsel); Auto-Refresh der Aufsichts-Ansicht durch „Aktualisieren"-Button mit Stand-Anzeige ersetzt |
| 1.3.0 | 2026-07-03 | Spalten sortierbar (klickbare Köpfe); neue Spalte „Restzeit (ca.)" aus der Battery-API (`dischargingTime`, neue DB-Spalte `discharging_min`, ≤ 30 min rot); `simulate(level, charging, restMin)` erweitert |
| 1.2.0 | 2026-07-03 | Aufsichts-Ansicht als reguläre ILIAS-Seite: Brotkrumen-Pfad zum Test (Locator), Test-Icon, Seitentitel/-beschreibung, KS-Panel um die Tabelle, MessageBox bei „keine Daten", Akkustand als farbiger Fortschrittsbalken |
| 1.1.0 | 2026-07-03 | Geräte-Spalte (Koffer · Gerät-Nr) aus dem zentralen Geräte-Inventar des Plugins (`etest_hosts`, Plugin ≥ 2.0.1), Guard bei fehlender Tabelle |
| 1.0.1 | 2026-07-03 | Fix: Aufsichts-Ansicht war weiß (fehlendes printToStdout — der Proxy druckt das Template nicht selbst); Zurück-Link zum Test ergänzt |
| 1.0.0 | 2026-07-03 | Erstversion: stille Akku-Reports (Events + 3-min-Heartbeat), Aufsichts-Ansicht mit Farb-Schwellen, Auto-Refresh und Veraltet-Markierung, simulate()-Helfer |
