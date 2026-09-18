<?php

declare(strict_types=1);

namespace Basis\Picodata\Test\Integration;

use Basis\Picodata\Map\ClassFactory;
use Basis\Picodata\Picodata;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests against a live Picodata cluster started by docker-compose.yml
 * (two nodes: picodata-1-1 / picodata-1-2, pg proto 5432, admin/T0psecret).
 * Override PICODATA_DSN / PICODATA_DSN2 for a different deployment. Skipped when
 * ext-pgsql or the cluster is unavailable, so the default suite stays green anywhere.
 *
 * The DSN uses the URI ("postgresql://") form on purpose: Picodata's pgproto
 * currently rejects libpq's keyword-form ("host=… user=…") StartupMessage with
 * 28P01 while the semantically identical URI form authenticates. See README.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected ?Picodata $db = null;

    protected static function dsn(int $node = 1): string
    {
        $env = getenv($node === 1 ? 'PICODATA_DSN' : 'PICODATA_DSN2');

        return $env !== false && $env !== ''
            ? $env
            : sprintf('postgresql://admin:T0psecret@picodata-1-%d:5432', $node);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('pgsql')) {
            self::markTestSkipped('ext-pgsql is required for integration tests');
        }

        $connection = @pg_connect(static::dsn());
        if ($connection === false) {
            self::markTestSkipped('Picodata cluster not reachable at ' . static::dsn());
        }
        pg_close($connection);

        ClassFactory::forget();
        $this->db = Picodata::connect(static::dsn());
    }

    protected function tearDown(): void
    {
        $this->db = null;
        ClassFactory::forget();
    }

    /** @param list<string> $tables */
    protected function dropAll(array $tables): void
    {
        if ($this->db === null) {
            return;
        }

        foreach ($tables as $table) {
            $this->db->execute("DROP TABLE IF EXISTS {$table}");
        }
    }
}
