<?php

namespace LibreNMS\Tests\Unit\Api;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use LibreNMS\Tests\Fixtures\AlertCorrelationFixture;
use Mockery;
use Mockery\MockInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AlertCorrelationMetadataTest extends TestCase
{
    private AlertCorrelationFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new AlertCorrelationFixture;
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            $this->fixture->close();
            parent::tearDown();
        }
    }

    public function testProfileDispatcherRejectsUnsupportedProfileWithoutDatabase(): void
    {
        DB::shouldReceive('connection')->never();
        $response = list_alerts(AlertCorrelationFixture::request(['profile' => 'unsupported', 'state' => '0,1']));
        $this->assertClosed($response, 'CORRELATION_PROFILE_REQUEST', 400);
    }

    public function testStrictRequestRejectsMissingArrayStateExtraKeyAndSingleId(): void
    {
        DB::shouldReceive('connection')->never();
        foreach ([
            [['profile' => null, 'state' => '0,1'], null],
            [['profile' => ['msp-correlation-v1'], 'state' => '0,1'], null],
            [['profile' => 'msp-correlation-v1'], null],
            [['profile' => 'msp-correlation-v1', 'state' => '0'], null],
            [['profile' => 'msp-correlation-v1', 'state' => '0,1', 'limit' => '1'], null],
            [['profile' => 'msp-correlation-v1', 'state' => '0,1'], 7],
        ] as [$query, $id]) {
            $this->assertClosed(list_alerts(AlertCorrelationFixture::request($query, $id)), 'CORRELATION_PROFILE_REQUEST', 400);
        }
    }

    public function testCompleteCollectionCommitsBeforeStreamAndKeepsOriginalTimestamp(): void
    {
        $committed = false;
        $db = $this->connection($this->rows(257));
        $db->shouldReceive('commit')->once()->andReturnUsing(static function () use (&$committed): void { $committed = true; });
        $response = AlertCorrelationFixture::profile();
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertTrue($committed);
        $body = AlertCorrelationFixture::body($response);
        $this->assertSame(257, $body['count']);
        $this->assertTrue($body['complete']);
        $this->assertSame('repeatable-read', $body['consistency']);
        $this->assertSame([0, 1], $body['states']);
        $this->assertSame(range(1, 257), array_column($body['alerts'], 'id'));
        $this->assertSame(['id', 'device_id', 'rule_id', 'hostname', 'state', 'timestamp'], array_keys($body['alerts'][0]));
        $this->assertSame('2020-01-01 00:00:00', $body['alerts'][0]['timestamp']);
    }

    public function testShortResponseSendFailsAfterHeadersAndClosesTheSpool(): void
    {
        $db = $this->connection($this->rows(1));
        $db->shouldReceive('commit')->once();
        $response = AlertCorrelationFixture::profile();
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $callback = $response->getCallback();
        $this->assertInstanceOf(Closure::class, $callback);
        $resource = (new ReflectionFunction($callback))->getStaticVariables()['body'];
        $this->assertIsResource($resource);
        $this->assertTrue(ftruncate($resource, 17));
        $this->assertTrue(rewind($resource));
        ob_start();
        try {
            $callback();
            $this->fail('A short send must not silently complete');
        } catch (RuntimeException $error) {
            $this->assertSame('CORRELATION_RESPONSE_FAILED', $error->getMessage());
        } finally {
            ob_end_clean();
        }
        $this->assertFalse(is_resource($resource));
    }

    public function testRowCeilingRefusesWholeCollectionInsteadOfTruncating(): void
    {
        $db = $this->connection($this->rows(65537));
        $db->shouldReceive('rollBack')->once();
        $db->shouldNotReceive('commit');
        $this->assertClosed(AlertCorrelationFixture::profile(), 'CORRELATION_COLLECTION_LIMIT');
    }

    public function testEncodedByteCeilingRefusesWholeCollectionInsteadOfTruncating(): void
    {
        $db = $this->connection($this->rows(20000, str_repeat('x', 1024)));
        $db->shouldReceive('rollBack')->once();
        $db->shouldNotReceive('commit');
        $this->assertClosed(AlertCorrelationFixture::profile(), 'CORRELATION_COLLECTION_LIMIT');
    }

    public function testMalformedScalarRefusesWithoutPartialAlerts(): void
    {
        $bad = (object) ['id' => 1, 'device_id' => 42, 'rule_id' => 3, 'hostname' => 'fixture.invalid', 'state' => 1, 'timestamp' => '2026-02-30 00:00:00'];
        $db = $this->connection([$bad]);
        $db->shouldReceive('rollBack')->once();
        $db->shouldNotReceive('commit');
        $this->assertClosed(AlertCorrelationFixture::profile(), 'CORRELATION_COLLECTION_INVALID');
    }

    public function testDuplicateIdRefusesWithoutPartialAlerts(): void
    {
        $row = (object) ['id' => 1, 'device_id' => 42, 'rule_id' => 3, 'hostname' => 'fixture.invalid', 'state' => 1, 'timestamp' => '2020-01-01 00:00:00'];
        $db = $this->connection([$row, $row]);
        $db->shouldReceive('rollBack')->once();
        $db->shouldNotReceive('commit');
        $this->assertClosed(AlertCorrelationFixture::profile(), 'CORRELATION_COLLECTION_INVALID');
    }

    public function testChangedPdoAfterBeginIsRefusedBeforeAnySelect(): void
    {
        $db = Mockery::mock(Connection::class);
        $original = Mockery::mock(PDO::class);
        $original->shouldReceive('inTransaction')->andReturn(false);
        $replacement = Mockery::mock(PDO::class);
        DB::shouldReceive('connection')->once()->andReturn($db);
        $db->shouldReceive('transactionLevel')->once()->andReturn(0);
        $db->shouldReceive('getPdo')->twice()->andReturn($original, $replacement);
        $db->shouldReceive('statement')->once()->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('rollBack')->once();
        $db->shouldNotReceive('select');
        $db->shouldNotReceive('table');
        $this->assertClosed(AlertCorrelationFixture::profile(), 'CORRELATION_COLLECTION_FAILED');
    }

    public function testBeginCallbackFailureStillAttemptsOwnedRollback(): void
    {
        $db = Mockery::mock(Connection::class);
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(false);
        DB::shouldReceive('connection')->once()->andReturn($db);
        $db->shouldReceive('transactionLevel')->once()->andReturn(0);
        $db->shouldReceive('getPdo')->once()->andReturn($pdo);
        $db->shouldReceive('statement')->once()->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $db->shouldReceive('beginTransaction')->once()->andThrow(new RuntimeException('SENSITIVE_TEST_CANARY'));
        $db->shouldReceive('rollBack')->once();
        $db->shouldNotReceive('select');
        $this->assertClosed(AlertCorrelationFixture::profile(), 'CORRELATION_COLLECTION_FAILED');
    }

    public function testForeignTransactionIsNeverRolledBack(): void
    {
        $db = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->once()->andReturn($db);
        $db->shouldReceive('transactionLevel')->once()->andReturn(1);
        $db->shouldNotReceive('rollBack');
        $db->shouldNotReceive('statement');
        $this->assertClosed(AlertCorrelationFixture::profile(), 'CORRELATION_COLLECTION_INVALID');
    }

    private function assertClosed(JsonResponse|StreamedResponse $response, string $code, int $status = 503): void
    {
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame(['status' => 'error', 'contract' => 'msp-correlation-v1', 'complete' => false, 'code' => $code], $response->getData(true));
        $this->assertStringNotContainsString('SENSITIVE_TEST_CANARY', $response->getContent());
    }

    /**
     * @param  iterable<object>  $rows
     * @return Connection&MockInterface
     */
    private function connection(iterable $rows): Connection
    {
        $db = Mockery::mock(Connection::class);
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(false);
        DB::shouldReceive('connection')->once()->andReturn($db);
        $db->shouldReceive('transactionLevel')->once()->andReturn(0);
        $db->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $db->shouldReceive('statement')->once()->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('select')->once()->andReturn(array_map(static fn ($name) => (object) ['name' => $name, 'engine' => 'InnoDB'], ['alert_faults', 'devices', 'alert_rules']));
        $query = Mockery::mock(Builder::class);
        $db->shouldReceive('table')->once()->with('alert_faults as A')->andReturn($query);
        $query->shouldReceive('join')->once()->with('devices as D', 'D.device_id', '=', 'A.device_id')->andReturnSelf();
        $query->shouldReceive('join')->once()->with('alert_rules as R', 'R.id', '=', 'A.rule_id')->andReturnSelf();
        $query->shouldReceive('select')->once()->with(['A.id', 'A.device_id', 'A.rule_id', 'A.state', 'A.timestamp'])->andReturnSelf();
        $query->shouldReceive('selectRaw')->once()->with('CASE WHEN OCTET_LENGTH(D.hostname) <= 1024 THEN D.hostname ELSE NULL END AS hostname')->andReturnSelf();
        $query->shouldReceive('whereIn')->once()->with('A.state', [0, 1])->andReturnSelf();
        $query->shouldReceive('lazyById')->once()->with(256, 'A.id', 'id')->andReturn(new LazyCollection(static function () use ($rows) { yield from $rows; }));

        return $db;
    }

    /** @return iterable<object> */
    private function rows(int $count, string $hostname = 'fixture.invalid'): iterable
    {
        for ($id = 1; $id <= $count; $id++) {
            yield (object) ['id' => $id, 'device_id' => 42, 'rule_id' => 3, 'hostname' => $hostname, 'state' => $id % 2, 'timestamp' => '2020-01-01 00:00:00'];
        }
    }
}
