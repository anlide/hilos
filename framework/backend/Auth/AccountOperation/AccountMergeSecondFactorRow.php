<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/** Immutable DB-only merge copy of a transferred secondfactor row. */
final readonly class AccountMergeSecondFactorRow
{
    public const string id = 'id';
    public const string userId = 'userId';
    public const string label = 'label';
    public const string secret = 'secret';
    public const string lastUsedStep = 'lastUsedStep';
    public const string confirmedAt = 'confirmedAt';
    public const string lastUsedAt = 'lastUsedAt';
    public const string createdAt = 'createdAt';

    private const array KEYS = [
        self::id,
        self::userId,
        self::label,
        self::secret,
        self::lastUsedStep,
        self::confirmedAt,
        self::lastUsedAt,
        self::createdAt,
    ];

    /**
     * @param int $id Transferred factor id
     * @param int $userId Survivor account id
     * @param string $label Stored label
     * @param ?string $secret Retained DB secret or explicit null
     * @param ?int $lastUsedStep Last accepted TOTP step or null, never a moment
     * @param int $confirmedAt Confirmation in signed epoch milliseconds
     * @param ?int $lastUsedAt Last use in signed epoch milliseconds or null
     * @param int $createdAt Creation in signed epoch milliseconds
     * @throws InvalidFormatException When a row reference is not positive
     */
    public function __construct(
        public int $id,
        public int $userId,
        public string $label,
        public ?string $secret,
        public ?int $lastUsedStep,
        public int $confirmedAt,
        public ?int $lastUsedAt,
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
        foreach ([self::id, self::userId, self::confirmedAt, self::createdAt] as $key) {
            if (!is_int($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires integer values under its declared fields.');
            }
        }
        foreach ([self::lastUsedStep, self::lastUsedAt] as $key) {
            if ($data[$key] !== null && !is_int($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires integer or null values under its declared fields.');
            }
        }
        foreach ([self::label] as $key) {
            if (!is_string($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires string values under its declared fields.');
            }
        }
        foreach ([self::secret] as $key) {
            if ($data[$key] !== null && !is_string($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires string or null values under its declared fields.');
            }
        }

        return new self(
            id: $data[self::id],
            userId: $data[self::userId],
            label: $data[self::label],
            secret: $data[self::secret],
            lastUsedStep: $data[self::lastUsedStep],
            confirmedAt: $data[self::confirmedAt],
            lastUsedAt: $data[self::lastUsedAt],
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
            self::label => $this->label,
            self::secret => $this->secret,
            self::lastUsedStep => $this->lastUsedStep,
            self::confirmedAt => $this->confirmedAt,
            self::lastUsedAt => $this->lastUsedAt,
            self::createdAt => $this->createdAt,
        ];
    }
}
