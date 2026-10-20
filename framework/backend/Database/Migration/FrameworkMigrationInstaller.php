<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\MigrationTrackCheck;
use Hilos\Fs\Exception\FileAlreadyExistsException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;
use Throwable;

/** Plans and transports SQL sources only; never initializes an application, DB or runtime. */
class FrameworkMigrationInstaller
{
    public const string LOCK_FILE = '.hilos-framework.lock';

    private const int INDEX_DIGITS = 3;
    private const int TEMP_NAME_BYTES = 16;
    private const string ORIGIN_MARKER = 'hilos-framework:';
    private const string GENERATED_PREFIX = 'framework_';

    private readonly FrameworkSchemaCatalog $schema;
    private readonly FrameworkMigrationCatalog $catalog;

    /**
     * Parsed metadata and source plans live for this operation only; there is no shared RT reader or writer.
     *
     * @param ?FrameworkSchemaCatalog $schema Source unit metadata, or the framework catalog
     * @param ?FrameworkMigrationCatalog $catalog Immutable changes for that schema, or the framework catalog
     * @throws DatabaseException When framework source metadata is invalid or unreadable
     */
    public function __construct(?FrameworkSchemaCatalog $schema = null, ?FrameworkMigrationCatalog $catalog = null)
    {
        $this->schema = $schema ?? new FrameworkSchemaCatalog();
        $this->catalog = $catalog ?? new FrameworkMigrationCatalog($this->schema);
    }

    /**
     * @param FrameworkMigrationProject ...$projects Explicit targets, without switching the active facade
     * @return list<FrameworkMigrationPlan> Infrastructure plans in target order; no SQL files are written
     * @throws DatabaseException When a target lacks origin, has invalid provenance or cannot satisfy the selected graph
     * @throws IncompleteFeatureActivationException When a facade declares an unknown feature
     * @throws FsException When a track cannot be locked or read
     */
    public function plan(FrameworkMigrationProject ...$projects): array
    {
        $plans = [];
        $seen = [];
        foreach ($projects as $project) {
            $this->claimTarget($project, $seen);
            $plans[] = FsPath::withLock(
                $project->root . '/' . self::LOCK_FILE,
                false,
                fn (): FrameworkMigrationPlan => $this->planProject($project),
            );
        }

        return $plans;
    }

    /**
     * Preflight every target before writing SQL, then replan each under its exclusive track lock.
     * An I/O failure is a per-target outcome, not a promise to roll back previously published files.
     *
     * @param FrameworkMigrationProject ...$projects Explicit source targets
     * @return FrameworkMigrationResult Actual publications and failures, including an interrupted first half
     */
    public function execute(FrameworkMigrationProject ...$projects): FrameworkMigrationResult
    {
        return $this->run(
            array_map(static fn (FrameworkMigrationProject $project): FrameworkMigrationPlan => new FrameworkMigrationPlan($project, []), $projects),
            fn (FrameworkMigrationPlan $plan): FrameworkMigrationPlan => $this->planProject($plan->project),
        );
    }

    /**
     * Low-level source transport for explicit reviewed artifacts, including the repository's one-time demo pairs.
     * This does not discover or certify an unknown project's existing schema.
     *
     * @param FrameworkMigrationProject $project Explicit target
     * @param FrameworkMigrationPair ...$pairs Complete reviewed source pairs, in dependency order
     * @return FrameworkMigrationPlan Checked source publication plan
     * @throws DatabaseException When provenance, coverage, names or a partial pair are invalid
     * @throws FsException When a track cannot be locked or read
     */
    public function planSources(FrameworkMigrationProject $project, FrameworkMigrationPair ...$pairs): FrameworkMigrationPlan
    {
        return FsPath::withLock(
            $project->root . '/' . self::LOCK_FILE,
            false,
            fn (): FrameworkMigrationPlan => $this->preparePlan($project, $pairs, $this->readTrack($project)),
        );
    }

