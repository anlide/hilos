<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Database\Object;

use Hilos\Database\Object\Item\Object_;

/**
 * Negative sample: the same writes of the row array, in the file that declares it. The
 * store owns its own storage — a rule that fired here would leave the ArrayAccess door
 * and the load seam with no way to do their work — so this file must report nothing.
 */
final class Objects
{
    /** @var array<int|string, Object_> Rows this store holds, keyed by primary key */
    private array $objects = [];

    /**
     * @param int|string $key Key written, dropped and then wiped along with the rest
     * @param Object_ $object Row put under that key
     */
    public function rewrite(int|string $key, Object_ $object): void
    {
        $this->objects[$key] = $object;
        $this->objects[] = $object;
        unset($this->objects[$key]);
        $this->objects = [];
    }
}
