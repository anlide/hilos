<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;

/**
 * The contract half of the seeded tree: `read()` declares what it throws and
 * `start()` deliberately declares nothing over an implementation that throws, which
 * is the defect the rule was written for.
 */
interface SourceInterface
{
    /**
     * @return string Payload read from the source
     * @throws NarrowException When the source refuses to answer
     */
    public function read(): string;

    /**
     * @return bool True when the source came up
     */
    public function start(): bool;
}
