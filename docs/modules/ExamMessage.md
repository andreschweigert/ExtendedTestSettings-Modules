# ExamMessage — Entwicklerdokumentation

**Modul-ID:** `exammessage`  
**Speicherort:** `modules/exammessage/` (externes Modul)  
**Referenz-ZIP:** `modules-zip/modules/exammessage/ExamMessage_v1.3.0.zip`  
**ILIAS-Kompatibilität:** 9  
**Zuletzt aktualisiert:** 2026-05-26

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
- Auth über RBAC-`read` statt `tst_active`-Check

---

## Dateistruktur

```
modules/exammessage/
├── module.json                      ← Modul-Metadaten, allow_student_access: true
├── class.ilExamMessageHandler.php   ← Handler (implements ilExamModuleInterface)
├── class.ilExamMessageProxyGUI.php  ← Poll-Endpoint (JSON, no HTML output)
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
    "description": "Sendet eine Nachricht an alle Teilnehmer mit AJAX-Polling (15 s) für Echtzeit-Zustellung ohne Seitenneuladen.",
    "version": "1.3.0",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamMessageHandler",
    "handler_file": "class.ilExamMessageHandler.php",
    "gui_file": "class.ilExamMessageProxyGUI.php",
    "gui_class": "ilExamMessageProxyGUI",
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
| `tst_active` | Nur in `serveResourceFile()` anderer Module — **nicht** für ExamMessage |

---

## ilExamMessageHandler

Implementiert `ilExamModuleInterface`.

```
isEnabled(ref_id)          → gibt immer true zurück — JS muss immer geladen werden,
                             damit Nachrichten auch NACH Test-Einstieg ankommen

injectAssets(tpl, ref_id)  → nur wenn isTestPlayerContext();
                             exammessage.css + exammessage.js injiziert;
                             window.ExamMessage.config als JSON via addOnLoadCode gesetzt
                             (enthält poll_url, sent_at, expiry, message, type)

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
        'sent_at'  => $sent_at,    // aktuell aktive Nachricht (0 wenn keine)
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

## ilExamMessageProxyGUI

Wird via `module.json` als `gui_class` registriert und durch
`ilExtendedTestSettingsModuleProxyGUI::executeCommand()` aufgerufen.

```
executeCommand()   → dispatcht auf poll()
poll()             → liest exammessage_settings für den Test;
                     prüft sent_at > 0 && expiry > time();
                     gibt JSON zurück: {sent_at, expiry, message, type} oder {sent_at: 0}
jsonExit(data, status) → ob_end_clean(), Content-Type: application/json, exit
```

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
Kein `tst_active`-Check: dieser wäre unzuverlässig (je nach Durchgangsnummer/Status
kann `last_finished_pass` ≠ NULL sein, obwohl der Student im Player sitzt).

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
            if (data.sent_at > lastSentAt && data.expiry > now && data.message) {
                lastSentAt = data.sent_at;
                showBanner(data.message, data.type || 0, data.expiry);
            }
        }).catch(() => {});
}
```

Deduplizierung via `lastSentAt` (Timestamp-Vergleich) — bereits gezeigte Nachrichten
werden nicht erneut angezeigt.

### showBanner()

```
showBanner(message, type, expiry)
  → bestehendes Banner entfernen
  → <div id="exam-message-banner"> mit Typ-Klasse (type-warning / type-danger) prependen
  → Countdown-Ticker (setInterval 1 s): m:ss-Format → js-Format → Dismiss
  → Klick auf Banner → dismiss() → CSS-Klasse exam-message-hiding → animationend → remove()
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
| `allow_student_access: true` + RBAC-`read`-Check | `tst_active`-Check ist unzuverlässig (bei mehreren Durchgängen ist `last_finished_pass` ≠ NULL); `read`-Recht ist für eingeschriebene Studierende immer vorhanden |
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
| 2026-05-26 | **1.3.0** | „Nachricht jetzt senden"-Submit-Button (`exammessage_send_now`); Poll-Auth auf RBAC-`read` umgestellt (fix 403 für Studierende) |
| 2026-05-26 | 1.2.0 | AJAX-Polling (15 s), Nachrichtentypen (Info/Warnung/Dringend), Versand-Log (`exammessage_log`), Countdown-Banner, tryStart-Retry-Fix für ILIAS-Timing, Student-Auth via ProxyGUI |
| 2026-05-26 | 1.0.0 | Initiale Version — statisches Info-Banner, einmal pro Seitenaufruf angezeigt |
