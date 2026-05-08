<?php

declare(strict_types=1);

namespace Hilos\Database\Settings;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Hilos;

/**
 * Typed reader for one catalog-backed setting.
 */
final class SettingValue
{
    /**
     * Creates a typed setting value reader.
     *
     * @param string $key Setting key
     */
    public function __construct(
        private readonly string $key,
    ) {
    }

    /**
     * Reads a string setting value.
     *
     * @return string Setting value resolved from DB or catalog default
     * @throws SettingException When catalog metadata is unavailable, the type is not string, or the resolved value is invalid
     */
    public function string(): string
    {
        $this->ensureType(SettingsCatalogConstants::TYPE_STRING);
        $value = $this->resolveValue();

        if ($value === null) {
            return '';
        }
        if (!is_scalar($value)) {
            throw new SettingInvalidValueException("Setting '{$this->key}' value cannot be converted to string");
        }

        return (string)$value;
    }

    /**
     * Reads an integer setting value.
     *
     * @return int Setting value resolved from DB or catalog default
     * @throws SettingException When catalog metadata is unavailable, the type is not integer, or the resolved value is invalid
     */
    public function int(): int
    {
        $this->ensureType(SettingsCatalogConstants::TYPE_INTEGER);
        $value = $this->resolveValue();

        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int)$value;
        }

        throw new SettingInvalidValueException("Setting '{$this->key}' value is not a valid integer");
    }

    /**
     * Reads a float setting value.
     *
     * @return float Setting value resolved from DB or catalog default
     * @throws SettingException When catalog metadata is unavailable, the type is not float, or the resolved value is invalid
     */
    public function float(): float
    {
        $this->ensureType(SettingsCatalogConstants::TYPE_FLOAT);
        $value = $this->resolveValue();

        if (is_float($value) || is_int($value)) {
            return (float)$value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float)$value;
        }

        throw new SettingInvalidValueException("Setting '{$this->key}' value is not a valid float");
    }

    /**
     * Reads a boolean setting value.
     *
     * @return bool Setting value resolved from DB or catalog default
     * @throws SettingException When catalog metadata is unavailable, the type is not boolean, or the resolved value is invalid
     */
    public function bool(): bool
    {
        $this->ensureType(SettingsCatalogConstants::TYPE_BOOLEAN);
        $value = $this->resolveValue();

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => throw new SettingInvalidValueException("Setting '{$this->key}' value is not a valid boolean"),
            };
        }

        throw new SettingInvalidValueException("Setting '{$this->key}' value is not a valid boolean");
    }

    /**
     * Verifies that the requested reader matches the catalog type.
     *
     * @param string $expectedType Expected catalog type
     * @throws SettingTypeMismatchException When the requested reader does not match the catalog type
     * @throws SettingAccessorUnavailableException When Hilos::$setting is not initialized
     * @throws SettingException When catalog metadata lookup rejects the key
     */
    private function ensureType(string $expectedType): void
    {
        $actualType = $this->settings()->typeFor($this->key);
        if ($actualType !== $expectedType) {
            throw new SettingTypeMismatchException(
                "Setting '{$this->key}' is '{$actualType}', cannot read it as '{$expectedType}'",
            );
        }
    }

    /**
     * Resolves persisted setting value with catalog default fallback.
     *
     * @return mixed Persisted value or catalog default
     * @throws SettingAccessorUnavailableException When Hilos::$setting is not initialized
     * @throws SettingException When the catalog default cannot be resolved
     */
    private function resolveValue(): mixed
    {
        if (!Hilos::$db instanceof HilosDbContext) {
            return $this->settings()->defaultValueFor($this->key);
        }

        $value = Hilos::$db->settings[$this->key]?->value;
        if ($value === null || $value === '') {
            return $this->settings()->defaultValueFor($this->key);
        }

        return $value;
    }

    /**
     * Returns the active global settings accessor.
     *
     * @return SettingsAccessor Settings accessor
     * @throws SettingAccessorUnavailableException When Hilos::$setting is not initialized
     */
    private function settings(): SettingsAccessor
    {
        return Hilos::$setting
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');
    }
}
