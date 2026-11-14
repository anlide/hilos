<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

/** @internal One queue cell; it never contains a native collection of child pages. */
final class RtRowRelease
{
    public bool $started = false;
    public int $releasedBytes = 0;
    public int $pendingReferences = 1;

    /**
     * @param RtRowPage $page Reference being retired
     * @param ?RtRowRole $role External holder, or null for an internal edge
     * @param ?self $group Original root receipt; all child releases share its fixed counter
     */
    public function __construct(public readonly RtRowPage $page, public readonly ?RtRowRole $role, public readonly ?self $group = null)
    {
    }

    /** @return bool Whether this root's reference and all retirement work it owns have finished */
    public function isFinished(): bool
    {
        return ($this->group ?? $this)->pendingReferences === 0;
    }
}
