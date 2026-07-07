<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examguard_settings')) {
    $db->createTable('examguard_settings', [
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
        'exit_prevention' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 1,
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
    $db->addPrimaryKey('examguard_settings', ['test_id']);
}
