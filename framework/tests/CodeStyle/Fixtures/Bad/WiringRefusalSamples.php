<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Exception;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\WiringRefusal;
use Throwable;

/**
 * Deliberately broken sample: every catch below stands over a read of the facade and takes
 * everything, so WIRING-REFUSAL-SWALLOWED must report each one.
 */
final class WiringRefusalSamples
{
    /**
     * The shape the incident had: a permission check that answers "no" to a wiring defect.
     *
     * @param int $userId Id of the user whose flag is read
     * @return bool Whether the user is an administrator
     */
    public function isAdmin(int $userId): bool
    {
        try {
            return (Hilos::$db->users[$userId] ?? null)?->admin === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The runtime half, and a broader base than the one above.
     *
     * @return int How many connections this node believes it holds
     */
    public function connectionCount(): int
    {
        try {
            return count(Hilos::$rt?->connections ?? []);
        } catch (Exception) {
            return 0;
        }
    }

    /**
     * The framework's own base is broad too: it is the parent both refusal species answer to.
     *
     * @return array<int, string> Names this node can see
     */
    public function names(): array
    {
        try {
            $names = [];
            foreach (Hilos::$db->users as $user) {
                $names[] = "{$user->name}";
            }

            return $names;
        } catch (HilosException) {
            return [];
        }
    }

    /**
     * A marker with nothing after the colon buys nothing, exactly as it does for `@`.
     *
     * @return ?string Label of the row, when it can be had
     */
    public function label(): ?string
    {
        try {
            return (string) Hilos::$rt->labels['first'];
        // read-refusal-swallowed:
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A rethrow written AFTER the broad clause never runs, so it is not a way out.
     *
     * @return ?string Label of the row, when it can be had
     * @throws WiringRefusal Never reached, which is the point of the sample
     */
    public function labelWithLateRethrow(): ?string
    {
        try {
            return (string) Hilos::$rt->labels['second'];
        } catch (Throwable) {
            return null;
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        }
    }
}
