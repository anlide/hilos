<?php

declare(strict_types=1);

namespace Hilos\Environment;

use ArrayAccess;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvKeyInvalidException;
use Hilos\Environment\Exception\EnvMutationNotSupportedException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;

/**
 * Catalog-backed environment accessor for Hilos::$env[$key] and typed reads.
 *
 * @implements ArrayAccess<EnvConstants|string, string>
 */
class EnvAccessor implements ArrayAccess
{
    /** @var ?array<string, string> Loaded .env file cache */
    private ?array $envCache = null;

    /** @var ?array<string, string> Loaded .env.example cache */
    private ?array $exampleCache = null;

    private ?string $rootPath = null;

    private ?string $envPath = null;

    private ?string $examplePath = null;

    /**
     * Returns the environment catalog for this accessor.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    protected function getCatalog(): array
    {
        return EnvCatalogStub::getCatalog();
    }

    /**
     * Initializes .env and .env.example caches for a project root.
     *
     * @param ?string $rootPath Directory that contains .env and .env.example
     * @param bool $copyExample If true, copy .env.example to .env when .env is missing
     */
    public function init(?string $rootPath = null, bool $copyExample = true): void
    {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
        $this->envPath = $this->rootPath . '/.env';
        $this->examplePath = $this->rootPath . '/.env.example';

        if ($copyExample && !file_exists($this->envPath) && file_exists($this->examplePath)) {
            copy($this->examplePath, $this->envPath);
        }

        $this->envCache = file_exists($this->envPath) ? $this->parseEnvFile($this->envPath) : [];
        $this->exampleCache = null;
    }

    /**
     * Loads an explicit env file as the active .env cache.
     *
     * @param string $envFilePath Path to env file
     * @throws EnvInvalidValueException When the env file is missing
     */
    public function load(string $envFilePath): void
    {
        if (!file_exists($envFilePath)) {
            throw new EnvInvalidValueException("Environment file '{$envFilePath}' does not exist");
        }

        $this->envPath = $envFilePath;
        $this->envCache = $this->parseEnvFile($envFilePath);
    }

    /**
     * Clears and reloads the active env and example caches.
     */
    public function reload(): void
    {
        $envPath = $this->envPath;
        $examplePath = $this->examplePath;

        $this->clearCache();
        $this->envPath = $envPath;
        $this->examplePath = $examplePath;

        if ($this->envPath !== null && file_exists($this->envPath)) {
            $this->envCache = $this->parseEnvFile($this->envPath);
        } else {
            $this->envCache = [];
        }
    }

    /**
     * Clears loaded env and example values.
     */
    public function clearCache(): void
    {
        $this->envCache = null;
        $this->exampleCache = null;
    }

    /**
     * Returns true when the key is declared in the catalog and can resolve to a string value.
     *
     * @param mixed $offset Env key
     */
    public function offsetExists(mixed $offset): bool
    {
        if (!$offset instanceof EnvConstants && !is_string($offset)) {
            return false;
        }

        try {
            $this->string($offset);
        } catch (EnvInvalidValueException|EnvKeyInvalidException|EnvNotInCatalogException|EnvTypeMismatchException|MissingEnvironmentVariableException) {
            return false;
        }

        return true;
    }

    /**
     * Returns a string env value with catalog default handling.
     *
     * @param mixed $offset Env key
     * @return string Effective string value
     * @throws EnvInvalidValueException When the catalog or value is invalid
     * @throws EnvKeyInvalidException When the key is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as string
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function offsetGet(mixed $offset): string
    {
        if (!$offset instanceof EnvConstants && !is_string($offset)) {
            throw new EnvKeyInvalidException('Environment variable key must be a non-empty string or EnvConstants case');
        }

        return $this->string($offset);
    }

    /**
     * Direct env writes are not supported by this read accessor.
     *
     * @param mixed $offset Env key
     * @param mixed $value Value
     * @throws EnvMutationNotSupportedException Always, env writes must update the process environment or .env file
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new EnvMutationNotSupportedException('Use process environment or .env files to write env values');
    }

    /**
     * Direct env deletes are not supported by this read accessor.
     *
     * @param mixed $offset Env key
     * @throws EnvMutationNotSupportedException Always, env deletes must update the process environment or .env file
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new EnvMutationNotSupportedException('Use process environment or .env files to delete env values');
    }

    /**
     * Reads a string env variable.
     *
     * @param EnvConstants|string $name Environment variable name
     * @return string Effective string value
     * @throws EnvInvalidValueException When the catalog or value is invalid
     * @throws EnvKeyInvalidException When the key is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as string
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function string(EnvConstants|string $name): string
    {
        $key = $this->keyName($name);
        $value = $this->effectiveValue($key, EnvCatalogConstants::TYPE_STRING);

        if (!is_scalar($value)) {
            throw new EnvInvalidValueException("Environment variable '{$key}' value cannot be converted to string");
        }

        return (string)$value;
    }

    /**
     * Reads an integer env variable.
     *
     * @param EnvConstants|string $name Environment variable name
     * @return int Effective integer value
     * @throws EnvInvalidValueException When the value is not a valid integer
     * @throws EnvKeyInvalidException When the key is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as integer
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function int(EnvConstants|string $name): int
    {
        $key = $this->keyName($name);
        $value = $this->effectiveValue($key, EnvCatalogConstants::TYPE_INTEGER);

        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int)$value;
        }

        throw new EnvInvalidValueException("Environment variable '{$key}' value is not a valid integer");
    }

    /**
     * Reads a float env variable.
     *
     * @param EnvConstants|string $name Environment variable name
     * @return float Effective float value
     * @throws EnvInvalidValueException When the value is not a valid float
     * @throws EnvKeyInvalidException When the key is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as float
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function float(EnvConstants|string $name): float
    {
        $key = $this->keyName($name);
        $value = $this->effectiveValue($key, EnvCatalogConstants::TYPE_FLOAT);

        if (is_float($value) || is_int($value)) {
            return (float)$value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float)$value;
        }

        throw new EnvInvalidValueException("Environment variable '{$key}' value is not a valid float");
    }

    /**
     * Reads a boolean env variable.
     *
     * @param EnvConstants|string $name Environment variable name
     * @return bool Effective boolean value
     * @throws EnvInvalidValueException When the value is not a valid boolean
     * @throws EnvKeyInvalidException When the key is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as boolean
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function bool(EnvConstants|string $name): bool
    {
        $key = $this->keyName($name);
        $value = $this->effectiveValue($key, EnvCatalogConstants::TYPE_BOOLEAN);

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => throw new EnvInvalidValueException("Environment variable '{$key}' value is not a valid boolean"),
            };
        }

        throw new EnvInvalidValueException("Environment variable '{$key}' value is not a valid boolean");
    }

    /**
     * Returns normalized LLM base URL.
     *
     * @param EnvConstants|string $urlKey Primary URL env key
     * @param EnvConstants|string|null $fallbackKey Fallback URL env key when primary is empty
     * @return string Normalized URL without trailing slash or /api/generate suffix
     */
    public function normalizedLlmUrl(EnvConstants|string $urlKey, EnvConstants|string|null $fallbackKey = null): string
    {
        $url = trim($this->string($urlKey));
        if ($url === '' && $fallbackKey !== null) {
            $url = $this->string($fallbackKey);
        } elseif ($url === '') {
            $url = LLMConstants::DEFAULT_LOCAL_URL;
        }

        $url = rtrim($url, '/');
        if (str_ends_with($url, '/api/generate')) {
            $url = substr($url, 0, -strlen('/api/generate'));
        }

        return $url;
    }

