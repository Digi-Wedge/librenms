<?php

namespace App\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class AlertCorrelationMetadata
{
    public const PROFILE = 'msp-correlation-v1';
    private const BATCH_ROWS = 256;
    private const MAX_ROWS = 65536;
    private const MAX_BYTES = 16777216;
    private const LIMIT = 'CORRELATION_COLLECTION_LIMIT';
    private const INVALID = 'CORRELATION_COLLECTION_INVALID';

    public static function respond(Request $request): JsonResponse|StreamedResponse
    {
        $query = $request->query->all();
        if (count($query) !== 2 || ($query['profile'] ?? null) !== self::PROFILE
            || ($query['state'] ?? null) !== '0,1' || $request->route('id') !== null) {
            return self::error('CORRELATION_PROFILE_REQUEST', 400);
        }

        $spool = null;
        $db = null;
        $started = false;
        $pdo = null;
        try {
            $db = DB::connection();
            if ($db->transactionLevel() !== 0) {
                throw new RuntimeException(self::INVALID);
            }
            $spool = @tmpfile();
            if ($spool === false) {
                throw new RuntimeException('spool');
            }
            // Only the next transaction is affected; no session or global policy changes.
            $pdo = $db->getPdo();
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            // beginTransaction listeners can throw after PDO BEGIN/tracking increment.
            $started = true;
            $db->beginTransaction();
            if ($db->getPdo() !== $pdo) {
                throw new RuntimeException('transaction identity');
            }
            $engines = $db->select("SELECT TABLE_NAME AS name, ENGINE AS engine
                FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('alert_faults', 'devices', 'alert_rules')");
            $names = [];
            foreach ($engines as $table) {
                if ($table->engine !== 'InnoDB') {
                    throw new RuntimeException(self::INVALID);
                }
                $names[] = $table->name;
            }
            sort($names);
            if ($names !== ['alert_faults', 'alert_rules', 'devices']) {
                throw new RuntimeException(self::INVALID);
            }

            $bytes = 0;
            self::write($spool, '{"status":"ok","contract":"' . self::PROFILE
                . '","states":[0,1],"consistency":"repeatable-read","alerts":[', $bytes);
            // Query builder avoids AlertFault's compressed JSON and timestamp model casts.
            // SQL bounds even an unexpectedly oversized hostname before PDO buffers a batch.
            $rows = $db->table('alert_faults as A')
                ->join('devices as D', 'D.device_id', '=', 'A.device_id')
                ->join('alert_rules as R', 'R.id', '=', 'A.rule_id')
                ->select(['A.id', 'A.device_id', 'A.rule_id', 'A.state', 'A.timestamp'])
                ->selectRaw('CASE WHEN OCTET_LENGTH(D.hostname) <= 1024 THEN D.hostname ELSE NULL END AS hostname')
                ->whereIn('A.state', [0, 1])
                ->lazyById(self::BATCH_ROWS, 'A.id', 'id');
            $count = 0;
            $previous = 0;
            foreach ($rows as $row) {
                if (++$count > self::MAX_ROWS) {
                    throw new RuntimeException(self::LIMIT);
                }
                $value = self::row($row);
                if ($value['id'] <= $previous) {
                    throw new RuntimeException(self::INVALID);
                }
                $previous = $value['id'];
                self::write($spool, ($count === 1 ? '' : ',')
                    . json_encode($value, JSON_THROW_ON_ERROR), $bytes);
            }
            self::write($spool, '],"count":' . $count . ',"complete":true}', $bytes);
            if (! @rewind($spool)) {
                throw new RuntimeException('spool');
            }
            // Nothing is emitted before complete iteration, encoding and successful commit.
            $db->commit();
            $started = false;
            $body = $spool;
            $response = response()->stream(static function () use ($body, $bytes): void {
                try {
                    if (@fpassthru($body) !== $bytes) {
                        throw new RuntimeException('CORRELATION_RESPONSE_FAILED');
                    }
                } finally {
                    fclose($body);
                }
            }, 200, ['Content-Type' => 'application/json', 'Content-Length' => (string) $bytes,
                'Cache-Control' => 'no-store']);
            $spool = null;

            return $response;
        } catch (Throwable $error) {
            if ($started) {
                try {
                    $db->rollBack();
                } catch (Throwable) {
                    // The caller still receives failure, never a partial collection.
                }
                try {
                    if ($pdo !== null && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable) {
                    // Also close the original owned PDO if a callback replaced it.
                }
            }
            if (is_resource($spool)) {
                fclose($spool);
            }
            $code = $error instanceof RuntimeException
                && in_array($error->getMessage(), [self::LIMIT, self::INVALID], true)
                ? $error->getMessage() : 'CORRELATION_COLLECTION_FAILED';

            return self::error($code, 503);
        }
    }

    /** @return array<string, int|string> */
    private static function row(object $row): array
    {
        $value = [];
        foreach (['id', 'device_id', 'rule_id'] as $key) {
            $raw = $row->{$key} ?? null;
            if ((! is_int($raw) && ! is_string($raw))
                || ! preg_match('/^[1-9][0-9]{0,15}$/D', (string) $raw)
                || (int) $raw > 9007199254740991) {
                throw new RuntimeException(self::INVALID);
            }
            $value[$key] = (int) $raw;
        }
        $state = $row->state ?? null;
        if (! in_array($state, [0, 1, '0', '1'], true)) {
            throw new RuntimeException(self::INVALID);
        }
        $host = $row->hostname ?? null;
        $at = $row->timestamp ?? null;
        if (! is_string($host) || $host === '' || strlen($host) > 1024
            || preg_match('//u', $host) !== 1 || ! is_string($at)
            || ! preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?$/D', $at, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            throw new RuntimeException(self::INVALID);
        }

        return $value + ['hostname' => $host, 'state' => (int) $state, 'timestamp' => $at];
    }

    /** @param resource $spool */
    private static function write($spool, string $text, int &$bytes): void
    {
        $size = strlen($text);
        if ($bytes + $size > self::MAX_BYTES) {
            throw new RuntimeException(self::LIMIT);
        }
        if (@fwrite($spool, $text) !== $size) {
            throw new RuntimeException('spool');
        }
        $bytes += $size;
    }

    private static function error(string $code, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'contract' => self::PROFILE,
            'complete' => false, 'code' => $code], $status);
    }
}
