<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examlogger_events')) {
    $db->createTable('examlogger_events', [
        'id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'test_obj_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'test_ref_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'user_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'event_type' => [
            'type'    => 'text',
            'length'  => 64,
            'notnull' => true,
        ],
        'question_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'ip_addr' => [
            'type'    => 'text',
            'length'  => 45,
            'notnull' => false,
            'default' => null,
        ],
        'tstamp' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'details' => [
            'type'    => 'clob',
            'notnull' => false,
            'default' => null,
        ],
    ]);
    $db->addPrimaryKey('examlogger_events', ['id']);
    $db->createSequence('examlogger_events');

    $db->addIndex('examlogger_events', ['test_ref_id'], 'i1');
    $db->addIndex('examlogger_events', ['user_id'],     'i2');
    $db->addIndex('examlogger_events', ['event_type'],  'i3');
    $db->addIndex('examlogger_events', ['tstamp'],      'i4');
}

if (!$db->tableExists('examlogger_hosts')) {
    $db->createTable('examlogger_hosts', [
        'ip_addr' => [
            'type'    => 'text',
            'length'  => 45,
            'notnull' => true,
            'default' => '',
        ],
        'hostname' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => true,
            'default' => '',
        ],
        'ili_nr' => [
            'type'    => 'text',
            'length'  => 20,
            'notnull' => true,
            'default' => '',
        ],
        'koffer' => [
            'type'    => 'text',
            'length'  => 20,
            'notnull' => true,
            'default' => '',
        ],
        'geraet_nr' => [
            'type'    => 'text',
            'length'  => 20,
            'notnull' => true,
            'default' => '',
        ],
        'import_note' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => true,
            'default' => '',
        ],
    ]);
    $db->addPrimaryKey('examlogger_hosts', ['ip_addr']);
    $db->addIndex('examlogger_hosts', ['ili_nr'], 'h1');
    $db->addIndex('examlogger_hosts', ['koffer'],  'h2');
}
