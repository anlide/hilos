<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\Core\Router\SignalDataInterface;

/**
 * Common shape of every DB and RT sync signal payload.
 *
 * A parameter typed with this interface accepts any sync payload, including the
 * collection-scoped clear that carries no row identity. The kind of operation is
 * not declared here: it stays encoded in the concrete DTO class, so a caller that
 * needs a specific applicator narrows by `instanceof`.
 */
interface SyncSignalDataInterface extends SignalDataInterface
{
    /** @var string Collection key the synced fact belongs to */
    public string $collectionKey { get; }

    /** @var ?string Accept key of the writing connection, or null when unattended */
    public ?string $origin { get; }
}
