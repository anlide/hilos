<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Database\Object\Collection;

use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;

/**
 * Negative sample of what the rule is obliged to let through, on the very path where it
 * is strictest. Every line below reads like a mutation of the row array and is not one,
 * so a single reported line here means the rule has grown wider than the document it
 * enforces.
 *
 * @extends Objects<Object_>
 */
final class ObjectStoreMutateLookAlikes extends Objects
{
    /**
     * Reads of the row array, which desynchronize nobody: a narrowed lookup inside a
     * concrete collection is written exactly this way.
     *
     * @param int|string $key Key to look up
     * @return array<int, mixed> Whatever the store answers, read and never written
     */
    public function reads(int|string $key): array
    {
        $held = $this->objects[$key] ?? null;
        $rest = array_filter(
            $this->objects,
            static fn(int|string $each): bool => $each !== $key,
            ARRAY_FILTER_USE_KEY,
        );

        return [isset($this->objects[$key]), $held, $rest, count($this->objects)];
    }

    /**
     * A field of a row that is already in the store: membership does not change, and the
     * write travels the row's own sync.
     *
     * @param int|string $key Key of the row to write a field of
     */
    public function rowField(int|string $key): void
    {
        $this->objects[$key]->userId = 1;
    }

    /**
     * A local carrying the store's name. The rule reads `$this->objects` and nothing
     * else, so a variable that merely shares the name is not the store.
     *
     * @param Object_ $object Row put into the local
     * @return array<int, Object_> The local list, which no view subscribes to
     */
    public function localNamedLikeTheStore(Object_ $object): array
    {
        $objects = [];
        $objects[] = $object;
        unset($objects[0]);

        return $objects;
    }
}
