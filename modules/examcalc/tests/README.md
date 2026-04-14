# ExamCalc – Regressionstest

## Ausführen

Vom Plugin-Root aus:

```bash
node js/examcalc/tests/test-examcalc.cjs
```

Exit-Code `0` = alle Tests grün. Exit-Code `1` = mindestens ein Fehler.

## Was getestet wird

| Abschnitt | Beispiele |
|-----------|-----------|
| Grundrechenarten | `2+3`, `0.1+0.2`, negative Zahlen |
| Operator-Priorität & Klammern | `2+3*4=14`, `(2+3)*4=20` |
| Potenzen & Wurzeln | `2^10`, `√9`, `√(4+5)`, `10^(3)` |
| Logarithmen | `log(100)=2`, `ln(e)=1`, `exp(ln(5))=5` |
| Konstanten | `π`, `e` mit 10-Stellen-Toleranz |
| Trig DEG-Modus | `sin(30)=0.5`, `cos(90)=0`, `tan(45)=1` |
| Inverse Trig DEG | `asin(0.5)=30`, `acos(0.5)=60`, `atan(1)=45` |
| Trig RAD-Modus | `sin(π/6)=0.5`, `cos(π)=-1` |
| Inverse Trig RAD | `atan(1)=π/4` |
| Verschachtelte Funktionen | `ln(sqrt(4))`, `sin(asin(0.5))` |
| Kehrwert | `1/(4)=0.25` |
| Fakultät | `5!=120`, `10!=3628800` |
| Prozent | `50%=0.5`, `100*50%=50` |
| Epsilon-Cleanup | `sin(30°)→0.5` exakt, `0.1+0.2→0.3` |
| Große/kleine Zahlen | `2^32`, `1e-10` |
| Fehler-Fälle | `eval()`, unbekannte Funktionen, `log(0)`, `1/0` |

## Pipeline (muss mit `calculator.js` synchron bleiben)

Der Test spiegelt `evaluateWithMathJs()` + `applyEpsilonCleanup()` exakt nach.
Wenn sich diese Funktionen in `calculator.js` ändern, muss der Test entsprechend
angepasst werden — insbesondere:

- **Transformationen** (`evalMath`-Funktion oben im Test): `log→log10`, `ln→log`, `√`, `π`, `%`
- **DEG-Scope** (`evalDeg`): `sin/cos/tan` mit `×π/180`, `asin/acos/atan` mit `×180/π`
- **Epsilon-Cleanup** (`epsilonCleanup`): Schwellwert und `toPrecision(10)`

## Neuen Test hinzufügen

```js
section('Mein neuer Abschnitt');
t('Beschreibung', evalDeg('sin(90)'), 1);        // DEG-Modus
t('Beschreibung', evalRad('sqrt(2)'), Math.sqrt(2), 1e-9); // RAD / mit Toleranz
tErr('Beschreibung', () => evalRad('ungültig()')); // muss Fehler werfen
```

Toleranz (3. Parameter von `t()`): Standard `1e-9`. Für Konstanten wie `π` reicht `1e-9`.
