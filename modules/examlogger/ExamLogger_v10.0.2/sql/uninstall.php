<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examlogger_events')) {
    $db->dropTable('examlogger_events');
}
if ($db->sequenceExists('examlogger_events')) {
    $db->dropSequence('examlogger_events');
}
if ($db->tableExists('examlogger_hosts')) {
    $db->dropTable('examlogger_hosts');
}
