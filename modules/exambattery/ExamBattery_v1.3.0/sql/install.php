<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('exambattery_settings')) {
    $db->createTable('exambattery_settings', [
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
        // Gelb-Schwelle in % — steuert nur die Einfärbung der Aufsichts-Ansicht
        'warn_pct' => [
            'type'    => 'integer',
            'length'  => 2,
            'notnull' => true,
            'default' => 20,
        ],
        // Rot-Schwelle in % — muss unter warn_pct liegen
        'crit_pct' => [
            'type'    => 'integer',
            'length'  => 2,
            'notnull' => true,
            'default' => 10,
        ],
        'updated_at' => [
            'type'    => 'timestamp',
            'notnull' => false,
            'default' => null,
        ],
    ]);
    $db->addPrimaryKey('exambattery_settings', ['test_id']);
}

if (!$db->tableExists('exambattery_status')) {
    $db->createTable('exambattery_status', [
        'test_obj_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'user_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        // Akkustand 0–100 (ganzzahlig)
        'level_pct' => [
            'type'    => 'integer',
            'length'  => 2,
            'notnull' => true,
            'default' => 0,
        ],
        // 1 = Gerät lädt gerade
        'charging' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 0,
        ],
        // Geschätzte Restzeit in Minuten (Battery-API dischargingTime); -1 = unbekannt
        'discharging_min' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => -1,
        ],
        'ip_addr' => [
            'type'    => 'text',
            'length'  => 45,
            'notnull' => true,
            'default' => '',
        ],
        'test_ref_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        // Zeitpunkt der letzten Meldung (Unix-Timestamp) — Upsert, keine Historie
        'tstamp' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
    ]);
    $db->addPrimaryKey('exambattery_status', ['test_obj_id', 'user_id']);
}

if ($db->tableExists('exambattery_status')
    && !$db->tableColumnExists('exambattery_status', 'discharging_min')) {
    $db->addTableColumn('exambattery_status', 'discharging_min', [
        'type'    => 'integer',
        'length'  => 4,
        'notnull' => true,
        'default' => -1,
    ]);
}
