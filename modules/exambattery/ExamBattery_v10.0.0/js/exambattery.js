/**
 * ExamBattery — stiller Akku-Monitor für Prüfungsgeräte
 * Part of ExtendedTestSettings Plugin
 *
 * Strategie:
 *   - KEINERLEI sichtbare Änderung im Testplayer (kein DOM-Element, kein Banner).
 *   - Battery Status API (navigator.getBattery): nur Chromium + Secure Context.
 *     Fehlt die API (Firefox, HTTP-Dev-Instanz): lautlos nichts tun.
 *   - Desktop-Geräte ohne Akku (level=100 UND lädt): keine Reports.
 *   - Gemeldet wird NUR wenn nötig: Akkustand gefallen, Ladezustand gewechselt
 *     oder Heartbeat fällig (5 min seit letztem Report). Der letzte Report-Stand
 *     liegt im sessionStorage und überlebt so die Seitenneuladungen bei jeder
 *     Fragennavigation — Fragenwechsel erzeugen dadurch KEINE eigenen Reports.
 *   - Fehlgeschlagene Reports still verwerfen — der nächste Heartbeat kommt ohnehin.
 *   - Entwickler-Helfer: window.ExamBattery.simulate(levelPct, charging, restMin).
 */
(function () {
    'use strict';

    // Live-Read der Config — addJavaScript läuft vor addOnLoadCode,
    // daher niemals beim IIFE-Init cachen.
    function cfg() {
        var c = (window.ExamBattery && window.ExamBattery.config) || {};
        return {
            refId:            c.refId            || 0,
            reportUrl:        c.reportUrl        || '',
            heartbeatSeconds: c.heartbeatSeconds || 300,
            debug:            !!c.debug,
        };
    }

    function log() {
        if (!cfg().debug) { return; }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[ExamBattery]');
        console.log.apply(console, args);
    }

    var batteryRef = null;   // BatteryManager, sobald verfügbar

    // ─── Letzter Report-Stand (überlebt Fragennavigation via sessionStorage) ──────

    var _refFromUrl = (function () {
        var m = window.location.search.match(/[?&]ref_id=(\d+)/);
        return m ? m[1] : '0';
    })();
    var STORAGE_KEY = 'exambattery_last_' + _refFromUrl;

    var memLast = null;   // Fallback, falls sessionStorage blockiert ist

    function loadLast() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : memLast;
        } catch (e) {
            return memLast;
        }
    }

    function saveLast(state) {
        memLast = state;
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) { /* Privacy-Modus o.ä. — in-memory reicht pro Seite */ }
    }

    // ─── Report ──────────────────────────────────────────────────────────────────

    function sendReport(levelPct, charging, remainingMin) {
        var c = cfg();
        if (!c.reportUrl) { return; }

        window.fetch(c.reportUrl, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({
                level:     levelPct,
                charging:  charging ? 1 : 0,
                remaining: typeof remainingMin === 'number' ? remainingMin : -1,
            }),
            credentials: 'same-origin',
        }).then(function () {
            log('reported level=' + levelPct + '% charging=' + (charging ? 1 : 0) + ' remaining=' + remainingMin + 'min');
        }).catch(function () {
            // Still verwerfen — kein Retry-Sturm, nächster Heartbeat kommt ohnehin.
        });
    }

    // Geschätzte Restzeit in Minuten aus der Battery-API; -1 = unbekannt.
    // dischargingTime ist beim Laden bzw. kurz nach Statuswechseln Infinity.
    function remainingMinutes() {
        if (!batteryRef || batteryRef.charging) { return -1; }
        var t = batteryRef.dischargingTime;
        if (typeof t !== 'number' || !isFinite(t) || t <= 0) { return -1; }
        return Math.min(6000, Math.round(t / 60));
    }

    function reportFromBattery() {
        if (!batteryRef) { return; }
        var level    = Math.round(batteryRef.level * 100);
        var charging = !!batteryRef.charging;

        // Desktop ohne Akku: level=100 und lädt → keinerlei Aktivität.
        // (Wird pro Report geprüft: ein voll geladenes Notebook meldet wieder,
        // sobald es vom Netz getrennt wird und levelchange feuert.)
        if (level === 100 && charging) {
            return;
        }

        // Nur melden wenn nötig: erster Report, Akkustand gefallen,
        // Ladezustand gewechselt oder Heartbeat fällig.
        var last = loadLast();
        var due = !last
            || level < last.level
            || charging !== last.charging
            || (Date.now() - last.ts) >= cfg().heartbeatSeconds * 1000;
        if (!due) {
            return;
        }

        sendReport(level, charging, remainingMinutes());
        // Optimistisch merken — schlägt der Report fehl, holt ihn der
        // nächste fällige Heartbeat nach (bewusst kein Retry).
        saveLast({ ts: Date.now(), level: level, charging: charging });
    }

    // ─── Check-Timer ─────────────────────────────────────────────────────────────

    // Prüft jede Minute, ob der Heartbeat fällig ist (der Fälligkeits-Zeitpunkt
    // liegt im sessionStorage und überlebt so die Fragennavigation).
    // Timer immer aufsetzen, auch wenn die Config beim ersten Tick noch leer ist —
    // cfg() wird bei jedem Tick live gelesen.
    var CHECK_INTERVAL_MS = 60000;

    function scheduleCheck() {
        setTimeout(function () {
            reportFromBattery();
            scheduleCheck();
        }, CHECK_INTERVAL_MS);
    }

    // ─── Public API (Entwickler-Helfer) ──────────────────────────────────────────

    window.ExamBattery        = window.ExamBattery || {};
    window.ExamBattery.config = window.ExamBattery.config || {};

    // Simulierter Report für Tests ohne echtes Akku-Gerät (umgeht Desktop-Erkennung
    // und Drosselung). Drittes Argument optional: Restzeit in Minuten (Default -1).
    window.ExamBattery.simulate = function (levelPct, charging, remainingMin) {
        var level     = Math.max(0, Math.min(100, Math.round(Number(levelPct) || 0)));
        var remaining = Math.max(-1, Math.min(6000, Math.round(Number(remainingMin))));
        if (isNaN(remaining)) { remaining = -1; }
        sendReport(level, !!charging, remaining);
        console.log('[ExamBattery] simulate: level=' + level + '% charging=' + (charging ? 1 : 0) + ' remaining=' + remaining + 'min');
    };

    // ─── Init ────────────────────────────────────────────────────────────────────

    scheduleCheck();

    if (typeof navigator.getBattery !== 'function') {
        // Battery-API fehlt (Firefox, kein Secure Context): lautlos nichts tun.
        // Check-Ticks laufen leer (batteryRef bleibt null) — keine Requests.
        return;
    }

    navigator.getBattery().then(function (battery) {
        batteryRef = battery;
        battery.addEventListener('levelchange', reportFromBattery);
        battery.addEventListener('chargingchange', reportFromBattery);

        // Initial-Check leicht verzögert — die Config wird erst per addOnLoadCode
        // gesetzt. Meldet nur, wenn laut sessionStorage tatsächlich fällig.
        setTimeout(reportFromBattery, 3000);
        log('started — throttled reporting (fall/charge-change/heartbeat), state via sessionStorage');
    }).catch(function () {
        // Lautlos — kein Fehler, kein Log-Spam.
    });
})();
