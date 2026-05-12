/**
 * ExamConnGuard — Verbindungs-Schutz im Test-Player
 * Part of ExtendedTestSettings Plugin
 *
 * Strategie (On-Demand-Probe):
 *   - Im Normalbetrieb läuft KEIN Hintergrund-Heartbeat (kein Dauer-Traffic).
 *   - Submit/Klick-Events werden im Capture immer abgefangen (preventDefault),
 *     dann mit einer schnellen Probe (HEAD auf eine bekannte statische URL aus
 *     dem Plugin-Modul) abgesichert.
 *       * Probe ok       → Action wird sofort durchgelassen (replay).
 *       * Probe failt    → Outage-Modus: Overlay rauf, Action gequeued.
 *   - Im Outage-Modus läuft ein kurzer Reconnect-Heartbeat (alle 2 s), um den
 *     Reconnect zu erkennen. Sobald ok → Overlay weg, queued Action ausführen.
 *   - fetch() global gewrappt mit Exponential-Backoff (max 6 Versuche) für
 *     ILIAS-Autosaves. Wrapper triggert NICHT eigenständig Offline-State.
 *   - beforeunload schützt vor F5/Strg+R während Outage.
 */
(function () {
    'use strict';

    // Live-Read der Config bei JEDEM Aufruf — addOnLoadCode (das die Config setzt)
    // läuft NACH addJavaScript (das diese Datei lädt). Würden wir die Config
    // einmal beim Init cachen, hätten wir Default-Werte (insbesondere leere
    // heartbeatUrl), und die Probe würde via Early-Return optimistisch true
    // zurückgeben → spurious exit-outage nach jedem Reconnect-Heartbeat.
    function cfg() {
        var c = (window.ExamConnGuard && window.ExamConnGuard.config) || {};
        return {
            refId:               c.refId || 0,
            heartbeatUrl:        c.heartbeatUrl || '',
            preflightTimeout:    c.preflightTimeout || 1200,
            reconnectIntervalMs: c.reconnectIntervalMs || 2000,
            reconnectGraceMs:    c.reconnectGraceMs || 800,
            watchdogIntervalMs:  c.watchdogIntervalMs || 500,
            debug:               !!c.debug
        };
    }

    function log() {
        if (!cfg().debug) {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[ExamConnGuard]');
        console.log.apply(console, args);
    }

    // ─── State ───────────────────────────────────────────────────────────────

    var inOutage         = false;
    var outageStart      = 0;
    var reconnectTimer   = null;
    var graceTimer       = null;
    var watchdogTimer    = null;
    var pendingAction    = null;
    var overlayHost      = null;
    var overlayShadow    = null;
    var timerInterval    = null;
    var beforeUnloadOn   = false;
    var inReplay         = false;   // schützt vor submit/click-Replay-Loops

    // ─── Overlay (Shadow-DOM — entkoppelt von ILIAS-CSS) ─────────────────────

    var OVERLAY_CSS = ''
        + ':host{all:initial;}'
        + '.wrap{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;'
        + 'justify-content:center;background:rgba(20,20,30,0.78);backdrop-filter:blur(10px);'
        + '-webkit-backdrop-filter:blur(10px);font-family:-apple-system,BlinkMacSystemFont,'
        + '"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#222;}'
        + '.box{background:#fff;border-radius:10px;padding:36px 44px;max-width:480px;width:90%;'
        + 'text-align:center;box-shadow:0 12px 48px rgba(0,0,0,0.45);}'
        + '.icon{font-size:46px;line-height:1;margin-bottom:14px;}'
        + '.title{font-size:22px;font-weight:600;margin:0 0 10px 0;color:#1a1a1a;}'
        + '.msg{margin:6px 0;font-size:15px;line-height:1.5;color:#333;}'
        + '.timer{margin-top:18px;font-variant-numeric:tabular-nums;font-size:15px;color:#666;}'
        + '.spinner{display:inline-block;width:14px;height:14px;border:2px solid #ccc;'
        + 'border-top-color:#2980b9;border-radius:50%;animation:ecg-spin 0.9s linear infinite;'
        + 'margin-right:8px;vertical-align:-2px;}'
        + '@keyframes ecg-spin{to{transform:rotate(360deg);}}';

    function buildOverlay() {
        if (overlayHost) {
            return;
        }
        overlayHost = document.createElement('div');
        overlayHost.id = 'examconnguard-host';
        overlayHost.style.cssText = 'position:fixed;inset:0;z-index:2147483647;';

        overlayShadow = overlayHost.attachShadow ? overlayHost.attachShadow({ mode: 'closed' }) : null;
        var root = overlayShadow || overlayHost;

        var style = document.createElement('style');
        style.textContent = OVERLAY_CSS;
        root.appendChild(style);

        var wrap = document.createElement('div');
        wrap.className = 'wrap';
        wrap.innerHTML = ''
            + '<div class="box">'
            + '<div class="icon">📡</div>'
            + '<h2 class="title">Verbindung unterbrochen</h2>'
            + '<p class="msg">Bitte warten — Ihre Eingaben werden gesichert.</p>'
            + '<p class="msg">Sobald die Verbindung wiederhergestellt ist, '
            + 'geht es automatisch weiter. Bitte <strong>nicht</strong> neu laden.</p>'
            + '<div class="timer"><span class="spinner"></span>'
            + 'Unterbrochen seit <span id="ecg-elapsed">0</span> s</div>'
            + '</div>';
        root.appendChild(wrap);

        document.body.appendChild(overlayHost);
    }

    function showOverlay() {
        buildOverlay();
        outageStart = Date.now();
        updateElapsed();
        if (timerInterval) {
            clearInterval(timerInterval);
        }
        timerInterval = setInterval(updateElapsed, 1000);
    }

    function hideOverlay() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        if (overlayHost && overlayHost.parentNode) {
            overlayHost.parentNode.removeChild(overlayHost);
        }
        overlayHost = null;
        overlayShadow = null;
    }

    function updateElapsed() {
        if (!overlayHost) {
            return;
        }
        var root = overlayShadow || overlayHost;
        var el = root.getElementById ? root.getElementById('ecg-elapsed') : null;
        if (!el && root.querySelector) {
            el = root.querySelector('#ecg-elapsed');
        }
        if (el) {
            el.textContent = String(Math.floor((Date.now() - outageStart) / 1000));
        }
    }

    // ─── Probe (HEAD-Request mit Cache-Bust) ─────────────────────────────────

    function probe(timeoutMs) {
        return new Promise(function (resolve) {
            var c = cfg();

            if (!c.heartbeatUrl) {
                // Sicherer Default: ohne URL niemals "online" annehmen.
                // Andernfalls würde der Reconnect-Heartbeat nach 2.8 s exit-outage
                // ohne jeden Beweis (Bug bis 1.0.3 — Config war zur Init-Zeit leer).
                log('probe failed — no heartbeatUrl configured');
                resolve(false);
                return;
            }

            // Veto via navigator.onLine — wenn der Browser explizit offline meldet,
            // ist eine "ok" Probe garantiert eine Lüge (cached Response, Service
            // Worker, oder DevTools-Offline-Edge-Case). Niemals als online akzeptieren.
            if (navigator.onLine === false) {
                log('probe veto — navigator.onLine === false');
                resolve(false);
                return;
            }

            var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var timeoutId = setTimeout(function () {
                if (ctrl) { ctrl.abort(); }
                resolve(false);
            }, timeoutMs);

            var url = c.heartbeatUrl + (c.heartbeatUrl.indexOf('?') >= 0 ? '&' : '?')
                + '_ecg=' + Date.now();

            // GET + Body-Check statt HEAD: probe.txt enthält "ok". Cached/stale
            // Responses mit anderem Body fallen durch, auch wenn Status 200 ist.
            var opts = { method: 'GET', cache: 'no-store', credentials: 'same-origin' };
            if (ctrl) { opts.signal = ctrl.signal; }

            // _origFetch verwenden, sonst Recursion durch unseren Wrapper.
            (window.ExamConnGuard._origFetch || window.fetch).call(window, url, opts)
                .then(function (res) {
                    clearTimeout(timeoutId);
                    if (!res || !res.ok) {
                        resolve(false);
                        return;
                    }
                    return res.text().then(function (body) {
                        var trimmed = (body || '').replace(/^\s+|\s+$/g, '');
                        resolve(trimmed === 'ok');
                    }, function () {
                        resolve(false);
                    });
                })
                .catch(function () {
                    clearTimeout(timeoutId);
                    resolve(false);
                });
        });
    }

    // ─── Outage-Modus ────────────────────────────────────────────────────────

    function enterOutage(source) {
        if (inOutage) {
            return;
        }
        log('entering outage (' + source + ')');
        inOutage = true;
        showOverlay();
        enableBeforeUnload();
        startReconnectHeartbeat();
        startOverlayWatchdog();
    }

    function exitOutage(source) {
        if (!inOutage) {
            return;
        }
        log('exiting outage (' + source + ')');
        inOutage = false;
        stopOverlayWatchdog();
        stopReconnectHeartbeat();
        hideOverlay();
        disableBeforeUnload();
        replayQueued();
    }

    // ─── Overlay-Watchdog ────────────────────────────────────────────────────
    //
    // Sicherheitsnetz: falls ein anderer JS-Code (ILIAS-Test-Player, anderes
    // Plugin) unser Overlay-Element via removeChild / innerHTML aus dem DOM
    // schiebt, hängen wir es wieder ein. Während Outage muss der User das
    // Overlay sehen, egal was sonst im DOM passiert.

    function startOverlayWatchdog() {
        if (watchdogTimer) {
            return;
        }
        watchdogTimer = setInterval(function () {
            if (!inOutage) {
                return;
            }
            var detached = !overlayHost
                || !overlayHost.parentNode
                || (document.body && !document.body.contains(overlayHost));
            if (detached) {
                log('overlay watchdog: re-attaching');
                // State auf "kein Overlay" zurücksetzen, damit buildOverlay
                // ein frisches Element erzeugt.
                overlayHost = null;
                overlayShadow = null;
                buildOverlay();
                if (!timerInterval) {
                    timerInterval = setInterval(updateElapsed, 1000);
                }
                updateElapsed();
            }
        }, cfg().watchdogIntervalMs);
    }

    function stopOverlayWatchdog() {
        if (watchdogTimer) {
            clearInterval(watchdogTimer);
            watchdogTimer = null;
        }
    }

    function startReconnectHeartbeat() {
        if (reconnectTimer) {
            return;
        }
        var c = cfg();
        reconnectTimer = setInterval(function () {
            probe(cfg().preflightTimeout).then(function (ok) {
                if (!ok || !inOutage) {
                    return;
                }
                // Stabilitäts-Fenster: kurzes Online-Flackern soll nicht
                // sofort triggern. Erst wenn Verbindung graceMs stabil ist.
                if (graceTimer) {
                    return;
                }
                log('reconnect candidate, waiting for grace');
                graceTimer = setTimeout(function () {
                    graceTimer = null;
                    if (!inOutage) {
                        return;
                    }
                    // Zweite Probe nach Grace-Period bestätigt.
                    probe(cfg().preflightTimeout).then(function (stillOk) {
                        if (stillOk) {
                            exitOutage('reconnect-confirmed');
                        } else {
                            log('reconnect gave way again, staying offline');
                        }
                    });
                }, cfg().reconnectGraceMs);
            });
        }, c.reconnectIntervalMs);
    }

    function stopReconnectHeartbeat() {
        if (reconnectTimer) {
            clearInterval(reconnectTimer);
            reconnectTimer = null;
        }
        if (graceTimer) {
            clearTimeout(graceTimer);
            graceTimer = null;
        }
    }

    // ─── beforeunload-Schutz ─────────────────────────────────────────────────

    function beforeUnloadHandler(e) {
        e.preventDefault();
        e.returnValue = 'Verbindung ist unterbrochen. Bitte warten — die Seite läuft weiter, sobald die Verbindung wieder da ist.';
        return e.returnValue;
    }

    function enableBeforeUnload() {
        if (beforeUnloadOn) {
            return;
        }
        window.addEventListener('beforeunload', beforeUnloadHandler);
        beforeUnloadOn = true;
    }

    function disableBeforeUnload() {
        if (!beforeUnloadOn) {
            return;
        }
        window.removeEventListener('beforeunload', beforeUnloadHandler);
        beforeUnloadOn = false;
    }

    // ─── Queue + Replay ──────────────────────────────────────────────────────

    function queueAction(action) {
        // Nur eine pending Action — neuere überschreibt ältere.
        // Studi klickt im Zweifel mehrmals "Weiter"; das letzte Klicken zählt.
        pendingAction = action;
        log('queued action', action.type);
    }

    function performAction(action) {
        // inReplay schützt davor, dass unser eigener submit/click-Listener
        // den replayten Submit nochmal abfängt → Endlos-Schleife.
        // requestSubmit() feuert das submit-Event, .click() das click-Event.
        inReplay = true;
        try {
            if (action.type === 'submit' && action.form) {
                if (typeof action.form.requestSubmit === 'function' && action.submitter) {
                    action.form.requestSubmit(action.submitter);
                } else {
                    action.form.submit();
                }
            } else if (action.type === 'click' && action.element) {
                // Re-Click feuert das click-Event nochmal — unser Listener
                // erkennt inReplay und überspringt, der originale ILIAS-Handler
                // (preventDefault + window.location.href = url) läuft normal.
                action.element.click();
            } else if (action.type === 'navigate' && action.href) {
                window.location.href = action.href;
            }
        } catch (err) {
            log('replay error', err);
        }
        // Flag-Reset asynchron — synchroner Submit hat das Event schon ausgelöst,
        // danach navigiert die Seite eh.
        setTimeout(function () { inReplay = false; }, 0);
    }

    function replayQueued() {
        if (!pendingAction) {
            return;
        }
        var action = pendingAction;
        pendingAction = null;
        log('replaying queued action', action.type);
        // Kurzer Defer, damit eventuelle State-Updates nach Reconnect
        // (z. B. eingegebener Text in Inputs) sicher persistiert sind.
        setTimeout(function () { performAction(action); }, 50);
    }

    // ─── Pre-Flight + Intercept ──────────────────────────────────────────────

    function dispatchAction(action) {
        // Schon im Outage: direkt queuen, Overlay ist da.
        if (inOutage) {
            queueAction(action);
            return;
        }

        // Browser sagt explizit offline → vertrauen, Outage rauf.
        if (navigator.onLine === false) {
            queueAction(action);
            enterOutage('navigator-onLine');
            return;
        }

        // Normalfall: Pre-Flight-Probe.
        log('pre-flight probe for', action.type);
        probe(cfg().preflightTimeout).then(function (ok) {
            if (ok) {
                log('pre-flight ok, executing', action.type);
                performAction(action);
            } else {
                log('pre-flight failed, entering outage');
                queueAction(action);
                enterOutage('pre-flight');
            }
        });
    }

    function isAnchorTarget(el) {
        if (!el || (el.tagName || '').toLowerCase() !== 'a') {
            return false;
        }
        var href = el.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#') {
            return false;
        }
        var lower = href.toLowerCase();
        if (lower.indexOf('javascript:') === 0 || lower.indexOf('mailto:') === 0
            || lower.indexOf('tel:') === 0) {
            return false;
        }
        return true;
    }

    function isButtonTarget(el) {
        if (!el) {
            return false;
        }
        var tag = (el.tagName || '').toLowerCase();
        if (tag === 'button') {
            return true;
        }
        if (tag === 'input') {
            var type = (el.getAttribute('type') || '').toLowerCase();
            return type === 'submit' || type === 'image' || type === 'button';
        }
        return false;
    }

    function findInteractiveAncestor(el) {
        var node = el;
        while (node && node !== document.body) {
            if (isAnchorTarget(node) || isButtonTarget(node)) { return node; }
            node = node.parentNode;
        }
        return null;
    }

    function onSubmitCapture(e) {
        // Direkter Form-Submit (z. B. Enter im Textfeld) — Form-Submit-Events
        // OHNE vorherigen Button-Click.
        if (inReplay) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        dispatchAction({
            type:      'submit',
            form:      e.target,
            submitter: e.submitter || null
        });
    }

    function onClickCapture(e) {
        if (inReplay) {
            return;
        }
        // Modifier-Keys (Strg+Klick = neuer Tab etc.) und non-left-click ignorieren.
        if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
            return;
        }

        var hit = findInteractiveAncestor(e.target);
        if (!hit) {
            return;
        }

        var tag = (hit.tagName || '').toLowerCase();

        // Anchor mit target=_blank → durchlassen (neuer Tab).
        if (tag === 'a') {
            var anchorTarget = (hit.getAttribute('target') || '').toLowerCase();
            if (anchorTarget === '_blank') {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            dispatchAction({ type: 'navigate', href: hit.href });
            return;
        }

        // Button: ILIAS-Test-Player nutzt Buttons mit JS-onclick, die intern
        // window.location.href setzen (CSRF-Token in URL). Wir können den
        // Original-Handler nicht direkt auslesen — also fangen wir den Click
        // ab, proben, und feuern bei OK den Click via element.click() neu.
        // inReplay sorgt dafür, dass der Re-Click unseren Listener überspringt.
        e.preventDefault();
        e.stopPropagation();
        dispatchAction({ type: 'click', element: hit });
    }

    // ─── fetch()-Wrapper mit Exponential Backoff ─────────────────────────────
    //
    // Retry für ILIAS-Autosaves bei transienten Fehlern (5xx, kurzem Netzaus).
    // Triggert NICHT eigenständig den Outage-Modus — sonst spurious offline
    // wenn ein einzelner Background-fetch zufällig failt (siehe 1.0.0-Bug).

    var MAX_RETRIES = 6;

    function isRetriable(err) {
        return (err && err.name === 'TypeError') || (err && err.name === 'AbortError');
    }

    function wrapFetch() {
        if (!window.fetch || (window.ExamConnGuard && window.ExamConnGuard._fetchWrapped)) {
            return;
        }
        var origFetch = window.fetch.bind(window);
        window.ExamConnGuard = window.ExamConnGuard || {};
        window.ExamConnGuard._origFetch = origFetch;
        window.ExamConnGuard._fetchWrapped = true;

        window.fetch = function (input, init) {
            var attempt = 0;

            function tryOnce() {
                return origFetch(input, init).then(function (res) {
                    if (res.status >= 500 && res.status < 600 && attempt < MAX_RETRIES) {
                        return backoff();
                    }
                    return res;
                }).catch(function (err) {
                    if (isRetriable(err) && attempt < MAX_RETRIES) {
                        return backoff();
                    }
                    throw err;
                });
            }

            function backoff() {
                attempt++;
                var delay = Math.min(30000, 500 * Math.pow(2, attempt - 1));
                log('fetch retry', attempt, 'in', delay, 'ms');
                return new Promise(function (resolve) {
                    setTimeout(function () { resolve(tryOnce()); }, delay);
                });
            }

            return tryOnce();
        };
    }

    // ─── Init ────────────────────────────────────────────────────────────────

    window.ExamConnGuard = window.ExamConnGuard || {};
    window.ExamConnGuard.config = window.ExamConnGuard.config || {};

    wrapFetch();

    // Bewusst KEIN window.offline-Listener: Outage-Modus startet ausschließlich
    // beim Submit/Click. Während der Studi tippt, soll nichts stören — das Netz
    // ist nur beim Verlassen der Frage relevant. Der dispatchAction-Pfad hat
    // einen navigator.onLine-Shortcut für sofortige Outage ohne Probe-Latenz.

    // Capture-Phase: greift VOR ILIAS-Handlern.
    document.addEventListener('submit', onSubmitCapture, true);
    document.addEventListener('click',  onClickCapture,  true);

    log('initialized — config at init:', JSON.stringify(cfg()));
    console.log('[ExamConnGuard] Connection guard activated (on-demand probe, live config)');
})();
