# ExamResourceSheet — Hilfsmittelblatt

![Entwicklungsstand: Im Test](https://img.shields.io/badge/Entwicklungsstand-Im%20Test-blue)

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Dozenten laden ein **Hilfsmittelblatt** (PDF oder HTML, max. 10 MB) in den Test-Einstellungen hoch — oder geben alternativ **eigenen Text** direkt im Formular ein (einfache Formatierung mit `strong`/`b`/`i`/`u`/`br`). Während der Prüfung erscheint ein **linker Edge-Tab** im Test-Player, über den Studierende den Inhalt in einem einschiebbaren Panel öffnen können. Das Panel schiebt die ILIAS-Seite nach rechts (Push-Modus). PDFs werden über PDF.js gerendert — kein nativer Browser-Viewer, kein Download-Button. Text im PDF ist markier- und kopierbar.

**Typische Anwendungsfälle:** Formelsammlung, Vokabelliste, Periodensystem, Gesetzestexte

## Konfiguration

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Beschreibung |
|---|---|---|
| Hilfsmittelblatt aktivieren | `examresourcesheet_enabled` | Schaltet den Edge-Tab im Test-Player an/aus |
| Button-Beschriftung | `examresourcesheet_label` | Text auf dem Tab (max. 100 Zeichen, z.B. „Formelblatt") |
| Quelle | `examresourcesheet_source` | `file` = hochgeladene Datei, `text` = eigener Text |
| Aktuelle Datei | — | Anzeige mit Link (öffnet im neuen Tab, nur bei aktiviertem Modul) |
| Aktuelle Datei entfernen | `examresourcesheet_delete_file` | Checkbox — löscht die Datei beim Speichern |
| Textinhalt | `examresourcesheet_text` | Eigener Text (max. 20.000 Zeichen), erlaubte Tags: `strong`/`b`/`i`/`u`/`br` |
| Datei hochladen | `examresourcesheet_file` | PDF oder HTML, max. 10 MB |

## Datenbank

Eigene Tabelle: `examressheet_settings`

| Spalte | Bedeutung |
|---|---|
| `test_id` (PK) | ILIAS obj_id des Tests |
| `enabled` | 1 = aktiv |
| `label` | Button-Beschriftung (Default: „Hilfsmittel") |
| `filename` | Originaler Dateiname (für Anzeige) |
| `stored_name` | Physischer Dateiname `{test_id}_{token}.{ext}` |
| `mimetype` | `application/pdf` oder `text/html` |
| `filesize` | Dateigröße in Bytes |
| `source_mode` | `file` (Default) oder `text` — was im Player angezeigt wird |
| `content_text` | Eigener Text-Inhalt (roh gespeichert, Sanitizing bei Ausgabe) |
| `updated_at` | Letzter Speicherzeitpunkt |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `css/examresourcesheet.css` | Styles für Edge-Tab, Panel, Text-Layer |
| `js/pdf.min.js` | PDF.js 3.11.174 (nur bei PDF-Dateien) |
| `js/pdf.worker.min.js` | PDF.js Worker (nur bei PDF-Dateien) |
| `js/examresourcesheet.js` | Edge-Tab, Panel, Resize, Blob-Fetch-Logik |

## Sicherheit

- Dateien werden außerhalb des Web-Roots gespeichert (`data/<client>/plugins/ExtendedTestSettings/resourcesheets/`)
- Auslieferung über PHP-Endpoint mit Zugriffsschutz (aktive Test-Session oder Schreibrecht)
- Dateiname auf dem Server ist obfuskiert (32-Zeichen SHA-256-Token)
- MIME-Type wird serverseitig mit `finfo` geprüft (Whitelist: PDF + HTML)
- Hochgeladenes HTML wird in einem **`sandbox`-iframe** angezeigt — JavaScript im
  Dokument wird nicht ausgeführt (verhindert Same-Origin-Skriptausführung, ab 1.2.1)

## Versionen

| Version | Datei | ILIAS | Änderungen |
|---|---|---|---|
| 1.3.0 | `ExamResourceSheet_v1.3.0.zip` | 9 | Quelle wählbar: Datei-Upload **oder** eigener Text (Whitelist `strong`/`b`/`i`/`u`/`br`, serverseitig sanitisiert); hochgeladene Datei im Formular als Link einsehbar und per Checkbox entfernbar. Neue Spalten `source_mode`, `content_text` |
| 1.2.1 | `ExamResourceSheet_v1.2.1.zip` | 9 | Sicherheitsfix: HTML-Hilfsmittelblätter werden in einem `sandbox`-iframe geladen (keine JS-Ausführung in der Nutzer-Session) |
| 1.2.0 | `ExamResourceSheet_v1.2.0.zip` | 9 | Sprachdateien DE/EN (i18n), Texte über `ilModuleLanguage` |
| 1.1.0 | `ExamResourceSheet_v1.1.0.zip` | 9 | PDF.js-Rendering, Text-Layer (Markieren/Kopieren), linker Edge-Tab, Push-Modus, Drag-Resize + Buttons, SessionStorage-State |
| 1.0.0 | `ExamResourceSheet_v1.0.0.zip` | 9 | Initiale Version: floating Button, iframe-Overlay |

## Installation

Siehe [`/docs/how-to.md`](../../docs/how-to.md).

## Entwickler-Dokumentation

Vollständige Doku (Dateistruktur, DB-Schema, Asset-Injection, JS-Architektur):
→ [`/docs/modules/ExamResourceSheet.md`](../../docs/modules/ExamResourceSheet.md)
