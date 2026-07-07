<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examdyslexia_settings')) {
    $db->dropTable('examdyslexia_settings');
}
