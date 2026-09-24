<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * One account the Hilos user page may offer as a merge loser (HIL-411).
 *
 * The project-owned user fields stay together so {@see AbstractHilosMergeCandidatesTable}
 * can put them in the entity-bearing `users` slot. The framework-owned identity projection is
 * kept beside them for the inline `merge` slot. Search-only fields never enter either slot.
 */
final class HilosMergeCandidateTableRow extends AbstractTableRow
{
    public const string identityAddresses = 'identityAddresses';
    public const string exactUserId = 'exactUserId';

    /**
     * @param array<string, mixed> $userFields Project user payload, including its numeric id
     * @param list<array{type: string, identifier: string, provider: ?string, verified: bool}> $identities Safe identity metadata
     * @param bool $hasPassword Whether the account has a password identity
     * @param ?int $exactUserId User id only when it exactly matches a numeric search term
     */
    public function __construct(
        public readonly array $userFields,
        public readonly array $identities,
        public readonly bool $hasPassword,
        public readonly ?int $exactUserId = null,
    ) {
    }

    /** @return int Stable candidate user id */
    public function getRowKey(): int
    {
        return (int) $this->userFields[AbstractHilosUserTableRow::id];
    }

    /** @return string Payload key the candidate id travels under */
    public static function keyField(): string
    {
        return AbstractHilosUserTableRow::id;
    }

    /**
     * @return array<string, mixed> Flat row used by the in-memory table engine
     */
    public function toArray(): array
    {
        return $this->userFields + [
            AbstractHilosMergeCandidatesTable::FIELD_IDENTITIES => $this->identities,
            AbstractHilosMergeCandidatesTable::FIELD_HAS_PASSWORD => $this->hasPassword,
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
        self::requireInt($data, AbstractHilosUserTableRow::id);
        $identities = self::optionalArray($data, AbstractHilosMergeCandidatesTable::FIELD_IDENTITIES) ?? [];
        $hasPassword = self::requireBool($data, AbstractHilosMergeCandidatesTable::FIELD_HAS_PASSWORD);
        $exactUserId = self::optionalInt($data, self::exactUserId);
        unset(
            $data[AbstractHilosMergeCandidatesTable::FIELD_IDENTITIES],
            $data[AbstractHilosMergeCandidatesTable::FIELD_HAS_PASSWORD],
            $data[self::identityAddresses],
            $data[self::exactUserId],
        );

        /** @var list<array{type: string, identifier: string, provider: ?string, verified: bool}> $identities */
        return new static($data, $identities, $hasPassword, $exactUserId);
    }
}
