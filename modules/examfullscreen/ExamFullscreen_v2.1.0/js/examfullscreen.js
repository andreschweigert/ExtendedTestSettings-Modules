/**
 * ExamFullscreen — Vollbild-Modus für Prüfungen
 * Part of ExtendedTestSettings Plugin
 *
 * Nutzt Browser-Fullscreen (F11 / Ctrl+Cmd+F) statt der Fullscreen-API.
 * Browser-Fullscreen überlebt Seitennavigationen — kein Flicker beim
 * Fragenwechsel. Erkennung über Fenstergröße vs. Bildschirmgröße.
 */
(function () {
    'use strict';

    function getCfg() {
        return (window.ExamFullscreen && window.ExamFullscreen.config) || {};
    }

    function getSessionKey() {
        return 'examfullscreen_' + (getCfg().ref_id || 'active');
    }

    function getMode() {
        return getCfg().mode === 'force' ? 'force' : 'warn';
    }

    /**
     * Prüft ob der Browser im Vollbild ist.
     * Zuerst Fullscreen-API (zuverlässig in Safari), dann Größenvergleich als
     * Fallback für F11 / Ctrl+Cmd+F. Auf macOS zählt die Menüleiste (~25 px)
     * zum screen.height, daher dort großzügigere Höhen-Toleranz (50 px).
     */
    function isFullscreen() {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            return true;
        }
        var isMac = /Mac/i.test(navigator.platform || navigator.userAgent || '');
        var hTolerance = isMac ? 50 : 5;
        return (
            window.innerHeight >= screen.height - hTolerance
            && window.innerWidth >= screen.width - 5
        );
    }

    /**
     * Erkennt das Betriebssystem für die richtige Tastenkombination.
     */
    function getShortcutInfo() {
        var ua = navigator.userAgent || '';
        if (/Mac/i.test(navigator.platform || ua)) {
            return {
                key: 'Ctrl + Cmd + F',
                icon: '⌃⌘F',
                os: 'mac'
            };
        }
        // Linux und Windows: F11
        return {
            key: 'F11',
            icon: 'F11',
            os: 'other'
        };
    }

    // ─── Start-Overlay ───────────────────────────────────────────────────────

    var startOverlay = null;

    function showStartOverlay() {
        var info = getShortcutInfo();

        startOverlay = document.createElement('div');
        startOverlay.id = 'examfullscreen-start';
        startOverlay.innerHTML = '<div id="examfullscreen-box">'
            + '<div id="examfullscreen-icon">&#x26F6;</div>'
            + '<h2 id="examfullscreen-title">Diese Prüfung muss im Vollbild-Modus durchgeführt werden.</h2>'
            + '<p id="examfullscreen-msg">Drücken Sie <kbd id="examfullscreen-kbd">' + info.key + '</kbd> um den Vollbild-Modus zu aktivieren.</p>'
            + '</div>';
        document.body.appendChild(startOverlay);
    }

    function hideStartOverlay() {
        if (startOverlay && startOverlay.parentNode) {
            startOverlay.parentNode.removeChild(startOverlay);
            startOverlay = null;
        }
    }

    // ─── Warn/Force-Overlay (Vollbild verlassen) ─────────────────────────────

    var overlay = null;

    function showOverlay() {
        if (overlay) return;

        var info = getShortcutInfo();
        var isForce = (getMode() === 'force');

        var dismissBtn = isForce
            ? ''
            : '<button id="examfullscreen-dismiss" type="button">Trotzdem fortfahren</button>';

        overlay = document.createElement('div');
        overlay.id = 'examfullscreen-overlay';
        overlay.innerHTML = '<div id="examfullscreen-box">'
            + '<div id="examfullscreen-icon">&#x26F6;</div>'
            + '<h2 id="examfullscreen-title">⚠ Diese Prüfung muss im Vollbild-Modus durchgeführt werden.</h2>'
            + '<p id="examfullscreen-msg">Drücken Sie <kbd id="examfullscreen-kbd">' + info.key + '</kbd> um den Vollbild-Modus wiederherzustellen.</p>'
            + dismissBtn
            + '</div>';
        document.body.appendChild(overlay);

        if (!isForce) {
            document.getElementById('examfullscreen-dismiss').addEventListener('click', function () {
                hideOverlay();
            });
        }
    }

    function hideOverlay() {
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
            overlay = null;
        }
    }

    // ─── Vollbild-Überwachung via resize ─────────────────────────────────────
    // Browser-Fullscreen (F11) ändert die Fenstergröße. Wir überwachen das
    // resize-Event um Ein-/Austritt zu erkennen. Debounce mit 300ms damit
    // Zwischenzustände beim Resize ignoriert werden.

    var resizeTimer = null;

    function onResize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (isFullscreen()) {
                // Fullscreen (wieder) aktiv
                if (!sessionStorage.getItem(getSessionKey())) {
                    sessionStorage.setItem(getSessionKey(), '1');
                }
                hideStartOverlay();
                hideOverlay();
            } else if (sessionStorage.getItem(getSessionKey())) {
                // War im Fullscreen, jetzt nicht mehr → Warnung
                showOverlay();
            }
        }, 300);
    }

    window.addEventListener('resize', onResize);

    // ─── Start ────────────────────────────────────────────────────────────────

    function init() {
        if (isFullscreen()) {
            // Bereits im Fullscreen (Fragenwechsel oder F11 schon aktiv)
            sessionStorage.setItem(getSessionKey(), '1');
        } else if (sessionStorage.getItem(getSessionKey()) === '1') {
            // Fullscreen war aktiv, jetzt nicht mehr.
            // Könnte Fragenwechsel sein (F11 bleibt normalerweise) oder
            // der Nutzer hat ESC gedrückt. Warn-Overlay zeigen.
            showOverlay();
        } else {
            // Noch nie im Fullscreen gewesen → Start-Overlay zeigen.
            // Session-Key wird erst gesetzt wenn F11 tatsächlich aktiviert wird.
            showStartOverlay();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    console.log('[ExamFullscreen] Aktiviert, Modus: ' + getMode());
})();
