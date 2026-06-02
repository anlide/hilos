<?php

declare(strict_types=1);

namespace Hilos\Core\Http;

use Hilos\Constants\HttpConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\Exception\MissingRequestQueryParamException;

/**
 * Typed string collection over request query params.
 *
 * Query params are parsed from the URL query string without PHP array syntax
 * expansion, so `token[]=abc` stays a string param named `token[]`.
 */
final class RequestQueryParams
{
    /**
     * @var array<string, string>
     */
    private readonly array $values;

    /**
     * Creates query params from a string map.
     *
     * @param array<string, string> $values Query param values
     * @throws InvalidFormatException When any value is not a string
     */
    public function __construct(array $values = [])
    {
        $this->values = self::normalizeStringMap($values);
    }

    /**
     * @return self Empty query params
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Creates query params from the query portion of a request path (after `?`).
     *
     * @param string $path Request path, optionally followed by a query string
     * @return self Query params parsed from the path, empty when there is no query string
     */
    public static function fromPath(string $path): self
    {
        $queryPos = strpos($path, HttpConstants::QUERY_STRING_SEPARATOR);
        if ($queryPos === false) {
            return new self();
        }

        return self::fromQueryString(substr($path, $queryPos + 1));
    }

    /**
     * Creates query params by parsing a raw URL query string.
     *
     * Splits on `&` and `=` and url-decodes both sides; empty keys are skipped
     * and a repeated key keeps its last value.
     *
     * @param string $queryString Raw query string without the leading `?`
     * @return self Query params parsed from the query string
     */
    public static function fromQueryString(string $queryString): self
    {
        $values = [];
        foreach (explode(HttpConstants::QUERY_PAIR_SEPARATOR, $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode(HttpConstants::QUERY_KEY_VALUE_SEPARATOR, $pair, 2), 2, '');
            $key = urldecode($key);
            if ($key === '') {
                continue;
            }

            $values[$key] = urldecode($value);
        }

        return new self($values);
    }

    /**
     * Creates query params from a deserialized string map, such as a transport payload.
     *
     * @param array<string, string> $values Query param values
     * @return self Query params built from the map
     * @throws InvalidFormatException When any value is not a string
     */
    public static function fromStringMap(array $values): self
    {
        return new self($values);
    }

    /**
     * @param string $key Query param name
     * @return bool True when the param is present
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @param string $key Query param name
     * @return ?string Param value, or null when the param is absent
     */
    public function getString(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Returns a required non-empty query param value.
     *
     * @param string $key Query param name
     * @return string Non-empty param value
     * @throws MissingRequestQueryParamException When the key is absent
     * @throws EmptyValueException When the value is empty
     */
    public function requireString(string $key): string
    {
        if (!$this->has($key)) {
            throw new MissingRequestQueryParamException($key);
        }

        $value = $this->values[$key];
        if ($value === '') {
            throw new EmptyValueException("{$key} cannot be empty");
        }

        return $value;
    }

    /**
     * Returns a required query param value that matches the given regex.
     *
     * @param string $key Query param name
     * @param string $pattern PCRE pattern the value must match
     * @param string $message Error message when the value does not match
     * @return string Param value that matches the pattern
     * @throws MissingRequestQueryParamException When the key is absent
     * @throws EmptyValueException When the value is empty
     * @throws InvalidFormatException When the value does not match the pattern
     */
    public function requireStringMatching(string $key, string $pattern, string $message): string
    {
        $value = $this->requireString($key);
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidFormatException($message);
        }

        return $value;
    }

    /**
     * Returns query params as a string map for transport and diagnostics.
     *
     * @return array<string, string> Query param values keyed by name
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param array<string, string> $values Query param values
     * @return array<string, string> Validated string map with string keys
     * @throws InvalidFormatException When any value is not a string
     */
    private static function normalizeStringMap(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($value)) {
                throw new InvalidFormatException('Request query params must be a string map');
            }

            $normalized[(string)$key] = $value;
        }

        return $normalized;
    }
}
