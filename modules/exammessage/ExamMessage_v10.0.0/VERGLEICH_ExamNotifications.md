# Vergleichsanalyse: `exammessage` (unser Modul) ↔ `ExamNotifications` (HSBI-Plugin)

Stand: 2026-06-02

Verglichen werden:

- **A) `ExamNotifications`** — eigenständiges HSBI-Plugin (Felix Kümmel, FH/HS Bielefeld), getestet mit ILIAS **9.14**, Plugin-ID `exnot`, Version `9.0.0006`. Liegt jetzt als ILIAS-9-Fassung vor (vorher haben wir die ILIAS-7-Fassung als Vorlage genutzt).
- **B) `exammessage`** — unser **Modul** innerhalb des `ExtendedTestSettings`-Plugins, Modul-ID `exammessage`, Version `1.4.0`, ILIAS 9.

Beide haben **denselben fachlichen Zweck**: Eine Prüfungsaufsicht schickt während eines laufenden Tests eine Nachricht, die den Studierenden im Test-Player eingeblendet wird, ohne Drittprogramme (Zoom o.ä.).

---

## 1. Gemeinsamkeiten (was fachlich gleich ist)

| Aspekt | Beide |
|---|---|
| **Use Case** | Aufsicht → Nachricht → Anzeige im Test-Player während laufender Bearbeitung |
| **Zustellung** | **AJAX-Polling** vom Browser des Studierenden (kein Push/WebSocket) |
| **Anzeige als Banner** | Beide bieten ein oben eingeblendetes, überlagerndes **Banner** (rot/auffällig), per Klick schließbar |
| **Bezug pro Testobjekt** | Nachricht hängt an einem Test (bei A `ref_id`, bei B `test_id`/obj_id) |
| **Sender wird gespeichert** | A: `sender_user_id`; B: `sent_by` (Login) |
| **Zeitstempel** | Beide speichern Sendezeitpunkt |
| **Nur Schreibberechtigte senden** | A: Hauptmenüpunkt nur bei Schreibrecht; B: Einstellung über Test-Settings-Tab (Tutor/Admin) |
| **Schließen = weg** | Studierender kann Banner schließen |

Konzeptionell ist B also eine **funktionale Reimplementierung** von A — aber mit deutlich anderer technischer Verankerung.

---

## 2. Der zentrale Unterschied: Integrationsform

| | A) ExamNotifications | B) exammessage |
|---|---|---|
| **Art** | Eigenständiges UIHook-**Plugin** | **Modul** in einem Träger-Plugin (`ExtendedTestSettings`) |
| **Eigene plugin.php** | Ja (`exnot`) | Nein — läuft unter `xets`/ExtendedTestSettings |
| **Eigener ilCtrl-Router** | Eigene `ExamNotificationsGUI` über `ilUIPluginRouterGUI` | Geteilter Proxy `ilextendedtestsettingsmoduleproxygui` des Träger-Plugins, Dispatch über `module_id=exammessage` |
| **Lebenszyklus** | Eigene Plugin-Installation/Aktivierung | Wird als ZIP-Modul ins Träger-Plugin eingespielt; Tabellen via `ensureTables()` lazy angelegt |

Das ist der größte Architekturunterschied: **A ist ein Plugin, B ist ein Plugin-Modul.** Daraus folgt fast alles Weitere.

---

## 3. Architektur & Code-Stil

