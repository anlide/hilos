<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Daemon\DaemonApplication;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Schema\SetOwnershipGuard;
use Hilos\Hilos;

/**
 * AccountErasureCoverageGuard - the question "who erases a person's rows of this table" asked at
 * the startup of a node.
 *
 * Every mounted collection owes an answer: a participant of the account erasure covers it, or the
 * erasure leaves it alone for a stated reason. The framework answers for its own collections in
 * {@see AccountErasureParticipantCatalog}; a project answers for its own in its
 * {@see AccountDataOwnerDirectory}, and every collection that directory names has to be one this
 * installation mounts, or the answer is about a table that is not there. All of it is constants
 * and the map of mounted collections, so the whole question is answered without a single query.
 *
 * It refuses the STARTUP rather than the erasure that would have missed the table, because the
 * gap is born on the day a table is added, and a refused erasure would surface it on the day a
 * person asked to be forgotten - after their rows had already outlived the request. An
 * installation without accounts erases nobody, so without {@see HilosFeature::AUTH} there is
 * nothing to ask.
 *
 * All findings are collected before the throw rather than reported one per start, for the
 * reason {@see SetOwnershipGuard} gives: the declarations are written by hand, and the reader of
 * a refusal is the author of a table or of the directory, not the operator who started the node.
 *
 * Runs from {@see DaemonApplication::run()} right after the set ownership gate: both read
 * constants alone, and a table without an answer to "who erases it" is the same kind of defect as
 * a table without a set. Only the daemon carries it, as it carries its neighbours.
 */
final class AccountErasureCoverageGuard
{
    /**
     * Refuses the startup of a node whose mounted collections the account erasure does not answer for.
     *
     * Silent for an installation without accounts, and for one that mounted no database.
     *
     * @throws InvalidTopologyException When a mounted collection has no erasure participant and no exemption, when the
     *     project's directory names a collection this installation does not mount, or when the directory is malformed
     *     or overlaps the framework's participants, the exemptions or itself
     */
    public static function assertMountedCollectionsCovered(): void
    {
        if (!Hilos::hasFeature(HilosFeature::AUTH) || Hilos::$db === null) {
            return;
        }

        $catalog = AccountErasureParticipantCatalog::installation();
        $mounted = array_map(
            static fn (string $collectionClass): string => $collectionClass::COLLECTION_KEY,
            Hilos::$db->getObjectCollectionClasses(),
        );

        $answered = array_keys($catalog->exempt());
        foreach ($catalog->participants() as $participant) {
            array_push($answered, ...$participant->collections());
        }

        $errors = [];
        foreach (array_diff($mounted, $answered) as $collection) {
            $errors[] = "Collection [{$collection}] has no account erasure participant and no exemption";
        }
        foreach (array_diff(self::projectCollections(), $mounted) as $collection) {
            $errors[] = "Account data owner directory names collection [{$collection}] that this installation does not mount";
        }

        if ($errors !== []) {
            throw InvalidTopologyException::forErrors(Hilos::appClass(), $errors);
        }
    }

    /**
     * Names the collections the project's directory speaks for: its owners' and its exemptions.
     *
     * @return list<string> Collection keys in directory order, the owners' first
     * @throws InvalidTopologyException When the directory is malformed
     */
    private static function projectCollections(): array
    {
        $directory = Hilos::accountDataOwnerDirectoryClass();
        $collections = [];
        foreach ($directory::declaredOwners() as $owner) {
            array_push($collections, ...$owner->collections);
        }

        return [...$collections, ...array_keys($directory::declaredExemptions())];
    }
}
