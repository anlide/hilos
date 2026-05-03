<?php

declare(strict_types=1);

namespace Hilos\Core\Http;

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

    public static function empty(): self
    {
        return new self();
    }

    public static function fromPath(string $path): self
    {
        $queryPos = strpos($path, '?');
        if ($queryPos === false) {
            return new self();
        }

        return self::fromQueryString(substr($path, $queryPos + 1));
    }

    public static function fromQueryString(string $queryString): self
    {
        $values = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if ($key === '') {
                continue;
            }

            $values[$key] = urldecode($value);
        }

        return new self($values);
    }

    /**
     * Creates query params from a serialized string map.
     *
     * @param array<string, string> $values Query param values
     * @throws InvalidFormatException When any value is not a string
     */
    public static function fromStringMap(array $values): self
    {
        return new self($values);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function getString(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Returns a required non-empty query param value.
     *
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
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param array<string, string> $values Query param values
     * @return array<string, string>
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
