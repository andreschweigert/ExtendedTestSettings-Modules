<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('exammessage_settings')) {
    $db->dropTable('exammessage_settings');
}
