/**
 * exammagnifier.js - Bildschirmlupe für ILIAS Testplayer
 * Part of ExtendedTestSettings Plugin
 *
 * Vollbild-Zoom: transform auf <body>, transform-origin wird per
 * Rand-Schwenk gesteuert (wie der Vollbildmodus der Windows-Lupe):
 * In der Bildmitte liegt eine Ruhezone — dort bewegt sich nichts.
 * Erst am Rand gleitet der Ausschnitt in diese Richtung, je näher
 * am Rand desto schneller. Die eigene UI (Toolbar, Zeiger-Overlay)
 * hängt direkt unter <html> und bleibt dadurch unskaliert. Kein
 * DOM-Umbau, keine iframes werden bewegt (TinyMCE-sicher).
 *
 * Config via window.ExamMagnifier.config (addOnLoadCode) — NIE beim
 * IIFE-Init cachen, immer live über cfg() lesen.
 */

(function () {
    'use strict';

    var ZOOM_STEPS = [100, 150, 200, 300, 400, 600, 800, 1200, 1600];

    // Rand-Schwenk: äußere 22 % je Seite schwenken, innen ist Ruhezone
    var EDGE_BAND = 0.22;
    // Sichtgeschwindigkeit am Rand (px pro Frame), skaliert mit dem Zoom
    var PAN_SPEED = 26;
    // Ruhezonen-Hinweis nach dem Aktivieren (ms)
    var HINT_DURATION = 2500;

    // Laufzeit-Zustand (wird über sessionStorage über Fragenwechsel gerettet)
    var state = { z: 0, inv: false, cur: false, open: true };

    var toolbar = null, controls = null, zoomVal = null, toggleBtn = null;
    var cursorEl = null, ringEl = null, hintEl = null;
    var btns = {};
    var hintTimer = 0;

    // Zoom-Schwenk: Origin in Seitenkoordinaten
    var origin = { x: 0, y: 0 };
    var panRaf = null;
    var lastClient = null;
    var pointerInWindow = false;
    var wasZoomed = false;

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
    // Vollbild-Zoom mit Rand-Schwenk
    // -------------------------------------------------------------------------

    function clampOriginToViewport() {
        var minX = window.pageXOffset, maxX = minX + window.innerWidth;
        var minY = window.pageYOffset, maxY = minY + window.innerHeight;
        origin.x = Math.min(Math.max(origin.x, minX), maxX);
        origin.y = Math.min(Math.max(origin.y, minY), maxY);
    }

    // Beim Einschalten auf den Fragebereich zentrieren statt auf den Zeiger
    function centerOnQuestion() {
        var cx = window.innerWidth / 2;
        var cy = window.innerHeight / 2;
        var el = document.getElementById('tst_output');
        if (el) {
            var r = el.getBoundingClientRect();
            if (r.width > 0 && r.height > 0) {
                cx = Math.min(Math.max(r.left + r.width / 2, 0), window.innerWidth);
                cy = Math.min(Math.max(r.top + Math.min(r.height, window.innerHeight) / 2, 0), window.innerHeight);
            }
        }
        origin.x = window.pageXOffset + cx;
        origin.y = window.pageYOffset + cy;
    }

    // Quadratische Kennlinie: sanfter Einstieg, schnell direkt am Rand
    function edgeVelocity(frac) {
        var v = 0;
        if (frac < EDGE_BAND) {
            v = -(EDGE_BAND - frac) / EDGE_BAND;
        } else if (frac > 1 - EDGE_BAND) {
            v = (frac - (1 - EDGE_BAND)) / EDGE_BAND;
        }
        return v * Math.abs(v);
    }

    function pointerOverOwnUi(x, y) {
        // Toolbar liegt am Rand — dort darf kein Schwenken ausgelöst werden
        if (!toolbar) return false;
        var r = toolbar.getBoundingClientRect();
        return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
    }

    function panTick() {
        panRaf = null;
        var s = currentZoom() / 100;
        if (s <= 1) return;

        if (pointerInWindow && lastClient && !pointerOverOwnUi(lastClient.x, lastClient.y)) {
            var vx = edgeVelocity(lastClient.x / window.innerWidth);
            var vy = edgeVelocity(lastClient.y / window.innerHeight);
            if (vx || vy) {
                // Origin-Tempo so, dass die Sichtgeschwindigkeit mit dem Zoom skaliert
                var f = PAN_SPEED * s / (s - 1);
                origin.x += vx * f;
                origin.y += vy * f;
            }
        }

        clampOriginToViewport();
        document.body.style.transformOrigin = origin.x + 'px ' + origin.y + 'px';
        panRaf = requestAnimationFrame(panTick);
    }

    function startPanLoop() {
        if (!panRaf) {
            panRaf = requestAnimationFrame(panTick);
        }
    }

    function stopPanLoop() {
        if (panRaf) {
            cancelAnimationFrame(panRaf);
            panRaf = null;
        }
    }

    function showDeadzoneHint() {
        if (!hintEl) {
            hintEl = document.createElement('div');
            hintEl.id = 'exammagnifier-deadzone';
            hintEl.className = 'exammagnifier-ui';
            hintEl.setAttribute('aria-hidden', 'true');
            var label = document.createElement('span');
            label.textContent = txt('deadzone');
            hintEl.appendChild(label);
            document.documentElement.appendChild(hintEl);
        }
        hintEl.style.display = 'block';
        clearTimeout(hintTimer);
        hintTimer = setTimeout(function () {
            hintEl.style.display = 'none';
        }, HINT_DURATION);
    }

    function applyZoom() {
        var z = currentZoom();
        if (zoomVal) {
            zoomVal.textContent = z + ' %';
        }

        if (z <= 100) {
            stopPanLoop();
            document.body.style.transform = '';
            document.body.style.transformOrigin = '';
            document.documentElement.classList.remove('exammagnifier-zoomed');
            if (hintEl) { hintEl.style.display = 'none'; }
            wasZoomed = false;
            return;
        }

        if (!wasZoomed) {
            centerOnQuestion();
            showDeadzoneHint();
        }
        wasZoomed = true;

        clampOriginToViewport();
        document.documentElement.classList.add('exammagnifier-zoomed');
        document.body.style.transformOrigin = origin.x + 'px ' + origin.y + 'px';
        document.body.style.transform = 'scale(' + (z / 100) + ')';
        startPanLoop();
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
            pointerInWindow = true;

            if (state.cur && cursorEl) {
                cursorEl.style.left = (e.clientX - 6) + 'px';
                cursorEl.style.top  = (e.clientY - 4) + 'px';
            }
        }, { passive: true });

        // Zeiger verlässt das Fenster: Schwenken anhalten
        document.documentElement.addEventListener('mouseleave', function () {
            pointerInWindow = false;
        });

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
