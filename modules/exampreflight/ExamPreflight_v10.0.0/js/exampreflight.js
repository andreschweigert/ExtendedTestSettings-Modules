/**
 * ExamPreflight Cockpit — geführter Probedurchlauf durch den ILIAS-Testplayer
 *
 * Der Testplayer läuft in einem same-origin iframe. Das Cockpit:
 *   1. startet (optional) einen Tab-Capture via Screen-Capture-API
 *   2. wartet, bis der Prüfer den Test im iframe startet
 *   3. prüft pro Fragenseite Medien/Formeln/Layout, macht einen Screenshot
 *      (segmentiert über die gesamte Fragenhöhe) und klickt automatisch weiter
 *   4. lädt jedes Ergebnis per fetch() zum Server hoch
 *
 * Config wird via init() aus addOnLoadCode übergeben — niemals beim Script-Load
 * cachen (addJavaScript läuft vor addOnLoadCode).
 */
(function () {
    'use strict';

    var cfg = null;
    var els = {};

    var state = 'idle';        // idle | waiting | running | paused | done | aborted | error
    var runId = 0;
    var position = 0;
    var lastPageKey = '';      // zuletzt fertig verarbeitete Seite
    var seenPages = {};        // alle in diesem Lauf geprüften Seiten (Zyklus-Erkennung)
    var repeatCount = 0;
    var totalErrors = 0;
    var totalWarnings = 0;
    var pausedRequested = false;
    var processingPage = false;
    var finishing = false;

    var captureStream = null;
    var captureVideo = null;

    var jsErrors = [];
    var autoStartAttempts = 0;
    var pwAttempts = 0;
    var summaryJumps = 0;      // eigener Cap für den Fragenübersicht-Sprung
    var uploadFailures = 0;

    // ─── Init / UI ────────────────────────────────────────────────────────────

    function init(config) {
        cfg = config;
        els.frame    = document.getElementById('epf-frame');
        els.state    = document.getElementById('epf-state');
        els.progress = document.getElementById('epf-progress');
        els.hint     = document.getElementById('epf-hint');
        els.findings = document.getElementById('epf-findings');
        els.btnStart = document.getElementById('epf-btn-start');
        els.btnPause = document.getElementById('epf-btn-pause');
        els.btnStop  = document.getElementById('epf-btn-stop');
        els.shotsCb  = document.getElementById('epf-shots-enabled');
        els.delCb    = document.getElementById('epf-del-attempt');

        if (!els.frame || !cfg) { return; }

        els.btnStart.addEventListener('click', onStart);
        els.btnPause.addEventListener('click', onPauseToggle);
        els.btnStop.addEventListener('click', onStop);
        els.frame.addEventListener('load', onFrameLoad);
    }

    function t(key) {
        return (cfg && cfg.lang && cfg.lang[key]) || key;
    }

    function setState(s, badgeClass) {
        state = s;
        els.state.textContent = t('state_' + s);
        els.state.className = 'epf-badge epf-badge-' + (badgeClass || s);
    }

    function setHint(text) {
        els.hint.textContent = text;
    }

    function updateProgress() {
        var total = cfg.expectedQuestions > 0 ? (' ' + t('progress_of') + ' ~' + cfg.expectedQuestions) : '';
        els.progress.textContent = position > 0
            ? (t('question_short') || 'Frage') + ' ' + position + total
                + ' — ' + totalErrors + ' ❌ / ' + totalWarnings + ' ⚠️'
            : '';
    }

    function addFindingLine(pos, title, status, findings) {
        var line = document.createElement('div');
        var icon = status === 'fail' ? '❌' : (status === 'warn' ? '⚠️' : '✅');
        line.className = 'epf-finding-line epf-finding-' + status;
        var msg = '';
        if (findings.length) {
            msg = ' — ' + findings.slice(0, 3).map(function (f) { return f.message; }).join('; ');
            if (findings.length > 3) { msg += ' …'; }
        }
        line.textContent = icon + ' ' + pos + '. ' + title + msg;
        els.findings.insertBefore(line, els.findings.firstChild);
    }

    // ─── Start / Pause / Stop ─────────────────────────────────────────────────

    function onStart() {
        els.btnStart.disabled = true;
        els.shotsCb.disabled = true;

        // Zustand für einen (Neu-)Start zurücksetzen
        position = 0;
        lastPageKey = '';
        seenPages = {};
        repeatCount = 0;
        totalErrors = 0;
        totalWarnings = 0;
        pausedRequested = false;
        processingPage = false;
        finishing = false;
        autoStartAttempts = 0;
        pwAttempts = 0;
        summaryJumps = 0;
        uploadFailures = 0;
        updateProgress();

        var wantShots = els.shotsCb.checked;
        // Screen-Capture-API gibt es nur in Secure Contexts (HTTPS) — auf einem
        // HTTP-System klar sagen, dass es an der Umgebung liegt, nicht am Nutzer.
        var captureUnavailable = !(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia);
        var capturePromise = (wantShots && !captureUnavailable) ? startCapture() : Promise.resolve(false);

        capturePromise.then(function (captureOk) {
            if (wantShots && !captureOk) {
                setHint(t(captureUnavailable ? 'hint_capture_unavailable' : 'hint_capture_denied'));
            }
            var body = new FormData();
            body.append('epf_token', cfg.csrfToken);
            body.append('screenshots', captureOk ? '1' : '');
            body.append('viewport', window.innerWidth + 'x' + window.innerHeight);
            return fetch(cfg.startRunUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); });
        }).then(function (data) {
            if (!data || !data.ok) { throw new Error('startRun failed'); }
            runId = data.runId;
            setState('waiting');
            setHint(t('hint_start_test'));
            els.btnPause.disabled = false;
            els.btnStop.disabled = false;
            // Falls der Prüfer den Test schon geöffnet hat: aktuelle Seite direkt verarbeiten
            onFrameLoad();
        }).catch(function () {
            // Kein Zombie-Lauf: alte runId entwerten und Capture-Stream freigeben,
            // sonst würden spätere Frame-Loads in den vorherigen Lauf hochladen.
            runId = 0;
            stopCapture();
            setState('error');
            els.btnStart.disabled = false;
            els.shotsCb.disabled = false;
        });
    }

    function onPauseToggle() {
        if (state === 'running' || state === 'waiting') {
            pausedRequested = true;
            setState('paused');
        } else if (state === 'paused') {
            pausedRequested = false;
            setState('running');
            var doc = frameDoc();
            if (doc && isQuestionPage(doc) && pageKeyOf(doc, extractTitle(doc)) === lastPageKey) {
                // Seite wurde vor der Pause bereits fertig verarbeitet → nur weiterblättern
                clickNext(doc);
            } else {
                // Deckt auch Nicht-Fragenseiten ab (TestScreen etc.): onFrameLoad
                // entscheidet zwischen processCurrentPage und tryAutoAdvance.
                onFrameLoad();
            }
        }
    }

    function onStop() {
        if (!window.confirm(t('confirm_stop'))) { return; }
        finishRun('aborted');
    }

    function finishRun(result) {
        if (finishing) { return; }
        finishing = true;
        stopCapture();
        var body = new FormData();
        body.append('epf_token', cfg.csrfToken);
        body.append('run_id', String(runId));
        body.append('result', result);
        fetch(cfg.finishRunUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return null; })
            .then(function (data) {
                setState(result === 'aborted' ? 'aborted' : 'done');
                setHint(result === 'aborted' ? t('hint_aborted') : t('hint_done'));
                els.btnPause.disabled = true;
                els.btnStop.disabled = true;
                var summary = data && data.ok
                    ? data.checked + ' ✓, ' + data.errors + ' ❌, ' + data.warnings + ' ⚠️ — '
                    : '';
                var link = document.createElement('a');
                link.href = cfg.reportPageUrl;
                link.className = 'btn btn-primary btn-sm';
                link.textContent = t('open_report');
                var line = document.createElement('div');
                line.className = 'epf-finding-line epf-finding-summary';
                line.textContent = summary;
                line.appendChild(link);
                els.findings.insertBefore(line, els.findings.firstChild);

                if (els.delCb && els.delCb.checked) {
                    // Start bleibt gesperrt, bis der iframe zurückgesetzt ist —
                    // sonst würde ein sofortiger Neu-Start ins iframe-Reset rennen.
                    deleteOwnAttempt();
                } else {
                    els.btnStart.disabled = false;   // erneuter Lauf ohne Seiten-Reload möglich
                    els.shotsCb.disabled = false;
                }
            });
    }

    /** Eigenen Testlauf des Prüfers serverseitig löschen (offizielle ILIAS-API) */
    function deleteOwnAttempt() {
        var body = new FormData();
        body.append('epf_token', cfg.csrfToken);
        fetch(cfg.deleteAttemptUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return null; })
            .then(function (data) {
                var ok = data && data.ok;
                var line = document.createElement('div');
                line.className = 'epf-finding-line ' + (ok ? 'epf-finding-ok' : 'epf-finding-warn');
                line.textContent = ok ? '🗑 ' + t('attempt_deleted') : '⚠️ ' + t('attempt_delete_failed');
                els.findings.insertBefore(line, els.findings.firstChild);
                if (ok) {
                    // Testfenster auf den frischen Ausgangszustand zurücksetzen
                    els.frame.src = cfg.testUrl;
                }
                // Erst jetzt einen neuen Lauf erlauben (iframe-Reset ist angestoßen)
                els.btnStart.disabled = false;
                els.shotsCb.disabled = false;
            });
    }

    // ─── Frame-Navigation ─────────────────────────────────────────────────────

    function onFrameLoad() {
        if (runId <= 0 || finishing || state === 'done' || state === 'aborted') { return; }

        var doc = frameDoc();
        if (!doc) { return; }

        // JS-Fehler der Fragenseite ab jetzt mitschneiden
        jsErrors = [];
        try {
            els.frame.contentWindow.addEventListener('error', function (e) {
                if (jsErrors.length < 10) {
                    jsErrors.push(String(e.message || 'resource error: ' + (e.target && e.target.src ? e.target.src : '')));
                }
            }, true);
        } catch (ignore) {}

        if (!isQuestionPage(doc)) {
            if (state === 'running') { setState('waiting'); }
            // Ende-Seite erreicht (z.B. "Test beenden" wurde geklickt) → Lauf abschließen
            if (position > 0 && isFinishedPage(doc)) {
                setHint(t('hint_done_page'));
                finishRun('completed');
                return;
            }
            if (!pausedRequested) {
                tryAutoAdvance(doc);
            }
            return;
        }

        if (pausedRequested) {
            setState('paused');
            return;
        }

        setState('running');
        setHint(t('hint_running'));
        processCurrentPage();
    }

    function frameDoc() {
        try {
            return els.frame.contentDocument;
        } catch (e) {
            return null;
        }
    }

    function isQuestionPage(doc) {
        return !!(doc.getElementById('taForm') && doc.querySelector('.tst_question-container'));
    }

    function isFinishedPage(doc) {
        // Abschluss-/Ergebnisseite des Players (kein Fragenformular mehr, aber Player-Kontext)
        var url = '';
        try { url = String(els.frame.contentWindow.location.href); } catch (ignore) {}
        return /cmd(=|%5B)(afterTestPassFinished|showFinalStatement|finishTest|redirectAfterFinish)/i.test(url);
    }

    // ─── Auto-Start: Zwischenseiten selbst bedienen ───────────────────────────

    /**
     * Auf Nicht-Fragenseiten (TestScreen, Info-Seite, Passwort-Seite) den Test
     * automatisch starten: Start-/Fortsetzen-Link klicken, Launcher-Modal mit
     * Passwort + Prüfungsbedingungen ausfüllen, klassische Passwort-Seite bedienen.
     */
    function tryAutoAdvance(doc) {
        // Harte Sperre: Innerhalb des laufenden Players (Zwischenseiten wie
        // Einleitung, Beenden-Bestätigung) NIEMALS automatisch klicken/submitten —
        // Auto-Start gehört nur auf TestScreen/Info-/Passwort-Seite.
        var url = '';
        try { url = String(els.frame.contentWindow.location.href); } catch (ignore) {}
        if (/cmdclass=iltestoutput|cmdclass=iltestplayer|cmd=startPlayer|cmd=resumePlayer/i.test(url)) {
            // Einzige sichere Ausnahme: Fragenübersicht — der Sprung zur ersten
            // Frage ist ein reiner GET-Navigations-Link (cmd=showQuestion), kein Submit.
            var qlink = doc.querySelector('a[href*="cmd=showQuestion"][href*="sequence="]');
            if (qlink && summaryJumps < 3) {
                summaryJumps++;   // eigener Cap — teilt sich NICHT das Auto-Start-Budget
                setHint(t('hint_autostart'));
                try {
                    els.frame.contentWindow.location.href = qlink.href;
                } catch (e) {
                    qlink.click();
                }
                return;
            }
            setHint(t('hint_non_question'));
            return;
        }

        // 1. Klassische Passwort-Seite (ilTestPasswordProtectionGUI)
        var pw = doc.querySelector('input[type=password][name="password"]');
        if (pw && cfg.password && pwAttempts < 2) {
            pwAttempts++;
            setHint(t('hint_password'));
            setInputValue(pw, cfg.password);
            var form = pw.form;
            var sub = form && form.querySelector('input[type=submit], button[type=submit], [name^="cmd["]');
            if (sub) { sub.click(); } else if (form) { form.submit(); }
            return;
        }

        if (autoStartAttempts >= 5) {
            setHint(t('hint_start_test'));
            return;
        }

        // 2. Direkter Start-/Fortsetzen-Link (TestScreen-Launcher oder Info-Toolbar)
        var link = doc.querySelector(
            'a[href*="cmd=resumePlayer"], [data-action*="cmd=resumePlayer"],'
            + ' a[href*="cmd=startPlayer"], [data-action*="cmd=startPlayer"],'
            + ' a[href*="cmd=startTest"], [data-action*="cmd=startTest"]'
        );
        if (link) {
            autoStartAttempts++;
            setHint(t('hint_autostart'));
            link.click();
            return;
        }

        // 3. KS-Launcher-Modal (Eigenständigkeitserklärung/Prüfungsbedingungen,
        //    Passwort): Das Formular liegt bereits im DOM (.c-launcher__form bzw.
        //    <dialog>) — direkt befüllen und abschicken, statt auf Signal-Buttons
        //    zu klicken (die reagieren nicht zuverlässig auf synthetische Klicks).
        if (fillLauncherModal(doc)) {
            autoStartAttempts++;
            setHint(t('hint_autostart'));
            return;
        }

        // 4. Fallback: Launcher-Button klicken (Modal öffnet sich), dann ausfüllen
        var trigger = doc.querySelector(
            'a[href*="launcher_id=exam_modal"], [data-action*="launcher_id=exam_modal"],'
            + ' .c-launcher button, .c-launcher a.btn, .c-launcher a'
        );
        if (trigger) {
            autoStartAttempts++;
            setHint(t('hint_autostart'));
            trigger.click();
            // Modal braucht ggf. einen Moment — mehrfach versuchen (idempotent)
            setTimeout(function () { fillLauncherModal(doc); }, 700);
            setTimeout(function () { fillLauncherModal(doc); }, 1800);
            return;
        }

        setHint(t('hint_start_test'));
    }

    /**
     * Launcher-/Modal-Formular befüllen und direkt absenden (Checkbox für
     * Eigenständigkeitserklärung, Passwort). Das Modal muss dafür nicht sichtbar
     * sein — der POST geht auch aus dem versteckten <dialog> raus.
     * Gibt true zurück, wenn ein Formular gefunden und abgeschickt wurde.
     */
    function fillLauncherModal(doc) {
        if (finishing || pausedRequested) { return false; }

        // NUR das Formular des Start-Launchers — niemals andere Modal-Formulare!
        // Player-Seiten enthalten z.B. das "Test beenden"-Bestätigungs-Modal im DOM;
        // ein breiterer Selektor würde dessen Formular abschicken und den Test beenden.
        var form = doc.querySelector('.c-launcher form, .c-launcher__form form');
        if (!form || form.getAttribute('data-epf-handled') === '1') { return false; }

        var pw = form.querySelector('input[type=password]');
        if (pw) {
            if (!cfg.password) { setHint(t('hint_start_test')); return false; }
            setInputValue(pw, cfg.password);
        }

        // Prüfungsbedingungen (Eigenständigkeitserklärung) u.ä. bestätigen
        form.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
            cb.checked = true;
            try { cb.dispatchEvent(new Event('change', { bubbles: true })); } catch (ignore) {}
        });

        form.setAttribute('data-epf-handled', '1');
        try {
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        } catch (e) {
            form.submit();
        }
        return true;
    }

    /** Echt sichtbar? (geschlossene Modals sind per Stylesheet versteckt) */
    function isElementVisible(el, doc) {
        try {
            if (el.tagName === 'DIALOG' && !el.open) { return false; }
            var cs = doc.defaultView.getComputedStyle(el);
            if (cs.display === 'none' || cs.visibility === 'hidden') { return false; }
            var r = el.getBoundingClientRect();
            return r.width > 0 && r.height > 0;
        } catch (e) {
            return false;
        }
    }

    /** Wert setzen + input-Event feuern (für Formular-Frameworks) */
    function setInputValue(input, value) {
        input.value = value;
        try {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (ignore) {}
    }

    // ─── Fragen-Verarbeitung ──────────────────────────────────────────────────

    function processCurrentPage() {
        if (processingPage || finishing || state !== 'running') { return; }
        var doc = frameDoc();
        if (!doc || !isQuestionPage(doc)) { return; }

        // Duplikat-Erkennung VOR der Verarbeitung: dieselbe Seite erneut geladen
        // (z.B. weil der Weiter-Klick nicht navigiert hat) → nicht doppelt erfassen,
        // sondern erneut weiterklicken; ab dem 2. Mal abbrechen (Endlosschleife).
        var pageKey = pageKeyOf(doc, extractTitle(doc));
        if (pageKey === lastPageKey) {
            repeatCount++;
            if (repeatCount >= 2) {
                // Navigation dreht sich im Kreis → Lauf als abgebrochen beenden.
                setHint(t('state_error'));
                finishRun('aborted');
            } else {
                clickNext(doc);
            }
            return;
        }

        // Zyklus-Erkennung: Frage schon früher geprüft (z.B. Fragenübersicht am Ende
        // → Sprung zur ersten Frage) → alle Fragen durch, Lauf sauber abschließen.
        if (seenPages[pageKey]) {
            finishRun('completed');
            return;
        }

        processingPage = true;

        waitForMedia(doc, cfg.settleMs)
            .then(function () {
                doc = frameDoc();
                if (!doc || !isQuestionPage(doc) || finishing) { processingPage = false; return null; }

                // Während des Waits kann der Prüfer im iframe navigiert haben →
                // Seite neu bewerten, sonst Doppel-Upload/umgangene Zyklus-Erkennung.
                var title = extractTitle(doc);
                var pk = pageKeyOf(doc, title);
                if (pk === lastPageKey || seenPages[pk]) {
                    processingPage = false;
                    if (state === 'running' && !pausedRequested) { clickNext(doc); }
                    return null;
                }

                // Pause während des Waits angefordert → hier stoppen, beim Resume
                // wird die Seite erneut verarbeitet (lastPageKey ist noch nicht gesetzt).
                if (pausedRequested) {
                    processingPage = false;
                    setState('paused');
                    return null;
                }

                repeatCount = 0;
                lastPageKey = pk;
                seenPages[pk] = true;
                position++;

                return runChecks(doc).then(function (findings) {
                    updateProgress();
                    return takeScreenshot(doc).then(function (shot) {
                        return uploadResult(title, detectQType(doc), findings, shot);
                    }).then(function (status) {
                        addFindingLine(position, title, status, findings);
                        processingPage = false;
                        if (state === 'running' && !pausedRequested && !finishing) {
                            clickNext(doc);
                        } else if (pausedRequested) {
                            setState('paused');
                        }
                    });
                });
            })
            .catch(function () {
                processingPage = false;
            });
    }

    function extractTitle(doc) {
        var el = doc.querySelector('#tst_right .ilc_page_title_PageTitle, .ilc_page_title_PageTitle, #tst_right h1');
        var title = el ? el.textContent.trim() : '';
        return title || (doc.title || '').trim() || ('Frage ' + (position + 1));
    }

    function pageKeyOf(doc, title) {
        var seq = '';
        try {
            var m = String(els.frame.contentWindow.location.href).match(/sequence=(\d+)/);
            if (m) { seq = m[1]; }
        } catch (ignore) {}

        // Sequence zuverlässiger aus der Formular-Action (die trägt die ilCtrl-Params
        // auch dann, wenn die Browser-URL nach einem POST keine sequence enthält).
        if (!seq) {
            var form = doc.getElementById('taForm');
            var action = form ? (form.getAttribute('action') || '') : '';
            var fm = action.match(/sequence[=%5D]+(\d+)/i);
            if (fm) { seq = fm[1]; }
        }

        // Falls weder URL noch Action eine Sequence liefern: Fragen-ID aus dem DOM,
        // sonst würde ein konstanter doc.title alle Seiten gleich aussehen lassen.
        if (!seq) {
            var q = doc.querySelector('[id^="question"], [id^="ilc_qid"], .ilc_question_Standard [name]');
            if (q) { seq = q.id || q.getAttribute('name') || ''; }
        }

        return seq + '|' + title;
    }

    function detectQType(doc) {
        var c = doc.querySelector('.tst_question-container');
        if (!c) { return ''; }
        if (c.querySelector('input[type=radio]'))    { return 'SingleChoice'; }
        if (c.querySelector('input[type=checkbox]')) { return 'MultipleChoice'; }
        if (c.querySelector('textarea'))             { return 'Freitext'; }
        if (c.querySelector('select'))               { return 'Auswahl'; }
        if (c.querySelector('input[type=text]'))     { return 'Eingabe'; }
        if (c.querySelector('input[type=file]'))     { return 'Upload'; }
        return '';
    }

    // Warten bis Bilder/Videos geladen (oder fehlgeschlagen) sind + Settle-Zeit
    function waitForMedia(doc, settleMs) {
        var waits = [];

        doc.querySelectorAll('img').forEach(function (img) {
            if (!img.complete) {
                // Lazy-Bilder unterhalb des Viewports laden erst beim Scrollen (das
                // passiert erst beim Screenshot) → nicht darauf warten, sonst 10 s
                // Stall + falsches "slow_image". Eager anfordern und weiterlaufen.
                if ((img.loading === 'lazy') && !isNearViewport(img, doc)) {
                    try { img.loading = 'eager'; } catch (ignore) {}
                    return;
                }
                waits.push(new Promise(function (resolve) {
                    img.addEventListener('load', resolve, { once: true });
                    img.addEventListener('error', resolve, { once: true });
                    setTimeout(resolve, 10000);
                }));
            }
        });

        doc.querySelectorAll('video, audio').forEach(function (m) {
            if (m.readyState === 0 && !m.error) {
                waits.push(new Promise(function (resolve) {
                    m.addEventListener('loadedmetadata', resolve, { once: true });
                    m.addEventListener('error', resolve, { once: true });
                    setTimeout(resolve, 10000);
                }));
            }
        });

        return Promise.all(waits).then(function () {
            return new Promise(function (resolve) { setTimeout(resolve, settleMs); });
        });
    }

    // ─── Checks ───────────────────────────────────────────────────────────────

    /** Führt alle Checks aus; asynchron wegen der HEAD-Requests für Hintergrundbilder. */
    function runChecks(doc) {
        var findings = [];
        var container = doc.querySelector('.tst_question-container') || doc.body;

        checkImages(container, findings);
        checkAv(container, findings);
        checkIframes(container, findings);
        checkMath(doc, findings);
        checkRawTex(container, findings);
        checkFormatting(doc, container, findings);
        checkOptions(container, findings);
        checkQuestionText(container, findings);
        checkLayout(doc, container, findings);
        checkResources(findings);

        jsErrors.forEach(function (msg) {
            findings.push({ check: 'js_error', level: 'warning', message: 'JavaScript-Fehler', detail: msg.substring(0, 300) });
        });

        return checkBgImages(doc, container, findings).then(function () {
            return findings;
        });
    }

    function shortSrc(src) {
        return String(src || '').split('?')[0].split('/').slice(-2).join('/').substring(0, 200);
    }

    function checkImages(container, findings) {
        container.querySelectorAll('img').forEach(function (img) {
            if (!img.src || img.src.indexOf('data:') === 0) { return; }
            if (img.complete && img.naturalWidth === 0) {
                findings.push({
                    check: 'broken_image', level: 'error',
                    message: 'Bild wird nicht geladen',
                    detail: shortSrc(img.src) + (img.alt ? ' (alt: ' + img.alt + ')' : '')
                });
            } else if (!img.complete && img.loading !== 'lazy') {
                // Lazy-Bilder, die (noch) nicht sichtbar sind, sind kein Fehler —
                // sie laden bewusst erst beim Scrollen. Nicht als "slow" werten.
                findings.push({
                    check: 'slow_image', level: 'warning',
                    message: 'Bild nach Wartezeit noch nicht geladen',
                    detail: shortSrc(img.src)
                });
            }
        });
    }

    /** Grobe Nähe zum sichtbaren Bereich (± ein Viewport) */
    function isNearViewport(el, doc) {
        try {
            var r = el.getBoundingClientRect();
            var h = (doc.defaultView.innerHeight || 800);
            return r.top < h * 2 && r.bottom > -h;
        } catch (e) {
            return true;
        }
    }

    function checkAv(container, findings) {
        container.querySelectorAll('video, audio').forEach(function (m) {
            var kind = m.tagName === 'VIDEO' ? 'Video' : 'Audio';
            var src = m.currentSrc || m.src || (m.querySelector('source') ? m.querySelector('source').src : '');
            if (m.error || m.networkState === 3 /* NETWORK_NO_SOURCE */) {
                findings.push({
                    check: 'broken_media', level: 'error',
                    message: kind + '-Quelle kann nicht abgespielt werden',
                    detail: shortSrc(src) + (m.error ? ' (Code ' + m.error.code + ')' : '')
                });
            } else if (m.readyState === 0) {
                findings.push({
                    check: 'slow_media', level: 'warning',
                    message: kind + ' nach Wartezeit ohne Metadaten',
                    detail: shortSrc(src)
                });
            }
            if (m.tagName === 'VIDEO' && m.readyState >= 1 && (m.videoWidth === 0 || m.videoHeight === 0)) {
                findings.push({
                    check: 'empty_video', level: 'error',
                    message: 'Video liefert keine Bilddaten (0×0)',
                    detail: shortSrc(src)
                });
            }
        });
    }

    function checkIframes(container, findings) {
        container.querySelectorAll('iframe').forEach(function (f) {
            // Unsichtbare Hilfs-iframes (TinyMCE, Modul-Helfer, about:blank) sind
            // kein Rendering-Problem für Studierende → nur sichtbare Frames mit
            // echter Quelle prüfen.
            var r = f.getBoundingClientRect();
            var src = f.getAttribute('src') || '';
            if (r.width < 10 || r.height < 10 || src === '' || src === 'about:blank') {
                return;
            }
            try {
                var d = f.contentDocument;
                if (d && d.body && d.body.innerHTML.trim() === '') {
                    findings.push({
                        check: 'empty_iframe', level: 'warning',
                        message: 'Eingebetteter Frame ist leer',
                        detail: shortSrc(src)
                    });
                }
            } catch (e) {
                findings.push({
                    check: 'external_iframe', level: 'info',
                    message: 'Externer Frame (Inhalt nicht prüfbar)',
                    detail: shortSrc(src)
                });
            }
        });
    }

    function checkMath(doc, findings) {
        var errs = doc.querySelectorAll('merror, .MathJax_Error, mjx-merror');
        if (errs.length > 0) {
            findings.push({
                check: 'math_error', level: 'error',
                message: 'Formel-Renderfehler (MathJax)',
                detail: errs.length + ' Fehlerstelle(n), z.B.: ' + (errs[0].textContent || '').trim().substring(0, 120)
            });
        }
    }

    /**
     * Sichtbarer Fließtext ohne Code-Bereiche: <pre>/<code>/<textarea> zeigen
     * Markup/LaTeX legitim als Inhalt (z.B. Programmier-Fragen) → für die
     * Text-Checks (raw_tex, raw_html, Encoding) ausblenden.
     */
    function proseText(container) {
        var out = '';
        (function walk(node) {
            for (var n = node.firstChild; n; n = n.nextSibling) {
                if (n.nodeType === 3) {
                    out += n.nodeValue;
                } else if (n.nodeType === 1) {
                    var tag = n.tagName;
                    if (tag === 'PRE' || tag === 'CODE' || tag === 'TEXTAREA'
                        || tag === 'SCRIPT' || tag === 'STYLE') {
                        continue;
                    }
                    walk(n);
                }
            }
        })(container);
        return out;
    }

    /** Unverarbeiteter Formel-Code sichtbar → MathJax ist gar nicht angesprungen */
    function checkRawTex(container, findings) {
        var text = proseText(container);
        var m = text.match(/\[tex\]|\[\/tex\]|\\begin\{/);
        if (!m) {
            // \( … \) als sichtbarer Text — verarbeitete Formeln tauchen so nicht im textContent auf
            m = text.match(/\\\([^)]{1,300}\\\)/);
        }
        if (m) {
            findings.push({
                check: 'raw_tex', level: 'error',
                message: 'Unverarbeiteter Formel-Code sichtbar (MathJax nicht angewendet)',
                detail: text.substr(Math.max(0, m.index - 20), 100).replace(/\s+/g, ' ').trim()
            });
        }
    }

    /** CSS-Hintergrundbilder per HEAD-Request prüfen (asynchron, same-origin) */
    function checkBgImages(doc, container, findings) {
        var urls = {};
        var all = container.querySelectorAll('*');
        var win = doc.defaultView;
        for (var i = 0; i < all.length && i < 800; i++) {
            var bg = '';
            try { bg = win.getComputedStyle(all[i]).backgroundImage || ''; } catch (e) { continue; }
            var m = /url\(["']?([^"')]+)["']?\)/.exec(bg);
            if (m && m[1] && m[1].indexOf('data:') !== 0) {
                urls[m[1]] = true;
            }
        }

        var jobs = Object.keys(urls).slice(0, 10).map(function (u) {
            var abs;
            try { abs = new URL(u, doc.baseURI); } catch (e) { return Promise.resolve(); }
            if (abs.origin !== window.location.origin) { return Promise.resolve(); } // cross-origin nicht prüfbar
            // GET mit Range statt HEAD: manche Server/Handler lehnen HEAD ab (405)
            // oder validieren signierte Pfade nur bei GET → sonst False Positives.
            return fetch(abs.href, { method: 'GET', headers: { Range: 'bytes=0-0' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok && r.status !== 206) {
                        findings.push({
                            check: 'broken_bg_image', level: 'error',
                            message: 'CSS-Hintergrundbild liefert HTTP ' + r.status,
                            detail: shortSrc(abs.href)
                        });
                    }
                })
                .catch(function () {
                    findings.push({
                        check: 'broken_bg_image', level: 'warning',
                        message: 'CSS-Hintergrundbild nicht ladbar',
                        detail: shortSrc(abs.href)
                    });
                });
        });

        return Promise.all(jobs);
    }

    /** Antwortoptionen-Plausibilität: leere Auswahlmenüs, Radio-Gruppen mit nur einer Option */
    function checkOptions(container, findings) {
        container.querySelectorAll('select').forEach(function (s) {
            // Long-Menu-/JS-befüllte Selects (autocomplete) starten leer und werden
            // erst clientseitig gefüllt → nicht als Fehler werten.
            if (s.getAttribute('data-autocomplete') !== null
                || s.className.indexOf('autocomplete') !== -1
                || s.closest('.ilc_question_KprimChoice, .ilc_question_LongMenu')) {
                return;
            }
            if (s.querySelectorAll('option').length < 2) {
                findings.push({
                    check: 'sparse_options', level: 'warning',
                    message: 'Auswahlmenü ohne echte Optionen',
                    detail: (s.name || s.id || '').substring(0, 100)
                });
            }
        });

        var groups = {};
        container.querySelectorAll('input[type=radio]').forEach(function (r) {
            groups[r.name] = (groups[r.name] || 0) + 1;
        });
        var single = Object.keys(groups).filter(function (n) { return groups[n] === 1; });
        if (single.length > 0) {
            findings.push({
                check: 'sparse_options', level: 'warning',
                message: single.length + ' Antwortgruppe(n) mit nur einer Auswahloption',
                detail: single.slice(0, 3).join(', ').substring(0, 150)
            });
        }
    }

    /** Formatierungs-Auffälligkeiten: Encoding-Salat, sichtbarer HTML-Code, Mini-Schrift, unlesbarer Text */
    function checkFormatting(doc, container, findings) {
        var text = proseText(container);

        // Encoding-Probleme (UTF-8/Latin-1-Mojibake, Ersatzzeichen)
        var enc = text.match(/�|Ã¤|Ã¶|Ã¼|ÃŸ|Ã„|Ã–|Ãœ|â€“|â€ž|â€œ|â‚¬/);
        if (enc) {
            findings.push({
                check: 'encoding_error', level: 'warning',
                message: 'Zeichensalat im Text (Encoding-Problem)',
                detail: text.substr(Math.max(0, enc.index - 20), 80).replace(/\s+/g, ' ').trim()
            });
        }

        // HTML-Code als sichtbarer Text (doppelt escaped, Import-Artefakt).
        // Nach dem Tagnamen MUSS '>', '/>' oder Whitespace+Attribut folgen — sonst
        // schlägt der Check auf Ungleichungen wie "a<b und c>d" an (Variablen b/i/p…).
        var raw = text.match(/<\/?(p|br|div|span|strong|em|b|i|ul|ol|li|table|img)(\s*\/?>|\s+[a-z-]+\s*=)|&nbsp;|&lt;\/?[a-z]|&amp;[a-z]+;/i);
        if (raw) {
            findings.push({
                check: 'raw_html', level: 'warning',
                message: 'HTML-Code als Text sichtbar (nicht gerendert)',
                detail: text.substr(Math.max(0, raw.index - 20), 100).replace(/\s+/g, ' ').trim()
            });
        }

        // Winzige Schrift + kaum lesbarer Text (Farbe ≈ Hintergrund)
        var win = doc.defaultView;
        var tiny = 0, tinyExample = '';
        var faint = 0, faintExample = '';
        var els = container.querySelectorAll('p, span, li, td, div, em, strong, label');
        for (var i = 0; i < els.length && i < 500; i++) {
            var el = els[i];
            var own = directText(el);
            if (own.length < 3) { continue; }
            var rect = el.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) { continue; }

            var cs;
            try { cs = win.getComputedStyle(el); } catch (e) { continue; }

            if (parseFloat(cs.fontSize) < 9) {
                tiny++;
                if (!tinyExample) { tinyExample = own.substring(0, 60); }
            }

            if (own.length >= 15 && !cs.backgroundImage.includes('url(')) {
                var fg = parseColor(cs.color);
                var bg = effectiveBg(el, win);
                if (fg && bg && contrastRatio(fg, bg) < 1.5) {
                    faint++;
                    if (!faintExample) { faintExample = own.substring(0, 60); }
                }
            }
        }
        if (tiny > 0) {
            findings.push({
                check: 'tiny_font', level: 'warning',
                message: tiny + ' Element(e) mit winziger Schrift (< 9 px)',
                detail: tinyExample
            });
        }
        if (faint > 0) {
            findings.push({
                check: 'low_contrast', level: 'warning',
                message: faint + ' Element(e) mit kaum lesbarem Text (Farbe ≈ Hintergrund)',
                detail: faintExample
            });
        }
    }

    /** Direkter Text eines Elements (ohne Kind-Elemente) */
    function directText(el) {
        var s = '';
        for (var n = el.firstChild; n; n = n.nextSibling) {
            if (n.nodeType === 3) { s += n.nodeValue; }
        }
        return s.trim();
    }

    /** 'rgb(a)'-String → [r,g,b] oder null (bei voller Transparenz) */
    function parseColor(str) {
        var m = /rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?\)/.exec(String(str));
        if (!m) { return null; }
        if (m[4] !== undefined && parseFloat(m[4]) < 0.1) { return null; }
        return [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
    }

    /** Wirksame Hintergrundfarbe: Eltern hochlaufen bis nicht-transparent; Default weiß */
    function effectiveBg(el, win) {
        var node = el;
        while (node && node.nodeType === 1) {
            var cs;
            try { cs = win.getComputedStyle(node); } catch (e) { return null; }
            if (cs.backgroundImage && cs.backgroundImage.includes('url(')) { return null; } // Bild-Hintergrund → nicht bewertbar
            var c = parseColor(cs.backgroundColor);
            if (c) { return c; }
            node = node.parentElement;
        }
        return [255, 255, 255];
    }

    /** WCAG-Kontrastverhältnis zweier RGB-Farben */
    function contrastRatio(a, b) {
        function lum(c) {
            var v = c.map(function (x) {
                x /= 255;
                return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
            });
            return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
        }
        var l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }

    /**
     * Fragentext selbst prüfen: In ILIAS steht der Fragentext in .ilc_qtitle_Title
     * (Formelfrage: .ilc_question_FormulaQuestion > .ilc_qanswer_Answer). Fehlt er
     * oder ist er extrem kurz UND ohne Bild/Formel, wird die Frage faktisch ohne
     * erkennbaren Text angezeigt — häufige Import-/Editier-Panne.
     */
    function checkQuestionText(container, findings) {
        var qtitle = container.querySelector('.ilc_qtitle_Title')
            || container.querySelector('.ilc_question_FormulaQuestion > .ilc_qanswer_Answer');

        // Kein bekannter Fragentext-Container → nicht zuverlässig prüfbar, nicht melden
        if (!qtitle) { return; }

        var txt   = (qtitle.textContent || '').replace(/\s+/g, ' ').trim();
        var media = qtitle.querySelector('img, video, audio, canvas, svg, iframe, object, embed, mjx-container, .MathJax');

        if (txt.length === 0 && !media) {
            findings.push({
                check: 'missing_questiontext', level: 'error',
                message: 'Frage hat keinen Fragentext',
                detail: ''
            });
        } else if (txt.length > 0 && txt.split(' ').length < 5 && !media) {
            findings.push({
                check: 'short_questiontext', level: 'warning',
                message: 'Fragentext ist sehr kurz (unter 5 Wörter) — evtl. unvollständig',
                detail: '„' + txt + '"'
            });
        }
    }

    function checkLayout(doc, container, findings) {
        // Delos scrollt in .il-layout-page-content — horizontaler Overflow landet dort
        var sc = getScroller(doc) || doc.documentElement;

        if (sc.scrollWidth > sc.clientWidth + 4) {
            findings.push({
                check: 'h_overflow', level: 'warning',
                message: 'Seite hat horizontalen Overflow (Scrollbalken)',
                detail: sc.scrollWidth + 'px Inhalt bei ' + sc.clientWidth + 'px Viewport'
            });
        }

        var cRect = container.getBoundingClientRect();
        var protruding = 0;
        var example = '';
        container.querySelectorAll('img, table, div, iframe, video, pre').forEach(function (el) {
            var r = el.getBoundingClientRect();
            if (r.width > 0 && r.right > cRect.right + 12) {
                protruding++;
                if (!example) {
                    example = '<' + el.tagName.toLowerCase() + '> '
                        + Math.round(r.right - cRect.right) + 'px über den Rand';
                }
            }
        });
        if (protruding > 0) {
            findings.push({
                check: 'protruding', level: 'warning',
                message: protruding + ' Element(e) ragen aus dem Fragenbereich',
                detail: example
            });
        }

        var text = (container.textContent || '').trim();
        var interactive = container.querySelectorAll('input, textarea, select, img, video, audio, canvas').length;
        if (text.length < 5 && interactive === 0) {
            findings.push({
                check: 'empty_question', level: 'error',
                message: 'Fragenbereich ist (nahezu) leer',
                detail: ''
            });
        }
    }

    function checkResources(findings) {
        try {
            var entries = els.frame.contentWindow.performance.getEntriesByType('resource');
            entries.forEach(function (e) {
                // responseStatus gibt es erst in neueren Browsern — opportunistisch nutzen
                if (typeof e.responseStatus === 'number' && e.responseStatus >= 400) {
                    findings.push({
                        check: 'http_error', level: 'error',
                        message: 'Ressource liefert HTTP ' + e.responseStatus,
                        detail: shortSrc(e.name)
                    });
                }
            });
        } catch (ignore) {}
    }

    // ─── Screenshots (Screen-Capture-API, segmentiert) ────────────────────────

    function startCapture() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            return Promise.resolve(false);
        }
        setHint(t('hint_capture'));
        return navigator.mediaDevices.getDisplayMedia({
            video: { displaySurface: 'browser' },
            audio: false,
            preferCurrentTab: true,          // Chrome: eigenen Tab vorschlagen
            selfBrowserSurface: 'include'
        }).then(function (stream) {
            captureStream = stream;
            captureVideo = document.createElement('video');
            captureVideo.style.position = 'fixed';
            captureVideo.style.left = '-99999px';
            captureVideo.muted = true;
            captureVideo.srcObject = stream;
            document.body.appendChild(captureVideo);
            stream.getVideoTracks()[0].addEventListener('ended', function () {
                captureStream = null;   // Nutzer hat Freigabe beendet → ohne Screenshots weiter
            });
            return captureVideo.play().then(function () { return true; });
        }).catch(function () {
            captureStream = null;
            return false;
        });
    }

    function stopCapture() {
        if (captureStream) {
            captureStream.getTracks().forEach(function (tr) { tr.stop(); });
            captureStream = null;
        }
        if (captureVideo) {
            captureVideo.remove();
            captureVideo = null;
        }
    }

    /**
     * Scroll-Container einer ILIAS-9-Seite: Delos scrollt nicht im document,
     * sondern in .il-layout-page-content. Fallback: scrollingElement (z.B. Kiosk).
     */
    function getScroller(doc) {
        return doc.querySelector('.il-layout-page-content') || null;
    }

    /**
     * Screenshot der gesamten Frage: scrollt den Inhalts-Container des Players
     * segmentweise durch und setzt die Frames auf einem Canvas zusammen.
     * Gibt {blob,w,h} oder null.
     */
    function takeScreenshot(doc) {
        if (!captureStream || !captureVideo || captureVideo.videoWidth === 0) {
            return Promise.resolve(null);
        }

        var win = els.frame.contentWindow;
        var scaleX = captureVideo.videoWidth / window.innerWidth;
        var scaleY = captureVideo.videoHeight / window.innerHeight;

        // Cockpit so scrollen, dass der iframe möglichst vollständig sichtbar ist
        try { els.frame.scrollIntoView({ block: 'start' }); } catch (ignore) {}
        var rect = els.frame.getBoundingClientRect();

        // Scroll-/Mess-Element im Player bestimmen
        var scroller = getScroller(doc);
        var isRoot = !scroller;
        var band; // sichtbarer Content-Bereich in Tab-Koordinaten (CSS px)
        if (isRoot) {
            scroller = doc.scrollingElement || doc.documentElement;
            band = { top: rect.top, left: rect.left, width: rect.width, height: win.innerHeight || rect.height };
        } else {
            var sRect = scroller.getBoundingClientRect(); // iframe-interne Koordinaten
            band = {
                top: rect.top + sRect.top,
                left: rect.left + sRect.left,
                width: sRect.width,
                height: sRect.height
            };
        }

        var visTop    = Math.max(band.top, 0);
        var visBottom = Math.min(band.top + band.height, rect.bottom, window.innerHeight);
        var ch        = visBottom - visTop;              // erfasste Content-Höhe pro Segment
        // Linke Kante sauber clampen (Quell-X UND Breite), sonst horizontaler Versatz
        var srcLeft   = Math.max(0, band.left);
        var effWidth  = band.width - (srcLeft - band.left);
        if (ch < 50 || effWidth < 50) {
            return Promise.resolve(null);
        }

        var contentH = Math.max(scroller.scrollHeight, ch);
        var segments = Math.min(cfg.maxSegments, Math.max(1, Math.ceil(contentH / ch)));
        var totalH   = Math.min(contentH, segments * ch);

        var canvas = document.createElement('canvas');
        canvas.width  = Math.round(effWidth * scaleX);
        canvas.height = Math.round(totalH * scaleY);
        var ctx = canvas.getContext('2d');

        var seg = 0;

        function grabSegment() {
            return new Promise(function (resolve) {
                scroller.scrollTop = seg * ch;
                setTimeout(function () {
                    // Ziel-Y = tatsächliche Scrollposition → keine Lücke/kein schwarzer
                    // Balken, auch wenn die Container-Oberkante über dem Viewport liegt.
                    var actualY = scroller.scrollTop || 0;
                    try {
                        ctx.drawImage(
                            captureVideo,
                            Math.round(srcLeft * scaleX), Math.round(visTop * scaleY),
                            Math.round(effWidth * scaleX), Math.round(ch * scaleY),
                            0, Math.round(actualY * scaleY),
                            Math.round(effWidth * scaleX), Math.round(ch * scaleY)
                        );
                    } catch (ignore) {}
                    seg++;
                    resolve();
                }, 250);
            });
        }

        var chain = Promise.resolve();
        for (var i = 0; i < segments; i++) {
            chain = chain.then(grabSegment);
        }

        return chain.then(function () {
            scroller.scrollTop = 0;
            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) {
                    resolve(blob ? { blob: blob, w: canvas.width, h: canvas.height } : null);
                }, 'image/jpeg', 0.85);
            });
        }).catch(function () {
            return null;
        });
    }

    // ─── Upload + Weiter-Navigation ───────────────────────────────────────────

    function uploadResult(title, qtype, findings, shot) {
        findings.forEach(function (f) {
            if (f.level === 'error') { totalErrors++; }
            if (f.level === 'warning') { totalWarnings++; }
        });
        updateProgress();

        var body = new FormData();
        body.append('epf_token', cfg.csrfToken);
        body.append('run_id', String(runId));
        body.append('position', String(position));
        body.append('title', title);
        body.append('qtype', qtype);
        body.append('checks', JSON.stringify(findings));
        try {
            body.append('page_url', String(els.frame.contentWindow.location.href));
        } catch (ignore) {}
        if (shot) {
            body.append('shot', shot.blob, 'shot.jpg');
            body.append('shot_w', String(shot.w));
            body.append('shot_h', String(shot.h));
        }

        return fetch(cfg.reportUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) { throw new Error('http ' + r.status); }
                return r.json();
            })
            .then(function (data) {
                return (data && data.status) ? data.status
                    : (findings.some(function (f) { return f.level === 'error'; }) ? 'fail'
                        : (findings.some(function (f) { return f.level === 'warning'; }) ? 'warn' : 'ok'));
            })
            .catch(function () {
                // Upload fehlgeschlagen → im Cockpit sichtbar machen; die Frage fehlt
                // sonst still im Server-Bericht (Zählerdiskrepanz).
                uploadFailures++;
                setHint(t('hint_upload_failed'));
                return 'warn';
            });
    }

    function clickNext(doc) {
        var btn = doc.querySelector('.ilTstNavigationBtn_Next button, .ilTstNavigationBtn_Next a');
        if (!btn) {
            // Keine Weiter-Schaltfläche → letzte Frage erreicht, Lauf abschließen.
            // Der Test wird bewusst NICHT automatisch beendet/abgegeben.
            finishRun('completed');
            return;
        }

        var nav = { happened: false };
        var posAtClick = position;
        try {
            els.frame.contentWindow.addEventListener('pagehide', function () { nav.happened = true; }, { once: true });
        } catch (ignore) {}

        function navigated() {
            return nav.happened || position !== posAtClick;
        }

        btn.click();

        // ILIAS kann statt zu navigieren einen Bestätigungs-Modal zeigen
        // (z.B. "Antwort festschreiben") → dessen Bestätigen-Button klicken.
        // WICHTIG: Nur ECHT SICHTBARE Modals (geschlossene sind nur per Stylesheet
        // versteckt und lägen sonst im Selektor!) und NIEMALS einen Button, der den
        // Test beendet/abgibt — das "Test beenden"-Modal liegt immer mit im DOM.
        setTimeout(function () {
            if (navigated() || state !== 'running') { return; }
            var d = frameDoc();
            if (!d) { return; }
            var modal = null;
            d.querySelectorAll('.modal, .il-modal-roundtrip, dialog').forEach(function (m) {
                if (!modal && isElementVisible(m, d)) { modal = m; }
            });
            if (!modal) { return; }
            var confirmBtn = modal.querySelector('.btn-primary, button[name*="cmd"], input[type=submit]');
            if (!confirmBtn) { return; }
            var haystack = (confirmBtn.name || '')
                + ' ' + (confirmBtn.getAttribute('onclick') || '')
                + ' ' + ((confirmBtn.form && confirmBtn.form.action) || '');
            if (/finish|suspend|submit.*test/i.test(haystack)) { return; }
            confirmBtn.click();
        }, 2000);

        // Wenn nach längerer Zeit gar nichts passiert ist: pausieren statt hängen
        setTimeout(function () {
            if (!navigated() && state === 'running' && !processingPage) {
                pausedRequested = true;
                setState('paused');
                setHint(t('hint_non_question'));
            }
        }, 8000);
    }

    window.ExamPreflightCockpit = { init: init };
})();
