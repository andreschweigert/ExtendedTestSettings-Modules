# ExamPreflight — Preflight-Durchlauf

![Entwicklungsstand: Pilotbetrieb](https://img.shields.io/badge/Entwicklungsstand-Pilotbetrieb-blueviolet)

Geführter Probedurchlauf durch einen ILIAS-Test **vor** der Prüfung: prüft pro Frage
automatisch, ob alles korrekt gerendert wird, und erstellt Screenshots für einen
Prüfbericht.

Das Gegenstück zu **ExamChecker**: ExamChecker prüft die *Einstellungen* des Tests,
ExamPreflight prüft das tatsächliche *Rendering* der Fragen.

## Was wird geprüft?

| Bereich | Checks |
|---|---|
| Bilder | nicht ladbare Bilder (`naturalWidth == 0`), nach Wartezeit unvollständige Bilder, kaputte CSS-Hintergrundbilder (HEAD-Request) |
| Video/Audio | nicht abspielbare Quellen, fehlende Metadaten, Videos ohne Bilddaten (0×0) |
| Frames | leere eingebettete Frames, Hinweis auf externe (nicht prüfbare) Frames |
| Formeln | MathJax-Renderfehler (`merror`), unverarbeiteter Formel-Code im sichtbaren Text (`[tex]`, `\(…\)`) |
| Layout | horizontaler Overflow, aus dem Fragenbereich ragende Elemente, (nahezu) leere Fragen |
| Formatierung | Encoding-Salat (`Ã¤`, `â€“`, `�`), HTML-Code als sichtbarer Text, winzige Schrift (< 9 px), kaum lesbarer Text (Farbe ≈ Hintergrund) — Code-Blöcke (`<pre>`/`<code>`) werden ignoriert |
| Antwortoptionen | leere Auswahlmenüs, Radio-Gruppen mit nur einer Option (Import-Artefakte) |
| Fragentext | fehlender Fragentext (Fehler) bzw. sehr kurzer Fragentext unter 5 Wörtern (Warnung) — Bild-/Formel-Fragen ausgenommen |
| Ressourcen | HTTP-Fehler ≥ 400 (via Performance-API, browserabhängig) |
| JavaScript | Fehler während der Fragenseite |

## Wie funktioniert es?

1. In den **Erweiterten Test-Einstellungen** auf „Preflight-Durchlauf starten" klicken
2. Im Cockpit „Durchlauf starten" — optional die Tab-Freigabe für Screenshots bestätigen
   (Screen-Capture-API, ein Browser-Dialog, empfohlen: Chrome/Edge)
3. Den Test im eingebetteten Fenster starten — ab der ersten Frage läuft alles automatisch:
   Checks → Screenshot (gesamte Fragenhöhe, segmentiert) → Weiter-Klick
4. Am Ende: Prüfbericht mit einer Karte pro Frage (Status ✅/⚠️/❌, Befunde, Screenshot)

**Pause/Abbruch** jederzeit über die Cockpit-Leiste. Der Test wird **nicht** automatisch
abgegeben.

## Wichtig

- Der Durchlauf erzeugt einen **normalen Testlauf** für das prüfende Konto.
  Diesen vor Prüfungsbeginn unter *Teilnehmerdaten* löschen (Hinweis erscheint im Bericht).
- Screenshots werden serverseitig unter
  `<CLIENT_DATA_DIR>/plugins/ExtendedTestSettings/exampreflight/` gespeichert und beim
  Löschen eines Laufs bzw. bei der Deinstallation entfernt.
- Zugriff auf Cockpit und Berichte nur mit Schreibrecht am Test.

## Installation

ZIP über `Administration → Plugins → ExtendedTestSettings → Module` hochladen und
aktivieren (siehe [docs/how-to.md](../../docs/how-to.md)).

## Versionen

| Version | Datum | Änderungen |
|---|---|---|
| 1.2.2 | 2026-07-02 | Härtung nach Code-Review: Weiter-Klick bestätigt nur noch echt sichtbare Modals und niemals einen Test-beenden-Button; robustere Frage-Erkennung (Kollisions- und Zyklus-Schutz); keine Fehlalarme mehr bei Lazy-Bildern, HTML-Code-Erkennung, Auswahlmenüs; CSRF-Token auf allen Endpunkten, Screenshot-Upload nur echte JPEGs, `nosniff`-Header, Cockpit-Seite nicht cachebar |
| 1.2.1 | 2026-07-02 | Auto-Start stabilisiert (Launcher-Erkennung, Start-Modal wird direkt abgeschickt, Sperre gegen Fremd-Modals im Player, Fragenübersicht wird übersprungen, Zyklus-Erkennung am Testende); neue Checks: fehlender/zu kurzer Fragentext |
| 1.2.0 | 2026-07-02 | Vollautomatischer Durchlauf: Test-Start/Fortsetzen wird selbst geklickt, Passwort und Prüfungsbedingungen im Start-Modal automatisch ausgefüllt; Option „Testlauf danach löschen" entfernt am Ende den eigenen (!) Testlauf über die offizielle ILIAS-API |
| 1.1.1 | 2026-07-02 | Navigation: Back-Link „← Test" + Tabs Cockpit/Prüfberichte in allen Ansichten; klarer Hinweis, wenn Screenshots mangels HTTPS nicht verfügbar sind |
| 1.1.0 | 2026-07-02 | Neue Checks: unverarbeiteter Formel-Code, kaputte CSS-Hintergrundbilder, Antwortoptionen-Plausibilität, Formatierung (Encoding, sichtbarer HTML-Code, Mini-Schrift, kaum lesbarer Text) |
| 1.0.1 | 2026-07-02 | Fix: „Eingebetteter Frame ist leer" nicht mehr für unsichtbare Hilfs-iframes (TinyMCE, Modul-Helfer, `about:blank`) melden — nur sichtbare Frames mit echter Quelle werden geprüft |
| 1.0.0 | 2026-07-02 | Initiale Version |
