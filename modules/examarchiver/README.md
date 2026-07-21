# ExamArchiver — Prüfungs-Archivierung

> **Entwicklungsstand:** Experimentell

**Modul-ID:** `examarchiver` · **Aktuelle Version:** 1.0.0 · **ILIAS:** 9

Archiviert Tests **vollständig**: Alle Antworten, Punkte, Noten, manuelles Feedback und
die Test-Einstellungen werden als **selbsttragendes ZIP** festgeschrieben — offline lesbar,
ohne laufende ILIAS-Installation. Ein Manifest mit **SHA-256-Prüfsummen** macht jede
nachträgliche Veränderung nachweisbar; jeder Download und jede Prüfung wird protokolliert.

## Funktionsweise

- **Auslösung manuell durch Admins** — idealerweise erst nach Abschluss der Notenfestsetzung
  (vorher können sich manuelle Korrekturen noch ändern).
- Globale Verwaltung: `Administration → Plugins → ExtendedTestSettings → Tab „Archiv"` —
  Tests per Checkbox auswählen → **„Ausgewählte Tests archivieren"**. Die Erzeugung läuft
  paketweise (5 Teilnehmer pro Request) mit Fortschrittsanzeige, große Kohorten laufen
  daher nicht in PHP-Timeouts.
- Pro Test: In den Erweiterten Test-Einstellungen zeigt der Abschnitt „Prüfungs-Archivierung"
  den letzten Archiv-Stand und verlinkt eine Einzeltest-Ansicht mit „Diesen Test jetzt archivieren".

## Inhalt eines Archivs

```
ExamArchive_<obj_id>_<datum>_<lauf>/
├── index.html               Übersicht: Testdaten, Einstellungs-Snapshot, Notenschlüssel,
│                            Notenverteilung, durchsuch-/sortierbare Teilnehmerliste
├── index.pdf                dieselbe Übersicht als PDF (wenn wkhtmltopdf verfügbar)
├── teilnehmer/<login>_<active_id>.html   Akte pro Teilnehmer: jede Frage mit Antwort,
│                            Musterlösung, Punkten, manuellem Feedback; alle Durchläufe,
│                            der gewertete markiert
├── teilnehmer/<login>_<active_id>.pdf    dieselbe Akte als PDF
├── data/archive.json        kompletter maschinenlesbarer Roh-Snapshot (inkl. tst_solutions-Rohwerte)
├── data/ergebnisse.csv      Ergebnisliste (Semikolon, UTF-8 BOM)
├── data/test.json           Test-Snapshot zum Archivierungszeitpunkt
└── manifest.json            SHA-256 jeder Datei + Erzeugungs-Metadaten
```

Alle HTML-Dateien sind selbsttragend (CSS/JS inline, Bilder als data-URIs eingebettet).
Die Antwort-Darstellung übernimmt die jeweilige ILIAS-Fragenklasse (`getSolutionOutput()`),
dadurch werden alle Fragetypen korrekt gerendert.

## Integrität

- `manifest.json` listet SHA-256-Hashes aller Archiv-Dateien.
- Die Prüfsummen des ZIPs und des Manifests werden zusätzlich in der DB gespeichert.
- Button **„Integrität prüfen"** berechnet den ZIP-Hash neu und vergleicht mit der DB.
- Beim **Löschen** wird nur die ZIP-Datei entfernt — der Metadaten-Eintrag (inkl. Hashes)
  bleibt als Nachweis erhalten (`status=deleted`).

## Ablage

`<CLIENT_DATA_DIR>/plugins/ExtendedTestSettings/examarchiver/<obj_id>/ExamArchive_….zip`
— außerhalb des Webroots, Auslieferung nur über die GUI (write-Recht auf den Test).
Bei **Deinstallation** des Moduls werden die DB-Tabellen entfernt, die ZIP-Dateien auf
der Platte bleiben bewusst liegen.

## DB-Tabellen

| Tabelle | Inhalt |
|---|---|
| `examarchiver_archives` | Ein Datensatz pro Archiv-Lauf (Status, Zähler, Dateiname, SHA-256 von ZIP und Manifest) |
| `examarchiver_log` | Zugriffsprotokoll: erstellt / heruntergeladen / geprüft / gelöscht / fehlgeschlagen |

## Konfiguration

| Einstellung | Wo | Standard |
|---|---|---|
| PDF-Erzeugung | automatisch, wenn `wkhtmltopdf` auf dem Server gefunden wird | aktiv |
| Paketgröße | Konstante `CHUNK_SIZE` in `class.ilExamArchiverGUI.php` | 5 Teilnehmer/Request |

## Hinweise / Grenzen

- **Anonyme Tests:** Es wird archiviert, was die DB hergibt — bei aktivierter Anonymität
  fehlt die Zuordnung zu Klarnamen prinzipbedingt.
- Formel-/Medieninhalte: Bilder aus der ILIAS-Installation werden eingebettet (bis 2 MB/Bild);
  externe URLs bleiben Verweise.
- Der Haupt-Repo-Tab („Archiv" in der Plugin-Konfiguration) gehört zum Plugin-Kern
  (`ConfigGUI`), nicht zum Modul-ZIP.

## Installation

1. ZIP hochladen: `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul aktivieren — `sql/install.php` legt die Tabellen an.
3. Tab „Archiv" erscheint in der Plugin-Konfiguration (Plugin-Kern ab entsprechendem Stand).

## Versionshistorie

| Version | Datum | Änderungen |
|---|---|---|
| 1.0.0 | 2026-07-02 | Initiale Implementierung: chunked Erzeugung, HTML+PDF+JSON/CSV, SHA-256-Manifest, Integritätsprüfung, Zugriffsprotokoll, Admin-Tab + Einzeltest-Ansicht |
