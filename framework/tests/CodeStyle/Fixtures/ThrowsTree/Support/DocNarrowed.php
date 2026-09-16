<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/**
 * Seeds a child that narrows its parent's tag, whose own record must win.
 *
 * @property-read NarrowRegistry $store
 */
final class DocNarrowed extends DocTyped
{
    /**
     * @return string Name read through the narrowed tag
     */
    public function readsTheNarrowedTag(): string
    {
        return $this->store->name();
    }
}
