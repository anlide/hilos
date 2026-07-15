<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use BackedEnum;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use TypeError;
use ValueError;

/**
 * PageRouteParams - Typed collection over WebSocket `page_subscribe.params`.
 *
 * Wraps the raw `array<string, string>` that arrives on page subscribe and
 * exposes typed `get*` / `require*` accessors. Built once per dispatch in
 * {@see PageSignalRouter::dispatchPageSubscribe} and passed to every
 * `AbstractPage::onSubscribe` and `AbstractPage::onUpdateSubscription`.
 *
 * Semantics:
 * - Missing key or empty string is "absent". `get*` returns null,
 *   `require*` throws {@see MissingPageRouteParamException} (HTTP 400).
 * - Key present with a non-empty value that fails the typed contract is
 *   "invalid". Both `get*` and `require*` throw
 *   {@see InvalidPageRouteParamException} (HTTP 400); `get*` never swallows
 *   malformed input as null.
 * - `getInt` / `requireInt` accept any integer, including `"0"` and negatives.
 *   Use `getPositiveInt` / `requirePositiveInt` for DB ids and other `> 0`
 *   route params.
 */
final class PageRouteParams
{
    /**
     * Creates the collection from the raw subscribe params.
     *
     * @param array<string, string> $raw Raw route params from {@see WebSocketPageSubscribeSignalDTO}
     */
    public function __construct(private readonly array $raw) {}

    /**
     * Returns true when the key is present with a non-empty value.
     *
     * Empty-string values are treated as absent to keep `has` aligned with
     * the `get*` / `require*` missing semantics.
     *
     * @param string $key Route param name
     */
    public function has(string $key): bool
    {
        $value = $this->raw[$key] ?? null;
        return is_string($value) && $value !== '';
    }

    /**
     * Returns the raw string, or null when the key is missing or empty.
     *
     * @param string $key Route param name
     */
    public function getString(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Returns the raw string or throws when the key is missing or empty.
     *
     * @param string $key Route param name
     * @throws MissingPageRouteParamException When the key is absent or empty
     */
    public function requireString(string $key): string
    {
        $value = $this->getString($key)
            ?? throw new MissingPageRouteParamException($key);

        return $value;
    }

    /**
     * Parses the value as a signed integer; null when the key is missing.
     *
     * `"0"` and negatives are valid integers here. For DB ids prefer
     * {@see self::getPositiveInt()} / {@see self::requirePositiveInt()}.
     *
     * @param string $key Route param name
     * @throws InvalidPageRouteParamException When the value is not a valid integer
     */
    public function getInt(string $key): ?int
    {
        $value = $this->getString($key);
        if ($value === null) {
            return null;
        }

        return $this->parseInt($key, $value);
    }

    /**
     * Parses the value as a signed integer; throws on missing or malformed input.
     *
     * @param string $key Route param name
     * @throws MissingPageRouteParamException When the key is absent or empty
     * @throws InvalidPageRouteParamException When the value is not a valid integer
     */
    public function requireInt(string $key): int
    {
        $value = $this->requireString($key);
        return $this->parseInt($key, $value);
    }

    /**
     * Parses the value as a positive integer (`> 0`); null when the key is missing.
     *
     * Suited for DB ids and other route params that cannot be zero or negative.
     *
     * @param string $key Route param name
     * @throws InvalidPageRouteParamException When the value is non-integer or `<= 0`
     */
    public function getPositiveInt(string $key): ?int
    {
        $value = $this->getString($key);
        if ($value === null) {
            return null;
        }

        return $this->parsePositiveInt($key, $value);
    }

    /**
     * Parses the value as a positive integer (`> 0`); throws on missing or invalid input.
     *
     * @param string $key Route param name
     * @throws MissingPageRouteParamException When the key is absent or empty
     * @throws InvalidPageRouteParamException When the value is non-integer or `<= 0`
     */
    public function requirePositiveInt(string $key): int
    {
        $value = $this->requireString($key);
        return $this->parsePositiveInt($key, $value);
    }

    /**
     * Parses the value as a case of the given {@see BackedEnum}; null when missing.
     *
     * @template T of BackedEnum
     * @param string $key Route param name
     * @param class-string<T> $enumClass Backed enum class with a string backing type
     * @return ?T Enum case or null when the key is absent
     * @throws InvalidPageRouteParamException When the value does not match any enum case
     */
    public function getEnum(string $key, string $enumClass): ?BackedEnum
    {
        $value = $this->getString($key);
        if ($value === null) {
            return null;
        }

        return $this->parseEnum($key, $value, $enumClass);
    }

    /**
     * Parses the value as an enum case; throws on missing or unknown case.
     *
     * @template T of BackedEnum
     * @param string $key Route param name
     * @param class-string<T> $enumClass Backed enum class with a string backing type
     * @return T Enum case
     * @throws MissingPageRouteParamException When the key is absent or empty
     * @throws InvalidPageRouteParamException When the value does not match any enum case
     */
    public function requireEnum(string $key, string $enumClass): BackedEnum
    {
        $value = $this->requireString($key);
        return $this->parseEnum($key, $value, $enumClass);
    }

    /**
     * Returns the raw backing array for logging and diagnostics.
     *
     * @return array<string, string> Raw route params as received on subscribe
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @throws InvalidPageRouteParamException
     */
    private function parseInt(string $key, string $value): int
    {
        if (!$this->looksLikeInt($value)) {
            throw new InvalidPageRouteParamException($key, "not an integer: '{$value}'");
        }

        $parsed = (int) $value;
        if (!$this->intRoundTrips($value, $parsed)) {
            throw new InvalidPageRouteParamException($key, "integer out of range: '{$value}'");
        }

        return $parsed;
    }

    /**
     * @throws InvalidPageRouteParamException
     */
    private function parsePositiveInt(string $key, string $value): int
    {
        $parsed = $this->parseInt($key, $value);
        if ($parsed <= 0) {
            throw new InvalidPageRouteParamException($key, "expected positive int, got '{$value}'");
        }

        return $parsed;
    }

    /**
     * @template T of BackedEnum
     * @param class-string<T> $enumClass
     * @return T
     * @throws InvalidPageRouteParamException
     */
    private function parseEnum(string $key, string $value, string $enumClass): BackedEnum
    {
        try {
            return $enumClass::from($value);
        } catch (ValueError | TypeError $e) {
            throw new InvalidPageRouteParamException(
                $key,
                "unknown {$enumClass} case: '{$value}'",
                $e,
            );
        }
    }

    private function looksLikeInt(string $value): bool
    {
        $candidate = $value;
        if ($candidate !== '' && ($candidate[0] === '-' || $candidate[0] === '+')) {
            $candidate = substr($candidate, 1);
        }

        return $candidate !== '' && ctype_digit($candidate);
    }

    /**
     * Confirms a value validated by {@see self::looksLikeInt()} fits in a native int.
     *
     * Guards against silent saturation when a digit string exceeds PHP_INT_MAX:
     * `(int) "99999999999999999999"` clamps to PHP_INT_MAX instead of failing.
     *
     * @param string $value Raw route value already passed through looksLikeInt()
     * @param int $parsed Result of casting $value to int
     * @return bool True when re-stringifying $parsed reproduces $value's canonical form
     */
    private function intRoundTrips(string $value, int $parsed): bool
    {
        $sign = '';
        $digits = $value;
        if ($digits[0] === '-' || $digits[0] === '+') {
            $sign = $digits[0];
            $digits = substr($digits, 1);
        }

        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return $parsed === 0;
        }

        return (string) $parsed === ($sign === '-' ? '-' : '') . $digits;
    }
}
