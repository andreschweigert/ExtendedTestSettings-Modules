<?php
/**
 * ExamCalc - Datenbank-Deinstallation
 * Wird beim Deinstallieren des Moduls ausgeführt.
 */
global $DIC;
$db = $DIC->database();

if ($db->tableExists('examcalc_settings')) {
    $db->dropTable('examcalc_settings');
}
