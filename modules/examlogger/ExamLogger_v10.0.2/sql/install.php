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

// Geräte-Inventar lebt seit Plugin 2.0.1 zentral in etest_hosts (Plugin-dbupdate);
// die alte Modul-Tabelle wird beim Update entfernt.
if ($db->tableExists('examlogger_hosts')) {
    $db->dropTable('examlogger_hosts');
}
