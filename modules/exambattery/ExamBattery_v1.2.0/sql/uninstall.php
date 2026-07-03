<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('exambattery_settings')) {
    $db->dropTable('exambattery_settings');
}

if ($db->tableExists('exambattery_status')) {
    $db->dropTable('exambattery_status');
}
