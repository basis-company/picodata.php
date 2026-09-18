<?php

declare(strict_types=1);

namespace Basis\Picodata;

/**
 * Statement outcome: fetched rows and affected-row count.
 */
final class Result
{
    /**
     * @param array[] $rows    List of associative rows.
     * @param int     $affected Rows changed by the statement.
     */
    public function __construct(
        private array $rows = [],
        private int $affected = 0,
    ) {
    }

    /**
     * @return array[] All rows, each an associative array.
     */
    public function all(): array
    {
        return $this->rows;
    }

    /**
     * Affected row count.
     */
    public function rowCount(): int
    {
        return $this->affected;
    }

    /**
     * First row or null when empty.
     */
    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /**
     * Values of one column across all rows.
     */
    public function column(string $name): array
    {
        return array_map(
            static fn (array $row): mixed => $row[$name] ?? null,
            $this->rows,
        );
    }

    /**
     * Distinct column names, in order of first appearance.
     *
     * @return string[]
     */
    public function columns(): array
    {
        $seen = [];
        foreach ($this->rows as $row) {
            foreach (array_keys($row) as $key) {
                $seen[$key] = true;
            }
        }

        return array_keys($seen);
    }
}
