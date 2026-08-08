<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: every number below is written more than once in this
 * file, so MAGIC-REPEAT must report each occurrence — the pair that sits in one
 * method, the pair spread over two, and the pair inside an array that has no keys
 * to name it.
 */
final class MagicRepeatSamples
{
    /**
     * @param int $seconds Wait expressed in seconds
     * @return int Wait expressed in microseconds
     */
    public function toMicroseconds(int $seconds): int
    {
        return $seconds * 1000 * 1000;
    }

    /**
     * @param float $milliseconds Budget expressed in milliseconds
     * @return bool True when the budget is spent
     */
    public function isSpent(float $milliseconds): bool
    {
        return $milliseconds > 2000.0;
    }

    /**
     * @return float Default budget in milliseconds
     */
    public function defaultBudget(): float
    {
        return 2000.0;
    }

    /**
     * A list has no key to name its elements, so the exemption for entry values does
     * not reach it and the repeat is still a repeat.
     *
     * @return array<int, int> Retry delays in milliseconds
     */
    public function retryDelaysMs(): array
    {
        return [3000, 3000];
    }

    /**
     * The `=>` of an arrow function is not the `=>` of an array entry, and these
     * elements have no keys either — so the exemption must not reach them.
     *
     * @return array<int, callable(): int> Budgets in milliseconds, each behind a thunk
     */
    public function lazyBudgetsMs(): array
    {
        return [
            static fn (): int => 4000,
            static fn (): int => 4000,
        ];
    }
}
