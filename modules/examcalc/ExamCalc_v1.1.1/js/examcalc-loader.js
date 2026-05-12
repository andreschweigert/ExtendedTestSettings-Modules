/**
 * examcalc-loader.js - Lädt HTML Template und initialisiert Calculator (Extended)
 * Part of ExtendedTestSettings Plugin
 * Erweitert um: Test-Ende Cleanup für Prüfungssicherheit
 */

(function () {
    'use strict';

    // Debug-Logging initialisieren falls noch nicht vorhanden
    if (typeof window.ecLog === 'undefined') {
        window.ecLog = function (msg) {
            console.log('[ExamCalc] ' + msg);
        };
    }

    /**
     * Calculator HTML Template laden und einfügen
     */
    async function loadCalculatorTemplate() {
        try {
            window.ecLog('🔄 Lade Calculator Template...');

            // Template-Pfad prüfen
            if (!window.ExamCalc || !window.ExamCalc.templatePath) {
                console.error('[ExamCalc] ❌ Kein templatePath gesetzt! window.ExamCalc:', window.ExamCalc);
                throw new Error('Template-Pfad nicht gefunden');
            }

            // NEU: Zeige welcher Modus aktiv ist
            const calculatorMode = window.ExamCalc?.calculatorMode || 'unknown';
            window.ecLog(`🎚️ Calculator-Modus: ${calculatorMode}`);
            window.ecLog(`🌐 Versuche Template zu laden von: "${window.ExamCalc.templatePath}"`);

            const response = await fetch(window.ExamCalc.templatePath);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const html = await response.text();

            // NEU: Zeige ersten Teil des geladenen HTMLs
            window.ecLog(`📄 Template geladen (${html.length} Zeichen)`);
            window.ecLog(`📄 Template-Start: ${html.substring(0, 100)}...`);

            // HTML in DOM einfügen
            const wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            document.body.appendChild(wrapper);

            window.ecLog('✅ Calculator Template geladen');

            return true;

        } catch (error) {
            window.ecLog('❌ Fehler beim Laden des Templates: ' + error.message);

            // Fallback: Inline HTML verwenden (erweitert)
            return loadCalculatorFallback();
        }
    }

    /**
     * Fallback: Calculator HTML inline erstellen (erweitert)
     */
    function loadCalculatorFallback() {
        window.ecLog('🔄 Verwende Fallback-HTML...');

        // Prüfe welcher Modus aktiv ist
        const calculatorMode = window.ExamCalc?.calculatorMode || 'simple';
        window.ecLog(`📋 Fallback-Modus erkannt: ${calculatorMode}`);

        let calcHTML = '';

        if (calculatorMode === 'simple') {
            // EINFACHER CALCULATOR
            calcHTML = `
            <button id="calculator-btn" title="Taschenrechner (Alt + R)">🔢</button>
            <div id="calculator" style="display: none;">
                <div class="header">
                    <h3>Einfacher Taschenrechner</h3>
                    <div class="header-buttons">
                        <button class="close-btn" type="button">×</button>
                    </div>
                </div>
                
                <div class="calculator-content">
                    <div class="calculator-main">
                        <input type="text" id="calc-display" readonly placeholder="0">
                        
                        <div class="buttons">
                            <button>7</button>
                            <button>8</button>
                            <button>9</button>
                            <button>/</button>
                            <button class="del-btn">DEL</button>
                            
                            <button>4</button>
                            <button>5</button>
                            <button>6</button>
                            <button>*</button>
                            <button>AC</button>
                            
                            <button>1</button>
                            <button>2</button>
                            <button>3</button>
                            <button>-</button>
                            <button>(</button>
                            
                            <button>0</button>
                            <button>.</button>
                            <button class="equals">=</button>
                            <button>+</button>
                            <button>)</button>
                        </div>
                        
                        <div class="calc-info">Grundrechenarten: + − × ÷</div>
                    </div>
                </div>
            </div>
        `;
        } else {
            // WISSENSCHAFTLICHER CALCULATOR
            calcHTML = `
            <button id="calculator-btn" title="Taschenrechner (Alt + R)">🧮</button>
            <div id="calculator" style="display: none;">
                <div class="header">
                    <h3>Taschenrechner</h3>
                    <div class="header-buttons">
                        <button id="history-toggle" class="history-btn" type="button" title="Verlauf">📜</button>
                        <button class="close-btn" type="button">×</button>
                    </div>
                </div>
                <div class="calculator-content">
                    <div class="calculator-main">
                        <input type="text" id="calc-display" readonly placeholder="0">
                        <div class="buttons">
                            <button>(</button><button>)</button><button>√</button><button>^</button><button>AC</button>
                            <button>7</button><button>8</button><button>9</button><button>/</button><button class="del-btn">DEL</button>
                            <button>4</button><button>5</button><button>6</button><button>*</button><button id="fraction-toggle" class="fraction-toggle">⅓</button>
                            <button>1</button><button>2</button><button>3</button><button>-</button><button>π</button>
                            <button>0</button><button>.</button><button class="equals">=</button><button>+</button><button>e</button>
                            <button class="sci">sin</button><button class="sci">cos</button><button class="sci">tan</button><button class="sci">log</button><button>SCI</button>
                            <button class="sci">ln</button><button class="sci">%</button><button class="sci">!</button><button class="sci">x²</button><button class="sci">1/x</button>
                        </div>
                        <div class="calc-info">
                            Wissenschaftlicher Taschenrechner 
                            <span style="background: #fff3cd; padding: 0.2rem 0.4rem; border-radius: 3px; color: #856404; font-size: 0.75rem; margin-left: 0.5rem;">
                                🚧 Beta
                            </span>
                        </div>
                    </div>
                    <div id="history-panel" class="history-panel hidden">
                        <div class="history-header">
                            <h4>Verlauf</h4>
                            <button id="clear-history" class="clear-history-btn" type="button">🗑️</button>
                        </div>
                        <div id="history-list" class="history-list">
                            <div class="history-empty">Noch keine Rechnungen</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        }

        const wrapper = document.createElement('div');
        wrapper.innerHTML = calcHTML.trim();
        document.body.appendChild(wrapper);

        window.ecLog(`✅ Fallback HTML eingefügt (${calculatorMode})`);
        return true;
    }

    /**
     * Calculator initialisieren
     */
    function initializeCalculator() {
        try {
            window.ecLog('🔄 Initialisiere Calculator...');

            // Calculator-Klasse prüfen
            if (!window.ExamCalc || !window.ExamCalc.Calculator) {
                throw new Error('Calculator-Klasse nicht gefunden');
            }

            // Calculator-Instanz erstellen und initialisieren  
            const calculator = new window.ExamCalc.Calculator();
            const success = calculator.init();

            if (success) {
                // Global verfügbar machen für Debugging
                window.ExamCalc.instance = calculator;

                // NEU: Test-Ende Cleanup einrichten
                setupTestEndCleanup(calculator);

                window.ecLog('🎉 ExamCalc vollständig initialisiert!');
            } else {
                throw new Error('Calculator-Initialisierung fehlgeschlagen');
            }

        } catch (error) {
            window.ecLog('❌ Fehler bei Calculator-Initialisierung: ' + error.message);
        }
    }

    /**
     * NEU: Test-Ende Cleanup einrichten (wichtig für Prüfungen!)
     */
    function setupTestEndCleanup(calculator) {
        // Beim Verlassen der Seite
        window.addEventListener('beforeunload', () => {
            cleanupForTestEnd(calculator);
        });

        // Bei Seitenwechsel innerhalb ILIAS
        window.addEventListener('pagehide', () => {
            cleanupForTestEnd(calculator);
        });

        // Bei Klick auf "Test beenden" Buttons
        document.addEventListener('click', (e) => {
            const target = e.target;

            // Verschiedene mögliche "Test beenden" Buttons erkennen
            if (target.type === 'submit' || target.tagName === 'BUTTON' || target.tagName === 'A') {
                const text = target.textContent.toLowerCase();
                const finishKeywords = ['beenden', 'finish', 'submit', 'abgeben', 'end test', 'test beenden'];

                if (finishKeywords.some(keyword => text.includes(keyword))) {
                    window.ecLog('🔚 Test-Ende erkannt - Cleanup wird ausgeführt');
                    cleanupForTestEnd(calculator);
                }
            }
        });

        // Auch bei Navigation zu anderen ILIAS-Seiten
        const originalPushState = history.pushState;
        const originalReplaceState = history.replaceState;

        history.pushState = function () {
            cleanupForTestEnd(calculator);
            return originalPushState.apply(history, arguments);
        };

        history.replaceState = function () {
            cleanupForTestEnd(calculator);
            return originalReplaceState.apply(history, arguments);
        };

        window.ecLog('🛡️ Test-Ende Cleanup eingerichtet');
    }

    /**
     * NEU: Cleanup für Prüfungsende
     */
    function cleanupForTestEnd(calculator) {
        if (calculator && calculator.reset) {
            calculator.reset();
            window.ecLog('🧹 Calculator für Prüfungsende zurückgesetzt');
        }

        // Calculator verstecken
        if (calculator && calculator.hide) {
            calculator.hide();
        }

        // Zusätzlich: Alle Daten aus localStorage/sessionStorage löschen (falls vorhanden)
        try {
            const keys = Object.keys(localStorage);
            keys.forEach(key => {
                if (key.includes('examcalc') || key.includes('calculator')) {
                    localStorage.removeItem(key);
                }
            });
        } catch (e) {
            // localStorage nicht verfügbar - ignorieren
        }
    }

    /**
     * Hauptfunktion: Calculator laden
     */
    async function setupExamCalc(retries = 10) {
        window.ecLog('🚀 ExamCalc Loader gestartet');

        try {
            // Warten bis templatePath verfügbar ist (max. 10 Versuche)
            while ((!window.ExamCalc || !window.ExamCalc.templatePath) && retries > 0) {
                window.ecLog('⏳ Warte auf window.ExamCalc.templatePath...');
                await new Promise(resolve => setTimeout(resolve, 100));
                retries--;
            }

            if (!window.ExamCalc || !window.ExamCalc.templatePath) {
                throw new Error('Template-Pfad ist nicht verfügbar');
            }

            const templateLoaded = await loadCalculatorTemplate();

            if (!templateLoaded) {
                throw new Error('Template konnte nicht geladen werden');
            }

            await new Promise(resolve => setTimeout(resolve, 100));

            initializeCalculator();

        } catch (error) {
            window.ecLog('💥 Kritischer Fehler beim ExamCalc Setup: ' + error.message);
        }
    }

    /**
     * NEU: Public API für externe Steuerung
     */
    window.ExamCalc = window.ExamCalc || {};
    window.ExamCalc.cleanup = function () {
        if (window.ExamCalc.instance) {
            cleanupForTestEnd(window.ExamCalc.instance);
        }
    };

    window.ExamCalc.reset = function () {
        if (window.ExamCalc.instance && window.ExamCalc.instance.reset) {
            window.ExamCalc.instance.reset();
        }
    };

    // Event-Listener für Page Load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupExamCalc);
    } else {
        // DOM bereits geladen
        setupExamCalc();
    }

    // Alternative: Window load als Fallback
    window.addEventListener('load', () => {
        // Nur ausführen wenn noch nicht initialisiert
        if (!window.ExamCalc?.instance?.isInitialized) {
            window.ecLog('🔄 Window.load Fallback triggered');
            setupExamCalc();
        }
    });

})();