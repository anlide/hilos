<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/** Immutable birth revision of one transferred snapshot. */
final readonly class AccountMergeRowRevision
{
    public const string id = 'id';
    public const string revision = 'revision';

    /**
     * @param int $id Transferred business row id
     * @param int $revision Revision assigned by its transfer write
     * @throws InvalidFormatException When either coordinate is not positive
     */
    public function __construct(public int $id, public int $revision)
    {
        if ($id <= 0 || $revision <= 0) {
            throw new InvalidFormatException('Merge admission ids and revisions must be positive.');
        }
    }

    /**
     * @param string $json Exact saved stamp object
     * @return self Checked immutable coordinates
     * @throws InvalidJsonException When the stamp is malformed JSON
     * @throws InvalidFormatException When keys, types or coordinates are invalid
     */
    public static function fromJson(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidJsonException('The merge admission stamp must be valid JSON.', previous: $failure);
        }
        if (!$object instanceof stdClass) {
            throw new InvalidFormatException('The merge admission stamp must be a JSON object.');
        }

        return self::fromArray(get_object_vars($object));
    }

    /**
     * @param array<string, mixed> $data Exact stamp at the JSON boundary
     * @return self Checked coordinates without coercion
     * @throws InvalidFormatException When keys, types or coordinates are invalid
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 2 || !isset($data[self::id], $data[self::revision])
            || !is_int($data[self::id]) || !is_int($data[self::revision])) {
            throw new InvalidFormatException('Merge admission stamps require exactly integer id and revision fields.');
        }

        return new self($data[self::id], $data[self::revision]);
    }

    /**
     * @return string Exact nonsecret stamp JSON
     * @throws JsonException When encoding fails
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int> Exact saved coordinates */
    public function toArray(): array
    {
        return [self::id => $this->id, self::revision => $this->revision];
    }
}
