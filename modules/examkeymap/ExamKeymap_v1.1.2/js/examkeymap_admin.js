/**
 * ExamKeymap Admin — Virtueller Keyboard-Editor für die globale Übersetzungstabelle
 * Part of ExtendedTestSettings Plugin
 *
 * Zeichnet ein ISO-Keyboard mit den effektiven Belegungen (eingebaute Tabelle
 * + Overrides aus examkeymap_custom). Klick auf eine Taste öffnet den Editor
 * (Basis / Shift / AltGr). Die Overrides werden als JSON in das Hidden-Feld
 * des Formulars serialisiert und serverseitig gespeichert.
 *
 * Benötigt js/examkeymap.js (liefert window.ExamKeymap.tables).
 */
(function () {
    'use strict';

    // Config live lesen — addOnLoadCode läuft nach addJavaScript
    function cfg() {
        return (window.ExamKeymapAdmin && window.ExamKeymapAdmin.config) || {};
    }

    function txt(key, fallback) {
        var t = cfg().txt || {};
        return t[key] || fallback;
    }

    function tables() {
        return (window.ExamKeymap && window.ExamKeymap.tables) || { de: {}, en: {} };
    }

    // ─── Physisches ISO-Layout (Zeilen für die Darstellung) ──────────────────
    // c = KeyboardEvent.code (editierbar), label = inaktive Taste, w = Breite

    var ROWS = [
        [
            { c: 'Backquote' }, { c: 'Digit1' }, { c: 'Digit2' }, { c: 'Digit3' },
            { c: 'Digit4' }, { c: 'Digit5' }, { c: 'Digit6' }, { c: 'Digit7' },
            { c: 'Digit8' }, { c: 'Digit9' }, { c: 'Digit0' }, { c: 'Minus' },
            { c: 'Equal' }, { label: '⌫', w: 2 }
        ],
        [
            { label: 'Tab ⇥', w: 1.5 }, { c: 'KeyQ' }, { c: 'KeyW' }, { c: 'KeyE' },
            { c: 'KeyR' }, { c: 'KeyT' }, { c: 'KeyY' }, { c: 'KeyU' }, { c: 'KeyI' },
            { c: 'KeyO' }, { c: 'KeyP' }, { c: 'BracketLeft' }, { c: 'BracketRight' },
            { label: '⏎', w: 1.5 }
        ],
        [
            { label: '🔍 / ⇪', w: 1.8, hint: 'caps' }, { c: 'KeyA' }, { c: 'KeyS' },
            { c: 'KeyD' }, { c: 'KeyF' }, { c: 'KeyG' }, { c: 'KeyH' }, { c: 'KeyJ' },
            { c: 'KeyK' }, { c: 'KeyL' }, { c: 'Semicolon' }, { c: 'Quote' },
            { c: 'Backslash' }
        ],
        [
            { label: '⇧', w: 1.25 }, { c: 'IntlBackslash' }, { c: 'KeyZ' },
            { c: 'KeyX' }, { c: 'KeyC' }, { c: 'KeyV' }, { c: 'KeyB' }, { c: 'KeyN' },
            { c: 'KeyM' }, { c: 'Comma' }, { c: 'Period' }, { c: 'Slash' },
            { label: '⇧', w: 2.75 }
        ],
        [
            { label: 'Ctrl', w: 1.5 }, { label: 'Alt', w: 1.5 }, { label: '', w: 7 },
            { label: 'AltGr', w: 1.5 }, { label: 'Ctrl', w: 1.5 }
        ]
    ];

    // ─── Zustand ─────────────────────────────────────────────────────────────

    var overrides = { de: {}, en: {} }; // nur Abweichungen von der eingebauten Tabelle
    var layout = 'de';
    var selectedCode = null;
    var dirty = false;

    function effectiveEntry(code) {
        if (overrides[layout][code]) {
            return overrides[layout][code];
        }
        return tables()[layout][code] || null;
    }

    function builtinEntry(code) {
        return tables()[layout][code] || null;
    }

    function isOverride(code) {
        return !!overrides[layout][code];
    }

    function isDisabled(code) {
        var o = overrides[layout][code];
        return !!o && !o.b && !o.s && !o.g;
    }

    function serialize() {
        var field = document.getElementById('examkeymap-overrides');
        if (field) {
            field.value = JSON.stringify(overrides);
        }
    }

    // ─── Rendering ───────────────────────────────────────────────────────────

    var root = null;

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) {
            node.className = cls;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function render() {
        if (!root) {
            return;
        }
        root.innerHTML = '';
        root.appendChild(renderToolbar());
        root.appendChild(renderKeyboard());
        root.appendChild(renderEditor());
        serialize();
    }

    function renderToolbar() {
        var bar = el('div', 'examkeymap-adm-toolbar');

        ['de', 'en'].forEach(function (l) {
            var btn = el('button',
                'btn btn-default examkeymap-adm-layoutbtn' + (l === layout ? ' active' : ''),
                txt('layout_' + l, l.toUpperCase()));
            btn.type = 'button';
            btn.addEventListener('click', function () {
                layout = l;
                selectedCode = null;
                render();
            });
            bar.appendChild(btn);
        });

        if (dirty) {
            bar.appendChild(el('span', 'examkeymap-adm-dirty', txt('dirty_hint', 'Ungespeicherte Änderungen')));
        }

        return bar;
    }

    function renderKeyboard() {
        var kb = el('div', 'examkeymap-adm-keyboard');

        ROWS.forEach(function (row) {
            var rowEl = el('div', 'examkeymap-adm-row');

            row.forEach(function (key) {
                var w = key.w || 1;

                if (!key.c) {
                    // Inaktive Taste (nur Darstellung)
                    var deadEl = el('div', 'examkeymap-adm-key dead', key.label || '');
                    deadEl.style.flexGrow = String(w);
                    if (key.hint === 'caps') {
                        deadEl.title = txt('caps_hint',
                            'ChromeOS-Lupe — als Feststelltaste nutzbar (Einstellung pro Test)');
                    }
                    rowEl.appendChild(deadEl);
                    return;
                }

                var entry = effectiveEntry(key.c);
                var keyEl = el('div', 'examkeymap-adm-key'
                    + (isOverride(key.c) ? ' override' : '')
                    + (isDisabled(key.c) ? ' disabled' : '')
                    + (key.c === selectedCode ? ' selected' : ''));
                keyEl.style.flexGrow = String(w);
                keyEl.title = key.c + (isOverride(key.c) ? ' — ' + txt('override', 'Angepasst') : '');

                keyEl.appendChild(el('span', 'examkeymap-adm-shift', entry ? (entry.s || '') : ''));
                keyEl.appendChild(el('span', 'examkeymap-adm-altgr', entry ? (entry.g || '') : ''));
                keyEl.appendChild(el('span', 'examkeymap-adm-base', entry ? (entry.b || '') : ''));

                keyEl.addEventListener('click', function () {
                    selectedCode = key.c;
                    render();
                });

                rowEl.appendChild(keyEl);
            });

            kb.appendChild(rowEl);
        });

        return kb;
    }

    function renderEditor() {
        var panel = el('div', 'examkeymap-adm-editor');

        if (!selectedCode) {
            panel.appendChild(el('p', 'examkeymap-adm-hint', txt('select_hint', 'Taste anklicken, um die Belegung zu bearbeiten.')));
            return panel;
        }

        var entry = effectiveEntry(selectedCode) || { b: '', s: '', g: '' };

        panel.appendChild(el('h4', '', selectedCode + (isOverride(selectedCode) ? ' — ' + txt('override', 'Angepasst') : '')));

        var fields = [
            { key: 'b', label: txt('base', 'Basis') },
            { key: 's', label: txt('shift', 'Shift') },
            { key: 'g', label: txt('altgr', 'AltGr') }
        ];

        var inputs = {};
        var grid = el('div', 'examkeymap-adm-fields');
        fields.forEach(function (f) {
            var wrap = el('label', 'examkeymap-adm-field');
            wrap.appendChild(el('span', '', f.label));
            var input = el('input');
            input.type = 'text';
            input.maxLength = 4;
            input.value = entry[f.key] || '';
            inputs[f.key] = input;
            wrap.appendChild(input);
            grid.appendChild(wrap);
        });
        panel.appendChild(grid);
        panel.appendChild(el('p', 'examkeymap-adm-hint', txt('empty_hint', 'Leeres Feld = Ebene nicht belegt (Taste wird durchgereicht).')));

        var apply = el('button', 'btn btn-default', txt('apply', 'Übernehmen'));
        apply.type = 'button';
        apply.addEventListener('click', function () {
            var def = {
                b: inputs.b.value,
                s: inputs.s.value,
                g: inputs.g.value
            };
            var builtin = builtinEntry(selectedCode) || { b: '', s: '', g: '' };
            if (def.b === (builtin.b || '') && def.s === (builtin.s || '') && def.g === (builtin.g || '')) {
                // identisch zur eingebauten Belegung → Override entfernen
                delete overrides[layout][selectedCode];
            } else {
                overrides[layout][selectedCode] = def;
            }
            dirty = true;
            render();
        });
        panel.appendChild(apply);

        if (isOverride(selectedCode)) {
            var remove = el('button', 'btn btn-default examkeymap-adm-remove', txt('remove', 'Auf Standard zurücksetzen'));
            remove.type = 'button';
            remove.addEventListener('click', function () {
                delete overrides[layout][selectedCode];
                dirty = true;
                render();
            });
            panel.appendChild(remove);
        }

        return panel;
    }

    // ─── Init ────────────────────────────────────────────────────────────────

    function init() {
        root = document.getElementById('examkeymap-admin-root');
        var conf = cfg();

        // Config/Container können beim ersten Tick noch fehlen → erneut versuchen
        if (!root || !conf.txt || !window.ExamKeymap || !window.ExamKeymap.tables) {
            setTimeout(init, 200);
            return;
        }

        // Gespeicherte Overrides übernehmen (Server liefert Objekte je Layout)
        ['de', 'en'].forEach(function (l) {
            var saved = (conf.overrides || {})[l] || {};
            Object.keys(saved).forEach(function (code) {
                overrides[l][code] = {
                    b: saved[code].b || '',
                    s: saved[code].s || '',
                    g: saved[code].g || ''
                };
            });
        });

        var form = document.getElementById('examkeymap-admin-form');
        if (form) {
            form.addEventListener('submit', serialize);
        }

        render();
        console.log('[ExamKeymap] Admin-Editor geladen');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
