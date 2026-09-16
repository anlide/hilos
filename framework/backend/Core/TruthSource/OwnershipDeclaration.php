<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Closure;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\Exception\ClaimedRowKeysMissingException;
use Hilos\Core\TruthSource\Exception\ClaimWidthConflictException;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * OwnershipDeclaration - reads what a class says it owns and turns it into registered claims.
 *
 * The declaration lives on the class ({@see TruthSourceOwner::OWNS_DB}, {@see TruthSourceOwner::OWNS_RT})
 * rather than in a call inside onStart(), so it can be answered before an instance exists: the
 * worker deciding whether to build an agent, and the topology validator judging a daemon with
 * nothing running, both ask the class. This is the reader that answers them, and the same one that
 * lays the claim down when an agent actually starts.
 *
 * The class it reads need not be an agent. Everything that implements {@see TruthSourceOwner}
 * answers here the same way - a test-only CLI command and the application class beside it - and
 * this reader has no way of telling them apart, which is what one form of the declaration means.
 *
 * The walk is {@see get_parent_class()} and a constant read per step, deliberately not Reflection:
 * nothing here needs to look past what PHP already resolves, and an inherited value merely repeats
 * a record the union folds back together.
 */
final class OwnershipDeclaration
{
    /**
     * Registers every collection the agent declares, database and runtime, whole and by rows.
     *
     * The order is the mechanism and not a tidiness. An agent writes its first row inside
     * {@see AbstractAgent::onStart()}, so the grant has to stand by then - which is why
     * {@see WorkerManager} lays it before calling the hook. A caller that skips this finds
     * every write of the agent refused with "no truth source registered", however correct
     * the declaration on its class is.
     *
     * Both halves and both widths, exactly as the worker asks for them: the whole-collection
     * claims are read off the CLASS (which lets the declaration be answered where no instance exists),
     * and the by-row claims off the INSTANCE, which is the only thing that knows which rows it holds.
     *
     * @param AbstractAgent $agent Agent whose class declares the collections and whose instance names the rows
     * @throws ClaimWidthConflictException When one collection is named by both widths of a half
     * @throws ClaimedRowKeysMissingException When the seam names no row of a narrowly declared collection
     */
    public static function claimAll(AbstractAgent $agent): void
    {
        self::claimDb($agent::class, $agent->getId());
        self::claimRt($agent::class, $agent->getId());
        self::claimDbRows($agent);
        self::claimRtRows($agent);
    }

    /**
     * The database collections a class owns, its parents' claims folded in.
     *
     * @param class-string<TruthSourceOwner> $agentClass Class to read the declaration off
     * @return array<string, TruthSourceOperations> Collection key => operations its owner may perform
     */
    public static function dbCollectionsOf(string $agentClass): array
    {
        return self::declaredCollectionsOf($agentClass, static fn (string $class): array => $class::OWNS_DB);
    }

    /**
     * The database collections a class claims whole without the right to add, its parents' claims folded in.
     *
     * What a start waits for beside {@see AbstractAgent::READS_DB}, and read off the class for the
     * same reason: the wait comes before the instance exists. Only the keys, because the start
     * needs to know what to wait for and not what the claim may do.
     *
     * @param class-string<TruthSourceOwner> $agentClass Class to read the declaration off
     * @return list<string> Keys of the collections its borrowed claims name
     */
    public static function borrowedDbCollectionsOf(string $agentClass): array
    {
        return array_keys(array_filter(self::dbCollectionsOf($agentClass), self::isBorrowedClaim(...)));
    }

    /**
     * Registers every database collection the class declares, under one owner.
     *
     * The width is the whole collection: a claim whose keys only the live instance knows is
     * declared in the other map of this half and laid down by {@see self::claimDbRows()}, which
     * asks the instance for them.
     *
     * Each claim is its own reader interest, and whether it is a ready one depends on the claim.
     * A claim that may add is ready at once: the owner reads its own rows the moment it starts
     * writing them, and the copy this process caches lives only under an interest. A borrowed
     * claim is not ({@see self::isBorrowedClaim()}): its holder writes rows somebody else brought
     * into being, so its readiness arrives the way a reader's does - {@see WorkerManager} waits
     * for it before the instance is built, exactly as for what the class reads - and marking it
     * here would skip the drop of the cache that arrival makes.
     *
     * A class that declares nothing registers nothing - the empty map never reaches a registry.
     *
     * @param class-string<TruthSourceOwner> $agentClass Class to read the declaration off
     * @param string $ownerId Id the claim is registered under - an agent's own id, or the id of the runner that claims for a class
     */
    public static function claimDb(string $agentClass, string $ownerId): void
    {
        foreach (self::dbCollectionsOf($agentClass) as $collection => $operations) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $ownerId, $operations);

