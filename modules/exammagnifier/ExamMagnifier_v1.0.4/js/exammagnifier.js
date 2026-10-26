/**
 * exammagnifier.js - Bildschirmlupe für ILIAS Testplayer
 * Part of ExtendedTestSettings Plugin
 *
 * Erprobungsversion mit VIER umschaltbaren Zoom-Steuerungen (Toolbar-Button
 * oder Strg+Alt+M, Auswahl bleibt pro Teilnehmer in sessionStorage):
 *
 *   push   Schieben — Mausbewegung im Randbereich schiebt den Ausschnitt
 *          (Ratsche: nur auswärts; bei gedrückter Taste nie; ganz außen
 *          langsames Nachgleiten, weil der angepinnte Zeiger keine
 *          Bewegungs-Deltas mehr liefert)
 *   glide  Gleiten — Ausschnitt gleitet von allein, solange der Zeiger im
 *          Randband steht (je näher am Rand, desto schneller)
 *   prop   Direkt — Ausschnitt hängt 1:1 an der Zeigerposition (geglättet)
 *   reflow Umbruch — Layoutbreite wird verengt und hochskaliert: Text
 *          bricht um, kein seitliches Bewegen, nur normales Scrollen
 *
 * Technik: transform auf <body> (push/glide/prop: Origin wandert; reflow:
 * zusätzlich width-Kompensation). Die eigene UI (Toolbar, Zeiger-Overlay)
 * hängt direkt unter <html> und bleibt dadurch unskaliert. Kein DOM-Umbau,
 * keine iframes werden bewegt (TinyMCE-sicher).
 *
 * Config via window.ExamMagnifier.config (addOnLoadCode) — NIE beim
 * IIFE-Init cachen, immer live über cfg() lesen.
 */

