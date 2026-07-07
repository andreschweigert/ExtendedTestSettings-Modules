<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examfullscr_settings')) {
    $db->dropTable('examfullscr_settings');
}
