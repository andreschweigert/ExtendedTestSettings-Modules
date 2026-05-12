<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examsymbols_settings')) {
    $db->dropTable('examsymbols_settings');
}
