<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Negative sample: an enum pointing at its own cases, which is how a real one
 * documents itself. {@see SEEDED} is the ordinary spelling and {@see DEFAULT} the
 * one named with a reserved word, which the lexer hands over as its own token
 * rather than as a plain name.
 */
enum PhpDocCleanKind: string
{
    /** A case the fixtures seed on purpose. */
    case SEEDED = 'seeded';

    /** The last-resort case, named with a word the lexer knows. */
    case DEFAULT = 'default';
}
