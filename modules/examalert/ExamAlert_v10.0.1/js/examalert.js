/**
 * examalert.js - KORRIGIERTE VERSION - Mit korrekten Zeilenumbrüchen
 * Diskrete Toast-Benachrichtigung ohne Ton
 */

(function() {
    'use strict';

    // Minimales Logging (nur in Debug-Modus)
    const DEBUG = false; // Setzen Sie auf true für Debugging
    const log = DEBUG ? (msg) => console.log('[ExamAlert] ' + msg) : () => {};

    class ExamAlertManager {
        constructor() {
            this.config = null;
            this.testEndTime = null;
            this.alertShown = false;
            this.checkInterval = null;
            this.isInitialized = false;
            this.countdownInterval = null;
            this.storageKey = null; // Für "nur einmal anzeigen"
        }

        init() {
            log('Initialisiere ExamAlert...');

            // Prüfe ob "Test beenden" bereits geklickt wurde
            const currentUrl = window.location.href;
            if (currentUrl.includes('cmd=finishTest')) {
                log('Test wird gerade beendet - keine Warnung mehr anzeigen');
                return false;
            }

            // active_id aus URL extrahieren für eindeutigen Storage-Key
            const urlParams = new URLSearchParams(window.location.search);
            const activeId = urlParams.get('active_id');
            
            if (!activeId) {
                log('Keine active_id gefunden - kann Warnung nicht eindeutig zuordnen');
                // Trotzdem weitermachen, aber ohne "nur einmal"-Check
            }

            // Storage-Key mit active_id bilden
            const storageKey = activeId ? `examalert_shown_${activeId}` : `examalert_shown_${window.ExamAlert?.refId || 'unknown'}`;
            
            // Prüfe ob Warnung für diese active_id bereits angezeigt wurde
            if (sessionStorage.getItem(storageKey) === 'true') {
                log(`Warnung wurde bereits für active_id ${activeId} angezeigt - überspringe`);
                return false;
            }

            // Konfiguration prüfen
            if (!window.ExamAlert?.config?.enabled) {
                log('ExamAlert nicht aktiviert oder konfiguriert');
                return false;
            }

            this.config = window.ExamAlert.config;
            this.storageKey = storageKey;
            log(`Konfiguration: ${this.config.alert_time}s vor Ende, Storage-Key: ${storageKey}`);

            // ILIAS Timer-Variablen verwenden
            if (!this.detectILIASTestEnd()) {
                log('ILIAS Testende nicht gefunden');
                return false;
            }

            log(`Testende: ${new Date(this.testEndTime).toLocaleTimeString()}`);
            this.startMonitoring();
            
            this.isInitialized = true;
            return true;
        }

        /**
         * Nutzt ILIAS 9 Timer-System
         */
        detectILIASTestEnd() {
            // Methode 1: ILIAS test_end Variable
            if (typeof window.test_end === 'number' && window.test_end > 0) {
                this.testEndTime = window.test_end * 1000;
                log('Testende via window.test_end gefunden');
                return true;
            }

            // Methode 2: ILIAS Timer-Element auslesen
            const timeElement = document.querySelector('.ilTstWorkingFormInfo_ProcessTimeLeft, .timeleft, [class*="timeleft"]');
            if (timeElement) {
                const timeText = timeElement.textContent?.trim();
                log(`Zeit-Element gefunden: "${timeText}"`);
                
                // Parse "Es verbleiben Ihnen noch X Minuten Y Sekunden"
                const match = timeText.match(/(\d+)\s*(?:Minuten?|min)\s*(?:(?:und\s*)?(\d+)\s*(?:Sekunden?|sec)?)?|(\d+)\s*(?:Sekunden?|sec)/i);
                if (match) {
                    const minutes = parseInt(match[1] || 0);
                    const seconds = parseInt(match[2] || match[3] || 0);
                    const totalSeconds = minutes * 60 + seconds;
                    
                    if (totalSeconds > 0) {
                        this.testEndTime = Date.now() + (totalSeconds * 1000);
                        log(`Testende via Text-Parsing: ${minutes}m ${seconds}s`);
                        return true;
                    }
                }
            }

            // Methode 3: ILIAS versteckte Felder
            const testEndInput = document.querySelector('input[name*="test_end"], input[name*="end_time"]');
            if (testEndInput && testEndInput.value) {
                const endTime = parseInt(testEndInput.value);
                if (endTime > Date.now() / 1000) {
                    this.testEndTime = endTime * 1000;
                    log('Testende via verstecktes Feld gefunden');
                    return true;
                }
            }

            return false;
        }

        startMonitoring() {
            log('Starte Zeit-Monitoring...');
            
            // Prüfe alle 10 Sekunden (ressourcenschonend)
            this.checkInterval = setInterval(() => {
                this.checkTime();
            }, 10000);

            // Sofortige Prüfung
            this.checkTime();

            // Zusätzliche Prüfung 5 Sekunden vor Alert-Zeit
            const preAlertTime = Math.max(5000, (this.config.alert_time - 5) * 1000);
            setTimeout(() => {
                if (!this.alertShown) {
                    this.checkTime();
                    // Dann jede Sekunde prüfen für Präzision
                    if (this.checkInterval) {
                        clearInterval(this.checkInterval);
                    }
                    this.checkInterval = setInterval(() => this.checkTime(), 1000);
                }
            }, this.getTimeUntilAlert() - preAlertTime);
        }

        checkTime() {
            if (this.alertShown) return;

            const now = Date.now();
            const timeRemaining = this.testEndTime - now;
            const secondsRemaining = Math.floor(timeRemaining / 1000);

            // Debug-Log nur alle 60 Sekunden
            if (DEBUG && secondsRemaining % 60 === 0) {
                log(`Noch ${Math.floor(secondsRemaining / 60)}:${(secondsRemaining % 60).toString().padStart(2, '0')}`);
            }

            // Alert anzeigen
            if (secondsRemaining <= this.config.alert_time && secondsRemaining > 0) {
                this.showAlert();
            }

            // Monitoring beenden
            if (secondsRemaining <= 0) {
                this.stopMonitoring();
            }
        }

        getTimeUntilAlert() {
            const now = Date.now();
            const timeRemaining = this.testEndTime - now;
            return timeRemaining - (this.config.alert_time * 1000);
        }

        showAlert() {
            if (this.alertShown) return;
            this.alertShown = true;

            // Markiere als "bereits angezeigt" für diese active_id
            if (this.storageKey) {
                sessionStorage.setItem(this.storageKey, 'true');
                log(`Warnung für ${this.storageKey} gespeichert`);
            }

            log('Zeige diskrete Zeit-Benachrichtigung');

            const now = Date.now();
            const timeRemaining = this.testEndTime - now;
            const secondsRemaining = Math.floor(timeRemaining / 1000);
            // XSS-Schutz: Meldung escapen, erlaubte Format-Tags bleiben erhalten
            const messageHtml = this.escapeHtml(this.config.alert_message || 'Achtung: Der Test endet bald!');

            const toastHTML = `
                <div id="exam-alert-toast" style="
                    position: fixed;
                    top: -100px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #FFE9B3;
                    color: black;
                    padding: 1.5rem 2rem;
                    border-radius: 12px;
                    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
                    z-index: 99999;
                    font-family: system-ui, -apple-system, sans-serif;
                    font-size: 16px;
                    font-weight: 500;
                    text-align: center;
                    min-width: 350px;
                    max-width: 90vw;
                    border: 2px solid #FFB347;
                    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                ">
                    <div style="display: flex; align-items: center; justify-content: center; gap: 12px;">
                        <span style="font-size: 24px;"></span>
                        <div style="text-align: left;">
                            <div style="font-weight: 600; margin-bottom: 8px; color: black;">Zeit-Warnung</div>
                            <div style="font-size: 14px; line-height: 1.5; white-space: pre-line; color: black; font-weight: normal;">
${messageHtml}</div>
                            <div style="font-size: 13px; margin-top: 12px; padding: 6px 12px; background: rgba(0,0,0,0.1); border-radius: 20px; display: inline-block;">
                                <strong id="exam-alert-countdown" style="color: black;">Noch ${secondsRemaining} Sekunden</strong>
                            </div>
                        </div>
                        <button onclick="window.ExamAlert.instance.closeToast()" style="
                            background: rgba(0, 0, 0, 0.1);
                            border: 1px solid rgba(0, 0, 0, 0.2);
                            color: black;
                            width: 28px;
                            height: 28px;
                            border-radius: 50%;
                            cursor: pointer;
                            font-size: 16px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            margin-left: 8px;
                            transition: all 0.2s;
                            flex-shrink: 0;
                        " onmouseover="this.style.background='rgba(0,0,0,0.2)'" 
                           onmouseout="this.style.background='rgba(0,0,0,0.1)'">×</button>
                    </div>
                </div>

                <style>
                @keyframes slideInFromTop {
                    from {
                        top: -100px;
                        opacity: 0;
                    }
                    to {
                        top: 20px;
                        opacity: 1;
                    }
                }

                @keyframes pulse {
                    0%, 100% { box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15); }
                    50% { box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25); }
                }

                #exam-alert-toast {
                    animation: slideInFromTop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards,
                               pulse 2s ease-in-out infinite;
                }

                @media (max-width: 480px) {
                    #exam-alert-toast {
                        min-width: auto;
                        max-width: 95vw;
                        padding: 1rem 1.5rem;
                        font-size: 14px;
                    }
                }
                </style>
            `;

            document.body.insertAdjacentHTML('beforeend', toastHTML);

            setTimeout(() => {
                const toast = document.getElementById('exam-alert-toast');
                if (toast) {
                    toast.style.top = '20px';
                }
            }, 100);

            this.startCountdown();
        }

        startCountdown() {
            // Countdown alle Sekunde aktualisieren
            this.countdownInterval = setInterval(() => {
                const countdownElement = document.getElementById('exam-alert-countdown');
                if (!countdownElement) {
                    // Element nicht mehr da, Interval stoppen
                    this.stopCountdown();
                    return;
                }

                const now = Date.now();
                const timeRemaining = this.testEndTime - now;
                const secondsRemaining = Math.floor(timeRemaining / 1000);

                if (secondsRemaining <= 0) {
                    this.stopCountdown();
                    return;
                }

                // Format: Minuten:Sekunden falls > 60s, sonst nur Sekunden
                if (secondsRemaining >= 60) {
                    const minutes = Math.floor(secondsRemaining / 60);
                    const seconds = secondsRemaining % 60;
                    countdownElement.innerHTML = `Noch ${minutes}:${seconds.toString().padStart(2, '0')} Minuten`;
                } else {
                    countdownElement.innerHTML = `Noch ${secondsRemaining} Sekunden`;
                }
            }, 1000);
        }

        stopCountdown() {
            if (this.countdownInterval) {
                clearInterval(this.countdownInterval);
                this.countdownInterval = null;
            }
        }

        closeToast() {
            const toast = document.getElementById('exam-alert-toast');
            if (toast) {
                toast.style.top = '-100px';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                }, 400);
            }
            this.stopCountdown();
        }

        stopMonitoring() {
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
                this.checkInterval = null;
            }
            this.stopCountdown();
            log('Monitoring gestoppt');
        }

        escapeHtml(text) {
            // Erlaube sichere HTML-Tags: <strong>, <b>, <u>, <i>, <br>
            const div = document.createElement('div');
            div.textContent = text;
            let escaped = div.innerHTML;
            
            // Ersetze escaped Tags zurück zu echten Tags (nur sichere!)
            escaped = escaped.replace(/&lt;strong&gt;/gi, '<strong>');
            escaped = escaped.replace(/&lt;\/strong&gt;/gi, '</strong>');
            escaped = escaped.replace(/&lt;b&gt;/gi, '<b>');
            escaped = escaped.replace(/&lt;\/b&gt;/gi, '</b>');
            escaped = escaped.replace(/&lt;u&gt;/gi, '<u>');
            escaped = escaped.replace(/&lt;\/u&gt;/gi, '</u>');
            escaped = escaped.replace(/&lt;i&gt;/gi, '<i>');
            escaped = escaped.replace(/&lt;\/i&gt;/gi, '</i>');
            escaped = escaped.replace(/&lt;br&gt;/gi, '<br>');
            escaped = escaped.replace(/&lt;br\/&gt;/gi, '<br/>');
            escaped = escaped.replace(/&lt;br \/&gt;/gi, '<br />');
            
            return escaped;
        }
    }

    // Initialisierung - warte auf ILIAS Timer
    function initExamAlert() {
        // Warte bis ILIAS Timer-Variablen verfügbar sind
        let attempts = 0;
        const maxAttempts = 50; // 5 Sekunden maximal warten

        const tryInit = () => {
            attempts++;
            
            if (window.test_end || document.querySelector('.ilTstWorkingFormInfo_ProcessTimeLeft, .timeleft')) {
                // ILIAS Timer gefunden
                const manager = new ExamAlertManager();
                if (manager.init()) {
                    window.ExamAlert.instance = manager;
                    log('ExamAlert erfolgreich initialisiert');
                } else {
                    log('ExamAlert-Initialisierung fehlgeschlagen');
                }
            } else if (attempts < maxAttempts) {
                // Noch nicht bereit, nochmal versuchen
                setTimeout(tryInit, 100);
            } else {
                log('ILIAS Timer-System nicht gefunden nach 5 Sekunden');
            }
        };

        tryInit();
    }

    // DOM Ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initExamAlert);
    } else {
        initExamAlert();
    }

    // Fallback für window.load
    window.addEventListener('load', () => {
        if (!window.ExamAlert?.instance?.isInitialized) {
            setTimeout(initExamAlert, 500);
        }
    });

})();