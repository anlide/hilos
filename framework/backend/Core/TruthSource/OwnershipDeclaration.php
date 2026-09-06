<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Closure;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * OwnershipDeclaration - reads what a class says it owns and turns it into registered claims.
 *
 * The declaration lives on the class ({@see AbstractAgent::OWNS_DB}, {@see AbstractAgent::OWNS_RT})
 * rather than in a call inside onStart(), so it can be answered before an instance exists: the
 * worker deciding whether to build an agent, and the topology validator judging a daemon with
 * nothing running, both ask the class. This is the reader that answers them, and the same one that
 * lays the claim down when an agent actually starts.
 *
 * The walk is {@see get_parent_class()} and a constant read per step, deliberately not Reflection:
 * nothing here needs to look past what PHP already resolves, and an inherited value merely repeats
 * a record the union folds back together.
 */
final class OwnershipDeclaration
{
    /**
     * The database collections a class owns, its parents' claims folded in.
     *
     * @param class-string<AbstractAgent> $agentClass Agent class to read the declaration off
     * @return array<string, TruthSourceOperations> Collection key => operations its owner may perform
     */
    public static function dbCollectionsOf(string $agentClass): array
    {
        return self::declaredCollectionsOf($agentClass, static fn (string $class): array => $class::OWNS_DB);
    }

    /**
     * Registers every database collection the class declares, under one owner.
     *
     * The width is the whole collection and there is no way to say otherwise here: a claim whose
     * keys only the live instance knows is a seam of its own (HIL-895), and a form this list would
     * have to redo is not worth writing twice.
     *
     * Each claim is its own reader interest, and a ready one, exactly as the seam it replaces made
     * it ({@see AbstractAgent::registerDbTruthSource()}): the copy this process caches lives only
     * under an interest, and the owner reads its own rows the moment it starts writing them.
     *
     * A class that declares nothing registers nothing - the empty map never reaches a registry.
     *
     * @param class-string<AbstractAgent> $agentClass Agent class to read the declaration off
     * @param string $ownerId Id the claim is registered under, from AbstractAgent::getId()
     */
    public static function claimDb(string $agentClass, string $ownerId): void
    {
        foreach (self::dbCollectionsOf($agentClass) as $collection => $operations) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $ownerId, $operations);

            SourceInterestRegistry::register(SourceChange::KIND_DB, $collection, SourceConsumer::agent($ownerId));
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $collection);
        }
    }

    /**
     * The runtime collections a class owns, its parents' claims folded in.
     *
     * @param class-string<AbstractAgent> $agentClass Agent class to read the declaration off
     * @return array<string, TruthSourceOperations> Collection key => operations its owner may perform
     */
    public static function rtCollectionsOf(string $agentClass): array
    {
        return self::declaredCollectionsOf($agentClass, static fn (string $class): array => $class::OWNS_RT);
    }

    /**
     * Registers every runtime collection the class declares, under one owner.
     *
     * The width is the whole collection and there is no way to say otherwise here, for the reason
     * the database half gives: a claim whose keys only the live instance knows is a seam of its
     * own (HIL-895).
     *
     * Each claim is its own reader interest, and a ready one, exactly as the seam it replaces made
     * it ({@see AbstractAgent::registerRtTruthSource()}): a writer holds the copy of what it
     * writes, so there is no state on its way here for it to wait for.
     *
     * A class that declares nothing registers nothing - the empty map never reaches a registry.
     *
     * @param class-string<AbstractAgent> $agentClass Agent class to read the declaration off
     * @param string $ownerId Id the claim is registered under, from AbstractAgent::getId()
     */
    public static function claimRt(string $agentClass, string $ownerId): void
    {
        foreach (self::rtCollectionsOf($agentClass) as $collection => $operations) {
            RtTruthSourceRegistry::register($collection, TruthSourceKeys::all(), $ownerId, $operations);

            SourceInterestRegistry::register(SourceChange::KIND_RT, $collection, SourceConsumer::agent($ownerId));
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, $collection);
        }
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
     * @param class-string<AbstractAgent> $agentClass Agent class to read the declaration off
     * @param Closure(class-string<AbstractAgent>): array<string, list<TruthSourceOperation>> $declarationOf
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
