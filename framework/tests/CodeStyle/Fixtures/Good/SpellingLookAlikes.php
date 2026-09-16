<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Negative sample: nothing here is one of the six British forms of spelling.md,
 * though every line looks like one. `neighbour` has no pair in the table, which is
 * the silence the third entry of the document's Exceptions section explains;
 * `cancellation` is spelled the same in both dialects and is not a form of the word
 * the table names; `license` and `licensing` are the American side; a seventh pair
 * for `Cancelling` is not in the table; and `discolouration` opens no boundary inside
 * a word. SPELLING must stay silent on all of it.
 *
 * This file sits outside every scanned root, so only the fixture test reads it.
 */
final class SpellingLookAlikes
{
    /** The neighbour a declaration is resolved against: no pair in the table. */
    public const string NEIGHBOUR_ROLE = 'neighbour';

    /** Correct in both dialects, and not a form of the word the table names. */
    public const string CANCELLATION_REASON = 'cancellation requested';

    /** The American side of the first pair, in two of its forms. */
    public const string LICENSE_NOTE = 'licensing is covered by the MIT License';

    /**
     * The seventh pair is not in the table, so the hump is silent here.
     *
     * @param bool $cancelling Whether the ceremony is being torn down
     * @return bool The same flag, handed back
     */
    public function isCancelling(bool $cancelling): bool
    {
        return $cancelling;
    }

    /**
     * @return string A word that contains a British form without opening a boundary at it
     */
    public function discolouration(): string
    {
        return 'discolouration';
    }
}
