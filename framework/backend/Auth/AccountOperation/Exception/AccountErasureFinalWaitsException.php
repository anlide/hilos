<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Exception;

use Hilos\Auth\AccountOperation\AccountErasurePersonalFinal;
use Hilos\Core\Exception\ValidationException;

/**
 * The person's erasure final waits: a part of the account has no result yet, or another account is
 * still folded into it (HIL-1942).
 *
 * Thrown by {@see AccountErasurePersonalFinal::run()} before it writes anything. A part waits while
 * an expected owner has not recorded its result or while a wait for an owner stands; a folded
 * account waits until its own final removed its merge row. No person reads the words; the erasure
 * coordinator tells the wait apart by this type, by {@see $participants} and by {@see $foldedUserIds}.
 * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
 */
final class AccountErasureFinalWaitsException extends ValidationException
{
    /**
     * @param list<string> $participants Participants whose parts of the account wait, each once
     * @param list<int> $foldedUserIds Accounts still folded into this one, ascending
     * @param string $message Words of the wait
     */
    private function __construct(public readonly array $participants, public readonly array $foldedUserIds, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $participants Participants whose parts of the account wait, each once, in assignment order
     * @param list<int> $foldedUserIds Accounts still folded into this one; at least one of the two lists is not empty
     * @return self The wait, naming the parts first and the folded accounts after them in ascending order
     */
    public static function of(array $participants, array $foldedUserIds): self
    {
        sort($foldedUserIds);
        $words = [];
        if ($participants !== []) {
            $words[] = 'The person\'s erasure final waits for the parts of ' . implode(', ', $participants) . '.';
        }
        if ($foldedUserIds !== []) {
            $words[] = 'The person\'s erasure final waits for the accounts folded into it: #' . implode(', #', $foldedUserIds) . '.';
        }

        return new self($participants, $foldedUserIds, implode(' ', $words));
    }
}
