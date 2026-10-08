<?php

declare(strict_types=1);

namespace Hilos\Environment;

use ArrayAccess;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvKeyInvalidException;
use Hilos\Environment\Exception\EnvMutationNotSupportedException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/**
 * Catalog-backed environment accessor: Hilos::$env[$key] hands back the typed reader that
 * answers for the value, as Hilos::$env[$key]->string().
 *
 * @implements ArrayAccess<EnvConstants|string, EnvValue>
 */
class EnvAccessor implements ArrayAccess
{
    /**
     * @var class-string<CatalogProviderInterface> Catalog provider class
     */
    private string $catalogClass = EnvCatalogStub::class;

    /** @var ?array<string, array<string, mixed>> Catalog as the provider named it, resolved once per accessor */
    private ?array $catalogCache = null;

    /** @var ?array<string, true> Framework catalog keys, built once when provenance is first asked */
    private ?array $frameworkCatalogKeys = null;

    /** @var ?array<string, string> Loaded .env file cache */
    private ?array $envCache = null;

    /** @var ?array<string, string> Loaded .env.example cache */
    private ?array $exampleCache = null;

    private ?string $rootPath = null;

    private ?string $envPath = null;

    private ?string $examplePath = null;

    /**
     * Creates an environment accessor backed by the given catalog provider.
     *
     * @param class-string<CatalogProviderInterface> $catalogClass Catalog provider class
     */
    public function __construct(string $catalogClass = EnvCatalogStub::class)
    {
        $this->catalogClass = $catalogClass;
    }

    /**
     * Returns the environment catalog for this accessor, asking the provider once.
     *
     * The catalog is a declaration, not a value: the provider builds the same array from the same
     * literals every call, so keeping the first answer changes nothing an owner can observe. It is
     * remembered because one index read consults it three times — the key check, the type check and
     * the value — and the biggest catalog in the tree is the env one. Rebuilding it per lookup makes
     * a read cost 0.225 ms where 0.203 ms of that is array construction, and every settings catalog
     * whose defaults come from env pays that price per entry, per build.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     * @throws EnvInvalidValueException When any catalog declaration is invalid
     */
    protected function getCatalog(): array
    {
        if ($this->catalogCache === null) {
            $catalog = $this->catalogClass::getCatalog();
            foreach ($catalog as $key => $entry) {
                if (!is_array($entry)) {
                    throw new EnvInvalidValueException("Environment variable '{$key}' catalog entry is invalid");
                }
                foreach (array_keys($entry) as $field) {
                    if (!in_array($field, [
                        EnvCatalogConstants::CATALOG_ENTRY_TYPE,
                        EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE,
                        EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING,
                        EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING,
                        EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE,
                        EnvCatalogConstants::CATALOG_ENTRY_PER_NODE,
                        EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE,
                    ], true)) {
                        throw new EnvInvalidValueException("Environment variable '{$key}' catalog field '{$field}' is unknown");
                    }
                }
                $this->entryType($key, $entry);
                foreach ([
                    EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING,
                    EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING,
                    EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE,
                    EnvCatalogConstants::CATALOG_ENTRY_PER_NODE,
                    EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE,
                ] as $field) {
                    $this->entryBool($key, $entry, $field, false);
                }
                if (($entry[EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE] ?? false)
                    && ($entry[EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE] ?? false)) {
                    throw new EnvInvalidValueException(
                        "Environment variable '{$key}' catalog fields 'sensitive' and 'admin_view_visible' conflict",
                    );
                }
            }
            $this->catalogCache = $catalog;
        }

        return $this->catalogCache;
    }

    /**
     * Initializes .env and .env.example caches for a project root.
     *
     * A missing .env is read as an empty cache, never written: the only way to get that
     * file is the explicit `composer run setup-env` step.
     *
     * @param ?string $rootPath Directory that contains .env and .env.example
     */
    public function init(?string $rootPath = null): void
    {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
        $this->envPath = $this->rootPath . '/.env';
        $this->examplePath = $this->rootPath . '/.env.example';

        $this->envCache = file_exists($this->envPath) ? $this->parseEnvFile($this->envPath) : [];
        $this->exampleCache = null;
    }

