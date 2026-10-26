# ExamGrouping — Entwicklerdokumentation

**Modul-ID:** `examgrouping`  
**Speicherort:** `modules/examgrouping/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `modules-zip/modules/examgrouping/ExamGrouping_v10.0.1.zip`
**Zuletzt aktualisiert:** 2026-07-07

---

## ILIAS-10-Port (Version 10.0.0, 2026-07-07)

Start der ILIAS-10-Linie (Branch `ilias10`; Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1).
module.json: `version` 10.0.0, `ilias_min`/`ilias_max` = 10.

- Keine Code-Änderungen nötig. Web-Asset-Pfade (addJavaScript/addCss) funktionieren über den zentralen Registry-Compat des Hauptplugins (ab 10.0.0: Handler erhalten den web-relativen Pfad via `getRelativeDirectory()`).

---

## Was ist ExamGrouping?

Erlaubt es, die Fragen eines ILIAS-Tests zu **benannten Gruppen** (z.B. "Grundlagen",
"Anwendung", "Analyse") zusammenzufassen und die **Gesamtpunktzahl je Gruppe** aus
den Testergebnissen auszuwerten.

Das Modul erkennt automatisch ob ein Test eine **fixe Fragenliste** oder **Fragenpools
(Zufallstest)** verwendet und wählt die passende Gruppierungs-Logik:

- **Fixer Test (`FIXED_QUEST_SET`):** Einzelne Fragen werden Gruppen zugeordnet.
- **Zufallstest (`RANDOM_QUEST_SET`):** Fragenpools werden Gruppen zugeordnet.
  Die Max-Punkte je Gruppe variieren pro Teilnehmer abhängig von den gezogenen Fragen.

**Wichtig:** Das Modul schreibt ausschließlich in eigene Tabellen (`examgrouping_*`, `examgrp_*`).
ILIAS-Kerntabellen werden nur gelesen.

---

## Dateistruktur

```
modules/examgrouping/
├── module.json                     ← Modul-Metadaten inkl. GUI-Registrierung
├── class.ilExamGroupingHandler.php ← Handler (implements ilExamModuleInterface)
├── class.ilExamGroupingGUI.php     ← Verwaltungs-GUI (Gruppen + Ergebnisse + Export)
└── sql/
    ├── install.php                 ← Legt examgrouping_groups, examgrouping_items,
    │                                  examgrp_pool_items an
    └── uninstall.php               ← Löscht alle drei Tabellen
```

---

## module.json

```json
{
    "id": "examgrouping",
    "label": "Fragengruppierung",
    "description": "Fragen eines Tests zu benannten Gruppen zusammenfassen ...",
    "version": "1.0.4",
    "ilias_min": "9",
    "ilias_max": "9",
    "handler_class": "ilExamGroupingHandler",
    "handler_file": "class.ilExamGroupingHandler.php",
    "gui_file": "class.ilExamGroupingGUI.php",
    "gui_class": "ilExamGroupingGUI",
    "require_files": [],
    "tables": ["examgrouping_groups", "examgrouping_items", "examgrp_pool_items"]
}
```

---

## Routing-Kette

```
ilUIPluginRouterGUI
  └── ilExtendedTestSettingsModuleProxyGUI  (?module_id=examgrouping&cmd=show&ref_id=...)
        └── ilExamGroupingGUI::executeCommand()   (geladen via require_once)
