<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * One account the Hilos user page may offer as a merge loser (HIL-411).
 *
 * The user fields stay together so {@see HilosMergeCandidatesTable} can put them in the
 * entity-bearing `users` slot. The identity projection is kept beside them for the inline
 * `merge` slot. Search-only fields never enter either slot.
 */
final class HilosMergeCandidateTableRow extends AbstractTableRow
{
    public const string identityAddresses = 'identityAddresses';
    public const string exactUserId = 'exactUserId';

    /**
     * @param array<string, mixed> $userFields User payload, including its numeric id
     * @param list<array{type: string, identifier: string, provider: ?string, verified: bool}> $identities Safe identity metadata
     * @param bool $hasPassword Whether the account has a password identity
     * @param bool $hasSecondFactor Whether the account has a confirmed authenticator
     * @param ?int $exactUserId User id only when it exactly matches a numeric search term
     * @param ?string $unverifiedPasswordAddress Address of an unconfirmed password that a merge will remove instead of demoting
     */
    public function __construct(
        public readonly array $userFields,
        public readonly array $identities,
        public readonly bool $hasPassword,
        public readonly bool $hasSecondFactor,
        public readonly ?int $exactUserId = null,
        public readonly ?string $unverifiedPasswordAddress = null,
    ) {
    }

    /** @return int Stable candidate user id */
    public function getRowKey(): int
    {
        return (int) $this->userFields[HilosUserTableRow::id];
    }

    /** @return string Payload key the candidate id travels under */
    public static function keyField(): string
    {
        return HilosUserTableRow::id;
    }

    /**
     * @return array<string, mixed> Flat row used by the in-memory table engine
     */
    public function toArray(): array
    {
        return $this->userFields + [
            HilosMergeCandidatesTable::FIELD_IDENTITIES => $this->identities,
            HilosMergeCandidatesTable::FIELD_HAS_PASSWORD => $this->hasPassword,
            HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR => $this->hasSecondFactor,
            HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS => $this->unverifiedPasswordAddress,
            self::identityAddresses => implode(' ', array_column($this->identities, 'identifier')),
            self::exactUserId => $this->exactUserId,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw candidate row payload
     * @return static Restored candidate row
     * @throws InvalidFormatException When the row carries no candidate user id
     */
    public static function fromArray(array $data): static
    {
        self::requireInt($data, HilosUserTableRow::id);
        $identities = self::optionalArray($data, HilosMergeCandidatesTable::FIELD_IDENTITIES) ?? [];
        $hasPassword = self::requireBool($data, HilosMergeCandidatesTable::FIELD_HAS_PASSWORD);
        $hasSecondFactor = self::requireBool($data, HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR);
        $exactUserId = self::optionalInt($data, self::exactUserId);
        $unverifiedPasswordAddress = self::optionalString($data, HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS);
        unset(
            $data[HilosMergeCandidatesTable::FIELD_IDENTITIES],
            $data[HilosMergeCandidatesTable::FIELD_HAS_PASSWORD],
            $data[HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR],
            $data[HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS],
            $data[self::identityAddresses],
            $data[self::exactUserId],
        );

        /** @var list<array{type: string, identifier: string, provider: ?string, verified: bool}> $identities */
        return new static(
            userFields: $data,
            identities: $identities,
            hasPassword: $hasPassword,
            hasSecondFactor: $hasSecondFactor,
            exactUserId: $exactUserId,
            unverifiedPasswordAddress: $unverifiedPasswordAddress,
        );
    }
}
