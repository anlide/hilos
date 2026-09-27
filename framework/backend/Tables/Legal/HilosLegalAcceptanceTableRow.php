<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Legal administration table row and its wire representation.
 */
final class HilosLegalAcceptanceTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string userId = 'userId';
    public const string name = 'name';
    public const string email = 'email';
    public const string document = 'document';
    public const string revisionId = 'revisionId';
    public const string declared = 'declared';
    public const string acceptedAt = 'acceptedAt';

    /**
     * @param int $rowKey Stable row identity
     * @param int $userId UserId
     * @param string $name Name
     * @param ?string $email Email
     * @param string $document Document
     * @param string $revisionId RevisionId
     * @param ?bool $declared Whether the revision is declared, unknown when the catalog is invalid
     * @param string $acceptedAt AcceptedAt
     */
    public function __construct(
        public int $rowKey,
        public int $userId,
        public string $name,
        public ?string $email,
        public string $document,
        public string $revisionId,
        public ?bool $declared,
        public string $acceptedAt,
    ) {
    }

    /** @return int Stable row identity */
    public function getRowKey(): int
    {
        return $this->rowKey;
    }

    /** @return string Identity field in the wire payload */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Serialized table row */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::userId => $this->userId,
            self::name => $this->name,
            self::email => $this->email,
            self::document => $this->document,
            self::revisionId => $this->revisionId,
            self::declared => $this->declared,
            self::acceptedAt => $this->acceptedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire row payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a field is absent or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireInt($data, self::rowKey),
            userId: self::requireInt($data, self::userId),
            name: self::requireString($data, self::name),
            email: self::optionalString($data, self::email),
            document: self::requireString($data, self::document),
            revisionId: self::requireString($data, self::revisionId),
            declared: self::optionalBool($data, self::declared),
            acceptedAt: self::requireString($data, self::acceptedAt),
        );
    }
}
