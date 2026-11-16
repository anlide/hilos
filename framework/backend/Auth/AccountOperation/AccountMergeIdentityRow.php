<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/** Immutable DB-only merge copy of a transferred identity row. */
final readonly class AccountMergeIdentityRow
{
    public const string id = 'id';
    public const string userId = 'userId';
    public const string type = 'type';
    public const string identifier = 'identifier';
    public const string secret = 'secret';
    public const string provider = 'provider';
    public const string verified = 'verified';
    public const string passwordChangedAt = 'passwordChangedAt';
    public const string providerName = 'providerName';
    public const string createdAt = 'createdAt';
    public const string updatedAt = 'updatedAt';

    private const array KEYS = [
        self::id,
        self::userId,
        self::type,
        self::identifier,
        self::secret,
        self::provider,
        self::verified,
        self::passwordChangedAt,
        self::providerName,
        self::createdAt,
        self::updatedAt,
    ];

    /**
     * @param int $id Transferred identity id
     * @param int $userId Survivor account id
     * @param string $type Stored identity type
     * @param string $identifier Stored identifier
     * @param ?string $secret Retained DB secret or explicit null
     * @param ?string $provider Stored provider or null
     * @param bool $verified Stored verification flag
     * @param ?int $passwordChangedAt Password change in signed epoch milliseconds or null
     * @param ?string $providerName Stored provider name or null
     * @param int $createdAt Creation in signed epoch milliseconds
     * @param int $updatedAt Update in signed epoch milliseconds
     * @throws InvalidFormatException When a row reference is not positive
     */
    public function __construct(
        public int $id,
        public int $userId,
        public string $type,
        public string $identifier,
        public ?string $secret,
        public ?string $provider,
        public bool $verified,
        public ?int $passwordChangedAt,
        public ?string $providerName,
        public int $createdAt,
        public int $updatedAt,
    ) {
        if ($id <= 0 || $userId <= 0) {
            throw new InvalidFormatException('Merge copy row references must be positive.');
        }
    }

    /**
     * @param string $json Complete saved row object
     * @return self Strictly checked row, preserving explicit nullable values
     * @throws InvalidJsonException When the row is malformed JSON
     * @throws InvalidFormatException When the root, keys, types or references are invalid
     */
    public static function fromJson(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidJsonException('The merge copy row must be valid JSON.', previous: $failure);
        }
        if (!$object instanceof stdClass) {
            throw new InvalidFormatException('The merge copy row must be a JSON object.');
        }

        return self::fromArray(get_object_vars($object));
    }

    /**
     * @param array<string, mixed> $data Complete row at the JSON boundary
     * @return self Strictly checked immutable row
     * @throws InvalidFormatException When keys, scalar types or references are invalid
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== count(self::KEYS)) {
            throw new InvalidFormatException('The merge copy row requires exactly its declared fields.');
        }
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidFormatException('The merge copy row is missing a required field.');
            }
        }
        foreach ([self::id, self::userId, self::createdAt, self::updatedAt] as $key) {
            if (!is_int($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires integer values under its declared fields.');
            }
        }
        foreach ([self::passwordChangedAt] as $key) {
            if ($data[$key] !== null && !is_int($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires integer or null values under its declared fields.');
            }
        }
        foreach ([self::type, self::identifier] as $key) {
            if (!is_string($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires string values under its declared fields.');
            }
        }
        foreach ([self::secret, self::provider, self::providerName] as $key) {
            if ($data[$key] !== null && !is_string($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires string or null values under its declared fields.');
            }
        }
        foreach ([self::verified] as $key) {
            if (!is_bool($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires boolean values under its declared fields.');
            }
        }

        return new self(
            id: $data[self::id],
            userId: $data[self::userId],
            type: $data[self::type],
            identifier: $data[self::identifier],
            secret: $data[self::secret],
            provider: $data[self::provider],
            verified: $data[self::verified],
            passwordChangedAt: $data[self::passwordChangedAt],
            providerName: $data[self::providerName],
            createdAt: $data[self::createdAt],
            updatedAt: $data[self::updatedAt],
        );
    }

    /**
     * @return string Complete row JSON with all nullable keys
     * @throws JsonException When a stored string cannot be encoded without loss
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> Exact row fields at the JSON boundary */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::userId => $this->userId,
            self::type => $this->type,
            self::identifier => $this->identifier,
            self::secret => $this->secret,
            self::provider => $this->provider,
            self::verified => $this->verified,
            self::passwordChangedAt => $this->passwordChangedAt,
            self::providerName => $this->providerName,
            self::createdAt => $this->createdAt,
            self::updatedAt => $this->updatedAt,
        ];
    }
}
