<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/**
 * A constructor documenting more than the base one does, which must stay silent: PHP
 * exempts the constructor from the override contract, and nobody reaches this one
 * through {@see Constructed}.
 *
 * `OtherException` is declared because the `parent::__construct()` call really does
 * propagate it — that half of the rule still applies here.
 */
final class WidenedConstruct extends Constructed
{
    /**
     * @param string $name Name the value stands for
     * @throws NarrowException When the name is refused for a reason of this subclass
     * @throws OtherException When the name is refused
     */
    public function __construct(string $name)
    {
        parent::__construct($name);
    }
}
