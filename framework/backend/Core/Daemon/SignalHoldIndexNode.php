<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** Scalar-only AVL node owned by one index; no cursor retains a branch or a payload. */
final class SignalHoldIndexNode
{
    public ?self $left = null;

    public ?self $right = null;

    public int $height = 1;

    /** @param int $identity Stable owner-local order key */
    public function __construct(public int $identity)
    {
    }
}
