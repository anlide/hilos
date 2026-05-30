<?php

declare(strict_types=1);

namespace Hilos\Database\Settings;

use ArrayAccess;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingDefaultReferenceCycleException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingKeyInvalidException;
use Hilos\Database\Settings\Exception\SettingMutationNotSupportedException;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Hilos;

/**
 * Catalog-backed settings accessor for Hilos::$setting[$key]->string()/int()/float()/bool().
 *
 * @implements ArrayAccess<string, SettingValue>
 */
class SettingsAccessor implements ArrayAccess
{
    /**
     * @var class-string<CatalogProviderInterface> Catalog provider class
     */
    private string $catalogClass = SettingsCatalogStub::class;

    /**
     * Creates a settings accessor backed by the given catalog provider.
     *
     * @param class-string<CatalogProviderInterface> $catalogClass Catalog provider class
     */
    public function __construct(string $catalogClass = SettingsCatalogStub::class)
    {
        $this->catalogClass = $catalogClass;
    }

    /**
     * Returns the settings catalog for this accessor.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    protected function getCatalog(): array
    {
        return $this->catalogClass::getCatalog();
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
     * Returns the resolved catalog default value for a setting key.
     *
     * A scalar default is returned as-is. An array default must reference
     * another catalog setting through the CATALOG_DEFAULT_SETTING_KEY field.
     *
     * @param string $key Setting key
     * @return mixed Resolved catalog default value
     * @throws DatabaseException When a referenced setting persisted lookup fails
     * @throws SettingException When the key or default reference is invalid
     */
    public function defaultValueFor(string $key): mixed
    {
        return $this->resolveDefaultValueFor($key, []);
    }

    /**
     * Returns the effective setting value from DB or resolved catalog default.
     *
     * @param string $key Setting key
     * @return mixed Persisted value when not null, otherwise resolved catalog default
     * @throws DatabaseException When persisted setting lookup fails
     * @throws SettingException When the key or default reference is invalid
     */
    public function effectiveValueFor(string $key): mixed
    {
        return $this->resolveEffectiveValueFor($key, []);
    }

    /**
     * Returns the referenced setting key for an array default.
     *
     * @param string $key Setting key
     * @return ?string Referenced setting key, or null for scalar defaults
     * @throws SettingException When the key or default reference is invalid
     */
    public function defaultReferenceKeyFor(string $key): ?string
    {
        $this->ensureCatalogedKey($key);

        $default = $this->defaultDeclarationFor($key);
        if (!is_array($default)) {
            return null;
        }

        $referenceKey = $this->referenceKeyFromDefault($key, $default);
        $this->ensureCompatibleDefaultReference($key, $referenceKey);

        return $referenceKey;
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

    /**
     * Resolves the catalog default value and follows references when needed.
     *
     * @param string $key Setting key
     * @param list<string> $path Keys already visited while resolving this chain
     * @return mixed Resolved default value
     * @throws DatabaseException When a referenced setting persisted lookup fails
     * @throws SettingException When the default reference is invalid or cyclic
     */
    private function resolveDefaultValueFor(string $key, array $path): mixed
    {
        $path = $this->appendResolutionKey($key, $path);

        return $this->resolveDefaultValueForPath($key, $path);
    }

    /**
     * Resolves the effective setting value and follows default references.
     *
     * @param string $key Setting key
     * @param list<string> $path Keys already visited while resolving this chain
     * @return mixed Persisted value when not null, otherwise resolved default
     * @throws DatabaseException When persisted setting lookup fails
     * @throws SettingException When the default reference is invalid or cyclic
     */
    private function resolveEffectiveValueFor(string $key, array $path): mixed
    {
        $path = $this->appendResolutionKey($key, $path);

        if (Hilos::$db instanceof HilosDbContext) {
            /** @noinspection PhpPossiblePolymorphicInvocationInspection Framework-level magic settings property on abstract HilosDbContext; runtime instance is always concrete */
            $persistedValue = Hilos::$db->settings[$key]?->value;
            if ($persistedValue !== null) {
                return $persistedValue;
            }
        }

        return $this->resolveDefaultValueForPath($key, $path);
    }

    /**
     * Resolves a default for a key that is already present in the resolution path.
     *
     * @param string $key Setting key
     * @param list<string> $path Keys already visited while resolving this chain
     * @return mixed Resolved default value
     * @throws DatabaseException When a referenced setting persisted lookup fails
     * @throws SettingException When the default reference is invalid or cyclic
     */
    private function resolveDefaultValueForPath(string $key, array $path): mixed
    {
        $default = $this->defaultDeclarationFor($key);
        if (!is_array($default)) {
            return $default;
        }

        $referenceKey = $this->referenceKeyFromDefault($key, $default);
        $this->ensureCompatibleDefaultReference($key, $referenceKey);

        return $this->resolveEffectiveValueFor($referenceKey, $path);
    }

    /**
     * Returns a raw catalog default declaration.
     *
     * @param string $key Setting key
     * @return array<string, mixed>|bool|float|int|string Scalar default or reference declaration
     * @throws SettingInvalidValueException When the default declaration is missing or has unsupported type
     */
    private function defaultDeclarationFor(string $key): array|bool|float|int|string
    {
        $catalog = $this->getCatalog();
        if (!array_key_exists(SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE, $catalog[$key])) {
            throw new SettingInvalidValueException(
                "Setting '{$key}' catalog entry must define '"
                . SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE
                . "'",
            );
        }

        $default = $catalog[$key][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE];
        if (!is_scalar($default) && !is_array($default)) {
            throw new SettingInvalidValueException(
                "Setting '{$key}' catalog default must be scalar or a default reference array, "
                . get_debug_type($default)
                . ' given',
            );
        }

        return $default;
    }

    /**
     * Appends a key to the resolution path and rejects cycles.
     *
     * @param string $key Setting key
     * @param list<string> $path Keys already visited while resolving this chain
     * @return list<string> Resolution path including the current key
     * @throws SettingException When the key is invalid, missing, or cyclic
     */
    private function appendResolutionKey(string $key, array $path): array
    {
        $this->ensureCatalogedKey($key);

        if (in_array($key, $path, true)) {
            throw new SettingDefaultReferenceCycleException(
                'Setting default reference cycle detected: ' . implode(' -> ', [...$path, $key]),
            );
        }

        return [...$path, $key];
    }

    /**
     * Extracts a referenced setting key from an array default declaration.
     *
     * @param string $key Setting key that owns the default declaration
     * @param array<string, mixed> $default Catalog default declaration
     * @return string Referenced setting key
     * @throws SettingInvalidValueException When the default declaration is malformed
     */
    private function referenceKeyFromDefault(string $key, array $default): string
    {
        $referenceKey = $default[SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY] ?? null;
        if (!is_string($referenceKey) || $referenceKey === '') {
            throw new SettingInvalidValueException(
                "Setting '{$key}' default reference must contain a non-empty '"
                . SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY
                . "' string",
            );
        }

        return $referenceKey;
    }

    /**
     * Verifies that a reference default points to a setting with the same type.
     *
     * @param string $key Setting key that owns the reference
     * @param string $referenceKey Referenced setting key
     * @throws SettingException When the referenced key is missing or has another type
     */
    private function ensureCompatibleDefaultReference(string $key, string $referenceKey): void
    {
        $sourceType = $this->typeFor($key);
        $referenceType = $this->typeFor($referenceKey);

        if ($sourceType !== $referenceType) {
            throw new SettingTypeMismatchException(
                "Setting '{$key}' default references '{$referenceKey}' with type '{$referenceType}', expected '{$sourceType}'",
            );
        }
    }
}
