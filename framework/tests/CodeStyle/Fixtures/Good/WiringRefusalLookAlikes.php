<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Hilos\Core\Exception\LogicException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Hilos;
use Hilos\WiringRefusal;
use Throwable;

/**
 * Negative sample: the three ways out of WIRING-REFUSAL-SWALLOWED, plus the two shapes that
 * look like a hit and are not.
 *
 * Each method here is a decision the rule has to be able to make. A narrow catch, a rethrow
 * standing above the broad one, and a marked catch are the ways out the document names; a read
 * outside any `try` and a broad catch over something that is not the facade are not hits at all,
 * and a rule that fired on either would make every file in the tree owe a record.
 */
final class WiringRefusalLookAlikes
{
    /**
     * Way out one: catch what is actually expected here and nothing above it.
     *
     * @return array<int, string> Names, or none while the project has not mounted the collection
     */
    public function namesWhileTheCollectionMayBeAbsent(): array
    {
        try {
            return array_map(static fn(object $user): string => (string) $user->name, [...Hilos::$db->users]);
        } catch (CollectionNotFoundException) {
            return [];
        }
    }

    /**
     * Way out two: keep the broad catch, and let the refusal past it first.
     *
     * @return int How many connections this node believes it holds
     * @throws WiringRefusal When this process is not a declared reader of the collection
     */
    public function connectionCount(): int
    {
        try {
            return count(Hilos::$rt?->connections ?? []);
        } catch (WiringRefusal $refusal) {
            throw $refusal;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Way out three: say why the answer is honest here, in the marker the rule reads.
     *
     * @return bool Whether the runtime holds anything at all under that name
     */
    public function runtimeHoldsLabels(): bool
    {
        try {
            return Hilos::$rt->labels !== null;
        // read-refusal-swallowed: the caller is the mount check itself, and a refusal is its no
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Not a hit: a broad catch that always leaves by a throw keeps nothing.
     *
     * The commonest honest one, and the reason the rule reads the body rather than the clause:
     * it exists to undo the transaction, not to answer for the failure.
     *
     * @return int Rows the merge moved
     * @throws LogicException Whatever the merge raised, after the transaction is undone
     */
    public function mergeUnderATransaction(): int
    {
        try {
            return count([...Hilos::$db->users]);
        } catch (Throwable $failure) {
            $this->rollBack();

            throw $failure;
        }
    }

    /**
     * Undoes what the failed merge started; a fixture stub with nothing to undo.
     */
    private function rollBack(): void
    {
    }

    /**
     * Not a hit: the read is not inside the `try` at all, so nothing here can swallow it.
     *
     * @return int Number of users, computed before the guarded work begins
     */
    public function countUsersThenDoSomethingElse(): int
    {
        $users = [...Hilos::$db->users];

        try {
            return count($users);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Not a hit: a broad catch is ordinary over work that reaches no collection of ours.
     *
     * @param string $payload Text handed in from outside
     * @return array<string, mixed> Decoded payload, or an empty one
     */
    public function decode(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : throw new LogicException('payload is not an object');
        } catch (Throwable) {
            return [];
        }
    }
}