    /**
     * Rechecks every explicit artifact plan before any publication and again inside its exclusive lock.
     * Paths and presence flags supplied in a plan are never trusted as write destinations.
     *
     * @param FrameworkMigrationPlan ...$plans Reviewed source plans from planSources()
     * @return FrameworkMigrationResult Actual publications and per-target failures
     */
    public function publish(FrameworkMigrationPlan ...$plans): FrameworkMigrationResult
    {
        return $this->run($plans, fn (FrameworkMigrationPlan $plan): FrameworkMigrationPlan => $this->preparePlan(
            $plan->project,
            array_map(static fn (FrameworkMigrationPublication $publication): FrameworkMigrationPair => $publication->pair, $plan->publications),
            $this->readTrack($plan->project),
        ));
    }

    /**
     * @param FrameworkMigrationProject $project Track to scan under the caller's lock
     * @param bool $requireComplete Refuse framework half-pairs instead of leaving them for the source writer to resume
     * @return list<FrameworkMigrationTrackEntry> Complete numeric snapshot, including both orphan directions
     * @throws DatabaseException When numbers, framework headers or complete provenance are invalid
     * @throws FsException When numbered track sources cannot be read
     */
    public function readTrack(FrameworkMigrationProject $project, bool $requireComplete = false): array
    {
        $names = FsPath::entries($project->root);
        $duplicates = MigrationTrackCheck::takenTwice($names);
        if ($duplicates !== []) {
            throw new DatabaseException(
                'Framework source track has duplicate migration numbers: ' . implode(', ', array_merge(...array_values($duplicates))),
            );
        }
        $up = [];
        $down = [];
        foreach ($names as $name) {
            if (preg_match(MigrationTrackCheck::UP_FILE_PATTERN, $name, $matches) === 1) {
                $up[$this->index($matches[1])] = $name;
            } elseif (preg_match(MigrationTrackCheck::DOWN_FILE_PATTERN, $name, $matches) === 1) {
                $down[$this->index($matches[1])] = $name;
            }
        }
        $indices = array_unique(array_merge(array_keys($up), array_keys($down)));
        sort($indices, SORT_NUMERIC);
        $track = [];
        foreach ($indices as $index) {
            $upName = $up[$index] ?? null;
            $downName = $down[$index] ?? null;
            try {
                $upSql = $upName === null ? null : FrameworkMigrationOrigin::canonicalSql(FsPath::read($project->root . '/' . $upName));
                $downSql = $downName === null ? null : FrameworkMigrationOrigin::canonicalSql(FsPath::read($project->root . '/' . $downName));
            } catch (DatabaseException $exception) {
                throw new DatabaseException("Framework migration {$upName}, {$downName}: {$exception->getMessage()}", previous: $exception);
            }
            $origin = null;
            if ($this->isFrameworkSource($upName, $upSql) || $this->isFrameworkSource($downName, $downSql)) {
                if ($upSql === null || $downSql === null) {
                    if ($requireComplete) {
                        $missing = $upName === null
                            ? substr($downName, 0, -strlen('_down.sql')) . '.sql'
                            : substr($upName, 0, -strlen('.sql')) . '_down.sql';
                        throw new DatabaseException("Framework migration {$missing} is missing from this project; run db:framework:pull");
                    }
                } else {
                    if (substr($upName, 0, -strlen('.sql')) !== substr($downName, 0, -strlen('_down.sql'))) {
                        throw new DatabaseException("Framework origin pair filenames disagree: {$upName}, {$downName}");
                    }
                    try {
                        $origin = $this->catalog->verifyPair($upSql, $downSql);
                    } catch (DatabaseException $exception) {
                        throw new DatabaseException("Framework migration {$upName}, {$downName}: {$exception->getMessage()}", previous: $exception);
                    }
                }
            }
            $track[] = new FrameworkMigrationTrackEntry($index, $upName, $downName, $upSql, $downSql, $origin);
        }
        try {
            $this->catalog->coveredParts($this->origins($track));
        } catch (DatabaseException $exception) {
            $files = array_map(
                static fn (FrameworkMigrationTrackEntry $entry): string => $entry->upName,
                array_values(array_filter($track, static fn (FrameworkMigrationTrackEntry $entry): bool => $entry->origin !== null)),
            );
            throw new DatabaseException('Framework migrations ' . implode(', ', $files) . ': ' . $exception->getMessage(), previous: $exception);
        }

        return $track;
    }

