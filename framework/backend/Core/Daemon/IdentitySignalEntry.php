<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** Scalar identity obligation; the manager's original parked or ready array owns the payload. */
final class IdentitySignalEntry
{
    public ?int $releaseId = null;

    /**
     * @param int $id Original monotonic parked identity
     * @param string $acceptKey Original browser connection
     * @param string $page Original requested page
     */
    public function __construct(public readonly int $id, public readonly string $acceptKey, public readonly string $page)
    {
    }
}
