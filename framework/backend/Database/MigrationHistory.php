<?php

declare(strict_types=1);

namespace Hilos\Database;

/** A present migration table; its absence is represented by null at the read boundary. */
final readonly class MigrationHistory
{
    /**
     * @param list<MigrationRecord> $rows Actual rows, including every failed row
     */
    public function __construct(public array $rows)
    {
    }

    /**
     * @return ?int Highest successful row, including explicit zero; null when no successful row exists
     */
    public function level(): ?int
    {
        $successful = $this->successfulIndices();
        return $successful === [] ? null : max($successful);
    }

    /**
     * @return list<int> Every recorded index in row order
     */
    public function recordedIndices(): array
    {
        return array_map(static fn (MigrationRecord $row): int => $row->index, $this->rows);
    }

    /**
     * @return list<int> Exact successful indices, ascending
     */
    public function successfulIndices(): array
    {
        $indices = [];
        foreach ($this->rows as $row) {
            if (!$row->failed) {
                $indices[] = $row->index;
            }
        }
        sort($indices);
        return $indices;
    }

    /**
     * @return list<int> Every failed index, ascending
     */
    public function failedIndices(): array
    {
        $indices = [];
        foreach ($this->rows as $row) {
            if ($row->failed) {
                $indices[] = $row->index;
            }
        }
        sort($indices);
        return $indices;
    }
}
