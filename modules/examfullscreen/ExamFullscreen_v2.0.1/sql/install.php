<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examfullscr_settings')) {
    $db->createTable('examfullscr_settings', [
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
        'mode' => [
            'type'    => 'text',
            'length'  => 10,
            'notnull' => false,
        ],
    ]);
    $db->addPrimaryKey('examfullscr_settings', ['test_id']);
}
