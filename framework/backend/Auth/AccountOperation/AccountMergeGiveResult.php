<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Database\Identity\PasswordFate;
use JsonException;
use stdClass;

/** Durable nonsecret give fact, independent of the lifetime of the handover copy. */
final readonly class AccountMergeGiveResult
{
    public const string give = 'give';
    public const string rows = 'rows';
    public const string identities = 'identities';
    public const string passkeyCredentials = 'passkeyCredentials';
    public const string secondFactors = 'secondFactors';
    public const string secondFactorBackupCodes = 'secondFactorBackupCodes';
    public const string identitiesMoved = 'identitiesMoved';
    public const string passwordKept = 'passwordKept';
    public const string secondFactorKept = 'secondFactorKept';
    public const string effectiveWaitDays = 'effectiveWaitDays';
    public const string admission = 'admission';
    public const string GIVEN = 'given';
    public const string SECOND_FACTOR_BOTH = 'both';
    public const string SECOND_FACTOR_LOSER = 'loser';
    public const string SECOND_FACTOR_SURVIVOR = 'survivor';
    public const string SECOND_FACTOR_NONE = 'none';

    private const array KEYS = [
        self::give, self::rows, self::identitiesMoved, self::passwordKept, self::secondFactorKept, self::effectiveWaitDays, self::admission,
    ];
    private const array ROW_KEYS = [self::identities, self::passkeyCredentials, self::secondFactors, self::secondFactorBackupCodes];
    private const array SECOND_FACTOR_OUTCOMES = [
        self::SECOND_FACTOR_BOTH, self::SECOND_FACTOR_LOSER, self::SECOND_FACTOR_SURVIVOR, self::SECOND_FACTOR_NONE,
    ];

    public int $identitiesMoved;

    /**
     * @param list<int> $identities Original transferred identity ids
     * @param list<int> $passkeyCredentials Original transferred credential ids
     * @param list<int> $secondFactors Original transferred factor ids
     * @param list<int> $secondFactorBackupCodes Original transferred backup-code ids
     * @param PasswordFate $passwordKept Actual retained password outcome
     * @param string $secondFactorKept Actual retained protection outcome
     * @param AccountMergeAdmissionManifest $admission Original revisions assigned by the transfer writes
     * @param ?int $effectiveWaitDays Captured source wait for BOTH, otherwise null
     * @throws InvalidFormatException When an outcome, wait or ascending unique positive id list is invalid
     */
    public function __construct(
        public array $identities,
        public array $passkeyCredentials,
        public array $secondFactors,
        public array $secondFactorBackupCodes,
        public PasswordFate $passwordKept,
        public string $secondFactorKept,
        public AccountMergeAdmissionManifest $admission,
        public ?int $effectiveWaitDays,
    ) {
        foreach ([$identities, $passkeyCredentials, $secondFactors, $secondFactorBackupCodes] as $ids) {
            self::validateIds($ids);
        }
        if (!in_array($secondFactorKept, self::SECOND_FACTOR_OUTCOMES, true)) {
            throw new InvalidFormatException('The merge give second-factor outcome is unknown.');
        }
        if ($effectiveWaitDays !== null && $effectiveWaitDays < 0) {
            throw new InvalidFormatException('The merge give effective wait must be nonnegative or null.');
        }
        foreach (self::ROW_KEYS as $key) {
            if ($this->{$key} !== array_column($admission->{$key}, AccountMergeRowRevision::id)) {
                throw new InvalidFormatException('The merge give rows must match their original admission stamps.');
            }
        }
        $this->identitiesMoved = count($identities);
    }

    /**
     * @param AccountOperationResultPayload $payload Saved opaque give fact
     * @return self Strictly checked nonsecret manifest and outcomes
     * @throws InvalidJsonException When the saved result is malformed JSON
     * @throws InvalidFormatException When the result does not have the exact give contract
     */
    public static function of(AccountOperationResultPayload $payload): self
    {
        return self::fromJson($payload->toJson());
    }

    /**
     * @param string $json Saved nonsecret give fact
     * @return self Strictly checked manifest and outcomes
     * @throws InvalidJsonException When the result is malformed JSON
     * @throws InvalidFormatException When keys, types, ids or outcomes are invalid
     */
    public static function fromJson(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidJsonException('The merge give result must be valid JSON.', previous: $failure);
        }
        if (!$object instanceof stdClass || !($object->{self::rows} ?? null) instanceof stdClass) {
            throw new InvalidFormatException('The merge give result and its row manifest must be JSON objects.');
        }
        $data = get_object_vars($object);
        $data[self::rows] = get_object_vars($object->{self::rows});
        if (!($object->{self::admission} ?? null) instanceof stdClass) {
            throw new InvalidFormatException('The merge give result requires its admission object.');
        }
        $data[self::admission] = AccountMergeAdmissionManifest::fromObject($object->{self::admission})->toArray();

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data Exact nonsecret result at the JSON boundary
     * @return self Checked manifest without normalization of saved ids
     * @throws InvalidFormatException When keys, types, ids, count or outcomes are invalid
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== count(self::KEYS)) {
            throw new InvalidFormatException('The merge give result requires exactly its declared fields.');
        }
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidFormatException('The merge give result is missing a required field.');
            }
        }
        if ($data[self::give] !== self::GIVEN || !is_array($data[self::rows]) || count($data[self::rows]) !== count(self::ROW_KEYS)) {
            throw new InvalidFormatException('The merge give result requires a given fact and its exact row manifest.');
        }
        if (!is_array($data[self::admission])) {
            throw new InvalidFormatException('The merge give result requires its original admission manifest.');
        }
        foreach (self::ROW_KEYS as $key) {
            if (!array_key_exists($key, $data[self::rows]) || !is_array($data[self::rows][$key])) {
                throw new InvalidFormatException('The merge give manifest requires every transferred id list.');
            }
            self::validateIds($data[self::rows][$key]);
        }
        if (!is_int($data[self::identitiesMoved]) || $data[self::identitiesMoved] !== count($data[self::rows][self::identities])) {
            throw new InvalidFormatException('The merge give identity count must equal its transferred identity list length.');
        }
        if (!is_string($data[self::passwordKept]) || PasswordFate::tryFrom($data[self::passwordKept]) === null
            || !is_string($data[self::secondFactorKept])) {
            throw new InvalidFormatException('The merge give retention outcomes must be known strings.');
        }
        if ($data[self::effectiveWaitDays] !== null && !is_int($data[self::effectiveWaitDays])) {
            throw new InvalidFormatException('The merge give effective wait must be an integer or null.');
        }

        return new self(
            identities: $data[self::rows][self::identities],
            passkeyCredentials: $data[self::rows][self::passkeyCredentials],
            secondFactors: $data[self::rows][self::secondFactors],
            secondFactorBackupCodes: $data[self::rows][self::secondFactorBackupCodes],
            passwordKept: PasswordFate::from($data[self::passwordKept]),
            secondFactorKept: $data[self::secondFactorKept],
            admission: AccountMergeAdmissionManifest::fromArray($data[self::admission]),
            effectiveWaitDays: $data[self::effectiveWaitDays],
        );
    }

    /**
     * @return AccountOperationResultPayload Nonsecret fact for the existing result boundary
     * @throws JsonException When the result cannot be encoded
     * @throws InvalidJsonException When the opaque boundary refuses the encoded JSON
     * @throws InvalidFormatException When the opaque boundary refuses the object shape
     */
    public function toPayload(): AccountOperationResultPayload
    {
        return AccountOperationResultPayload::fromJson($this->toJson());
    }

    /**
     * @return string Exact nonsecret give JSON
     * @throws JsonException When the result cannot be encoded
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> Only transferred row ids, count and retention outcomes */
    public function toArray(): array
    {
        return [
            self::give => self::GIVEN,
            self::rows => [
                self::identities => $this->identities,
                self::passkeyCredentials => $this->passkeyCredentials,
                self::secondFactors => $this->secondFactors,
                self::secondFactorBackupCodes => $this->secondFactorBackupCodes,
            ],
            self::identitiesMoved => $this->identitiesMoved,
            self::passwordKept => $this->passwordKept->value,
            self::secondFactorKept => $this->secondFactorKept,
            self::effectiveWaitDays => $this->effectiveWaitDays,
            self::admission => $this->admission->toArray(),
        ];
    }

    /**
     * @param array $ids Saved transferred ids
     * @throws InvalidFormatException When the ids are not a list of ascending unique positive integers
     */
    private static function validateIds(array $ids): void
    {
        if (!array_is_list($ids)) {
            throw new InvalidFormatException('Merge give row ids must be lists.');
        }
        $previousId = 0;
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= $previousId) {
                throw new InvalidFormatException('Merge give row ids must be positive, ascending and unique.');
            }
            $previousId = $id;
        }
    }
}
