/**
 * ExamNetQos — clientseitige Netzwerk-Qualitätsmessung
 * Part of ExtendedTestSettings Plugin
 *
 * Strategie:
 *   - Hintergrund-Mess-Timer (konfigurierbares Intervall, Default 10 s).
 *   - RTT: HEAD-Request auf probe.txt, Zeit von Start bis Response.
 *   - Paketverlust (optional): N parallele HEAD-Requests, Anteil fehlgeschlagener.
 *   - navigator.connection: opportunistisch ohne Extra-Request ausgelesen.
 *   - Schwellwert-Überschreitungen → automatisch als Ereignis gespeichert.
 *   - Batch-Reporting: alle 5 Messungen oder alle 30 s → POST an PHP-Endpoint.
 *   - sendBeacon() beim beforeunload für den letzten ausstehenden Batch.
 */
(function () {
    'use strict';

    // Live-Read der Config — addJavaScript läuft vor addOnLoadCode,
    // daher niemals beim IIFE-Init cachen.
    function cfg() {
        var c = (window.ExamNetQos && window.ExamNetQos.config) || {};
        return {
            refId:           c.refId           || 0,
            probeUrl:        c.probeUrl         || '',
            reportUrl:       c.reportUrl        || '',
            intervalSeconds: c.intervalSeconds  || 10,
            rttWarnMs:       c.rttWarnMs        || 500,
            rttCritMs:       c.rttCritMs        || 2000,
            lossEnabled:     c.lossEnabled      !== false,
            lossProbes:      c.lossProbes       || 3,
            debug:           !!c.debug,
        };
    }

    function log() {
        if (!cfg().debug) { return; }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[ExamNetQos]');
        console.log.apply(console, args);
    }

    // ─── Session-Key (stabil pro Browser-Tab + Test-Ref) ────────────────────────
    // sessionStorage überlebt Seiten-Navigationen innerhalb desselben Tabs
    // (ILIAS-Test lädt bei jeder Frage neu), wird aber bei neuem Tab/Fenster
    // oder Browser-Neustart geleert → eindeutig pro Prüfungs-Sitzung.

    var _refFromUrl = (function () {
        var m = window.location.search.match(/[?&]ref_id=(\d+)/);
        return m ? m[1] : '0';
    })();
    var STORAGE_KEY = 'examnetqos_sk_' + _refFromUrl;

    var sessionKey = (function () {
        try {
            var stored = sessionStorage.getItem(STORAGE_KEY);
            if (stored) { return stored; }
            var key = (
                Math.random().toString(36).substr(2) +
                Math.random().toString(36).substr(2) +
                Date.now().toString(36)
            ).substr(0, 16);
            sessionStorage.setItem(STORAGE_KEY, key);
            return key;
        } catch (e) {
            // sessionStorage nicht verfügbar (z.B. Privacy-Modus mit blockiertem Storage)
            return (
                Math.random().toString(36).substr(2) +
                Math.random().toString(36).substr(2) +
                Date.now().toString(36)
            ).substr(0, 16);
        }
    })();

    // ─── Batch-Buffer ────────────────────────────────────────────────────────────

    var pendingEvents  = [];
    var lastFlush      = 0;
    var FLUSH_BATCH    = 5;       // flush nach N Messungen
    var FLUSH_INTERVAL = 30000;   // oder spätestens nach 30 s

    // ─── Status-Tracking ─────────────────────────────────────────────────────────

    var lastStatus = 'ok';   // 'ok' | 'degraded' | 'critical'

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    function getOrigFetch() {
        // ExamConnGuard wrapat window.fetch mit Backoff; für Probes immer
        // den originalen fetch nehmen, damit Timeout/Fehler sofort zurückkommen.
        return (window.ExamConnGuard && window.ExamConnGuard._origFetch)
            ? window.ExamConnGuard._origFetch
            : window.fetch;
    }

    function getConnType() {
        var nc = navigator.connection
              || navigator.mozConnection
              || navigator.webkitConnection;
        return (nc && nc.effectiveType) ? String(nc.effectiveType) : '';
    }

    function nowSec() {
        return Math.floor(Date.now() / 1000);
    }

    // ─── Einzel-Probe ────────────────────────────────────────────────────────────

    function probeSingle(url, timeoutMs) {
        return new Promise(function (resolve, reject) {
            var ctrl    = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var timerId = setTimeout(function () {
                if (ctrl) { ctrl.abort(); }
                reject(new Error('timeout'));
            }, timeoutMs);

            getOrigFetch()(url, {
                method:      'HEAD',
                cache:       'no-store',
                credentials: 'same-origin',
                signal:      ctrl ? ctrl.signal : undefined,
            }).then(function (res) {
                clearTimeout(timerId);
                if (res.ok) {
                    resolve(Date.now());
                } else {
                    reject(new Error('http ' + res.status));
                }
            }).catch(function (err) {
                clearTimeout(timerId);
                reject(err);
            });
        });
    }

    // ─── Messung (RTT + optional Paketverlust) ───────────────────────────────────

    function measure() {
        var c = cfg();
        if (!c.probeUrl) {
            return;
        }

        var n        = c.lossEnabled ? Math.max(1, c.lossProbes) : 1;
        var timeout  = Math.max(c.rttCritMs * 2, 5000);
        var baseUrl  = c.probeUrl + '?_nqos=' + Date.now();
        var promises = [];

        for (var i = 0; i < n; i++) {
            (function (idx) {
                var t0  = Date.now();
                var url = baseUrl + '&_p=' + idx;
                promises.push(
                    probeSingle(url, timeout)
                        .then(function () { return Date.now() - t0; })
                        .catch(function () { return -1; })
                );
            })(i);
        }

        Promise.all(promises).then(function (results) {
            var successful = results.filter(function (r) { return r >= 0; });
            var rtt = successful.length > 0
                ? Math.round(successful.reduce(function (s, r) { return s + r; }, 0) / successful.length)
                : 0;  // 0 = alle Probes gefailt (Timeout)
            var loss_pct = n > 1
                ? Math.round((n - successful.length) / n * 100)
                : -1;  // -1 = Paketverlust-Messung deaktiviert (single probe)

            onMeasured(rtt, loss_pct, c);
        });
    }

    // ─── Auswertung & Ereignis-Erkennung ─────────────────────────────────────────

    function onMeasured(rtt, loss_pct, c) {
        var newStatus;
        if (rtt === 0) {
            newStatus = 'critical';       // Timeout = komplett offline
        } else if (rtt >= c.rttCritMs) {
            newStatus = 'critical';
        } else if (rtt >= c.rttWarnMs) {
            newStatus = 'degraded';
        } else {
            newStatus = 'ok';
        }

        pushEvent('measurement', rtt, loss_pct);
        log('measured rtt=' + rtt + 'ms loss=' + loss_pct + '% status=' + newStatus);

        if (newStatus !== lastStatus) {
            if (newStatus === 'ok') {
                pushEvent('recovered', rtt, loss_pct);
                log('recovered');
            } else if (newStatus === 'degraded') {
                pushEvent('degraded', rtt, loss_pct);
                log('degraded');
            } else {
                pushEvent('critical', rtt, loss_pct);
                log('critical');
            }
            lastStatus = newStatus;
        }

        var shouldFlush = pendingEvents.length >= FLUSH_BATCH
            || (Date.now() - lastFlush) >= FLUSH_INTERVAL;
        if (shouldFlush) {
            flush();
        }
    }

    // ─── Event-Buffer ────────────────────────────────────────────────────────────

    function pushEvent(type, rtt, loss_pct) {
        pendingEvents.push({
            event_type: type,
            rtt_ms:     rtt     !== undefined ? rtt     : 0,
            loss_pct:   loss_pct !== undefined ? loss_pct : -1,
            conn_type:  getConnType(),
            ts:         nowSec(),
        });
    }

    function flush() {
        if (pendingEvents.length === 0) { return; }
        var c = cfg();
        if (!c.reportUrl) { return; }

        var events     = pendingEvents.splice(0, 20);  // max 20 pro Batch
        lastFlush      = Date.now();

        // Wrapped fetch ist für Report OK — Backoff bei kurzem Netzausfall ist erwünscht.
        window.fetch(c.reportUrl, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ sessionKey: sessionKey, events: events }),
            credentials: 'same-origin',
        }).catch(function () {
            // Ereignisse wieder vorne einreihen
            pendingEvents = events.concat(pendingEvents);
            log('flush failed, ' + events.length + ' events re-queued');
        });
    }

    // ─── Init ────────────────────────────────────────────────────────────────────

    function startMeasuring() {
        // Kein Config-Check hier: addJavaScript läuft vor addOnLoadCode (il.Util.addOnLoad /
        // jQuery-ready), die Config ist beim IIFE-Init noch leer. measure() liest cfg() live
        // und kehrt sofort zurück solange probeUrl fehlt — nach 3 s ist die Config gesetzt.

        // Erste Messung nach 3 s, danach selbst-reschedulend damit das Intervall live aus
        // cfg() gelesen wird statt einmalig beim Init gecacht.
        function scheduleNext() {
            setTimeout(function () {
                measure();
                scheduleNext();
            }, (cfg().intervalSeconds || 10) * 1000);
        }
        setTimeout(function () {
            measure();
            scheduleNext();
        }, 3000);

        document.addEventListener('examconnguard:reconnect', function () {
            pushEvent('reconnect', 0, -1);
            flush();
            log('received reconnect signal from ExamConnGuard');
        });

        log('started — first measure in 3 s, interval live from cfg()');
    }

    // Letzten Batch beim Verlassen senden (sendBeacon für Zuverlässigkeit)
    window.addEventListener('beforeunload', function () {
        if (pendingEvents.length === 0) { return; }
        var c = cfg();
        if (!c.reportUrl) { return; }
        var payload = JSON.stringify({ sessionKey: sessionKey, events: pendingEvents });
        if (navigator.sendBeacon) {
            navigator.sendBeacon(c.reportUrl, new Blob([payload], { type: 'application/json' }));
        }
        pendingEvents = [];
    });

    window.ExamNetQos          = window.ExamNetQos || {};
    window.ExamNetQos.config   = window.ExamNetQos.config || {};

    startMeasuring();
    console.log('[ExamNetQos] Network QoS monitoring started (session=' + sessionKey + ')');
})();
