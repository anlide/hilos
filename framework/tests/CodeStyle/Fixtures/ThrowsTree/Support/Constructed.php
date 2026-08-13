<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/**
 * Seeds `new Bar()`, whose contract is the constructor rather than a named method.
 */
class Constructed
{
    /**
     * @param string $name Name the value stands for
     * @throws OtherException When the name is refused
     */
    public function __construct(public string $name)
    {
    }
}
