<?php

interface ilGlobalTemplateInterface {}
interface ilDBInterface {}

class ilCtrl
{
    public function setParameterByClass(string $class, string $key, string $value): void {}
    public function getFormActionByClass(array $classes, string $cmd): string { return '/ilias.php?cmd=' . $cmd; }
    public function redirectByClass(array $classes, string $cmd): void {}
}

class ilModuleLanguage
{
    public function txt(string $key): string
    {
        return $key === 'confirm_delete' ? 'Gruppe "%s" löschen?' : $key;
    }
}

class ilSession
{
    private static array $values = [];
    public static function get(string $key) { return self::$values[$key] ?? null; }
    public static function set(string $key, $value): void { self::$values[$key] = $value; }
}

class ilObject
{
    public static function _lookupObjectId(int $ref_id): int { return 42; }
}

class TestTemplate implements ilGlobalTemplateInterface
{
    public function setOnScreenMessage(string $type, string $message): void {}
}

class TestDb implements ilDBInterface
{
    public array $queries = [];
    public array $writes = [];
    public function queryF(string $sql, array $types, array $values)
    {
        $this->queries[] = $values;
        return $values === [7, 42] ? ['id' => 7, 'name' => 'Example'] : false;
    }
    public function fetchAssoc($result) { return $result; }
    public function quote(int $value, string $type): string { return (string) $value; }
    public function manipulate(string $sql): void { $this->writes[] = $sql; }
}

function check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

require dirname(__DIR__) . '/ExamGrouping_v1.2.3/class.ilExamGroupingGUI.php';

$gui = (new ReflectionClass(ilExamGroupingGUI::class))->newInstanceWithoutConstructor();
$db = new TestDb();
foreach (['ctrl' => new ilCtrl(), 'tpl' => new TestTemplate(), 'db' => $db, 'lng' => new ilModuleLanguage()] as $property => $value) {
    (new ReflectionProperty(ilExamGroupingGUI::class, $property))->setValue($gui, $value);
}

$csvCell = new ReflectionMethod(ilExamGroupingGUI::class, 'csvCell');
foreach (['=1+1', '+SUM(1,2)', '-2+3', '@SUM(1,2)', '  =1+1', "\t=1+1", "\r=1+1"] as $input) {
    check($csvCell->invoke($gui, $input) === "'" . $input, "CSV formula accepted: $input");
}
check($csvCell->invoke($gui, 'Anna Beispiel') === 'Anna Beispiel', 'normal CSV cell changed');

$render = new ReflectionMethod(ilExamGroupingGUI::class, 'renderDeleteForm');
$form = $render->invoke($gui, 12, 7, "');window.evil=1;//");
check(str_contains($form, 'method="post"'), 'delete form is not POST');
check(str_contains($form, 'name="cmd[deleteGroup]"'), 'delete command missing from form');
check(!str_contains($form, '<a '), 'delete still uses a link');
check(preg_match('/name="examgrouping_delete_token" value="([a-f0-9]{32})"/', $form, $match) === 1, 'delete token missing');
$token = $match[1];

$delete = new ReflectionMethod(ilExamGroupingGUI::class, 'deleteGroup');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = ['group_id' => '7', 'examgrouping_delete_token' => $token];
$delete->invoke($gui, 12);
check($db->writes === [], 'GET deleted a group');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['examgrouping_delete_token'] = 'wrong';
$delete->invoke($gui, 12);
check($db->writes === [], 'bad token deleted a group');

$_POST['examgrouping_delete_token'] = $token;
$delete->invoke($gui, 12);
check($db->queries === [[7, 42]], 'group was not scoped to the current test');
check(count($db->writes) === 3, 'valid POST failed to delete group and assignments');

echo "ExamGrouping: POST/CSRF/group scope and CSV formulas OK\n";
