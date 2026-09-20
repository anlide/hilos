<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\{NarrowException, OtherException};

/** Seeds a magic receiver whose class is named by the collection's own constant. */
final class ConstantBacked extends CollectionBase
{
    public const string OBJECT_COLLECTION_CLASS = Registry::class;

    /** @return string Name read through the constant-backed receiver */
    public function readsThroughTheConstant(): string
    {
        return $this->objectCollection->name();
    }

    /**
     * A typed step without a reader leaves the body unread, even with a dead tag.
     *
     * @return string Name read through the constant-backed receiver
     * @throws NarrowException A dead tag behind an unresolved read
     * @throws OtherException The live contract of Registry::name()
     */
    public function keepsADeadTagWithoutAMagicReader(): string
    {
        return $this->objectCollection->name();
    }
}
