<?php

/**
 * ExamGrouping Handler
 * Zeigt im AdvancedGUI-Formular einen Link zur Fragengruppierungs-Verwaltung.
 * Part of ExtendedTestSettings Plugin
 */
class ilExamGroupingHandler implements ilExamModuleInterface
{
    private string $plugin_path;
    private ilModuleLanguage $lng;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;

        // Modul-eigene Sprachdatei (lang/ilias_<lang>.lang in diesem Modulverzeichnis)
        require_once $plugin_path . '/classes/Module/ilModuleLanguage.php';
        $this->lng = new ilModuleLanguage(__DIR__);
    }

    public function getInfo(): array
    {
        return [
            'title'       => $this->lng->txt('title'),
            'icon'        => '📊',
            'description' => $this->lng->txt('description'),
            'version'     => '1.2.4',
        ];
    }

    public function isEnabled(int $ref_id): bool
    {
        return false;
    }

    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        // keine Assets nötig
    }

    public function getFormItems(int $ref_id): array
    {
        $item = new ilCustomInputGUI();
        $item->setHtml($this->getSettingsHTML($ref_id));
        return [$item];
    }

    public function saveSettings(int $ref_id, array $post_data): bool
    {
        return true;
    }

    private function getSettingsHTML(int $ref_id): string
    {
        global $DIC;
        $ctrl = $DIC->ctrl();

        $ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', 'module_id', 'examgrouping');
        $ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', 'ref_id', $ref_id);
        $link = $ctrl->getLinkTargetByClass(
            ['ilUIPluginRouterGUI', 'ilExtendedTestSettingsModuleProxyGUI'],
            'show'
        );

        $groups_count = $this->countGroupsForRef($ref_id);
        $count_text   = $groups_count === 0
            ? $this->lng->txt('no_groups')
            : sprintf($this->lng->txt('groups_count'), $groups_count);

        return '<p>' . htmlspecialchars($count_text) . ' '
            . $this->lng->txt('intro') . '</p>'
            . '<a href="' . htmlspecialchars($link) . '" class="btn btn-default">'
            . $this->lng->txt('manage_btn')
            . '</a>';
    }

    private function countGroupsForRef(int $ref_id): int
    {
        global $DIC;
        $db     = $DIC->database();
        $obj_id = ilObject::_lookupObjectId($ref_id);

        try {
            $result = $db->queryF(
                "SELECT COUNT(*) as cnt FROM examgrouping_groups WHERE test_id = %s",
                ['integer'],
                [$obj_id]
            );
            if ($row = $db->fetchAssoc($result)) {
                return (int) $row['cnt'];
            }
        } catch (Exception $e) {
            // Tabelle evtl. noch nicht migriert
        }

        return 0;
    }
}
