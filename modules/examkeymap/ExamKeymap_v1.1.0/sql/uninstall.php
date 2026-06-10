<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examkeymap_settings')) {
    $db->dropTable('examkeymap_settings');
}

if ($db->tableExists('examkeymap_custom')) {
    $db->dropTable('examkeymap_custom');
}
