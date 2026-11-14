<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Hilos\Core\Exception\InvalidFormatException;
use LogicException;

/**
 * Process-local handle binding, separate from replicated application state.
 *
 * A handle standing in a collection may be attached to the collection's resolution root
 * ({@see RtRowRoot}): a published snapshot generation then gives it new values and a baseline
 * without anybody visiting it, and it reads them from the root until it is settled. A write to
 * the handle settles it first, so a later local write always wins over the generation.
 */
final class RtRowBinding
{
    private static int $nextIncarnation = 0;

    private ?RtRowDescriptor $baseline = null;

    /** Resolution root of the collection this handle stands in, null for a handle no generation reached */
    private ?RtRowRoot $root = null;

    /**
     * @param RtRowDescriptor $descriptor Current immutable values
     * @param int $incarnation Handle lifetime token, independent of textual ids
     */
    private function __construct(private RtRowDescriptor $descriptor, public readonly int $incarnation)
    {
    }

    /**
     * A candidate holder of this handle's values for a draft: the same lifetime token, its own
     * leases and no root. This handle is settled first, so the draft starts from what it reads.
     *
     * @return self Unpublished copy of this handle
     * @throws InvalidFormatException When retained upload identities are malformed
     * @throws LogicException When a backing lease closed
     */
    public function fork(): self
    {
        $this->settle();
        $copy = clone $this;
        $copy->descriptor = $this->descriptor->retain(RtRowRole::Candidate);
        $copy->root = null;

        return $copy;
    }

    /**
     * @param RtRowDescriptor $descriptor Initial immutable values
     * @return self A new handle lifetime
     */
    public static function create(RtRowDescriptor $descriptor): self
    {
        return new self($descriptor, ++self::$nextIncarnation);
    }

    /** @return RtRowDescriptor Current local version; frozen readers keep the descriptor itself */
    public function current(): RtRowDescriptor
    {
        return $this->root?->staged($this)[0] ?? $this->descriptor;
    }

    /** @return ?RtRowDescriptor Current local baseline; null before the first baseline mark */
    public function baseline(): ?RtRowDescriptor
    {
        $staged = $this->root?->staged($this);

        return $staged === null ? $this->baseline : $staged[1];
    }

    /**
     * @param ?RtRowDescriptor $baseline Prepared immutable baseline, null when rolling back an unmarked row
     */
    public function markBaseline(?RtRowDescriptor $baseline): void
    {
        $this->settle();
        $this->baseline = $baseline;
    }

    /**
     * @param RtRowDescriptor $descriptor Fully prepared local descriptor
     */
    public function replace(RtRowDescriptor $descriptor): void
    {
        $this->settle();
        $this->descriptor = $descriptor;
    }

    /**
     * @internal Lets a snapshot generation of the collection this handle stands in reach it.
     * SCAFFOLD: reached only by a staged snapshot import, which has no production caller until the
     * initial-sync frames move onto it (HIL-1817).
     *
     * @param RtRowRoot $root Resolution root of that collection
     */
    public function attach(RtRowRoot $root): void
    {
        if ($this->root !== $root) {
            $this->settle();
            $this->root = $root;
        }
    }

    /**
     * @internal Takes what a published generation gave this handle into the handle itself, in
     * constant work: the generation's own leases change hands and roles, nothing is copied.
     */
    public function settle(): void
    {
        $staged = $this->root?->take($this);
        if ($staged !== null) {
            [$this->descriptor, $this->baseline] = $staged;
            $this->descriptor->reclassify(RtRowRole::Resident);
            $this->baseline->reclassify(RtRowRole::Ordinary);
        }
    }
}
