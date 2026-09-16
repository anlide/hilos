<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/**
 * Seeds magic receivers typed by a class-level tag: one naming a class the tree
 * declares, and one naming a generic placeholder no root declares, which must stay
 * silent.
 *
 * @property-read Registry $store
 * @property-read TStore $absent
 */
abstract class DocTyped
{
    /**
     * @return string Name read through the receiver whose tag names no known class
     */
    public function readsTheAbsentTag(): string
    {
        return $this->absent->name();
    }
}
