<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examnetqos_settings')) {
    $db->createTable('examnetqos_settings', [
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
        'interval_seconds' => [
            'type'    => 'integer',
            'length'  => 2,
            'notnull' => true,
            'default' => 10,
        ],
        'rtt_warn_ms' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 500,
        ],
        'rtt_crit_ms' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 2000,
        ],
        'loss_enabled' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 1,
        ],
        'loss_probes' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 3,
        ],
        'updated_at' => [
            'type'    => 'timestamp',
            'notnull' => false,
            'default' => null,
        ],
    ]);
    $db->addPrimaryKey('examnetqos_settings', ['test_id']);
}

if (!$db->tableExists('examnetqos_events')) {
    $db->createTable('examnetqos_events', [
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
        // client-generated unique key per page load, groups measurements into sessions
        'session_key' => [
            'type'    => 'text',
            'length'  => 32,
            'notnull' => true,
            'default' => '',
        ],
        // measurement | degraded | critical | recovered | reconnect
        'event_type' => [
            'type'    => 'text',
            'length'  => 16,
            'notnull' => true,
        ],
        // RTT in ms; 0 = probe timed out / failed
        'rtt_ms' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        // packet loss 0–100; -1 = loss probing disabled for this measurement
        'loss_pct' => [
            'type'    => 'integer',
            'length'  => 2,
            'notnull' => true,
            'default' => -1,
        ],
        // navigator.connection.effectiveType: slow-2g | 2g | 3g | 4g | ''
        'conn_type' => [
            'type'    => 'text',
            'length'  => 16,
            'notnull' => true,
            'default' => '',
        ],
        'ip_addr' => [
            'type'    => 'text',
            'length'  => 45,
            'notnull' => true,
            'default' => '',
        ],
        'tstamp' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
    ]);
    $db->addPrimaryKey('examnetqos_events', ['id']);
    $db->createSequence('examnetqos_events');

    $db->addIndex('examnetqos_events', ['test_ref_id'],  'i1');
    $db->addIndex('examnetqos_events', ['user_id'],      'i2');
    $db->addIndex('examnetqos_events', ['session_key'],  'i3');
    $db->addIndex('examnetqos_events', ['tstamp'],       'i4');
    $db->addIndex('examnetqos_events', ['event_type'],   'i5');
}
