<?php

declare(strict_types=1);

namespace Basis\Picodata;

use Basis\Picodata\Map\Mapper;

/**
 * Automatic, loss-free change registration (the sharding.php idea in SQL).
 *
 * Every registered write runs atomically with its journal rows: if no
 * transaction is open, one is started just for the write + journal inserts,
 * guaranteeing a change row appears exactly when the data change commits.
 * Tables (`picodata_change`, `picodata_subscription`) are created lazily on
 * the first registration; until then writes carry zero journaling overhead.
 */
final class Changes
{
    public const CHANGE_TABLE = 'picodata_change';
    public const SUBSCRIPTION_TABLE = 'picodata_subscription';

    /** Explicit, dependency-free journal DDL (mirrors Change/Subscription). */
    private const DDL = [
        'CREATE TABLE ' . self::CHANGE_TABLE . ' (id TEXT PRIMARY KEY, listener TEXT, tablename TEXT, action TEXT, data TEXT, context TEXT, created_at DATETIME) USING memtx DISTRIBUTED GLOBALLY',
        'CREATE INDEX picodata_change_listener_idx ON ' . self::CHANGE_TABLE . ' USING TREE (listener, id)',
        'CREATE TABLE ' . self::SUBSCRIPTION_TABLE . ' (id TEXT PRIMARY KEY, listener TEXT, tablename TEXT) USING memtx DISTRIBUTED GLOBALLY',
        'CREATE UNIQUE INDEX picodata_subscription_listener_tablename_idx ON ' . self::SUBSCRIPTION_TABLE . ' USING TREE (listener, tablename)',
    ];

    /** @var array<string, list<string>|null> table => listeners cache */
    private array $cache = [];

    private bool $ready = false;
    /** @var \Closure|array */
    private \Closure|array $context = [];

    /**
     * @param \Closure(): bool $inTransaction reports open transaction level > 0
     */
    public function __construct(
        private Driver $driver,
        private \Closure $inTransaction,
        private ?Mapper $mapper = null,
    ) {
    }

    /**
     * Subscribe $listener to changes of $table. '*' means every table;
     * fnmatch globs ('auth_*', 'log_?') match by pattern, case-insensitively.
     */
    public function register(string $table, string $listener = 'default'): void
    {
        $this->ensure();
        $this->driver->statement(
            'INSERT INTO ' . self::SUBSCRIPTION_TABLE . ' (id, listener, tablename) VALUES (?,?,?) ON CONFLICT DO NOTHING',
            [self::uuid(), $listener, $table],
        );
        $this->cache = [];
    }

    /** @return list<string> listener names watching $table */
    public function listeners(string $table): array
    {
        if (!isset($this->cache[$table])) {
            if (!$this->ready && !$this->exists()) {
                return $this->cache[$table] = [];
            }

            $listeners = [];
            foreach ($this->driver->statement('SELECT listener, tablename FROM ' . self::SUBSCRIPTION_TABLE)->all() as $row) {
                $row = self::arr($row);
                if (self::matches((string) ($row['tablename'] ?? ''), $table)) {
                    $listeners[(string) $row['listener']] = (string) $row['listener'];
                }
            }

            return $this->cache[$table] = array_values($listeners);
        }

        return $this->cache[$table];
    }

    public function applies(string $table): bool
    {
        return $this->listeners($table) !== [];
    }

