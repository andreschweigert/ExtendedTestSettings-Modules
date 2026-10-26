<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examguard_settings')) {
    $db->dropTable('examguard_settings');
}
