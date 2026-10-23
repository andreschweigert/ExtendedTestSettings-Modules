<?php

declare(strict_types=1);

define('SYSTEM_FOLDER_ID', 1);

class ilObject
{
    public static function _lookupType(int $ref_id, bool $reference = false): string
    {
        return in_array($ref_id, [42, 43], true) ? 'tst' : 'crs';
    }

    public static function _lookupObjectId(int $ref_id): int
    {
        return $ref_id === 42 ? 142 : 0;
    }
}

class FakeRbac
{
    public bool $admin = false;
    public bool $testWrite = true;

    public function checkAccess(string $permission, int $ref_id): bool
    {
        return $permission === 'write'
            && ($ref_id === SYSTEM_FOLDER_ID ? $this->admin : ($ref_id === 42 && $this->testWrite));
    }
}

class FakeResult
{
    public array $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }
}

class FakeDatabase
{
    public array $queries = [];
    public bool $enabled = true;
    public bool $openPass = true;

    public function queryF(string $sql, array $types, array $values): FakeResult
    {
        $this->queries[] = [$sql, $values];
        if (str_contains($sql, 'SELECT enabled FROM examnetqos_settings')) {
            return new FakeResult([['enabled' => $this->enabled ? 1 : 0]]);
        }
        if (str_contains($sql, 'SELECT test_id FROM tst_tests')) {
            return new FakeResult([['test_id' => 7]]);
        }
        if (str_contains($sql, 'SELECT active_id FROM tst_active')) {
            check(str_contains($sql, 'submitted = %s') && $values === [7, 9, 0], 'Abgabestatus fehlt');
            return new FakeResult($this->openPass ? [['active_id' => 12]] : []);
        }
        return new FakeResult([]);
    }

    public function query(string $sql): FakeResult
    {
        throw new RuntimeException('Unbegrenzte Abfrage: ' . $sql);
    }

    public function fetchAssoc(FakeResult $result): array|false
    {
        return array_shift($result->rows) ?: false;
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

require __DIR__ . '/../ExamNetQos_v1.2.1/class.ilExamNetQosViewGUI.php';

$rbacsystem = new FakeRbac();
$DIC = new FakeDic();
$class = new ReflectionClass(ilExamNetQosViewGUI::class);
$view = $class->newInstanceWithoutConstructor();

$_GET = ['ref_id' => '42', 'filter_ref_id' => '43'];
check($class->getMethod('authorizeView')->invoke($view), 'Berechtigter Test wurde abgewiesen');
check($class->getMethod('selectedTestRef')->invoke($view) === 42, 'Fremder Testfilter wurde übernommen');

$class->getMethod('fetchEvents')->invoke($view, 43, 0, '', '', '', 3000);
check(count($DIC->db->queries) === 2, 'Unerwartete Zahl von Abfragen');
foreach ($DIC->db->queries as [$sql, $values]) {
    check(str_contains($sql, 'e.test_ref_id = %s') && in_array(42, $values, true), 'Abfrage ohne Testgrenze');
}

$other = $class->newInstanceWithoutConstructor();
$_GET = ['ref_id' => '43'];
check(!$class->getMethod('authorizeView')->invoke($other), 'Fremder Test wurde zugelassen');
$_GET = ['ref_id' => '99'];
check(!$class->getMethod('authorizeView')->invoke($other), 'Nicht-Test wurde zugelassen');

$rbacsystem->admin = true;
check($class->getMethod('authorizeView')->invoke($other), 'Globale Admin-Ansicht wurde abgewiesen');
$rbacsystem->admin = false;

$canReport = $class->getMethod('canReport');
$DIC->db->enabled = false;
check(!$canReport->invoke($view, $DIC, 42, 9), 'Deaktivierte Messung nimmt Reports an');
$DIC->db->enabled = true;
check($canReport->invoke($view, $DIC, 42, 9), 'Aktivierte Testvorschau wurde abgewiesen');
$rbacsystem->testWrite = false;
$DIC->db->openPass = false;
check(!$canReport->invoke($view, $DIC, 42, 9), 'Abgegebener Testlauf nimmt Reports an');
$DIC->db->openPass = true;
check($canReport->invoke($view, $DIC, 42, 9), 'Offener Testlauf wurde abgewiesen');

$csvCell = $class->getMethod('csvCell');
foreach (['=1+1', '  @SUM(1)', "\t=1+1", '+cmd', '-2+3'] as $value) {
    check($csvCell->invoke($view, $value) === "'" . $value, 'CSV-Formel nicht neutralisiert');
}
check($csvCell->invoke($view, 'Normaler Text') === 'Normaler Text', 'Normaler CSV-Text verändert');

echo "ExamNetQos-Sicherheitsprüfungen erfolgreich\n";
