<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;

/**
 * A trait that satisfies an interface method for whoever uses it. The using class
 * names the method nowhere, so the widening it carries is only visible if the rule
 * looks through the trait as well.
 */
trait WideningTrait
{
    /**
     * @return string Payload read from the source
     * @throws NarrowException When the source refuses to answer
     */
    public function read(): string
    {
        return 'read';
    }

    /**
     * @return bool True when the source came up
     * @throws NarrowException When the source refuses to come up
     */
    public function start(): bool
    {
        return true;
    }
}
