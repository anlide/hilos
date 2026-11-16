<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/** Immutable DB-only merge copy of a transferred backupcode row. */
final readonly class AccountMergeBackupCodeRow
{
    public const string id = 'id';
    public const string userId = 'userId';
    public const string code = 'code';
    public const string usedAt = 'usedAt';
    public const string createdAt = 'createdAt';

    private const array KEYS = [
        self::id,
        self::userId,
        self::code,
        self::usedAt,
        self::createdAt,
    ];

    /**
     * @param int $id Transferred backup-code id
     * @param int $userId Survivor account id
     * @param ?string $code Retained DB code or explicit null
     * @param ?int $usedAt Use in signed epoch milliseconds or null
     * @param int $createdAt Creation in signed epoch milliseconds
     * @throws InvalidFormatException When a row reference is not positive
     */
    public function __construct(
        public int $id,
        public int $userId,
        public ?string $code,
        public ?int $usedAt,
        public int $createdAt,
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
        foreach ([self::id, self::userId, self::createdAt] as $key) {
            if (!is_int($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires integer values under its declared fields.');
            }
        }
        foreach ([self::usedAt] as $key) {
            if ($data[$key] !== null && !is_int($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires integer or null values under its declared fields.');
            }
        }
        foreach ([self::code] as $key) {
            if ($data[$key] !== null && !is_string($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires string or null values under its declared fields.');
            }
        }

        return new self(
            id: $data[self::id],
            userId: $data[self::userId],
            code: $data[self::code],
            usedAt: $data[self::usedAt],
            createdAt: $data[self::createdAt],
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
            self::code => $this->code,
            self::usedAt => $this->usedAt,
            self::createdAt => $this->createdAt,
        ];
    }
}
