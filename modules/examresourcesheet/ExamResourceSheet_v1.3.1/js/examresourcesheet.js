(function () {
    'use strict';

    var BASE_WIDTH  = 440;
    var PANEL_MIN   = 240;
    var RESIZE_STEP = 80;
    var CANVAS_PAD  = 24;
    var STATE_KEY   = 'examresourcesheet_state';

    function maxPanelWidth() { return Math.round(window.innerWidth * 0.92); }

    function saveState(open, width) {
        try { sessionStorage.setItem(STATE_KEY, JSON.stringify({ open: open, width: width })); } catch (e) {}
    }

    function loadState() {
        try { return JSON.parse(sessionStorage.getItem(STATE_KEY)); } catch (e) { return null; }
    }

    function buildUI(cfg) {
        var label  = cfg.label || 'Hilfsmittel';
        var isText = cfg.mode === 'text';
        var isPdf  = !isText && cfg.mimetype === 'application/pdf';

        var blobUrl      = null;
        var pdfDoc       = null;
        var pdfBaseWidth = 0;
        var renderTasks  = [];
        var htmlIframe   = null;
        var isOpen       = false;
        var isResizing   = false;
        var dragStartX   = 0;
        var dragStartW   = 0;

        // ── Left-edge tab ─────────────────────────────────────────────
        var tab = document.createElement('button');
        tab.id   = 'examresourcesheet-tab';
        tab.type = 'button';
        tab.title = label;
        tab.setAttribute('aria-label', label + ' öffnen');
        tab.setAttribute('aria-expanded', 'false');
        tab.setAttribute('aria-controls', 'examresourcesheet-panel');

        var tabIcon = document.createElement('span');
        tabIcon.className = 'ers-tab-icon';
        tabIcon.setAttribute('aria-hidden', 'true');
        tabIcon.textContent = '📄';

        var tabLabel = document.createElement('span');
        tabLabel.className = 'ers-tab-label';
        tabLabel.textContent = label;

        tab.appendChild(tabIcon);
        tab.appendChild(tabLabel);

        // ── Panel ─────────────────────────────────────────────────────
        var panel = document.createElement('div');
        panel.id = 'examresourcesheet-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', label);
        panel.setAttribute('aria-hidden', 'true');

        // ── Header: [Titel] [−] [Breite] [+] [×] ─────────────────────
        var header = document.createElement('div');
        header.id = 'examresourcesheet-panel-header';

        var titleSpan = document.createElement('span');
        titleSpan.id = 'examresourcesheet-title';
        titleSpan.textContent = label + (cfg.filename ? ': ' + cfg.filename : '');

        var narrowBtn = document.createElement('button');
        narrowBtn.className = 'ers-resize-btn';
        narrowBtn.type = 'button';
        narrowBtn.textContent = '−';
        narrowBtn.title = 'Schmaler';
        narrowBtn.setAttribute('aria-label', 'Panel schmaler');

        var widthIndicator = document.createElement('span');
        widthIndicator.className = 'ers-width-level';
        widthIndicator.textContent = BASE_WIDTH + 'px';

        var widerBtn = document.createElement('button');
        widerBtn.className = 'ers-resize-btn';
        widerBtn.type = 'button';
        widerBtn.textContent = '+';
        widerBtn.title = 'Breiter';
        widerBtn.setAttribute('aria-label', 'Panel breiter');

        var closeBtn = document.createElement('button');
        closeBtn.id   = 'examresourcesheet-close-btn';
        closeBtn.type = 'button';
        closeBtn.innerHTML = '&#x2715;';
        closeBtn.setAttribute('aria-label', label + ' schließen');

        header.appendChild(titleSpan);
        header.appendChild(narrowBtn);
        header.appendChild(widthIndicator);
        header.appendChild(widerBtn);
        header.appendChild(closeBtn);

        // ── Scroll-Bereich ────────────────────────────────────────────
        var scrollArea = document.createElement('div');
        scrollArea.id = 'examresourcesheet-scroll-area';

        // ── Resize-Handle (rechter Rand) ──────────────────────────────
        var resizeHandle = document.createElement('div');
        resizeHandle.id = 'examresourcesheet-resize-handle';
        resizeHandle.setAttribute('aria-hidden', 'true');

        panel.appendChild(header);
        panel.appendChild(scrollArea);
        panel.appendChild(resizeHandle);
        document.body.appendChild(tab);
        document.body.appendChild(panel);

        // ── State wiederherstellen (ohne Animation) ───────────────────
        var saved     = loadState();
        var initWidth = (saved && saved.width) ? saved.width : BASE_WIDTH;

        panel.style.transition        = 'none';
        document.body.style.transition = 'none';

        panel.style.width             = initWidth + 'px';
        widthIndicator.textContent    = initWidth + 'px';
        narrowBtn.disabled            = initWidth <= PANEL_MIN;
        widerBtn.disabled             = initWidth >= maxPanelWidth();

        if (saved && saved.open) {
            isOpen = true;
            panel.style.left = '0';
            document.body.classList.add('ers-panel-active');
            document.body.style.marginLeft = initWidth + 'px';
            panel.setAttribute('aria-hidden', 'false');
            tab.setAttribute('aria-expanded', 'true');
            tab.setAttribute('aria-label', label + ' schließen');
            loadFile();
        } else {
            panel.style.left = '-' + (initWidth + 20) + 'px';
        }

        panel.getBoundingClientRect();  // reflow erzwingen
        panel.style.transition        = '';
        document.body.style.transition = '';

        // ── Öffnen / Schließen ────────────────────────────────────────
        function openPanel() {
            isOpen = true;
            document.body.classList.add('ers-panel-active');
            document.body.classList.remove('ers-resizing');
            panel.style.left = '0';
            document.body.style.marginLeft = panel.offsetWidth + 'px';
            panel.setAttribute('aria-hidden', 'false');
            tab.setAttribute('aria-expanded', 'true');
            tab.setAttribute('aria-label', label + ' schließen');
            saveState(true, panel.offsetWidth);
            loadFile();
        }

        function closePanel() {
            isOpen = false;
            document.body.classList.add('ers-panel-active');
            document.body.classList.remove('ers-resizing');
            panel.style.left = '-' + (panel.offsetWidth + 20) + 'px';
            document.body.style.marginLeft = '0';
            panel.setAttribute('aria-hidden', 'true');
            tab.setAttribute('aria-expanded', 'false');
            tab.setAttribute('aria-label', label + ' öffnen');
            tab.focus();
            saveState(false, panel.offsetWidth);
            setTimeout(function () { document.body.classList.remove('ers-panel-active'); }, 320);
        }

        // ── Breiten-Buttons ───────────────────────────────────────────
        function applyWidth(newW) {
            newW = Math.max(PANEL_MIN, Math.min(newW, maxPanelWidth()));
            panel.style.width          = newW + 'px';
            widthIndicator.textContent = newW + 'px';
            narrowBtn.disabled         = newW <= PANEL_MIN;
            widerBtn.disabled          = newW >= maxPanelWidth();
            if (isOpen) {
                document.body.style.marginLeft = newW + 'px';
            }
            saveState(isOpen, newW);
            if (isPdf && pdfDoc) rerenderPdf();
        }

        narrowBtn.addEventListener('click', function () { applyWidth(panel.offsetWidth - RESIZE_STEP); });
        widerBtn.addEventListener('click',  function () { applyWidth(panel.offsetWidth + RESIZE_STEP); });

        // ── PDF: Fit-to-Width + Text-Layer ───────────────────────────
        function rerenderPdf() {
            if (!pdfDoc || pdfBaseWidth === 0) return;

            renderTasks.forEach(function (t) { try { t.cancel(); } catch (e) {} });
            renderTasks = [];
            scrollArea.innerHTML = '';

            var scale = Math.max(0.3, (panel.offsetWidth - CANVAS_PAD) / pdfBaseWidth);

            for (var n = 1; n <= pdfDoc.numPages; n++) {
                (function (pageNum) {
                    var container = document.createElement('div');
                    container.className = 'ers-page-container';
                    scrollArea.appendChild(container);

                    var canvas = document.createElement('canvas');
                    canvas.className = 'ers-pdf-page';
                    container.appendChild(canvas);

                    pdfDoc.getPage(pageNum).then(function (page) {
                        var vp = page.getViewport({ scale: scale });

                        canvas.width        = vp.width;
                        canvas.height       = vp.height;
                        canvas.style.width  = vp.width  + 'px';
                        canvas.style.height = vp.height + 'px';
                        container.style.width  = vp.width  + 'px';
                        container.style.height = vp.height + 'px';

                        var renderTask = page.render({ canvasContext: canvas.getContext('2d'), viewport: vp });
                        renderTasks.push(renderTask);
                        renderTask.promise.catch(function (err) {
                            if (err && err.name !== 'RenderingCancelledException') {
                                console.error('[ExamResourceSheet] Canvas S.' + pageNum, err);
                            }
                        });

                        var textLayerDiv = document.createElement('div');
                        textLayerDiv.className = 'ers-text-layer';
                        textLayerDiv.style.width  = vp.width  + 'px';
                        textLayerDiv.style.height = vp.height + 'px';
                        textLayerDiv.style.setProperty('--scale-factor', vp.scale);
                        container.appendChild(textLayerDiv);

                        try {
                            var textTask = pdfjsLib.renderTextLayer({
                                textContentSource: page.streamTextContent(),
                                container: textLayerDiv,
                                viewport: vp,
                                textDivs: []
                            });
                            if (textTask && textTask.promise) {
                                renderTasks.push(textTask);
                                textTask.promise.catch(function (err) {
                                    if (err && err.name !== 'RenderingCancelledException') {
                                        console.error('[ExamResourceSheet] TextLayer S.' + pageNum, err);
                                    }
                                });
                            }
                        } catch (err) {
                            console.error('[ExamResourceSheet] renderTextLayer:', err);
                        }
                    }).catch(function (err) {
                        console.error('[ExamResourceSheet] getPage S.' + pageNum, err);
                    });
                })(n);
            }
        }

        // ── Resize-Drag ───────────────────────────────────────────────
        resizeHandle.addEventListener('mousedown', function (e) {
            isResizing = true;
            dragStartX = e.clientX;
            dragStartW = panel.offsetWidth;
            resizeHandle.classList.add('active');
            document.body.style.cursor     = 'col-resize';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            if (!isResizing) return;
            var newW = Math.max(PANEL_MIN, Math.min(dragStartW + (e.clientX - dragStartX), maxPanelWidth()));
            panel.style.transition         = 'none';
            panel.style.width              = newW + 'px';
            widthIndicator.textContent     = newW + 'px';
            document.body.classList.add('ers-resizing');
            if (isOpen) document.body.style.marginLeft = newW + 'px';
        });

        document.addEventListener('mouseup', function () {
            if (!isResizing) return;
            isResizing = false;
            resizeHandle.classList.remove('active');
            document.body.style.cursor     = '';
            document.body.style.userSelect = '';
            panel.style.transition         = '';
            document.body.classList.remove('ers-resizing');
            narrowBtn.disabled = panel.offsetWidth <= PANEL_MIN;
            widerBtn.disabled  = panel.offsetWidth >= maxPanelWidth();
            saveState(isOpen, panel.offsetWidth);
            if (isPdf && pdfDoc) rerenderPdf();
        });

        // ── Inhalt laden ──────────────────────────────────────────────
        function loadFile() {
            if (isText) {
                // contentHtml ist serverseitig sanitisiert (Whitelist strong/b/i/u/br)
                if (!scrollArea.querySelector('.ers-text-content')) {
                    var textDiv = document.createElement('div');
                    textDiv.className = 'ers-text-content';
                    textDiv.innerHTML = cfg.contentHtml || '';
                    scrollArea.appendChild(textDiv);
                }
                return;
            }

            if (blobUrl) {
                if (isPdf && pdfDoc) rerenderPdf();
                return;
            }

            fetch(cfg.fileUrl, { credentials: 'same-origin' })
                .then(function (resp) {
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    return resp.blob();
                })
                .then(function (blob) {
                    blobUrl = URL.createObjectURL(blob);

                    if (isPdf) {
                        pdfjsLib.GlobalWorkerOptions.workerSrc = cfg.pdfWorkerSrc;
                        // PDF.js 3.11.174: eval bei PDF-Schriftarten deaktivieren
                        // (CVE-2024-4367, sonst JS-Ausführung aus präparierten PDFs).
                        pdfjsLib.getDocument({ url: blobUrl, isEvalSupported: false }).promise
                            .then(function (pdf) { pdfDoc = pdf; return pdf.getPage(1); })
                            .then(function (p)   { pdfBaseWidth = p.getViewport({ scale: 1 }).width; rerenderPdf(); })
                            .catch(function (err) {
                                titleSpan.textContent = label + ' – PDF-Fehler';
                                console.error('[ExamResourceSheet]', err);
                            });
                    } else {
                        htmlIframe = document.createElement('iframe');
                        htmlIframe.title = label;
                        // Sicherheit: Das Hilfsmittelblatt ist ein statisches Dokument.
                        // Eine blob:-URL erbt die ILIAS-Origin — ohne sandbox würde
                        // <script> im hochgeladenen HTML in der Nutzer-Session laufen.
                        // sandbox="" (kein allow-scripts) unterbindet jegliche JS-Ausführung.
                        htmlIframe.setAttribute('sandbox', '');
                        htmlIframe.src   = blobUrl;
                        scrollArea.appendChild(htmlIframe);
                    }
                })
                .catch(function (err) {
                    titleSpan.textContent = label + ' – Fehler beim Laden';
                    console.error('[ExamResourceSheet]', err);
                });
        }

        tab.addEventListener('click', function () { isOpen ? closePanel() : openPanel(); });
        closeBtn.addEventListener('click', closePanel);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen) closePanel(); });
    }

    // Polling bis die Config von addOnLoadCode gesetzt wurde (max. 3 Sekunden).
    // addJavaScript läuft vor addOnLoadCode — beim ersten DOMContentLoaded noch nicht verfügbar.
    function waitForConfig(retries) {
        var cfg = window.ExamResourceSheet && window.ExamResourceSheet.config;
        if (cfg && cfg.enabled && (cfg.fileUrl || cfg.contentHtml)) { buildUI(cfg); return; }
        if (retries > 0) setTimeout(function () { waitForConfig(retries - 1); }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { waitForConfig(30); });
    } else {
        waitForConfig(30);
    }

})();
