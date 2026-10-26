<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('exammessage_settings')) {
    $db->createTable('exammessage_settings', [
        'test_id'      => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'message_text' => ['type' => 'text',    'length' => 2000, 'notnull' => false],
        'duration'     => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 60],
        'sent_at'      => ['type' => 'integer', 'length' => 4, 'notnull' => false],
        'sent_by'      => ['type' => 'text',    'length' => 100, 'notnull' => false],
        'type'         => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0],
    ]);
    $db->addPrimaryKey('exammessage_settings', ['test_id']);
} else {
    if (!$db->tableColumnExists('exammessage_settings', 'duration')) {
        $db->addTableColumn('exammessage_settings', 'duration', [
            'type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 60,
        ]);
    }
    if (!$db->tableColumnExists('exammessage_settings', 'sent_at')) {
        $db->addTableColumn('exammessage_settings', 'sent_at', [
            'type' => 'integer', 'length' => 4, 'notnull' => false,
        ]);
    }
    if (!$db->tableColumnExists('exammessage_settings', 'sent_by')) {
        $db->addTableColumn('exammessage_settings', 'sent_by', [
            'type' => 'text', 'length' => 100, 'notnull' => false,
        ]);
    }
    if (!$db->tableColumnExists('exammessage_settings', 'type')) {
        $db->addTableColumn('exammessage_settings', 'type', [
            'type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0,
        ]);
    }
}

if (!$db->tableExists('exammessage_log')) {
    $db->createTable('exammessage_log', [
        'id'           => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'test_id'      => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'message_text' => ['type' => 'text',    'length' => 2000, 'notnull' => false],
        'duration'     => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 60],
        'sent_at'      => ['type' => 'integer', 'length' => 4, 'notnull' => false],
        'sent_by'      => ['type' => 'text',    'length' => 100, 'notnull' => false],
        'type'         => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0],
    ]);
    $db->addPrimaryKey('exammessage_log', ['id']);
    $db->createSequence('exammessage_log');
} else {
    if (!$db->tableColumnExists('exammessage_log', 'type')) {
        $db->addTableColumn('exammessage_log', 'type', [
            'type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0,
        ]);
    }
}
