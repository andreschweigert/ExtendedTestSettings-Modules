# ExamBattery — Entwicklerdokumentation

**Modul-ID:** `exambattery`
**Speicherort:** `modules/exambattery/` (externes Modul, nicht im Git-Repo)
**Referenz-ZIP:** `modules-zip/modules/exambattery/ExamBattery_v1.1.0.zip`
**Zuletzt aktualisiert:** 2026-07-03

---

## Was ist ExamBattery?

Stiller, rein passiver Akku-Monitor für Prüfungsgeräte (Anwendungsfall: ChromeOS-Koffergeräte
im Hörsaal ohne Steckdose). Das Testplayer-JS liest die Battery Status API aus und meldet
Stände still an den Server; eine Aufsichts-Ansicht zeigt pro Test die letzten Gerätestände
farbig sortiert. **Kern-Invariante: Im Testplayer entsteht niemals ein sichtbares UI-Element**
— kein Banner, kein DOM-Element, auch nicht bei 5 % Akku. Warnungen an Studierende sind
bewusst nicht vorgesehen (Design-Entscheidung: die Aufsicht entscheidet über Eingriffe).

**Abgrenzung zu ExamNetQos:** gleiche Grundarchitektur (Handler + ViewGUI + AJAX-Report über
den Proxy, `allow_student_access`), aber Upsert statt Zeitreihe — es zählt nur der letzte
Stand pro Gerät, keine Historie in v1.0.0.

---

## Dateistruktur

```
modules/exambattery/
├── module.json                          ← Modul-Metadaten, allow_student_access: true
├── class.ilExamBatteryHandler.php       ← Handler (implements ilExamModuleInterface)
├── class.ilExamBatteryViewGUI.php       ← AJAX-Endpoint (report) + Aufsichts-Ansicht (showStatus)
├── js/
│   └── exambattery.js                   ← Battery-API-Auslesung, Event- + Heartbeat-Reports
├── lang/
│   ├── ilias_de.lang                    ← Formular- und Tabellen-Texte DE
│   └── ilias_en.lang                    ← … EN
└── sql/
    ├── install.php                      ← Legt exambattery_settings + exambattery_status an
    └── uninstall.php                    ← Löscht beide Tabellen
```

Bewusst **kein CSS**: es gibt kein Testplayer-UI; die Aufsichts-Tabelle nutzt
Bootstrap-Klassen + Inline-Styles (Muster examnetqos).

---

## Datenfluss

```
Testplayer (Chromium, HTTPS)                       Server
────────────────────────────                       ──────
navigator.getBattery()
  ├─ API fehlt (Firefox/HTTP) → return (stumm, keine Requests)
  ├─ levelchange / chargingchange  ──┐
  ├─ initialer Report (nach 3 s)   ──┤
  └─ Heartbeat alle 180 s          ──┴─→ reportFromBattery()
                                          ├─ level=100 UND charging → verwerfen (Desktop)
                                          └─ fetch POST cmd=report ────→ handleReport()
                                                {level, charging}          ├─ POST only (405)
                                                                           ├─ canReport(): write ODER tst_active
                                                                           ├─ Validierung: is_int(level) 0–100,
                                                                           │  charging === 0|1, sonst 400
                                                                           └─ $db->replace() in exambattery_status
Aufsicht (Schreibrecht)
  cmd=showStatus ──────────────────────────────────→ showStatus()
                                                       ├─ ORDER BY level_pct ASC (kritisch oben)
                                                       ├─ Farbe: ≤crit rot, ≤warn gelb, sonst grün
                                                       ├─ tstamp älter 600 s → Zeile opacity 0.45
                                                       └─ JS-Reload alle 30 s
```

## JS-Details (`js/exambattery.js`)

- Config live über `cfg()` lesen (nie beim IIFE-Init cachen — `addJavaScript` läuft vor
  `addOnLoadCode`); Heartbeat-Timer wird immer aufgesetzt (selbst-reschedulendes
  `setTimeout`), auch wenn die Config beim ersten Tick leer ist.
- Desktop-Erkennung sitzt **pro Report** in `reportFromBattery()` (nicht einmalig beim
  Init): ein voll geladenes Notebook (`level=100`, lädt) meldet wieder, sobald es vom
  Netz getrennt wird und `levelchange` feuert.
