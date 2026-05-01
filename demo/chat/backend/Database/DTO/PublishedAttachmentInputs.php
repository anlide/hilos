<?php

declare(strict_types=1);

namespace Demo\Chat\Database\DTO;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Typed list of published attachment metadata for one chat event.
 *
 * @implements IteratorAggregate<int, PublishedAttachmentInput>
 */
final readonly class PublishedAttachmentInputs implements Countable, IteratorAggregate
{
    /** @var list<PublishedAttachmentInput> */
    private array $items;

    public function __construct(PublishedAttachmentInput ...$items)
    {
        $this->items = $items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int, PublishedAttachmentInput>
     */
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }
}
