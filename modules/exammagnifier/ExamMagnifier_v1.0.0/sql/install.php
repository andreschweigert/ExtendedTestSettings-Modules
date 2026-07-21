<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('exammagnifier_settings')) {
    $db->createTable('exammagnifier_settings', [
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
            'notnull' => true,
            'default' => 'all',
        ],
        'user_list' => [
            'type'    => 'clob',
            'notnull' => false,
        ],
        'allow_zoom' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 1,
        ],
        'max_zoom' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 1600,
        ],
        'allow_invert' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 1,
        ],
        'allow_cursor' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 1,
        ],
        'updated_at' => [
            'type'    => 'timestamp',
            'notnull' => false,
        ],
    ]);
    $db->addPrimaryKey('exammagnifier_settings', ['test_id']);
}
