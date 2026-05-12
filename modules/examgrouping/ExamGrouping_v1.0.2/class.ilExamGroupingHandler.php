<?php

/**
 * ExamGrouping Handler
 * Zeigt im AdvancedGUI-Formular einen Link zur Fragengruppierungs-Verwaltung.
 * Part of ExtendedTestSettings Plugin
 */
class ilExamGroupingHandler implements ilExamModuleInterface
{
    private string $plugin_path;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;
    }

    public function getInfo(): array
    {
        return [
            'title'       => 'Fragengruppierung',
            'icon'        => '📊',
            'description' => 'Fragen zu Gruppen zusammenfassen und Gesamtpunktzahl je Gruppe auswerten.',
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
            ? 'Noch keine Gruppen definiert.'
            : $groups_count . ' Gruppe(n) konfiguriert.';

        return '<p>' . htmlspecialchars($count_text) . ' '
            . 'Fassen Sie Fragen dieses Tests zu benannten Gruppen zusammen '
            . 'und werten Sie die Gesamtpunktzahl je Gruppe aus.</p>'
            . '<a href="' . htmlspecialchars($link) . '" class="btn btn-default">'
            . 'Gruppen verwalten / Ergebnisse anzeigen'
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
