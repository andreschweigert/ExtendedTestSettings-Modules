(function () {
    'use strict';

    var lastSentAt   = 0;
    var cntInterval  = null;
    var startTries   = 0;
    var refId        = 0;
    var userId       = 0;

    // Persistenz des "Weggeklickt"-Status pro Test + Konto (überlebt Reload,
    // trennt Nutzer auf geteilten Laptops). Rein clientseitig, keine Server-Last.
    function dismissKey() {
        return 'exammsg_dismissed_' + refId + '_' + userId;
    }
    function getDismissed() {
        try { return parseInt(localStorage.getItem(dismissKey()), 10) || 0; }
        catch (e) { return 0; } // privater Modus o.ä. -> wie bisher (nur In-Memory)
    }
    function setDismissed(sentAt) {
        try { localStorage.setItem(dismissKey(), String(sentAt)); }
        catch (e) {}
    }

    function tryStart() {
        var cfg = window.ExamMessage && window.ExamMessage.config;
        if (!cfg || !cfg.poll_url) {
            // addOnLoadCode hasn't run yet — retry until it does
            if (++startTries < 40) {
                setTimeout(tryStart, 50);
            }
            return;
        }

        refId  = cfg.ref_id  || 0;
        userId = cfg.user_id || 0;

        var now = Math.floor(Date.now() / 1000);

        if (cfg.sent_at > 0 && cfg.expiry > now && cfg.message && cfg.sent_at > getDismissed()) {
            lastSentAt = cfg.sent_at;
            showBanner(cfg.message, cfg.type || 0, cfg.expiry, cfg.sent_at);
        }

        setInterval(poll, 15000);
    }

    function poll() {
        var cfg = window.ExamMessage && window.ExamMessage.config;
        if (!cfg || !cfg.poll_url) {
            return;
        }

        fetch(cfg.poll_url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.sent_at) {
                    return;
                }
                var now = Math.floor(Date.now() / 1000);
                if (data.sent_at > lastSentAt && data.expiry > now && data.message && data.sent_at > getDismissed()) {
                    lastSentAt = data.sent_at;
                    showBanner(data.message, data.type || 0, data.expiry, data.sent_at);
                }
            })
            .catch(function () {});
    }

    function showBanner(message, type, expiry, sentAt) {
        var existing = document.getElementById('exam-message-banner');
        if (existing) {
            existing.remove();
        }
        if (cntInterval) {
            clearInterval(cntInterval);
            cntInterval = null;
        }

        var banner = document.createElement('div');
        banner.id = 'exam-message-banner';
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');

        if (type === 1) {
            banner.classList.add('type-warning');
        } else if (type === 2) {
            banner.classList.add('type-danger');
        }

        var icon = type === 1 ? '⚠️' : (type === 2 ? '🚨' : 'ℹ️');

        banner.innerHTML =
            '<span id="exam-message-icon" aria-hidden="true">' + icon + '</span>' +
            '<div id="exam-message-body">' +
            '<div id="exam-message-label">Nachricht der Prüfungsaufsicht</div>' +
            '<div id="exam-message-text"></div>' +
            '</div>' +
            '<div id="exam-message-countdown-wrap">' +
            '<span id="exam-message-countdown"></span>' +
            '</div>';

        banner.querySelector('#exam-message-text').innerHTML = sanitize(message);
        document.body.insertBefore(banner, document.body.firstChild);

        var countdownEl = banner.querySelector('#exam-message-countdown');

        function tick() {
            var left = expiry - Math.floor(Date.now() / 1000);
            if (left <= 0) {
                clearInterval(cntInterval);
                cntInterval = null;
                dismiss(banner);
                return;
            }
            if (left >= 60) {
                var m = Math.floor(left / 60);
                var s = left % 60;
                countdownEl.textContent = m + ':' + (s < 10 ? '0' : '') + s + ' min';
            } else {
                countdownEl.textContent = left + 's';
            }
        }

        tick();
        cntInterval = setInterval(tick, 1000);

        banner.addEventListener('click', function () {
            setDismissed(sentAt); // bewusstes Wegklicken merken (nicht der Countdown-Ablauf)
            if (cntInterval) {
                clearInterval(cntInterval);
                cntInterval = null;
            }
            dismiss(banner);
        });
    }

    function dismiss(banner) {
        banner.classList.add('exam-message-hiding');
        banner.addEventListener('animationend', function () {
            banner.remove();
        }, { once: true });
    }

    function sanitize(html) {
        var tmp = document.createElement('div');
        tmp.textContent = html;
        var out = tmp.innerHTML;
        ['strong', 'b', 'i', 'u', 'br'].forEach(function (tag) {
            out = out.replace(new RegExp('&lt;' + tag + '&gt;', 'gi'), '<' + tag + '>');
            out = out.replace(new RegExp('&lt;/' + tag + '&gt;', 'gi'), '</' + tag + '>');
            out = out.replace(new RegExp('&lt;' + tag + '\\s*/&gt;', 'gi'), '<' + tag + ' />');
        });
        return out;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryStart);
    } else {
        tryStart();
    }
})();
