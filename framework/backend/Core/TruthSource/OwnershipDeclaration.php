<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Closure;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\Exception\ClaimedRowKeysMissingException;
use Hilos\Core\TruthSource\Exception\ClaimedSetKeyMissingException;
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
     * Registers every collection the agent declares, database and runtime, in every width.
     *
     * The order is the mechanism and not a tidiness. An agent writes its first row inside
     * {@see AbstractAgent::onStart()}, so the grant has to stand by then - which is why
     * {@see WorkerManager} lays it before calling the hook. A caller that skips this finds
     * every write of the agent refused with "no truth source registered", however correct
     * the declaration on its class is.
     *
     * The database half in three widths and the runtime half in two, exactly as the worker asks for
     * them: the whole-collection claims are read off the CLASS (which lets the declaration be answered
     * where no instance exists), and the by-row and set claims off the INSTANCE, which is the only
     * thing that knows which rows or which set it holds.
     *
     * A refusal leaves as it came, and the claims laid before it stay: taking them back belongs to
     * the caller, which also holds the reader interest raised before any claim - {@see WorkerManager}
     * gives both back in one catch.
     *
     * @param AbstractAgent $agent Agent whose class declares the collections and whose instance names the rows
     * @throws ClaimWidthConflictException When one collection is named by more than one width of a half
     * @throws ClaimedRowKeysMissingException When the seam names no row of a narrowly declared collection
     * @throws ClaimedSetKeyMissingException When the seam names no set key of a collection declared by a set
     */
    public static function claimAll(AbstractAgent $agent): void
    {
        self::claimDb($agent::class, $agent->getId());
        self::claimRt($agent::class, $agent->getId());
        self::claimDbRows($agent);
        self::claimRtRows($agent);
        self::claimDbSet($agent);
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
     * The database collections a class claims whole or by a set without the right to add, its parents' claims folded in.
     *
     * What a start waits for beside {@see AbstractAgent::READS_DB}, and read off the class for the
     * same reason: the wait comes before the instance exists. Only the keys, because the start
     * needs to know what to wait for and not what the claim may do.
     *
     * Narrower than its runtime twin in what it accepts: an agent, not any owner, because the map
     * of sets is declared on {@see AbstractAgent} alone. Both callers hand it an agent already.
     *
     * @param class-string<AbstractAgent> $agentClass Class to read the declaration off
     * @return list<string> Keys of the collections its borrowed claims name
     */
    public static function borrowedDbCollectionsOf(string $agentClass): array
    {
        return array_values(array_unique([
            ...array_keys(array_filter(self::dbCollectionsOf($agentClass), self::isBorrowedClaim(...))),
            ...array_keys(array_filter(self::dbSetCollectionsOf($agentClass), self::isBorrowedClaim(...))),
        ]));
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
     * more than one of the three maps of this half is a contradiction with no reading: the registry
     * keeps one grant per (collection, agent) pair and a repeated registration replaces it, so the
     * order of these calls would silently decide the width. A seam that names no row is not a claim
     * of nothing either - that width is the right to create ({@see TruthSourceRegistry::registerCreate()}) -
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
     * @throws ClaimWidthConflictException When one collection is named by more than one width of this half
     * @throws ClaimedRowKeysMissingException When the seam names no row of a narrowly declared collection
     */
    public static function claimDbRows(AbstractAgent $agent): void
    {
        $narrow = self::dbRowCollectionsOf($agent::class);
        self::refuseWidthConflict($agent, self::dbWidthsOf($agent::class));

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
     * The database collections a class owns BY A SET, its parents' claims folded in.
     *
     * Only the collections: which set of them this owner holds is not on the class at all, and is
     * asked of the instance by {@see self::claimDbSet()}. The column that cuts the set is not here
     * either - the collection's Entity names it, and the owner does not choose it.
     *
     * An agent, not any owner, for the reason {@see self::dbRowCollectionsOf()} gives: the other
     * kinds implementing {@see TruthSourceOwner} have no instance to ask for a set key.
     *
     * @param class-string<AbstractAgent> $agentClass Class to read the declaration off
     * @return array<string, TruthSourceOperations> Collection key => operations its owner may perform on the rows of its set
     */
    public static function dbSetCollectionsOf(string $agentClass): array
    {
        return self::declaredCollectionsOf($agentClass, static fn (string $class): array => $class::OWNS_DB_SET);
    }

    /**
     * Registers every database collection the agent declares by a set, over the set it names.
     *
     * Two halves meeting, as in {@see self::claimDbRows()}: the map comes off the class, the set
     * key off the live instance ({@see AbstractAgent::ownedDbSetKey()}), asked once, at start. Once
     * is enough here where it is not for rows: the grant holds the key of the set and not a list
     * gathered at start, so a row of the set brought into being after this call is covered by it
     * without another word - the write door asks the row which sets it touches.
     *
     * Refuses on two counts rather than registering something half meant. A collection named by
     * more than one of the three maps of this half is refused as {@see self::claimDbRows()}
     * refuses it, and before anything is laid. A seam that names no set key is refused by name:
     * {@see TruthSourceKeys::set()} refuses the empty key itself, and its refusal is turned into
     * one that says which agent and which collection before it leaves - the factory's own words
     * name neither.
     *
     * Each claim is its own reader interest, and whether it is a ready one depends on the claim, as
     * in {@see self::claimDb()}: a set claim that may add is ready at once, and a borrowed one
     * ({@see self::isBorrowedClaim()}) - its holder edits the rows of its set but somebody else
     * brings them into being - is waited for by {@see WorkerManager} beside the reads.
     *
     * A class that declares nothing registers nothing - the empty map never reaches a registry.
     *
     * @param AbstractAgent $agent Agent whose class declares the collections and whose instance names the set
     * @throws ClaimWidthConflictException When one collection is named by more than one width of this half
     * @throws ClaimedSetKeyMissingException When the seam names no set key of a collection declared by a set
     */
    public static function claimDbSet(AbstractAgent $agent): void
    {
        $held = self::dbSetCollectionsOf($agent::class);
        self::refuseWidthConflict($agent, self::dbWidthsOf($agent::class));

        foreach ($held as $collection => $operations) {
            try {
                $keys = TruthSourceKeys::set($agent->ownedDbSetKey($collection));
            } catch (InvalidArgumentException $noSetKey) {
                throw new ClaimedSetKeyMissingException(
                    $agent::class . " declares database collection '{$collection}' by a set and named no set key",
                    previous: $noSetKey,
                );
            }

            TruthSourceRegistry::register($collection, $keys, $agent->getId(), $operations);

            SourceInterestRegistry::register(SourceChange::KIND_DB, $collection, SourceConsumer::agent($agent->getId()));
            if (!self::isBorrowedClaim($operations)) {
                SourceInterestRegistry::markReady(SourceChange::KIND_DB, $collection);
            }
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
        self::refuseWidthConflict($agent, ['OWNS_RT' => self::rtCollectionsOf($agent::class), 'OWNS_RT_ROWS' => $narrow]);

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
     * Whether a claim over the whole collection or over a set is borrowed: its holder may not add a row.
     *
     * A holder that brings no row into being only ever writes rows somebody else wrote, so it
     * holds no copy of them - the copy has to arrive, and the claim waits for it the way a read
     * does. Asked of the FOLDED operations, so a subclass that adds the right its parent's record
     * lacked owns the collection rather than borrowing it. A claim over named rows never comes
     * here: the rows it names are its own ({@see self::claimDbRows()}).
     *
     * Sufficient and not complete, and the rule says so rather than promise otherwise: a co-owner
     * that may add rows as well holds no copy of the rows the other owner wrote either, and is not
     * caught here. The tree has several - the cluster demo's claimer holds the worker statuses
     * whole while every worker holds its own row of them, and the chat demo holds five
     * collections whole under two or three owners at once (its Hilos::SHARED_DB_OWNERS) - and
     * none of them reads another owner's rows in its start hook, so no second form of borrowing
     * exists for them until one does.
     *
     * @param TruthSourceOperations $operations Folded operations of one claim over the whole collection or over a set
     * @return bool True when the claim may not add, and so waits for the state it edits
     */
    private static function isBorrowedClaim(TruthSourceOperations $operations): bool
    {
        return !$operations->allows(TruthSourceOperation::Add);
    }

    /**
     * The database maps of a class, one per width, each folded up its chain.
     *
     * Named by their constants, spelled as the topology validator spells them in its own refusals,
     * so a refusal of the start and a refusal of the topology name a declaration the same way.
     *
     * @param class-string<AbstractAgent> $agentClass Class to read the declarations off
     * @return array<string, array<string, TruthSourceOperations>> Declaration name => collections it holds
     */
    private static function dbWidthsOf(string $agentClass): array
    {
        return [
            'OWNS_DB' => self::dbCollectionsOf($agentClass),
            'OWNS_DB_ROWS' => self::dbRowCollectionsOf($agentClass),
            'OWNS_DB_SET' => self::dbSetCollectionsOf($agentClass),
        ];
    }

    /**
     * Refuses a half in which one collection is declared in more than one width.
     *
     * One check for every map of a half, shared by the two halves rather than spelled out in each,
     * for the reason the walk below is shared: one refusal is one rule, and two copies of its
     * message would answer the same contradiction in two voices. Each half hands in as many maps
     * as it has widths.
     *
     * Read on the FOLDED maps, so a contradiction between a parent and its subclass is caught as
     * readily as one written twice in a single class - the folding is what makes the two records
     * meet at all.
     *
     * @param AbstractAgent $agent Agent the maps were read off, named in the message
     * @param array<string, array<string, TruthSourceOperations>> $widths Declaration name => collections it holds, one per width of the half
     * @throws ClaimWidthConflictException When two maps of the half name a collection in common
     */
    private static function refuseWidthConflict(AbstractAgent $agent, array $widths): void
    {
        $mapsByCollection = [];
        foreach ($widths as $declaration => $collections) {
            foreach (array_keys($collections) as $collection) {
                $mapsByCollection[$collection][] = $declaration;
            }
        }

        $parts = [];
        foreach ($mapsByCollection as $collection => $maps) {
            if (count($maps) > 1) {
                $parts[] = "'{$collection}' in " . implode(' and ', $maps);
            }
        }
        if ($parts === []) {
            return;
        }

        throw new ClaimWidthConflictException(
            $agent::class . ' declares ' . implode(', ', $parts) . '; a collection stands in exactly one of the maps of its half',
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
