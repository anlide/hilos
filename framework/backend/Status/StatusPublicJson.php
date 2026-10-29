<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Status\Exception\StatusException;
use JsonException;
use stdClass;

/** Strict disk boundary: objects, lists and scalar types retain their JSON meaning. */
final class StatusPublicJson
{
    /**
     * @param string $json Public file contents
     * @return mixed Decoded JSON, with objects distinct from lists
     * @throws StatusException When the JSON is malformed
     */
    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StatusException('Invalid public Status JSON.', 0, $exception);
        }
    }

    /**
     * @param mixed $value Decoded object
     * @param list<string> $keys Exact required public fields
     * @return array<string, mixed> Fields at the deserialization boundary
     * @throws StatusException When a required field is missing or an unknown field is present
     */
    public static function fields(mixed $value, array $keys): array
    {
        if (!$value instanceof stdClass) {
            throw new StatusException('Public Status requires a JSON object.');
        }
        $fields = get_object_vars($value);
        if (count($fields) !== count($keys) || array_diff($keys, array_keys($fields)) !== []) {
            throw new StatusException('Invalid public Status object fields.');
        }
        return $fields;
    }

    /**
     * @param mixed $value Decoded list
     * @return list<mixed> Values in file order
     * @throws StatusException When the value is not a JSON list
     */
    public static function items(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new StatusException('Public Status requires a JSON list.');
        }
        return $value;
    }

    /**
     * @param mixed $value Decoded component key list
     * @return list<string> Unique nonempty public catalog keys in canonical string order
     * @throws StatusException When the list contains invalid or duplicate keys
     */
    public static function componentKeys(mixed $value): array
    {
        $keys = self::items($value);
        foreach ($keys as $key) {
            self::text($key);
        }
        if (count(array_unique($keys)) !== count($keys)) {
            throw new StatusException('Public Status component keys must be unique.');
        }
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * @param mixed $value Required string
     * @param bool $allowEmpty Whether empty public text is permitted
     * @return string Uncoerced public string
     * @throws StatusException When the type or empty-value contract is invalid
     */
    public static function text(mixed $value, bool $allowEmpty = false): string
    {
        if (!is_string($value) || (!$allowEmpty && $value === '')) {
            throw new StatusException('Public Status requires a valid string.');
        }
        return $value;
    }

    /**
     * @param mixed $value Required integer
     * @return int Nonnegative integer, without numeric-string or float coercion
     * @throws StatusException When the integer contract is invalid
     */
    public static function integer(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new StatusException('Public Status requires a nonnegative integer.');
        }
        return $value;
    }
}
