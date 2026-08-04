<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/*
 * A plain block comment is not a docblock, so @throws \OutOfBoundsException here
 * is text, not a type reference.
 */

/**
 * Negative sample: both rules read tokens, so naming getStateItem() in prose or
 * quoting it as data is not a reach, and a fully qualified name outside a
 * docblock is not a PHPDoc reference.
 */
final class MentionsInStringsAndComments
{
    /**
     * @return array<int, string> Rule names quoted as data
     */
    public function describe(): array
    {
        // Callers outside Database/ and Runtime/ must not call getStateItem().
        return [
            'getStateCollection',
            'getStateItem',
            'stateCollection',
            '\Hilos\Tests\CodeStyle\Violation',
            '$this->stateCollection is written as text here',
        ];
    }
}