    /**
     * Loads an explicit env file as the active .env cache.
     *
     * The file lands on the same rank as .env, which is below the process environment:
     * it names what the launching stack left unsaid, it does not overrule it.
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
     * Returns true when the key is declared in the catalog and resolves to a value of its own type.
     *
     * @param mixed $offset Env key
     * @return bool Whether the key resolves to a value
     */
    public function offsetExists(mixed $offset): bool
    {
        if (!$offset instanceof EnvConstants && !is_string($offset)) {
            return false;
        }

        try {
            $this->effectiveValueFor($this->keyName($offset));
        } catch (EnvException) {
            return false;
        }

        return true;
    }

    /**
     * Returns the typed reader for a cataloged env key.
     *
     * @param mixed $offset Env key
     * @return EnvValue Typed env value reader
     * @throws EnvException When the key is invalid or missing from the catalog
     */
    public function offsetGet(mixed $offset): EnvValue
    {
        if (!$offset instanceof EnvConstants && !is_string($offset)) {
            throw new EnvKeyInvalidException('Environment variable key must be a non-empty string or EnvConstants case');
        }

        $key = $this->keyName($offset);
        $this->entryFor($key);

        return new EnvValue($this, $key);
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
     * Returns normalized LLM base URL.
     *
     * @param EnvConstants|string $urlKey Primary URL env key
     * @param EnvConstants|string|null $fallbackKey Fallback URL env key when primary is empty
     * @return string Normalized URL without trailing slash or /api/generate suffix
     * @throws EnvException When a URL key is invalid, uncataloged, or its value is not a string
     */
    public function normalizedLlmUrl(EnvConstants|string $urlKey, EnvConstants|string|null $fallbackKey = null): string
    {
        $url = trim($this[$urlKey]->string());
        if ($url === '' && $fallbackKey !== null) {
            $url = $this[$fallbackKey]->string();
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
     * @return list<string> Declared environment keys in catalog order
     * @throws EnvInvalidValueException When catalog metadata is invalid
     */
    public function catalogKeys(): array
    {
        return array_keys($this->getCatalog());
    }

    /**
     * @param EnvConstants|string $name Environment key
     * @return bool Whether the key must have a value
     * @throws EnvException When the key or its catalog metadata is invalid
     */
    public function requiredFor(EnvConstants|string $name): bool
    {
        $key = $this->keyName($name);

        return $this->entryBool($key, $this->entryFor($key), EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING, false);
    }

    /**
     * @param EnvConstants|string $name Environment key
     * @return bool Whether the framework's own catalog declares it
     * @throws EnvException When the key is not declared by this accessor or is invalid
     */
    public function declaredByFramework(EnvConstants|string $name): bool
    {
        $key = $this->keyName($name);
        $this->entryFor($key);

        $this->frameworkCatalogKeys ??= array_fill_keys(array_keys(EnvCatalogStub::getCatalog()), true);

        return isset($this->frameworkCatalogKeys[$key]);
    }

    /**
     * @return list<string> Distinct existing directories holding the active dotenv files
     */
    public function watchedFileDirectories(): array
    {
        $directories = [];
        foreach ([$this->envPath, $this->examplePath] as $path) {
            if ($path !== null && file_exists($path)) {
                $directory = dirname($path);
                if (!in_array($directory, $directories, true)) {
                    $directories[] = $directory;
                }
            }
        }

        return $directories;
    }

    /**
     * @param EnvConstants|string $name Environment key
     * @return EnvResolution Source and value held by this process
     * @throws EnvException When the key or catalog metadata is invalid
     */
    public function resolutionFor(EnvConstants|string $name): EnvResolution
    {
        return $this->resolve($this->keyName($name), $this->envCache ?? [], $this->exampleCache);
    }

    /**
     * @return EnvFiles Fresh dotenv entries without changing either process cache
     */
    public function readFilesFromDisk(): EnvFiles
    {
        return new EnvFiles(
            $this->envPath !== null && file_exists($this->envPath) ? $this->parseEnvFile($this->envPath) : [],
            $this->examplePath !== null && file_exists($this->examplePath) ? $this->parseEnvFile($this->examplePath) : [],
        );
    }

    /**
     * @param EnvConstants|string $name Environment key
     * @param EnvFiles $files Fresh files to use beneath the process environment
     * @return EnvResolution Source and value a restart with these files would choose
     * @throws EnvException When the key or catalog metadata is invalid
     */
    public function resolutionOnDisk(EnvConstants|string $name, EnvFiles $files): EnvResolution
    {
        return $this->resolve($this->keyName($name), $files->env, $files->example);
    }

    /**
     * Returns whether an env value must remain on its node. Unknown keys are closed.
     *
     * @param EnvConstants|string $name Environment variable name
     * @return bool Whether the value is sensitive
     * @throws EnvInvalidValueException When catalog metadata is invalid
     * @throws EnvKeyInvalidException When the key is invalid
     */
    public function sensitiveFor(EnvConstants|string $name): bool
    {
        $key = $this->keyName($name);
        $catalog = $this->getCatalog();

        return !isset($catalog[$key])
            || ($catalog[$key][EnvCatalogConstants::CATALOG_ENTRY_SENSITIVE] ?? false);
    }

    /**
     * Returns whether a key is expected to differ between nodes.
     *
     * @param EnvConstants|string $name Environment variable name
     * @return bool Whether the key belongs to the node
     * @throws EnvInvalidValueException When catalog metadata is invalid
     * @throws EnvKeyInvalidException When the key is invalid
     */
    public function perNodeFor(EnvConstants|string $name): bool
    {
        $key = $this->keyName($name);
        $catalog = $this->getCatalog();

        return $catalog[$key][EnvCatalogConstants::CATALOG_ENTRY_PER_NODE] ?? false;
    }

    /**
     * Returns whether an admin view-mode viewer may see an env value.
     *
     * @param EnvConstants|string $name Environment variable name
     * @return bool Whether the value is visible to a viewer
     * @throws EnvInvalidValueException When catalog metadata is invalid
     * @throws EnvKeyInvalidException When the key is invalid
     */
    public function visibleInAdminViewMode(EnvConstants|string $name): bool
    {
        $key = $this->keyName($name);
        $catalog = $this->getCatalog();

        return $catalog[$key][EnvCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE] ?? false;
    }

    /**
     * Names every required catalog key that has no value, in catalog order.
     *
     * Asks {@see effectiveValueFor()} the same question a runtime read asks, key by key, so
     * the check and the reads it precedes can never disagree about what "missing" means:
     * process environment, then .env, then .env.example, with the entry's emptyIsMissing
     * flag deciding whether an empty string counts as an answer.
     *
     * @return list<string> Missing required environment variable names, in catalog order
     * @throws EnvInvalidValueException When a catalog entry carries an invalid type or flag
     * @throws EnvNotInCatalogException Carried from the shared read; the keys come from the catalog
     */
    public function missingRequired(): array
    {
        $missing = [];
        foreach ($this->getCatalog() as $key => $entry) {
            if (!$this->entryBool($key, $entry, EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING, false)) {
                continue;
            }

            try {
                $this->effectiveValueFor($key);
            } catch (MissingEnvironmentVariableException) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Resolves an env value using loaded values, defaults, and missing-value rules.
     *
     * The seam {@see EnvValue} reads through: it answers for the value alone, in the key's own
     * catalog type, and leaves the question of which type was asked for to the reader.
     *
     * @param string $key Environment variable name
     * @return mixed Effective env value
     * @throws EnvInvalidValueException When catalog metadata is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function effectiveValueFor(string $key): mixed
    {
        $resolution = $this->resolve($key, $this->envCache ?? [], $this->exampleCache);
        if ($resolution->source !== EnvSource::MISSING) {
            return $resolution->value;
        }
        if ($this->entryBool($key, $this->entryFor($key), EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING, false)) {
            throw new MissingEnvironmentVariableException($key);
        }

        return '';
    }

    /**
     * Resolves one key by the same ladder for process caches and fresh files. An
     * empty answer marked missing falls through to the catalog default, not to
     * the next file, matching the existing effective-value contract.
     *
     * @param string $key Catalog key
     * @param array<string, string> $envMap Active dotenv values
     * @param ?array<string, string> $exampleMap Example values, null to load the process cache lazily
     * @return EnvResolution Chosen source and value
     * @throws EnvInvalidValueException When catalog metadata is invalid
     * @throws EnvNotInCatalogException When the key is not declared
     */
    private function resolve(string $key, array $envMap, ?array $exampleMap): EnvResolution
    {
        $entry = $this->entryFor($key);
        $this->entryType($key, $entry);

        $value = getenv($key);
        if ($value !== false) {
            $source = EnvSource::PROCESS;
        } elseif (array_key_exists($key, $envMap)) {
            $value = $envMap[$key];
            $source = EnvSource::ENV_FILE;
        } else {
            if ($exampleMap === null) {
                $this->exampleCache = $this->examplePath !== null && file_exists($this->examplePath)
                    ? $this->parseEnvFile($this->examplePath)
                    : [];
                $exampleMap = $this->exampleCache;
            }
            $value = $exampleMap[$key] ?? null;
            $source = EnvSource::EXAMPLE;
        }

        if ($value !== null && $this->entryBool($key, $entry, EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING, false)
            && trim($value) === '') {
            $value = null;
        }
        if ($value !== null) {
            return new EnvResolution($source, $value);
        }
        if (array_key_exists(EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE, $entry)) {
            $default = $entry[EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE];
            if (!is_scalar($default)) {
                throw new EnvInvalidValueException("Environment variable '{$key}' catalog default must be scalar");
            }

            return new EnvResolution(EnvSource::CATALOG_DEFAULT, $default);
        }

        return new EnvResolution(EnvSource::MISSING, null);
    }

    /**
     * Parses a dotenv-style key/value file.
     *
     * @param string $filePath Env file path
     * @return array<string, string> Parsed env values
     */
    private function parseEnvFile(string $filePath): array
    {
        $env = [];
        try {
            foreach (FsPath::readLines($filePath) as $line) {
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
        } catch (FsException) {
            return [];
        }

        return $env;
    }

    /**
     * @param string $key Environment variable name
     * @return array<string, mixed> Catalog entry
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
     * @param string $key Environment variable name
     * @param array<string, mixed> $entry Catalog entry
     * @return string Catalog type
     * @throws EnvInvalidValueException When the catalog type is invalid
     */
    private function entryType(string $key, array $entry): string
    {
        $type = $entry[EnvCatalogConstants::CATALOG_ENTRY_TYPE] ?? null;
        if (!in_array($type, [
            EnvCatalogConstants::TYPE_STRING,
            EnvCatalogConstants::TYPE_INTEGER,
            EnvCatalogConstants::TYPE_FLOAT,
            EnvCatalogConstants::TYPE_BOOLEAN,
        ], true)) {
            throw new EnvInvalidValueException("Environment variable '{$key}' catalog field 'type' is invalid");
        }

        return $type;
    }

    /**
     * @param string $key Environment variable name
     * @param array<string, mixed> $entry Catalog entry
     * @param string $field Catalog boolean flag name
     * @param bool $default Default flag value
     * @return bool Catalog flag value
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
     * @param EnvConstants|string $name Environment variable name
     * @return string Normalized environment variable name
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
