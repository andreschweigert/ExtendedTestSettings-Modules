<?php

/**
 * ExamResourceSheet Handler - Hilfsmittelblatt (PDF/HTML) im Test-Player
 * Part of ExtendedTestSettings Plugin
 */
class ilExamResourceSheetHandler implements ilExamModuleInterface
{
    private string $plugin_path;

    private const MAX_FILESIZE = 10 * 1024 * 1024; // 10 MB
    private const ALLOWED_MIMETYPES = ['application/pdf', 'text/html'];
    private const ALLOWED_EXTENSIONS = ['pdf', 'html', 'htm'];
    private const TABLE = 'examressheet_settings';

    private ilModuleLanguage $lng;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);

        $this->ensureTableExists();
    }

    public function isEnabled(int $ref_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            if ($test_id <= 0) {
                return false;
            }

            $result = $db->queryF(
                "SELECT enabled FROM " . self::TABLE . " WHERE test_id = %s",
                ['integer'],
                [$test_id]
            );

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            return false;
        } catch (Exception $e) {
            error_log("[ExamResourceSheet] isEnabled Fehler: " . $e->getMessage());
            return false;
        }
    }

    public function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET['cmdClass'] ?? '');

        $player_classes = [
            'iltestplayer',
            'iltestplayerfixedquestionsetgui',
            'iltestplayerrandquestionsetgui',
            'iltestplayergui',
            'iltestplayercommandsgui',
            'iltestoutputgui',
        ];

        foreach ($player_classes as $cls) {
            if (str_contains($cmd_class, $cls)) {
                return true;
            }
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $url_patterns = ['cmd=startPlayer', 'cmd=resumePlayer', 'cmd=showQuestion', 'cmd=nextQuestion'];

        foreach ($url_patterns as $pattern) {
            if (str_contains($request_uri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            $result = $db->queryF(
                "SELECT label, filename, stored_name, mimetype FROM " . self::TABLE .
                " WHERE test_id = %s AND enabled = 1",
                ['integer'],
                [$test_id]
            );

            $row = $db->fetchAssoc($result);

            if (!$row || empty($row['stored_name'])) {
                return;
            }

            // Token aus stored_name extrahieren: "{test_id}_{token}.{ext}"
            $basename       = pathinfo($row['stored_name'], PATHINFO_FILENAME); // "{test_id}_{token}"
            $underscore_pos = strpos($basename, '_');
            if ($underscore_pos === false) {
                return;
            }
            $token = substr($basename, $underscore_pos + 1); // 32-char hex

            $file_url = ILIAS_HTTP_PATH . '/ilias.php'
                . '?baseClass=ilUIPluginRouterGUI'
                . '&cmdClass=ilextendedtestsettingsmoduleproxygui'
                . '&cmd=serveResourceFile'
                . '&ref_id=' . $ref_id
                . '&token=' . urlencode($token);

            $config = [
                'enabled'      => true,
                'label'        => $row['label'] ?: 'Hilfsmittel',
                'mimetype'     => $row['mimetype'],
                'filename'     => $row['filename'],
                'fileUrl'      => $file_url,
                'pdfWorkerSrc' => '',
            ];

            $css_path = $this->plugin_path . '/modules/examresourcesheet/css/examresourcesheet.css';
            $tpl->addCss($css_path);

            // PDF.js nur laden wenn die Datei ein PDF ist (spart Bandbreite bei HTML-Dateien)
            if ($row['mimetype'] === 'application/pdf') {
                $tpl->addJavaScript($this->plugin_path . '/modules/examresourcesheet/js/pdf.min.js');

                // Absoluter HTTP-Pfad zum Worker — Browser-Worker benötigt URL, keinen relativen Pfad
                $clean = ltrim(str_replace('./', '', $this->plugin_path), '/');
                $config['pdfWorkerSrc'] = rtrim(ILIAS_HTTP_PATH, '/')
                    . '/' . $clean
                    . '/modules/examresourcesheet/js/pdf.worker.min.js';
            }

            $js_path = $this->plugin_path . '/modules/examresourcesheet/js/examresourcesheet.js';
            $tpl->addJavaScript($js_path);

            $config_json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

            $tpl->addOnLoadCode(
                "window.ExamResourceSheet = window.ExamResourceSheet || {};" .
                "window.ExamResourceSheet.config = $config_json;"
            );

        } catch (Exception $e) {
            error_log("[ExamResourceSheet] injectAssets Fehler: " . $e->getMessage());
        }
    }

    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            if ($test_id <= 0) {
                return false;
            }

            // Checkbox-Wert: aus $post_data ODER direkt aus $_POST (Fallback bei multipart)
            // AdvancedGUI nutzt getParsedBody() — bei multipart/form-data kommt $_POST zurück.
            // Zur Sicherheit: $_POST direkt prüfen wenn $post_data den Key nicht enthält.
            $enabled_raw = $post_data['examresourcesheet_enabled']
                ?? $_POST['examresourcesheet_enabled']
                ?? null;
            $enabled = ($enabled_raw !== null && $enabled_raw !== '' && $enabled_raw !== '0') ? 1 : 0;

            $label_raw = $post_data['examresourcesheet_label']
                ?? $_POST['examresourcesheet_label']
                ?? 'Hilfsmittel';
            $label = trim((string) $label_raw);
            if ($label === '') {
                $label = 'Hilfsmittel';
            }
            if (mb_strlen($label) > 100) {
                $label = mb_substr($label, 0, 100);
            }

            // Bestehende Einstellungen laden (für alten Dateinamen)
            $result   = $db->queryF(
                "SELECT stored_name FROM " . self::TABLE . " WHERE test_id = %s",
                ['integer'],
                [$test_id]
            );
            $existing = $db->fetchAssoc($result);

            $new_file = false;
            $stored_name_new = null;
            $filename_new    = null;
            $mimetype_new    = null;
            $filesize_new    = null;

            // Datei-Upload prüfen
            $upload = $_FILES['examresourcesheet_file'] ?? null;
            if (
                $upload !== null
                && isset($upload['tmp_name'])
                && $upload['error'] === UPLOAD_ERR_OK
                && $upload['size'] > 0
            ) {
                // Größe prüfen
                if ($upload['size'] > self::MAX_FILESIZE) {
                    error_log("[ExamResourceSheet] Upload abgelehnt: Datei zu groß ({$upload['size']} Bytes)");
                    // Nicht false zurückgeben — nur den Upload überspringen, Enabled/Label trotzdem speichern
                } else {
                    // MIME-Type ermitteln (serverseitig)
                    $detected_mime = $this->detectMimeType($upload['tmp_name']);
                    $orig_name     = $upload['name'] ?? 'upload';
                    $orig_ext      = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

                    if (
                        in_array($detected_mime, self::ALLOWED_MIMETYPES, true)
                        && in_array($orig_ext, self::ALLOWED_EXTENSIONS, true)
                    ) {
                        $data_dir = $this->getStorageDir();
                        if (!is_dir($data_dir)) {
                            mkdir($data_dir, 0755, true);
                        }

                        $token      = substr(hash('sha256', $test_id . '_' . time() . '_' . bin2hex(random_bytes(8))), 0, 32);
                        $ext        = ($detected_mime === 'application/pdf') ? 'pdf' : 'html';
                        $new_stored = $test_id . '_' . $token . '.' . $ext;
                        $dest       = $data_dir . $new_stored;

                        if (move_uploaded_file($upload['tmp_name'], $dest)) {
                            // Alte Datei löschen
                            if (!empty($existing['stored_name'])) {
                                $old_file = $data_dir . $existing['stored_name'];
                                if (is_file($old_file)) {
                                    @unlink($old_file);
                                }
                            }
                            $stored_name_new = $new_stored;
                            $filename_new    = basename($orig_name);
                            $mimetype_new    = $detected_mime;
                            $filesize_new    = (int) $upload['size'];
                            $new_file        = true;
                        } else {
                            error_log("[ExamResourceSheet] move_uploaded_file fehlgeschlagen nach: $dest");
                        }
                    } else {
                        error_log("[ExamResourceSheet] Upload abgelehnt: MIME='$detected_mime', Ext='$orig_ext'");
                    }
                }
            }

            // Speichern: INSERT wenn neu, UPDATE wenn bereits vorhanden.
            // NICHT db->replace() verwenden — das ist REPLACE INTO (DELETE+INSERT)
            // und würde filename/stored_name auf NULL setzen wenn kein Upload.
            $now = date('Y-m-d H:i:s');

            if ($existing === false || $existing === null) {
                // Erster Speichervorgang: INSERT
                $insert_data = [
                    'test_id'    => ['integer',   $test_id],
                    'enabled'    => ['integer',   $enabled],
                    'label'      => ['text',       $label],
                    'updated_at' => ['timestamp',  $now],
                ];
                if ($new_file) {
                    $insert_data['filename']    = ['text',    $filename_new];
                    $insert_data['stored_name'] = ['text',    $stored_name_new];
                    $insert_data['mimetype']    = ['text',    $mimetype_new];
                    $insert_data['filesize']    = ['integer', $filesize_new];
                }
                $db->insert(self::TABLE, $insert_data);
            } else {
                // Folgespeicherungen: UPDATE — nur geänderte Felder anfassen
                $update_data = [
                    'enabled'    => ['integer',   $enabled],
                    'label'      => ['text',       $label],
                    'updated_at' => ['timestamp',  $now],
                ];
                if ($new_file) {
                    $update_data['filename']    = ['text',    $filename_new];
                    $update_data['stored_name'] = ['text',    $stored_name_new];
                    $update_data['mimetype']    = ['text',    $mimetype_new];
                    $update_data['filesize']    = ['integer', $filesize_new];
                }
                $db->update(
                    self::TABLE,
                    $update_data,
                    ['test_id' => ['integer', $test_id]]
                );
            }

            return true;

        } catch (Exception $e) {
            error_log("[ExamResourceSheet] saveSettings Fehler: " . $e->getMessage());
            return false;
        }
    }

    public function getFormItems(int $ref_id): array
    {
        $row = $this->loadSettings($ref_id);

        $cb = new ilCheckboxInputGUI($this->lng->txt('enable'), 'examresourcesheet_enabled');
        $cb->setChecked((bool) ($row['enabled'] ?? false));
        $cb->setInfo($this->lng->txt('enable_info'));

        $label_field = new ilTextInputGUI($this->lng->txt('label_field'), 'examresourcesheet_label');
        $label_field->setValue($row['label'] ?? 'Hilfsmittel');
        $label_field->setMaxLength(100);
        $label_field->setInfo($this->lng->txt('label_field_info'));
        $cb->addSubItem($label_field);

        // Aktuellen Dateinamen anzeigen wenn vorhanden
        if (!empty($row['filename'])) {
            $size_kb    = isset($row['filesize']) ? round((int) $row['filesize'] / 1024) : '?';
            $info_field = new ilNonEditableValueGUI($this->lng->txt('current_file'), '');
            $info_field->setValue(htmlspecialchars($row['filename'], ENT_QUOTES) . ' (' . $size_kb . ' KB)');
            $cb->addSubItem($info_field);
        }

        // Datei-Upload als eigenes top-level Item zurückgeben,
        // damit ilPropertyFormGUI enctype="multipart/form-data" setzt.
        $file_field = new ilFileInputGUI(
            empty($row['filename']) ? $this->lng->txt('file_upload') : $this->lng->txt('file_replace'),
            'examresourcesheet_file'
        );
        $file_field->setSuffixes(['pdf', 'html', 'htm']);
        $file_field->setInfo($this->lng->txt('file_info'));

        return [$cb, $file_field];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'ExamResourceSheet',
            'title'       => $this->lng->txt('title'),
            'icon'        => '',
            'description' => $this->lng->txt('description'),
            'version'     => '1.2.1',
            'ilias_min'   => '9',
            'ilias_max'   => '9',
            'features'    => [
                'PDF- und HTML-Dokumente',
                'Overlay-Panel im Test-Player (rechts ausklappbar)',
                'Konfigurierbarer Button-Label',
                'Sichere Dateiablage außerhalb des Web-Roots',
                'Zugriffsschutz via Token-URL (nur aktive Test-Sessions)',
                'HTML-Anzeige in sandboxed iframe (keine JS-Ausführung)',
                'Alte Datei wird beim Ersetzen automatisch gelöscht',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    /**
     * Tabelle anlegen falls sie noch nicht existiert.
     * Wird im Konstruktor gerufen, damit direktes Ablegen in modules/ ohne ZIP-Installer funktioniert.
     */
    private function ensureTableExists(): void
    {
        global $DIC;
        try {
            $db = $DIC->database();
            if (!$db->tableExists(self::TABLE)) {
                $install_file = __DIR__ . '/sql/install.php';
                if (file_exists($install_file)) {
                    (function () use ($install_file, $db): void {
                        require $install_file;
                    })();
                    error_log("[ExamResourceSheet] Tabelle " . self::TABLE . " automatisch angelegt.");
                }
            }
        } catch (Throwable $e) {
            error_log("[ExamResourceSheet] ensureTableExists Fehler: " . $e->getMessage());
        }
    }

    private function loadSettings(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            if ($test_id <= 0) {
                return [];
            }

            $result = $db->queryF(
                "SELECT * FROM " . self::TABLE . " WHERE test_id = %s",
                ['integer'],
                [$test_id]
            );

            return $db->fetchAssoc($result) ?: [];
        } catch (Exception $e) {
            error_log("[ExamResourceSheet] loadSettings Fehler: " . $e->getMessage());
            return [];
        }
    }

    private function getStorageDir(): string
    {
        $base = defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : (ILIAS_DATA_DIR . '/' . CLIENT_ID);
        return rtrim($base, '/') . '/plugins/ExtendedTestSettings/resourcesheets/';
    }

    private function detectMimeType(string $tmp_path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp_path);
            finfo_close($finfo);
            if ($mime !== false) {
                if (str_starts_with($mime, 'text/html')) {
                    return 'text/html';
                }
                return $mime;
            }
        }

        // Fallback: magic bytes
        $handle = @fopen($tmp_path, 'rb');
        if ($handle) {
            $bytes = fread($handle, 5);
            fclose($handle);
            if ($bytes !== false && substr($bytes, 0, 4) === '%PDF') {
                return 'application/pdf';
            }
        }

        return 'application/octet-stream';
    }
}