(function () {
    'use strict';

    var ZOOM_STEPS = [100, 150, 200, 300, 400, 600, 800, 1200, 1600];
    var MODES = ['push', 'glide', 'prop', 'reflow'];

    // push: äußere 18 % je Seite sind Schiebezone
    var PUSH_BAND = 0.18;
    // push: Nachgleiten ganz außen (Abstand zum Viewport-Rand, Basistempo)
    var GLIDE_MARGIN = 8;
    var GLIDE_SPEED = 6;
    // glide: äußere 22 % je Seite, Sichtgeschwindigkeit am Rand (px/Frame)
    var GLIDE_BAND = 0.22;
    var GLIDE_PAN_SPEED = 26;
    // Ruhezonen-Hinweis nach dem Aktivieren (ms)
    var HINT_DURATION = 2500;

    // push: Übersetzung Mausbewegung → Sichtverschiebung (1:1 bei kleinem
    // Zoom, moderat beschleunigt bei hohen Stufen, sonst endlose Mauswege)
    function gear(s) {
        return Math.min(1 + (s - 1) * 0.25, 5);
    }

    // Laufzeit-Zustand (wird über sessionStorage über Fragenwechsel gerettet)
    var state = { z: 0, m: 'push', inv: false, cur: false, open: true };

    var toolbar = null, controls = null, zoomVal = null, toggleBtn = null;
    var cursorEl = null, ringEl = null, hintEl = null;
    var btns = {};
    var hintTimer = 0;

    // Zoom-Schwenk: Origin in Seitenkoordinaten
    var origin = { x: 0, y: 0 };
    var target = { x: 0, y: 0 };   // prop: Ziel-Origin (lerp)
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

    function modeLabel(m) {
        return txt('mode_' + m);
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
            state.m    = MODES.indexOf(parsed.m) >= 0 ? parsed.m : 'push';
            state.inv  = !!parsed.inv;
            state.cur  = !!parsed.cur;
            state.open = parsed.open !== false;
        } catch (e) {
            console.warn('[ExamMagnifier] Zustand konnte nicht geladen werden', e);
        }
    }

    // -------------------------------------------------------------------------
    // Vollbild-Zoom — gemeinsame Helfer
    // -------------------------------------------------------------------------

    function clampOriginToViewport() {
        var minX = window.pageXOffset, maxX = minX + window.innerWidth;
        var minY = window.pageYOffset, maxY = minY + window.innerHeight;
        origin.x = Math.min(Math.max(origin.x, minX), maxX);
        origin.y = Math.min(Math.max(origin.y, minY), maxY);
    }

    function applyOrigin() {
        document.body.style.transformOrigin = origin.x + 'px ' + origin.y + 'px';
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
        target.x = origin.x;
        target.y = origin.y;
    }

    function pointerOverOwnUi(x, y) {
        // Toolbar liegt am Rand — dort darf kein Schieben/Gleiten ausgelöst werden
        if (!toolbar) return false;
        var r = toolbar.getBoundingClientRect();
        return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
    }

    // -------------------------------------------------------------------------
    // Steuerung "push": Mausbewegung im Randbereich schiebt (Ratsche)
    // -------------------------------------------------------------------------

    function handlePushMove(x, y, buttons, prev) {
        var s = currentZoom() / 100;
        if (s <= 1 || !prev || buttons || pointerOverOwnUi(x, y)) return;

        var w = window.innerWidth;
        var h = window.innerHeight;
        var dx = x - prev.x;
        var dy = y - prev.y;
        var g = gear(s);
        var vx = 0, vy = 0;

        if ((x < w * PUSH_BAND && dx < 0) || (x > w * (1 - PUSH_BAND) && dx > 0)) {
            vx = dx * g;
        }
        if ((y < h * PUSH_BAND && dy < 0) || (y > h * (1 - PUSH_BAND) && dy > 0)) {
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

    // -------------------------------------------------------------------------
    // rAF-Loop: bedient Nachgleiten (push), Rand-Gleiten (glide), Lerp (prop)
    // -------------------------------------------------------------------------

    function edgeVelocityQuad(frac) {
        var v = 0;
        if (frac < GLIDE_BAND) {
            v = -(GLIDE_BAND - frac) / GLIDE_BAND;
        } else if (frac > 1 - GLIDE_BAND) {
            v = (frac - (1 - GLIDE_BAND)) / GLIDE_BAND;
        }
        return v * Math.abs(v);
    }

    function panTick() {
        panRaf = null;
        var s = currentZoom() / 100;
        if (s <= 1 || state.m === 'reflow') return;

        var pointerUsable = pointerInWindow && lastClient && !lastButtons
            && !pointerOverOwnUi(lastClient.x, lastClient.y);

        if (state.m === 'push' && pointerUsable) {
            // Nachgleiten nur ganz außen, wo der Zeiger keine Deltas mehr liefert
            var w = window.innerWidth;
            var h = window.innerHeight;
            var v = GLIDE_SPEED * Math.sqrt(s);
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
        } else if (state.m === 'glide' && pointerUsable) {
            var gx = edgeVelocityQuad(lastClient.x / window.innerWidth);
            var gy = edgeVelocityQuad(lastClient.y / window.innerHeight);
            if (gx || gy) {
                var f = GLIDE_PAN_SPEED * s / (s - 1);
                origin.x += gx * f;
                origin.y += gy * f;
                clampOriginToViewport();
                applyOrigin();
            }
        } else if (state.m === 'prop') {
            origin.x += (target.x - origin.x) * 0.18;
            origin.y += (target.y - origin.y) * 0.18;
            clampOriginToViewport();
            applyOrigin();
        }

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
        prevClient = null;
    }

    // -------------------------------------------------------------------------
    // Ruhezonen-Hinweis (nur push/glide)
    // -------------------------------------------------------------------------

    function showDeadzoneHint() {
        if (state.m !== 'push' && state.m !== 'glide') {
            if (hintEl) { hintEl.style.display = 'none'; }
            return;
        }
        if (!hintEl) {
            hintEl = document.createElement('div');
            hintEl.id = 'exammagnifier-deadzone';
            hintEl.className = 'exammagnifier-ui';
            hintEl.setAttribute('aria-hidden', 'true');
            hintEl.appendChild(document.createElement('span'));
            document.documentElement.appendChild(hintEl);
        }
        hintEl.firstChild.textContent = txt(state.m === 'push' ? 'deadzone_push' : 'deadzone_glide');
        hintEl.style.inset = (state.m === 'push' ? PUSH_BAND : GLIDE_BAND) * 100 + '%';
        hintEl.style.display = 'block';
        clearTimeout(hintTimer);
        hintTimer = setTimeout(function () {
            hintEl.style.display = 'none';
        }, HINT_DURATION);
    }

    // -------------------------------------------------------------------------
    // Zoom anwenden / Modus wechseln
    // -------------------------------------------------------------------------

    function clearZoomStyles() {
        stopPanLoop();
        document.body.style.transform = '';
        document.body.style.transformOrigin = '';
        document.body.style.width = '';
        document.documentElement.classList.remove('exammagnifier-zoomed');
        if (hintEl) { hintEl.style.display = 'none'; }
    }

    function applyZoom() {
        var z = currentZoom();
        if (zoomVal) {
            zoomVal.textContent = z + ' %';
        }

        if (z <= 100) {
            clearZoomStyles();
            wasZoomed = false;
            return;
        }

        var s = z / 100;
        document.documentElement.classList.add('exammagnifier-zoomed');

        if (state.m === 'reflow') {
            // Layoutbreite verengen und hochskalieren: Text bricht um,
            // kein seitliches Bewegen, nur normales Scrollen
            stopPanLoop();
            document.body.style.width = (100 / s) + '%';
            document.body.style.transformOrigin = '0 0';
            document.body.style.transform = 'scale(' + s + ')';
            if (hintEl) { hintEl.style.display = 'none'; }
            wasZoomed = true;
            return;
        }

        document.body.style.width = '';

        if (!wasZoomed) {
            centerOnQuestion();
            showDeadzoneHint();
        }
        wasZoomed = true;

        clampOriginToViewport();
        applyOrigin();
        document.body.style.transform = 'scale(' + s + ')';
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

    function cycleMode() {
        if (!cfg().allow_zoom) return;
        var idx = MODES.indexOf(state.m);
        state.m = MODES[(idx + 1) % MODES.length];
        if (btns.mode) {
            btns.mode.textContent = modeLabel(state.m);
        }
        // Umschalten setzt die Darstellung sauber neu auf
        var restoreHint = wasZoomed;
        clearZoomStyles();
        wasZoomed = false;
        applyZoom();
        if (restoreHint && currentZoom() > 100) {
            showDeadzoneHint();
        }
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

            // Erprobung: Steuerungs-Modus umschaltbar (Schieben/Gleiten/Direkt/Umbruch)
            btns.mode = makeButton('mode', txt('mode_title'), modeLabel(state.m));
            btns.mode.classList.add('exammagnifier-modebtn');
            btns.mode.addEventListener('click', cycleMode);
            controls.appendChild(btns.mode);
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
            var prev = prevClient;
            lastClient = { x: e.clientX, y: e.clientY };
            prevClient = { x: e.clientX, y: e.clientY };
            lastButtons = e.buttons || 0;
            pointerInWindow = true;

            if (state.m === 'push') {
                handlePushMove(e.clientX, e.clientY, e.buttons || 0, prev);
            } else if (state.m === 'prop' && currentZoom() > 100) {
                target.x = window.pageXOffset + e.clientX;
                target.y = window.pageYOffset + e.clientY;
            }

            if (state.cur && cursorEl) {
                cursorEl.style.left = (e.clientX - 6) + 'px';
                cursorEl.style.top  = (e.clientY - 4) + 'px';
            }
        }, { passive: true });

        // Zeiger verlässt das Fenster: Schieben/Gleiten anhalten,
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
            } else if (code === 'KeyM') {
                cycleMode();
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
