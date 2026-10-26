/**
 * examdyslexia.js - Legasthenie-Modus für ILIAS Testplayer
 * Liest window.ExamDyslexia.config (gesetzt via addOnLoadCode, NICHT im IIFE cachen)
 */

(function () {
    'use strict';

    // Auf Closure-Ebene, damit activate/deactivate/initReadingRuler darauf zugreifen können
    var _config = null;

    // -------------------------------------------------------------------------
    // Hilfsfunktionen
    // -------------------------------------------------------------------------

    function buildStorageKey() {
        var urlParams = new URLSearchParams(window.location.search);
        var activeId = urlParams.get('active_id') || 'unknown';
        return 'examdyslexia_active_' + activeId;
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

    function applyCssCustomProperties(config) {
        var root = document.documentElement;
        root.style.setProperty('--examdyslexia-line-height', config.line_height || '1.8');
        root.style.setProperty('--examdyslexia-letter-spacing', config.letter_spacing || '0.05em');
        root.style.setProperty('--examdyslexia-bg', config.bg_color || 'transparent');
    }

    // -------------------------------------------------------------------------
    // Leseschiene (Reading Ruler)
    // -------------------------------------------------------------------------

    function initReadingRuler() {
        if (document.getElementById('examdyslexia-ruler')) return;

        var ruler = document.createElement('div');
        ruler.id = 'examdyslexia-ruler';
        ruler.setAttribute('aria-hidden', 'true');

        // Höhe: etwas mehr als eine Zeile, basierend auf konfiguriertem line-height
        var lh = parseFloat((_config && _config.line_height) || '1.8');
        ruler.style.height = (lh * 1.15).toFixed(2) + 'em';

        document.body.appendChild(ruler);

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
            var h = ruler.offsetHeight || Math.round(lh * 1.15 * 16);
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

    function activate() {
        document.body.classList.add('examdyslexia-active');

        // Leseschiene beim ersten Aktivieren einrichten
        if (_config) {
            initReadingRuler();
        }

        var btn = document.getElementById('examdyslexia-toggle');
        if (btn) {
            btn.classList.add('examdyslexia-on');
            btn.setAttribute('aria-pressed', 'true');
            btn.querySelector('.examdyslexia-label').textContent = 'Lesemodus: EIN';
        }
    }

    function deactivate() {
        document.body.classList.remove('examdyslexia-active');

        // Leseschiene ausblenden (Element bleibt im DOM für schnelles Re-Aktivieren)
        var ruler = document.getElementById('examdyslexia-ruler');
        if (ruler) {
            ruler.style.display = 'none';
        }

        var btn = document.getElementById('examdyslexia-toggle');
        if (btn) {
            btn.classList.remove('examdyslexia-on');
            btn.setAttribute('aria-pressed', 'false');
            btn.querySelector('.examdyslexia-label').textContent = 'Lesemodus: AUS';
        }
    }

    // -------------------------------------------------------------------------
    // Toggle-Button
    // -------------------------------------------------------------------------

    function renderToggleButton(isActive, storageKey) {
        if (document.getElementById('examdyslexia-toggle')) return;

        var btn = document.createElement('button');
        btn.id = 'examdyslexia-toggle';
        btn.type = 'button';
        btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        btn.setAttribute('aria-label', 'Legasthenie-Lesemodus umschalten');
        btn.title = 'Legasthenie-freundlichen Lesemodus ein-/ausschalten';

        var icon = document.createElement('span');
        icon.className = 'examdyslexia-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '👁';

        var label = document.createElement('span');
        label.className = 'examdyslexia-label';
        label.textContent = isActive ? 'Lesemodus: EIN' : 'Lesemodus: AUS';

        btn.appendChild(icon);
        btn.appendChild(label);

        if (isActive) {
            btn.classList.add('examdyslexia-on');
        }

        btn.addEventListener('click', function () {
            var nowActive = document.body.classList.contains('examdyslexia-active');
            if (nowActive) {
                deactivate();
                sessionStorage.setItem(storageKey, 'false');
            } else {
                activate();
                sessionStorage.setItem(storageKey, 'true');
            }
        });

        document.body.appendChild(btn);
    }

    // -------------------------------------------------------------------------
    // Initialisierung
    // -------------------------------------------------------------------------

    function init() {
        var config = window.ExamDyslexia && window.ExamDyslexia.config;
        if (!config || !config.enabled) {
            return;
        }

        _config = config;

        applyCssCustomProperties(config);

        if (config.use_font && config.font_path) {
            injectFontFaceFallback(config.font_path);
        }

        if (config.allow_toggle) {
            var storageKey = buildStorageKey();
            var stored = sessionStorage.getItem(storageKey);
            var isActive = stored === 'true';

            if (isActive) {
                activate();
            }

            renderToggleButton(isActive, storageKey);
        } else {
            activate();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.addEventListener('load', function () {
        var config = window.ExamDyslexia && window.ExamDyslexia.config;
        if (config && config.enabled && !document.getElementById('examdyslexia-toggle') && config.allow_toggle) {
            init();
        }
    });

})();
