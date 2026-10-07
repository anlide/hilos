<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/**
 * Seeds a child that narrows its parent's tag, whose own record must win.
 *
 * @property-read NarrowRegistry $store
 * @property-read NarrowRegistry $shared
 * @property-read NarrowRegistry $phantom
 */
final class DocNarrowed extends DocTyped
{
    /**
     * @return string Name read through the narrowed static type
     */
    public function readsTheNarrowedStaticType(): string
    {
        return self::$shared->name();
    }

    /**
     * @return string Name read through a tag with no static declaration
     */
    public function readsThePhantomStaticTag(): string
    {
        return self::$phantom->name();
    }

    /**
     * @return string Name read through the narrowed tag
     */
    public function readsTheNarrowedTag(): string
    {
        return $this->store->name();
    }
}
