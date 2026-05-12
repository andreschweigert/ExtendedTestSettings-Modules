<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examconnguard_settings')) {
    $db->dropTable('examconnguard_settings');
}
