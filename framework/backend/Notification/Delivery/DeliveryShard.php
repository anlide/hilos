<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Core\Exception\InvalidArgumentException;

/** Reduces a saved source key against the current pool width. */
final class DeliveryShard
{
    /**
     * @param int $routingKey Nonnegative source key
     * @param int $poolSize Positive current pool width
     * @return int One-based pool index
     * @throws InvalidArgumentException When the source key is negative or the pool width is not positive
     */
    public static function indexFor(int $routingKey, int $poolSize): int
    {
        if ($routingKey < 0 || $poolSize < 1) {
            throw new InvalidArgumentException('Delivery sharding requires a nonnegative key and a positive pool size');
        }

        return 1 + ($routingKey % $poolSize);
    }
}
