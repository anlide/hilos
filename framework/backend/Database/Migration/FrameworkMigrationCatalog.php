<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Database\DatabaseException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use JsonException;
use stdClass;

/** Immutable on-disk migration metadata; no database, runtime or migration execution. */
final class FrameworkMigrationCatalog
{
    private const string GENESIS_FILE = 'catalog.json';
    private const string CHANGE_DIRECTORY = 'Change';
    private const string CHANGE_FILE = 'change.json';

    /** @var array<string, ?string> Genesis up hashes indexed by unit */
    private array $genesisUp = [];

    /** @var array<string, ?string> Genesis down hashes indexed by unit */
    private array $genesisDown = [];

    /** @var array<string, FrameworkMigrationPart> Immutable sources indexed by exact part key */
    private array $parts = [];

    /**
     * The parsed catalog is private to this instance and can be rebuilt from source files.
     *
     * @param FrameworkSchemaCatalog $schema Single source of unit and feature metadata
     * @param string $directory Framework migration source root, replaceable with a temporary tree in tests
     * @throws DatabaseException When genesis, manifests, identities, source files or confined paths are invalid
     */
    public function __construct(
        private readonly FrameworkSchemaCatalog $schema = new FrameworkSchemaCatalog(),
        private readonly string $directory = __DIR__,
    ) {
        try {
            $this->readGenesis();
            $this->readChanges();
        } catch (FsException $exception) {
            throw new DatabaseException('Cannot read framework migration catalog: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param class-string<Hilos> $hilosClass Facade declaration whose units to select
     * @return list<FrameworkMigrationPart> Selected parts, dependencies first and independent parts in ID order
     * @throws DatabaseException When selected schema requirements or part dependencies cannot be satisfied
     * @throws IncompleteFeatureActivationException When a declared feature has no definition
     */
    public function selectedParts(string $hilosClass): array
    {
        return $this->partsForUnits(array_column($this->schema->selectedUnits($hilosClass), 'key'));
    }

    /**
     * Unit selection is a union: a dependency in an unselected unit is refused, never silently installed.
     *
     * @param list<string> $units Exact selected unit keys
     * @return list<FrameworkMigrationPart> Complete selected parts in stable topological order
     * @throws DatabaseException When a unit is unknown, dependencies are missing or cyclic, or a unit has unordered branches
     */
    public function partsForUnits(array $units): array
    {
        $selected = [];
        foreach ($units as $unit) {
            $this->schema->unit($unit);
            $selected[$unit] = true;
        }
        $parts = array_filter($this->parts, static fn (FrameworkMigrationPart $part): bool => isset($selected[$part->unit]));

        return $this->orderedParts($parts);
    }

    /**
     * @param string $changeId Exact change group id
     * @param string $unit Exact unit key
     * @return FrameworkMigrationPart Immutable source part
     * @throws DatabaseException When the identity is malformed or absent
     */
    public function part(string $changeId, string $unit): FrameworkMigrationPart
    {
        $key = FrameworkMigrationPart::keyOf($changeId, $unit);

        return $this->parts[$key] ?? throw new DatabaseException("Unknown framework migration part {$key}");
    }

    /**
     * Checks the latest explicitly ordered snapshot, or genesis when the unit has no parts.
     *
     * @param ?list<string> $units Unit keys to check, or the entire schema catalog
     * @throws DatabaseException When a selected graph is invalid, a stub cannot be read, or it drifted from its declared form
     */
    public function verifyCurrentStubs(?array $units = null): void
    {
        $units ??= array_column($this->schema->allUnits(), 'key');
        $up = $this->genesisUp;
        $down = $this->genesisDown;
        foreach ($this->partsForUnits($units) as $part) {
            $up[$part->unit] = $part->afterStubUpSha256;
            $down[$part->unit] = $part->afterStubDownSha256;
        }
        try {
            foreach ($units as $key) {
                $unit = $this->schema->unit($key);
                if ($unit->bootstrapOwned) {
                    continue;
                }
                if (
                    FrameworkMigrationOrigin::bodyHash(FsPath::read($unit->upPath)) !== $up[$key]
                    || FrameworkMigrationOrigin::bodyHash(FsPath::read($unit->downPath)) !== $down[$key]
                ) {
                    throw new DatabaseException("Framework stub drift for unit {$key}; declare its immutable change part");
                }
            }
        } catch (FsException $exception) {
            throw new DatabaseException('Cannot read framework migration stub: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Validates both generated directions and the exact declared coverage against immutable sources.
     * Install/baseline bodies keep their own digest; they are never compared with a future stub.
     *
     * @param string $upSql Generated up migration including origin
     * @param string $downSql Generated down migration including origin
     * @return FrameworkMigrationOrigin Verified origin, including future composite baselines
     * @throws DatabaseException When the pair, identities or immutable source digests disagree
     */
    public function verifyPair(string $upSql, string $downSql): FrameworkMigrationOrigin
    {
        $origin = FrameworkMigrationOrigin::parsePair($upSql, $downSql);
        $this->verifyOrigin($origin);

        return $origin;
    }

    /**
     * Body hashes must already have been checked by parsePair(); this checks source and coverage metadata.
     *
     * @param FrameworkMigrationOrigin $origin Parsed origin or provenance prepared for writing
     * @throws DatabaseException When units, source digests or exact coverage are invalid
     */
    public function verifyOrigin(FrameworkMigrationOrigin $origin): void
    {
        foreach ($origin->units as $unit) {
            $this->schema->unit($unit);
        }
        foreach ($origin->parts as $reference) {
            $source = $this->part($reference->changeId, $reference->unit);
            if ($source->upSha256 !== $reference->upSha256 || $source->downSha256 !== $reference->downSha256) {
                throw new DatabaseException("Framework origin disagrees with immutable source {$source->key()}");
            }
        }
        if ($origin->kind === FrameworkMigrationOrigin::CHANGE) {
            $source = $origin->parts[0];
            if ($origin->upSha256 !== $source->upSha256 || $origin->downSha256 !== $source->downSha256) {
                throw new DatabaseException("Framework change body disagrees with immutable source {$source->key()}");
            }
        }
    }

    /**
     * @param list<FrameworkMigrationOrigin> $origins Verified origins from a project's SQL pairs
     * @return list<FrameworkMigrationPart> Exact covered source set in deterministic identity order
     * @throws DatabaseException When provenance overlaps, dependencies are incomplete or cyclic, or unit branches are unordered
     */
    public function coveredParts(array $origins): array
    {
        $covered = [];
        $installed = [];
        $adopted = false;
        foreach ($origins as $origin) {
            $this->verifyOrigin($origin);
            if ($origin->kind === FrameworkMigrationOrigin::ADOPT) {
                if ($adopted) {
                    throw new DatabaseException('Duplicate framework genesis adoption provenance');
                }
                $adopted = true;
            }
            if ($origin->kind !== FrameworkMigrationOrigin::CHANGE) {
                foreach ($origin->units as $unit) {
                    if (isset($installed[$unit])) {
                        throw new DatabaseException("Duplicate framework installation provenance for unit {$unit}");
                    }
                    $installed[$unit] = true;
                }
            }
            foreach ($origin->parts as $part) {
                if (isset($covered[$part->key()])) {
                    throw new DatabaseException("Duplicate framework part provenance {$part->key()}");
                }
                $covered[$part->key()] = $this->part($part->changeId, $part->unit);
            }
        }
        $this->orderedParts($covered);
        ksort($covered, SORT_STRING);

        return array_values($covered);
    }

    /**
     * Exact set difference; a later ID never implies an earlier part was copied.
     *
     * @param list<string> $units Selected unit keys
     * @param list<FrameworkMigrationOrigin> $origins Verified project provenance
     * @return list<FrameworkMigrationPart> Selected parts not covered by any origin
     * @throws DatabaseException When selection or provenance is invalid or duplicated
     */
    public function missingParts(array $units, array $origins): array
    {
        $covered = [];
        foreach ($this->coveredParts($origins) as $part) {
            $covered[$part->key()] = true;
        }

        return array_values(array_filter(
            $this->partsForUnits($units),
            static fn (FrameworkMigrationPart $part): bool => !isset($covered[$part->key()]),
        ));
    }

    /** Read the JSON boundary once, keeping only typed unit-key/digest maps inside the catalog. */
    private function readGenesis(): void
    {
        $data = $this->readObject($this->directory . '/' . self::GENESIS_FILE);
        if (($data->format ?? null) !== FrameworkMigrationOrigin::FORMAT || !is_array($data->units ?? null)) {
            throw new DatabaseException('Unsupported or malformed framework genesis format');
        }
        foreach ($data->units as $entry) {
            if (
                !$entry instanceof stdClass || !is_string($entry->unit ?? null)
                || !property_exists($entry, 'upSha256') || !property_exists($entry, 'downSha256')
                || array_key_exists($entry->unit, $this->genesisUp)
            ) {
                throw new DatabaseException('Malformed or duplicate framework genesis unit');
            }
            $unit = $this->schema->unit($entry->unit);
            $this->assertStubHashes($unit, $entry->upSha256, $entry->downSha256);
            $this->genesisUp[$unit->key] = $entry->upSha256;
            $this->genesisDown[$unit->key] = $entry->downSha256;
        }
        foreach ($this->schema->allUnits() as $unit) {
            if (!array_key_exists($unit->key, $this->genesisUp)) {
                throw new DatabaseException("Framework genesis misses schema unit {$unit->key}");
            }
        }
    }

    /** Every manifest is read even for an unselected unit, so duplicate group IDs cannot be overwritten. */
    private function readChanges(): void
    {
        $root = $this->directory . '/' . self::CHANGE_DIRECTORY;
        $seenIds = [];
        foreach (FsPath::entries($root) as $entry) {
            if ($entry === 'README.md') {
                continue;
            }
            $group = $this->confinedPath($root, $entry, directory: true);
            $data = $this->readObject($this->confinedPath($group, self::CHANGE_FILE));
            if (
                ($data->format ?? null) !== FrameworkMigrationOrigin::FORMAT || !is_string($data->id ?? null)
                || !is_array($data->parts ?? null) || $data->parts === []
            ) {
                throw new DatabaseException("Unsupported or malformed framework change manifest {$entry}");
            }
            if (isset($seenIds[$data->id])) {
                throw new DatabaseException("Duplicate framework change id {$data->id}");
            }
            $seenIds[$data->id] = true;
            if ($data->id !== $entry) {
                throw new DatabaseException("Framework change directory disagrees with its id {$data->id}");
            }
            foreach ($data->parts as $part) {
                $this->readPart($data->id, $group, $part);
            }
        }
    }

    /**
     * @param string $id Validated manifest group id
     * @param string $group Confined source directory
     * @param mixed $data Untrusted manifest part at the JSON boundary
     */
    private function readPart(string $id, string $group, mixed $data): void
    {
        if (
            !$data instanceof stdClass || !is_string($data->unit ?? null)
            || !is_string($data->up ?? null) || !is_string($data->down ?? null)
            || !is_array($data->requires ?? null)
            || !property_exists($data, 'afterStubUpSha256') || !property_exists($data, 'afterStubDownSha256')
        ) {
            throw new DatabaseException("Malformed framework migration part in {$id}");
        }
        $key = FrameworkMigrationPart::keyOf($id, $data->unit);
        if (isset($this->parts[$key])) {
            throw new DatabaseException("Duplicate framework migration part {$key}");
        }
        $unit = $this->schema->unit($data->unit);
        $this->assertStubHashes($unit, $data->afterStubUpSha256, $data->afterStubDownSha256);
        $requires = [];
        foreach ($data->requires as $requirement) {
            if (
                !$requirement instanceof stdClass || !is_string($requirement->changeId ?? null)
                || !is_string($requirement->unit ?? null) || count(get_object_vars($requirement)) !== 2
            ) {
                throw new DatabaseException("Malformed framework part dependency in {$key}");
            }
            $requires[] = FrameworkMigrationPart::keyOf($requirement->changeId, $requirement->unit);
        }
        $upSql = FrameworkMigrationOrigin::canonicalSql(FsPath::read($this->confinedPath($group, $data->up)));
        $downSql = FrameworkMigrationOrigin::canonicalSql(FsPath::read($this->confinedPath($group, $data->down)));
        $this->parts[$key] = new FrameworkMigrationPart(
            $id,
            $unit->key,
            FrameworkMigrationOrigin::bodyHash($upSql),
            FrameworkMigrationOrigin::bodyHash($downSql),
            $requires,
            $upSql,
            $downSql,
            $data->afterStubUpSha256,
            $data->afterStubDownSha256,
        );
    }

    /** Path resolution is physical as well as lexical: no absolute paths, dot segments or escaping symlinks. */
    private function confinedPath(string $root, string $relative, bool $directory = false): string
    {
        if (
            $relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_contains($relative, ':') || str_starts_with($relative, '/')
            || array_intersect(explode('/', $relative), ['', '.', '..']) !== []
        ) {
            throw new DatabaseException("Invalid relative framework migration path {$relative}");
        }
        $resolvedRoot = realpath($root);
        $path = realpath($root . '/' . $relative);
        if (
            $resolvedRoot === false || $path === false || !str_starts_with($path, $resolvedRoot . '/')
            || ($directory ? !is_dir($path) : !is_file($path))
        ) {
            throw new DatabaseException("Framework migration path escapes its group or is absent: {$relative}");
        }

        return $path;
    }

    /** Decode boundary objects without converting JSON objects into empty lists. */
    private function readObject(string $path): stdClass
    {
        try {
            $data = json_decode(FsPath::read($path), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DatabaseException("Invalid framework migration JSON at {$path}", previous: $exception);
        }
        if (!$data instanceof stdClass) {
            throw new DatabaseException("Framework migration metadata must be an object at {$path}");
        }

        return $data;
    }

    /** Bootstrap ownership is represented by two nulls, never hashes of an invented stub. */
    private function assertStubHashes(FrameworkSchemaUnit $unit, mixed $up, mixed $down): void
    {
        if ($unit->bootstrapOwned) {
            if ($up !== null || $down !== null) {
                throw new DatabaseException("Bootstrap-owned unit {$unit->key} cannot have stub hashes");
            }

            return;
        }
        if (!is_string($up) || !is_string($down)) {
            throw new DatabaseException("Unit {$unit->key} requires both stub hashes");
        }
        FrameworkMigrationPart::assertHash($up);
        FrameworkMigrationPart::assertHash($down);
    }

    /**
     * @param array<string, FrameworkMigrationPart> $parts Selected source parts keyed by exact identity
     * @return list<FrameworkMigrationPart> Stable topological order with each unit explicitly linearized
     * @throws DatabaseException When a dependency is absent, cycles, or a unit's branches have no explicit order
     */
    private function orderedParts(array $parts): array
    {
        $remaining = [];
        $dependents = [];
        $ready = [];
        foreach ($parts as $key => $part) {
            $remaining[$key] = count($part->requires);
            foreach ($part->requires as $dependency) {
                if (!isset($parts[$dependency])) {
                    throw new DatabaseException("Framework part {$key} requires missing or unselected part {$dependency}");
                }
                $dependents[$dependency][] = $key;
            }
            if ($remaining[$key] === 0) {
                $ready[$key] = $part;
            }
        }
        $ordered = [];
        $ancestors = [];
        $previousByUnit = [];
        while ($ready !== []) {
            ksort($ready, SORT_STRING);
            $key = array_key_first($ready);
            $part = $ready[$key];
            unset($ready[$key]);
            $ancestors[$key] = [];
            foreach ($part->requires as $dependency) {
                $ancestors[$key][$dependency] = true;
                $ancestors[$key] += $ancestors[$dependency];
            }
            $previous = $previousByUnit[$part->unit] ?? null;
            if ($previous !== null && !isset($ancestors[$key][$previous])) {
                throw new DatabaseException("Ambiguous framework change branches for unit {$part->unit}: {$previous}, {$key}");
            }
            $previousByUnit[$part->unit] = $key;
            $ordered[] = $part;
            foreach ($dependents[$key] ?? [] as $dependent) {
                if (--$remaining[$dependent] === 0) {
                    $ready[$dependent] = $parts[$dependent];
                }
            }
        }
        if (count($ordered) !== count($parts)) {
            throw new DatabaseException('Framework migration part dependency cycle');
        }

        return $ordered;
    }
}
