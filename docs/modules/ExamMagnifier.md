# ExamMagnifier — Entwicklerdokumentation

**Modul-ID:** `exammagnifier`
**Referenz-ZIP:** `modules-zip/modules/exammagnifier/ExamMagnifier_v1.0.2.zip`
**Entwicklungsstand:** experimentell
**Zuletzt aktualisiert:** 2026-07-23

---

## Was ist ExamMagnifier?

Bildschirmlupe im Testplayer als browserbasierter Ersatz für die
Windows-Bildschirmlupe in Kiosk-Linux-Umgebungen: Vollbild-Zoom bis 1600 %
mit Schiebe-Steuerung (Ruhezone in der Mitte, Mausbewegung am Rand
schiebt den Ausschnitt),
Farbinvertierung und großer Mauszeiger mit Klick-Hervorhebung. Bedienung über eine einklappbare Toolbar unten links
plus Tastatur-Shortcuts. Freigabe wahlweise für alle Teilnehmer oder nur
für hinterlegte Benutzer (Nachteilsausgleich); nicht gelistete Teilnehmer
bekommen keinerlei Assets injiziert.

## Dateistruktur

```
modules/exammagnifier/
├── module.json                          kein gui_file — reines Player-Modul
├── class.ilExamMagnifierHandler.php     Handler (Settings, Benutzerabgleich, Asset-Injection, Formular)
├── js/exammagnifier.js                  Zoom, Invertierung, Zeiger, Toolbar, Shortcuts, sessionStorage
├── css/exammagnifier.css                Toolbar, Root-Filter, Zeiger-Overlay
├── lang/ilias_de.lang / ilias_en.lang
└── sql/install.php / uninstall.php
```

## module.json

Kein `gui_file`/`gui_class`, kein `allow_student_access` — keine eigene
GUI-Seite, keine AJAX-Endpoints. `tables: ["exammagnifier_settings"]`.

## Datenbank

### `exammagnifier_settings` (PK: `test_id` = obj_id)

| Spalte | Typ | Default | Beschreibung |
|---|---|---|---|
| `test_id` | integer(4) | — | obj_id des Tests |
| `enabled` | integer(1) | 0 | Modul für den Test aktiv |
| `mode` | text(10) | `all` | `all` = alle Teilnehmer, `list` = nur Benutzerliste |
| `user_list` | clob | NULL | normalisierte Liste (lowercase, eine Zeile pro Eintrag) |
| `allow_zoom` | integer(1) | 1 | Vollbild-Zoom freigegeben |
| `max_zoom` | integer(4) | 1600 | Whitelist: 400 / 800 / 1600 |
| `allow_invert` | integer(1) | 1 | Farbinvertierung freigegeben |
| `allow_cursor` | integer(1) | 1 | großer Zeiger freigegeben |
| `updated_at` | timestamp | NULL | letzte Änderung |

## Datenfluss

1. `injectAssets()` prüft: Modul aktiv → Testplayer-Kontext →
   `userHasAccess()` (bei `mode=list` Abgleich des normalisierten
   Anmeldenamens **oder** der Matrikelnummer gegen `user_list`) →
   mindestens eine Hilfe freigegeben. Sonst: keinerlei Injektion.
2. CSS + JS mit filemtime-Cache-Busting, Config (inkl. übersetzter
   JS-Labels) via `addOnLoadCode` nach `window.ExamMagnifier.config`.
3. JS baut Toolbar + Overlays, stellt den sessionStorage-Zustand wieder
   her (`exammagnifier_state_<ref_id>`: Zoomstufe, Invertierung, Zeiger,
   Toolbar auf/zu) — überlebt Fragenwechsel (Full-Page-Reload).

## Technik-Entscheidungen

### Vollbild-Zoom statt Linse

Eine Lupen-Linse bräuchte eine DOM-Kopie (veraltet bei Eingaben) oder
Screenshots. Der Vollbild-Zoom arbeitet auf dem echten DOM:
`document.body.style.transform = scale(s)`; `transform-origin` (in
Seitenkoordinaten, auf den sichtbaren Viewport geklemmt) bestimmt den
Ausschnitt. Vertikal bleibt normales Scrollen möglich (`overflow-x:
hidden` auf `<html>` gegen springende Scrollbalken).

