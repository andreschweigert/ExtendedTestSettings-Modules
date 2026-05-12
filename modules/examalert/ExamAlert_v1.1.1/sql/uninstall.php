<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examalert_settings')) {
    $db->dropTable('examalert_settings');
}