### A) ExamNotifications — schwergewichtig, „Enterprise"
- Vollständige Schichtung in Namespaces: `Model/`, `DAO/`, `View/`, `Control/`.
- Eigenes generisches **CRUD-Framework** als Submodul `plugin-core-functions` (`HSBI\PHPUtilities\CRUD\…`): `TupleBasedObjectCRUDController`, `FieldDefinition`, `FilterDefinition`, `SortDefinition`, `Tuple` usw. → DB-Zugriff vollständig abstrahiert.
- Command-Pattern: `DefineMessagesCommand`, `GetMessagesCommand`, `CloseMessageCommand`, `ConfigCommand`.
- **Migrations** (`Migration_From_1_1_0`, `Migration_From_9_0_0000`).
- Eigene Logging-Fassade `PLog::debug()->parameter()/->return()`.
- Generierte PHPDoc-API-Doku (`doc/api/…`), Tests (`test/`, phpstan).
- Anonyme Models mit Getter/Setter für jedes Feld (`Message`, `MessageViewed`, `MessageStatus`, …).

### B) exammessage — schlank, direkt
- Im Wesentlichen **zwei Klassen**: `ilExamMessageHandler` (Logik, implementiert `ilExamModuleInterface`) und `ilExamMessageGUI` (Poll-Endpoint).
- **Direkter** DB-Zugriff über `$DIC->database()` (`queryF`, `replace`, `insert`, `update`, `nextId`).
- Eigene kleine `ilModuleLanguage`-Fassade für modul-eigene Sprachdateien.
- Inline-HTML/CSS für Status- und Log-Anzeige im Settings-Formular.
- Keine Submodule, keine Migrationen, keine generierte Doku.

**Fazit Stil:** A ist generisch/wiederverwendbar und stark abstrahiert; B ist pragmatisch und auf genau diesen Use Case zugeschnitten. B ist mit ~600 Zeilen Code überschaubar, A umfasst Dutzende Klassen + Framework-Submodul.

---

## 4. Datenmodell / Datenbank

| | A) ExamNotifications | B) exammessage |
|---|---|---|
| **Tabelle(n)** | `ui_uihk_exnot_message`, `ui_uihk_exnot_message_viewed` (+ Config) | `exammessage_settings`, `exammessage_log` |
| **Mehrere aktive Nachrichten** | **Ja** — Nachrichten sind eine Liste (n pro Test) | **Nein** — `exammessage_settings` hat PK `test_id`, also **genau eine aktuelle** Nachricht pro Test (per `replace`) |
| **Verlauf/History** | Über dieselbe message-Tabelle (alle Nachrichten bleiben) | Separate **Append-only** `exammessage_log`-Tabelle mit Sequence |
| **„Gesehen/Geschlossen"-Status pro User** | **Ja** — `MessageViewed` mit `MessageStatus` (VIEWED/CLOSED) pro (message, user) | **Nein** — keine per-User-Statushaltung; das Schließen passiert nur clientseitig |
| **Sender** | `sender_user_id` (FK auf usr_data) | `sent_by` als Login-String |
| **Textlänge** | 200 Zeichen | bis 2000 Zeichen (serverseitig gekappt) |
| **Felder Anzeigedauer/Typ** | — (kein Ablauf-Timer, kein Typ) | `duration` (Anzeigedauer) + `type` (Info/Warnung/Dringend) |

**Wichtigster inhaltlicher Modellunterschied:**
- A modelliert **viele Nachrichten je Test + individuellen Lese-/Schließ-Status je Student** (server-persistiert). Eine geschlossene Nachricht bleibt für diesen User weg, auch nach Reload.
- B modelliert **eine „aktuelle" Nachricht je Test** mit **Ablaufzeit** (`duration`) und einem separaten **Log** für die Historie. Lese-/Schließ-Status wird **nicht** serverseitig gehalten — Schließen ist rein clientseitig; nach Reload/innerhalb der Laufzeit kann sie wieder erscheinen.

B hat dafür zwei Konzepte, die A nicht hat: **Anzeigedauer mit Countdown** und **Nachrichten-Typ/Severity** (blau/orange/rot inkl. Icon).

---

## 5. Wo wird eingehakt (Hook-Punkte)

