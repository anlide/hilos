<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\PasskeyCredential as EntityPasskeyCredential;
use Hilos\Database\Entity\Item\SecondFactor as EntitySecondFactor;
use Hilos\Database\Entity\Item\SecondFactorBackupCode as EntitySecondFactorBackupCode;
use Hilos\Database\PhpType;

/** Immutable transfer coordinates and a complete, nonsecret mapped row in PHP representation. */
final readonly class DbSyncAdmissionData
{
    public const string operationId = 'operationId';
    public const string assignmentId = 'assignmentId';
    public const string ownerUserId = 'ownerUserId';
    public const string revision = 'revision';
    public const string snapshot = 'snapshot';

    private const array KEYS = [self::operationId, self::assignmentId, self::ownerUserId, self::revision, self::snapshot];

    /**
     * @param int $operationId Last transfer operation
     * @param int $assignmentId Last transfer assignment
     * @param int $ownerUserId Current canonical owner
     * @param int $revision Committed row revision
     * @param ?array<string, mixed> $snapshot Full mapped row, or null for deletion
     * @throws InvalidFormatException When coordinates are not positive
     */
    public function __construct(
        public int $operationId,
        public int $assignmentId,
        public int $ownerUserId,
        public int $revision,
        public ?array $snapshot,
    ) {
        if ($operationId <= 0 || $assignmentId <= 0 || $ownerUserId <= 0 || $revision <= 0) {
            throw new InvalidFormatException('DB sync admission coordinates must be positive.');
        }
    }

    /**
     * @param array<string, mixed> $data Exact admission wire fields
     * @param string $collectionKey Collection whose mapped row is carried
     * @return self Decoded coordinates and snapshot
     * @throws InvalidFormatException When metadata or the binary representation is malformed
     */
    public static function fromArray(array $data, string $collectionKey): self
    {
        if (count($data) !== count(self::KEYS) || array_diff(self::KEYS, array_keys($data)) !== []) {
            throw new InvalidFormatException('DB sync admission requires exactly its declared fields.');
        }
        foreach ([self::operationId, self::assignmentId, self::ownerUserId, self::revision] as $key) {
            if (!is_int($data[$key])) {
                throw new InvalidFormatException('DB sync admission coordinates must be integers.');
            }
        }
        if ($data[self::snapshot] !== null && !is_array($data[self::snapshot])) {
            throw new InvalidFormatException('DB sync admission snapshot must be a mapped row or null.');
        }

        return new self(
            $data[self::operationId], $data[self::assignmentId], $data[self::ownerUserId], $data[self::revision],
            $data[self::snapshot] === null ? null : self::decodeRow($collectionKey, $data[self::snapshot]),
        );
    }

    /**
     * @param string $collectionKey Collection whose binary fields need transport encoding
     * @return array<string, mixed> Exact wire fields
     */
    public function toArray(string $collectionKey): array
    {
        return [
            self::operationId => $this->operationId,
            self::assignmentId => $this->assignmentId,
            self::ownerUserId => $this->ownerUserId,
            self::revision => $this->revision,
            self::snapshot => $this->snapshot === null ? null : self::encodeRow($collectionKey, $this->snapshot),
        ];
    }

    /**
     * Validates the full row without constructing an Entity or touching the database.
     *
     * @param string $collectionKey Protected collection
     * @param string $idString Canonical positive primary key
     * @param bool $delete Whether the enclosing frame is a deletion
     * @param ?array<string, mixed> $row Enclosing mapped row or diff when validating a frame
     * @param ?array<string, mixed> $previous Previous mapped values when validating an update
     * @throws InvalidFormatException When keys, scalar types, row identity or deletion state disagree
     */
    public function validate(string $collectionKey, string $idString, bool $delete, ?array $row = null, ?array $previous = null): void
    {
        $entityClass = self::entityClass($collectionKey);
        if (!ctype_digit($idString) || (int)$idString <= 0 || (string)(int)$idString !== $idString
            || $delete !== ($this->snapshot === null)) {
            throw new InvalidFormatException('DB sync admission row identity or deletion state is invalid.');
        }
        if ($this->snapshot !== null) {
            self::validateRow($collectionKey, $this->snapshot, full: true);
            if ($this->snapshot[$entityClass::id] !== (int)$idString || $this->snapshot[$entityClass::user_id] !== $this->ownerUserId) {
                throw new InvalidFormatException('DB sync admission snapshot disagrees with its row or owner.');
            }
        }
        if ($row !== null) {
            self::validateRow($collectionKey, $row);
            foreach ($row as $column => $value) {
                if (($column === $entityClass::id && $value !== (int)$idString)
                    || ($column === $entityClass::user_id && $value !== $this->ownerUserId)
                    || ($this->snapshot !== null && $value !== $this->snapshot[$column])) {
                    throw new InvalidFormatException('DB sync row values disagree with their admitted snapshot.');
                }
            }
        }
        if ($previous !== null) {
            self::validateRow($collectionKey, $previous);
            if (array_key_exists($entityClass::id, $previous) && $previous[$entityClass::id] !== (int)$idString) {
                throw new InvalidFormatException('DB sync previous values must retain their primary key.');
            }
        }
    }

    /**
     * @param string $collectionKey Protected collection
     * @param array<string, mixed> $row Full mapped row or partial diff
     * @param bool $full Whether every mapped column must be present
     * @throws InvalidFormatException When a row contains unmapped keys or incorrectly typed values
     */
    public static function validateRow(string $collectionKey, array $row, bool $full = false): void
    {
        $entityClass = self::entityClass($collectionKey);
        if (array_diff(array_keys($row), $entityClass::_columns) !== []
            || ($full && array_diff($entityClass::_columns, array_keys($row)) !== [])) {
            throw new InvalidFormatException('DB sync admission carries only the exact mapped columns.');
        }
        $nullable = match ($collectionKey) {
            HilosDbContext::identities => [EntityIdentity::provider, EntityIdentity::provider_name, EntityIdentity::password_changed_at],
            HilosDbContext::passkeyCredentials => [EntityPasskeyCredential::transports, EntityPasskeyCredential::aaguid,
                EntityPasskeyCredential::label, EntityPasskeyCredential::last_used_at],
            HilosDbContext::secondFactors => [EntitySecondFactor::last_used_step, EntitySecondFactor::confirmed_at, EntitySecondFactor::last_used_at],
            HilosDbContext::secondFactorBackupCodes => [EntitySecondFactorBackupCode::used_at],
        };
        foreach ($row as $key => $value) {
            if ($value === null && in_array($key, $nullable, true)) {
                continue;
            }
            $valid = match ($entityClass::_types[$key]) {
                PhpType::INTEGER->value => is_int($value),
                PhpType::BOOLEAN->value => is_bool($value),
                PhpType::STRING->value, PhpType::TEXT->value, PhpType::BINARY->value => is_string($value),
                default => false,
            };
            if (in_array($key, [EntityIdentity::id, EntityIdentity::user_id, EntityPasskeyCredential::identity_id], true)
                && (!is_int($value) || $value <= 0)) {
                throw new InvalidFormatException('DB sync admission row references must be positive.');
            }
            if (!$valid) {
                throw new InvalidFormatException('DB sync admission mapped values must retain their declared scalar types.');
            }
        }
    }

    /**
     * @param string $collectionKey Enclosing frame collection
     * @param array<string, mixed> $row Full row, diff or previous values in PHP form
     * @return array<string, mixed> Wire row with canonical Base64Url for a passkey handle
     */
    public static function encodeRow(string $collectionKey, array $row): array
    {
        if ($collectionKey === HilosDbContext::passkeyCredentials && array_key_exists(EntityPasskeyCredential::user_handle, $row)) {
            $row[EntityPasskeyCredential::user_handle] = Base64Url::encode($row[EntityPasskeyCredential::user_handle]);
        }

        return $row;
    }

    /**
     * @param string $collectionKey Enclosing frame collection
     * @param array<string, mixed> $row Full row, diff or previous values from the wire
     * @return array<string, mixed> PHP row with the original passkey handle bytes
     * @throws InvalidFormatException When a handle is not canonical Base64Url
     */
    public static function decodeRow(string $collectionKey, array $row): array
    {
        if ($collectionKey === HilosDbContext::passkeyCredentials && array_key_exists(EntityPasskeyCredential::user_handle, $row)) {
            $encoded = $row[EntityPasskeyCredential::user_handle];
            $decoded = is_string($encoded) ? Base64Url::decode($encoded) : null;
            if ($decoded === null || Base64Url::encode($decoded) !== $encoded) {
                throw new InvalidFormatException('DB sync passkey user handle must be canonical Base64Url.');
            }
            $row[EntityPasskeyCredential::user_handle] = $decoded;
        }

        return $row;
    }

    /**
     * @param string $collectionKey Protected collection
     * @return class-string<Entity> Static mapped row declaration
     * @throws InvalidFormatException When the collection cannot carry admission metadata
     */
    private static function entityClass(string $collectionKey): string
    {
        return match ($collectionKey) {
            HilosDbContext::identities => EntityIdentity::class,
            HilosDbContext::passkeyCredentials => EntityPasskeyCredential::class,
            HilosDbContext::secondFactors => EntitySecondFactor::class,
            HilosDbContext::secondFactorBackupCodes => EntitySecondFactorBackupCode::class,
            default => throw new InvalidFormatException('DB sync admission requires a protected collection.'),
        };
    }
}
