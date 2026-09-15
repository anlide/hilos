<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/** Seeds the same magic receiver without a class declaration, which must stay silent. */
final class ConstantMissing extends CollectionBase
{
    /**
     * @return string Name read through the undeclared receiver
     */
    public function readsThroughTheConstant(): string
    {
        return $this->objectCollection->name();
    }
}
