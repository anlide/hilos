<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/** Immutable DB-only merge copy of a transferred passkey row. */
final readonly class AccountMergePasskeyRow
{
    public const string id = 'id';
    public const string identityId = 'identityId';
    public const string userId = 'userId';
    public const string credentialId = 'credentialId';
    public const string publicKey = 'publicKey';
    public const string algorithm = 'algorithm';
    public const string signCount = 'signCount';
    public const string transports = 'transports';
    public const string aaguid = 'aaguid';
    public const string userHandle = 'userHandle';
    public const string label = 'label';
    public const string lastUsedAt = 'lastUsedAt';
    public const string createdAt = 'createdAt';

    private const array KEYS = [
        self::id,
        self::identityId,
        self::userId,
        self::credentialId,
        self::publicKey,
        self::algorithm,
        self::signCount,
        self::transports,
        self::aaguid,
        self::userHandle,
        self::label,
        self::lastUsedAt,
        self::createdAt,
    ];

    /**
     * @param int $id Transferred credential id
     * @param int $identityId Transferred identity id
     * @param int $userId Survivor account id
     * @param string $credentialId Unchanged credential identifier
     * @param string $publicKey Unchanged stored public key
     * @param int $algorithm Unchanged COSE algorithm
     * @param int $signCount Unchanged signature counter
     * @param ?string $transports Original stored transport string or null
     * @param ?string $aaguid Stored authenticator identifier or null
     * @param string $userHandle Original user-handle bytes
     * @param ?string $label Stored label or null
     * @param ?int $lastUsedAt Last use in signed epoch milliseconds or null
     * @param int $createdAt Creation in signed epoch milliseconds
     * @throws InvalidFormatException When a row reference is not positive
     */
    public function __construct(
        public int $id,
        public int $identityId,
        public int $userId,
        public string $credentialId,
        public string $publicKey,
        public int $algorithm,
        public int $signCount,
        public ?string $transports,
        public ?string $aaguid,
        public string $userHandle,
        public ?string $label,
        public ?int $lastUsedAt,
        public int $createdAt,
    ) {
        if ($id <= 0 || $identityId <= 0 || $userId <= 0) {
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
        foreach ([self::id, self::identityId, self::userId, self::algorithm, self::signCount, self::createdAt] as $key) {
            if (!is_int($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires integer values under its declared fields.');
            }
        }
        foreach ([self::lastUsedAt] as $key) {
            if ($data[$key] !== null && !is_int($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires integer or null values under its declared fields.');
            }
        }
        foreach ([self::credentialId, self::publicKey, self::userHandle] as $key) {
            if (!is_string($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires string values under its declared fields.');
            }
        }
        foreach ([self::transports, self::aaguid, self::label] as $key) {
            if ($data[$key] !== null && !is_string($data[$key])) {
                throw new InvalidFormatException('The merge copy row requires string or null values under its declared fields.');
            }
        }
        $userHandle = Base64Url::decode($data[self::userHandle]);
        if ($userHandle === null || Base64Url::encode($userHandle) !== $data[self::userHandle]) {
            throw new InvalidFormatException('The merge passkey user handle must be canonical Base64Url.');
        }

        return new self(
            id: $data[self::id],
            identityId: $data[self::identityId],
            userId: $data[self::userId],
            credentialId: $data[self::credentialId],
            publicKey: $data[self::publicKey],
            algorithm: $data[self::algorithm],
            signCount: $data[self::signCount],
            transports: $data[self::transports],
            aaguid: $data[self::aaguid],
            userHandle: $userHandle,
            label: $data[self::label],
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
            self::identityId => $this->identityId,
            self::userId => $this->userId,
            self::credentialId => $this->credentialId,
            self::publicKey => $this->publicKey,
            self::algorithm => $this->algorithm,
            self::signCount => $this->signCount,
            self::transports => $this->transports,
            self::aaguid => $this->aaguid,
            self::userHandle => Base64Url::encode($this->userHandle),
            self::label => $this->label,
            self::lastUsedAt => $this->lastUsedAt,
            self::createdAt => $this->createdAt,
        ];
    }
}
