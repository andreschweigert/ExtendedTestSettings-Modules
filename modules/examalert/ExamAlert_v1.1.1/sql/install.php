<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examalert_settings')) {
    $db->createTable('examalert_settings', [
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
        'alert_time' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 30,
        ],
        'alert_message' => [
            'type'    => 'text',
            'length'  => 1000,
            'notnull' => false,
            'default' => 'Achtung: Der Test endet bald!',
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
    $db->addPrimaryKey('examalert_settings', ['test_id']);
}
