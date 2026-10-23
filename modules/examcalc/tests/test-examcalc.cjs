const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const moduleDir = path.join(__dirname, '..', 'ExamCalc_v1.2.1', 'js');

function loadCalculator(mode) {
    const context = vm.createContext({
        window: {},
        document: { getElementById: () => null },
        console,
        setTimeout: () => 0,
    });
    if (mode === 'advanced') {
        vm.runInContext(fs.readFileSync(path.join(moduleDir, 'math.min.js'), 'utf8'), context);
        assert.equal(context.math.version, '15.2.0');
    }
    const file = mode === 'advanced' ? 'calculator.js' : 'calculator-simple.js';
    vm.runInContext(fs.readFileSync(path.join(moduleDir, file), 'utf8'), context);
    const calculator = new context.window.ExamCalc.Calculator();
    calculator.display = { value: '' };
    return { calculator, context };
}

function check(mode, expression, expected, angleMode = 'deg') {
    const { calculator } = loadCalculator(mode);
    calculator.angleMode = angleMode;
    calculator.current = expression;
    calculator.calculate();
    assert.notEqual(calculator.display.value, 'Fehler', `${mode}: ${expression}`);
    assert.ok(Math.abs(Number(calculator.display.value) - expected) < 1e-9,
        `${mode}: ${expression} yielded ${calculator.display.value}, expected ${expected}`);
}

for (const mode of ['simple', 'advanced']) {
    check(mode, '2+3*4', 14);
    check(mode, '(2+3)*4', 20);
    check(mode, '0.1+0.2', 0.3);
    check(mode, '-3+5', 2);
}

for (const [expression, expected, angleMode] of [
    ['2^10', 1024],
    ['√9', 3],
    ['√(4+5)', 3],
    ['log(100)', 2],
    ['ln(e)', 1],
    ['exp(ln(5))', 5],
    ['sin(30)', 0.5],
    ['cos(90)', 0],
    ['tan(45)', 1],
    ['asin(0.5)', 30],
    ['sin(asin(0.5))', 0.5],
    ['sin(π/6)', 0.5, 'rad'],
    ['cos(π)', -1, 'rad'],
    ['1/(4)', 0.25],
    ['5!', 120],
    ['50%', 0.5],
    ['2EE3', 2000],
]) {
    check('advanced', expression, expected, angleMode);
}

const { calculator: badInput } = loadCalculator('advanced');
badInput.current = 'constructor';
badInput.calculate();
assert.equal(badInput.display.value, 'Ungültige Eingabe');

const { context } = loadCalculator('advanced');
let getterCalled = false;
assert.throws(() => context.math.matrix([1]).get({
    length: 1,
    reduce: () => { getterCalled = true; return 0; },
}));
assert.equal(getterCalled, false, 'math.js must reject object indices before invoking methods');

console.log('ExamCalc: beide Modi, wissenschaftliche Funktionen und math.js-Indexschutz OK');
