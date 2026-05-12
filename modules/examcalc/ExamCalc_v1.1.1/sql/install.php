<?php
/**
 * ExamCalc - Datenbank-Installation
 * Wird beim ersten Aktivieren des Moduls ausgeführt.
 */
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examcalc_settings')) {
    $db->createTable('examcalc_settings', [
        'test_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'enabled' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 0,
        ],
        'calculator_mode' => [
            'type'    => 'text',
            'length'  => 20,
            'notnull' => true,
            'default' => 'simple',
        ],
        'created_at' => [
            'type'    => 'timestamp',
            'notnull' => false,
        ],
        'updated_at' => [
            'type'    => 'timestamp',
            'notnull' => false,
        ],
    ]);
    $db->addPrimaryKey('examcalc_settings', ['test_id']);
}
