/**
 * examdyslexia.js - Legasthenie-Modus für ILIAS Testplayer
 * Part of ExtendedTestSettings Plugin
 *
 * Leiste oben rechts: Ein/Aus-Schalter (allow_toggle) und Zahnrad (allow_customize).
 * Das Zahnrad öffnet ein Panel, in dem der Studierende Hintergrundfarbe,
 * Zeilenabstand und Zeichenabstand selbst verstellt — entlang derselben Stufen,
 * die auch dem Dozenten zur Verfügung stehen. Dessen Einstellungen sind die
 * Startwerte, "Zurücksetzen" stellt sie wieder her.
 *
 * Zustand (an/aus + gewählte Stufen) liegt als JSON im sessionStorage und
 * überlebt damit den Fragenwechsel (ILIAS lädt die Seite dabei komplett neu).
 *
 * Config via window.ExamDyslexia.config (addOnLoadCode) — NIE beim IIFE-Init
 * cachen, immer live über cfg() lesen.
 */

(function () {
    'use strict';

    // Fallbacks, falls die Config die Listen (noch) nicht mitliefert
    var BG_FALLBACK = ['none', '#FEFAE0', '#FFFDE7', '#E3F2FD'];
    var LH_FALLBACK = ['1.5', '1.8', '2.0', '2.5'];
    var LS_FALLBACK = ['0', '0.05em', '0.12em', '0.2em'];

    // Farbfelder in der Reihenfolge von BG_FALLBACK
    var BG_LABEL_KEYS = ['bg_none', 'bg_cream', 'bg_yellow', 'bg_blue'];
    // Stufennamen für den Zeichenabstand (Zeilenabstand zeigt seinen Zahlenwert)
    var LS_LABEL_KEYS = ['ls_0', 'ls_1', 'ls_2', 'ls_3'];

    // Laufzeit-Zustand (wird über sessionStorage über Fragenwechsel gerettet)
    var state = { on: false, bg: 'none', lh: '1.8', ls: '0.05em', open: false };

    var bar = null, toggleBtn = null, toggleLabel = null, gearBtn = null, panel = null;
    var swatches = [], lhVal = null, lsVal = null;
    var initialized = false;

    // -------------------------------------------------------------------------
    // Config & Hilfsfunktionen
    // -------------------------------------------------------------------------

    function cfg() {
        return (window.ExamDyslexia && window.ExamDyslexia.config) || {};
    }

    function txt(key) {
        var labels = cfg().labels || {};
        return labels[key] || key;
    }

    function bgList() { return cfg().bg_colors || BG_FALLBACK; }
    function lhList() { return cfg().line_heights || LH_FALLBACK; }
    function lsList() { return cfg().letter_spacings || LS_FALLBACK; }

    function storageKey() {
        var urlParams = new URLSearchParams(window.location.search);
        var id = urlParams.get('active_id') || cfg().ref_id || 'unknown';
        return 'examdyslexia_state_' + id;
    }

    // Whitelist-Prüfung: nur Werte aus der Liste, sonst der Dozentenwert
    function pick(list, value, fallback) {
        return list.indexOf(value) >= 0 ? value : fallback;
    }

    // Startwerte des Dozenten (auch das Ziel von "Zurücksetzen")
    function teacherDefaults() {
        var config = cfg();
        return {
            bg: pick(bgList(), config.bg_color, bgList()[0]),
            lh: pick(lhList(), config.line_height, '1.8'),
            ls: pick(lsList(), config.letter_spacing, '0.05em')
        };
    }

    function injectFontFaceFallback(fontPath) {
        if (!fontPath) return;
        var styleId = 'examdyslexia-fontface';
        if (document.getElementById(styleId)) return;

        var style = document.createElement('style');
        style.id = styleId;
        style.textContent = [
            '@font-face {',
            '  font-family: "OpenDyslexic";',
            '  src: url("' + fontPath + 'OpenDyslexic-Regular.woff2") format("woff2"),',
            '       url("' + fontPath + 'OpenDyslexic-Regular.woff") format("woff");',
            '  font-weight: normal; font-display: swap;',
            '}',
            '@font-face {',
            '  font-family: "OpenDyslexic";',
            '  src: url("' + fontPath + 'OpenDyslexic-Bold.woff2") format("woff2"),',
            '       url("' + fontPath + 'OpenDyslexic-Bold.woff") format("woff");',
            '  font-weight: bold; font-display: swap;',
            '}'
        ].join('\n');
        document.head.appendChild(style);
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
        var def = teacherDefaults();
        state.bg = def.bg;
        state.lh = def.lh;
        state.ls = def.ls;

        try {
            var raw = sessionStorage.getItem(storageKey());
            if (!raw) return;
            var parsed = JSON.parse(raw);

            state.on = parsed.on === true;

            // Gespeicherte Stufen (und ein offenes Panel) nur übernehmen, wenn
            // der Dozent das Anpassen noch erlaubt — sonst gelten seine Werte
            if (cfg().allow_customize) {
                state.open = parsed.open === true;
                state.bg = pick(bgList(), parsed.bg, def.bg);
                state.lh = pick(lhList(), parsed.lh, def.lh);
                state.ls = pick(lsList(), parsed.ls, def.ls);
            }
        } catch (e) {
            console.warn('[ExamDyslexia] Zustand konnte nicht geladen werden', e);
        }
    }

    // -------------------------------------------------------------------------
    // Darstellung anwenden
    // -------------------------------------------------------------------------

    function applyStyles() {
        var root = document.documentElement;
        root.style.setProperty('--examdyslexia-line-height', state.lh);
        root.style.setProperty('--examdyslexia-letter-spacing', state.ls);
        root.style.setProperty('--examdyslexia-bg', state.bg === 'none' ? 'transparent' : state.bg);
        updateRulerHeight();
    }

    // -------------------------------------------------------------------------
    // Leseschiene (Reading Ruler)
    // -------------------------------------------------------------------------

    function updateRulerHeight() {
        var ruler = document.getElementById('examdyslexia-ruler');
        if (!ruler) return;
        var lh = parseFloat(state.lh) || 1.8;
        ruler.style.height = (lh * 1.15).toFixed(2) + 'em';
    }

    function initReadingRuler() {
        if (document.getElementById('examdyslexia-ruler')) return;

        var ruler = document.createElement('div');
        ruler.id = 'examdyslexia-ruler';
        ruler.setAttribute('aria-hidden', 'true');
        document.body.appendChild(ruler);
        updateRulerHeight();

        var target = document.getElementById('tst_output') || document.body;
        var cachedRect = null;

        // Rect nur bei Resize neu berechnen (nicht bei jedem mousemove)
        window.addEventListener('resize', function () {
            cachedRect = null;
        }, { passive: true });

        target.addEventListener('mousemove', function (e) {
            if (!document.body.classList.contains('examdyslexia-active')) return;
            if (!cachedRect) {
                cachedRect = target.getBoundingClientRect();
            }
            var h = ruler.offsetHeight || Math.round((parseFloat(state.lh) || 1.8) * 1.15 * 16);
            ruler.style.top  = (e.clientY - Math.floor(h / 2)) + 'px';
            ruler.style.left  = cachedRect.left + 'px';
            ruler.style.width = cachedRect.width + 'px';
            ruler.style.display = 'block';
        }, { passive: true });

        target.addEventListener('mouseleave', function () {
            ruler.style.display = 'none';
        });
    }

    // -------------------------------------------------------------------------
    // Aktivierung / Deaktivierung
    // -------------------------------------------------------------------------

    function applyActive() {
        document.body.classList.toggle('examdyslexia-active', state.on);

        if (state.on) {
            initReadingRuler();
        } else {
            // Leseschiene ausblenden (Element bleibt im DOM für schnelles Re-Aktivieren)
            var ruler = document.getElementById('examdyslexia-ruler');
            if (ruler) {
                ruler.style.display = 'none';
            }
        }

        if (toggleBtn) {
            toggleBtn.classList.toggle('examdyslexia-on', state.on);
            toggleBtn.setAttribute('aria-pressed', String(state.on));
            toggleLabel.textContent = txt(state.on ? 'toggle_on' : 'toggle_off');
        }
    }

    function setActive(on) {
        state.on = on;
        applyActive();
        saveState();
    }

    // -------------------------------------------------------------------------
    // Panel: Werte ändern
    // -------------------------------------------------------------------------

    function refreshPanel() {
        for (var i = 0; i < swatches.length; i++) {
            var isOn = bgList()[i] === state.bg;
            swatches[i].classList.toggle('examdyslexia-swatch-on', isOn);
            swatches[i].setAttribute('aria-pressed', String(isOn));
        }
        if (lhVal) {
            lhVal.textContent = state.lh;
        }
        if (lsVal) {
            var idx = lsList().indexOf(state.ls);
            lsVal.textContent = txt(LS_LABEL_KEYS[idx] || 'ls_1');
        }
    }

    // Jede Änderung im Panel schaltet den Lesemodus mit ein — sonst bliebe
    // die Einstellung ohne sichtbare Wirkung
    function changed() {
        applyStyles();
        refreshPanel();
        if (!state.on) {
            state.on = true;
            applyActive();
        }
        saveState();
    }

    function setBg(value) {
        state.bg = pick(bgList(), value, state.bg);
        changed();
    }

    function stepLh(delta) {
        var list = lhList();
        var idx = Math.max(0, Math.min(list.indexOf(state.lh) + delta, list.length - 1));
        state.lh = list[idx];
        changed();
    }

    function stepLs(delta) {
        var list = lsList();
        var idx = Math.max(0, Math.min(list.indexOf(state.ls) + delta, list.length - 1));
        state.ls = list[idx];
        changed();
    }

    function resetToTeacher() {
        var def = teacherDefaults();
        state.bg = def.bg;
        state.lh = def.lh;
        state.ls = def.ls;
        applyStyles();
        refreshPanel();
        saveState();
    }

    // -------------------------------------------------------------------------
    // Panel: Aufbau
    // -------------------------------------------------------------------------

    function makeRow(labelText) {
        var row = document.createElement('div');
        row.className = 'examdyslexia-row';

        var caption = document.createElement('span');
        caption.className = 'examdyslexia-rowlabel';
        caption.textContent = labelText;
        row.appendChild(caption);

        return row;
    }

    function makeStepButton(label, symbol, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'examdyslexia-step';
        btn.title = label;
        btn.setAttribute('aria-label', label);
        btn.textContent = symbol;
        btn.addEventListener('click', onClick);
        return btn;
    }

    function buildStepper(decLabel, incLabel, onDec, onInc) {
        var wrap = document.createElement('span');
        wrap.className = 'examdyslexia-stepper';

        var val = document.createElement('span');
        val.className = 'examdyslexia-val';
        val.setAttribute('aria-live', 'polite');

        wrap.appendChild(makeStepButton(decLabel, '−', onDec));
        wrap.appendChild(val);
        wrap.appendChild(makeStepButton(incLabel, '+', onInc));

        return { wrap: wrap, val: val };
    }

    function buildPanel() {
        panel = document.createElement('div');
        panel.id = 'examdyslexia-panel';
        panel.setAttribute('role', 'group');
        panel.setAttribute('aria-label', txt('panel_label'));

        // Hintergrundfarbe als Farbfelder
        var bgRow = makeRow(txt('bg_label'));
        var swatchWrap = document.createElement('span');
        swatchWrap.className = 'examdyslexia-swatches';

        bgList().forEach(function (color, i) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'examdyslexia-swatch';
            if (color === 'none') {
                btn.classList.add('examdyslexia-swatch-none');
            } else {
                btn.style.background = color;
            }
            var label = txt(BG_LABEL_KEYS[i] || 'bg_label');
            btn.title = label;
            btn.setAttribute('aria-label', label);
            btn.setAttribute('aria-pressed', 'false');
            btn.addEventListener('click', function () { setBg(color); });
            swatchWrap.appendChild(btn);
            swatches.push(btn);
        });

        bgRow.appendChild(swatchWrap);
        panel.appendChild(bgRow);

        // Zeilenabstand
        var lhRow = makeRow(txt('lh_label'));
        var lhStepper = buildStepper(
            txt('lh_dec'), txt('lh_inc'),
            function () { stepLh(-1); },
            function () { stepLh(1); }
        );
        lhVal = lhStepper.val;
        lhRow.appendChild(lhStepper.wrap);
        panel.appendChild(lhRow);

        // Zeichenabstand
        var lsRow = makeRow(txt('ls_label'));
        var lsStepper = buildStepper(
            txt('ls_dec'), txt('ls_inc'),
            function () { stepLs(-1); },
            function () { stepLs(1); }
        );
        lsVal = lsStepper.val;
        lsRow.appendChild(lsStepper.wrap);
        panel.appendChild(lsRow);

        // Zurücksetzen auf die Dozentenwerte
        var reset = document.createElement('button');
        reset.type = 'button';
        reset.className = 'examdyslexia-reset';
        reset.textContent = txt('reset');
        reset.title = txt('reset_title');
        reset.addEventListener('click', resetToTeacher);
        panel.appendChild(reset);

        bar.appendChild(panel);
        refreshPanel();
    }

    function applyPanelOpen() {
        if (!panel) return;
        panel.style.display = state.open ? 'block' : 'none';
        gearBtn.classList.toggle('examdyslexia-on', state.open);
        gearBtn.setAttribute('aria-expanded', String(state.open));
        gearBtn.title = txt(state.open ? 'panel_close' : 'panel_open');
        gearBtn.setAttribute('aria-label', gearBtn.title);
    }

    function togglePanel() {
        state.open = !state.open;
        applyPanelOpen();
        saveState();
    }

    // -------------------------------------------------------------------------
    // Leiste (Toggle + Zahnrad)
    // -------------------------------------------------------------------------

    function buildBar() {
        var config = cfg();
        if (document.getElementById('examdyslexia-bar')) return;
        if (!config.allow_toggle && !config.allow_customize) return;

        bar = document.createElement('div');
        bar.id = 'examdyslexia-bar';

        var buttons = document.createElement('div');
        buttons.className = 'examdyslexia-buttons';

        if (config.allow_toggle) {
            toggleBtn = document.createElement('button');
            toggleBtn.id = 'examdyslexia-toggle';
            toggleBtn.type = 'button';
            toggleBtn.setAttribute('aria-pressed', String(state.on));
            toggleBtn.setAttribute('aria-label', txt('toggle_aria'));
            toggleBtn.title = txt('toggle_title');

            var icon = document.createElement('span');
            icon.className = 'examdyslexia-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '👁';

            toggleLabel = document.createElement('span');
            toggleLabel.className = 'examdyslexia-label';
            toggleLabel.textContent = txt(state.on ? 'toggle_on' : 'toggle_off');

            toggleBtn.appendChild(icon);
            toggleBtn.appendChild(toggleLabel);
            toggleBtn.addEventListener('click', function () { setActive(!state.on); });

            buttons.appendChild(toggleBtn);
        }

        if (config.allow_customize) {
            gearBtn = document.createElement('button');
            gearBtn.id = 'examdyslexia-gear';
            gearBtn.type = 'button';
            gearBtn.setAttribute('aria-expanded', 'false');
            gearBtn.textContent = '⚙';
            gearBtn.addEventListener('click', togglePanel);
            buttons.appendChild(gearBtn);
        }

        bar.appendChild(buttons);
        document.body.appendChild(bar);

        if (config.allow_customize) {
            buildPanel();
            applyPanelOpen();
        }
    }

    // -------------------------------------------------------------------------
    // Panel schließen: Escape oder Klick daneben
    // -------------------------------------------------------------------------

    function bindDismiss() {
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && state.open) {
                togglePanel();
                if (gearBtn) gearBtn.focus();
            }
        });

        document.addEventListener('mousedown', function (e) {
            if (state.open && bar && !bar.contains(e.target)) {
                togglePanel();
            }
        });
    }

    // -------------------------------------------------------------------------
    // Initialisierung
    // -------------------------------------------------------------------------

    function init() {
        var config = cfg();
        // Ohne Config nichts tun — der load-Handler holt den Aufbau dann nach.
        // Das Flag schützt auch den Fall ohne eigene Leiste (beide Optionen aus)
        // vor doppelt registrierten Listenern.
        if (!config.enabled || initialized) return;
        initialized = true;

        loadState();

        // Ohne Toggle-Button ist der Modus für alle erzwungen
        if (!config.allow_toggle) {
            state.on = true;
        }

        applyStyles();

        if (config.use_font && config.font_path) {
            injectFontFaceFallback(config.font_path);
        }

        buildBar();
        bindDismiss();
        applyActive();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Fallback: Config kam erst nach DOMContentLoaded an (addOnLoadCode)
    window.addEventListener('load', init);

})();
