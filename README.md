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
| [exambattery](modules/exambattery/) | ![Im Test](https://img.shields.io/badge/Im%20Test-blue) | Stiller Akku-Monitor für Prüfungsgeräte: meldet Akkustände an eine Aufsichts-Ansicht, keinerlei Anzeige für Studierende |
| [examcalc](modules/examcalc/) | ![Prüfungserprobt](https://img.shields.io/badge/Pr%C3%BCfungserprobt-brightgreen) | Taschenrechner während der Prüfung (einfach oder wissenschaftlich), Shortcut Alt + R |
| [examchecker](modules/examchecker/) | ![In Entwicklung](https://img.shields.io/badge/In%20Entwicklung-yellow) | Prüft Test-Einstellungen gegen konfigurierbare Soll-Werte (Präsenz-/Fernprüfung) |
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

---

## Tests

**Stand: im Aufbau (2026-08-04).** Die Tests des Plugin-**Kerns** liegen im
Plugin-Repository (`tests/`); hier in `tests/` entsteht der Prüfsatz für die
**Module**. Er läuft ohne ILIAS — kein Webserver, keine Datenbank, kein Netz —
und prüft die Modulquellen statisch:

- **Manifest-Integrität:** die in `module.json` genannten Dateien existieren;
  Root-, Quell- und ZIP-Manifest sowie `getInfo()` nennen dieselbe Version
- **Sprachdateien:** `ilias_de.lang` und `ilias_en.lang` mit demselben
  Schlüsselsatz — fehlt ein Schlüssel, steht im UI der rohe Schlüsselname
- **Regelprüfungen** aus dem verbindlichen Regelwerk des Plugins (R4/R5/R7–R10):
  keine schreibende Aktion an einem Link statt an einem Formular, kein
  `json_encode()` ohne HEX-Flags in Inline-JavaScript, kein `tst_active` ohne
  `submitted`, kein ungeprüftes `X-Forwarded-For`, keine CSV-Zelle ohne
  Formel-Neutralisierung, kein `return true` im `catch`

### Zwei Läufe, ein Prüfsatz

| Lauf | Basis | beantwortet |
|---|---|---|
| `modules-source` | `modules/<id>/<Name>_v<version>/` | Ist der freigegebene Code in Ordnung? → Freigabe-Gate vor einem Release |
| `modules-installed` | `<Plugin>/modules/<id>/` | Läuft auf der Plattform der geprüfte Stand? → Nachweis im Betrieb |

Der Plugin-Pfad für den zweiten Lauf kommt aus `ETS_PLUGIN_PATH`; Vorgabe ist
das Elternverzeichnis dieses Repositories. Ist er nicht erreichbar, überspringt
sich der Lauf mit Begründung — er meldet nie stillschweigend „grün".

Ist ein Modul unter `modules/` älter als die freigegebene Version, ist das
**kein Regelverstoß**, sondern die Ansage „erst installieren, dann erneut
prüfen". Ein Verstoß, der nur im Installations-Lauf auftritt, ist ein Befund
über den *installierten* Stand.

### Zwei Regeln dazu

1. **Repariert wird immer in der Quelle.** `<Plugin>/modules/` ist gitignored —
   eine Korrektur dort ist beim nächsten Release wieder weg.
2. **Ein rot markierter Test wird nicht „grün gemacht".** Die Modul-Findings aus
   dem Audit vom 2026-08-03 sind noch offen; die Tests schreiben das
   Sollverhalten fest, nicht den Ist-Zustand. Bekannte Verstöße sind mit
   Finding-ID als „incomplete" ausgewiesen und in `tests/OFFENE_FINDINGS.md`
   gelistet — sie sind damit die Abnahmebedingung für den späteren Fix.

Eine Anleitung, wie man für ein neues Modul Tests schreibt, folgt unter
`docs/tests-schreiben.md`.
