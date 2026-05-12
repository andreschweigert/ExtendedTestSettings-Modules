<?php

global $DIC;
$db = $DIC->database();

if ($db->tableExists('examgrouping_items')) {
    $db->dropTable('examgrouping_items');
}

if ($db->sequenceExists('examgrouping_items')) {
    $db->dropSequence('examgrouping_items');
}

if ($db->tableExists('examgrouping_groups')) {
    $db->dropTable('examgrouping_groups');
}

if ($db->sequenceExists('examgrouping_groups')) {
    $db->dropSequence('examgrouping_groups');
}
