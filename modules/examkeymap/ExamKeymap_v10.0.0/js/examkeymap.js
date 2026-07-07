/**
 * ExamKeymap — Virtuelles Tastatur-Layout (DE/EN) für Kiosk-Geräte
 * Part of ExtendedTestSettings Plugin
 *
 * Problem: Im ChromeOS-Kioskmodus ist das OS-Tastaturlayout unbekannt und
 * nicht umstellbar. Lösung: Tastendrücke werden anhand von KeyboardEvent.code
 * (physische Taste, layoutUNabhängig) abgefangen und in das vom Studierenden
 * gewählte Layout (DE QWERTZ / EN-US QWERTY) umgeschrieben.
 *
 * Ein fixierter Button unten rechts rotiert durch: OS → DE → EN → OS.
 * Zustand "OS" = Pass-through (kein Eingriff).
 *
 * Virtuelle Feststelltaste (Option pro Test): primär über einen ⇪-Button
 * über dem Layout-Button — im ChromeOS-Kioskmodus schluckt das System den
 * Einzeldruck der Lupe-/Suchtaste, sodass KEIN Key-Event ankommt. Falls das
 * Gerät den Tastendruck doch durchreicht (MetaLeft/OSLeft), toggelt auch die
 * Taste selbst (sofort beim keydown, Rücknahme bei Kombination).
 *
 * Die eingebauten Tabellen werden als window.ExamKeymap.tables exponiert —
 * der Keyboard-Editor in der Administration (examkeymap_admin.js) nutzt sie.
 * Die Player-Logik läuft nur, wenn config.player gesetzt ist (injectAssets).
 */