    /**
     * Run $work (called as $work(true), returning [result, ?change] where
     * change is ['action' => ..., 'data' => ...] or null for no-ops that must
     * not be journaled) atomically with its journal rows. Opens a transaction
     * unless one is already active. Returns $work's result.
     *
     * @param \Closure(bool): array{0: mixed, 1: ?array{action: string, data: array}} $work
     */
    public function guard(string $table, \Closure $work): mixed
    {
        $this->ensure();
        $own = !($this->inTransaction)();

        if ($own) {
            $this->driver->statement('BEGIN');
        }

        try {
            [$result, $change] = $work(true);

            if (is_array($change)) {
                $this->record($table, $change['action'], $change['data']);
            }

            if ($own) {
                $this->driver->statement('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->driver->statement('ROLLBACK');
            }

            throw $e;
        }

        return $result;
    }

    /** @return list<Change> pending changes for $listener, oldest first */
    public function get(string $listener, int $limit = 100): array
    {
        $this->ensure();

        $rows = $this->driver
            ->statement(
                'SELECT * FROM ' . self::CHANGE_TABLE . ' WHERE listener = ? ORDER BY id LIMIT ' . $limit,
                [$listener],
            )
            ->all();

        $mapper = $this->mapper ??= new Mapper();

        return array_map(fn (array $row): Change => $mapper->hydrate(Change::class, self::arr($row)), $rows);
    }

    /** @param list<string> $ids @return int affected */
    public function ack(array $ids): int
    {
        $this->ensure();
        if ($ids === []) {
            return 0;
        }

        return $this->driver
            ->statement(
                'DELETE FROM ' . self::CHANGE_TABLE . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids,
            )
            ->rowCount();
    }

    /** Metadata attached to every journal row (array or callable snapshot). */
    public function setContext(array|callable $context): void
    {
        $this->context = is_array($context) ? $context : \Closure::fromCallable($context);
    }

    /** Drop the in-process subscription cache (after external registrations). */
    public function refresh(): void
    {
        $this->cache = [];
    }

    /** Create journal tables when absent (idempotent). */
    public function ensure(): void
    {
        if ($this->ready) {
            return;
        }

        $exists = $this->exists();
        $existsSub = $this->exists(self::SUBSCRIPTION_TABLE);

        if ($exists && $existsSub) {
            $this->ready = true;

            return;
        }

        [$ddlChange, $ddlChangeIdx, $ddlSub, $ddlSubIdx] = self::DDL;
        foreach ([
            $exists ? null : $ddlChange,
            $exists ? null : $ddlChangeIdx,
            $existsSub ? null : $ddlSub,
            $existsSub ? null : $ddlSubIdx,
        ] as $sql) {
            if ($sql !== null) {
                $this->driver->statement($sql);
            }
        }

        $this->ready = true;
    }

    private function exists(string $table = self::CHANGE_TABLE): bool
    {
        return $this->driver
            ->statement('SELECT name FROM _pico_table WHERE name = ?', [$table])
            ->first() !== null;
    }

    /** Does a subscription pattern cover $table? Exact name, '*' or fnmatch glob. */
    private static function matches(string $pattern, string $table): bool
    {
        $pattern = strtolower($pattern);
        if ($pattern === '') {
            return false;
        }
        if (strpbrk($pattern, '*?[') === false) {
            return $pattern === strtolower($table);
        }

        return fnmatch($pattern, strtolower($table));
    }

    private function record(string $table, string $action, array $data): void
    {
        $context = $this->context instanceof \Closure ? (($this->context)()) : $this->context;

        foreach ($this->listeners($table) as $listener) {
            $this->driver->statement(
                'INSERT INTO ' . self::CHANGE_TABLE . ' (id, listener, tablename, action, data, context, created_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)',
                [
                    self::uuid(),
                    $listener,
                    $table,
                    $action,
                    self::json($data),
                    self::json($context ?: []),
                ],
            );
        }
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** High-water mark of the last emitted millisecond, for monotonic ids. */
    private static ?int $lastMs = null;

    /** Per-millisecond sequence, kept ascending within the process. */
    private static int $seq = 0;

    /**
     * Version 7 (time-ordered) UUID: a 48-bit big-endian millisecond timestamp, a
     * 12-bit per-process monotonic sequence, then random bits. Both lexicographic and
     * numeric ordering match creation order, so `ORDER BY id` replays the journal in
     * the exact sequence the changes were recorded, including sub-millisecond bursts.
     */
    private static function uuid(): string
    {
        $ms = (int) (microtime(true) * 1000);

        if (self::$lastMs === null || $ms > self::$lastMs) {
            self::$lastMs = $ms;
            self::$seq = random_int(0, 0xff);
        } elseif ($ms < self::$lastMs) {
            $ms = self::$lastMs; // clock regression: hold the high-water mark
        }

        if ($ms === self::$lastMs && ++self::$seq > 0xfff) {
            self::$lastMs = ++$ms;
            self::$seq = 0;
        }

        $b = random_bytes(16);
        $b[0] = chr(($ms >> 40) & 0xff);
        $b[1] = chr(($ms >> 32) & 0xff);
        $b[2] = chr(($ms >> 24) & 0xff);
        $b[3] = chr(($ms >> 16) & 0xff);
        $b[4] = chr(($ms >> 8) & 0xff);
        $b[5] = chr($ms & 0xff);
        $b[6] = chr(0x70 | ((self::$seq >> 8) & 0x0f));
        $b[7] = chr(self::$seq & 0xff);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function arr(mixed $row): array
    {
        return is_object($row) ? get_object_vars($row) : (array) $row;
    }
}
