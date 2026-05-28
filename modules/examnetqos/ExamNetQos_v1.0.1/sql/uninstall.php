<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examnetqos_events')) {
    $db->dropSequence('examnetqos_events');
    $db->dropTable('examnetqos_events');
}

if ($db->tableExists('examnetqos_settings')) {
    $db->dropTable('examnetqos_settings');
}
