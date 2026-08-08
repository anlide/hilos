<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Negative sample: nothing here is a repeated magic number. A value read once is a
 * description, the allowlisted values carry no unit, a `const` declaration is the
 * cure rather than the disease, a keyed array entry is named by its key however
 * deep inside its value the number sits, strings are outside the rule altogether,
 * and digits inside a string literal are not number tokens at all.
 */
final class MagicRepeatClean
{
    private const int MS_PER_SECOND = 1000;

    private const int US_PER_MILLISECOND = 1000;

    /**
     * @param int $seconds Wait expressed in seconds
     * @return int Wait expressed in microseconds
     */
    public function toMicroseconds(int $seconds): int
    {
        return $seconds * self::MS_PER_SECOND * self::US_PER_MILLISECOND;
    }

    /**
     * @param array<int, string> $items Items to fold
     * @return array<int, string> Every second item, longest first
     */
    public function everySecond(array $items): array
    {
        $folded = [];
        for ($index = 0; $index < count($items); $index += 2) {
            $folded[] = $items[$index];
        }
        usort($folded, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        return array_slice($folded, 0, count($folded) - 1);
    }

    /**
     * @return array<int, string> Text that merely mentions numbers
     */
    public function describe(): array
    {
        return [
            'timeout',
            'timeout',
            'timeout',
            'waited 3000 ms, then 3000 ms more, giving up after 3000 ms',
        ];
    }

    /**
     * A catalog of defaults: two entries that happen to share a budget are two
     * quantities, and each is named by the key it hangs on — including when the
     * number is buried in a call that builds the entry.
     *
     * @return array<string, array{type: string, default: int}> Defaults by setting name
     */
    public function defaults(): array
    {
        return [
            'mail_timeout_ms' => self::intEntry(10000),
            'sms_timeout_ms' => self::intEntry(10000),
            'session_max_age_sec' => self::intEntry(30 * 24 * 60 * 60),
        ];
    }

    /**
     * @param int $default Value the setting takes when the environment says nothing
     * @return array{type: string, default: int} Catalog entry
     */
    private static function intEntry(int $default): array
    {
        return ['type' => 'integer', 'default' => $default];
    }
}
