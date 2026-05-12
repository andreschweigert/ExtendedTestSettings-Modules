/**
 * ExamGuard Module for ExtendedTestSettings
 * Blocks copy&paste, right-click and dev-tools in tests
 */
(function() {
    'use strict';
    
    // Rechtsklick blockieren
    document.addEventListener('contextmenu', function(e) { 
        e.preventDefault(); 
    }, false);
    
    // Keyboard shortcuts blockieren
    document.addEventListener('keydown', function(e) {
        const isMac = navigator.platform.toUpperCase().indexOf("MAC") !== -1;
        const ctrlOrCmd = isMac ? e.metaKey : e.ctrlKey;
        const key = e.key.toLowerCase();
        
        // Copy, Paste, Cut, Select All, Save blockieren
        if (ctrlOrCmd && ["c", "v", "x", "a", "s"].includes(key)) {
            e.preventDefault();
        }
        
        // F12 (DevTools) blockieren
        if (e.key === "F12") {
            e.preventDefault();
        }
        
        // DevTools shortcuts blockieren
        if (ctrlOrCmd && e.shiftKey && ["i", "j", "c"].includes(key)) {
            e.preventDefault();
        }
    }, false);
    
    // Text-Selection verhindern
    const style = document.createElement("style");
    style.innerHTML = `
        * { 
            user-select: none !important;
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
        }
        /* Ausnahme für Input-Felder */
        input, textarea, .ilc_qinput_TextInput, .ilc_qinput_TextAreaInput {
            user-select: text !important;
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
        }
    `;
    document.head.appendChild(style);
    
    console.log('[ExamGuard] Protection activated');
})();