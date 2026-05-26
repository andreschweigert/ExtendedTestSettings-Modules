# ExamMessage

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Ermöglicht dem Kursadministrator, während einer Prüfung eine **Nachricht an alle
Teilnehmer** zu senden. Die Nachricht erscheint im Test-Player als fixiertes **Banner**
am oberen Rand — per AJAX-Polling alle 15 Sekunden zugestellt, ohne Seitenneuladen.

> **Basiert auf der Idee von:** [ExamNotifications](https://github.com/iliasfhbi/ExamNotifications) von @iliasfhbi —
> reimplementiert als integriertes ExtendedTestSettings-Modul.

## Konfiguration

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Nachrichtentext | `exammessage_text` | Freitext, max. 2000 Zeichen (`<strong>`, `<b>`, `<i>`, `<u>`, `<br>` erlaubt) |
| Nachrichtentyp | `exammessage_type` | 0 = Info (blau), 1 = Warnung (orange), 2 = Dringend (rot) |
| Anzeigedauer | `exammessage_duration` | 30 s / 1 min / 2 min / 5 min / 10 min / 30 min |

Leerer Nachrichtentext löscht die aktive Nachricht beim Speichern.

### Senden

- **„Nachricht jetzt senden"** — direkter Submit-Button im Formular; setzt `sent_at`
  immer neu (auch bei unverändertem Text) und erzeugt einen Log-Eintrag.
- **„Einstellungen speichern"** — normaler Formular-Save; setzt `sent_at` nur wenn
  der Text sich geändert hat.

## Datenbank

| Tabelle | Primärschlüssel | Beschreibung |
|---|---|---|
| `exammessage_settings` | `test_id` | Aktuelle Konfiguration je Test |
| `exammessage_log` | `id` (Sequence) | Versand-Protokoll |

| Spalte | Bedeutung |
|---|---|
| `test_id` | ILIAS obj_id des Tests |
| `message_text` | Nachrichtentext (max. 2000 Zeichen, einfaches HTML) |
| `duration` | Anzeigedauer in Sekunden |
| `sent_at` | Unix-Timestamp des Versands (0 = keine aktive Nachricht) |
| `sent_by` | ILIAS-Login des Versenders |
| `type` | 0 = Info, 1 = Warnung, 2 = Dringend |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `css/exammessage.css` | Styles für das Banner (Info/Warnung/Dringend, Countdown, High-Contrast) |
| `js/exammessage.js` | tryStart-Retry, AJAX-Polling alle 15 s, Banner mit Countdown |

## Poll-Endpoint

```
ilias.php?baseClass=ilUIPluginRouterGUI
        &cmdClass=ilextendedtestsettingsmoduleproxygui
        &cmd=poll&module_id=exammessage&ref_id=<ref_id>
```

Zugangsbedingung: eingeloggt + Lesezugriff auf den Test (`read`-RBAC).  
Response: `{"sent_at": <ts>, "expiry": <ts>, "message": "...", "type": 0}` oder `{"sent_at": 0}`.

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.3.0 | `ExamMessage_v1.3.0.zip` | 9 | „Nachricht jetzt senden"-Button; Poll-Auth auf RBAC-read umgestellt |
| 1.2.0 | `ExamMessage_v1.2.0.zip` | 9 | AJAX-Polling (15 s), Nachrichtentypen, Versand-Log, tryStart-Retry-Fix |
| 1.0.0 | `ExamMessage_v1.0.0.zip` | 9 | Initiale Version — statisches Info-Banner |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, Handler-Methoden, Sicherheitsmodell):  
→ [`/docs/modules/ExamMessage.md`](../../docs/modules/ExamMessage.md)