    /**
     * @param string $tmpPath Complete same-directory temporary source
     * @param string $finalPath Destination chosen under the track lock
     * @throws FileAlreadyExistsException When any entry occupies the destination
     * @throws FileMoveException When linking or removing the published temp fails
     */
    protected function publishFile(string $tmpPath, string $finalPath): void
    {
        FsPath::publishExclusive($tmpPath, $finalPath);
    }

    /**
     * @param list<FrameworkMigrationPlan> $plans Infrastructure source intents
     * @param callable(FrameworkMigrationPlan): FrameworkMigrationPlan $recheck Track-dependent planner
     * @return FrameworkMigrationResult Batch preflight or execution outcomes
     */
    private function run(array $plans, callable $recheck): FrameworkMigrationResult
    {
        $outcomes = [];
        $seen = [];
        foreach ($plans as $plan) {
            try {
                $this->claimTarget($plan->project, $seen);
                FsPath::withLock($plan->project->root . '/' . self::LOCK_FILE, false, fn (): FrameworkMigrationPlan => $recheck($plan));
                $outcomes[] = new FrameworkMigrationProjectResult($plan->project);
            } catch (Throwable $exception) {
                $outcomes[] = new FrameworkMigrationProjectResult($plan->project, failure: $exception);
            }
        }
        $preflight = new FrameworkMigrationResult($outcomes);
        if ($preflight->hasFailures()) {
            return $preflight;
        }

        foreach ($plans as $key => $plan) {
            $published = [];
            $failure = null;
            try {
                FsPath::withLock($plan->project->root . '/' . self::LOCK_FILE, true, function () use ($plan, $recheck, &$published): void {
                    foreach ($recheck($plan)->publications as $publication) {
                        if (!$publication->downPresent) {
                            $this->writeFile($plan->project, $publication->downPath, $publication->pair->downSql, $published);
                        }
                        if (!$publication->upPresent) {
                            $this->writeFile($plan->project, $publication->upPath, $publication->pair->upSql, $published);
                        }
                    }
                });
            } catch (Throwable $exception) {
                $failure = $exception;
            }
            $outcomes[$key] = new FrameworkMigrationProjectResult($plan->project, $published, $failure, attempted: true);
        }

        return new FrameworkMigrationResult($outcomes);
    }

    /**
     * @param FrameworkMigrationProject $project Target being planned
     * @return FrameworkMigrationPlan Missing installs and changes in dependency order
     * @throws DatabaseException When origin, current stubs or the selected dependency graph are invalid
     * @throws IncompleteFeatureActivationException When a declared feature is unknown
     * @throws FsException When project or stub sources cannot be read
     */
    private function planProject(FrameworkMigrationProject $project): FrameworkMigrationPlan
    {
        $track = $this->readTrack($project);
        $origins = $this->origins($track);
        if ($origins === []) {
            throw new DatabaseException('This project has no framework catalog origin; only projects initialized for this catalog are supported');
        }
        $units = $this->schema->selectedUnits($project->facade);
        $keys = array_column($units, 'key');
        $this->catalog->verifyCurrentStubs($keys);
        $parts = $this->catalog->partsForUnits($keys);
        $known = [];
        foreach ($origins as $origin) {
            foreach ($origin->units as $unit) {
                $known[$unit] = true;
            }
        }
        $pairs = [];
        $installing = [];
        foreach ($units as $unit) {
            if ($unit->bootstrapOwned || isset($known[$unit->key])) {
                continue;
            }
            $up = FrameworkMigrationOrigin::canonicalSql(FsPath::read($unit->upPath));
            $down = FrameworkMigrationOrigin::canonicalSql(FsPath::read($unit->downPath));
            $pair = new FrameworkMigrationPair(
                self::GENERATED_PREFIX . 'install_' . $unit->key,
                FrameworkMigrationOrigin::forBodies(
                    FrameworkMigrationOrigin::INSTALL,
                    [$unit->key],
                    array_values(array_filter($parts, static fn (FrameworkMigrationPart $part): bool => $part->unit === $unit->key)),
                    $up,
                    $down,
                ),
                $up,
                $down,
            );
            $pairs[] = $pair;
            $installing[$unit->key] = $pair->name;
        }
        foreach ($this->catalog->missingParts($keys, $origins) as $part) {
            if (isset($installing[$part->unit])) {
                continue;
            }
            $pairs[] = new FrameworkMigrationPair(
                self::GENERATED_PREFIX . 'change_' . hash('sha256', $part->key()),
                FrameworkMigrationOrigin::forBodies(FrameworkMigrationOrigin::CHANGE, [$part->unit], [$part], $part->upSql, $part->downSql),
                $part->upSql,
                $part->downSql,
            );
        }

        return $this->preparePlan($project, $pairs, $track);
    }