- Fehlgeschlagene Reports: `catch` verwirft still — kein Retry, der nächste Heartbeat
  kommt ohnehin.
- Nach jeder Fragennavigation lädt ILIAS die Seite komplett neu → das IIFE initialisiert
  sich neu und meldet nach 3 s erneut. Kein sessionStorage nötig (Upsert, kein Session-Begriff).
- `window.ExamBattery.simulate(levelPct, charging)`: löst einen Report mit simulierten
  Werten aus (klemmt auf 0–100, umgeht die Desktop-Erkennung). Entwickler-Werkzeug für
  Instanzen ohne Battery-API.

## GUI-Details (`class.ilExamBatteryViewGUI.php`)

- `executeCommand()`: `cmd=report` → `handleReport()` (Studierenden-Zugriff über
  `allow_student_access`); alles andere verlangt Schreibrecht und rendert `showStatus`.
- **Wichtig:** Der Modul-Proxy druckt das Template NICHT selbst — `showStatus()` muss
  nach `setContent()` selbst `printToStdout()` aufrufen (Muster exampreflight), sonst
  bleibt die Seite weiß (war Bug in 1.0.0).
- `handleReport()` validiert hart: `$data['level']` muss `is_int` und 0–100 sein,
  `$data['charging']` exakt `0` oder `1` (strikte Typprüfung nach `json_decode`) —
  `level=999`, Floats oder Strings → HTTP 400. Zusätzlich `canReport()`:
  Schreibrecht ODER aktive Test-Session (`tst_active`), Muster examnetqos.
- Upsert per `$db->replace()` auf PK `(test_obj_id, user_id)`.
- Schwellwerte für die Einfärbung kommen aus `exambattery_settings` (Defaults 20/10,
  falls keine Zeile existiert).
- **Geräte-Spalte (ab 1.1.0):** `showStatus()` joint lesend gegen das zentrale
  Geräte-Inventar des Plugins (`etest_hosts`, Plugin ≥ 2.0.1, gepflegt im
  ConfigGUI-Tab „Geräte") über die IP. Join und Spalten sind mit
  `tableExists('etest_hosts')` geguarded — fehlt die Tabelle (Plugin noch nicht
  aktualisiert), zeigt die Spalte „—". Keinerlei Code-Abhängigkeit zu examlogger.

## Handler-Details (`class.ilExamBatteryHandler.php`)

- `injectAssets()` nur bei `isEnabled()` + Testplayer-Kontext; Config enthält **nicht**
  die Schwellwerte (das JS braucht sie nicht — reine Anzeige-Schwellen der Aufsichts-Ansicht).
- `saveSettings()`: klemmt warn auf 5–50, crit auf 5–30; bei `crit >= warn` fallen
  **beide** auf die Defaults (20/10) zurück.
- `getFormItems()`: Checkbox mit zwei Zahlen-Subitems + `ilCustomInputGUI` mit
  Proxy-Link auf `cmd=showStatus` (Muster exampreflight:
  `setParameterByClass`/`getLinkTargetByClass` über `['ilUIPluginRouterGUI',
  'ilExtendedTestSettingsModuleProxyGUI']`).

## Debugging

- `window.ExamBattery.config.debug = true` in der Konsole setzen → `[ExamBattery]`-Logs
  (Reports, Heartbeat-Start).
- Reports prüfen: `SELECT * FROM exambattery_status WHERE test_obj_id = <obj_id>;`
- Auf HTTP-Dev-Instanz gibt es kein `navigator.getBattery` → mit
  `window.ExamBattery.simulate(15, false)` testen.
- Fehlerlog-Prefix serverseitig: `[ExamBattery]`.

## Bekannte Grenzen (v1.0.0)

- Keine Historie — nur der letzte Stand pro Gerät (bewusst, Upsert).
- Kein ConfigGUI-Tab im Haupt-Repo (bewusst für v1.0.0; Ansicht ist pro Test über den
  Formular-Link erreichbar).
- Battery Status API liefert auf manchen Geräten nur grobe Stufen — für den Zweck
  (kritisch niedrige Akkus erkennen) ausreichend.
