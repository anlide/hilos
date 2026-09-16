<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/** Seeds a magic receiver typed only by the tag its parent writes down. */
final class DocInherited extends DocTyped
{
    /**
     * @return string Name read through the inherited tag
     */
    public function readsTheInheritedTag(): string
    {
        return $this->store->name();
    }
}
