<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Constants\CliCommands;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Identity\PasswordFate;

/**
 * AccountMergeSummary - what a completed account merge did (HIL-378).
 *
 * What {@see CliCommands::ACCOUNT_MERGE} answers with: how much of the loser's content moved
 * to the survivor. Surfaced to the initiator (CLI reply / admin-UI signal) so the operator
 * sees what the merge transferred.
 *
 * Framework-owned since HIL-729, beside the vocabulary the same reply is keyed by: it is the
 * shape of a FRAMEWORK command's answer, and a demo holding it meant every other project
 * would have had to write the same class to answer the same command.
 *
 * The rows are a map and not a count since HIL-729: the identity re-point is the
 * framework's own and is counted on its own, while everything else is whatever the project
 * keeps for a person - here the chat messages - reported under the name that project gives
 * it. That is what lets the framework's `account:merge` print a merge it knows nothing about.
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
     * @param array<string, int> $rowsMoved Rows re-pointed to the survivor, per family the project names
     * @param PasswordFate $passwordKept Whose password the account ended up with
     */
    public function __construct(
        public int $identitiesMoved,
        public array $rowsMoved,
        public PasswordFate $passwordKept,
    ) {
    }

    /**
     * Rebuilds a summary from the array {@see self::toArray()} put on the wire.
     *
     * Needed since HIL-729, where the merge stopped answering in the process that asked for
     * it: the browser's way in runs in the sessions library and the summary crosses one frame
     * to the project that acks. A malformed field is refused rather than defaulted, for the
     * reason a payload reader always is - a merge reported as having moved nothing would be
     * indistinguishable from one that did.
     *
     * @param array<string, mixed> $data Source data, as {@see self::toArray()} wrote it
     * @return self Summary the array describes
     * @throws InvalidFormatException When a count, the row map or the password outcome is missing or unreadable
     */
    public static function fromArray(array $data): self
    {
        $identitiesMoved = $data[AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED] ?? null;
        if (!is_int($identitiesMoved)) {
            throw new InvalidFormatException('Merge summary carries no identity count');
        }

        $rowsMoved = $data[AccountMergeCommandConstants::FIELD_ROWS_MOVED] ?? null;
        if (!is_array($rowsMoved)) {
            throw new InvalidFormatException('Merge summary carries no row map');
        }

        $passwordKept = $data[AccountMergeCommandConstants::FIELD_PASSWORD_KEPT] ?? null;
        $fate = is_string($passwordKept) ? PasswordFate::tryFrom($passwordKept) : null;
        if ($fate === null) {
            throw new InvalidFormatException('Merge summary carries no password outcome');
        }

        return new self($identitiesMoved, self::readRowMap($rowsMoved), $fate);
    }

    /**
     * Reads the per-family row map back, refusing anything that is not a name and a count.
     *
     * @param array<mixed> $rowsMoved Row map as it arrived
     * @return array<string, int> The same map, proven to be one
     * @throws InvalidFormatException When an entry is not a family name against a count
     */
    private static function readRowMap(array $rowsMoved): array
    {
        $map = [];
        foreach ($rowsMoved as $family => $moved) {
            if (!is_string($family) || !is_int($moved)) {
                throw new InvalidFormatException('Merge summary row map is not names against counts');
            }

            $map[$family] = $moved;
        }

        return $map;
    }

    /**
     * Converts the summary to an associative array for transport.
     *
     * @return array<string, int|string|array<string, int>> Counts, the row map, and the password outcome as its backed value
     */
    public function toArray(): array
    {
        return [
            AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED => $this->identitiesMoved,
            AccountMergeCommandConstants::FIELD_ROWS_MOVED => $this->rowsMoved,
            AccountMergeCommandConstants::FIELD_PASSWORD_KEPT => $this->passwordKept->value,
        ];
    }
}
