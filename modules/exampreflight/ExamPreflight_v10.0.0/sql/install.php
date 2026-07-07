<?php
/**
 * ExamPreflight — Tabellen anlegen
 * Wird vom ilModuleInstaller beim ZIP-Upload einmalig ausgeführt.
 */
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('exampreflight_runs')) {
    $db->createTable('exampreflight_runs', [
        'run_id' => [
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
        'status' => [
            'type'    => 'text',
            'length'  => 16,
            'notnull' => true,
            'default' => 'running',
        ],
        'started_at' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'finished_at' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'questions_checked' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'error_count' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'warning_count' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'meta' => [
            'type'    => 'clob',
            'notnull' => false,
        ],
    ]);
    $db->addPrimaryKey('exampreflight_runs', ['run_id']);
    $db->addIndex('exampreflight_runs', ['test_obj_id'], 'i1');
    $db->createSequence('exampreflight_runs');
}

if (!$db->tableExists('exampreflight_results')) {
    $db->createTable('exampreflight_results', [
        'result_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'run_id' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
        ],
        'position' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'title' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => false,
        ],
        'qtype' => [
            'type'    => 'text',
            'length'  => 64,
            'notnull' => false,
        ],
        'status' => [
            'type'    => 'text',
            'length'  => 8,
            'notnull' => true,
            'default' => 'ok',
        ],
        'error_count' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'warning_count' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'checks' => [
            'type'    => 'clob',
            'notnull' => false,
        ],
        'page_url' => [
            'type'    => 'text',
            'length'  => 1000,
            'notnull' => false,
        ],
        'shot_file' => [
            'type'    => 'text',
            'length'  => 255,
            'notnull' => false,
        ],
        'shot_w' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'shot_h' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
        'created_at' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => true,
            'default' => 0,
        ],
    ]);
    $db->addPrimaryKey('exampreflight_results', ['result_id']);
    $db->addIndex('exampreflight_results', ['run_id'], 'i1');
    $db->createSequence('exampreflight_results');
}
