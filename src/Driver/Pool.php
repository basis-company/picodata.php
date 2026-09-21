<?php

declare(strict_types=1);

namespace Basis\Picodata\Driver;

use Basis\Picodata\Driver;
use Basis\Picodata\Exception\ConnectionException;
use Basis\Picodata\Exception\InvalidException;
use Basis\Picodata\Result;

/**
 * Multi-host failover driver: tries the given DSNs at random, switches to
 * the next host when the current one goes away, comes back to it once its
 * cooldown expires.
 *
 * Stateless by design — the host list is exactly what was passed in (or read
 * from an environment variable), nothing is discovered from the cluster and
 * nothing is persisted. A dead host is avoided only for `retryAfter` seconds
 * inside the current process; a fresh php-fpm worker starts with a clean
 * slate and shuffles the list again.
 *
 * Only ConnectionException (host unreachable / connection dropped) triggers
 * a failover; a statement the server rejected throws ExecutionException and
 * is never replayed on another host.
 */
final class Pool implements Driver
{
    /** @var list<string> Host order randomized at construction. */
    private array $dsns;

    /** @var array<int, Driver> Lazily built per-DSN drivers. */
    private array $drivers = [];

    /** @var array<int, float> index -> unix time until which the host is skipped. */
    private array $retryAt = [];

    private int $cursor = 0;

    /**
     * @param list<string>                                    $dsns          One DSN per host, e.g. postgresql://app:secret@host1:5432/picodata
     * @param float                                           $retryAfter    Seconds to skip a host after it failed.
     * @param (callable(string): Driver)|null                  $driverFactory Test seam; defaults to a lazy Pgsql per DSN.
     */
    public function __construct(
        array $dsns,
        private readonly float $retryAfter = 5.0,
        private readonly mixed $driverFactory = null,
    ) {
        $dsns = array_values(array_filter(
            array_map(static fn (string $dsn): string => trim($dsn), $dsns),
            static fn (string $dsn): bool => $dsn !== '',
        ));
        if ($dsns === []) {
            throw new InvalidException('Pool requires at least one DSN');
        }

        // spread concurrent php-fpm workers across hosts from the first statement on
        shuffle($dsns);
        $this->dsns = $dsns;
    }

    /**
     * Build a pool from a comma-separated DSN list in an environment variable,
     * e.g. PICODATA_DSN=postgresql://app:s@h1:5432/db,postgresql://app:s@h2:5432/db
     *
     * @throws InvalidException When the variable is unset or empty.
     */
    public static function fromEnv(
        string $variable = 'PICODATA_DSN',
        float $retryAfter = 5.0,
        ?callable $driverFactory = null,
    ): self {
        $value = getenv($variable);
        if ($value === false || trim($value) === '') {
            throw new InvalidException(sprintf('Environment variable "%s" is unset or empty', $variable));
        }

        return new self(explode(',', $value), $retryAfter, $driverFactory);
    }

    public function driverName(): string
    {
        return 'pgsql';
    }

    public function statement(string $sql, array $params = []): Result
    {
        $hosts = count($this->dsns);
        $last = null;

        for ($attempt = 0; $attempt < $hosts; $attempt++) {
            $index = $this->pick();
            try {
                $result = $this->driver($index)->statement($sql, $params);
            } catch (ConnectionException $e) {
                $this->retryAt[$index] = microtime(true) + max($this->retryAfter, 0.001);
                $last = $e;
                continue;
            }

            unset($this->retryAt[$index]);
            // next statement starts the rotation past the host we just used
            $this->cursor = ($index + 1) % $hosts;

            return $result;
        }

        throw new ConnectionException(
            sprintf(
                'All %d pool hosts are unreachable; last error: %s',
                $hosts,
                $last?->getMessage() ?? 'unknown',
            ),
            0,
            $last,
        );
    }

    /**
     * Next host to try: the first from the current rotation that is not in
     * cooldown. When every host is cooling down, the one recovering soonest
     * gets the probe.
     */
    private function pick(): int
    {
        $now = microtime(true);
        $hosts = count($this->dsns);

        for ($i = 0; $i < $hosts; $i++) {
            $index = ($this->cursor + $i) % $hosts;
            if (($this->retryAt[$index] ?? 0.0) <= $now) {
                return $index;
            }
        }

        $soonest = $this->cursor;
        $soonestAt = INF;
        foreach ($this->retryAt as $index => $at) {
            if ($at < $soonestAt) {
                $soonestAt = $at;
                $soonest = $index;
            }
        }

        return $soonest;
    }

    private function driver(int $index): Driver
    {
        if (!isset($this->drivers[$index])) {
            $factory = $this->driverFactory;
            if ($factory === null) {
                $factory = static fn (string $dsn): Driver => new Pgsql($dsn);
            }
            $this->drivers[$index] = $factory($this->dsns[$index]);
        }

        return $this->drivers[$index];
    }
}
