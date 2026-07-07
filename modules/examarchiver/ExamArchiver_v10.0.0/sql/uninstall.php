<?php
global $DIC;
$db = $DIC->database();

// Hinweis: Die Archiv-ZIPs im Datenverzeichnis werden bewusst NICHT gelöscht —
// nur die Metadaten. Die Dateien liegen unter
// <CLIENT_DATA_DIR>/plugins/ExtendedTestSettings/examarchiver/
if ($db->tableExists('examarchiver_archives')) {
    $db->dropTable('examarchiver_archives');
}
if ($db->sequenceExists('examarchiver_archives')) {
    $db->dropSequence('examarchiver_archives');
}
if ($db->tableExists('examarchiver_log')) {
    $db->dropTable('examarchiver_log');
}
if ($db->sequenceExists('examarchiver_log')) {
    $db->dropSequence('examarchiver_log');
}
