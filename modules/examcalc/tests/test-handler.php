<?php

interface ilExamModuleInterface {}
interface ilGlobalTemplateInterface {}

class ilObject
{
    public static function _lookupObjectId(int $refId): int { return 42; }
}

class TestDatabase
{
    public array $row = ['enabled' => 1, 'calculator_mode' => 'simple'];

    public function queryF(string $query, array $types, array $values): array { return $this->row; }
    public function fetchAssoc(array $result): array { return $result; }
    public function replace(string $table, array $keys, array $values): void
    {
        $this->row['enabled'] = $values['enabled'][1];
        $this->row['calculator_mode'] = $values['calculator_mode'][1];
    }
}

class TestDIC
{
    public function __construct(private TestDatabase $db) {}
    public function database(): TestDatabase { return $this->db; }
}

class TestTemplate implements ilGlobalTemplateInterface
{
    public array $scripts = [];
    public string $code = '';
    public function addCss(string $path): void {}
    public function addJavaScript(string $path): void { $this->scripts[] = $path; }
    public function addOnLoadCode(string $code): void { $this->code = $code; }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require dirname(__DIR__) . '/ExamCalc_v1.2.1/class.ilExamCalcHandler.php';

$db = new TestDatabase();
$DIC = new TestDIC($db);
$_GET['cmdClass'] = 'ilTestPlayerGUI';

$handler = (new ReflectionClass(ilExamCalcHandler::class))->newInstanceWithoutConstructor();
$pluginPath = new ReflectionProperty(ilExamCalcHandler::class, 'plugin_path');
$pluginPath->setValue($handler, "/plugin's/<path>");

foreach (['simple', 'advanced'] as $mode) {
    check($handler->saveSettings(12, ['examcalc_enabled' => '1', 'examcalc_mode' => $mode]), 'save failed');
    check($handler->getCalculatorMode(12) === $mode, "mode $mode changed");
    $tpl = new TestTemplate();
    $handler->injectAssets($tpl, 12);
    check(str_contains($tpl->code, "window.ExamCalc.calculatorMode = \"$mode\";"), "mode $mode missing in JS");
    check(str_contains($tpl->code, '\\u0027'), 'apostrophe in plugin path not escaped');
    check(str_contains($tpl->code, '\\u003C'), 'HTML opener in plugin path not escaped');
    check(count($tpl->scripts) === ($mode === 'advanced' ? 3 : 2), "wrong assets for $mode");
}

foreach (["';window.x=1;//", ['advanced'], 'other'] as $mode) {
    check($handler->saveSettings(12, ['examcalc_enabled' => '1', 'examcalc_mode' => $mode]), 'save failed');
    check($db->row['calculator_mode'] === 'simple', 'invalid mode persisted');
}

$db->row['calculator_mode'] = "';window.x=1;//";
check($handler->getCalculatorMode(12) === 'simple', 'legacy poisoned mode accepted');
$tpl = new TestTemplate();
$handler->injectAssets($tpl, 12);
check(!str_contains($tpl->code, 'window.x=1'), 'poisoned mode rendered as JavaScript');

echo "ExamCalc: Modus-Validierung, Altwerte und JavaScript-Escaping OK\n";
