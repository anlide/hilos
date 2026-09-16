<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: every British form below is one of the six words of
 * spelling.md, standing where a PHP file writes English — a doc block, a line
 * comment, a string literal, a constant name behind an underscore, a variable name
 * behind a camelCase hump, and two word forms whose tail the matcher takes in.
 * SPELLING must report each one at its own line, and a line carrying the word
 * twice yields two records.
 *
 * This file sits outside every scanned root, so only the fixture test reads it.
 */
final class SpellingSamples
{
    /** The colour of the badge — a doc-block hit. */
    public const string BADGE_COLOUR = 'red';

    /** Two hits on one line: the constant name and the literal it holds. */
    public const string PASSKEY_CANCELLED_MESSAGE = 'The passkey request was cancelled.';

    /**
     * @param string $behaviour A variable named in the British form
     * @return string The licence text, organised for the screen
     */
    public function serialiseBehaviour(string $behaviour): string
    {
        // A behavioural note in a line comment, with the tail taken in.
        $localColour = $behaviour . ' serialises to ' . self::BADGE_COLOUR;

        return $localColour;
    }
}
