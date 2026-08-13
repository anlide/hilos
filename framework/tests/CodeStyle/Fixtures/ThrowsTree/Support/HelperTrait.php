<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/**
 * A trait carrying a contract, so the resolution order has a step to prove between
 * the class itself and its parents.
 */
trait HelperTrait
{
    /**
     * @throws OtherException When the helper's own step refuses
     */
    public function helpFromTrait(): void
    {
    }
}