### A) ExamNotifications
Zwei wählbare **Einbindungsarten** (Plugin-Config `PRESENTATION_TYPE`):
1. **GlobalScreen Toast + Notification Center** — über `ExamNotificationProvider extends AbstractNotificationProvider` (`getNotifications()`); nutzt `globalScreen()->notifications()`. Mit `withOpenedCallable`/`withClosedCallable` werden VIEWED/CLOSED serverseitig persistiert.
2. **JS-Banner** — `ExamNotificationV1.js`: prepended `<div id="ui-uihk-exnot-MessageContainer">` in `.il-layout-page-content`, pollt **alle 60 s** `cmdClass=ExamNotificationsGUI&cmd=get` und ersetzt das Container-HTML komplett (Server rendert das Banner-HTML).

Aktiv-Logik: nutzt `PCFIDIC::Objects()->Test()->getAllTestObjectsOfUser()` + `hasUnfinishedPasses()`, um zu bestimmen, ob der User offene Testläufe hat; Filterung der Nachrichten nach diesen ref_ids und nach bereits geschlossenen.

### B) exammessage
- **Asset-Injection** im Test-Player-Kontext über `injectAssets()` (vom Träger-Plugin am UIHook aufgerufen): lädt CSS + JS und schreibt `window.ExamMessage.config` per `addOnLoadCode`.
- Kontext-Erkennung selbstgebaut in `isTestPlayerContext()` (prüft `cmdClass` und `REQUEST_URI` auf `iltestplayer…`, `cmd=showQuestion` etc.).
- **Poll-Endpoint** `ilExamMessageGUI::poll()` über den geteilten Proxy; `exammessage.js` pollt **alle 15 s** und rendert das Banner **clientseitig** (JS baut DOM, Server liefert nur JSON).
- Initiale Nachricht wird schon beim Seitenaufbau in die JS-Config gelegt (sofort sichtbar, ohne auf den ersten Poll zu warten).

**Unterschiede Hook:**
- A: Anzeige-HTML kommt **vom Server** (HTML-Response), B: Server liefert **JSON**, Banner-DOM baut **JS**.
- A: nutzt fertige GlobalScreen-/Notification-Infrastruktur (inkl. Toast), B: rein eigenes Banner, kein Notification-Center.
- A: Poll-Intervall **60 s**, B: **15 s** (B reagiert schneller — gewünscht für „Echtzeit").
- A: bestimmt offene Testläufe serverseitig sauber über Test-API; B: erkennt den Player-Kontext heuristisch über cmdClass/URI.

---

## 6. Sender-Bedienoberfläche

| | A) ExamNotifications | B) exammessage |
|---|---|---|
| **Einstieg** | Eigener **Hauptmenüpunkt** im Testobjekt (nur bei Schreibrecht) | Eigener **Reiter/Block in den Test-Settings** des Träger-Plugins |
| **UI-Bau** | Eigene CRUD-Table-Komponenten (`Creation`/`CreateAction`, `History`/`ReadAction`, `SenderColumn`, `TimestampColumn`) | Klassische `ilPropertyFormGUI`-Items: `ilTextAreaInputGUI`, `ilSelectInputGUI`, `ilCustomInputGUI` |
| **Eingaben** | Nur Nachrichtentext (200) | Text (2000) + **Typ** + **Anzeigedauer** + Button „Jetzt senden" |
| **Status-Anzeige** | History-Tabelle | Inline **Status-Box** (läuft noch X, farbig nach Typ) + **Log-Tabelle** |
| **„Erneut/forciert senden"** | implizit durch neue Nachricht | expliziter `exammessage_send_now`-Button; sonst „nur bei Textänderung" neu senden |

---

## 7. Sicherheit / Robustheit (auffällige Punkte)

- **A** kapselt DB komplett über das CRUD-Framework (parametrisiert) → SQLi-arm by design.
- **B** nutzt `queryF`/`replace`/Typbindung → ebenfalls parametrisiert; Text wird mit `strip_tags($x, ALLOWED_TAGS)` (`<strong><b><i><u><br>`) gefiltert und auf 2000 Zeichen begrenzt.
  - Im Client zusätzlich eigene `sanitize()` in `exammessage.js`: erst `textContent` (alles escapen), dann gezielt die erlaubten Tags wieder freischalten. → XSS-bewusst.
