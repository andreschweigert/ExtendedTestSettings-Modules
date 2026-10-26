/**
 * examsymbols-loader.js - Lädt HTML Template und initialisiert Symbol-Manager
 * Part of ExtendedTestSettings Plugin
 */

(function() {
    'use strict';

    // Debug-Logging initialisieren falls noch nicht vorhanden
    if (typeof window.esLog === 'undefined') {
        window.esLog = function(msg) { 
            console.log('[ExamSymbols] ' + msg); 
        };
    }

    /**
     * Symbol-Panel HTML Template laden und einfügen
     */
    async function loadSymbolsTemplate() {
        try {
            window.esLog('🔄 Lade Symbol-Panel Template...');

            // Template-Pfad prüfen
            if (!window.ExamSymbols || !window.ExamSymbols.templatePath) {
                throw new Error('Template-Pfad nicht gefunden');
            }

            const response = await fetch(window.ExamSymbols.templatePath);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const html = await response.text();
            
            // HTML in DOM einfügen
            const wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            document.body.appendChild(wrapper);
            
            window.esLog('✅ Symbol-Panel Template geladen');
            
            return true;
            
        } catch (error) {
            window.esLog('❌ Fehler beim Laden des Templates: ' + error.message);
            
            // Fallback: Inline HTML verwenden
            return loadSymbolsFallback();
        }
    }

    /**
     * Fallback: Symbol-Panel HTML inline erstellen
     */
    function loadSymbolsFallback() {
        window.esLog('🔄 Verwende Fallback-HTML...');
        
        const symbolsHTML = `
            <button id="symbols-btn" title="Sonderzeichen (Alt + S)" aria-label="Sonderzeichen">📝</button>
            
            <div id="symbols-panel" role="application" aria-label="Sonderzeichen-Palette">
                <div class="header">
                    <h3>Symbole einfügen</h3>
                    <button class="close-btn" type="button" aria-label="Schließen">×</button>
                </div>
                
                <select id="template-select" style="display: none;">
                    <option value="math">Mathematik/Wissenschaft</option>
                    <option value="spanish">Spanisch</option>
                    <option value="italian">Italienisch</option>
                    <option value="german">Deutsch</option>
                    <option value="greek">Griechisch</option>
                </select>
                
                <div id="symbols-grid"></div>
                
                <div class="symbols-info">
                    Klicken Sie in ein Textfeld und wählen Sie ein Symbol aus
                </div>
            </div>
        `;
        
        const wrapper = document.createElement('div');
        wrapper.innerHTML = symbolsHTML.trim();
        document.body.appendChild(wrapper);
        
        window.esLog('✅ Fallback HTML eingefügt');
        return true;
    }

    /**
     * Symbol-Manager initialisieren
     */
    function initializeSymbolManager() {
        try {
            window.esLog('🔄 Initialisiere Symbol-Manager...');
            
            // Symbol-Manager-Klasse prüfen
            if (!window.ExamSymbols || !window.ExamSymbols.Manager) {
                throw new Error('Symbol-Manager-Klasse nicht gefunden');
            }

            // Symbol-Manager-Instanz erstellen und initialisieren
            const symbolManager = new window.ExamSymbols.Manager();
            const success = symbolManager.init();

            if (success) {
                // Global verfügbar machen für Debugging
                window.ExamSymbols.instance = symbolManager;
                window.esLog('🎉 ExamSymbols vollständig initialisiert!');
            } else {
                throw new Error('Symbol-Manager-Initialisierung fehlgeschlagen');
            }

        } catch (error) {
            window.esLog('❌ Fehler bei Symbol-Manager-Initialisierung: ' + error.message);
        }
    }

    /**
     * Hauptfunktion: ExamSymbols Setup
     */
    async function setupExamSymbols(retries = 10) {
    window.esLog('🚀 ExamSymbols Loader gestartet');

    try {
        // Warte, bis templatePath vorhanden ist
        while ((!window.ExamSymbols || !window.ExamSymbols.templatePath) && retries > 0) {
            window.esLog('⏳ Warte auf window.ExamSymbols.templatePath...');
            await new Promise(resolve => setTimeout(resolve, 100));
            retries--;
        }

        if (!window.ExamSymbols || !window.ExamSymbols.templatePath) {
            throw new Error('Template-Pfad ist nicht verfügbar');
        }

        // 1. Template laden
        const templateLoaded = await loadSymbolsTemplate();

        if (!templateLoaded) {
            throw new Error('Template konnte nicht geladen werden');
        }

        // 2. Kurz warten bis DOM ready
        await new Promise(resolve => setTimeout(resolve, 100));

        // 3. Symbol-Manager initialisieren
        initializeSymbolManager();

    } catch (error) {
        window.esLog('💥 Kritischer Fehler beim ExamSymbols Setup: ' + error.message);
    }
}


    // Event-Listener für Page Load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupExamSymbols);
    } else {
        // DOM bereits geladen
        setupExamSymbols();
    }

    // Alternative: Window load als Fallback
    window.addEventListener('load', () => {
        // Nur ausführen wenn noch nicht initialisiert
        if (!window.ExamSymbols?.instance?.isInitialized) {
            window.esLog('🔄 Window.load Fallback triggered');
            setupExamSymbols();
        }
    });

})();