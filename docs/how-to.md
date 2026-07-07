# Modul installieren

## Voraussetzungen

- ILIAS mit installiertem und aktiviertem Plugin **ExtendedTestSettings**
  (Branch dieses Repos = ILIAS-Hauptversion: `ilias9` für ILIAS 9, `ilias10` für ILIAS 10 — Modul-ZIPs immer vom passenden Branch nehmen)
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

## Troubleshooting

### Modul „verschwindet" nach dem Hochladen / Update schlägt fehl

**Symptom:** Nach einem Upload wird das Modul nicht mehr angezeigt; der Modul-Ordner
unter `.../ExtendedTestSettings/modules/<id>/` ist leer oder unvollständig.

**Ursache:** Im Live-`modules/`-Ordner liegen Dateien, die **nicht** dem Webserver-User
(`www-data`) gehören (z.B. durch manuelles `cp`/`unzip` als `root`). Der Installer löscht
beim Update zuerst das alte Verzeichnis (`removeDirectory()` → `unlink`) und entpackt
**erst danach** neu. `www-data` darf fremde Dateien aber nicht löschen → `Permission denied`
→ der Vorgang bricht **mitten im Löschen** ab und lässt das Modul ausgehöhlt zurück.

**Im PHP-Errorlog erkennbar an:**
```
uploadModule unerwarteter Fehler: unlink(.../modules/<id>/lang/...): Permission denied
  in classes/Module/ilModuleInstaller.php:315
```

**Lösung / Vorbeugung:** Sicherstellen, dass im Live-`modules/`-Pfad **alles** `www-data`
gehört. Nach jedem manuellen Eingriff:
```bash
chown -R www-data:www-data .../ExtendedTestSettings/modules/<id>
```
Danach das ZIP erneut über den Upload-Dialog einspielen.

> Hinweis: Der Aktiv-Status eines Moduls liegt unabhängig von den Dateien in der
> ILIAS-`settings`-Tabelle (`module=extendedtestsettings`, `keyword=module_active_<id>`)
> und übersteht einen Reinstall — ein erneutes Aktivieren ist i.d.R. nicht nötig.
