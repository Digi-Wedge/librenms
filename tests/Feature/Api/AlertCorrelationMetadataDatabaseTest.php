<?php

namespace LibreNMS\Tests\Feature\Api;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\JsonResponse;
use LibreNMS\Tests\Fixtures\AlertCorrelationFixture;
use PDOException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AlertCorrelationMetadataDatabaseTest extends TestCase
{
    private ?AlertCorrelationFixture $fixture = null;
    private Connection $db;
    private Connection $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $path = getenv('LIBRENMS_CORRELATION_TEST_FIXTURE');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Requires a creator-owned disposable MariaDB handoff; never uses configured application databases.');
        }
        $config = AlertCorrelationFixture::creatorConnection($path);
        $this->fixture = new AlertCorrelationFixture(['correlation' => $config, 'writer' => $config]);
        $this->db = $this->fixture->database->connection('correlation');
        $this->writer = $this->fixture->database->connection('writer');
        $identity = $this->db->selectOne('SELECT DATABASE() AS name, VERSION() AS version');
        $this->assertSame('librenms_correlation_test', $identity->name);
        $this->assertStringStartsWith('10.6.', $identity->version);
        $this->assertStringContainsString('MariaDB', $identity->version);
    }

    protected function tearDown(): void
    {
        $this->fixture?->close();
        parent::tearDown();
    }

    public function testCompleteProfileAndUnprofiledLegacyRoute(): void
    {
        foreach ([0, 1, 255, 256, 257] as $count) {
            $this->seed($count);
            $response = AlertCorrelationFixture::profile();
            $this->assertInstanceOf(StreamedResponse::class, $response);
            $this->assertSame(0, $this->db->transactionLevel());
            $this->assertFalse($this->db->getPdo()->inTransaction());
            $body = AlertCorrelationFixture::body($response);
            $this->assertSame($count, $body['count']);
            $this->assertCount($count, $body['alerts']);
            $this->assertTrue($body['complete']);
            $this->assertStringNotContainsString('PRIVATE_FIXTURE_CANARY', json_encode($body));
        }
        $this->seed(1);
        $legacy = list_alerts(AlertCorrelationFixture::request(['state' => '0,1']));
        $this->assertInstanceOf(JsonResponse::class, $legacy);
        $this->assertSame(['small' => true], $legacy->getData(true)['alerts'][0]['details']);
        $this->assertSame('PRIVATE_FIXTURE_CANARY', $legacy->getData(true)['alerts'][0]['notes']);
        $this->assertArrayNotHasKey('contract', $legacy->getData(true));
    }

    public function testOneReadonlySnapshotSurvivesConcurrentChangesBetweenBatches(): void
    {
        $this->seed(600);
        $this->db->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $queries = [];
        $mutated = false;
        $readonlyRefused = false;
        $this->fixture->events->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries, &$mutated, &$readonlyRefused): void {
            if ($event->connection !== $this->db || ! str_contains($event->sql, 'from `alert_faults`')) {
                return;
            }
            $queries[] = $event->sql;
            if ($mutated) {
                return;
            }
            $mutated = true;
            $this->assertSame(1, $this->db->transactionLevel());
            $this->assertTrue($this->db->getPdo()->inTransaction());
            try {
                $this->db->getPdo()->exec("INSERT INTO devices VALUES (99, 'must-not-write.invalid')");
            } catch (PDOException $error) {
                $readonlyRefused = ($error->errorInfo[1] ?? null) === 1792;
            }
            $this->writer->table('alert_faults')->where('id', 300)->update(['state' => 0, 'timestamp' => '2026-10-15 00:00:00']);
            $this->writer->table('alert_faults')->where('id', 400)->delete();
            $this->writer->table('alert_faults')->insert(['id' => 601, 'device_id' => 1, 'rule_id' => 1, 'state' => 0, 'timestamp' => '2026-10-15 00:00:00']);
            $this->writer->table('devices')->where('device_id', 1)->update(['hostname' => 'changed.invalid']);
            $this->writer->table('alert_rules')->where('id', 1)->delete();
        });
        $response = AlertCorrelationFixture::profile();
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertTrue($readonlyRefused);
        $this->assertSame(0, $this->db->transactionLevel());
        $this->assertFalse($this->db->getPdo()->inTransaction());
        $body = AlertCorrelationFixture::body($response);
        $this->assertSame(600, $body['count']);
        $this->assertSame(range(1, 600), array_column($body['alerts'], 'id'));
        foreach ($body['alerts'] as $row) {
            $this->assertSame('fixture.invalid', $row['hostname']);
            $this->assertSame('2020-01-01 00:00:00', $row['timestamp']);
            $this->assertSame($row['id'] % 2, $row['state']);
        }
        $this->assertCount(3, $queries);
        $this->assertSame('READ-COMMITTED', $this->db->selectOne('SELECT @@session.tx_isolation AS value')->value);
        $this->db->table('devices')->insert(['device_id' => 2, 'hostname' => 'after-transaction.invalid']);
    }

    public function testPostBeginListenerFailureClosesTheActualTransaction(): void
    {
        $this->seed(1);
        $this->fixture->events->listen(TransactionBeginning::class, static function (): void {
            throw new RuntimeException('PRIVATE_FIXTURE_CANARY');
        });
        $response = AlertCorrelationFixture::profile();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['status' => 'error', 'contract' => 'msp-correlation-v1', 'complete' => false, 'code' => 'CORRELATION_COLLECTION_FAILED'], $response->getData(true));
        $this->assertSame(0, $this->db->transactionLevel());
        $this->assertFalse($this->db->getPdo()->inTransaction());
    }

    public function testNontransactionalEngineRefusesBeforeFaultCollection(): void
    {
        $this->seed(1);
        $this->db->statement('ALTER TABLE devices ENGINE=MyISAM');
        $response = AlertCorrelationFixture::profile();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('CORRELATION_COLLECTION_INVALID', $response->getData(true)['code']);
        $this->assertFalse($response->getData(true)['complete']);
        $this->assertFalse($this->db->getPdo()->inTransaction());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCompressedDetailsDoNotExhaustTheOriginal128MiBPhpBudget(): void
    {
        $oldLimit = ini_set('memory_limit', '128M');
        try {
            $this->seed(16384, true);
            $queries = [];
            $this->fixture->events->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
                if (str_contains($event->sql, 'from `alert_faults`')) {
                    $queries[] = $event->sql;
                }
            });
            $response = AlertCorrelationFixture::profile();
            $this->assertInstanceOf(StreamedResponse::class, $response);
            $body = AlertCorrelationFixture::body($response);
            $this->assertTrue($body['complete']);
            $this->assertSame(16384, $body['count']);
            $this->assertCount(16384, $body['alerts']);
            $this->assertCount(65, $queries);
            foreach ($queries as $sql) {
                $this->assertStringContainsString('limit 256', $sql);
                $this->assertDoesNotMatchRegularExpression('/`(?:details|info|note|proc|notes|severity|name)`/i', $sql);
            }
            $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
        } finally {
            ini_set('memory_limit', $oldLimit);
        }
    }

    private function seed(int $count, bool $huge = false): void
    {
        // Only the separately creator-bound dummy database is reachable through this connection.
        foreach (['alert_faults', 'devices', 'alert_rules'] as $table) {
            $this->db->statement('DROP TABLE IF EXISTS ' . $table);
        }
        $this->db->statement('CREATE TABLE devices (device_id INT UNSIGNED PRIMARY KEY, hostname VARCHAR(128) NOT NULL) ENGINE=InnoDB');
        $this->db->statement('CREATE TABLE alert_rules (id INT UNSIGNED PRIMARY KEY, severity VARCHAR(16), name VARCHAR(128), proc TEXT, notes TEXT) ENGINE=InnoDB');
        $this->db->statement('CREATE TABLE alert_faults (id INT UNSIGNED PRIMARY KEY, device_id INT UNSIGNED NOT NULL, rule_id INT UNSIGNED NOT NULL, state INT NOT NULL, timestamp DATETIME NOT NULL, details LONGBLOB, info TEXT, note TEXT) ENGINE=InnoDB');
        $this->db->table('devices')->insert(['device_id' => 1, 'hostname' => 'fixture.invalid']);
        $this->db->table('alert_rules')->insert(['id' => 1, 'severity' => 'critical', 'name' => 'fixture', 'proc' => 'PRIVATE_FIXTURE_CANARY', 'notes' => 'PRIVATE_FIXTURE_CANARY']);
        $blob = $huge ? gzcompress(json_encode(['large' => str_repeat('x', 1048576)], JSON_THROW_ON_ERROR)) : gzcompress('{"small":true}');
        $batch = [];
        for ($id = 1; $id <= $count; $id++) {
            $batch[] = ['id' => $id, 'device_id' => 1, 'rule_id' => 1, 'state' => $id % 2,
                'timestamp' => '2020-01-01 00:00:00', 'details' => $blob, 'info' => 'PRIVATE_FIXTURE_CANARY', 'note' => 'PRIVATE_FIXTURE_CANARY'];
            if (count($batch) === 256 || $id === $count) {
                $this->db->table('alert_faults')->insert($batch);
                $batch = [];
            }
        }
    }
}
