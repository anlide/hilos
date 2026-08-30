<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: the three runtime-row readers of the full-row group
 * mint stubs the same way a frame reader does, and PAYLOAD-SENTINEL has to report
 * all of them with the full-row cure. The class inherits nothing on purpose — the
 * rule judges the name of the method, never the hierarchy around it.
 */
final class RowReaderSentinelSamples
{
    private string $id = '';

    private int $jobsDone = 0;

    private string $note = 'none';

    /**
     * @param array<string, mixed> $row Runtime row to read
     * @return self Sample built from the row
     */
    public static function fromRow(array $row): self
    {
        $state = new self();
        $state->id = (string)($row['id'] ?? '');

        return $state;
    }

    /**
     * @param array<string, mixed> $row Runtime row to read
     */
    public function hydrateBase(array $row): void
    {
        $this->jobsDone = (int)($row['jobsDone'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row Runtime row to read
     */
    public function hydrateOwn(array $row): void
    {
        $this->note = isset($row['note']) ? (string)$row['note'] : "";
    }
}
