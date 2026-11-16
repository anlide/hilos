<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/** Secret-bearing DB-only transfer copy, addressed by its give assignment. */
final readonly class AccountMergeHandoverCopy
{
    public const string operationId = 'operationId';
    public const string assignmentId = 'assignmentId';
    public const string loserUserId = 'loserUserId';
    public const string survivorUserId = 'survivorUserId';
    public const string loserPasswordIdentityId = 'loserPasswordIdentityId';
    public const string survivorPasswordIdentityId = 'survivorPasswordIdentityId';
    public const string effectiveWaitDays = 'effectiveWaitDays';
    public const string identities = 'identities';
    public const string passkeyCredentials = 'passkeyCredentials';
    public const string secondFactors = 'secondFactors';
    public const string secondFactorBackupCodes = 'secondFactorBackupCodes';

    private const array KEYS = [
        self::operationId, self::assignmentId, self::loserUserId, self::survivorUserId,
        self::loserPasswordIdentityId, self::survivorPasswordIdentityId, self::effectiveWaitDays,
        self::identities, self::passkeyCredentials, self::secondFactors, self::secondFactorBackupCodes,
    ];
    private const array ROW_KEYS = [self::identities, self::passkeyCredentials, self::secondFactors, self::secondFactorBackupCodes];

    /**
     * @param int $operationId Merge operation id
     * @param int $assignmentId Give assignment id shared with the result
     * @param int $loserUserId Original owner of transferred rows
     * @param int $survivorUserId Target owner of every copied row
     * @param ?int $loserPasswordIdentityId Loser preparation anchor or null
     * @param ?int $survivorPasswordIdentityId Survivor preparation anchor or null
     * @param ?int $effectiveWaitDays Saved loser effective wait at transfer, or null outside BOTH
     * @param list<AccountMergeIdentityRow> $identities Transferred identities in ascending id order
     * @param list<AccountMergePasskeyRow> $passkeyCredentials Transferred credentials in ascending id order
     * @param list<AccountMergeSecondFactorRow> $secondFactors Transferred factors in ascending id order
     * @param list<AccountMergeBackupCodeRow> $secondFactorBackupCodes Transferred codes in ascending id order
     * @throws InvalidFormatException When references, anchors, target ownership or typed lists disagree
     */
    public function __construct(
        public int $operationId,
        public int $assignmentId,
        public int $loserUserId,
        public int $survivorUserId,
        public ?int $loserPasswordIdentityId,
        public ?int $survivorPasswordIdentityId,
        public ?int $effectiveWaitDays,
        public array $identities,
        public array $passkeyCredentials,
        public array $secondFactors,
        public array $secondFactorBackupCodes,
    ) {
        if ($operationId <= 0 || $assignmentId <= 0 || $loserUserId <= 0 || $survivorUserId <= 0 || $loserUserId === $survivorUserId) {
            throw new InvalidFormatException('A merge copy requires positive references and distinct accounts.');
        }
        if (($loserPasswordIdentityId !== null && $loserPasswordIdentityId <= 0)
            || ($survivorPasswordIdentityId !== null && $survivorPasswordIdentityId <= 0)
            || ($effectiveWaitDays !== null && $effectiveWaitDays < 0)) {
            throw new InvalidFormatException('Merge copy password anchors must be positive and the saved wait nonnegative.');
        }
        self::validateRows($identities, AccountMergeIdentityRow::class, $survivorUserId);
        self::validateRows($passkeyCredentials, AccountMergePasskeyRow::class, $survivorUserId);
        self::validateRows($secondFactors, AccountMergeSecondFactorRow::class, $survivorUserId);
        self::validateRows($secondFactorBackupCodes, AccountMergeBackupCodeRow::class, $survivorUserId);
        $identityIds = [];
        foreach ($identities as $identity) {
            $identityIds[$identity->id] = true;
        }
        if (($loserPasswordIdentityId !== null && !isset($identityIds[$loserPasswordIdentityId]))
            || ($survivorPasswordIdentityId !== null && isset($identityIds[$survivorPasswordIdentityId]))) {
            throw new InvalidFormatException('Merge copy identities must include the loser password anchor and exclude the survivor anchor.');
        }
        foreach ($passkeyCredentials as $credential) {
            if (!isset($identityIds[$credential->identityId])) {
                throw new InvalidFormatException('A merge passkey must reference an identity in the same copy.');
            }
        }
    }

    /**
     * @param string $json Complete saved secret-bearing copy
     * @return self Strictly checked copy with binary user handles restored
     * @throws InvalidJsonException When the copy is malformed JSON
     * @throws InvalidFormatException When the root, rows, fields or references are invalid
     */
    public static function fromJson(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidJsonException('The merge handover copy must be valid JSON.', previous: $failure);
        }
        if (!$object instanceof stdClass) {
            throw new InvalidFormatException('The merge handover copy must be a JSON object.');
        }
        $data = get_object_vars($object);
        foreach (self::ROW_KEYS as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                throw new InvalidFormatException('Merge copy rows must be JSON lists.');
            }
            foreach ($data[$key] as $index => $row) {
                if (!$row instanceof stdClass) {
                    throw new InvalidFormatException('A merge copy row must be a JSON object.');
                }
                $data[$key][$index] = get_object_vars($row);
            }
        }

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data Exact copy fields at the JSON boundary
     * @return self Strictly checked copy without normalization of saved lists
     * @throws InvalidFormatException When keys, types, anchors or row lists are invalid
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== count(self::KEYS)) {
            throw new InvalidFormatException('The merge handover copy requires exactly its declared fields.');
        }
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidFormatException('The merge handover copy is missing a required field.');
            }
        }
        foreach ([self::operationId, self::assignmentId, self::loserUserId, self::survivorUserId] as $key) {
            if (!is_int($data[$key])) {
                throw new InvalidFormatException('Merge copy references must be integers.');
            }
        }
        foreach ([self::loserPasswordIdentityId, self::survivorPasswordIdentityId, self::effectiveWaitDays] as $key) {
            if ($data[$key] !== null && !is_int($data[$key])) {
                throw new InvalidFormatException('Merge copy anchors and saved wait must be integers or null.');
            }
        }
        foreach (self::ROW_KEYS as $key) {
            if (!is_array($data[$key]) || !array_is_list($data[$key])) {
                throw new InvalidFormatException('Merge copy rows must be lists.');
            }
            foreach ($data[$key] as $row) {
                if (!is_array($row)) {
                    throw new InvalidFormatException('A merge copy row must have its declared fields.');
                }
            }
        }

        return new self(
            operationId: $data[self::operationId],
            assignmentId: $data[self::assignmentId],
            loserUserId: $data[self::loserUserId],
            survivorUserId: $data[self::survivorUserId],
            loserPasswordIdentityId: $data[self::loserPasswordIdentityId],
            survivorPasswordIdentityId: $data[self::survivorPasswordIdentityId],
            effectiveWaitDays: $data[self::effectiveWaitDays],
            identities: array_map(AccountMergeIdentityRow::fromArray(...), $data[self::identities]),
            passkeyCredentials: array_map(AccountMergePasskeyRow::fromArray(...), $data[self::passkeyCredentials]),
            secondFactors: array_map(AccountMergeSecondFactorRow::fromArray(...), $data[self::secondFactors]),
            secondFactorBackupCodes: array_map(AccountMergeBackupCodeRow::fromArray(...), $data[self::secondFactorBackupCodes]),
        );
    }

    /**
     * @return string Secret-bearing JSON for private copy storage
     * @throws JsonException When a stored string cannot be encoded without loss
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> Exact secret-bearing copy fields at the JSON boundary */
    public function toArray(): array
    {
        return [
            self::operationId => $this->operationId,
            self::assignmentId => $this->assignmentId,
            self::loserUserId => $this->loserUserId,
            self::survivorUserId => $this->survivorUserId,
            self::loserPasswordIdentityId => $this->loserPasswordIdentityId,
            self::survivorPasswordIdentityId => $this->survivorPasswordIdentityId,
            self::effectiveWaitDays => $this->effectiveWaitDays,
            self::identities => array_map(static fn(AccountMergeIdentityRow $row): array => $row->toArray(), $this->identities),
            self::passkeyCredentials => array_map(static fn(AccountMergePasskeyRow $row): array => $row->toArray(), $this->passkeyCredentials),
            self::secondFactors => array_map(static fn(AccountMergeSecondFactorRow $row): array => $row->toArray(), $this->secondFactors),
            self::secondFactorBackupCodes => array_map(
                static fn(AccountMergeBackupCodeRow $row): array => $row->toArray(),
                $this->secondFactorBackupCodes,
            ),
        ];
    }

    /**
     * @param array $rows Typed transferred row list
     * @param class-string $rowClass Required concrete row type
     * @param int $userId Target owner
     * @throws InvalidFormatException When types, target ownership or ascending unique positive ids disagree
     */
    private static function validateRows(array $rows, string $rowClass, int $userId): void
    {
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Merge copy rows must be lists.');
        }
        $previousId = 0;
        foreach ($rows as $row) {
            if (!$row instanceof $rowClass || $row->id <= $previousId || $row->userId !== $userId) {
                throw new InvalidFormatException('Merge copy rows must have the declared type, target owner and ascending unique positive ids.');
            }
            $previousId = $row->id;
        }
    }
}
