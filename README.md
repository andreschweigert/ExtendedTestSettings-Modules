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

| Modul | Stand | Beschreibung |
|---|---|---|
| [examalert](modules/examalert/) | ![Prüfungserprobt](https://img.shields.io/badge/Pr%C3%BCfungserprobt-brightgreen) | Zeit-Warnung kurz vor Testende, konfigurierbare Nachricht mit HTML-Formatierung |
| [examarchiver](modules/examarchiver/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Test-Archivierung: Antworten, Punkte, Noten und Einstellungen als selbsttragendes ZIP (HTML + PDF + JSON/CSV) mit SHA-256-Manifest und Zugriffsprotokoll |
| [exambattery](modules/exambattery/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Stiller Akku-Monitor für Prüfungsgeräte: meldet Akkustände an eine Aufsichts-Ansicht, keinerlei Anzeige für Studierende |
| [examcalc](modules/examcalc/) | ![Prüfungserprobt](https://img.shields.io/badge/Pr%C3%BCfungserprobt-brightgreen) | Taschenrechner während der Prüfung (einfach oder wissenschaftlich), Shortcut Alt + R |
| [examchecker](modules/examchecker/) | ![In Entwicklung](https://img.shields.io/badge/In%20Entwicklung-yellow) | Prüft Test-Einstellungen gegen konfigurierbare Standards (Präsenz-/Fernprüfung) |
| [examconnguard](modules/examconnguard/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Verbindungs-Schutz: On-Demand-Probe bei Submit/Klick, Overlay + Auto-Replay bei WLAN-Ausfall |
| [examdyslexia](modules/examdyslexia/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Legasthenie-Modus: aktivierbarer Lesemodus für Studierende während der Prüfung, Darstellung im Testplayer selbst anpassbar |
| [examfullscreen](modules/examfullscreen/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Vollbild-Modus (F11) beim Teststart, warnt oder sperrt bei Verlassen des Vollbilds |
| [examgrouping](modules/examgrouping/) | ![Pilotbetrieb](https://img.shields.io/badge/Pilotbetrieb-blueviolet) | Fragen zu Gruppen zusammenfassen, Ergebnisse je Gruppe auswerten, CSV-Export |
| [examguard](modules/examguard/) | ![Prüfungserprobt](https://img.shields.io/badge/Pr%C3%BCfungserprobt-brightgreen) | Prüfungsschutz: blockiert Copy/Paste, Rechtsklick und kritische Tastenkombinationen |
| [examkeymap](modules/examkeymap/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Clientseitiges Tastatur-Layout DE/EN für Kiosk-Geräte (KeyboardEvent.code-basiert), umschaltbar im Testplayer, globale Übersetzungstabelle |
| [examlogger](modules/examlogger/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Serverseitiges Audit-Logging: Start, Navigation, Abgabe, Gerätezuordnung via IP, CSV-Export |
| [exammagnifier](modules/exammagnifier/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Bildschirmlupe: Vollbild-Zoom bis 1600 %, Farbinvertierung, großer Mauszeiger – für alle oder nur hinterlegte Benutzer (Nachteilsausgleich) |
| [exammessage](modules/exammessage/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Echtzeit-Nachricht an alle Prüfungsteilnehmer per AJAX-Polling (15 s), drei Nachrichtentypen, Countdown |
| [examnetqos](modules/examnetqos/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Misst Latenz und Paketverlust im Hintergrund, erkennt Netz-Ereignisse und speichert Zeitreihen zur Korrelation mit Teilnehmer-Beschwerden |
| [exampreflight](modules/exampreflight/) | ![Pilotbetrieb](https://img.shields.io/badge/Pilotbetrieb-blueviolet) | Geführter Probedurchlauf: prüft pro Frage Bilder, Videos, Formeln und Layout, Screenshots + Prüfbericht |
| [examresourcesheet](modules/examresourcesheet/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Hilfsmittelblatt (PDF/HTML) als seitliches Panel im Test-Player, Text markierbar, Push-Modus |
| [examsymbols](modules/examsymbols/) | ![Prüfungserprobt](https://img.shields.io/badge/Pr%C3%BCfungserprobt-brightgreen) | Sonderzeichen-Palette für Prüfungen (Mathe, Chemie, Physik, Sprachen), Shortcut Alt + S |

---

## Modul installieren

Siehe [docs/how-to.md](docs/how-to.md) für eine Schritt-für-Schritt-Anleitung.
