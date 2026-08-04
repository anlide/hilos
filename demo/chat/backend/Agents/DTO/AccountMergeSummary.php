<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\DTO;

use Demo\Chat\Agents\ChatAgent;

/**
 * AccountMergeSummary - counts returned by a completed account merge (HIL-378).
 *
 * The result of {@see ChatAgent::handleAccountMerge()}: how
 * much of the loser's content moved to the survivor. Surfaced to the initiator
 * (CLI reply / admin-UI signal) so the operator sees what the merge transferred.
 */
final readonly class AccountMergeSummary
{
    /**
     * @param int $identitiesMoved Sign-in identities re-pointed to the survivor
     * @param int $messagesMoved Chat messages re-pointed to the survivor
     */
    public function __construct(
        public int $identitiesMoved,
        public int $messagesMoved,
    ) {
    }

    /**
     * Converts the summary to an associative array for transport.
     *
     * @return array<string, int> Key => count array
     */
    public function toArray(): array
    {
        return [
            'identitiesMoved' => $this->identitiesMoved,
            'messagesMoved' => $this->messagesMoved,
        ];
    }
}
