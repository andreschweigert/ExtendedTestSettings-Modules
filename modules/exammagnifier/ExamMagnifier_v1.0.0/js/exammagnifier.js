/**
 * exammagnifier.js - Bildschirmlupe für ILIAS Testplayer
 * Part of ExtendedTestSettings Plugin
 *
 * Vollbild-Zoom: transform auf <body>, transform-origin folgt der Maus
 * (wie der Vollbildmodus der Windows-Bildschirmlupe). Die eigene UI
 * (Toolbar, Zeiger-Overlay) hängt direkt unter <html> und bleibt dadurch
 * unskaliert. Kein DOM-Umbau, keine iframes werden bewegt (TinyMCE-sicher).
 *
 * Config via window.ExamMagnifier.config (addOnLoadCode) — NIE beim
 * IIFE-Init cachen, immer live über cfg() lesen.
 */

(function () {
    'use strict';

    var ZOOM_STEPS = [100, 150, 200, 300, 400, 600, 800, 1200, 1600];

    // Laufzeit-Zustand (wird über sessionStorage über Fragenwechsel gerettet)
    var state = { z: 0, inv: false, cur: false, open: true };

    var toolbar = null, controls = null, zoomVal = null, toggleBtn = null;
    var cursorEl = null, ringEl = null;
    var btns = {};

    // Zoom-Schwenk: Ziel-/Ist-Origin in Seitenkoordinaten, rAF-Lerp
    var target = { x: 0, y: 0 };
    var current = { x: 0, y: 0 };
    var rafRunning = false;
    var lastClient = null;

    function cfg() {
        return (window.ExamMagnifier && window.ExamMagnifier.config) || {};
    }

    function txt(key) {
        var labels = cfg().labels || {};
        return labels[key] || key;
    }

    function storageKey() {
        return 'exammagnifier_state_' + (cfg().ref_id || 'active');
    }

    function steps() {
        var max = parseInt(cfg().max_zoom, 10) || 1600;
        return ZOOM_STEPS.filter(function (s) { return s <= max; });
    }

    function currentZoom() {
        var list = steps();
        return list[Math.min(state.z, list.length - 1)] || 100;
    }

    // -------------------------------------------------------------------------
    // Zustand speichern / laden
    // -------------------------------------------------------------------------

    function saveState() {
        try {
            sessionStorage.setItem(storageKey(), JSON.stringify(state));
        } catch (e) { /* Speicher voll/gesperrt: Zustand geht beim Fragenwechsel verloren */ }
    }

    function loadState() {
        try {
            var raw = sessionStorage.getItem(storageKey());
            if (!raw) return;
            var parsed = JSON.parse(raw);
            state.z    = Math.max(0, Math.min(parseInt(parsed.z, 10) || 0, steps().length - 1));
            state.inv  = !!parsed.inv;
            state.cur  = !!parsed.cur;
            state.open = parsed.open !== false;
        } catch (e) {
            console.warn('[ExamMagnifier] Zustand konnte nicht geladen werden', e);
        }
    }

    // -------------------------------------------------------------------------
    // Vollbild-Zoom
    // -------------------------------------------------------------------------

    function setTargetFromClient(cx, cy) {
        // Origin = Seitenkoordinate der Zeigerposition: der Punkt unter dem
        // Zeiger bleibt fix, Bewegung zum Rand schwenkt den Ausschnitt dorthin
        target.x = window.pageXOffset + cx;
        target.y = window.pageYOffset + cy;
        if (!rafRunning) {
            rafRunning = true;
            requestAnimationFrame(tick);
        }
    }

    function tick() {
        current.x += (target.x - current.x) * 0.18;
        current.y += (target.y - current.y) * 0.18;
        document.body.style.transformOrigin = current.x + 'px ' + current.y + 'px';
        if (Math.abs(target.x - current.x) > 0.5 || Math.abs(target.y - current.y) > 0.5) {
            requestAnimationFrame(tick);
        } else {
            rafRunning = false;
        }
    }

    function applyZoom() {
        var z = currentZoom();
        if (zoomVal) {
            zoomVal.textContent = z + ' %';
        }

        if (z <= 100) {
            document.body.style.transform = '';
            document.body.style.transformOrigin = '';
            document.documentElement.classList.remove('exammagnifier-zoomed');
            return;
        }

        document.documentElement.classList.add('exammagnifier-zoomed');
        document.body.style.transform = 'scale(' + (z / 100) + ')';

        // Ohne Sprung dort ansetzen, wo der Zeiger zuletzt war (sonst Mitte)
        var cx = lastClient ? lastClient.x : window.innerWidth / 2;
        var cy = lastClient ? lastClient.y : window.innerHeight / 2;
        target.x = window.pageXOffset + cx;
        target.y = window.pageYOffset + cy;
        current.x = target.x;
        current.y = target.y;
        document.body.style.transformOrigin = current.x + 'px ' + current.y + 'px';
    }

    function zoomBy(delta) {
        if (!cfg().allow_zoom) return;
        state.z = Math.max(0, Math.min(state.z + delta, steps().length - 1));
        applyZoom();
        saveState();
    }

    function zoomReset() {
        if (!cfg().allow_zoom) return;
        state.z = 0;
        applyZoom();
        saveState();
    }

    // -------------------------------------------------------------------------
    // Farbinvertierung
    // -------------------------------------------------------------------------

    function applyInvert() {
        document.documentElement.classList.toggle('exammagnifier-inverted', state.inv);
        if (btns.invert) {
            btns.invert.setAttribute('aria-pressed', String(state.inv));
            btns.invert.classList.toggle('exammagnifier-on', state.inv);
        }
    }

    function toggleInvert() {
        if (!cfg().allow_invert) return;
        state.inv = !state.inv;
        applyInvert();
        saveState();
    }

    // -------------------------------------------------------------------------
    // Großer Mauszeiger + Klick-Hervorhebung
    // -------------------------------------------------------------------------

    function ensureCursorOverlay() {
        if (cursorEl) return;

        cursorEl = document.createElement('div');
        cursorEl.id = 'exammagnifier-cursor';
        cursorEl.className = 'exammagnifier-ui';
        cursorEl.setAttribute('aria-hidden', 'true');
        cursorEl.innerHTML =
            '<svg viewBox="0 0 24 24" width="56" height="56" focusable="false">' +
            '<path d="M5 2 L5 19 L9.5 15.5 L12.5 21.5 L15.5 20 L12.5 14 L18 13.5 Z" ' +
            'fill="#ffd21e" stroke="#1a1a1a" stroke-width="1.4" stroke-linejoin="round"/></svg>';
        document.documentElement.appendChild(cursorEl);

        ringEl = document.createElement('div');
        ringEl.id = 'exammagnifier-ring';
        ringEl.className = 'exammagnifier-ui';
        ringEl.setAttribute('aria-hidden', 'true');
        document.documentElement.appendChild(ringEl);
    }

    function applyCursor() {
        if (state.cur) {
            ensureCursorOverlay();
        }
        document.documentElement.classList.toggle('exammagnifier-bigcursor', state.cur);
        if (cursorEl) {
            cursorEl.style.display = state.cur ? 'block' : 'none';
        }
        if (btns.cursor) {
            btns.cursor.setAttribute('aria-pressed', String(state.cur));
            btns.cursor.classList.toggle('exammagnifier-on', state.cur);
        }
    }

    function toggleCursor() {
        if (!cfg().allow_cursor) return;
        state.cur = !state.cur;
        applyCursor();
        saveState();
    }

    // -------------------------------------------------------------------------
    // Toolbar (unten links — oben rechts: examdyslexia, unten rechts: examcalc)
    // -------------------------------------------------------------------------

    function makeButton(id, label, symbol) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'exammagnifier-btn-' + id;
        btn.className = 'exammagnifier-btn';
        btn.title = label;
        btn.setAttribute('aria-label', label);
        btn.textContent = symbol;
        return btn;
    }

    function applyOpen() {
        if (!controls) return;
        controls.style.display = state.open ? 'flex' : 'none';
        toggleBtn.title = state.open ? txt('tb_close') : txt('tb_open');
        toggleBtn.setAttribute('aria-label', toggleBtn.title);
        toggleBtn.setAttribute('aria-expanded', String(state.open));
    }

    function buildToolbar() {
        if (document.getElementById('exammagnifier-toolbar')) return;

        toolbar = document.createElement('div');
        toolbar.id = 'exammagnifier-toolbar';
        toolbar.className = 'exammagnifier-ui';
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', txt('tb_label'));

        toggleBtn = makeButton('toggle', txt('tb_close'), '🔍');

        controls = document.createElement('span');
        controls.className = 'exammagnifier-controls';

        if (cfg().allow_zoom) {
            btns.zoomOut = makeButton('zoom-out', txt('zoom_out'), '−');
            zoomVal = document.createElement('span');
            zoomVal.className = 'exammagnifier-zoomval';
            zoomVal.setAttribute('aria-live', 'polite');
            zoomVal.textContent = '100 %';
            btns.zoomIn = makeButton('zoom-in', txt('zoom_in'), '+');

            btns.zoomOut.addEventListener('click', function () { zoomBy(-1); });
            btns.zoomIn.addEventListener('click', function () { zoomBy(1); });

            controls.appendChild(btns.zoomOut);
            controls.appendChild(zoomVal);
            controls.appendChild(btns.zoomIn);
        }

        if (cfg().allow_invert) {
            btns.invert = makeButton('invert', txt('invert'), '◐');
            btns.invert.setAttribute('aria-pressed', 'false');
            btns.invert.addEventListener('click', toggleInvert);
            controls.appendChild(btns.invert);
        }

        if (cfg().allow_cursor) {
            btns.cursor = makeButton('cursor', txt('cursor'), '➤');
            btns.cursor.setAttribute('aria-pressed', 'false');
            btns.cursor.addEventListener('click', toggleCursor);
            controls.appendChild(btns.cursor);
        }

        toggleBtn.addEventListener('click', function () {
            state.open = !state.open;
            applyOpen();
            saveState();
        });

        toolbar.appendChild(toggleBtn);
        toolbar.appendChild(controls);

        // Direkt unter <html>: bleibt vom body-Transform (Zoom) unberührt
        document.documentElement.appendChild(toolbar);
    }

    // -------------------------------------------------------------------------
    // Maus & Tastatur
    // -------------------------------------------------------------------------

    function isTypingTarget(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
    }

    function bindMouse() {
        document.addEventListener('mousemove', function (e) {
            lastClient = { x: e.clientX, y: e.clientY };

            if (currentZoom() > 100) {
                setTargetFromClient(e.clientX, e.clientY);
            }
            if (state.cur && cursorEl) {
                cursorEl.style.left = (e.clientX - 6) + 'px';
                cursorEl.style.top  = (e.clientY - 4) + 'px';
            }
        }, { passive: true });

        document.addEventListener('mousedown', function (e) {
            if (!state.cur || !ringEl) return;
            ringEl.style.left = (e.clientX - 35) + 'px';
            ringEl.style.top  = (e.clientY - 35) + 'px';
            ringEl.classList.remove('exammagnifier-pulse');
            void ringEl.offsetWidth; // Reflow erzwingen, damit die Animation neu startet
            ringEl.classList.add('exammagnifier-pulse');
        }, true);
    }

    function bindKeys() {
        document.addEventListener('keydown', function (e) {
            if (!e.ctrlKey || !e.altKey || e.shiftKey || e.metaKey) return;

            // In Eingabefeldern nichts abfangen: Strg+Alt = AltGr auf Windows,
            // sonst würden z.B. AltGr+0 (}) oder AltGr(+~) beim Tippen zoomen
            if (isTypingTarget(e.target)) return;

            var code = e.code || '';
            var handled = false;

            if (code === 'NumpadAdd' || code === 'Equal' || code === 'BracketRight' || e.key === '+') {
                zoomBy(1);
                handled = true;
            } else if (code === 'NumpadSubtract' || code === 'Minus' || code === 'Slash' || e.key === '-') {
                zoomBy(-1);
                handled = true;
            } else if (code === 'Digit0' || code === 'Numpad0') {
                zoomReset();
                handled = true;
            } else if (code === 'KeyI') {
                toggleInvert();
                handled = true;
            } else if (code === 'KeyP') {
                toggleCursor();
                handled = true;
            }

            if (handled) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
    }

    // -------------------------------------------------------------------------
    // Initialisierung
    // -------------------------------------------------------------------------

    function init() {
        var config = cfg();
        if (!config.enabled) return;
        if (document.getElementById('exammagnifier-toolbar')) return;
        if (!config.allow_zoom && !config.allow_invert && !config.allow_cursor) return;

        loadState();
        buildToolbar();
        bindMouse();
        bindKeys();

        // Gespeicherten Zustand wiederherstellen (überlebt Fragenwechsel)
        applyOpen();
        applyZoom();
        applyInvert();
        applyCursor();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Fallback: Config kam erst nach DOMContentLoaded an (addOnLoadCode)
    window.addEventListener('load', function () {
        if (cfg().enabled && !document.getElementById('exammagnifier-toolbar')) {
            init();
        }
    });

})();
