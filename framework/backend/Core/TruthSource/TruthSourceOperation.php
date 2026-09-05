<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * TruthSourceOperation - what a truth source may do to the rows it owns.
 *
 * The second axis of a truth-source right, next to the width of the claim: the width says
 * which rows are yours, this says what may be done with them. An agent library, for one,
 * brings a row into being and takes it away again, but never edits what is already written
 * in it.
 *
 * Deliberately not {@see TableMutationType}: that one tells a UI table which row changed,
 * which is another layer entirely, and a matching set of cases does not make two vocabularies
 * one.
 */
enum TruthSourceOperation: string
{
    case Add = 'add';
    case Update = 'update';
    case Remove = 'remove';

    /** Every operation - the right a source gets when its registration names none. */
    public const array ALL = [self::Add, self::Update, self::Remove];

    /**
     * No operation named - the kind of the agent answers for this claim.
     *
     * An empty list under a name, because a bare one would say both "nothing may be done here"
     * and "the set was never written", which is the very pair {@see TruthSourceKeys} exists to
     * take apart. Only a declaration carries it: it is resolved into a real set against the
     * class that is starting, before the claim reaches a registry.
     */
    public const array BY_KIND = [];
}
