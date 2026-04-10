# ExamGrouping — Entwicklerdokumentation

**Modul-ID:** `examgrouping`  
**Speicherort:** `modules/examgrouping/` (externes Modul, nicht im Git-Repo)  
**Referenz-ZIP:** `.dev/ExamGrouping_v1.0.1.zip`  
**Zuletzt aktualisiert:** 2026-04-10

---

## Was ist ExamGrouping?

Erlaubt es, die Fragen eines ILIAS-Tests zu **benannten Gruppen** (z.B. "Grundlagen",
"Anwendung", "Analyse") zusammenzufassen und die **Gesamtpunktzahl je Gruppe** aus
den Testergebnissen auszuwerten.

**Wichtig:** Das Modul schreibt ausschließlich in eigene Tabellen (`examgrouping_*`).
ILIAS-Kerntabellen werden nur gelesen.

---

## Einschränkungen

- Nur bei Tests mit **fixer Fragenliste** (`FIXED_QUEST_SET`).
  Bei Random-Question-Sets wird eine Hinweismeldung angezeigt und die Funktion verweigert,
  weil die Fragenzusammenstellung je Teilnehmer variiert.
- Zugänglich nur für Nutzer mit Schreibrecht auf den Test oder System-Admins.

---

## Dateistruktur

```
modules/examgrouping/
├── module.json                     ← Modul-Metadaten inkl. GUI-Registrierung
├── class.ilExamGroupingHandler.php ← Handler (implements ilExamModuleInterface)
├── class.ilExamGroupingGUI.php     ← Verwaltungs-GUI (Gruppen + Ergebnisse + Export)
└── sql/
    ├── install.php                 ← Legt examgrouping_groups + examgrouping_items an
    └── uninstall.php               ← Löscht beide Tabellen
```

---

## module.json

```json
{
    "id": "examgrouping",
    "label": "Fragengruppierung",
    "description": "Fragen eines Tests zu benannten Gruppen zusammenfassen ...",
    "version": "1.0.1",
    "ilias_min": "9",
    "ilias_max": "12",
    "handler_class": "ilExamGroupingHandler",
    "handler_file": "class.ilExamGroupingHandler.php",
    "gui_file": "class.ilExamGroupingGUI.php",
    "gui_class": "ilExamGroupingGUI",
    "require_files": [],
    "tables": ["examgrouping_groups", "examgrouping_items"]
}
```

`gui_file` + `gui_class` sind nötig damit der Proxy-Router die GUI-Klasse findet.
`require_files` ist leer, weil `ilExamGroupingGUI` direkt in `gui_file` angegeben ist
und nicht als Abhängigkeit des Handlers geladen werden muss.

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

Speichert Gruppen-Definitionen je Test.

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

Speichert Fragen-Zuordnungen zu Gruppen.

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | integer (PK, Sequence) | Auto-Increment |
| `group_id` | integer | FK → `examgrouping_groups.id` |
| `question_id` | integer | ILIAS `question_fi` (aus `tst_test_question`) |

Index: `i1` auf `group_id`.

Keine echte FK-Constraint (ILIAS-Konvention). Beim Löschen einer Gruppe werden die
zugehörigen Items im Code explizit mitgelöscht.

### Gelesene ILIAS-Tabellen (read-only)

| Tabelle | Wozu |
|---|---|
| `tst_tests` | `test_id` (interner PK) und `question_set_type` ermitteln |
| `tst_test_question` | Fragen des Tests mit Sequence-Nummer laden |
| `qpl_questions` | Fragetitel und Maximalpunktzahl |
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

`getFormItems()` ruft intern `getSettingsHTML()` auf und verpackt das Ergebnis in eine
`ilCustomInputGUI`. Der Link darin zeigt über den Proxy auf `ilExtendedTestSettingsModuleProxyGUI`:

```php
$ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', 'module_id', 'examgrouping');
$ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', 'ref_id', $ref_id);
$link = $ctrl->getLinkTargetByClass(
    ['ilUIPluginRouterGUI', 'ilExtendedTestSettingsModuleProxyGUI'],
    'show'
);
```

---

## ilExamGroupingGUI — Commands

