<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('exammessage_log')) {
    $db->dropTable('exammessage_log');
}
if ($db->sequenceExists('exammessage_log')) {
    $db->dropSequence('exammessage_log');
}
if ($db->tableExists('exammessage_settings')) {
    $db->dropTable('exammessage_settings');
}