            SourceInterestRegistry::register(SourceChange::KIND_DB, $collection, SourceConsumer::agent($ownerId));
            if (!self::isBorrowedClaim($operations)) {
                SourceInterestRegistry::markReady(SourceChange::KIND_DB, $collection);
            }
        }
    }

    /**
     * The database collections a class owns BY ROWS, its parents' claims folded in.
     *
     * Only the collections: which rows of them this owner holds is not on the class at all, and is
     * asked of the instance by {@see self::claimDbRows()}. That is enough for the reader who has
     * no instance to ask - the validator judging a topology sees that this class holds the
     * collection narrowly, which is what it needs to refuse a second whole-collection owner.
     *
     * Narrower than its whole-collection twin in what it accepts: an agent, not any owner. The
     * other two kinds implementing {@see TruthSourceOwner} - the test-only CLI commands and the
     * application class - have no instance to ask for keys, and claim under one shared id.
     *
     * @param class-string<AbstractAgent> $agentClass Class to read the declaration off
     * @return array<string, TruthSourceOperations> Collection key => operations its owner may perform on the rows it names
     */
    public static function dbRowCollectionsOf(string $agentClass): array
    {
        return self::declaredCollectionsOf($agentClass, static fn (string $class): array => $class::OWNS_DB_ROWS);
    }

    /**
     * Registers every database collection the agent declares narrowly, over the rows it names.
     *
     * Two halves meeting: the map comes off the class, the keys off the live instance
     * ({@see AbstractAgent::ownedDbRowKeys()}), because a row key of an instance cannot be written
     * on a class. Asked once, at start; there is no way to widen a claim afterwards.
     *
     * Refuses on two counts rather than registering something half meant. A collection named by
     * both maps of this half is a contradiction with no reading: the registry keeps one grant per
     * (collection, agent) pair and a repeated registration replaces it, so the order of these two
     * calls would silently decide the width. A seam that names no row is not a claim of nothing
     * either - that width is the right to create ({@see TruthSourceRegistry::registerCreate()}) -
     * and a collection registered with no rows held would go unnoticed until the first foreign
     * write.
     *
     * Each claim is its own reader interest, and a ready one whatever operations it carries: the
     * rows it names are this owner's own, so it reads them the moment it starts writing them. The
     * borrowed claim that the whole-collection half waits for ({@see self::claimDb()}) has no
     * narrow counterpart - a claim over named rows is not a claim over rows somebody else wrote.
     *
     * A class that declares nothing registers nothing - the empty map never reaches a registry.
     *
     * @param AbstractAgent $agent Agent whose class declares the collections and whose instance names the rows
     * @throws ClaimWidthConflictException When one collection is named by both widths of this half
     * @throws ClaimedRowKeysMissingException When the seam names no row of a narrowly declared collection
     */
    public static function claimDbRows(AbstractAgent $agent): void
    {
        $narrow = self::dbRowCollectionsOf($agent::class);
        self::refuseWidthConflict($agent, self::dbCollectionsOf($agent::class), $narrow);

        foreach ($narrow as $collection => $operations) {
            $keys = $agent->ownedDbRowKeys($collection);
            if ($keys === []) {
                throw new ClaimedRowKeysMissingException(
                    $agent::class . " declares database collection '{$collection}' by rows and named none of them",
                );
            }

            TruthSourceRegistry::register($collection, TruthSourceKeys::listed(...$keys), $agent->getId(), $operations);

            SourceInterestRegistry::register(SourceChange::KIND_DB, $collection, SourceConsumer::agent($agent->getId()));
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $collection);
        }
    }

    /**
     * The runtime collections a class owns, its parents' claims folded in.
     *
     * @param class-string<TruthSourceOwner> $agentClass Class to read the declaration off
     * @return array<string, TruthSourceOperations> Collection key => operations its owner may perform
     */
    public static function rtCollectionsOf(string $agentClass): array
    {
        return self::declaredCollectionsOf($agentClass, static fn (string $class): array => $class::OWNS_RT);
    }

    /**
     * The runtime collections a class claims whole without the right to add, its parents' claims folded in.
     *
     * The runtime twin of {@see self::borrowedDbCollectionsOf()}: what a start waits for beside
     * {@see AbstractAgent::READS_RT}.
     *
     * @param class-string<TruthSourceOwner> $agentClass Class to read the declaration off
     * @return list<string> Keys of the collections its borrowed claims name
     */
    public static function borrowedRtCollectionsOf(string $agentClass): array
    {
        return array_keys(array_filter(self::rtCollectionsOf($agentClass), self::isBorrowedClaim(...)));
    }

    /**
     * Registers every runtime collection the class declares, under one owner.
     *
     * The width is the whole collection, for the reason the database half gives: a claim whose
     * keys only the live instance knows belongs in the other map of this half and is laid down by
     * {@see self::claimRtRows()}.
     *
     * Each claim is its own reader interest, and ready at once only when it may add: a writer
     * holds the copy of what it writes, so there is no state on its way here for it to wait for.
     * A borrowed claim holds no such copy ({@see self::isBorrowedClaim()}) - the rows it edits
     * were written by somebody else and reach this process in a snapshot, which is what marks it
     * ready, and {@see WorkerManager} waits for that snapshot before the instance is built.
     *
     * A class that declares nothing registers nothing - the empty map never reaches a registry.
     *
     * @param class-string<TruthSourceOwner> $agentClass Class to read the declaration off
     * @param string $ownerId Id the claim is registered under - an agent's own id, or the id of the runner that claims for a class
     */
    public static function claimRt(string $agentClass, string $ownerId): void
    {
        foreach (self::rtCollectionsOf($agentClass) as $collection => $operations) {
            RtTruthSourceRegistry::register($collection, TruthSourceKeys::all(), $ownerId, $operations);

            SourceInterestRegistry::register(SourceChange::KIND_RT, $collection, SourceConsumer::agent($ownerId));
            if (!self::isBorrowedClaim($operations)) {
                SourceInterestRegistry::markReady(SourceChange::KIND_RT, $collection);
            }
        }
    }

    /**
     * The runtime collections a class owns BY ROWS, its parents' claims folded in.
     *
     * The runtime twin of {@see self::dbRowCollectionsOf()}, and the one the node cares about: the
     * master replicates runtime state by the map of owners, so a narrow record here is what lets
     * one collection converge across the mesh, each node's agents holding only their own rows.
     *
     * @param class-string<AbstractAgent> $agentClass Class to read the declaration off
     * @return array<string, TruthSourceOperations> Collection key => operations its owner may perform on the rows it names
     */
    public static function rtRowCollectionsOf(string $agentClass): array
    {
        return self::declaredCollectionsOf($agentClass, static fn (string $class): array => $class::OWNS_RT_ROWS);
    }

    /**
     * Registers every runtime collection the agent declares narrowly, over the rows it names.
     *
     * The runtime twin of {@see self::claimDbRows()}: the map comes off the class, the keys off
     * the live instance ({@see AbstractAgent::ownedRtRowKeys()}), and the same two refusals stand
     * in front of the registry for the same reasons.
     *
     * Each claim is its own reader interest, and a ready one whatever operations it carries, for
     * the reason the database half gives: the rows it names are this owner's own, and a writer
     * holds the copy of what it writes.
     *
     * A class that declares nothing registers nothing - the empty map never reaches a registry.
     *
     * @param AbstractAgent $agent Agent whose class declares the collections and whose instance names the rows
     * @throws ClaimWidthConflictException When one collection is named by both widths of this half
     * @throws ClaimedRowKeysMissingException When the seam names no row of a narrowly declared collection
     */
    public static function claimRtRows(AbstractAgent $agent): void
    {
        $narrow = self::rtRowCollectionsOf($agent::class);
        self::refuseWidthConflict($agent, self::rtCollectionsOf($agent::class), $narrow);

        foreach ($narrow as $collection => $operations) {
            $keys = $agent->ownedRtRowKeys($collection);
            if ($keys === []) {
                throw new ClaimedRowKeysMissingException(
                    $agent::class . " declares runtime collection '{$collection}' by rows and named none of them",
                );
            }

            RtTruthSourceRegistry::register($collection, TruthSourceKeys::listed(...$keys), $agent->getId(), $operations);

            SourceInterestRegistry::register(SourceChange::KIND_RT, $collection, SourceConsumer::agent($agent->getId()));
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, $collection);
        }
    }

    /**
     * Whether a whole-collection claim is borrowed: its holder may not add a row.
     *
     * A holder that brings no row into being only ever writes rows somebody else wrote, so it
     * holds no copy of them - the copy has to arrive, and the claim waits for it the way a read
     * does. Asked of the FOLDED operations, so a subclass that adds the right its parent's record
     * lacked owns the collection rather than borrowing it.
     *
     * Sufficient and not complete, and the rule says so rather than promise otherwise: a co-owner
     * that may add rows as well holds no copy of the rows the other owner wrote either, and is not
     * caught here. The tree has several - the cluster demo's claimer holds the worker statuses
     * whole while every worker holds its own row of them, and the chat demo holds five
     * collections whole under two or three owners at once (its Hilos::SHARED_DB_OWNERS) - and
     * none of them reads another owner's rows in its start hook, so no second form of borrowing
     * exists for them until one does.
     *
     * @param TruthSourceOperations $operations Folded operations of one whole-collection claim
     * @return bool True when the claim may not add, and so waits for the state it edits
     */
    private static function isBorrowedClaim(TruthSourceOperations $operations): bool
    {
        return !$operations->allows(TruthSourceOperation::Add);
    }

    /**
     * Refuses a half in which one collection is declared both whole and by rows.
     *
     * Shared by the two halves rather than spelled out in each, for the reason the walk below is
     * shared: one refusal is one rule, and two copies of its message would answer the same
     * contradiction in two voices.
     *
     * Read on the FOLDED maps, so a contradiction between a parent and its subclass is caught as
     * readily as one written twice in a single class - the folding is what makes the two records
     * meet at all.
     *
     * @param AbstractAgent $agent Agent both maps were read off, named in the message
     * @param array<string, TruthSourceOperations> $whole Collections this half declares whole
     * @param array<string, TruthSourceOperations> $byRows Collections this half declares by rows
     * @throws ClaimWidthConflictException When the two maps name a collection in common
     */
    private static function refuseWidthConflict(AbstractAgent $agent, array $whole, array $byRows): void
    {
        $conflicting = array_intersect_key($whole, $byRows);
        if ($conflicting === []) {
            return;
        }

        throw new ClaimWidthConflictException(
            $agent::class . " declares '" . implode("', '", array_keys($conflicting))
            . "' both whole and by rows; a collection stands in exactly one of the two maps of its half",
        );
    }

    /**
     * One walk up the chain, reading whichever half the closure was given for.
     *
     * Both halves share it rather than each keeping a copy, because the one thing in these twenty
     * lines that is not obvious is the order below, and two copies of it would drift apart without
     * a word. Which constant is being read is the closure's business; the walk itself has no half.
     *
     * Merging up rather than replacing: a repeated collection gets the union of the operation sets
     * declared for it, so a subclass widens its parent's claim and cannot narrow it. A parent's
     * methods run on the instance of its subclass and were written for a parent's rights, so a
     * right taken away here would be refused somewhere else entirely.
     *
     * The order inside a step is not interchangeable: {@see TruthSourceOperation::BY_KIND} is
     * expanded BEFORE the union, and expanding it after would let an empty list dissolve into a
     * neighbour - a parent saying "b => BY_KIND" beside a subclass saying "b => [Update]" would
     * come out as updating alone, with the kind of the agent silently lost.
     *
     * The kind is asked of the class that is STARTING, once, for every record however far up it
     * was declared. That is what an onStart() call in a parent already did on the instance of its
     * subclass, and what a kind is for: one answer for the whole class. It is one answer for both
     * halves too: no agent has yet wanted to do different things to the database rows it holds and
     * to the runtime rows beside them.
     *
     * @param class-string<TruthSourceOwner> $agentClass Class to read the declaration off
     * @param Closure(class-string<TruthSourceOwner>): array<string, list<TruthSourceOperation>> $declarationOf
     *     Reads the declared map off one step of the chain
     * @return array<string, TruthSourceOperations> Collection key => operations its owner may perform
     */
    private static function declaredCollectionsOf(string $agentClass, Closure $declarationOf): array
    {
        $byKind = null;
        $owned = [];
        $class = $agentClass;
        while ($class !== false) {
            foreach ($declarationOf($class) as $collection => $operations) {
                if ($operations === TruthSourceOperation::BY_KIND) {
                    $byKind ??= $agentClass::defaultTruthSourceOperations();
                    $declared = $byKind;
                } else {
                    $declared = TruthSourceOperations::of(...$operations);
                }
                $owned[$collection] = isset($owned[$collection])
                    ? $owned[$collection]->merge($declared)
                    : $declared;
            }
            $class = get_parent_class($class);
        }

        return $owned;
    }
}
