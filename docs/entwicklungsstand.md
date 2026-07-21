# Entwicklungsstand der Module (maturity)

Jedes Modul trägt in seiner `module.json` das Feld `"maturity"` mit dem
Entwicklungsstand. Das Feld steht in der Repo-Kopie
(`modules/<id>/module.json`) und im Quellordner; in die Release-ZIPs wandert
es mit dem jeweils nächsten regulären Release (kein eigener Versions-Bump nur
für dieses Feld). Zusätzlich zeigt jede Modul-README den Stand als Zeile
`> **Entwicklungsstand:** …` direkt unter der Überschrift.

## Stufen

| # | Schlüssel | Anzeige | Bedeutung |
|---|---|---|---|
| 1 | `konzept` | Konzept | Idee/Spec, noch kein lauffähiger Code |
| 2 | `experimentell` | Experimentell | Prototyp/Machbarkeitsstudie — Architektur und Fortbestand offen, kann verworfen werden |
| 3 | `entwicklung` | In Entwicklung | wird ernsthaft gebaut, Funktionsumfang wächst noch |
| 4 | `test` | Im Test | auf dem Dev-System installiert und technisch validiert |
| 5 | `pilot` | Pilotbetrieb | Einsatz in ersten echten Klausuren, gezielt unter Beobachtung |
| 6 | `erprobt` | Prüfungserprobt | produktiv im Prüfungseinsatz |
| 7 | `eingestellt` | Eingestellt | nicht mehr einsetzen (ersetzt/stillgelegt) |

## Regeln

- Der Stand wird **pro Versionslinie** (Branch `ilias9`, `ilias10`, …) gepflegt —
  dieselbe Funktion kann auf ILIAS 9 `erprobt` und auf ILIAS 10 erst `test` sein.
- Für neue Versionslinien gilt als Startwert: **min(Stand der Quell-Linie, `test`)** —
  auf der neuen Plattform lief noch keine echte Klausur, unreifere Module behalten
  ihren niedrigeren Stand.
- Stufenwechsel sind ein bewusster Schritt (kein Automatismus) und brauchen keinen
  Versions-Bump; sie werden einfach committet.
- Ein Modul darf Stufen überspringen oder direkt auf `eingestellt` wandern
  (z. B. von `experimentell`, wenn ein Prototyp verworfen wird).
