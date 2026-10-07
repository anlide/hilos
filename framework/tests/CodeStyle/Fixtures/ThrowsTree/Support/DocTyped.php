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
    /** Base receiver that a child's class tag may narrow on a static read. */
    public static ?Registry $shared = null;

    /**
     * @return string Name read through the declared base type
     */
    public function readsTheBaseStaticType(): string
    {
        return self::$shared->name();
    }

    /**
     * @return string Name read through the receiver whose tag names no known class
     */
    public function readsTheAbsentTag(): string
    {
        return $this->absent->name();
    }
}
