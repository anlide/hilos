<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * Look-alikes RANDOM-SOURCE has to stay silent on: the secure pair, which is legal
 * in any file and is the whole point of the split; the helper named without a call
 * behind it; a tolerant method spelled on some other class; and the same call
 * quoted in a string or written in a comment, where it is text and not a call.
 */
final class RandomSourceLookAlikes
{
    /**
     * @return string Secret drawn from the axis that refuses instead of degrading
     * @throws RandomException When the platform's secure random source refuses
     */
    public function secret(): string
    {
        return RandomHelper::secureHex(16) . bin2hex(RandomHelper::secureBytes(8));
    }

    /**
     * @return array<int, mixed> Spellings that only look like a tolerant call
     */
    public function lookAlikes(): array
    {
        // RandomHelper::hex(8) written in a comment is a mention, not a call
        return [
            RandomHelper::class,
            self::hex(4),
            'RandomHelper::bytes(16)',
        ];
    }

    /**
     * @param int $length Hex characters to hand back
     * @return string This class's own hex, which the helper's rule has no say over
     */
    private static function hex(int $length): string
    {
        return str_repeat('a', $length);
    }
}
