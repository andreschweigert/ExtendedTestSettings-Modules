# ExamMessage — Entwicklerdokumentation

**Modul-ID:** `exammessage`  
**Speicherort:** `modules/exammessage/` (externes Modul)  
**Referenz-ZIP:** `modules-zip/modules/exammessage/ExamMessage_v1.5.0.zip`  
**ILIAS-Kompatibilität:** 9  
**Zuletzt aktualisiert:** 2026-06-02

---

## Was ist ExamMessage?

Ermöglicht dem Kursadministrator, während einer laufenden Prüfung eine **Echtzeit-Nachricht
an alle Teilnehmer** zu senden. Die Nachricht erscheint im Test-Player als fixiertes Banner
am oberen Rand der Seite — zugestellt per **AJAX-Polling alle 15 Sekunden**, ohne dass die
Studierenden die Seite neu laden müssen. Eine automatische Countdown-Anzeige zeigt die
verbleibende Anzeigedauer; nach Ablauf verschwindet das Banner von selbst.

Drei Nachrichtentypen steuern die visuelle Gewichtung:  
Info (blau) · Warnung (orange) · Dringend (rot)

---

## Inspiration / Hintergrund

Die Idee und das Grundkonzept dieses Moduls stammen aus dem eigenständigen ILIAS-Plugin
**ExamNotifications** von [@iliasfhbi](https://github.com/iliasfhbi):

> https://github.com/iliasfhbi/ExamNotifications

ExamMessage ist eine **Reimplementierung** dieses Konzepts als integriertes Modul im
ExtendedTestSettings-Plugin. Wesentliche Unterschiede zur Vorlage:

- Kein eigenständiges Plugin — läuft als Modul innerhalb des ExtendedTestSettings-Modulsystems
- AJAX-Polling statt reinem Seitenaufruf (Nachrichten ohne Reload sichtbar)
- Drei Nachrichtentypen (Info / Warnung / Dringend) mit eigenem Styling
- Versand-Log in separater DB-Tabelle
- Direkt-Senden-Button ohne allgemeinen Formular-Save
- Auth über RBAC-`read` (Kern-Proxy); zusätzlich seit 1.5.0 ein Zustell-Gate auf
  `tst_active.submitted = 0` (nur an Teilnehmer mit offenem Testlauf)
- Wegklick-Persistenz clientseitig via `localStorage` (kein Server-State, seit 1.5.0)

---

## Dateistruktur

```
modules/exammessage/
├── module.json                      ← Modul-Metadaten, allow_student_access: true
├── class.ilExamMessageHandler.php   ← Handler (implements ilExamModuleInterface)
├── class.ilExamMessageGUI.php       ← Poll-Endpoint (JSON, no HTML output) + userHasActivePass()
├── lang/
│   ├── ilias_de.lang                ← deutsche Strings (seit 1.4.0)
│   └── ilias_en.lang                ← englische Strings (seit 1.4.0)
├── css/
│   └── exammessage.css              ← Banner-Styles (3 Typen, Countdown, High-Contrast)
├── js/
│   └── exammessage.js               ← tryStart-Retry, poll(), showBanner(), Countdown
└── sql/
    ├── install.php                  ← Legt exammessage_settings + exammessage_log an
    └── uninstall.php                ← Löscht beide Tabellen
```

**Auth/Routing-Klasse (im Kern-Plugin, nicht im Modul-ZIP):**

```
classes/class.ilExtendedTestSettingsModuleProxyGUI.php
  └── executeCommand()   ← Routing + Auth für alle Modul-GUIs inkl. poll
```

---

## module.json

```json
{
    "id": "exammessage",
    "label": "Prüfungs-Nachricht",
    "description": "Sendet eine Nachricht an alle Teilnehmer, die für eine konfigurierbare Dauer im Test-Player erscheint. AJAX-Polling alle 15 Sekunden für Echtzeit-Zustellung.",
    "version": "1.5.0",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamMessageHandler",
    "handler_file": "class.ilExamMessageHandler.php",
    "gui_file": "class.ilExamMessageGUI.php",
    "gui_class": "ilExamMessageGUI",
    "allow_student_access": true,
    "require_files": [],
    "tables": [
        "exammessage_settings",
        "exammessage_log"
    ]
}
```

`allow_student_access: true` signalisiert dem `ilExtendedTestSettingsModuleProxyGUI`,
dass der Poll-Endpoint für Nutzer mit `read`-Zugriff (Studierende) erreichbar ist —
nicht nur für Schreibberechtigte.

---

## Datenbank

### `exammessage_settings`

Aktuelle Konfiguration je Test. Primärschlüssel ist `test_id`.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `test_id` | integer(4) PK | ILIAS `obj_id` des Tests |
| `message_text` | text(2000) | Nachrichtentext (einfaches HTML) |
| `duration` | integer(4) | Anzeigedauer in Sekunden |
| `sent_at` | integer(4) | Unix-Timestamp des Versands; 0 = keine aktive Nachricht |
| `sent_by` | text(255) | ILIAS-Login des Versenders |
| `type` | integer(1) | 0 = Info, 1 = Warnung, 2 = Dringend |

Schreiben via `$db->replace()` mit `test_id` als Unique-Key — upsert-Semantik.

### `exammessage_log`

Versand-Protokoll. Jeder Send-Vorgang erzeugt einen neuen Eintrag.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | integer(4) PK | `$db->nextId('exammessage_log')` |
| `test_id` | integer(4) | ILIAS `obj_id` des Tests |
| `message_text` | text(2000) | Nachrichtentext zum Versandzeitpunkt |
| `duration` | integer(4) | Anzeigedauer in Sekunden |
| `sent_at` | integer(4) | Unix-Timestamp |
| `sent_by` | text(255) | ILIAS-Login |
| `type` | integer(1) | 0 / 1 / 2 |

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| *(via `ilObject::_lookupObjectId()`)* | `ref_id` → `obj_id` |
| `tst_active` ⋈ `tst_tests` | **Seit 1.5.0:** `userHasActivePass()` prüft `tst_active.submitted = 0` (offener Testlauf) als Zustell-Gate. Join `tst_active.test_fi = tst_tests.test_id`, Filter `tst_tests.obj_fi = <obj_id>`. |

**Warum `submitted` und nicht `last_finished_pass`?** `submitted` startet beim Testeinstieg
auf 0 und wird ausschließlich durch `ilTestPassFinishTasks` (Finish-Button / Auswertung /
Cron für verwaiste Pässe) auf 1 gesetzt — und nie zurück. Während der laufenden Bearbeitung
also zuverlässig `0`. `last_finished_pass` wäre dagegen unzuverlässig (kann bei mehreren
Durchgängen ≠ NULL sein, obwohl der Student im Player sitzt). **Restkante:** Bei Tests mit
mehreren erlaubten Durchgängen kann `submitted` nach Abschluss eines Pass auf 1 stehen —
für Klausuren (Einzeldurchgang) irrelevant.

---

## ilExamMessageHandler

Implementiert `ilExamModuleInterface`.

```
isEnabled(ref_id)          → gibt immer true zurück — JS muss immer geladen werden,
                             damit Nachrichten auch NACH Test-Einstieg ankommen

injectAssets(tpl, ref_id)  → nur wenn isTestPlayerContext();
                             exammessage.css + exammessage.js injiziert;
                             window.ExamMessage.config als JSON via addOnLoadCode gesetzt
                             (enthält poll_url, ref_id, user_id, sent_at, expiry, message, type);
                             die initiale Nachricht wird nur eingebettet, wenn eine aktive
                             vorliegt UND ilExamMessageGUI::userHasActivePass() true ist

saveSettings(ref_id, post) → liest exammessage_send_now, exammessage_text,
                             exammessage_duration, exammessage_type aus $post_data;
                             force_send = !empty(exammessage_send_now)
                             → strip_tags(ALLOWED_TAGS), mb_substr(2000)
                             → leerer Text: sent_at = 0, kein Log-Eintrag
                             → force_send || text geändert: sent_at = time(), Log-Eintrag
                             → nur duration/type geändert: $db->update() ohne Log

getFormItems(ref_id)       → [ilCustomInputGUI(Status-Box),
                               ilTextAreaInputGUI(exammessage_text),
                               ilSelectInputGUI(exammessage_type),
                               ilSelectInputGUI(exammessage_duration),
                               ilCustomInputGUI("Nachricht jetzt senden"-Button),
                               ilCustomInputGUI(Log-Tabelle, falls Einträge)]

getInfo()                  → name, title, icon, description, version
```

### „Nachricht jetzt senden"-Button

```php
// in getFormItems():
'<button type="submit" name="exammessage_send_now" value="1" class="btn btn-default btn-sm">'
. '&#x1F4E4;&nbsp;Nachricht jetzt senden</button>'
```

Ein normaler HTML-Submit-Button im übergeordneten `ilPropertyFormGUI`. Beim Klick wird
das Formular regulär abgeschickt (CSRF-Token automatisch enthalten). `saveSettings()`
erkennt `exammessage_send_now` im POST-Body und erzwingt `sent_at = time()` — auch wenn
der Text seit dem letzten Speichern unverändert ist. Nützlich um eine Nachricht für einen
weiteren Prüfungsdurchgang neu zu „starten".

### Erlaubte HTML-Tags

```php
private const ALLOWED_TAGS = '<strong><b><i><u><br>';
```

Serverseitig via `strip_tags()` gefiltert. Clientseitig in `exammessage.js` nochmals
durch `sanitize()` abgesichert — textContent-Escaping mit Whitelist-Restore.

### Asset-Injection

```php
$tpl->addCss(".../modules/exammessage/css/exammessage.css");
$tpl->addJavaScript(".../modules/exammessage/js/exammessage.js");

$tpl->addOnLoadCode(
    'window.ExamMessage = window.ExamMessage || {};' .
    'window.ExamMessage.config = ' . json_encode([
        'poll_url' => $poll_url,   // ilias.php?...&cmd=poll&ref_id=X
        'ref_id'   => $ref_id,     // localStorage-Key für Wegklick-Persistenz
        'user_id'  => $user_id,    // localStorage-Key (trennt Nutzer auf geteilten Laptops)
        'sent_at'  => $sent_at,    // aktuell aktive Nachricht (0 wenn keine / kein Teilnehmer)
        'expiry'   => $expiry,
        'message'  => $message,
        'type'     => $type,
    ]) . ';'
);
```

**Kritisch:** `addJavaScript` (externe Datei) wird von ILIAS **vor** `addOnLoadCode`
(Inline-JS) ausgeführt. Das JS darf `window.ExamMessage.config` deshalb nie beim
IIFE-Init cachen — es muss immer lazy aus `window.ExamMessage.config` gelesen werden.
`tryStart()` löst dieses Timing-Problem mit einem Retry-Mechanismus (s.u.).

### isTestPlayerContext()

Prüft `$_GET['cmdClass']` auf:
- `iltestplayer`, `iltestplayerfixedquestionsetgui`, `iltestplayerrandquestionsetgui`,
  `iltestplayergui`, `iltestplayercommandsgui`, `iltestoutputgui`

Sowie `$_SERVER['REQUEST_URI']` auf:
- `cmd=startPlayer`, `cmd=resumePlayer`, `cmd=showQuestion`, `cmd=nextQuestion`,
  `cmdClass=ilTestPlayer`

---

## ilExamMessageGUI

> Hieß bis 1.4.0 `ilExamMessageProxyGUI`. Umbenannt in 1.5.0 zur Konsistenz mit den
> übrigen Modul-GUIs (`ilExamGroupingGUI`, `ilExamLoggerViewGUI`, …) — „Proxy" war
> irreführend, der eigentliche Proxy ist `ilExtendedTestSettingsModuleProxyGUI` im
> Kern-Plugin. Diese Klasse ist nur der Endpunkt dahinter.

Wird via `module.json` als `gui_class` registriert und durch
`ilExtendedTestSettingsModuleProxyGUI::executeCommand()` aufgerufen.

```
executeCommand()   → dispatcht auf poll()
poll()             → liest exammessage_settings für den Test;
                     prüft sent_at > 0 && expiry > time();
                     ERST DANN userHasActivePass($uid, $obj_id) (Zustell-Gate);
                     gibt JSON zurück: {sent_at, expiry, message, type} oder {sent_at: 0}
userHasActivePass(user_id, obj_id) → static; true, wenn ein offener Testlauf existiert
                     (tst_active.submitted = 0). Wird auch vom Handler genutzt.
jsonExit(data, status) → ob_end_clean(), Content-Type: application/json, exit
```

**Performance:** Das Zustell-Gate (`userHasActivePass`) läuft im `poll()` erst, nachdem
feststeht, dass eine gültige Nachricht ansteht. Im Normalfall „keine Nachricht aktiv"
macht `poll()` weiterhin **genau eine** Query (`exammessage_settings`) — kein Extra-Query.

### Poll-Endpoint

```
GET ilias.php?baseClass=ilUIPluginRouterGUI
            &cmdClass=ilextendedtestsettingsmoduleproxygui
            &cmd=poll&module_id=exammessage&ref_id=<ref_id>
```

**Response (aktive Nachricht):**
```json
{"sent_at": 1748258400, "expiry": 1748258700, "message": "...", "type": 0}
```

**Response (keine/abgelaufene Nachricht):**
```json
{"sent_at": 0}
```

### Zugriffskontrolle

Auth liegt vollständig in `ilExtendedTestSettingsModuleProxyGUI::executeCommand()`:

```
eingeloggt + nicht anonym               → sonst Login-Redirect
allow_student_access: true (module.json) → checkAccess('write') ODER checkAccess('read')
                                           → bei keinem → HTTP 403 JSON
```

`checkAccess('read', $ref_id)` — Studierende, die den Test sehen dürfen, können pollen.

**Zwei Schichten (seit 1.5.0):**

1. **Auth (Endpoint erreichbar?)** — RBAC-`read` im Kern-Proxy, unverändert. Bewusst
   *kein* `last_finished_pass`-Check, da dieser unzuverlässig ist.
2. **Zustell-Gate (bekommt der Nutzer die Nachricht?)** — `userHasActivePass()` prüft
   `tst_active.submitted = 0`. Das `submitted`-Flag ist im Gegensatz zu `last_finished_pass`
   während der laufenden Bearbeitung zuverlässig 0 (s. DB-Abschnitt). So bleibt der Zugang
   großzügig (read), die Nachricht selbst geht aber nur an aktive Teilnehmer.

---

## Frontend (exammessage.js)

### Timing-Fix: tryStart() mit Retry

ILIAS-spezifisches Problem: `addJavaScript` feuert vor `addOnLoadCode`.
Wenn `DOMContentLoaded` auslöst, kann `window.ExamMessage.config` noch `undefined` sein.

```js
var startTries = 0;

function tryStart() {
    var cfg = window.ExamMessage && window.ExamMessage.config;
    if (!cfg || !cfg.poll_url) {
        if (++startTries < 40) { setTimeout(tryStart, 50); }  // max 2 s warten
        return;
    }
    // Initial-Banner anzeigen + setInterval(poll, 15000) starten
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryStart);
} else {
    tryStart();
}
```

`window.ExamMessage.config` wird **immer lazy** gelesen — nie beim IIFE-Init gecacht.

### poll()

```js
function poll() {
    var cfg = window.ExamMessage && window.ExamMessage.config;
    fetch(cfg.poll_url, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.sent_at > lastSentAt && data.expiry > now && data.message
                    && data.sent_at > getDismissed()) {   // seit 1.5.0
                lastSentAt = data.sent_at;
                showBanner(data.message, data.type || 0, data.expiry, data.sent_at);
            }
        }).catch(() => {});
}
```

Deduplizierung via `lastSentAt` (Timestamp-Vergleich) — bereits gezeigte Nachrichten
werden nicht erneut angezeigt.

### Wegklick-Persistenz via localStorage (seit 1.5.0)

Ein bewusst weggeklicktes Banner bleibt weg — auch nach einem Reload —, getrennt pro
Test **und** Konto (wichtig für geteilte Prüfungslaptops). Rein clientseitig, **keine
Server-Last, kein Endpoint**.

```js
function dismissKey()    { return 'exammsg_dismissed_' + refId + '_' + userId; }
function getDismissed()  { try { return parseInt(localStorage.getItem(dismissKey()),10)||0; } catch(e){ return 0; } }
function setDismissed(s) { try { localStorage.setItem(dismissKey(), String(s)); } catch(e){} }
```

- `refId`/`userId` kommen aus `window.ExamMessage.config`.
- Beim Anzeigen wird nur gezeigt, wenn `sent_at > getDismissed()` → eine **neuere**
  Nachricht (größeres `sent_at`) überschreibt die Dismissal automatisch.
- Nur das **aktive Wegklicken** persistiert (`setDismissed`), nicht der Countdown-Ablauf.
- `try/catch`: im privaten Modus fällt es sauber auf das alte In-Memory-Verhalten zurück.

Warum das auf geteilten Laptops genügt: Damit User A's Dismissal User B unterdrücken könnte,
müssten beide **dieselbe** Nachricht (gleiches `sent_at`) **innerhalb ihres Aktiv-Fensters**
nacheinander am selben Gerät bearbeiten — auf einem Einzelgerät praktisch ausgeschlossen.
Der `user_id`-Anteil im Key schließt selbst diesen konstruierten Fall aus.

### showBanner()

```
showBanner(message, type, expiry, sentAt)
  → bestehendes Banner entfernen
  → <div id="exam-message-banner"> mit Typ-Klasse (type-warning / type-danger) prependen
  → Countdown-Ticker (setInterval 1 s): m:ss-Format → js-Format → Dismiss
  → Klick auf Banner → setDismissed(sentAt) → dismiss() → exam-message-hiding → remove()
```

### sanitize(html)

```js
// 1. textContent-Escaping (alle Tags werden zu &lt;...&gt;)
// 2. Whitelist-Restore: &lt;strong&gt; → <strong>  etc.
// Whitelist: strong, b, i, u, br
```

---

## Styling (exammessage.css)

| CSS-Selektor | Bedeutung |
|---|---|
| `#exam-message-banner` | Fixiertes Banner oben (z-index: 99990), Default blau |
| `.type-warning` | Orange (#e8890c / #fff3e0) |
| `.type-danger` | Rot (#c0392b / #fde8e8) |
| `#exam-message-label` | Kleine Überschrift „Nachricht der Prüfungsaufsicht" |
| `#exam-message-text` | Nachrichtentext |
| `#exam-message-countdown-wrap` | Countdown-Bereich rechts |
| `@keyframes examMessageSlideDown/Up` | Ein-/Ausblend-Animation (0,35 s) |

Barrierefreiheit:
- `prefers-reduced-motion`: Animation deaktiviert
- `prefers-contrast: high`: dunklerer Hintergrund + stärkerer Rand für alle drei Typen
- `print`: Banner ausgeblendet
- `role="status"` + `aria-live="polite"` am Banner-Element

---

## Besondere Implementierungsentscheidungen

| Entscheidung | Begründung |
|---|---|
| `isEnabled()` immer `true` | Polling-JS muss auf der Seite sein, bevor eine Nachricht gesendet wird — sonst verpassen Studenten Nachrichten die nach ihrem Einstieg verschickt werden |
| **Auth** via RBAC-`read`, **Zustellung** via `tst_active.submitted=0` (seit 1.5.0) | Zugang großzügig (read-Recht haben alle Eingeschriebenen), Nachricht selbst aber nur an aktive Teilnehmer. `submitted` ist zuverlässig 0 während der Bearbeitung — im Gegensatz zu `last_finished_pass`, das bei mehreren Durchgängen ≠ NULL sein kann |
| Wegklick-Persistenz clientseitig (`localStorage`), nicht server-seitig (seit 1.5.0) | Kein Endpoint, keine Tabelle, keine Server-Last. Reicht praktisch aus, weil eine Kollision auf geteilten Laptops zeitliche Überlappung im selben Aktiv-Fenster erfordern würde; `ref_id+user_id`-Key schließt auch das aus |
| Submit-Button statt AJAX für „Jetzt senden" | Keine CSRF-Problematik, kein separater Endpoint; POST geht über den bestehenden ILIAS-Formular-Flow mit automatischem `rtoken` |
| `tryStart()`-Retry (50 ms × 40) | ILIAS führt `addJavaScript` vor `addOnLoadCode` aus — `window.ExamMessage.config` ist beim JS-Init noch nicht gesetzt |
| `lastSentAt`-Deduplizierung | Verhindert, dass beim nächsten Poll-Intervall dieselbe Nachricht nochmals als neu gilt |
| Log-Eintrag nur bei text-Änderung oder force_send | Reine Duration/Type-Anpassungen sind interne Konfigurationsänderungen, kein neuer Versand |

---

## Installation / Update

### Erstinstallation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren
3. In den Erweiterten Test-Einstellungen erscheint „Prüfungs-Nachricht" mit Textarea,
   Typ-Auswahl, Dauer-Auswahl und „Nachricht jetzt senden"-Button

### Update

Neue ZIP-Version über denselben Upload-Dialog hochladen. Die Tabellen
`exammessage_settings` und `exammessage_log` bleiben erhalten (`tableExists()`-Guard
in `sql/install.php`).

### Manuelle Ablage (Entwicklung)

Dateien direkt unter `modules/exammessage/` ablegen. Der Handler-Konstruktor ruft
`ensureTables()` auf — beide Tabellen werden beim ersten Seitenaufruf automatisch
angelegt (inkl. Migration der `type`-Spalte für v1.2.0+).

---

## Changelog

| Datum | Version | Was |
|---|---|---|
| 2026-06-02 | **1.5.0** | Wegklick-Persistenz via `localStorage` (Key `exammsg_dismissed_<ref_id>_<user_id>`, überlebt Reload, trennt Nutzer); Zustell-Gate `tst_active.submitted=0` (Nachricht nur an Teilnehmer mit offenem Lauf, nur bei aktiver Nachricht → kein Extra-Query im Normalfall); GUI-Klasse `ilExamMessageProxyGUI` → `ilExamMessageGUI` umbenannt; Config um `ref_id`/`user_id` erweitert |
| 2026-06-01 | 1.4.0 | i18n (DE/EN): Sprachstrings nach `lang/ilias_de.lang` / `lang/ilias_en.lang` ausgelagert, `ilModuleLanguage` |
| 2026-05-26 | 1.3.0 | „Nachricht jetzt senden"-Submit-Button (`exammessage_send_now`); Poll-Auth auf RBAC-`read` umgestellt (fix 403 für Studierende) |
| 2026-05-26 | 1.2.0 | AJAX-Polling (15 s), Nachrichtentypen (Info/Warnung/Dringend), Versand-Log (`exammessage_log`), Countdown-Banner, tryStart-Retry-Fix für ILIAS-Timing, Student-Auth via ProxyGUI |
| 2026-05-26 | 1.0.0 | Initiale Version — statisches Info-Banner, einmal pro Seitenaufruf angezeigt |
