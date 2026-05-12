<?php
/**
 * ExamResourceSheet - Datenbank-Installation
 * Tabellenname: examressheet_settings (max. 22 Zeichen, ILIAS-Limit)
 */
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examressheet_settings')) {
    $db->createTable('examressheet_settings', [
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
        'label' => [
            'type'    => 'text',
            'length'  => 100,
            'notnull' => false,
        ],
        'filename' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => false,
        ],
        'stored_name' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => false,
        ],
        'mimetype' => [
            'type'    => 'text',
            'length'  => 100,
            'notnull' => false,
        ],
        'filesize' => [
            'type'    => 'integer',
            'length'  => 8,
            'notnull' => false,
        ],
        'updated_at' => [
            'type'    => 'timestamp',
            'notnull' => false,
        ],
    ]);
    $db->addPrimaryKey('examressheet_settings', ['test_id']);
}
