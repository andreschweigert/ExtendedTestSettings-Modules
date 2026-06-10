<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examkeymap_settings')) {
    $db->createTable('examkeymap_settings', [
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
        'default_layout' => [
            'type'    => 'text',
            'length'  => 8,
            'notnull' => false,
        ],
        'search_caps' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 0,
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
    $db->addPrimaryKey('examkeymap_settings', ['test_id']);
}

// Update von 1.0.0: Lupe-/Suchtaste-als-Feststelltaste-Spalte nachrüsten
if (!$db->tableColumnExists('examkeymap_settings', 'search_caps')) {
    $db->addTableColumn('examkeymap_settings', 'search_caps', [
        'type'    => 'integer',
        'length'  => 1,
        'notnull' => true,
        'default' => 0,
    ]);
}

// Globale Übersetzungstabelle (Overrides der eingebauten Layout-Tabellen)
if (!$db->tableExists('examkeymap_custom')) {
    $db->createTable('examkeymap_custom', [
        'layout' => [
            'type'    => 'text',
            'length'  => 8,
            'notnull' => true,
        ],
        'keycode' => [
            'type'    => 'text',
            'length'  => 32,
            'notnull' => true,
        ],
        'base_char' => [
            'type'    => 'text',
            'length'  => 8,
            'notnull' => false,
        ],
        'shift_char' => [
            'type'    => 'text',
            'length'  => 8,
            'notnull' => false,
        ],
        'altgr_char' => [
            'type'    => 'text',
            'length'  => 8,
            'notnull' => false,
        ],
    ]);
    $db->addPrimaryKey('examkeymap_custom', ['layout', 'keycode']);
}