```

Der Handler (`ilExamGroupingHandler`) erscheint im "Erweiterte Test-Einstellungen"-Tab
und zeigt dort einen Link. Der Link zeigt auf `ilExtendedTestSettingsModuleProxyGUI`.
Die eigentliche Verwaltungsoberfläche läuft in `ilExamGroupingGUI`.

---

## Datenbank

### `examgrouping_groups`

Speichert Gruppen-Definitionen je Test — identisch für fixe und Zufallstests.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | integer (PK, Sequence) | Auto-Increment |
| `test_id` | integer | ILIAS `obj_id` des Tests |
| `name` | text(255) | Anzeigename der Gruppe |
| `position` | integer | Sortierreihenfolge (aufsteigend) |
| `created_at` | timestamp | Erstellungszeitpunkt |
| `threshold_type` | text(10) | `'percent'` oder `'points'` |
| `threshold_value` | float | Schwellenwert für "Bestanden" (Default: 50) |

Index: `i1` auf `test_id`.

### `examgrouping_items`

Fragen-Zuordnung für **fixe Tests**.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | integer (PK, Sequence) | Auto-Increment |
| `group_id` | integer | FK → `examgrouping_groups.id` |
| `question_id` | integer | ILIAS `question_fi` (aus `tst_test_question`) |

Index: `i1` auf `group_id`.

### `examgrp_pool_items`

Pool-Zuordnung für **Zufallstests**.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | integer (PK, Sequence) | Auto-Increment |
| `group_id` | integer | FK → `examgrouping_groups.id` |
| `pool_id` | integer | ILIAS `obj_id` des Fragenpools (`tst_rnd_quest_set_qpls.pool_fi`) |

Index: `i1` auf `group_id`.

Keine echte FK-Constraint (ILIAS-Konvention). Beim Löschen einer Gruppe werden die
zugehörigen Items in beiden Tabellen im Code explizit mitgelöscht.

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| `tst_tests` | `test_id` und `question_set_type` ermitteln |
| `tst_test_question` | Fragen des Tests mit Sequence-Nummer (fixer Test) |
| `qpl_questions` | Fragetitel, Maximalpunktzahl, `obj_fi` (Pool-Zugehörigkeit), `original_id` |
| `tst_rnd_quest_set_qpls` | Konfigurierte Fragenpools des Zufallstests |
| `tst_test_rnd_qst` | Gezogene Fragen je Teilnehmer/Pass (Zufallstest) |
| `tst_active` | Abgeschlossene Durchläufe (`submitted = 1`) + Abgabedatum |
| `tst_test_result` | Erreichte Punkte je Frage und Durchlauf |
| `usr_data` | Name und Login der Teilnehmer |

---

## ilExamGroupingHandler

Implementiert `ilExamModuleInterface`.

```
isEnabled()     → gibt immer false zurück (kein Testplayer-Widget)
injectAssets()  → no-op (kein Testplayer-Asset)
getFormItems()  → gibt [ilCustomInputGUI] mit Info-Text + Link zur Gruppen-Verwaltung zurück
saveSettings()  → gibt true zurück (nichts zu speichern)
getInfo()       → liefert title, icon, description (für AdvancedGUI-Formular-Header)
```

---

## ilExamGroupingGUI — Commands

| cmd | Methode | Beschreibung |
|---|---|---|
| `show` (default) | `show()` | Hauptansicht: erkennt Test-Typ, verzweigt in fixe oder Pool-Ansicht |
| `addGroup` | `addGroup()` | Formular: neue Gruppe anlegen |
| `saveGroup` | `saveGroup()` | POST: neue Gruppe speichern |
| `deleteGroup` | `deleteGroup()` | Gruppe + Items löschen |
| `editGroup` | `editGroup()` | Fragen- oder Pool-Zuweisung (erkennt Test-Typ automatisch) |
| `saveGroupQuestions` | `saveGroupQuestions()` | POST: Zuweisung speichern (fixer Test oder Zufallstest) |
| `editGroupSettings` | `editGroupSettings()` | Name + Schwellenwert bearbeiten |
| `saveGroupSettings` | `saveGroupSettings()` | POST: Einstellungen speichern |
| `exportResults` | `exportResults()` | CSV-Download, ruft `exit()` auf |

### Interne Verzweigung nach Test-Typ

`show()`, `editGroup()`, `saveGroupQuestions()` und `exportResults()` rufen `isRandomTest()`
und leiten in den jeweils passenden privaten Zweig weiter:

```
editGroup()
  ├── isRandomTest() → editGroupPools()       ← Pool-Checkboxen, speichert in examgrp_pool_items
  └── !isRandomTest() → editGroupQuestions()  ← Fragen-Checkboxen, speichert in examgrouping_items

saveGroupQuestions()
  ├── isRandomTest() → saveGroupPools()
  └── !isRandomTest() → saveGroupFixedQuestions()

exportResults()
  ├── isRandomTest() → exportRandomResults()
  └── !isRandomTest() → [bestehende Export-Logik]
