/**
 * calculator-simple.js - Einfacher Taschenrechner (nur Grundrechenarten)
 * Part of ExtendedTestSettings Plugin
 * 
 * SICHERHEITSFEATURES:
 * ✓ Strikte Input-Validierung (Whitelist-Ansatz)
 * ✓ Maximale Eingabelänge (DoS-Schutz)
 * ✓ Event-Isolation (stopPropagation)
 * ✓ Focus-basierte Zugriffskontrolle
 * ✓ Sanitization vor eval()
 * ✓ Ergebnis-Validierung (isFinite)
 * ✓ XSS-Schutz durch readonly Display
 * 
 * TASTATUR-SHORTCUTS:
 * - Alt + R: Taschenrechner öffnen/schließen
 * - 0-9, +, -, *, /, ., (, ): Eingabe (nur wenn Calculator aktiv)
 * - Enter oder =: Berechnung
 * - Backspace: Letztes Zeichen löschen
 * - Escape oder Delete: Alles löschen
 */

window.ExamCalc = window.ExamCalc || {};

window.ExamCalc.DEBUG = false;

window.ecLog = function (message) {
    if (window.ExamCalc.DEBUG) {
        console.log(`[ExamCalc Simple] ${message}`);
    }
};

window.ExamCalc.Calculator = class {
    constructor() {
        this.current = '';
        this.lastResult = null;
        this.btn = null;
        this.calc = null;
        this.display = null;
        this.isInitialized = false;
    }

    init() {
        this.btn = document.getElementById('calculator-btn');
        this.calc = document.getElementById('calculator');
        this.display = document.getElementById('calc-display');

        if (!this.btn || !this.calc || !this.display) {
            console.error("[ExamCalc Simple] Calculator-Elemente nicht gefunden");
            return false;
        }

        this.setupEventListeners();
        this.isInitialized = true;

        if (window.ecLog) {
            window.ecLog('✅ Einfacher Calculator initialisiert');
        }

        return true;
    }

    setupEventListeners() {
        this.btn.addEventListener('click', () => {
            this.toggle();
        });

        // Alt + R zum Öffnen/Schließen
        document.addEventListener('keydown', (e) => {
            if (e.altKey && e.key.toLowerCase() === 'r') {
                e.preventDefault();
                e.stopPropagation();
                this.toggle();
            }
        });

        // Tastatur-Eingaben für den Calculator (wenn sichtbar)
        document.addEventListener('keydown', (e) => {
            // Nur wenn Calculator sichtbar ist
            if (this.calc.style.display !== 'block') return;
            
            // SICHERHEIT: Ignoriere Tastatur-Events wenn Focus auf einem Input/Textarea ist
            // (außer es ist das Calculator-Display selbst)
            const activeElement = document.activeElement;
            if (activeElement && 
                (activeElement.tagName === 'INPUT' || 
                 activeElement.tagName === 'TEXTAREA' || 
                 activeElement.tagName === 'SELECT' ||
                 activeElement.isContentEditable) &&
                activeElement.id !== 'calc-display') {
                // User tippt in ein anderes Feld - Calculator ignoriert die Eingabe
                return;
            }
            
            const key = e.key;
            
            // Nur erlaubte Zeichen verarbeiten (Whitelist-Ansatz)
            const allowedKeys = /^[0-9+\-*/.()]$/;
            
            // Zahlen und Operatoren
            if (allowedKeys.test(key)) {
                e.preventDefault();
                e.stopPropagation();
                this.addToExpression(key);
            }
            // Enter oder = für Berechnung
            else if (key === 'Enter' || key === '=') {
                e.preventDefault();
                e.stopPropagation();
                this.calculate();
            }
            // Backspace für DEL
            else if (key === 'Backspace') {
                e.preventDefault();
                e.stopPropagation();
                this.deleteLastChar();
            }
            // Escape oder Delete für AC
            else if (key === 'Escape' || key === 'Delete') {
                e.preventDefault();
                e.stopPropagation();
                this.clear();
            }
            // Alle anderen Tasten werden ignoriert (keine Aktion)
        });

        const closeBtn = this.calc.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.hide());
        }

        this.calc.querySelectorAll('.buttons button').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.handleButtonClick(button.textContent, button.classList);
            });
        });

        this.makeCalculatorDraggable();
    }

    toggle() {
        const isVisible = this.calc.style.display === 'block';
        if (isVisible) {
            this.hide();
        } else {
            this.show();
        }
    }

    show() {
        this.calc.style.display = 'block';
        if (window.ecLog) {
            window.ecLog('🔄 Einfacher Calculator angezeigt');
        }
    }

    hide() {
        this.calc.style.display = 'none';
        if (window.ecLog) {
            window.ecLog('🔄 Einfacher Calculator versteckt');
        }
    }

    handleButtonClick(value, classList) {
        if (classList.contains('del-btn')) {
            this.deleteLastChar();
            return;
        }

        switch (value) {
            case '=':
                this.calculate();
                break;
            case 'AC':
                this.clear();
                break;
            default:
                this.addToExpression(value);
                break;
        }
    }

    deleteLastChar() {
        if (this.current.length > 0) {
            this.current = this.current.slice(0, -1);
            this.display.value = this.current || '0';
            
            // Wenn wir alles gelöscht haben und ein lastResult existiert, zeige 0
            if (this.current === '' && this.lastResult !== null) {
                this.lastResult = null;
            }
            
            if (window.ecLog) {
                window.ecLog(`🗑️ Letztes Zeichen gelöscht, aktuell: ${this.current || '(leer)'}`);
            }
        }
    }

    calculate() {
        try {
            if (this.current === '') {
                // Wenn nichts eingegeben wurde, aber ein letztes Ergebnis existiert, zeige es an
                if (this.lastResult !== null) {
                    this.display.value = this.lastResult;
                }
                return;
            }

            // SICHERHEIT: Strikte Input-Sanitization - nur Grundrechenarten
            let sanitized = this.current.replace(/[^0-9+\-*/.()]/g, '');
            
            if (!sanitized) {
                if (window.ecLog) {
                    window.ecLog('⚠️ Keine gültige Eingabe');
                }
                return;
            }

            // FIX: Implizite Multiplikation behandeln
            // 2(3) -> 2*(3), (2)3 -> (2)*3, )( -> )*(
            sanitized = sanitized.replace(/(\d)\(/g, '$1*(');  // 2( -> 2*(
            sanitized = sanitized.replace(/\)(\d)/g, ')*$1');  // )2 -> )*2
            sanitized = sanitized.replace(/\)\(/g, ')*(');     // )( -> )*(

            // SICHERHEIT: Verhindere potenziell gefährliche Patterns
            // Blockiere mehrfache Punkte in einer Zahl
            if (/\d+\.\d+\./.test(sanitized)) {
                throw new Error('Ungültige Dezimalzahl');
            }

            if (window.ecLog) {
                window.ecLog(`🔄 Vor eval: ${this.current} -> ${sanitized}`);
            }

            // SICHERHEIT: eval() ist hier kontrolliert, da wir nur Zahlen und Operatoren haben
            // Alternative wäre ein eigener Parser, aber für Grundrechenarten ist eval() akzeptabel
            // nach strikter Sanitization
            const result = eval(sanitized);

            // SICHERHEIT: Prüfe ob das Ergebnis eine gültige Zahl ist
            if (!isFinite(result)) {
                throw new Error('Ergebnis ist keine gültige Zahl');
            }

            // Runde sehr lange Dezimalzahlen
            const roundedResult = Math.round(result * 1e10) / 1e10;

            // Speichere sowohl das Ergebnis als auch setze es als current
            this.lastResult = roundedResult;
            this.current = String(roundedResult);
            this.display.value = roundedResult;

            if (window.ecLog) {
                window.ecLog(`🔢 Berechnung: ${sanitized} = ${roundedResult}`);
            }

        } catch (error) {
            this.display.value = 'Fehler';
            // NICHT this.current = 'Fehler', damit man weiter eingeben kann
            if (window.ecLog) {
                window.ecLog(`❌ Berechnungsfehler: ${error.message}`);
            }
            // Nach kurzer Zeit Fehler löschen
            setTimeout(() => {
                if (this.display.value === 'Fehler') {
                    this.clear();
                }
            }, 1500);
        }
    }

    clear() {
        this.current = '';
        this.lastResult = null;
        this.display.value = '0';
        if (window.ecLog) {
            window.ecLog('🧹 Calculator gelöscht');
        }
    }

    addToExpression(value) {
        // SICHERHEIT: Strikte Validierung - nur erlaubte Zeichen
        if (!/^[0-9+\-*/.()]$/.test(value)) {
            if (window.ecLog) {
                window.ecLog(`⛔ Ungültiges Zeichen blockiert: ${value}`);
            }
            return;
        }

        // SICHERHEIT: Verhindere zu lange Eingaben (DoS-Schutz)
        const MAX_LENGTH = 50;
        if (this.current.length >= MAX_LENGTH) {
            if (window.ecLog) {
                window.ecLog(`⚠️ Maximale Eingabelänge erreicht (${MAX_LENGTH})`);
            }
            return;
        }

        // Wenn gerade ein Ergebnis angezeigt wird und eine Zahl oder Punkt eingegeben wird,
        // starte eine neue Berechnung
        if (this.lastResult !== null && this.current === String(this.lastResult)) {
            if (!isNaN(value) || value === '.') {
                // Neue Berechnung beginnen
                this.current = value;
                this.lastResult = null;
            } else {
                // Operator wurde eingegeben - mit dem Ergebnis weiterrechnen
                this.current += value;
            }
        } else {
            // Normale Eingabe
            this.current += value;
        }
        
        this.display.value = this.current;
        
        if (window.ecLog) {
            window.ecLog(`➕ Eingabe: ${value}, Aktuell: ${this.current}`);
        }
    }

    reset() {
        this.clear();
        if (window.ecLog) {
            window.ecLog('🔄 Einfacher Calculator zurückgesetzt');
        }
    }

    makeCalculatorDraggable() {
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let startLeft = 0;
        let startTop = 0;

        this.calc.addEventListener('mousedown', (e) => {
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'INPUT') {
                return;
            }

            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;

            const rect = this.calc.getBoundingClientRect();
            startLeft = rect.left;
            startTop = rect.top;

            this.calc.style.cursor = 'grabbing';
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;

            const deltaX = e.clientX - startX;
            const deltaY = e.clientY - startY;

            this.calc.style.left = (startLeft + deltaX) + 'px';
            this.calc.style.top = (startTop + deltaY) + 'px';
            this.calc.style.right = 'auto';
            this.calc.style.bottom = 'auto';
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                this.calc.style.cursor = '';
            }
        });
    }
};