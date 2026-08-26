<?php
/**
 * ExamChecker - Datenbank-Deinstallation
 * Wird beim Deinstallieren des Moduls ausgeführt.
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examchecker_rules')) {
    $db->dropTable('examchecker_rules');
}
