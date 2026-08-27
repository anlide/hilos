<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Database\View\Item;

use Hilos\Database\Object\Item\Object_;

/**
 * Deliberately broken sample: a database wrapper that binds its row to the variable it
 * was handed instead of holding the row itself. Both halves of the rule stand here —
 * the storage that follows a variable and the signature that forces the caller to
 * produce one — so every line below must be reported by VIEW-WRAPPER-BIND.
 */
final class ByRefWrapperSamples
{
    /** @var Object_ Row this wrapper shows, bound the way the framework used to bind it */
    private Object_ $_object;

    /**
     * @param Object_ $object Row the wrapper is built from
     */
    public function __construct(Object_ &$object)
    {
        $this->_object = &$object;
    }

    /**
     * A wrapper rebound after construction: the same trap, reached by a setter rather
     * than by the constructor.
     *
     * @param Object_ $object Row the wrapper is pointed at instead
     */
    public function rebind(Object_ &$object): void
    {
        $this->_object = &$object;
    }
}