**Steuerungs-Evolution:** 1.0.0 koppelte den Origin direkt an die
Zeigerposition (nervös, Ecken schlecht erreichbar). 1.0.1 nutzte
positionsbasiertes Rand-Gleiten (Auto-Scroll solange der Zeiger im
Randband steht) — Rückmeldung: fühlt sich unnatürlich an, weil die
Bewegung von der Hand entkoppelt ist, und randnahe Ziele driften beim
Anklicken weg. Seit 1.0.2 gilt die **Schiebe-Steuerung**
(`handlePanMove`): die äußeren 18 % je Viewport-Seite (`EDGE_BAND`)
sind Schiebezone — dort schiebt das Maus-Delta selbst den Ausschnitt
(Sichtverschiebung = Delta × `gear(s)`, 1:1 bei kleinem Zoom, bis 5×
bei 1600 %; Origin-Verschiebung = Sichtverschiebung / (s−1)).
Ratschen-Prinzip: nur die auswärts gerichtete Delta-Komponente schiebt,
Zurückziehen bewegt nichts — wiederholte Wischbewegungen traversieren
so beliebig weit. Bei gedrückter Maustaste (`e.buttons`) wird nie
geschoben (stabiles Klicken am Rand). Nur in den äußersten
`GLIDE_MARGIN` = 8 px, wo der angepinnte Zeiger keine Deltas mehr
liefert, gleitet ein rAF-Loop (`glideTick`) langsam nach
(`GLIDE_SPEED * sqrt(s)`). Über der Toolbar (unten links am Rand!)
wird weder geschoben noch geglitten (`pointerOverOwnUi`); beim
Verlassen des Fensters wird die Delta-Kette zurückgesetzt. Beim
Einschalten zentriert `centerOnQuestion()` auf `#tst_output`, und ein
Ruhezonen-Hinweis (gestrichelter Rahmen + Label, `js_deadzone`) wird
2,5 s eingeblendet.

**Kein DOM-Umbau:** Bewusst kein Wrapper-Element um die Body-Kinder —
das würde iframes (TinyMCE-Essay-Editor!) neu laden. Eigene UI (Toolbar,
Zeiger-Overlay, Klick-Ring) hängt stattdessen direkt unter
`documentElement` und ist damit vom body-Transform unberührt.

**Kein `will-change: transform`:** würde die Raster-Ebene bei niedriger
Auflösung einfrieren — ohne re-rastern Chrome/Firefox den Text auf
Endskalierung, dadurch bleibt er auch bei 1600 % scharf.

Nebenwirkung: `position: fixed`-Elemente **innerhalb** von `<body>`
(z. B. Buttons anderer ETS-Module) werden durch den Transform zu
absolut positionierten Elementen und zoomen mit — konsistent mit dem
Verhalten der Windows-Lupe.

### Farbinvertierung

`filter: invert(1) hue-rotate(180deg)` auf `<html>` (Root-Filter =
klassischer Dark-Mode-Hack; auf `<body>` würde er `position: fixed`
brechen). `background: #ffffff` auf `<html>` gesetzt, damit der
transparente Grund zu Schwarz wird. Medien (`img`, `video`, `canvas`,
`picture`) und die eigene UI werden per Doppel-Invertierung zurückgeholt.

### Großer Mauszeiger

CSS-Custom-Cursor sind auf ~128 px begrenzt → Overlay-`<div>` mit
Inline-SVG (56 px, gelb mit dunkler Kontur), folgt `mousemove`
(clientX/Y, `position: fixed`, `pointer-events: none`). Echter Cursor
per `cursor: none` versteckt — **außer** auf der Toolbar, damit sie beim
Deaktivieren nicht im Blindflug bedient werden muss. Klick-Ring als
einmalige CSS-Animation (`prefers-reduced-motion` respektiert).

### Tastatur-Shortcuts

`Strg+Alt` + Plus/Minus/0/I/P, Capture-Phase. Zwei Fallen:

- **AltGr = Strg+Alt** auf Windows/DE-Layout: In Eingabefeldern
  (`input`, `textarea`, `select`, contenteditable) wird nichts
  abgefangen, sonst würde AltGr+0 (`}`) oder AltGr+`+` (`~`) beim
  Tippen den Zoom auslösen.
- **Layout-Streuung:** Matching über `e.code` (`NumpadAdd`, `Equal`,
  `BracketRight` = DE-Plus, `Minus`, `Slash` = DE-Minus) plus
  `e.key`-Fallback.

examguard blockiert nur Strg+C/V/X/A/S und F12 — keine Kollision.

### Toolbar-Platzierung

Unten links (`bottom: 20px; left: 20px`, z-index 99980). Belegte Ecken:
examdyslexia/examkeymap oben rechts, examcalc/examsymbols unten rechts,
exammessage/examalert oben, examresourcesheet links Mitte.

## Debugging

- Console-Prefix `[ExamMagnifier]` (PHP-Errorlog ebenso).
- `window.ExamMagnifier.config` in der Konsole prüfen (fehlt sie, wurde
  nicht injiziert → Aktivierung/Benutzerliste/Kontext prüfen).
- Zustand zurücksetzen: `sessionStorage.removeItem('exammagnifier_state_<ref_id>')`.
- Zoom hakt / Text unscharf: prüfen, ob ein anderes Modul/Theme
  `will-change` oder eigene Transforms auf `<body>` setzt.

## Versionshistorie

| Version | Datum | Änderungen |
|---|---|---|
| 1.0.2 | 2026-07-23 | Schiebe-Steuerung (Delta-basiert, Ratsche, Klick-Stabilität), Nachgleiten nur ganz außen; maturity → experimentell |
| 1.0.1 | 2026-07-23 | Rand-Schwenk statt Zeiger-Kopplung, Zentrierung auf `#tst_output`, Ruhezonen-Hinweis, `maturity`-Feld |
| 1.0.0 | 2026-07-21 | Erstversion |
