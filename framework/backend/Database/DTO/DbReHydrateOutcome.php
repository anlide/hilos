<?php

declare(strict_types=1);

namespace Hilos\Database\DTO;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Database\ReHydrateRound;

/**
 * DbReHydrateOutcome - what the initiator agent learns when the re-hydrate barrier ends (HIL-436).
 *
 * The whole verdict of a {@see ReHydrateRound}, reduced to what the agent that announced the swap
 * can act on: whether it may open the system up, and - when it may not - which processes to name
 * to the operator. Immutable, and deliberately not a bare bool: the reason a node stays closed is
 * the only thing standing between the operator and a system that "just does not come back".
 *
 * @see AbstractAgent::onDbReHydrateComplete()
 */
final readonly class DbReHydrateOutcome
{
    /**
     * @param bool $complete Whether every participant answered, and answered positively
     * @param list<string> $problems One line per participant that failed or went quiet
     */
    public function __construct(
        public bool $complete,
        public array $problems = [],
    ) {
    }
}
