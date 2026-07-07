<?php
/**
 * ExamArchiver Handler - Rechtssichere Archivierung von Tests (Antworten, Punkte, Noten)
 * Part of ExtendedTestSettings Plugin
 */

require_once __DIR__ . '/../../classes/Contracts/ilExamModuleInterface.php';
require_once __DIR__ . '/../../classes/Module/ilModuleLanguage.php';

class ilExamArchiverHandler implements ilExamModuleInterface
{
    private const TABLE = 'examarchiver_archives';

    private ilModuleLanguage $lng;

    public function __construct()
    {
        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'ExamArchiver',
            'title'       => $this->lng->txt('title'),
            'icon'        => '',
            'description' => $this->lng->txt('description'),
            'version'     => '1.0.0',
        ];
    }

    public function isEnabled(int $ref_id): bool
    {
        // Archivierung ist eine Admin-Funktion — aktiv sobald das Modul global aktiv ist
        return true;
    }

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        // Kein JS/CSS im Testplayer — Archivierung läuft rein serverseitig in der Admin-GUI
    }

    public function saveSettings(int $ref_id, array $post_data): bool
    {
        // Keine test-spezifischen Einstellungen
        return true;
    }

    public function getFormItems(int $ref_id): array
    {
        $items = [];

        $header = new ilFormSectionHeaderGUI();
        $header->setTitle($this->lng->txt('title'));
        $items[] = $header;

        $status = new ilCustomInputGUI($this->lng->txt('status_label'), '');
        $status->setHtml($this->buildStatusHtml($ref_id));
        $items[] = $status;

        return $items;
    }

    /**
     * Status des letzten Archivs dieses Tests + Link zur Archiv-Verwaltung.
     */
    private function buildStatusHtml(int $ref_id): string
    {
        global $DIC;

        $line = $this->lng->txt('status_none');

        try {
            $db     = $DIC->database();
            $obj_id = (int) ilObject::_lookupObjectId($ref_id);

            if ($obj_id > 0 && $db->tableExists(self::TABLE)) {
                $res = $db->queryF(
                    "SELECT a.created_at, a.done_count, a.status, u.login
                     FROM " . self::TABLE . " a
                     LEFT JOIN usr_data u ON u.usr_id = a.created_by
                     WHERE a.obj_id = %s AND a.status = %s
                     ORDER BY a.created_at DESC",
                    ['integer', 'text'],
                    [$obj_id, 'done']
                );
                $row = $db->fetchAssoc($res);
                if ($row) {
                    $line = sprintf(
                        $this->lng->txt('status_last'),
                        date('d.m.Y H:i', (int) $row['created_at']),
                        htmlspecialchars($row['login'] ?? '?'),
                        (int) $row['done_count']
                    );
                }
            }
        } catch (Exception $e) {
            error_log('[ExamArchiver] buildStatusHtml Fehler: ' . $e->getMessage());
        }

        $url = ILIAS_HTTP_PATH . '/ilias.php?baseClass=ilUIPluginRouterGUI'
             . '&cmdClass=ilextendedtestsettingsmoduleproxygui'
             . '&module_id=examarchiver&cmd=showArchives&ref_id=' . $ref_id;

        return '<p>' . $line . '</p>'
             . '<p><a class="btn btn-default btn-sm" href="' . htmlspecialchars($url) . '">'
             . $this->lng->txt('open_manager') . '</a></p>'
             . '<p class="text-muted" style="font-size:12px;">' . $this->lng->txt('status_info') . '</p>';
    }
}
