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
     * @param list<self> $operations Operations to name
     * @return string Operation values separated by a comma, as a refusal message spells them
     */
    public static function listAsText(array $operations): string
    {
        return implode(', ', array_map(static fn (self $operation): string => $operation->value, $operations));
    }
}
