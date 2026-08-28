<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Database\Object\Collection;

use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;

/**
 * Deliberately broken sample of the road this rule closes: a concrete object collection
 * that writes its inherited row array directly, so the membership changes with nothing
 * announced and every view goes on answering out of its cache. Each of the six lines
 * below must be reported by DB-OBJECT-MUTATE.
 *
 * @extends Objects<Object_>
 */
final class ObjectStoreMutate extends Objects
{
    /**
     * @param int|string $key Key written, appended to, dropped, wiped along with the rest and coalesce-assigned
     * @param Object_ $object Row put under that key
     */
    public function rewrite(int|string $key, Object_ $object): void
    {
        $this->objects[$key] = $object;
        $this->objects[] = $object;
        $this->objects = [];
        unset($this->objects[$key]);
        $this->objects[$key] ??= $object;
        $this->objects ??= [];
    }
}
