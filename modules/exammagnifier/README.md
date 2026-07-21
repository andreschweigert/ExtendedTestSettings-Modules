# ExamMagnifier

Externes Modul für das ILIAS-Plugin **ExtendedTestSettings**.

Bringt eine **Bildschirmlupe** in den ILIAS Test-Player — als browserbasierter Ersatz für die Windows-Bildschirmlupe in abgesicherten Kiosk-Umgebungen (z. B. Kiosk-Linux). Für Prüflinge mit Sehbeeinträchtigung oder Nachteilsausgleich.

## Features

- **Vollbild-Zoom bis 1600 %** — die gesamte Seite wird vergrößert, der sichtbare Ausschnitt folgt der Maus (wie der Vollbildmodus der Windows-Bildschirmlupe). Eingaben funktionieren dabei normal, Text bleibt scharf. Stufen: 100 / 150 / 200 / 300 / 400 / 600 / 800 / 1200 / 1600 %
- **Farbinvertierung** — helle Schrift auf dunklem Grund; Bilder, Videos und Canvas bleiben in Originalfarben
- **Großer Mauszeiger** — großer gelber Zeiger (Overlay, keine 128px-Cursor-Grenze) mit Klick-Hervorhebung (Ring-Animation)
- **Schwebende Toolbar** — `position: fixed; bottom: 20px; left: 20px` (kein Konflikt mit examdyslexia oben rechts / examcalc und examsymbols unten rechts), einklappbar auf ein 🔍-Symbol
- **Tastatur-Shortcuts** — Strg+Alt+Plus/Minus (Zoom), Strg+Alt+0 (Zoom zurücksetzen), Strg+Alt+I (Invertierung), Strg+Alt+P (Zeiger); in Eingabefeldern deaktiviert (AltGr-Kollision auf DE-Layouts)
- **Zielgruppe konfigurierbar** — alle Teilnehmer oder nur hinterlegte Benutzer (Anmeldename oder Matrikelnummer); nicht gelistete Teilnehmer bemerken nichts vom Modul
- **sessionStorage** — Zustand (Zoomstufe, Invertierung, Zeiger) überlebt Fragenwechsel
- **Rein clientseitig** — keine externen Ressourcen, keine Serverlast, Kiosk-tauglich
- **Default: alles aus** — Teilnehmer schalten Hilfen selbst ein

## Konfiguration (Dozent)

In den Erweiterten Test-Einstellungen des Tests:

| Einstellung | Formular-Name | Werte |
|---|---|---|
| Bildschirmlupe aktivieren | `exammagnifier_enabled` | Checkbox |
| Verfügbar für | `exammagnifier_mode` | `all` (alle Teilnehmer) / `list` (nur hinterlegte Benutzer) |
| Benutzerliste | `exammagnifier_user_list` | Textarea, ein Eintrag pro Zeile: Anmeldename oder Matrikelnummer |
| Vollbild-Zoom erlauben | `exammagnifier_allow_zoom` | Checkbox (Default: an) |
| Maximale Vergrößerung | `exammagnifier_max_zoom` | `400` / `800` / `1600` (Default: 1600) |
| Farbinvertierung erlauben | `exammagnifier_allow_invert` | Checkbox (Default: an) |
| Großen Mauszeiger erlauben | `exammagnifier_allow_cursor` | Checkbox (Default: an) |

**Hinweis:** Bei `mode = list` werden Einträge groß-/kleinschreibungs­unabhängig gegen ILIAS-Anmeldename **und** Matrikelnummer des angemeldeten Benutzers geprüft. Leere Liste ⇒ niemand sieht die Hilfen.

## Datenbank

Eigene Tabelle: `exammagnifier_settings`

| Spalte | Typ | Default | Bedeutung |
|---|---|---|---|
| `test_id` (PK) | integer | — | ILIAS obj_id des Tests |
| `enabled` | integer 0/1 | 0 | Modul aktiviert |
| `mode` | text | `all` | `all` oder `list` |
| `user_list` | clob | — | Benutzerliste (eine Zeile pro Eintrag, normalisiert) |
| `allow_zoom` | integer 0/1 | 1 | Vollbild-Zoom freigegeben |
| `max_zoom` | integer | 1600 | Obergrenze in Prozent (400/800/1600) |
| `allow_invert` | integer 0/1 | 1 | Farbinvertierung freigegeben |
| `allow_cursor` | integer 0/1 | 1 | Großer Zeiger freigegeben |
| `updated_at` | timestamp | — | Letzte Änderung |

## Geladene Assets im Testplayer

| Datei | Beschreibung |
|---|---|
| `css/exammagnifier.css` | Toolbar, Invertierungs-Filter, Zeiger-Overlay, Zoom-Overflow |
| `js/exammagnifier.js` | Zoom-Logik (body-Transform + Maus-Schwenk), Toolbar, Shortcuts, sessionStorage |

## Technische Details

- **Zoom:** `transform: scale()` direkt auf `<body>`, `transform-origin` folgt der Maus (rAF-geglättet). Kein DOM-Umbau, keine iframes werden bewegt — TinyMCE-/Formular-sicher. Eigene UI hängt unter `<html>` und bleibt dadurch unskaliert.
- **Invertierung:** `filter: invert(1) hue-rotate(180deg)` auf `<html>` (Root-Filter, bricht `position: fixed` nicht); Medien und eigene UI werden per Doppel-Invertierung zurückgeholt.
- **Zeiger:** Overlay-`<div>` mit Inline-SVG folgt `mousemove`; echter Cursor per `cursor: none` versteckt (Toolbar ausgenommen).
- Config wird **nicht** beim IIFE-Init gecacht (live über `cfg()`), Details siehe `docs/modules/ExamMagnifier.md`.

## Installation

ZIP über Administration → Plugins → ExtendedTestSettings → Module hochladen, dann aktivieren.

## Versionshistorie

| Version | Datum | Änderungen |
|---|---|---|
| 1.0.0 | 2026-07-21 | Erstversion: Vollbild-Zoom bis 1600 %, Farbinvertierung, großer Mauszeiger, Toolbar + Shortcuts, Freigabe für alle oder per Benutzerliste |
