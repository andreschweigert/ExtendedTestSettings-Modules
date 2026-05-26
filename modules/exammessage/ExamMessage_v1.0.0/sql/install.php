<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('exammessage_settings')) {
    $db->createTable('exammessage_settings', [
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
        'message_text' => [
            'type'    => 'text',
            'length'  => 2000,
            'notnull' => false,
        ],
        'updated_at' => [
            'type'    => 'timestamp',
            'notnull' => false,
        ],
    ]);
    $db->addPrimaryKey('exammessage_settings', ['test_id']);
}
