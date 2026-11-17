<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/** Original transfer revisions, independent of subsequent sidecar progress. */
final readonly class AccountMergeAdmissionManifest
{
    public const string identities = 'identities';
    public const string passkeyCredentials = 'passkeyCredentials';
    public const string secondFactors = 'secondFactors';
    public const string secondFactorBackupCodes = 'secondFactorBackupCodes';

    private const array KEYS = [self::identities, self::passkeyCredentials, self::secondFactors, self::secondFactorBackupCodes];

    /**
     * @param list<AccountMergeRowRevision> $identities Original identity stamps
     * @param list<AccountMergeRowRevision> $passkeyCredentials Original credential stamps
     * @param list<AccountMergeRowRevision> $secondFactors Original factor stamps
     * @param list<AccountMergeRowRevision> $secondFactorBackupCodes Original backup-code stamps
     * @throws InvalidFormatException When a list is untyped, unordered or duplicated
     */
    public function __construct(
        public array $identities,
        public array $passkeyCredentials,
        public array $secondFactors,
        public array $secondFactorBackupCodes,
    ) {
        foreach (self::KEYS as $key) {
            if (!array_is_list($this->{$key})) {
                throw new InvalidFormatException('Merge admission stamps must be lists.');
            }
            $previousId = 0;
            foreach ($this->{$key} as $stamp) {
                if (!$stamp instanceof AccountMergeRowRevision || $stamp->id <= $previousId) {
                    throw new InvalidFormatException('Merge admission stamps must have ascending unique ids.');
                }
                $previousId = $stamp->id;
            }
        }
    }

    /**
     * @param string $json Saved exact manifest
     * @return self Checked stamps without sorting, deduplication or type coercion
     * @throws InvalidJsonException When the manifest is malformed JSON
     * @throws InvalidFormatException When the root, keys, lists or stamps are invalid
     */
    public static function fromJson(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidJsonException('The merge admission manifest must be valid JSON.', previous: $failure);
        }
        if (!$object instanceof stdClass) {
            throw new InvalidFormatException('The merge admission manifest must be a JSON object.');
        }
        return self::fromObject($object);
    }

    /**
     * @param stdClass $object Decoded manifest at the JSON boundary
     * @return self Strictly typed original stamps
     * @throws InvalidFormatException When the keys, lists or stamps are invalid
     */
    public static function fromObject(stdClass $object): self
    {
        $data = get_object_vars($object);
        foreach ($data as &$rows) {
            if (!is_array($rows)) {
                throw new InvalidFormatException('Merge admission fields must be JSON arrays.');
            }
            foreach ($rows as &$row) {
                if (!$row instanceof stdClass) {
                    throw new InvalidFormatException('Merge admission stamps must be JSON objects.');
                }
                $row = get_object_vars($row);
            }
            unset($row);
        }
        unset($rows);

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data Exact manifest at the JSON boundary
     * @return self Checked immutable typed manifest
     * @throws InvalidFormatException When keys, lists or stamps are invalid
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== count(self::KEYS)) {
            throw new InvalidFormatException('The merge admission manifest requires exactly its four lists.');
        }
        foreach (self::KEYS as $key) {
            if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) {
                throw new InvalidFormatException('The merge admission manifest requires every declared list.');
            }
            foreach ($data[$key] as $row) {
                if (!is_array($row)) {
                    throw new InvalidFormatException('Merge admission stamps must be objects at the array boundary.');
                }
            }
        }

        return new self(
            array_map(AccountMergeRowRevision::fromArray(...), $data[self::identities]),
            array_map(AccountMergeRowRevision::fromArray(...), $data[self::passkeyCredentials]),
            array_map(AccountMergeRowRevision::fromArray(...), $data[self::secondFactors]),
            array_map(AccountMergeRowRevision::fromArray(...), $data[self::secondFactorBackupCodes]),
        );
    }

    /**
     * @return string Exact nonsecret manifest JSON
     * @throws JsonException When encoding fails
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /** @return array<string, list<array<string, int>>> Exact original stamps */
    public function toArray(): array
    {
        $data = [];
        foreach (self::KEYS as $key) {
            $data[$key] = array_map(static fn (AccountMergeRowRevision $row): array => $row->toArray(), $this->{$key});
        }

        return $data;
    }
}