```

### Command-Erkennung

Da `ilExamGroupingGUI` nicht in ilCtrl registriert ist, kann `$ctrl->getCmd()` nicht
verwendet werden. Stattdessen:

```php
$post_cmd = $_POST['cmd'] ?? null;
$cmd = is_array($post_cmd) ? (string) array_key_first($post_cmd) : ($_GET['cmd'] ?? 'show');
```

### Proxy-Routing in der GUI

Da `ilExamGroupingGUI` nicht in ilCtrl registriert ist, werden alle URL- und
Redirect-Generierungen über drei Hilfsmethoden auf den Proxy umgeleitet:

```php
private function proxyLink(string $cmd, array $params = []): string
private function proxyFormAction(string $cmd, array $params = []): string
private function proxyRedirect(string $cmd, array $params = []): void
```

> **Nie** `$this->ctrl->setParameter($this, ...)` oder `$this->ctrl->redirect($this, ...)`
> verwenden — `ilExamGroupingGUI` ist nicht in ilCtrl und jeder solche Aufruf wirft
> `RuntimeException: Class not found in control structure`.

---

## Zufallstest — Besonderheiten

### Pool-Konfiguration lesen

Die konfigurierten Pools werden aus `tst_rnd_quest_set_qpls` geladen (JOIN mit
`object_data` für den aktuellen Pool-Titel):

```sql
SELECT p.pool_fi, p.pool_title, p.quest_amount, p.sequence_pos,
       COALESCE(od.title, p.pool_title) AS actual_title
FROM tst_rnd_quest_set_qpls p
LEFT JOIN object_data od ON od.obj_id = p.pool_fi
WHERE p.test_fi = ?
ORDER BY p.sequence_pos
```

### Pass-Erkennung (Zufallstest)

Für Zufallstests wird `getSubmittedPassesRandom()` statt `getSubmittedPasses()`
verwendet. Die Passes werden über `tst_test_rnd_qst` identifiziert (gezogene Fragen),
nicht über `tst_test_result` — da letzteres bei automatischer Auswertung leer sein kann:

```sql
SELECT DISTINCT active_fi, pass FROM tst_test_rnd_qst
```

### Fragen-Kopien und `original_id`

ILIAS kopiert Pool-Fragen intern beim Zufallstest. `tst_test_rnd_qst.question_fi`
zeigt auf die **Kopie** in `qpl_questions`. Die Kopie hat eine abweichende `obj_fi`
(interner Schatten-Pool), nicht die Original-Pool-ID.

Die Pool-Zuordnung wird deshalb über `original_id` hergestellt:

```sql
SELECT ttrq.question_fi, copy_q.points AS max_points
FROM tst_test_rnd_qst ttrq
JOIN qpl_questions copy_q ON copy_q.question_id = ttrq.question_fi
JOIN qpl_questions orig    ON orig.question_id  = copy_q.original_id
WHERE ttrq.active_fi = ? AND ttrq.pass = ? AND orig.obj_fi IN (pool_ids)
```

`copy_q.points` wird für die Maximalpunkte verwendet (korrekt für Scoring),
`orig.obj_fi` für den Pool-Vergleich.

### Max-Punkte variieren je Teilnehmer

Da jeder Teilnehmer andere Fragen gezogen bekommt, ist die maximale Punktzahl
pro Gruppe individuell. Die Ergebnistabelle zeigt daher "X von Y Pkt" mit
dem jeweils individuellen Y. Die Ø-Zeile zeigt "von Ø Z Pkt".

---

## Schwellenwert-Logik

Pro Gruppe konfigurierbar:

- **`percent`:** Bestanden wenn `(erreicht / max) * 100 >= schwellenwert`
  (bei max = 0: nicht bewertet)
- **`points`:** Bestanden wenn `erreicht >= schwellenwert`

Default: `percent`, Wert `50`.

---

## Ergebnistabelle

### Mehrere Durchläufe

Jeder Teilnehmer kann mehrere Testdurchläufe haben. Die Tabelle zeigt alle
Durchläufe untereinander, der Teilnehmername wird via `rowspan` zusammengefasst.

**Trendpfeile** (gegenüber dem vorherigen Durchlauf, basierend auf Gesamt-Punkten):
- `▲` grün = Verbesserung
- `▼` rot = Verschlechterung
- `→` grau = gleich

### Spaltenstruktur (fixer Test)

```
Teilnehmer | Durchlauf | [Gruppe A] | [Gruppe B] | ... | Nicht zugeordnet* | Gesamt
```

### Spaltenstruktur (Zufallstest)

```
Teilnehmer | Durchlauf | [Gruppe A (Pool-Namen)] | ... | Nicht zugeordnet* | Gesamt
```

*Spalte "Nicht zugeordnet" erscheint nur wenn es Fragen/Pools ohne Gruppe gibt.

**Ø-Zeile:** Durchschnitt über den **letzten Durchlauf** je Teilnehmer.

### Technische Umsetzung (fixer Test)

```
getSubmittedPasses()     → alle Passes aller Teilnehmer (tst_test_result)
getTestQuestions()       → Fragen mit Sequence + Titel + Maximalpunktzahl
getQuestionResults()     → erreichte Punkte je Frage für einen Pass
getGroupQuestions()      → zugeordnete question_ids für eine Gruppe
```

### Technische Umsetzung (Zufallstest)

```
getSubmittedPassesRandom() → alle Passes aller Teilnehmer (tst_test_rnd_qst)
getTestPools()             → konfigurierte Pools (tst_rnd_quest_set_qpls)
getGroupPools()            → zugeordnete pool_ids für eine Gruppe
getRandomGroupResults()    → erreichte + max Punkte je Gruppe/Pass
                             (über original_id auf Pool-IDs gemappt)
