# Modul installieren

## Voraussetzungen

- ILIAS 9 mit installiertem und aktiviertem Plugin **ExtendedTestSettings**
- Admin-Zugang zu ILIAS

## Schritt-für-Schritt

1. ZIP-Datei des gewünschten Moduls aus diesem Repository herunterladen
   (z.B. `modules/examgrouping/ExamGrouping_v1.0.4.zip`)

2. In ILIAS einloggen und navigieren zu:
   `Administration → Plugins → ExtendedTestSettings → Module`

3. Im Bereich „Modul hochladen" die ZIP-Datei auswählen und auf **Hochladen** klicken

4. Das Modul erscheint nun in der Liste — auf **Aktivieren** klicken

5. Das Modul ist jetzt in den erweiterten Test-Einstellungen aller Tests verfügbar

## Modul deaktivieren / deinstallieren

- **Deaktivieren:** Modul bleibt installiert, erscheint aber nicht mehr in den Test-Einstellungen
- **Deinstallieren:** Entfernt Modul-Dateien und löscht alle zugehörigen Datenbank-Tabellen

> Achtung: Beim Deinstallieren gehen alle gespeicherten Modul-Daten verloren.

## Update auf neue Version

Neue ZIP-Version über denselben Upload-Dialog hochladen — der Installer ersetzt das
bestehende Modul-Verzeichnis automatisch. Die Datenbanktabellen bleiben erhalten,
sofern `sql/install.php` des Moduls mit `tableExists()`-Guards geschrieben ist.