| cmd | Methode | Beschreibung |
|---|---|---|
| `show` (default) | `show()` | Hauptansicht: Gruppen-Tabelle + Ergebnistabelle |
| `addGroup` | `addGroup()` | Formular: neue Gruppe anlegen |
| `saveGroup` | `saveGroup()` | POST: neue Gruppe speichern |
| `deleteGroup` | `deleteGroup()` | Gruppe + Items löschen |
| `editGroup` | `editGroup()` | Fragen-Zuweisung (Checkboxen) |
| `saveGroupQuestions` | `saveGroupQuestions()` | POST: Fragen-Zuweisung speichern |
| `editGroupSettings` | `editGroupSettings()` | Name + Schwellenwert bearbeiten |
| `saveGroupSettings` | `saveGroupSettings()` | POST: Einstellungen speichern |
| `exportResults` | `exportResults()` | CSV-Download, ruft `exit()` auf |

### Command-Erkennung

Da `ilExamGroupingGUI` nicht in ilCtrl registriert ist, kann `$ctrl->getCmd()` nicht
verwendet werden. Stattdessen:

```php
$post_cmd = $_POST['cmd'] ?? null;
$cmd = is_array($post_cmd) ? (string) array_key_first($post_cmd) : ($_GET['cmd'] ?? 'show');
```

ILIAS rendert Command-Buttons als `<input name="cmd[saveGroup]">` — daher ist
`$_POST['cmd']` ein Array, dessen erster Key der Command-Name ist.

### Proxy-Routing in der GUI

Da `ilExamGroupingGUI` nicht in ilCtrl registriert ist, werden alle URL- und
Redirect-Generierungen über drei Hilfsmethoden auf den Proxy umgeleitet.
Parameter werden **immer als zweites Array-Argument** übergeben:

```php
// Navigations-Link
private function proxyLink(string $cmd, array $params = []): string

// Form-Action für ilPropertyFormGUI
private function proxyFormAction(string $cmd, array $params = []): string

// Redirect (wirft Header-Location)
private function proxyRedirect(string $cmd, array $params = []): void
```

Alle drei setzen intern `module_id=examgrouping` via `setParameterByClass` auf
`ilExtendedTestSettingsModuleProxyGUI` und routen über
`['ilUIPluginRouterGUI', 'ilExtendedTestSettingsModuleProxyGUI']`.

**Beispiele:**
```php
// Link generieren
$add_link = $this->proxyLink('addGroup', ['ref_id' => $ref_id]);

// Formular-Action mit mehreren Params
$form->setFormAction($this->proxyFormAction('saveGroupSettings', [
    'ref_id'   => $ref_id,
    'group_id' => $group_id,
]));

// Redirect nach Speichern
$this->proxyRedirect('show', ['ref_id' => $ref_id]);
```

> **Nie** `$this->ctrl->setParameter($this, ...)` oder `$this->ctrl->redirect($this, ...)`
> verwenden — `ilExamGroupingGUI` ist nicht in ilCtrl und jeder solche Aufruf führt zu
> einem `RuntimeException: Class not found in control structure`.

---

## Schwellenwert-Logik

Pro Gruppe konfigurierbar:

- **`percent`:** Bestanden wenn `(erreicht / max) * 100 >= schwellenwert`
- **`points`:** Bestanden wenn `erreicht >= schwellenwert`

Default: `percent`, Wert `50` (= mind. 50% der Gruppenpunkte).

Wird für die Grün/Rot-Färbung in der Ergebnistabelle und die "Bestanden"-Spalte im CSV verwendet.

---

## Ergebnistabelle

### Mehrere Durchläufe

Jeder Teilnehmer kann mehrere Testdurchläufe haben (`tst_active` + `tst_test_result`).
Die Tabelle zeigt alle Durchläufe untereinander, der Teilnehmername wird via `rowspan`
zusammengefasst.

**Trendpfeile** (gegenüber dem vorherigen Durchlauf, basierend auf Gesamt-Punkten):
- `▲` grün = Verbesserung
- `▼` rot = Verschlechterung  
- `→` grau = gleich

### Spaltenstruktur

```
Teilnehmer | Durchlauf | [Gruppe A] | [Gruppe B] | ... | Nicht zugeordnet* | Gesamt
```

*Spalte "Nicht zugeordnet" erscheint nur wenn es Fragen gibt, die keiner Gruppe zugewiesen sind.

