# ExamCalc – Regressionstests

Vom Root dieses Distributions-Repositories aus ausführen:

```bash
node modules/examcalc/tests/test-examcalc.cjs
php modules/examcalc/tests/test-handler.php
```

Der Node-Test lädt die mitgelieferte math.js-Datei und die echten Rechner-Skripte in einem isolierten JavaScript-Kontext. Er prüft beide Modi, typische wissenschaftliche Funktionen und die Ablehnung eines ungültigen Matrix-Index durch math.js. Der PHP-Test verwendet den echten Handler mit einer Datenbank-Attrappe und prüft Modusauswahl, gespeicherte Altwerte und die sichere Ausgabe von Pfad und Modus als JavaScript.
