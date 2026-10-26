/**
 * symbols-manager.js - Symbol-Management für ExtendedTestSettings
 * BEREINIGT: Fallback-Templates entfernt, nutzt nur noch PHP-Config
 * KOMPLETT: Alle Funktionen für Multi-Editor-Support
 */

window.ExamSymbols = window.ExamSymbols || {};

window.ExamSymbols.Manager = class {
    constructor() {
        this.btn = null;
        this.panel = null;
        this.symbolsGrid = null;
        this.templateSelect = null;

        this.currentTemplate = 'math';
        this.currentSymbols = [];
        this.lastFocusedElement = null;
        this.isInitialized = false;

        // ENTFERNT: fallbackTemplates - Nutze nur noch PHP-Config
    }

    /**
     * Initialisiert das Symbol-Panel nachdem HTML geladen wurde
     */
    init() {
        this.btn = document.getElementById('symbols-btn');
        this.panel = document.getElementById('symbols-panel');
        this.symbolsGrid = document.getElementById('symbols-grid');
        this.templateSelect = document.getElementById('template-select');

        if (!this.btn || !this.panel || !this.symbolsGrid) {
            console.error("[ExamSymbols] Symbol-Panel-Elemente nicht gefunden");
            return false;
        }

        this.setupEventListeners();
        this.setupFocusTracking();
        this.setupDragAndDrop();
        this.loadConfiguration();
        this.isInitialized = true;

        if (window.esLog) {
            window.esLog('✅ Symbol-Manager initialisiert');
        }

        return true;
    }

    /**
     * Panel draggable machen (genau wie Calculator)
     */
    setupDragAndDrop() {
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let startLeft = 0;
        let startTop = 0;

        this.panel.addEventListener('mousedown', (e) => {
            // Nur bei Klick auf Panel-Header (nicht Buttons, Select, etc.)
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'SELECT' || e.target.classList.contains('symbol-btn')) {
                return;
            }

            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;

            const rect = this.panel.getBoundingClientRect();
            startLeft = rect.left;
            startTop = rect.top;

            this.panel.style.cursor = 'grabbing';
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;

            const deltaX = e.clientX - startX;
            const deltaY = e.clientY - startY;

            this.panel.style.left = (startLeft + deltaX) + 'px';
            this.panel.style.top = (startTop + deltaY) + 'px';
            this.panel.style.right = 'auto';
            this.panel.style.bottom = 'auto';
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                this.panel.style.cursor = '';
            }
        });
    }

    /**
     * Lädt Konfiguration aus PHP - VEREINFACHT
     */
    loadConfiguration() {
        if (window.ExamSymbols && window.ExamSymbols.config) {
            this.currentTemplate = window.ExamSymbols.config.template || 'math';

            // Symbole IMMER aus PHP-Config verwenden
            if (window.ExamSymbols.config.symbols && window.ExamSymbols.config.symbols.length > 0) {
                this.currentSymbols = window.ExamSymbols.config.symbols;
                if (window.esLog) {
                    window.esLog('Symbole aus PHP-Config geladen: ' + this.currentSymbols.length);
                }
            } else {
                // Fehler: Keine Symbole von PHP erhalten
                console.error("[ExamSymbols] Keine Symbole von PHP-Config erhalten");
                this.currentSymbols = [];
            }
        } else {
            // Fehler: Keine PHP-Config vorhanden
            console.error("[ExamSymbols] Keine PHP-Konfiguration verfügbar");
            this.currentTemplate = 'math';
            this.currentSymbols = [];
        }

        this.renderSymbols();
        this.updateTemplateSelector();
    }

    /**
     * Event-Listener setup
     */
    setupEventListeners() {
        // Toggle Button
        this.btn.addEventListener('click', (e) => {
            e.preventDefault();
            this.toggle();
        });

        // Alt + S Shortcut
        document.addEventListener('keydown', (e) => {
            if (e.altKey && e.key.toLowerCase() === 's') {
                e.preventDefault();
                this.toggle();
            }
        });

        // Template-Wechsler ENTFERNT - Nutzer sollen Template nicht ändern können
        // Template wird nur vom Admin in Einstellungen festgelegt

        // Close Button
        const closeBtn = this.panel.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.hide());
        }
    }

    /**
     * Focus-Tracking setup für verschiedene Editor-Typen
     */
    setupFocusTracking() {
        // Standard Textfeld Focus-Events
        document.addEventListener('focus', (e) => {
            if (this.isTextElement(e.target)) {
                this.lastFocusedElement = e.target;
                if (window.esLog) {
                    window.esLog('Textfeld fokussiert: ' + e.target.tagName);
                }
            }
        }, true);

        // TinyMCE Focus-Events (mit Verzögerung wegen async loading)
        setTimeout(() => {
            if (typeof tinymce !== 'undefined') {
                tinymce.on('AddEditor', (e) => {
                    const editor = e.editor;
                    editor.on('focus', () => {
                        if (window.esLog) {
                            window.esLog('TinyMCE Editor fokussiert: ' + editor.id);
                        }
                        this.lastFocusedElement = {
                            _isTinyMCE: true,
                            editor: editor
                        };
                    });
                });
            }
        }, 1000);
    }

    /**
     * Panel anzeigen/verstecken
     */
    toggle() {
        const isVisible = this.panel.style.display === 'block';
        if (isVisible) {
            this.hide();
        } else {
            this.show();
        }
    }

    /**
     * Panel anzeigen
     */
    show() {
        this.panel.style.display = 'block';
        this.loadConfiguration(); // Symbole neu laden

        if (window.esLog) {
            window.esLog(`📂 Symbol-Panel angezeigt (Template: ${this.currentTemplate})`);
        }
    }

    /**
     * Panel verstecken
     */
    hide() {
        this.panel.style.display = 'none';

        if (window.esLog) {
            window.esLog('📁 Symbol-Panel versteckt');
        }
    }

    /**
     * Template wechseln - VEREINFACHT: Nur aus PHP-Config
     */
    switchTemplate(templateName) {
        this.currentTemplate = templateName;

        // Symbole aus PHP-Config laden
        if (window.ExamSymbols?.config?.availableTemplates?.[templateName]?.symbols) {
            this.currentSymbols = window.ExamSymbols.config.availableTemplates[templateName].symbols;
            if (window.esLog) {
                window.esLog(`🔄 Template gewechselt zu: ${templateName} (${this.currentSymbols.length} Symbole)`);
            }
        } else {
            // Fehler: Template nicht gefunden
            console.error(`[ExamSymbols] Template '${templateName}' nicht in PHP-Config gefunden`);
            this.currentSymbols = [];
        }

        this.renderSymbols();
    }

    /**
     * Template-Selector aktualisieren - DEAKTIVIERT
     * Template wird nur vom Admin festgelegt, Nutzer können nicht wechseln
     */
    updateTemplateSelector() {
        if (!this.templateSelect) return;

        // Template-Selector immer verstecken
        this.templateSelect.style.display = 'none';
    }

    /**
     * Symbole in Grid rendern
     */
    renderSymbols() {
        if (!this.symbolsGrid) return;

        this.symbolsGrid.innerHTML = '';

        if (this.currentSymbols.length === 0) {
            this.symbolsGrid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: #666; padding: 20px;">Keine Symbole verfügbar</div>';
            return;
        }

        // Grid-Spalten je nach Symbol-Anzahl anpassen
        const columns = this.currentSymbols.length <= 14 ? 
            Math.min(5, this.currentSymbols.length) : 5;
        this.symbolsGrid.style.gridTemplateColumns = `repeat(${columns}, 1fr)`;

        this.currentSymbols.forEach(symbol => {
            const symbolBtn = document.createElement('button');
            symbolBtn.className = 'symbol-btn';
            symbolBtn.textContent = symbol;
            symbolBtn.title = `Symbol "${symbol}" einfügen`;

            // preventDefault() um Focus-Verlust zu vermeiden
            symbolBtn.addEventListener('mousedown', (e) => {
                e.preventDefault();
            });

            symbolBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.insertSymbol(symbol);
            });

            this.symbolsGrid.appendChild(symbolBtn);
        });
    }

    /**
     * Prüft ob Element ein Textfeld ist
     */
    isTextElement(element) {
        if (!element) return false;

        const tagName = element.tagName.toLowerCase();
        const contentEditable = element.contentEditable === 'true';

        return (tagName === 'textarea') ||
            (tagName === 'input' && ['text', 'email', 'password', 'search', 'url'].includes(element.type)) ||
            contentEditable;
    }

    /**
     * Symbol einfügen - Erweiterte Multi-Editor-Unterstützung
     */
    insertSymbol(symbol) {
        if (window.esLog) {
            window.esLog('Versuche Symbol einzufügen: ' + symbol);
        }

        // 1. Gespeichertes Element (für normale Textfelder)
        if (this.lastFocusedElement && this.isTextElement(this.lastFocusedElement)) {
            if (this.insertIntoNormalField(this.lastFocusedElement, symbol)) {
                return;
            }
        }

        // 2. Aktuell aktives Element
        const activeElement = document.activeElement;
        if (activeElement && this.isTextElement(activeElement)) {
            if (this.insertIntoNormalField(activeElement, symbol)) {
                return;
            }
        }

        // 3. TinyMCE Editor
        if (this.insertIntoTinyMCE(symbol)) {
            return;
        }

        // 4. CKEditor
        if (this.insertIntoCKEditor(symbol)) {
            return;
        }

        // 5. Iframe-basierte Editoren
        if (this.insertIntoIframe(symbol)) {
            return;
        }

        // Fallback: Stille Fehlerbehandlung (kein visuelles Feedback)
        if (window.esLog) {
            window.esLog('⚠ Kein fokussiertes Textfeld gefunden');
        }
    }

    /**
     * Standard-Textfeld Einfügung
     */
    insertIntoNormalField(targetElement, symbol) {
        try {
            if (targetElement.tagName === 'TEXTAREA' || targetElement.tagName === 'INPUT') {
                const start = targetElement.selectionStart || 0;
                const end = targetElement.selectionEnd || 0;
                const value = targetElement.value || '';

                targetElement.value = value.substring(0, start) + symbol + value.substring(end);

                const newPosition = start + symbol.length;
                targetElement.selectionStart = newPosition;
                targetElement.selectionEnd = newPosition;

                setTimeout(() => targetElement.focus(), 0);
                this.triggerInputEvents(targetElement);

                if (window.esLog) {
                    window.esLog('Symbol in normales Feld eingefügt');
                }
                return true;

            } else if (targetElement.contentEditable === 'true') {
                targetElement.focus();

                if (document.execCommand) {
                    document.execCommand('insertText', false, symbol);
                } else {
                    this.insertTextAtCursor(targetElement, symbol);
                }

                this.triggerInputEvents(targetElement);
                if (window.esLog) {
                    window.esLog('Symbol in ContentEditable eingefügt');
                }
                return true;
            }
        } catch (error) {
            console.error('[ExamSymbols] Fehler bei normalem Feld:', error);
        }

        return false;
    }

    /**
     * TinyMCE Editor Integration
     */
    insertIntoTinyMCE(symbol) {
        try {
            // TinyMCE 4.x und 5.x Support
            if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                const editor = tinymce.activeEditor;
                if (editor && editor.selection) {
                    editor.selection.setContent(symbol);
                    if (window.esLog) {
                        window.esLog('Symbol in TinyMCE eingefügt');
                    }
                    return true;
                }
            }

            // Alle Editoren durchgehen
            if (typeof tinymce !== 'undefined' && tinymce.editors) {
                for (let i = 0; i < tinymce.editors.length; i++) {
                    const editor = tinymce.editors[i];
                    if (editor && editor.selection && editor.hasFocus && editor.hasFocus()) {
                        editor.selection.setContent(symbol);
                        if (window.esLog) {
                            window.esLog('Symbol in TinyMCE Editor eingefügt');
                        }
                        return true;
                    }
                }
            }
        } catch (error) {
            console.error('[ExamSymbols] TinyMCE Fehler:', error);
        }

        return false;
    }

    /**
     * CKEditor Integration
     */
    insertIntoCKEditor(symbol) {
        try {
            // CKEditor 4.x Support
            if (typeof CKEDITOR !== 'undefined') {
                for (let name in CKEDITOR.instances) {
                    const editor = CKEDITOR.instances[name];
                    if (editor && editor.focusManager && editor.focusManager.hasFocus) {
                        editor.insertText(symbol);
                        if (window.esLog) {
                            window.esLog('Symbol in CKEditor eingefügt');
                        }
                        return true;
                    }
                }
            }

            // CKEditor 5.x Support
            if (typeof ClassicEditor !== 'undefined' && window.ckeditorInstances) {
                for (let editor of window.ckeditorInstances) {
                    if (editor && editor.model) {
                        editor.model.change(writer => {
                            writer.insertText(symbol, editor.model.document.selection.getFirstPosition());
                        });
                        if (window.esLog) {
                            window.esLog('Symbol in CKEditor 5 eingefügt');
                        }
                        return true;
                    }
                }
            }
        } catch (error) {
            console.error('[ExamSymbols] CKEditor Fehler:', error);
        }

        return false;
    }

    /**
     * Iframe-basierte Editoren (Fallback)
     */
    insertIntoIframe(symbol) {
        try {
            const iframes = document.querySelectorAll('iframe');

            for (let iframe of iframes) {
                try {
                    const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                    const activeEl = iframeDoc.activeElement;

                    if (activeEl && (activeEl.tagName === 'BODY' || activeEl.contentEditable === 'true')) {
                        iframe.contentWindow.focus();
                        activeEl.focus();

                        if (iframeDoc.execCommand) {
                            iframeDoc.execCommand('insertText', false, symbol);
                        } else {
                            this.insertTextAtCursor(activeEl, symbol);
                        }

                        if (window.esLog) {
                            window.esLog('Symbol in Iframe eingefügt');
                        }
                        return true;
                    }
                } catch (crossOriginError) {
                    // Cross-origin iframe - ignorieren
                    continue;
                }
            }
        } catch (error) {
            console.error('[ExamSymbols] Iframe Fehler:', error);
        }

        return false;
    }

    /**
     * Text an Cursor-Position einfügen
     */
    insertTextAtCursor(element, text) {
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            range.deleteContents();
            range.insertNode(document.createTextNode(text));
            range.collapse(false);
            selection.removeAllRanges();
            selection.addRange(range);
        }
    }

    /**
     * Events für ILIAS-Validierung triggern
     */
    triggerInputEvents(element) {
        const events = ['input', 'change', 'keyup', 'blur'];
        events.forEach(eventType => {
            const event = new Event(eventType, { bubbles: true });
            element.dispatchEvent(event);
        });
    }

    /**
     * ENTFERNT: Visuelles Feedback (war vorher besser ohne)
     * showInsertFeedback() und showErrorFeedback() nicht mehr benötigt
     */

    /**
     * Panel zurücksetzen
     */
    reset() {
        this.hide();
        this.currentTemplate = 'math';
        this.currentSymbols = [];
        this.lastFocusedElement = null;
        
        if (window.esLog) {
            window.esLog('Symbol-Manager zurückgesetzt');
        }
    }

    /**
     * Debug-Informationen
     */
    getDebugInfo() {
        return {
            isInitialized: this.isInitialized,
            currentTemplate: this.currentTemplate,
            symbolCount: this.currentSymbols.length,
            panelVisible: this.panel?.style.display === 'block',
            lastFocusedElement: this.lastFocusedElement?.tagName || 'none',
            availableTemplates: Object.keys(window.ExamSymbols?.config?.availableTemplates || {}),
            editorDetection: {
                tinymce: typeof tinymce !== 'undefined',
                ckeditor: typeof CKEDITOR !== 'undefined',
                ckeditor5: typeof ClassicEditor !== 'undefined'
            }
        };
    }
};