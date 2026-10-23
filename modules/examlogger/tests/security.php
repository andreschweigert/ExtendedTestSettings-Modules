<?php

declare(strict_types=1);

define('SYSTEM_FOLDER_ID', 1);

class ilObject
{
    public static function _lookupType(int $ref_id, bool $reference = false): string
    {
        return in_array($ref_id, [42, 43], true) ? 'tst' : 'crs';
    }
}

class FakeRbac
{
    public bool $admin = false;

    public function checkAccess(string $permission, int $ref_id): bool
    {
        return $permission === 'write'
            && ($ref_id === SYSTEM_FOLDER_ID ? $this->admin : $ref_id === 42);
    }
}

class FakeDatabase
{
    public array $queries = [];

    public function tableExists(string $table): bool
    {
        return true;
    }

    public function queryF(string $sql, array $types, array $values): array
    {
        $this->queries[] = [$sql, $values];
        return [];
    }

    public function query(string $sql): array
    {
        throw new RuntimeException('Unbegrenzte Abfrage: ' . $sql);
    }

    public function fetchAssoc(array $result): bool
    {
        return false;
    }
}

class FakeDic
{
    public FakeDatabase $db;

    public function __construct()
    {
        $this->db = new FakeDatabase();
    }

    public function database(): FakeDatabase
    {
        return $this->db;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require __DIR__ . '/../ExamLogger_v1.4.1/class.ilExamLoggerViewGUI.php';

$rbacsystem = new FakeRbac();
$DIC = new FakeDic();
$class = new ReflectionClass(ilExamLoggerViewGUI::class);
$view = $class->newInstanceWithoutConstructor();

$_GET = ['ref_id' => '42', 'filter_ref_id' => '99'];
check($class->getMethod('authorizeScope')->invoke($view), 'Berechtigter Test wurde abgewiesen');
check($class->getMethod('selectedTestRef')->invoke($view) === 42, 'Fremder Testfilter wurde übernommen');

$class->getMethod('fetchRows')->invoke($view, '', 99, 0, '', '', '', 1000);
check(count($DIC->db->queries) === 4, 'Unerwartete Zahl von Abfragen');
foreach ($DIC->db->queries as [$sql, $values]) {
    check(str_contains($sql, 'test_ref_id') && in_array(42, $values, true), 'Abfrage ohne Testgrenze');
}

$other = $class->newInstanceWithoutConstructor();
$_GET = ['ref_id' => '43'];
check(!$class->getMethod('authorizeScope')->invoke($other), 'Fremder Test wurde zugelassen');

$_GET = ['ref_id' => '99'];
check(!$class->getMethod('authorizeScope')->invoke($other), 'Nicht-Test wurde zugelassen');

$rbacsystem->admin = true;
check($class->getMethod('authorizeScope')->invoke($other), 'Globale Admin-Ansicht wurde abgewiesen');

$csvCell = $class->getMethod('csvCell');
foreach (['=1+1', '  @SUM(1)', "\t=1+1", '+cmd', '-2+3'] as $value) {
    check($csvCell->invoke($view, $value) === "'" . $value, 'CSV-Formel nicht neutralisiert');
}
check($csvCell->invoke($view, 'Normaler Text') === 'Normaler Text', 'Normaler CSV-Text verändert');

echo "ExamLogger-Sicherheitsprüfungen erfolgreich\n";
