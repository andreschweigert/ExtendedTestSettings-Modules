<?php
/**
 * ExamChecker - Datenbank-Installation
 * Wird beim ZIP-Upload ausgeführt (auch bei Updates), deshalb mit Guard.
 */
global $DIC;
$db = $DIC->database();

$fresh_install = !$db->tableExists('examchecker_rules');

if ($fresh_install) {
    $db->createTable('examchecker_rules', [
        'exam_type' => [
            'type'    => 'text',
            'length'  => 20,
            'notnull' => true,
        ],
        'check_key' => [
            'type'    => 'text',
            'length'  => 64,
            'notnull' => true,
        ],
        'enabled' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 0,
        ],
        'expected_value' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => true,
            'default' => '',
        ],
        'updated_at' => [
            'type'    => 'timestamp',
            'notnull' => false,
        ],
    ]);
    $db->addPrimaryKey('examchecker_rules', ['exam_type', 'check_key']);
}

// Nur bei der Erstanlage befüllen: eine vorhandene 1.x-Konfiguration übernehmen,
// sonst die Vorgaben aus dem Check-Katalog schreiben. Die alten ilSetting-Keys
// bleiben stehen, damit ein Downgraden auf 1.x folgenlos bleibt.
if ($fresh_install) {
    require_once __DIR__ . '/../class.ilExamCheckerDefinitions.php';
    require_once __DIR__ . '/../class.ilExamCheckerRules.php';

    try {
        (new ilExamCheckerRules($db))->seedFromLegacy();
    } catch (Throwable $e) {
        error_log('[ExamChecker] Übernahme der bisherigen Konfiguration fehlgeschlagen: '
            . $e->getMessage());
    }
}
