<?php

declare(strict_types=1);

namespace Hilos;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalDataEnvelope;

/**
 * Abstract base class for serializable DTOs.
 *
 * Concrete DTOs own their array shape and inherit shared JSON conversion.
 *
 * They also inherit the two ways of reading a field out of a payload, and a
 * field has no third role: it is either required — absent or of the wrong type
 * means the payload is broken and {@see self::requireString()} and its siblings
 * refuse it — or legitimately absent, which is a nullable property read with
 * {@see self::optionalString()} and its siblings. A default minted in place of
 * a missing field is neither: it hands every reader below a value that never
 * arrived. See docs/agents/signals/dto-convention.md.
 */
abstract class BaseDTO
{
    /**
     * Serializes the DTO to its array payload.
     *
     * @return array<string, mixed> DTO payload
     */
    abstract public function toArray(): array;

    /**
     * Restores a DTO instance from its array payload.
     *
     * @param array<string, mixed> $data DTO payload
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload is missing a field the DTO cannot be built without
     * @throws HilosException When the DTO refuses to be restored from this payload at all
     */
    abstract public static function fromArray(array $data): static;

    /**
     * Serializes the DTO payload to JSON.
     *
     * @return string JSON representation of the DTO payload
     */
    public function toJson(): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($this->toArray(), $flags);
        if ($json === false) {
            return '{"error":"json_encode_failed"}';
        }

        return $json;
    }

    /**
     * Restores a DTO instance from a JSON payload.
     *
     * @param string $json JSON-encoded DTO payload
     * @return static Restored DTO instance
     * @throws HilosException When JSON cannot be decoded into DTO data
     * @throws InvalidFormatException When the decoded payload is missing a field the DTO cannot be built without
     */
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true)
            ?? throw new HilosException('Invalid JSON provided');
        return static::fromArray($data);
    }

    /**
     * Reads a payload field the DTO cannot be built without.
     *
     * A key that is absent, or holds anything other than a string, means the
     * sender did not fill a field this DTO is defined by; there is no value to
     * work with and the payload is refused. An empty string is not that case
     * and passes through: a field left blank is real input, and whoever handles
     * the DTO answers it with its own validation message.
     *
     * The type is checked and never coerced. A cast would turn `null`, `false`
     * and `[]` into strings that look like data, which is the very substitution
     * the refusal exists to prevent.
     *
     * Refusing is safe on every seam that builds a DTO: an action payload is
     * built inside the try {@see PageSignalRouter::dispatchAction()} turns into
     * the client's fail-ack, and a signal payload inside the one
     * {@see SignalDataEnvelope::decode()} turns into a warning and the untyped
     * fallback.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-string
     */
    protected static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            // The message rides the client's fail-ack through
            // PageSignalRouter::clientReason(), so it names the payload key and
            // nothing about the class that reads it.
            throw new InvalidFormatException('Payload carries no string under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads an integer payload field the DTO cannot be built without.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return int Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-integer
     */
    protected static function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidFormatException('Payload carries no integer under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads an array payload field the DTO cannot be built without.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return array<string, mixed> Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-array
     */
    protected static function requireArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidFormatException('Payload carries no array under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads a boolean payload field the DTO cannot be built without.
     *
     * `false` is a value the sender chose, not the absence of one, so it passes
     * through untouched. That is the whole reason a boolean needs a reader of
     * its own: `?? false` reads an absent flag and a flag deliberately lowered
     * as the same thing, and every reader below then acts on a decision nobody
     * made.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return bool Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-boolean
     */
    protected static function requireBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidFormatException('Payload carries no boolean under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads a floating-point payload field the DTO cannot be built without.
     *
     * An integer is widened instead of refused, because a float is not carried
     * as one across JSON: `json_encode(0.0)` writes `0`, and a strict
     * `is_float()` would refuse a DTO its own serialization produced. A string
     * holding digits stays refused — that is a sender writing a number as text,
     * not the format losing the fraction.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return float Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds neither a float nor an integer
     */
    protected static function requireFloat(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (!is_float($value) && !is_int($value)) {
            throw new InvalidFormatException('Payload carries no number under key ' . $key);
        }

        return (float)$value;
    }

    /**
     * Reads a payload field the DTO cannot be built without and that carries either type.
     *
     * The two types are one field, not two: a row key is whatever the table's
     * own key column holds, and narrowing it here would refuse half the tables
     * over a difference the DTO does not care about. A boolean is refused
     * despite PHP counting it as neither — it is a flag, not a key.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return int|string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds neither an integer nor a string
     */
    protected static function requireIntOrString(array $data, string $key): int|string
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidFormatException('Payload carries no integer or string under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads a payload field that is allowed to be absent.
     *
     * Absence answers `null` — that is what the field being optional means. A
     * key that is present but holds the wrong type is not absence: the sender
     * meant to fill the field and filled it with something this DTO cannot
     * read, so the payload is refused exactly as a missing required field is.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?string Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-string
     */
    protected static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidFormatException('Payload carries a non-string under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads an integer payload field that is allowed to be absent.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?int Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-integer
     */
    protected static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidFormatException('Payload carries a non-integer under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads an array payload field that is allowed to be absent.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?array<string, mixed> Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-array
     */
    protected static function optionalArray(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_array($value)) {
            throw new InvalidFormatException('Payload carries a non-array under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads a boolean payload field that is allowed to be absent.
     *
     * Absence answers `null`, which is what distinguishes it from a flag the
     * sender lowered on purpose; `false` arrives as `false`.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?bool Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-boolean
     */
    protected static function optionalBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_bool($value)) {
            throw new InvalidFormatException('Payload carries a non-boolean under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads a floating-point payload field that is allowed to be absent.
     *
     * An integer is widened for the same reason it is in
     * {@see self::requireFloat()}: JSON does not keep a whole float a float.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?float Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds neither a float nor an integer
     */
    protected static function optionalFloat(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_float($value) && !is_int($value)) {
            throw new InvalidFormatException('Payload carries a non-number under key ' . $key);
        }

        return $value !== null ? (float)$value : null;
    }

    /**
     * Reads a payload field that carries either type and is allowed to be absent.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return int|string|null Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds neither an integer nor a string
     */
    protected static function optionalIntOrString(array $data, string $key): int|string|null
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_int($value) && !is_string($value)) {
            throw new InvalidFormatException('Payload carries neither an integer nor a string under key ' . $key);
        }

        return $value;
    }
}
