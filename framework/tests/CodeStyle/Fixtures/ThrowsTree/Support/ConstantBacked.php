<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/** Seeds a magic receiver whose class is named by the collection's own constant. */
final class ConstantBacked extends CollectionBase
{
    public const string OBJECT_COLLECTION_CLASS = Registry::class;

    /**
     * @return string Name read through the constant-backed receiver
     */
    public function readsThroughTheConstant(): string
    {
        return $this->objectCollection->name();
    }
}