- **B/Proxy**: `poll()` validiert `ref_id`, liefert nur Nachrichten innerhalb der Laufzeit (`expiry > time()`), und gibt sonst `{sent_at:0}` zurück. Kein Lookup, ob der Abrufende tatsächlich einen aktiven Testlauf hat — A prüft das (offene Pässe) strenger.
- **B**: `isEnabled()` gibt bewusst immer `true` zurück, damit das Polling-JS auch dann lädt, wenn beim Seiteneinstieg noch keine Nachricht existiert.

---

## 8. Feature-Matrix (kompakt)

| Feature | A ExamNotifications | B exammessage |
|---|---|---|
| Banner-Anzeige | ✔ (Server-HTML) | ✔ (Client-JS) |
| Toast / Notification-Center | ✔ | ✗ (nicht vorhanden) |
| Mehrere gleichzeitige Nachrichten | ✔ | ✗ (eine je Test) |
| Anzeigedauer / Ablauf-Countdown | ✗ | ✔ |
| Nachrichten-Typ (Info/Warn/Dringend) | ✗ | ✔ |
| Per-User Lese-/Schließ-Status (persistent) | ✔ | ✗ (clientseitig) |
| Verlauf / History | ✔ (message-Tabelle) | ✔ (separates Log) |
| Konfigurierbare Einbindungsart | ✔ | ✗ (fix: Banner) |
| Poll-Intervall | 60 s | 15 s |
| Sofort-Anzeige beim Seitenaufbau | – (erst Poll) | ✔ (Config inline) |
| Eigenständiges Plugin | ✔ | ✗ (Modul) |
| Generisches CRUD-Framework / Submodule | ✔ | ✗ |
| Migrations | ✔ | ✗ (lazy ensureTables) |

✔ = vorhanden, ✗ = nicht vorhanden.

---

## 9. Bewertung / Empfehlungen (Diskussionsgrundlage)

**Was B (wir) besser/anders macht:**
- Schneller (15 s, Sofort-Config), Typ-Severity + Countdown sind echte funktionale Mehrwerte.
- Deutlich weniger Code, keine Framework-Abhängigkeit → leichter wartbar im Modul-Kontext.
- Sauberes Append-only-Log getrennt von „aktueller Nachricht".

**Was A besser macht / was uns fehlt:**
1. **Per-User „geschlossen"-Persistenz.** Bei B kann eine geschlossene Nachricht innerhalb der Laufzeit nach Reload wiederkommen (lastSentAt ist nur im JS-Speicher). A merkt sich CLOSED serverseitig. → Falls erwünscht: `exammessage_viewed`/Statusspalte nachrüsten, oder Schließen per `localStorage` clientseitig persistieren.
2. **Mehrere gleichzeitige Nachrichten** je Test (A kann das, B nicht). Für unseren Use Case evtl. nicht nötig — bewusst entscheiden.
3. **Strengere Empfänger-Prüfung**: A liefert nur an User mit offenem Testlauf; B liefert die Nachricht an jeden, der den Poll-Endpoint mit gültiger `ref_id` aufruft. Für Vertraulichkeit ggf. zusätzlich „hat aktiven Pass?" prüfen.
4. **Toast/Notification-Center** als alternative, weniger aufdringliche Einbindung — bei uns bewusst nicht vorhanden.

**Punkte zum Klären:**
- Wollen wir das „geschlossen bleibt geschlossen"-Verhalten? (aktuell nicht garantiert)
- Reicht „eine aktuelle Nachricht je Test" dauerhaft, oder brauchen wir mehrere parallele?
- Soll der Poll-Endpoint auf „aktiver Testlauf des Users" abgesichert werden?
