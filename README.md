# ExtendedTestSettings — Module Repository

[![License](https://img.shields.io/badge/license-AGPL--3.0--only-blue)](LICENSE)

Dieses Repository enthält alle installierbaren Zusatzmodule für das ILIAS-Plugin **ExtendedTestSettings**.

Das Plugin selbst ermöglicht es, die ILIAS-Testeinstellungen um prüfungsspezifische Funktionen zu erweitern. Module können über die Plugin-Administration als ZIP hochgeladen und aktiviert werden — ohne Eingriff in den Plugin-Code.

**Plugin-Repository:** [andreschweigert/ExtendedTestSettings](https://github.com/andreschweigert/ExtendedTestSettings)

---

## Branches

Jeder Branch entspricht einer ILIAS-Hauptversion:

| Branch | ILIAS-Version | Modul-Versionsschema |
|---|---|---|
| `ilias9` | ILIAS 9 | 1.x/2.x (historisch) |
| `ilias10` | ILIAS 10 | 10.x — Major = ILIAS-Version, Sprünge +0.0.1 |

---

## Verfügbare Module

| Modul | Stand | Beschreibung |
|---|---|---|
| [examalert](modules/examalert/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Zeit-Warnung kurz vor Testende, konfigurierbare Nachricht mit HTML-Formatierung |
| [exambattery](modules/exambattery/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Stiller Akku-Monitor für Prüfungsgeräte: meldet Akkustände an eine Aufsichts-Ansicht, keinerlei Anzeige für Studierende |
| [examcalc](modules/examcalc/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Taschenrechner während der Prüfung (einfach oder wissenschaftlich), Shortcut Alt + R |
| [examchecker](modules/examchecker/) | ![In Entwicklung](https://img.shields.io/badge/In%20Entwicklung-yellow) | Prüft Test-Einstellungen gegen konfigurierbare Soll-Werte (Präsenz-/Fernprüfung) |
| [examconnguard](modules/examconnguard/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Verbindungs-Schutz: On-Demand-Probe bei Submit/Klick, Overlay + Auto-Replay bei WLAN-Ausfall |
| [examdyslexia](modules/examdyslexia/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Legasthenie-Modus: aktivierbarer Lesemodus für Studierende während der Prüfung |
| [examfullscreen](modules/examfullscreen/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Vollbild-Modus (F11) beim Teststart, warnt oder sperrt bei Verlassen des Vollbilds |
| [examgrouping](modules/examgrouping/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Fragen zu Gruppen zusammenfassen, Ergebnisse je Gruppe auswerten, CSV-Export |
| [examguard](modules/examguard/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Prüfungsschutz: blockiert Copy/Paste, Rechtsklick und kritische Tastenkombinationen |
| [examkeymap](modules/examkeymap/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Clientseitiges Tastatur-Layout DE/EN für Kiosk-Geräte (KeyboardEvent.code-basiert), umschaltbar im Testplayer, globale Übersetzungstabelle |
| [examlogger](modules/examlogger/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Serverseitiges Audit-Logging: Start, Navigation, Abgabe, Gerätezuordnung via IP, CSV-Export |
| [exammessage](modules/exammessage/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Echtzeit-Nachricht an alle Prüfungsteilnehmer per AJAX-Polling (15 s), drei Nachrichtentypen, Countdown |
| [examnetqos](modules/examnetqos/) | ![Experimentell](https://img.shields.io/badge/Experimentell-orange) | Misst Latenz und Paketverlust im Hintergrund, erkennt Netz-Ereignisse und speichert Zeitreihen zur Korrelation mit Teilnehmer-Beschwerden |
| [exampreflight](modules/exampreflight/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Geführter Probedurchlauf: prüft pro Frage Bilder, Videos, Formeln und Layout, Screenshots + Prüfbericht |
| [examresourcesheet](modules/examresourcesheet/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Hilfsmittelblatt (PDF/HTML) als seitliches Panel im Test-Player, Text markierbar, Push-Modus |
| [examsymbols](modules/examsymbols/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Sonderzeichen-Palette für Prüfungen (Mathe, Chemie, Physik, Sprachen), Shortcut Alt + S |

---

## Modul installieren

Siehe [docs/how-to.md](docs/how-to.md) für eine Schritt-für-Schritt-Anleitung.

## Lizenz

Die eigenen Modulbeiträge stehen unter **AGPL-3.0-only**. Der vollständige Text
steht in [LICENSE](LICENSE). Für diese Beiträge gilt die zusätzliche
Autorenangabe nach Abschnitt 7(b) in [NOTICE](NOTICE). Jedes neue Modul-ZIP
enthält beide Texte als `LICENSE.txt` und `NOTICE.txt`.

Mitgelieferte Fremdkomponenten behalten ihre eigenen Lizenzen: math.js
(Apache-2.0 und Hinweise zu Abhängigkeiten), Mozilla PDF.js (Apache-2.0) und
OpenDyslexic (SIL Open Font License 1.1). Ihre Lizenztexte liegen jeweils im
betroffenen Modul-ZIP.
