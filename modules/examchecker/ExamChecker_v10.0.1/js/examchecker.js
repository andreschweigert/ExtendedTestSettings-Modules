/**
 * ExamChecker - Prüfung der Test-Einstellungen anstoßen und Ergebnis anzeigen
 * Part of ExtendedTestSettings Plugin
 */
(function () {
    'use strict';

    /**
     * Config IMMER live lesen: addJavaScript lädt diese Datei vor addOnLoadCode,
     * beim Init ist window.ExamChecker.config deshalb noch leer.
     */
    function cfg() {
        return (window.ExamChecker && window.ExamChecker.config) || {};
    }

    function txt(key, fallback) {
        var i18n = cfg().i18n || {};
        return i18n[key] || fallback;
    }

    function init() {
        var button = document.getElementById('examchecker_start');
        if (!button) {
            return;
        }

        var select  = document.getElementById('examchecker_type');
        var status  = document.getElementById('examchecker_status');
        var results = document.getElementById('examchecker_results');

        button.addEventListener('click', function () {
            var config = cfg();

            if (!config.checkUrl || !config.token) {
                console.error('[ExamChecker] Konfiguration fehlt');
                return;
            }

            var originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = txt('runningBtn', 'Wird geprüft...');

            results.innerHTML = '';
            status.textContent = txt('running', 'Einstellungen werden geprüft...');
            status.hidden = false;

            var body = new URLSearchParams();
            body.append('examchecker_token', config.token);
            body.append('exam_type', select ? select.value : 'praesenz');

            fetch(config.checkUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        if (!response.ok && text.charAt(0) !== '{') {
                            throw new Error('HTTP ' + response.status);
                        }
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            throw new Error('Ungültige Antwort des Servers');
                        }
                    });
                })
                .then(function (data) {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    results.innerHTML = data.html || '';
                })
                .catch(function (error) {
                    console.error('[ExamChecker] ' + error.message);
                    var box = document.createElement('div');
                    box.className = 'ec-box ec-box-error';
                    box.textContent = txt('error', 'Fehler:') + ' ' + error.message;
                    results.innerHTML = '';
                    results.appendChild(box);
                })
                .then(function () {
                    status.hidden = true;
                    button.disabled = false;
                    button.textContent = originalLabel;
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