    /**
     * @param list<FrameworkMigrationPair> $pairs Pending source operations
     * @return list<FrameworkMigrationPair> Operations ordered by stub and exact part dependencies
     * @throws DatabaseException When explicit sources collapse dependencies into an unorderable installation
     */
    private function orderPairs(array $pairs): array
    {
        $partOwners = [];
        $installing = [];
        foreach ($pairs as $pair) {
            foreach ($pair->origin->parts as $part) {
                $partOwners[$part->key()] = $pair->name;
            }
            if ($pair->origin->kind !== FrameworkMigrationOrigin::CHANGE) {
                foreach ($pair->origin->units as $unit) {
                    $installing[$unit] = $pair->name;
                }
            }
        }
        $pending = [];
        $dependencies = [];
        foreach ($pairs as $pair) {
            $pending[$pair->name] = $pair;
            $dependencies[$pair->name] = [];
            if ($pair->origin->kind !== FrameworkMigrationOrigin::CHANGE) {
                foreach ($pair->origin->units as $unit) {
                    foreach ($this->schema->unit($unit)->dependencies as $dependency) {
                        $owner = $installing[$dependency] ?? null;
                        if ($owner !== null && $owner !== $pair->name) {
                            $dependencies[$pair->name][$owner] = true;
                        }
                    }
                }
            }
            foreach ($pair->origin->parts as $part) {
                foreach ($this->catalog->part($part->changeId, $part->unit)->requires as $required) {
                    $owner = $partOwners[$required] ?? null;
                    if ($owner !== null && $owner !== $pair->name) {
                        $dependencies[$pair->name][$owner] = true;
                    }
                }
            }
        }
        $ordered = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $name => $pair) {
                if (array_intersect_key($pending, $dependencies[$name]) !== []) {
                    continue;
                }
                $ordered[] = $pair;
                unset($pending[$name]);
                $progress = true;
            }
            if (!$progress) {
                throw new DatabaseException('Framework installation and change dependencies cannot be ordered');
            }
        }

        return $ordered;
    }

    /**
     * @param FrameworkMigrationProject $project Source target
     * @param list<FrameworkMigrationPair> $pairs Explicit desired source pairs
     * @param list<FrameworkMigrationTrackEntry> $track Current track snapshot
     * @return FrameworkMigrationPlan Checked next numbers or exact resumable paths
     * @throws DatabaseException When provenance, dependencies, names or a half-published pair cannot be reconciled
     */
    private function preparePlan(FrameworkMigrationProject $project, array $pairs, array $track): FrameworkMigrationPlan
    {
        $origins = $this->origins($track);
        $complete = [];
        foreach ($origins as $origin) {
            $complete[$origin->header()] = true;
        }
        $wanted = [];
        $names = [];
        foreach ($pairs as $pair) {
            $this->catalog->verifyPair($pair->upSql, $pair->downSql);
            $identity = $pair->origin->header();
            if (isset($wanted[$identity]) || isset($names[$pair->name])) {
                throw new DatabaseException("Duplicate planned framework pair {$pair->name}");
            }
            $wanted[$identity] = $pair;
            $names[$pair->name] = true;
        }
        $remaining = array_diff_key($wanted, $complete);
        $this->catalog->coveredParts(array_merge($origins, array_map(
            static fn (FrameworkMigrationPair $pair): FrameworkMigrationOrigin => $pair->origin,
            array_values($remaining),
        )));
        $resuming = [];
        $max = 0;
        foreach ($track as $entry) {
            $max = max($max, $entry->index);
            if ($entry->upName !== null && $entry->downName !== null) {
                $stem = preg_replace('/\A\d+_|\.sql\z/', '', $entry->upName);
                if ($entry->origin === null && isset($names[$stem])) {
                    throw new DatabaseException("Foreign migration pair occupies the planned framework name {$stem}");
                }
                continue;
            }
            $name = $entry->upName ?? $entry->downName;
            $stem = preg_replace('/\A\d+_|(?:_down)?\.sql\z/', '', $name);
            $sql = $entry->upSql ?? $entry->downSql;
            if (!str_contains($sql, self::ORIGIN_MARKER) && !str_starts_with($stem, self::GENERATED_PREFIX) && !isset($names[$stem])) {
                continue; // Legacy orphan files reserve their numbers; they are never inferred to be framework provenance.
            }
            $matches = [];
            foreach ($remaining as $identity => $pair) {
                if ($stem === $pair->name && $sql === ($entry->upName !== null ? $pair->upSql : $pair->downSql)) {
                    $matches[$identity] = $pair;
                }
            }
            if (count($matches) !== 1) {
                throw new DatabaseException("Unknown or damaged framework migration half-pair {$name}; refusing to guess its counterpart");
            }
            $identity = array_key_first($matches);
            if (isset($resuming[$identity])) {
                throw new DatabaseException("Ambiguous framework migration half-pairs for {$stem}");
            }
            $resuming[$identity] = $entry;
        }
        $publications = [];
        foreach ($this->orderPairs(array_values($remaining)) as $pair) {
            $identity = $pair->origin->header();
            $entry = $resuming[$identity] ?? null;
            if ($entry !== null) {
                $upName = $entry->upName ?? substr($entry->downName, 0, -strlen('_down.sql')) . '.sql';
                $downName = $entry->downName ?? substr($entry->upName, 0, -strlen('.sql')) . '_down.sql';
                $publications[] = new FrameworkMigrationPublication(
                    $pair,
                    $entry->index,
                    $project->root . '/' . $upName,
                    $project->root . '/' . $downName,
                    $entry->upName !== null,
                    $entry->downName !== null,
                );
                continue;
            }
            if ($max === PHP_INT_MAX) {
                throw new DatabaseException('Framework migration source track has exhausted its numeric indices');
            }
            $index = ++$max;
            $base = $project->root . '/' . str_pad((string)$index, self::INDEX_DIGITS, '0', STR_PAD_LEFT) . '_' . $pair->name;
            $publications[] = new FrameworkMigrationPublication($pair, $index, $base . '.sql', $base . '_down.sql');
        }
        $this->verifyNumberOrder($publications, $track);

        return new FrameworkMigrationPlan($project, $publications);
    }

    /**
     * A recoverable half cannot move below a prerequisite that must now receive a new number.
     *
     * @param list<FrameworkMigrationPublication> $publications Checked source operations
     * @param list<FrameworkMigrationTrackEntry> $track Current complete provenance
     * @throws DatabaseException When resuming a pair would invert an exact source dependency
     */
    private function verifyNumberOrder(array $publications, array $track): void
    {
        $partIndices = [];
        $unitIndices = [];
        foreach (array_merge($track, $publications) as $entry) {
            $origin = $entry instanceof FrameworkMigrationPublication ? $entry->pair->origin : $entry->origin;
            if ($origin === null) {
                continue;
            }
            foreach ($origin->parts as $part) {
                $partIndices[$part->key()] = $entry->index;
            }
            if ($origin->kind !== FrameworkMigrationOrigin::CHANGE) {
                foreach ($origin->units as $unit) {
                    $unitIndices[$unit] = $entry->index;
                }
            }
        }
        foreach ($publications as $publication) {
            foreach ($publication->pair->origin->parts as $part) {
                foreach ($this->catalog->part($part->changeId, $part->unit)->requires as $required) {
                    if ($partIndices[$required] > $publication->index) {
                        throw new DatabaseException("Framework migration {$publication->index} would precede required part {$required}");
                    }
                }
            }
            if ($publication->pair->origin->kind !== FrameworkMigrationOrigin::CHANGE) {
                foreach ($publication->pair->origin->units as $unit) {
                    foreach ($this->schema->unit($unit)->dependencies as $dependency) {
                        if (isset($unitIndices[$dependency]) && $unitIndices[$dependency] > $publication->index) {
                            throw new DatabaseException("Framework migration {$publication->index} would precede required unit {$dependency}");
                        }
                    }
                }
            }
        }
    }

    /**
     * @param ?string $name Numbered basename, absent for an orphan's missing direction
     * @param ?string $sql Canonical SQL, absent with its basename
     * @return bool Whether provenance or the generated name identifies this as a framework source
     */
    private function isFrameworkSource(?string $name, ?string $sql): bool
    {
        return $name !== null && $sql !== null && (
            str_contains($sql, self::ORIGIN_MARKER)
            || str_starts_with(substr($name, strpos($name, '_') + 1), self::GENERATED_PREFIX)
        );
    }

    /**
     * @param list<FrameworkMigrationTrackEntry> $track Infrastructure scan snapshot
     * @return list<FrameworkMigrationOrigin> Complete verified pair origins
     */
    private function origins(array $track): array
    {
        $origins = [];
        foreach ($track as $entry) {
            if ($entry->origin !== null) {
                $origins[] = $entry->origin;
            }
        }

        return $origins;
    }

    /**
     * @param string $digits Number parsed from a migration filename
     * @return int Exact representable source index
     * @throws DatabaseException When the filename number cannot be represented exactly
     */
    private function index(string $digits): int
    {
        $normalized = ltrim($digits, '0');
        if ($normalized !== '' && (string)(int)$normalized !== $normalized) {
            throw new DatabaseException("Migration source index is out of range: {$digits}");
        }

        return (int)$digits;
    }

    /**
     * @param FrameworkMigrationProject $project Explicit target
     * @param array<string, true> $seen Already encountered physical track roots
     * @throws DatabaseException When the same physical track is targeted twice
     */
    private function claimTarget(FrameworkMigrationProject $project, array &$seen): void
    {
        if (isset($seen[$project->root])) {
            throw new DatabaseException("Duplicate framework migration target {$project->root}");
        }
        $seen[$project->root] = true;
    }

    /**
     * @param FrameworkMigrationProject $project Locked source target
     * @param string $finalPath Rechecked final destination
     * @param string $sql Complete canonical source file
     * @param list<string> $published Actual files created during this target's execution
     * @throws FsException When temp creation, inspection or exclusive publication fails
     * @throws RandomException When the secure temp-name source fails
     */
    private function writeFile(FrameworkMigrationProject $project, string $finalPath, string $sql, array &$published): void
    {
        $tmpPath = $this->createTemp($project, $sql);
        try {
            $identity = $this->fileIdentity($tmpPath);
            try {
                $this->publishFile($tmpPath, $finalPath);
            } catch (FileMoveException $exception) {
                // A link can succeed before temp removal fails. Attribute only our own inode, never a competing file.
                try {
                    if ($this->fileIdentity($finalPath) === $identity) {
                        $published[] = $finalPath;
                    }
                } catch (FsException) {
                    // No readable published inode to attribute; preserve the original publication refusal.
                }
                throw $exception;
            }
            $published[] = $finalPath;
        } finally {
            try {
                FsPath::delete($tmpPath);
            } catch (FileDeleteException) {
                // Teardown is best-effort; never remove a published final to compensate for a retained temp.
            }
        }
    }

    /**
     * @param FrameworkMigrationProject $project Existing locked track directory
     * @param string $sql Complete source text
     * @return string Exclusively created temp path on the same filesystem as its final destination
     * @throws RandomException When the secure name source fails
     * @throws FsException When a temp file cannot be created or written
     */
    private function createTemp(FrameworkMigrationProject $project, string $sql): string
    {
        do {
            $path = $project->root . '/.hilos-framework-' . RandomHelper::secureHex(self::TEMP_NAME_BYTES) . '.tmp';
        } while (!FsPath::createExclusive($path, $sql));

        return $path;
    }

    /**
     * fstat() is a descriptor boundary; the pair of device and inode identifies our published hard link.
     *
     * @param string $path Temp or final path
     * @return array{int, int} Device and inode identity, private to this publication
     * @throws FsException When the file cannot be opened or inspected
     */
    private function fileIdentity(string $path): array
    {
        return FsPath::readWith($path, static function ($handle): array {
            $stat = fstat($handle);
            if ($stat === false) {
                throw new FileReadException('Cannot inspect framework migration publication descriptor');
            }

            return [$stat['dev'], $stat['ino']];
        });
    }
}
