<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examkeymap_settings')) {
    $db->dropTable('examkeymap_settings');
}
