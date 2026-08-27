<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

/**
 * A DB sync payload that names the process which broadcast it.
 *
 * Two identities travel with such a payload and must not be confused: `origin` is the
 * accept key of the connection whose write caused the fact, while `emitter` names the
 * process that put the fact on the wire. Only the second one answers "is this my own
 * echo", and it is the field a receiver compares with its own identity.
 *
 * A payload with no stamp reads as someone else's. That is the safe direction for
 * every DB sync fact alike: applying one extra time costs a redundant write to a
 * mirror, while swallowing a foreign change leaves this process holding a row the
 * rest of the cluster no longer has.
 */
interface EmitterStampedSyncSignalDataInterface extends SyncSignalDataInterface
{
    /** @var ?string Identity of the process that sent this fact, or null when unstamped */
    public ?string $emitter { get; }

    /**
     * Returns a copy stamped with the identity of the sending process.
     *
     * Called on the send path, so the stamp describes the process the fact leaves from
     * rather than the one that wrote the row.
     *
     * @param string $emitter Identity of the sending process
     * @return static Copy carrying the emitter stamp
     */
    public function withEmitter(string $emitter): static;
}
