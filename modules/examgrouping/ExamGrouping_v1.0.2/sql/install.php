<?php

global $DIC;
$db = $DIC->database();

// Tabelle: examgrouping_groups
if (!$db->tableExists('examgrouping_groups')) {
    $db->createTable('examgrouping_groups', [
        'id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'test_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'name' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => true,
        ],
        'position' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'created_at' => [
            'type'    => 'timestamp',
            'notnull' => false,
        ],
        'threshold_type' => [
            'type'    => 'text',
            'length'  => 10,
            'notnull' => false,
            'default' => 'percent',
        ],
        'threshold_value' => [
            'type'    => 'float',
            'notnull' => false,
            'default' => 50,
        ],
    ]);
    $db->addPrimaryKey('examgrouping_groups', ['id']);
    $db->createSequence('examgrouping_groups');
    $db->addIndex('examgrouping_groups', ['test_id'], 'i1');
}

// Tabelle: examgrouping_items
if (!$db->tableExists('examgrouping_items')) {
    $db->createTable('examgrouping_items', [
        'id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'group_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'question_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
    ]);
    $db->addPrimaryKey('examgrouping_items', ['id']);
    $db->createSequence('examgrouping_items');
    $db->addIndex('examgrouping_items', ['group_id'], 'i1');
}
