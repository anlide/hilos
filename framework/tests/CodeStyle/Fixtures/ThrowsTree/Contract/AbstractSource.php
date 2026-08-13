<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/**
 * Seeds the "implementation wider than the contract" half: `start()` documents an
 * exception {@see SourceInterface} says nothing about, so everyone reading through
 * the interface is sure the call is safe.
 */
abstract class AbstractSource implements SourceInterface
{
    /**
     * @return bool True when the source came up
     * @throws OtherException When the socket cannot be bound
     */
    public function start(): bool
    {
        return true;
    }
}
