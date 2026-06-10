<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examkeymap_settings')) {
    $db->createTable('examkeymap_settings', [
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
        'default_layout' => [
            'type'    => 'text',
            'length'  => 8,
            'notnull' => false,
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
    $db->addPrimaryKey('examkeymap_settings', ['test_id']);
}
