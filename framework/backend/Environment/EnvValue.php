<?php

declare(strict_types=1);

namespace Hilos\Environment;

use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvKeyInvalidException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;

/**
 * Typed reader for one catalog-backed environment variable.
 *
 * The reader holds the accessor it was taken from rather than reaching for the global one, because
 * env genuinely lives in more than one instance: a test builds its own {@see EnvAccessor} and swaps
 * the global for the length of a case, so a reader that went to the global would answer from a
 * different accessor than the index it came out of.
 */
final readonly class EnvValue
{
    /**
     * Creates a typed reader for one cataloged env key.
     *
     * @param EnvAccessor $accessor Accessor the key was read from
     * @param string $key Environment variable name
     */
    public function __construct(
        private EnvAccessor $accessor,
        private string $key,
    ) {
    }

    /**
     * Reads a string env value.
     *
     * @return string Effective string value
     * @throws EnvInvalidValueException When the catalog or value is invalid
     * @throws EnvKeyInvalidException Carried from the type lookup; the index that built this reader validated the key
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as string
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function string(): string
    {
        $this->ensureType(EnvCatalogConstants::TYPE_STRING);
        $value = $this->resolveValue();

        if (!is_scalar($value)) {
            throw new EnvInvalidValueException("Environment variable '{$this->key}' value cannot be converted to string");
        }

        return (string)$value;
    }

    /**
     * Reads an integer env value.
     *
     * @return int Effective integer value
     * @throws EnvInvalidValueException When catalog metadata or integer value is invalid
     * @throws EnvKeyInvalidException Carried from the type lookup; the index that built this reader validated the key
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as integer
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function int(): int
    {
        $this->ensureType(EnvCatalogConstants::TYPE_INTEGER);
        $value = $this->resolveValue();

        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int)$value;
        }

        throw new EnvInvalidValueException("Environment variable '{$this->key}' value is not a valid integer");
    }

    /**
     * Reads a float env value.
     *
     * @return float Effective float value
     * @throws EnvInvalidValueException When catalog metadata or float value is invalid
     * @throws EnvKeyInvalidException Carried from the type lookup; the index that built this reader validated the key
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as float
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function float(): float
    {
        $this->ensureType(EnvCatalogConstants::TYPE_FLOAT);
        $value = $this->resolveValue();

        if (is_float($value) || is_int($value)) {
            return (float)$value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float)$value;
        }

        throw new EnvInvalidValueException("Environment variable '{$this->key}' value is not a valid float");
    }

    /**
     * Reads a boolean env value.
     *
     * @return bool Effective boolean value
     * @throws EnvInvalidValueException When catalog metadata or boolean value is invalid
     * @throws EnvKeyInvalidException Carried from the type lookup; the index that built this reader validated the key
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as boolean
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function bool(): bool
    {
        $this->ensureType(EnvCatalogConstants::TYPE_BOOLEAN);
        $value = $this->resolveValue();

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
                default => throw new EnvInvalidValueException("Environment variable '{$this->key}' value is not a valid boolean"),
            };
        }

        throw new EnvInvalidValueException("Environment variable '{$this->key}' value is not a valid boolean");
    }

    /**
     * Verifies that the requested reader matches the catalog type.
     *
     * @param string $expectedType Expected catalog type
     * @throws EnvInvalidValueException When catalog metadata is invalid
     * @throws EnvKeyInvalidException Carried from the type lookup; the index that built this reader validated the key
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the requested reader does not match the catalog type
     */
    private function ensureType(string $expectedType): void
    {
        $actualType = $this->accessor->typeFor($this->key);
        if ($actualType !== $expectedType) {
            throw new EnvTypeMismatchException(
                "Environment variable '{$this->key}' is '{$actualType}', cannot read it as '{$expectedType}'",
            );
        }
    }

    /**
     * Resolves the effective env value, catalog default and missing-value rules included.
     *
     * @return mixed Effective env value
     * @throws EnvInvalidValueException When catalog metadata is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    private function resolveValue(): mixed
    {
        return $this->accessor->effectiveValueFor($this->key);
    }
}
