<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('exammagnifier_settings')) {
    $db->dropTable('exammagnifier_settings');
}