**Gesamt-Spalte:** Summe aller Fragen des Tests unabhängig von Gruppen — entspricht immer
dem tatsächlichen ILIAS-Testergebnis.

**Ø-Zeile:** Durchschnitt über den **letzten Durchlauf** je Teilnehmer (nicht alle Durchläufe,
damit mehrfach antretende Teilnehmer den Schnitt nicht verzerren).

### Technische Umsetzung

```
getSubmittedPasses()     → alle Passes aller Teilnehmer (aufsteigend nach pass-Nr)
                           → gruppiert nach active_id in $by_participant
getTestQuestions()       → Fragen mit Sequence + Titel + Maximalpunktzahl
getQuestionResults()     → erreichte Punkte je Frage für einen Pass
getGroupsForTest()       → Gruppen-Definitionen mit threshold-Feldern
getGroupQuestions()      → zugeordnete question_ids für eine Gruppe
```

`getGroupsForTest()` prüft via `tableColumnExists()` ob `threshold_type`/`threshold_value`
existieren — Rückfall auf Query ohne diese Spalten wenn Tabelle noch altes Schema hat.

---

## CSV-Export

**Trigger:** Button "CSV exportieren" rechts über der Ergebnistabelle.  
**Alle Durchläufe** aller Teilnehmer werden als einzelne Zeilen exportiert.

**Spaltenstruktur:**
```
Name | Login | Durchlauf | Datum |
  [Gruppe A] F1: Titel (Max Pkt) | F1 Max |
  [Gruppe A] ... |
  [Gruppe A] Gesamt Pkt | Max Pkt | % | Bestanden |
  ... weitere Gruppen ...
  [Nicht zugeordnet] ...   ← nur wenn vorhanden
  Gesamt Test Pkt | Gesamt Test Max | Gesamt Test %
```

**Format:**
- Trennzeichen: `;` (Excel-DE-kompatibel)
- Encoding: UTF-8 mit BOM (Excel erkennt Umlaute direkt)
- Dezimaltrennzeichen: `,`
- Dateiname: `fragengruppierung_<Testname>_<YYYYMMDD_HHmmss>.csv`

---

## Installation / Update

### Erstinstallation

1. ZIP in ILIAS hochladen:
   `Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen`
2. Modul in der Liste aktivieren (Standard: inaktiv)
3. In den Erweiterten Test-Einstellungen eines Tests erscheint jetzt der Link
   "Gruppen verwalten / Ergebnisse anzeigen"

### Update (neue ZIP-Version)

Gleiches Vorgehen wie Erstinstallation — der Installer entfernt das alte
Modul-Verzeichnis und entpackt die neue Version. `sql/install.php` läuft erneut
(daher immer `tableExists()`-Guard!).

> Achtung: Es gibt derzeit keinen automatischen DB-Migrations-Mechanismus für
> externe Module. Wenn das Schema sich ändert, muss `install.php` defensiv sein
> (z.B. via `tableColumnExists()` prüfen und Spalten nachträglich hinzufügen).

---

## Changelog

| Datum | Was |
|---|---|
| 2026-04-10 | Initiale Implementierung: Gruppen, Fragen-Zuweisung, Schwellenwert, Ergebnistabelle, CSV-Export |
| 2026-04-10 | Mehrere Durchläufe je Teilnehmer (rowspan, Trendpfeile, Abgabedatum) |
| 2026-04-10 | Nicht-zugeordnete-Fragen-Spalte + Gesamt summiert alle Fragen |
| 2026-04-10 | In modulares System migriert (`modules/examgrouping/`), Proxy-Routing |
| 2026-04-10 | Handler: `implements ilExamModuleInterface` ergänzt (war Bug — Modul lud nicht) |
| 2026-04-10 | **v1.0.1:** `module.json`: `gui_file`/`gui_class` ergänzt, `require_files: []` |
| 2026-04-10 | **v1.0.1:** Handler: `injectAssets()` + `getFormItems()` implementiert (Interface vollständig) |
| 2026-04-10 | **v1.0.1:** GUI: alle `ctrl->...($this,...)` durch `proxyLink/proxyFormAction/proxyRedirect` ersetzt |
| 2026-04-10 | **v1.0.1:** GUI: `executeCommand()` liest cmd aus `$_POST['cmd']` (Array) statt `$ctrl->getCmd()` |