```

---

## CSV-Export

**Alle Durchläufe** aller Teilnehmer als einzelne Zeilen.

**Format:**
- Trennzeichen: `;` (Excel-DE-kompatibel)
- Encoding: UTF-8 mit BOM
- Dezimaltrennzeichen: `,`
- Dateiname: `fragengruppierung_<Testname>_<YYYYMMDD_HHmmss>.csv`

**Spaltenstruktur fixer Test:**
```
Name | Login | Durchlauf | Datum |
  [Gruppe A] F1: Titel (Pkt) | F1 Max |
  [Gruppe A] Gesamt Pkt | Max Pkt | % | Bestanden |
  ... weitere Gruppen ...
  [Nicht zugeordnet] ...
  Gesamt Test Pkt | Gesamt Test Max | Gesamt Test %
```

**Spaltenstruktur Zufallstest:**
```
Name | Login | Durchlauf | Datum |
  [Gruppe A] Pools: Pool1, Pool2 | Erreicht Pkt | Max Pkt | % | Bestanden |
  ... weitere Gruppen ...
  [Nicht zugeordnet] Erreicht | Max | %
  Gesamt Pkt | Gesamt Max | Gesamt %
```

---

## Installation / Update

### Erstinstallation

1. ZIP in ILIAS hochladen:
   `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv)
3. In den Erweiterten Test-Einstellungen eines Tests erscheint der Link

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — der Installer ersetzt das alte
Modul-Verzeichnis. `sql/install.php` läuft erneut (daher immer `tableExists()`-Guard!).

> Bei einem Update von v1.0.2 auf v1.0.3+: Die neue Tabelle `examgrp_pool_items`
> wird von `install.php` automatisch angelegt, falls noch nicht vorhanden.

---

## Changelog

| Datum | Version | Was |
|---|---|---|
| 2026-05-26 | **v1.0.4** | Bugfix: Pass-Erkennung für Zufallstests via `tst_test_rnd_qst` statt `tst_test_result`; Pool-Matching über `qpl_questions.original_id` (ILIAS kopiert Pool-Fragen intern) |
| 2026-05-26 | **v1.0.3** | Zufallstest-Unterstützung: Pool-basierte Gruppierung, neue Tabelle `examgrp_pool_items`, `getSubmittedPassesRandom()`, `getRandomGroupResults()`, eigener CSV-Export für Zufallstests |
| 2026-05-12 | **v1.0.2** | ILIAS-Kompatibilität auf 9 beschränkt |
| 2026-04-10 | **v1.0.1** | Proxy-Routing, Interface-Fix, Formular-Submit-Fix, `gui_file`/`gui_class` in module.json |
| 2026-04-10 | **v1.0.0** | Initiale Implementierung: Gruppen, Fragen-Zuweisung, Schwellenwert, Ergebnistabelle, CSV-Export, mehrere Durchläufe, Trendpfeile |
