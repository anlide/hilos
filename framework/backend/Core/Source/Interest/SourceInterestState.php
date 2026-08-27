<?php

declare(strict_types=1);

namespace Hilos\Core\Source\Interest;

/**
 * How far a reader's interest in one collection has got.
 *
 * A reader earns the right to read not when it says it wants a collection but when the
 * collection's state has arrived, and between the two there is a window this names. There is
 * no third case on purpose: "no interest" is the absence of a record, so a caller that has to
 * distinguish it asks {@see SourceInterestRegistry::isDeclared()} rather than looking for a
 * case here.
 */
enum SourceInterestState
{
    /** Interest is on the books and the initial state is on its way. */
    case Declared;

    /** The initial state has landed in this process, so reads are answered. */
    case Ready;
}
