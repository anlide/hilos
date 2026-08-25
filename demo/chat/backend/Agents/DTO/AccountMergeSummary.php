<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\DTO;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\Database\Identity\PasswordFate;

/**
 * AccountMergeSummary - what a completed account merge did (HIL-378).
 *
 * The result of {@see ChatAgent::handleAccountMerge()}: how
 * much of the loser's content moved to the survivor. Surfaced to the initiator
 * (CLI reply / admin-UI signal) so the operator sees what the merge transferred.
 *
 * The password outcome is the one field that is not a count, and it is here because it
 * is the one thing the operator cannot infer (HIL-692): the counts describe what moved,
 * while this says which of two secrets a person is left holding. It is the outcome and
 * not the request - when a merge needed no decision, this is what happened anyway.
 */
final readonly class AccountMergeSummary
{
    /**
     * @param int $identitiesMoved Sign-in identities re-pointed to the survivor
     * @param int $messagesMoved Chat messages re-pointed to the survivor
     * @param PasswordFate $passwordKept Whose password the account ended up with
     */
    public function __construct(
        public int $identitiesMoved,
        public int $messagesMoved,
        public PasswordFate $passwordKept,
    ) {
    }

    /**
     * Converts the summary to an associative array for transport.
     *
     * @return array<string, int|string> Key => count, and the password outcome as its backed value
     */
    public function toArray(): array
    {
        return [
            ChatCommandConstants::FIELD_IDENTITIES_MOVED => $this->identitiesMoved,
            ChatCommandConstants::FIELD_MESSAGES_MOVED => $this->messagesMoved,
            ChatCommandConstants::FIELD_PASSWORD_KEPT => $this->passwordKept->value,
        ];
    }
}