    /**
     * Returns the catalog type for an env key.
     *
     * @param EnvConstants|string $name Environment variable name
     * @return string Catalog type
     * @throws EnvInvalidValueException When catalog metadata is invalid
     * @throws EnvKeyInvalidException When the key is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     */
    public function typeFor(EnvConstants|string $name): string
    {
        $key = $this->keyName($name);

        return $this->entryType($key, $this->entryFor($key));
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvFile(string $filePath): array
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $env = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $env[$key] = trim(trim($value), '"\'');
        }

        return $env;
    }

    /**
     * @throws EnvInvalidValueException When catalog metadata is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the catalog type does not match
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    private function effectiveValue(string $key, string $expectedType): mixed
    {
        $entry = $this->entryFor($key);
        $actualType = $this->entryType($key, $entry);
        if ($actualType !== $expectedType) {
            throw new EnvTypeMismatchException(
                "Environment variable '{$key}' is '{$actualType}', cannot read it as '{$expectedType}'",
            );
        }

        $value = $this->rawValue($key);
        if ($value !== null && $this->entryBool($key, $entry, EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING, false)) {
            $value = trim($value) === '' ? null : $value;
        }
        if ($value !== null) {
            return $value;
        }
        if (array_key_exists(EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE, $entry)) {
            return $entry[EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE];
        }
        if ($this->entryBool($key, $entry, EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING, false)) {
            throw new MissingEnvironmentVariableException($key);
        }

        return '';
    }

    /**
     * @return ?string Raw value from .env, system env, or .env.example
     */
    private function rawValue(string $key): ?string
    {
        if ($this->envCache !== null && array_key_exists($key, $this->envCache)) {
            return $this->envCache[$key];
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $this->exampleValue($key);
    }

    private function exampleValue(string $key): ?string
    {
        if ($this->exampleCache === null) {
            $this->exampleCache = $this->examplePath !== null && file_exists($this->examplePath)
                ? $this->parseEnvFile($this->examplePath)
                : [];
        }

        return $this->exampleCache[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     */
    private function entryFor(string $key): array
    {
        $catalog = $this->getCatalog();
        if (!array_key_exists($key, $catalog)) {
            throw new EnvNotInCatalogException("Environment variable '{$key}' is not in catalog");
        }

        return $catalog[$key];
    }

    /**
     * @param array<string, mixed> $entry Catalog entry
     * @throws EnvInvalidValueException When the catalog type is invalid
     */
    private function entryType(string $key, array $entry): string
    {
        $type = $entry[EnvCatalogConstants::CATALOG_ENTRY_TYPE] ?? null;
        if (!is_string($type) || !in_array($type, [
            EnvCatalogConstants::TYPE_STRING,
            EnvCatalogConstants::TYPE_INTEGER,
            EnvCatalogConstants::TYPE_FLOAT,
            EnvCatalogConstants::TYPE_BOOLEAN,
        ], true)) {
            throw new EnvInvalidValueException("Environment variable '{$key}' catalog type is invalid");
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $entry Catalog entry
     * @throws EnvInvalidValueException When the catalog flag is invalid
     */
    private function entryBool(string $key, array $entry, string $field, bool $default): bool
    {
        if (!array_key_exists($field, $entry)) {
            return $default;
        }
        if (!is_bool($entry[$field])) {
            throw new EnvInvalidValueException("Environment variable '{$key}' catalog flag '{$field}' must be boolean");
        }

        return $entry[$field];
    }

    /**
     * @throws EnvKeyInvalidException When the key is invalid
     */
    private function keyName(EnvConstants|string $name): string
    {
        $key = $name instanceof EnvConstants ? $name->name : $name;
        if ($key === '') {
            throw new EnvKeyInvalidException('Environment variable key must be a non-empty string');
        }

        return $key;
    }
}
