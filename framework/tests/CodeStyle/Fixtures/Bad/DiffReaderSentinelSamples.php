<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: the three diff readers seed both findings. Each of
 * them mints a stub, in all three spellings, and two of them also read a key with
 * the `optional*` family, which answers null to a key the diff never carried. The
 * reports must carry the diff cure, not the full-row one.
 */
final class DiffReaderSentinelSamples
{
    private string $id = 'row';

    private int $jobsDone = 0;

    private float $weight = 1.5;

    private ?string $name = null;

    private ?int $seenAt = null;

    /**
     * @param array<string, mixed> $diff Fields the update carries
     */
    public function applyDiff(array $diff): void
    {
        $this->id = (string)($diff['id'] ?? '');
        $this->name = self::optionalString($diff, 'name');
    }

    /**
     * @param array<string, mixed> $diff Fields the update carries
     */
    public function applyBaseDiff(array $diff): void
    {
        $this->jobsDone = match (true) {
            isset($diff['jobsDone']) => (int)$diff['jobsDone'],
            default => 0,
        };
    }

    /**
     * @param array<string, mixed> $diff Fields the update carries
     */
    public function applyOwnDiff(array $diff): void
    {
        $this->weight = (float)($diff['weight'] ?? 0.0);
        $this->seenAt = self::optionalInt($diff, 'seenAt');
    }
}
