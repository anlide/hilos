<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use OutOfBoundsException;

/**
 * HilosCodeSendAttempts - the code sends being watched, one row per browser session (HIL-826).
 *
 * Framework-owned state collection mounted by {@see AuthFeature::mount()} and by nothing else:
 * a project with no sign-in surface has no codes to send, and a collection mounted for it
 * would be read on every tick and never written.
 *
 * It holds only the sends somebody is still being shown: a row appears when a code is ordered
 * and goes when the wait it belongs to is dropped, so the collection is the size of the code
 * screens open right now rather than of the sessions that exist.
 *
 * @extends RtStates<HilosCodeSendAttempt>
 */
final class HilosCodeSendAttempts extends RtStates
{
    public const string STATE_CLASS = HilosCodeSendAttempt::class;

    /**
     * @param ?string $sessionTokenHash Hash of a session cookie token, or null for a missing optional key
     * @return ?HilosCodeSendAttempt Attempt row, or null when the session is waiting for nothing
     */
    public function get(?string $sessionTokenHash): ?HilosCodeSendAttempt
    {
        /** @var ?HilosCodeSendAttempt $state */
        $state = parent::get($sessionTokenHash);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid - and here it
     * almost always is, because a session with no code on its way has no row at all.
     *
     * @param mixed $offset Hash of a session cookie token
     * @return HilosCodeSendAttempt Attempt row
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): HilosCodeSendAttempt
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Code send attempt not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Code send attempt not found: {$offset}");
    }
}