(function () {
    'use strict';

    // Config IMMER live lesen — addJavaScript läuft vor addOnLoadCode,
    // beim Script-Init ist window.ExamKeymap.config noch nicht gesetzt.
    function cfg() {
        return (window.ExamKeymap && window.ExamKeymap.config) || {};
    }

    function txt(key, fallback) {
        var t = cfg().txt || {};
        return t[key] || fallback;
    }

    // ─── Mapping-Tabellen ────────────────────────────────────────────────────
    // Schlüssel: KeyboardEvent.code (physische Taste, US-QWERTY-Bezeichnung).
    // b = Basis, s = Shift, g = AltGr. CapsLock invertiert Shift bei Buchstaben.
    // Dead Keys (^ ´ `) werden vereinfacht als Literal-Zeichen eingefügt,
    // kein Kombinieren mit dem Folgezeichen (siehe README).

    var DE = {
        // Buchstabenreihe 1 (physisch QWERTY-Reihe) — KeyY ist die physische
        // Taste rechts oben (US "Y"), auf QWERTZ liegt dort das "z"
        KeyQ: { b: 'q', s: 'Q', g: '@' },
        KeyW: { b: 'w', s: 'W' },
        KeyE: { b: 'e', s: 'E', g: '€' },
        KeyR: { b: 'r', s: 'R' },
        KeyT: { b: 't', s: 'T' },
        KeyY: { b: 'z', s: 'Z' },
        KeyU: { b: 'u', s: 'U' },
        KeyI: { b: 'i', s: 'I' },
        KeyO: { b: 'o', s: 'O' },
        KeyP: { b: 'p', s: 'P' },
        // Buchstabenreihe 2
        KeyA: { b: 'a', s: 'A' },
        KeyS: { b: 's', s: 'S' },
        KeyD: { b: 'd', s: 'D' },
        KeyF: { b: 'f', s: 'F' },
        KeyG: { b: 'g', s: 'G' },
        KeyH: { b: 'h', s: 'H' },
        KeyJ: { b: 'j', s: 'J' },
        KeyK: { b: 'k', s: 'K' },
        KeyL: { b: 'l', s: 'L' },
        // Buchstabenreihe 3 — KeyZ ist die physische Taste links unten (US "Z"),
        // auf QWERTZ liegt dort das "y"
        KeyZ: { b: 'y', s: 'Y' },
        KeyX: { b: 'x', s: 'X' },
        KeyC: { b: 'c', s: 'C' },
        KeyV: { b: 'v', s: 'V' },
        KeyB: { b: 'b', s: 'B' },
        KeyN: { b: 'n', s: 'N' },
        KeyM: { b: 'm', s: 'M', g: 'µ' },
        // Ziffernreihe mit DE-Shift-Belegung + AltGr
        Digit1: { b: '1', s: '!' },
        Digit2: { b: '2', s: '"', g: '²' },
        Digit3: { b: '3', s: '§', g: '³' },
        Digit4: { b: '4', s: '$' },
        Digit5: { b: '5', s: '%' },
        Digit6: { b: '6', s: '&' },
        Digit7: { b: '7', s: '/', g: '{' },
        Digit8: { b: '8', s: '(', g: '[' },
        Digit9: { b: '9', s: ')', g: ']' },
        Digit0: { b: '0', s: '=', g: '}' },
        // Satz-/Sonderzeichen (physische US-Tasten → DE-Belegung)
        Minus:        { b: 'ß', s: '?', g: '\\' },
        Equal:        { b: '´', s: '`' },          // Dead Keys als Literal
        BracketLeft:  { b: 'ü', s: 'Ü' },
        BracketRight: { b: '+', s: '*', g: '~' },
        Semicolon:    { b: 'ö', s: 'Ö' },
        Quote:        { b: 'ä', s: 'Ä' },
        Backslash:    { b: '#', s: "'" },
        Backquote:    { b: '^', s: '°' },          // Dead Key als Literal
        Comma:        { b: ',', s: ';' },
        Period:       { b: '.', s: ':' },
        Slash:        { b: '-', s: '_' },
        IntlBackslash: { b: '<', s: '>', g: '|' }  // ISO-Taste neben linkem Shift
    };

    var EN = {
        KeyQ: { b: 'q', s: 'Q' },
        KeyW: { b: 'w', s: 'W' },
        KeyE: { b: 'e', s: 'E' },
        KeyR: { b: 'r', s: 'R' },
        KeyT: { b: 't', s: 'T' },
        KeyY: { b: 'y', s: 'Y' },
        KeyU: { b: 'u', s: 'U' },
        KeyI: { b: 'i', s: 'I' },
        KeyO: { b: 'o', s: 'O' },
        KeyP: { b: 'p', s: 'P' },
        KeyA: { b: 'a', s: 'A' },
        KeyS: { b: 's', s: 'S' },
        KeyD: { b: 'd', s: 'D' },
        KeyF: { b: 'f', s: 'F' },
        KeyG: { b: 'g', s: 'G' },
        KeyH: { b: 'h', s: 'H' },
        KeyJ: { b: 'j', s: 'J' },
        KeyK: { b: 'k', s: 'K' },
        KeyL: { b: 'l', s: 'L' },
        KeyZ: { b: 'z', s: 'Z' },
        KeyX: { b: 'x', s: 'X' },
        KeyC: { b: 'c', s: 'C' },
        KeyV: { b: 'v', s: 'V' },
        KeyB: { b: 'b', s: 'B' },
        KeyN: { b: 'n', s: 'N' },
        KeyM: { b: 'm', s: 'M' },
        Digit1: { b: '1', s: '!' },
        Digit2: { b: '2', s: '@' },
        Digit3: { b: '3', s: '#' },
        Digit4: { b: '4', s: '$' },
        Digit5: { b: '5', s: '%' },
        Digit6: { b: '6', s: '^' },
        Digit7: { b: '7', s: '&' },
        Digit8: { b: '8', s: '*' },
        Digit9: { b: '9', s: '(' },
        Digit0: { b: '0', s: ')' },
        Minus:        { b: '-', s: '_' },
        Equal:        { b: '=', s: '+' },
        BracketLeft:  { b: '[', s: '{' },
        BracketRight: { b: ']', s: '}' },
        Semicolon:    { b: ';', s: ':' },
        Quote:        { b: "'", s: '"' },
        Backslash:    { b: '\\', s: '|' },
        Backquote:    { b: '`', s: '~' },
        Comma:        { b: ',', s: '<' },
        Period:       { b: '.', s: '>' },
        Slash:        { b: '/', s: '?' },
        IntlBackslash: { b: '\\', s: '|' }
    };

    var LAYOUTS = { de: DE, en: EN };
    var CYCLE = ['os', 'de', 'en'];

    // Eingebaute Tabellen für den Admin-Keyboard-Editor exponieren
    window.ExamKeymap = window.ExamKeymap || {};
    window.ExamKeymap.tables = LAYOUTS;

    // ─── Layout-Zustand (sessionStorage, überlebt Fragennavigation) ─────────

    function storageKey() {
        // ref_id live aus der Config — NIE pro Pageload neu würfeln
        return 'examkeymap_' + (cfg().ref_id || 'active');
    }

    function capsStorageKey() {
        return 'examkeymap_caps_' + (cfg().ref_id || 'active');
    }

    function currentLayout() {
        var v = null;
        try {
            v = sessionStorage.getItem(storageKey());
        } catch (e) { /* sessionStorage gesperrt → Default nutzen */ }

        if (v === 'os' || v === 'de' || v === 'en') {
            return v;
        }

        var d = cfg().default_layout;
        return (d === 'de' || d === 'en') ? d : 'os';
    }

    function setLayout(layout) {
        try {
            sessionStorage.setItem(storageKey(), layout);
        } catch (e) { /* ignorieren, Zustand gilt dann nur bis zum Reload */ }
        renderButton();
        console.log('[ExamKeymap] Layout: ' + layout);
    }

    function cycleLayout() {
        var idx = CYCLE.indexOf(currentLayout());
        setLayout(CYCLE[(idx + 1) % CYCLE.length]);
    }

    // ─── Virtuelle Feststelltaste ────────────────────────────────────────────
    // Primäre Bedienung: ⇪-Button (im ChromeOS-Kioskmodus erreicht der
    // Einzeldruck der Lupe-Taste die Seite NICHT — das System schluckt ihn).
    // Zusätzlich, falls Events ankommen: Lupe-/Suchtaste (MetaLeft, je nach
    // Chrome-Version auch OSLeft) toggelt sofort beim keydown — Launcher/
    // Startmenü öffnen erst beim keyup, bis dahin ist der Toggle durch.
    // Folgt eine Kombination (Lupe+Taste = OS-Shortcut), wird zurückgetoggelt.

    var virtualCaps = false;
    var searchKeyDown = false;
    var searchToggled = false;

    function isSearchKey(e) {
        return e.code === 'MetaLeft' || e.code === 'MetaRight'
            || e.code === 'OSLeft' || e.code === 'OSRight'
            || (!e.code && e.key === 'Meta');
    }

    function loadVirtualCaps() {
        try {
            virtualCaps = sessionStorage.getItem(capsStorageKey()) === '1';
        } catch (e) { /* ignorieren */ }
    }

    function toggleVirtualCaps() {
        virtualCaps = !virtualCaps;
        try {
            sessionStorage.setItem(capsStorageKey(), virtualCaps ? '1' : '0');
        } catch (e) { /* ignorieren */ }
        renderButton();
        console.log('[ExamKeymap] Virtuelle Feststelltaste: ' + (virtualCaps ? 'an' : 'aus'));
    }

    function searchCapsActive() {
        var layout = currentLayout();
        return !!cfg().search_caps && (layout === 'de' || layout === 'en');
    }

    // ─── Modifier: AltGr-Erkennung ───────────────────────────────────────────
    // Auf einem EN-OS-Layout meldet die rechte Alt-Taste KEIN "AltGraph" —
    // deshalb wird AltRight zusätzlich manuell über keydown/keyup verfolgt.

    var altRightDown = false;

    function isAltGr(e) {
        return altRightDown
            || (e.getModifierState && e.getModifierState('AltGraph'))
            || (e.ctrlKey && e.altKey);
    }

    // ─── Zeichen auflösen ────────────────────────────────────────────────────

    function isLetter(ch) {
        return ch.length === 1 && ch.toLowerCase() !== ch.toUpperCase();
    }

    function lookupEntry(layout, code) {
        // Globale Übersetzungstabelle (Administration) hat Vorrang vor der
        // eingebauten Tabelle; leere Felder im Override = Taste nicht belegt
        var custom = cfg().custom || {};
        if (custom[layout] && custom[layout][code]) {
            return custom[layout][code];
        }
        return LAYOUTS[layout][code];
    }

    function resolveChar(entry, e) {
        var ch;

        if (isAltGr(e)) {
            ch = entry.g;
        } else {
            var shift = e.shiftKey;

            // CapsLock invertiert Shift nur bei Buchstaben (inkl. Umlauten).
            // XOR aus echter CapsLock und virtueller Feststelltaste.
            var realCaps = !!(e.getModifierState && e.getModifierState('CapsLock'));
            var caps = realCaps !== (searchCapsActive() && virtualCaps);
            if (entry.b && isLetter(entry.b) && caps) {
                shift = !shift;
            }

            ch = shift ? entry.s : entry.b;
        }

        // null/undefined/leer = nicht belegt → Pass-through
        return (ch === null || ch === undefined || ch === '') ? null : ch;
    }

    // ─── Ziel-Erkennung & Einfügen ───────────────────────────────────────────

    function isEditableTarget(t) {
        if (!t || !t.tagName) {
            return false;
        }
        var tag = t.tagName.toLowerCase();
        if (tag === 'textarea') {
            return !t.readOnly && !t.disabled;
        }
        if (tag === 'input') {
            return (t.type || 'text').toLowerCase() === 'text' && !t.readOnly && !t.disabled;
        }
        // TinyMCE-Body (contenteditable im iframe) und andere Editierbereiche
        return t.isContentEditable === true;
    }

    function fireInputEvent(el, ch) {
        // input-Event dispatchen, damit ILIAS-Autosave/Zeichenzähler/TinyMCE
        // die programmatische Änderung mitbekommen
        var ev;
        try {
            ev = new InputEvent('input', {
                bubbles: true,
                cancelable: false,
                inputType: 'insertText',
                data: ch
            });
        } catch (e) {
            ev = new Event('input', { bubbles: true });
        }
        el.dispatchEvent(ev);
    }

    function insertInField(el, ch) {
        // Selection ersetzen, Cursor hinter das eingefügte Zeichen
        var start = el.selectionStart;
        var end = el.selectionEnd;
        if (start === null || end === null) {
            return;
        }
        el.setRangeText(ch, start, end, 'end');
        fireInputEvent(el, ch);
    }

    function insertInEditable(el, ch, doc) {
        var win = doc.defaultView || window;
        var sel = win.getSelection();
        if (!sel || sel.rangeCount === 0) {
            return;
        }
        var range = sel.getRangeAt(0);
        range.deleteContents();
        var node = doc.createTextNode(ch);
        range.insertNode(node);
        range.setStartAfter(node);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
        fireInputEvent(el, ch);
    }

    // ─── Keydown-Handler ─────────────────────────────────────────────────────

    function onKeydown(e) {
        if (!cfg().player) {
            return; // nicht im Testplayer geladen (z.B. Admin-Editor)
        }

        // AltRight-Status immer verfolgen, auch im OS-Modus
        if (e.code === 'AltRight') {
            altRightDown = true;
            return;
        }

        // Lupe-/Suchtaste: sofort beim keydown toggeln (falls das Event
        // überhaupt ankommt — im Kioskmodus meist nicht, siehe oben)
        if (isSearchKey(e)) {
            if (!e.repeat && !searchKeyDown) {
                searchKeyDown = true;
                searchToggled = false;
                if (searchCapsActive()) {
                    toggleVirtualCaps();
                    searchToggled = true;
                }
            }
            return;
        }

        // Lupe+Taste = Kombination (OS-Shortcut) → Toggle zurücknehmen
        if ((searchKeyDown || e.metaKey) && searchToggled) {
            toggleVirtualCaps();
            searchToggled = false;
        }

        var layout = currentLayout();
        if (layout !== 'de' && layout !== 'en') {
            return; // OS-Modus: kein Eingriff
        }

        if (e.isComposing || e.defaultPrevented) {
            return;
        }

        // Ctrl/Meta-Kombinationen (Copy/Paste etc.) nie anfassen —
        // außer echtem AltGr (Ctrl+Alt bzw. AltRight)
        var altGr = isAltGr(e);
        if (e.metaKey || (e.ctrlKey && !altGr) || (e.altKey && !altGr)) {
            return;
        }

        // Nur Tasten mit Mapping-Eintrag — F-Tasten, Pfeile, Home/End,
        // Backspace, Delete, Tab, Enter, Escape usw. fallen automatisch durch
        var entry = lookupEntry(layout, e.code);
        if (!entry) {
            return;
        }

        var target = e.target;
        if (!isEditableTarget(target)) {
            return;
        }

        var ch = resolveChar(entry, e);
        if (ch === null) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        var doc = target.ownerDocument || document;
        if (target.isContentEditable) {
            insertInEditable(target, ch, doc);
        } else {
            insertInField(target, ch);
        }
    }

    function onKeyup(e) {
        if (e.code === 'AltRight') {
            altRightDown = false;
        }
        if (isSearchKey(e)) {
            searchKeyDown = false;
            searchToggled = false;
        }
    }

    function onWindowBlur() {
        altRightDown = false;
        searchKeyDown = false;
        searchToggled = false;
    }

    // ─── Listener-Registrierung (Hauptdokument + TinyMCE-iframes) ───────────

    var HOOK_FLAG = '__examkeymapHooked';

    function hookDocument(doc) {
        if (!doc || doc[HOOK_FLAG]) {
            return;
        }
        doc[HOOK_FLAG] = true;
        // Capture-Phase: vor TinyMCE-/ILIAS-eigenen Handlern eingreifen
        doc.addEventListener('keydown', onKeydown, true);
        doc.addEventListener('keyup', onKeyup, true);
        var win = doc.defaultView;
        if (win) {
            win.addEventListener('blur', onWindowBlur);
        }
    }

    function hookIframes() {
        // TinyMCE initialisiert nach Page-Load und kann iframes neu erzeugen —
        // das Flag auf dem contentDocument verschwindet dabei, also wird
        // bei jedem Tick nachregistriert
        var frames = document.querySelectorAll('iframe');
        for (var i = 0; i < frames.length; i++) {
            try {
                var doc = frames[i].contentDocument;
                if (doc) {
                    hookDocument(doc);
                }
            } catch (e) {
                // Cross-Origin-iframe → ignorieren
            }
        }
    }

    // ─── Buttons (Layout-Umschalter + Feststelltaste) ────────────────────────

    var button = null;
    var capsButton = null;

    function ensureButton() {
        if (button || !document.body || !cfg().player) {
            return;
        }
        loadVirtualCaps();

        button = document.createElement('button');
        button.id = 'examkeymap-btn';
        button.type = 'button';
        button.addEventListener('click', cycleLayout);
        document.body.appendChild(button);

        // ⇪-Button: primäre Bedienung der virtuellen Feststelltaste
        // (Lupe-Einzeldruck kommt im Kioskmodus nicht im Browser an)
        capsButton = document.createElement('button');
        capsButton.id = 'examkeymap-caps-btn';
        capsButton.type = 'button';
        capsButton.textContent = '⇪';
        capsButton.addEventListener('click', toggleVirtualCaps);
        document.body.appendChild(capsButton);

        renderButton();
    }

    function renderButton() {
        if (!button) {
            return;
        }
        var layout = currentLayout();
        var labels = {
            os: txt('os', 'OS'),
            de: txt('de', 'DE'),
            en: txt('en', 'EN')
        };
        button.textContent = '⌨ ' + labels[layout];
        button.title = txt('tooltip', 'Tastatur-Layout umschalten (OS → DE → EN)');
        button.setAttribute('data-layout', layout);

        if (capsButton) {
            capsButton.style.display = searchCapsActive() ? '' : 'none';
            capsButton.title = txt('caps_tooltip', 'Feststelltaste umschalten (Großschreibung)');
            capsButton.setAttribute('data-active', virtualCaps ? '1' : '0');
        }
    }

    // ─── Init ────────────────────────────────────────────────────────────────

    hookDocument(document);

    // Timer immer aufsetzen, auch wenn die Config beim ersten Tick noch leer
    // ist (addOnLoadCode läuft nach addJavaScript) — Config wird live gelesen
    function tick() {
        ensureButton();
        renderButton();
        if (cfg().player) {
            hookIframes();
        }
        setTimeout(tick, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tick);
    } else {
        tick();
    }

    console.log('[ExamKeymap] Geladen');
})();
