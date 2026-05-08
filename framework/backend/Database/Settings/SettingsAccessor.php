<?php

declare(strict_types=1);

namespace Hilos\Database\Settings;

use ArrayAccess;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\Exception\SettingKeyInvalidException;
use Hilos\Database\Settings\Exception\SettingMutationNotSupportedException;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;

/**
 * Catalog-backed settings accessor for Hilos::$setting[$key]->string()/int()/float()/bool().
 *
 * @implements ArrayAccess<string, SettingValue>
 */
class SettingsAccessor implements ArrayAccess
{
    /**
     * Returns the settings catalog for this accessor.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    protected function getCatalog(): array
    {
        return SettingsCatalogStub::getCatalog();
    }

    /**
     * Checks whether the setting key exists in the catalog.
     *
     * @param mixed $offset Setting key
     * @return bool True when the key exists in the catalog
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && array_key_exists($offset, $this->getCatalog());
    }

    /**
     * Returns a typed reader for a cataloged setting key.
     *
     * @param mixed $offset Setting key
     * @return SettingValue Typed setting value reader
     * @throws SettingException When the key is invalid or missing from the catalog
     */
    public function offsetGet(mixed $offset): SettingValue
    {
        $this->ensureCatalogedKey($offset);

        return new SettingValue($offset);
    }

    /**
     * Returns the catalog type for a setting key.
     *
     * @param string $key Setting key
     * @return string Catalog type
     * @throws SettingException When the key is invalid or missing from the catalog
     */
    public function typeFor(string $key): string
    {
        $this->ensureCatalogedKey($key);

        return $this->getCatalog()[$key][SettingsCatalogConstants::CATALOG_ENTRY_TYPE]
            ?? SettingsCatalogConstants::TYPE_STRING;
    }

    /**
     * Returns the catalog default value for a setting key.
     *
     * @param string $key Setting key
     * @return mixed Catalog default value
     * @throws SettingException When the key is invalid or missing from the catalog
     */
    public function defaultValueFor(string $key): mixed
    {
        $this->ensureCatalogedKey($key);

        return $this->getCatalog()[$key][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] ?? null;
    }

    /**
     * Direct setting writes are not supported by this read accessor.
     *
     * @param mixed $offset Setting key
     * @param mixed $value Value
     * @throws SettingMutationNotSupportedException Always, setting writes must use Hilos::$db->settings actions
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new SettingMutationNotSupportedException('Use Hilos::$db->settings actions to write settings');
    }

    /**
     * Direct setting deletes are not supported by this read accessor.
     *
     * @param mixed $offset Setting key
     * @throws SettingMutationNotSupportedException Always, setting deletes must use Hilos::$db->settings actions
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new SettingMutationNotSupportedException('Use Hilos::$db->settings actions to delete settings');
    }

    /**
     * Verifies a catalog key before reading catalog metadata.
     *
     * @param mixed $key Setting key
     * @throws SettingException When the key is invalid or missing from the catalog
     */
    private function ensureCatalogedKey(mixed $key): void
    {
        if (!is_string($key) || $key === '') {
            throw new SettingKeyInvalidException('Setting key must be a non-empty string');
        }
        if (!array_key_exists($key, $this->getCatalog())) {
            throw new SettingNotInCatalogException("Setting '{$key}' is not in catalog");
        }
    }
}
