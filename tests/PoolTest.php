<?php

declare(strict_types=1);

namespace Basis\Picodata\Test;

use Basis\Picodata\Driver;
use Basis\Picodata\Driver\Pool;
use Basis\Picodata\Exception\ConnectionException;
use Basis\Picodata\Exception\ExecutionException;
use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Result;
use PHPUnit\Framework\TestCase;

final class StubPoolDriver implements Driver
{
    public int $calls = 0;

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function statement(string $sql, array $params = []): Result
    {
        $this->calls++;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new Result([['ok' => 1]], 1);
    }

    public function driverName(): string
    {
        return 'stub';
    }
}

final class PoolTest extends TestCase
{
    /** @param array<string, StubPoolDriver> $stubs dsn -> stub */
    private function pool(array $stubs, float $retryAfter = 5.0): Pool
    {
        return new Pool(
            array_keys($stubs),
            $retryAfter,
            static fn (string $dsn): Driver => $stubs[$dsn],
        );
    }

    public function testFailoverToHealthyHost(): void
    {
        $stubs = [
            'dsn-dead-1' => new StubPoolDriver(new ConnectionException('dead 1')),
            'dsn-dead-2' => new StubPoolDriver(new ConnectionException('dead 2')),
            'dsn-ok' => new StubPoolDriver(),
        ];
        $pool = $this->pool($stubs);

        $this->assertSame([['ok' => 1]], $pool->statement('SELECT 1')->all());
        $this->assertGreaterThanOrEqual(1, $stubs['dsn-ok']->calls);
        // each dead host is probed at most once per statement
        $this->assertLessThanOrEqual(1, $stubs['dsn-dead-1']->calls);
        $this->assertLessThanOrEqual(1, $stubs['dsn-dead-2']->calls);
    }

    public function testThrowsWhenEveryHostIsDown(): void
    {
        $stubs = [
            'a' => new StubPoolDriver(new ConnectionException('a is down')),
            'b' => new StubPoolDriver(new ConnectionException('b is down')),
            'c' => new StubPoolDriver(new ConnectionException('c is down')),
        ];
        $pool = $this->pool($stubs);

        try {
            $pool->statement('SELECT 1');
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('3 pool hosts are unreachable', $e->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }

        // every host tried exactly once for the single statement
        $this->assertSame(1, $stubs['a']->calls);
        $this->assertSame(1, $stubs['b']->calls);
        $this->assertSame(1, $stubs['c']->calls);
    }

    public function testServerRejectedStatementIsNotRetried(): void
    {
        // single host so the pick is deterministic: a rejected statement
        // must surface as-is, without the pool replaying it anywhere
        $stub = new StubPoolDriver(new ExecutionException('syntax error'));
        $pool = $this->pool(['a' => $stub]);

        try {
            $pool->statement('SELEKT 1');
            $this->fail('expected ExecutionException');
        } catch (ExecutionException $e) {
            $this->assertSame('syntax error', $e->getMessage());
        }

        $this->assertSame(1, $stub->calls, 'a rejected statement must not be retried');
    }

    public function testDeadHostIsSkippedWhileCoolingDown(): void
    {
        $stubs = [
            'dead' => new StubPoolDriver(new ConnectionException('down')),
            'ok' => new StubPoolDriver(),
        ];
        $pool = $this->pool($stubs);

        for ($i = 0; $i < 5; $i++) {
            $pool->statement('SELECT 1');
        }

        // the dead host is probed on the first statement at most, the rest
        // of the statements go straight to the healthy one (5 runs), plus
        // at most one failover during the first run
        $this->assertGreaterThanOrEqual(5, $stubs['ok']->calls);
        $this->assertLessThanOrEqual(1, $stubs['dead']->calls);
    }

    public function testEmptyDsnListIsRejected(): void
    {
        $this->expectException(InvalidException::class);
        new Pool([]);
    }

    public function testBlankEntriesAreDropped(): void
    {
        $this->expectException(ConnectionException::class);
        $pool = new Pool(['  ', 'postgresql://x@h1/db'], driverFactory: static fn (string $dsn): Driver => new class () implements Driver {
            public function statement(string $sql, array $params = []): Result
            {
                throw new ConnectionException('unreachable');
            }

            public function driverName(): string
            {
                return 'stub';
            }
        });

        $pool->statement('SELECT 1'); // single host, tried once, throws
    }

    public function testFromEnvRejectsUnsetVariable(): void
    {
        putenv('PICODATA_TEST_DSN');
        $this->expectException(InvalidException::class);
        Pool::fromEnv('PICODATA_TEST_DSN');
    }

    public function testFromEnvParsesCommaSeparatedList(): void
    {
        $stubs = [
            'a' => new StubPoolDriver(),
            'b' => new StubPoolDriver(),
        ];
        putenv('PICODATA_TEST_DSN=a,b');

        try {
            $pool = Pool::fromEnv(
                'PICODATA_TEST_DSN',
                driverFactory: static fn (string $dsn): Driver => $stubs[$dsn]
                    ?? throw new ConnectionException('unexpected dsn: ' . $dsn),
            );

            // both hosts are live, the rotation must hand out each of them
            for ($i = 0; $i < 4; $i++) {
                $pool->statement('SELECT 1');
            }

            $this->assertGreaterThanOrEqual(1, $stubs['a']->calls);
            $this->assertGreaterThanOrEqual(1, $stubs['b']->calls);
        } finally {
            putenv('PICODATA_TEST_DSN');
        }
    }

    public function testDriverName(): void
    {
        $pool = $this->pool(['a' => new StubPoolDriver()]);
        $this->assertSame('pgsql', $pool->driverName());
    }
}
