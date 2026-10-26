<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examsymbols_settings')) {
    $db->createTable('examsymbols_settings', [
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
        'template' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => false,
            'default' => 'math',
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
    $db->addPrimaryKey('examsymbols_settings', ['test_id']);
}
