<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * @internal One immutable root agrees membership, insertion order and a change prefix.
 * SCAFFOLD: isolated version state, not the selector of production Runtime readers.
 */
final readonly class RtVersionDescriptor
{
    /**
     * @param RtRowArena $arena Storage owner
     * @param RtRowValues $root Version page lease
     * @param int $revision Last revision of this prepared/committed prefix
     * @param int $nextOrdinal Next unused insertion ordinal
     * @param int $count Membership size
     */
    public function __construct(
        public RtRowArena $arena,
        public RtRowValues $root,
        public int $revision = 0,
        public int $nextOrdinal = 1,
        public int $count = 0,
    ) {
    }

    /**
     * @param RtRowRole $role New root holder's role
     * @return self Independent constant-work descriptor lease
     * @throws LogicException When this descriptor lease closed
     */
    public function retain(RtRowRole $role): self
    {
        return new self($this->arena, $this->root->retain($role), $this->revision, $this->nextOrdinal, $this->count);
    }
}
