<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Core\Agent\AbstractAgent;

/**
 * TruthSourceOwner - the one form in which a class states what it owns.
 *
 * Implemented by everything that may hold a collection: {@see AbstractAgent}, the test-only CLI
 * commands, and the application class that names a collection only the project knows. An interface
 * and not a constant repeated on each of them, because three independent declarations of one name
 * would be three forms of one fact, drifting apart the moment one of them is explained differently.
 *
 * It is also the question that can be asked of a class with nothing running: {@see
 * OwnershipDeclaration} reads it off the class-string, and so does the validator that judges a
 * topology before the first process is built.
 *
 * The interface carries no width. A claim whose keys only the live instance knows is a seam of its
 * own (HIL-895); everything declared here is the whole collection.
 */
interface TruthSourceOwner
{
    /**
     * @var array<string, list<TruthSourceOperation>> DB collections this class OWNS, each mapped to
     *     the operations it may perform on their rows. Read off the class before the instance
     *     exists ({@see OwnershipDeclaration::claimDb()}), which is the whole point: a claim made
     *     inside onStart() is invisible to the worker deciding whether to build the agent, and
     *     invisible to the validator that judges the topology with no agent running at all.
     *
     *     A map and not a list of names, because a claim may narrow its operations and a list
     *     would need a second constant to say so. {@see TruthSourceOperation::BY_KIND} leaves the
     *     answer to the kind of the agent - it is resolved through
     *     {@see self::defaultTruthSourceOperations()} of the class that is STARTING, which is what
     *     a call from a parent's onStart() already did on the instance of its subclass.
     *
     *     A subclass declaring this MERGES with what its parents declared, where
     *     {@see AbstractAgent::READS_DB} replaces: a repeated collection gets the union of both
     *     operation sets, so a subclass widens its parent's claim and cannot narrow it. The two
     *     rules differ because the two misses cost differently - a lost read is refused in the
     *     action that reached for it, while a lost claim refuses nothing until some sweep an hour
     *     later finds the collection has no owner.
     *
     *     A collection named here does not belong in {@see AbstractAgent::READS_DB}: the claim is
     *     the reader interest already.
     */
    public const array OWNS_DB = [];

    /**
     * @var array<string, list<TruthSourceOperation>> Runtime collections this class OWNS, each
     *     mapped to the operations it may perform on their rows. Read off the class before the
     *     instance exists ({@see OwnershipDeclaration::claimRt()}), which is what a call inside
     *     onStart() can never be: the worker deciding whether to build the agent, and the
     *     validator judging a topology with nothing running, both have only the class to ask.
     *
     *     A subclass declaring this MERGES with what its parents declared, where
     *     {@see AbstractAgent::READS_RT} replaces: a repeated collection gets the union of both
     *     operation sets, so a subclass widens its parent's claim and cannot narrow it. A parent's
     *     methods run on the instance of its subclass and were written for a parent's rights, so a
     *     right taken away here would be refused somewhere else entirely.
     *
     *     A record here is read by the whole node and not by this worker alone: the master
     *     replicates runtime state by the map of owners, so a collection named here becomes the
     *     single place in the cluster its rows may be written from, and every other node reaches
     *     it by frame. That makes an entry cost more than one in {@see self::OWNS_DB}, where the
     *     rows sit in a database each process can read for itself.
     *
     *     {@see TruthSourceOperation::BY_KIND} leaves the answer to the kind of the agent - it is
     *     resolved through {@see self::defaultTruthSourceOperations()} of the class that is
     *     STARTING, the same way the database half resolves it.
     *
     *     A collection named here does not belong in {@see AbstractAgent::READS_RT}: the claim is
     *     the reader interest already.
     */
    public const array OWNS_RT = [];

    /**
     * Operations this class's claims carry unless a claim was made with its own set.
     *
     * The one place a kind of owner says what it may do to the rows it holds, so that changing
     * the answer for a whole kind costs this one line and no walk of the call sites.
     *
     * Static because the maps above are answered off the class: the resolver expands
     * {@see TruthSourceOperation::BY_KIND} before any instance exists, and the topology validator
     * has no instance to take. Late static binding keeps a subclass's answer winning.
     *
     * @return TruthSourceOperations Operations every claim of this class gets
     */
    public static function defaultTruthSourceOperations(): TruthSourceOperations;
}
