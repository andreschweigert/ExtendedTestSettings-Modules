<?php
global $DIC;
$db = $DIC->database();

// Archiv-Läufe: ein Datensatz pro erzeugtem Archiv (Tabellenname max. 22 Zeichen!)
if (!$db->tableExists('examarchiver_archives')) {
    $db->createTable('examarchiver_archives', [
        'id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'obj_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'ref_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'title' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => true,
            'default' => '',
        ],
        // running | zipping | done | failed | deleted
        'status' => [
            'type'    => 'text',
            'length'  => 16,
            'notnull' => true,
            'default' => 'running',
        ],
        'created_by' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'created_at' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'finished_at' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'total_count' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'done_count' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'zip_name' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => true,
            'default' => '',
        ],
        'zip_size' => [
            'type'    => 'integer',
            'length'  => 8,
            'notnull' => true,
            'default' => 0,
        ],
        'zip_sha256' => [
            'type'    => 'text',
            'length'  => 64,
            'notnull' => true,
            'default' => '',
        ],
        'manifest_sha256' => [
            'type'    => 'text',
            'length'  => 64,
            'notnull' => true,
            'default' => '',
        ],
        'pdf_enabled' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 0,
        ],
        'error' => [
            'type'    => 'text',
            'length'  => 4000,
            'notnull' => false,
            'default' => null,
        ],
    ]);
    $db->addPrimaryKey('examarchiver_archives', ['id']);
    $db->createSequence('examarchiver_archives');
    $db->addIndex('examarchiver_archives', ['obj_id'], 'i1');
    $db->addIndex('examarchiver_archives', ['status'], 'i2');
}

// Zugriffsprotokoll: erstellt / heruntergeladen / geprüft / gelöscht
if (!$db->tableExists('examarchiver_log')) {
    $db->createTable('examarchiver_log', [
        'id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'archive_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'obj_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'user_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        // created | downloaded | verified | verify_failed | deleted | failed
        'action' => [
            'type'    => 'text',
            'length'  => 32,
            'notnull' => true,
        ],
        'details' => [
            'type'    => 'text',
            'length'  => 1000,
            'notnull' => false,
            'default' => null,
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
    ]);
    $db->addPrimaryKey('examarchiver_log', ['id']);
    $db->createSequence('examarchiver_log');
    $db->addIndex('examarchiver_log', ['archive_id'], 'i1');
    $db->addIndex('examarchiver_log', ['tstamp'], 'i2');
}
