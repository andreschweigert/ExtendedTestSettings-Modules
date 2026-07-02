<?php
/**
 * ExamPreflight — Tabellen und Screenshot-Verzeichnis entfernen
 */
global $DIC;
$db = $DIC->database();

foreach (['exampreflight_runs', 'exampreflight_results'] as $table) {
    if ($db->tableExists($table)) {
        $db->dropTable($table);
    }
    if ($db->sequenceExists($table)) {
        $db->dropSequence($table);
    }
}

// Screenshot-Ablage: <CLIENT_DATA_DIR>/plugins/ExtendedTestSettings/exampreflight/
$data_dir = (defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : ILIAS_DATA_DIR . '/' . CLIENT_ID)
    . '/plugins/ExtendedTestSettings/exampreflight';

if (is_dir($data_dir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($data_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($data_dir);
}
