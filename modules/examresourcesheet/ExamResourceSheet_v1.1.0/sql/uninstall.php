<?php
/**
 * ExamResourceSheet - Datenbank-Deinstallation
 * Löscht die Tabelle und alle gespeicherten Dateien beim Deinstallieren.
 */
global $DIC;
$db = $DIC->database();

// Gespeicherte Dateien löschen
$data_dir = (defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : ILIAS_DATA_DIR . '/' . CLIENT_ID)
    . '/plugins/ExtendedTestSettings/resourcesheets/';

if (is_dir($data_dir)) {
    foreach (glob($data_dir . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($data_dir);
}

if ($db->tableExists('examressheet_settings')) {
    $db->dropTable('examressheet_settings');
}
