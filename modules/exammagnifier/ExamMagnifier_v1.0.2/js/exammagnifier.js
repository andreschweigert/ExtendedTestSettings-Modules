/**
 * exammagnifier.js - Bildschirmlupe für ILIAS Testplayer
 * Part of ExtendedTestSettings Plugin
 *
 * Vollbild-Zoom: transform auf <body>, transform-origin wird per
 * Schiebe-Steuerung bewegt (wie der Vollbildmodus der Windows-Lupe):
 * In der Bildmitte liegt eine Ruhezone — dort bewegt sich nichts.
 * Im Randbereich schiebt die Mausbewegung selbst den Ausschnitt
 * (Ratsche: nur auswärts, Zurückziehen schiebt nicht zurück — so
 * funktionieren wiederholte Wischbewegungen). Steht die Hand, steht
 * das Bild; bei gedrückter Maustaste wird nie geschoben (stabiles
 * Klicken auch am Rand). Nur ganz außen, wo der Zeiger am
 * Viewport-Rand ansteht und keine Deltas mehr liefert, gleitet der
 * Ausschnitt langsam nach. Die eigene UI (Toolbar, Zeiger-Overlay)
 * hängt direkt unter <html> und bleibt dadurch unskaliert. Kein
 * DOM-Umbau, keine iframes werden bewegt (TinyMCE-sicher).
 *
 * Config via window.ExamMagnifier.config (addOnLoadCode) — NIE beim
 * IIFE-Init cachen, immer live über cfg() lesen.
 */

(function () {
    'use strict';

    var ZOOM_STEPS = [100, 150, 200, 300, 400, 600, 800, 1200, 1600];

    // Schiebezone: äußere 18 % je Seite, innen ist Ruhezone
    var EDGE_BAND = 0.18;
    // Übersetzung Mausbewegung → Sichtverschiebung: 1:1 bei kleinem Zoom,
    // moderat beschleunigt bei hohen Stufen (sonst endlose Mauswege)
    function gear(s) {
        return Math.min(1 + (s - 1) * 0.25, 5);
    }
    // Nachgleiten ganz außen: Abstand zum Viewport-Rand (px) und Basistempo
    var GLIDE_MARGIN = 8;
    var GLIDE_SPEED = 6;
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
    var prevClient = null;
    var lastButtons = 0;
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

    function pointerOverOwnUi(x, y) {
        // Toolbar liegt am Rand — dort darf kein Schieben ausgelöst werden
        if (!toolbar) return false;
        var r = toolbar.getBoundingClientRect();
        return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
    }

    function applyOrigin() {
        document.body.style.transformOrigin = origin.x + 'px ' + origin.y + 'px';
    }

    /**
     * Schiebe-Steuerung: Mausbewegung im Randbereich schiebt den Ausschnitt.
     * Ratsche: nur die auswärts gerichtete Komponente schiebt — Zurückziehen
     * bewegt nichts, dadurch funktionieren wiederholte Wischbewegungen.
     */
    function handlePanMove(x, y, buttons) {
        var s = currentZoom() / 100;
        if (s <= 1) { prevClient = null; return; }

        var prev = prevClient;
        prevClient = { x: x, y: y };

        // Bei gedrückter Maustaste oder über der Toolbar nie schieben
        if (!prev || buttons || pointerOverOwnUi(x, y)) return;

        var w = window.innerWidth;
        var h = window.innerHeight;
        var dx = x - prev.x;
        var dy = y - prev.y;
        var g = gear(s);
        var vx = 0, vy = 0;

        if ((x < w * EDGE_BAND && dx < 0) || (x > w * (1 - EDGE_BAND) && dx > 0)) {
            vx = dx * g;
        }
        if ((y < h * EDGE_BAND && dy < 0) || (y > h * (1 - EDGE_BAND) && dy > 0)) {
            vy = dy * g;
        }

        if (vx || vy) {
            // Sichtverschiebung v entspricht Origin-Verschiebung v/(s-1)
            origin.x += vx / (s - 1);
            origin.y += vy / (s - 1);
            clampOriginToViewport();
            applyOrigin();
        }
    }

    /**
     * Nachgleiten nur ganz außen: Steht der Zeiger am Viewport-Rand an,
     * liefert der Browser keine Bewegungs-Deltas mehr — dort gleitet der
     * Ausschnitt langsam weiter, solange der Zeiger dort verharrt.
     */
    function glideTick() {
        panRaf = null;
        var s = currentZoom() / 100;
        if (s <= 1) return;

        if (pointerInWindow && lastClient && !lastButtons
            && !pointerOverOwnUi(lastClient.x, lastClient.y)) {

            var w = window.innerWidth;
            var h = window.innerHeight;
            var v = GLIDE_SPEED * Math.sqrt(s); // moderat mit dem Zoom skaliert
            var vx = 0, vy = 0;

            if (lastClient.x <= GLIDE_MARGIN)          vx = -v;
            else if (lastClient.x >= w - GLIDE_MARGIN) vx = v;
            if (lastClient.y <= GLIDE_MARGIN)          vy = -v;
            else if (lastClient.y >= h - GLIDE_MARGIN) vy = v;

            if (vx || vy) {
                origin.x += vx / (s - 1);
                origin.y += vy / (s - 1);
                clampOriginToViewport();
                applyOrigin();
            }
        }

        panRaf = requestAnimationFrame(glideTick);
    }

    function startPanLoop() {
        if (!panRaf) {
            panRaf = requestAnimationFrame(glideTick);
        }
    }

    function stopPanLoop() {
        if (panRaf) {
            cancelAnimationFrame(panRaf);
            panRaf = null;
        }
        prevClient = null;
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
            lastButtons = e.buttons || 0;
            pointerInWindow = true;

            handlePanMove(e.clientX, e.clientY, e.buttons || 0);

            if (state.cur && cursorEl) {
                cursorEl.style.left = (e.clientX - 6) + 'px';
                cursorEl.style.top  = (e.clientY - 4) + 'px';
            }
        }, { passive: true });

        // Zeiger verlässt das Fenster: Schieben/Nachgleiten anhalten,
        // Delta-Kette zurücksetzen (sonst Sprung beim Wiedereintritt)
        document.documentElement.addEventListener('mouseleave', function () {
            pointerInWindow = false;
            prevClient = null;
        });

        document.addEventListener('mouseup', function (e) {
            lastButtons = e.buttons || 0;
        }, true);

        document.addEventListener('mousedown', function (e) {
            lastButtons = e.buttons || 1;
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
