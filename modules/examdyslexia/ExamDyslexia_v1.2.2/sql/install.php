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
        'font_family' => [
            'type'    => 'text',
            'length'  => 20,
            'notnull' => true,
            'default' => 'opendyslexic',
        ],
        'word_spacing' => [
            'type'    => 'text',
            'length'  => 10,
            'notnull' => true,
            'default' => '0',
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

    // 1.3.0: Schriftauswahl statt fester OpenDyslexic-Checkbox
    if (!$db->tableColumnExists('examdyslexia_settings', 'font_family')) {
        $db->addTableColumn('examdyslexia_settings', 'font_family', [
            'type'    => 'text',
            'length'  => 20,
            'notnull' => true,
            'default' => 'opendyslexic',
        ]);

        // Bestandsdaten übernehmen: Haken gesetzt → OpenDyslexic (= Spaltendefault),
        // Haken nicht gesetzt → Standardschrift des Testplayers
        $db->manipulateF(
            "UPDATE examdyslexia_settings SET font_family = %s WHERE use_font = %s",
            ['text', 'integer'],
            ['default', 0]
        );
    }

    // 1.3.0: Wortabstand
    if (!$db->tableColumnExists('examdyslexia_settings', 'word_spacing')) {
        $db->addTableColumn('examdyslexia_settings', 'word_spacing', [
            'type'    => 'text',
            'length'  => 10,
            'notnull' => true,
            'default' => '0',
        ]);
    }
}
