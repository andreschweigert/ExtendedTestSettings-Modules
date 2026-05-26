/**
 * exammessage.js - Administratornachricht im Test-Player
 */

(function () {
    'use strict';

    function init() {
        const cfg = window.ExamMessage && window.ExamMessage.config;
        if (!cfg || !cfg.message || cfg.message.trim() === '') {
            return;
        }

        if (document.getElementById('exam-message-banner')) {
            return;
        }

        const banner = document.createElement('div');
        banner.id = 'exam-message-banner';
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');

        banner.innerHTML =
            '<span id="exam-message-icon" aria-hidden="true">ℹ️</span>' +
            '<div id="exam-message-body">' +
            '<div id="exam-message-label">Nachricht der Prüfungsaufsicht</div>' +
            '<div id="exam-message-text"></div>' +
            '</div>' +
            '<button id="exam-message-close" aria-label="Nachricht schließen" type="button">×</button>';

        // Nachrichtentext sicher einfügen (kein direktes innerHTML der Nutzereingabe)
        banner.querySelector('#exam-message-text').innerHTML = sanitize(cfg.message);

        document.body.insertBefore(banner, document.body.firstChild);

        banner.querySelector('#exam-message-close').addEventListener('click', function () {
            banner.classList.add('exam-message-hiding');
            banner.addEventListener('animationend', function () {
                banner.remove();
            }, { once: true });
        });
    }

    /**
     * Lässt nur die vom Backend bereits gefilterten Tags durch.
     * Doppelte Absicherung auf Client-Seite: nur <strong>, <b>, <i>, <u>, <br> erlaubt.
     */
    function sanitize(html) {
        const tmp = document.createElement('div');
        tmp.textContent = html;
        let escaped = tmp.innerHTML;

        const allowed = ['strong', 'b', 'i', 'u', 'br'];
        allowed.forEach(function (tag) {
            const open  = new RegExp('&lt;' + tag + '&gt;', 'gi');
            const close = new RegExp('&lt;/' + tag + '&gt;', 'gi');
            const self  = new RegExp('&lt;' + tag + '\\s*/&gt;', 'gi');
            escaped = escaped.replace(open,  '<' + tag + '>');
            escaped = escaped.replace(close, '</' + tag + '>');
            escaped = escaped.replace(self,  '<' + tag + ' />');
        });

        return escaped;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
