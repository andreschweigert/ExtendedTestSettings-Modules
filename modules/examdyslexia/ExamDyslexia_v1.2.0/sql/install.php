<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('examdyslexia_settings')) {
    $db->createTable('examdyslexia_settings', [
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
        'allow_toggle' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 1,
        ],
        'use_font' => [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 1,
        ],
        'line_height' => [
            'type'    => 'text',
            'length'  => 10,
            'notnull' => true,
            'default' => '1.8',
        ],
        'letter_spacing' => [
            'type'    => 'text',
            'length'  => 10,
            'notnull' => true,
            'default' => '0.05em',
        ],
        'bg_color' => [
            'type'    => 'text',
            'length'  => 20,
            'notnull' => true,
            'default' => 'none',
        ],
        'allow_customize' => [
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
    $db->addPrimaryKey('examdyslexia_settings', ['test_id']);
} else {
    // 1.2.0: Studierende dürfen die Darstellung im Testplayer selbst anpassen
    if (!$db->tableColumnExists('examdyslexia_settings', 'allow_customize')) {
        $db->addTableColumn('examdyslexia_settings', 'allow_customize', [
            'type'    => 'integer',
            'length'  => 1,
            'notnull' => true,
            'default' => 1,
        ]);
    }
}
