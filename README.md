# ExtendedTestSettings — Module Repository

Dieses Repository enthält alle installierbaren Zusatzmodule für das ILIAS-Plugin **ExtendedTestSettings**.

Das Plugin selbst ermöglicht es, die ILIAS-Testeinstellungen um prüfungsspezifische Funktionen zu erweitern. Module können über die Plugin-Administration als ZIP hochgeladen und aktiviert werden — ohne Eingriff in den Plugin-Code.

**Plugin-Repository:** [andreschweigert/ExtendedTestSettings](https://github.com/andreschweigert/ExtendedTestSettings)

---

## Branches

Jeder Branch entspricht einer ILIAS-Hauptversion:

| Branch | ILIAS-Version |
|---|---|
| `ilias9` | ILIAS 9 |

---

## Verfügbare Module

| Modul | Beschreibung |
|---|---|
| [examalert](modules/examalert/) | Zeit-Warnung kurz vor Testende, konfigurierbare Nachricht mit HTML-Formatierung |
| [examarchiver](modules/examarchiver/) | Test-Archivierung: Antworten, Punkte, Noten und Einstellungen als selbsttragendes ZIP (HTML + PDF + JSON/CSV) mit SHA-256-Manifest und Zugriffsprotokoll |
| [examcalc](modules/examcalc/) | Taschenrechner während der Prüfung (einfach oder wissenschaftlich), Shortcut Alt + R |
| [examchecker](modules/examchecker/) | Prüft Test-Einstellungen gegen konfigurierbare Standards (Präsenz-/Fernprüfung) |
| [examconnguard](modules/examconnguard/) | Verbindungs-Schutz: On-Demand-Probe bei Submit/Klick, Overlay + Auto-Replay bei WLAN-Ausfall |
| [examdyslexia](modules/examdyslexia/) | Legasthenie-Modus: aktivierbarer Lesemodus für Studierende während der Prüfung |
| [examfullscreen](modules/examfullscreen/) | Vollbild-Modus (F11) beim Teststart, warnt oder sperrt bei Verlassen des Vollbilds |
| [examgrouping](modules/examgrouping/) | Fragen zu Gruppen zusammenfassen, Ergebnisse je Gruppe auswerten, CSV-Export |
| [examguard](modules/examguard/) | Prüfungsschutz: blockiert Copy/Paste, Rechtsklick und kritische Tastenkombinationen |
| [examkeymap](modules/examkeymap/) | Clientseitiges Tastatur-Layout DE/EN für Kiosk-Geräte (KeyboardEvent.code-basiert), umschaltbar im Testplayer, globale Übersetzungstabelle |
| [examlogger](modules/examlogger/) | Serverseitiges Audit-Logging: Start, Navigation, Abgabe, Gerätezuordnung via IP, CSV-Export |
| [exammessage](modules/exammessage/) | Echtzeit-Nachricht an alle Prüfungsteilnehmer per AJAX-Polling (15 s), drei Nachrichtentypen, Countdown |
| [examnetqos](modules/examnetqos/) | Misst Latenz und Paketverlust im Hintergrund, erkennt Netz-Ereignisse und speichert Zeitreihen zur Korrelation mit Teilnehmer-Beschwerden |
| [exampreflight](modules/exampreflight/) | Geführter Probedurchlauf: prüft pro Frage Bilder, Videos, Formeln und Layout, Screenshots + Prüfbericht |
| [examresourcesheet](modules/examresourcesheet/) | Hilfsmittelblatt (PDF/HTML) als seitliches Panel im Test-Player, Text markierbar, Push-Modus |
| [examsymbols](modules/examsymbols/) | Sonderzeichen-Palette für Prüfungen (Mathe, Chemie, Physik, Sprachen), Shortcut Alt + S |

---

## Modul installieren

Siehe [docs/how-to.md](docs/how-to.md) für eine Schritt-für-Schritt-Anleitung.
