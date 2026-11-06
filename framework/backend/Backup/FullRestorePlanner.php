<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\DependencyProof;
use Hilos\Backup\FullRestore\DumpObjectType;
use Hilos\Backup\FullRestore\MigrationOperation;
use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\MigrationStep;
use Hilos\Backup\FullRestore\MigrationTargetEvidence;
use Hilos\Backup\FullRestore\PreparedTarget;
use Hilos\Backup\FullRestore\ProjectedObject;
use Hilos\Backup\FullRestore\ServerMetadata;
use Hilos\Backup\FullRestore\SqlTokens;
use Hilos\Backup\FullRestore\TableDrop;
use Hilos\Backup\FullRestore\TableName;
use Hilos\Backup\FullRestore\TableStructure;
use Hilos\Database\DatabaseException;
use Hilos\Database\MigrationUpTrack;

/**
 * Proven cleanup and its read-only evidence, owned by one cold command or hot restore child.
 * BackupRestorer rechecks this evidence immediately before executing the ordered cleanup or first import.
 */
final readonly class FullRestorePlanner
{
    private const string BASE_TABLE = 'BASE TABLE';

    /**
     * @param FullRestorePlan $inputs Original files, configuration, addresses, marker and rollout snapshot
     * @param FullRestoreMigrationEvidence $migrations Actual histories and shared UP source snapshot
     * @param FullRestoreSchemaSnapshot $schema Physical structures, privileges and dependencies
     * @param list<TableDrop> $cleanup Explicit child-before-parent removals; this class never executes them
     */
    private function __construct(
        public FullRestorePlan $inputs,
        public FullRestoreMigrationEvidence $migrations,
        public FullRestoreSchemaSnapshot $schema,
        public array $cleanup,
    ) {
    }

    /**
     * @param FullRestorePlan $inputs Every FULL input and destination already admitted
     * @return self Proven cleanup, bound to this invocation's exact inputs
     * @throws RestoreFailedException When provenance, structure, replay or dependency proof refuses
     * @throws DatabaseException When the caller's connection cannot be restored
     */
    public static function prepare(FullRestorePlan $inputs): self
    {
        $inputs->assertUnchanged();
        $migrations = FullRestoreMigrationEvidence::prepare($inputs->targets);
        $schema = FullRestoreSchemaSnapshot::read($inputs->targets);
        $cleanup = self::prove($migrations->targets, $migrations->track, $schema);
        return new self($inputs, $migrations, $schema, $cleanup);
    }

    /**
     * Must be called immediately before the first DROP or importing mysql process, within
     * the existing hot exclusion or cold stopped-process window. No plan survives a retry.
     *
     * @throws RestoreFailedException When any input, binding, rollout, history, track or catalog changed
     * @throws DatabaseException When the caller's connection cannot be restored
     */
    public function assertUnchanged(): void
    {
        $this->inputs->assertUnchanged();
        $this->schema->assertUnchanged($this->inputs->targets);
        $this->migrations->assertUnchanged();
        // Catalog reads can be long on a server with many objects; close with a fresh rollout/configuration check.
        $this->inputs->assertUnchanged();
    }

    /**
     * Pure compiler of the read-only evidence. Array maps are the infrastructure catalog boundary.
     * A source queue is consumed once, including its qualified journal operations.
     *
     * @param array<int, MigrationTargetEvidence> $evidence Facts by configured destination
     * @param MigrationUpTrack $track Same complete UP files as the real migrator
     * @param FullRestoreSchemaSnapshot $schema Physical catalog evidence
     * @return list<TableDrop> Only proven base tables, ordered across every FULL destination
     * @throws RestoreFailedException When any required proof is incomplete
     */
    public static function prove(array $evidence, MigrationUpTrack $track, FullRestoreSchemaSnapshot $schema): array
    {
        // readUpTrack and codeMigrationIndex share Migration's UP enumerator. Use this exact rechecked snapshot.
        $compatibility = RestoreMigrationGuard::decide(
            array_map(static fn (MigrationTargetEvidence $facts): BackupConnectionMeta => new BackupConnectionMeta(
                $facts->binding->target->connection->index, $facts->binding->target->config->database, $facts->facts->archiveLevel,
            ), array_values($evidence)),
            $track->files === [] ? null : max(array_column($track->files, 'index')),
            false,
        );
        if ($compatibility->reason !== null) {
            throw RestoreFailedException::beforeDestructive($compatibility->reason);
        }
        foreach ($evidence as $index => $facts) {
            try {
                $schema->schemas[$index]->assertSupportedProof();
            } catch (RestoreFailedException $failure) {
                throw RestoreFailedException::beforeDestructive("Full restore metadata, connection {$index}: " . $failure->getMessage(), $failure);
            }
        }
        $sources = [];
        $candidates = [];
        foreach ($evidence as $facts) {
            $source = $facts->binding->source;
            $sourceIndex = $source->connection->index;
            if (isset($sources[$sourceIndex])) {
                continue;
            }
            $sources[$sourceIndex] = true;
            $group = array_filter($evidence,
                static fn (MigrationTargetEvidence $target): bool => $target->binding->source->connection->index === $sourceIndex);
            try {
                array_push($candidates, ...self::group($group, $track, $schema));
            } catch (RestoreFailedException $failure) {
                throw RestoreFailedException::beforeDestructive("Full restore planner, connection {$sourceIndex}: "
                    . $failure->getMessage(), $failure);
            }
        }
        return self::dependencies($candidates, $evidence, $schema);
    }

    /**
     * @param array<int, MigrationTargetEvidence> $group Destinations sharing one physical execution source
     * @param MigrationUpTrack $track Complete source files for the unknown-level compatibility path
     * @param FullRestoreSchemaSnapshot $schema Physical catalog
     * @return list<TableDrop> Proven candidates before dependency sorting
     * @throws RestoreFailedException When lineage or replay is ambiguous
     */
    private static function group(array $group, MigrationUpTrack $track, FullRestoreSchemaSnapshot $schema): array
    {
        $sourceFacts = reset($group);
        $pending = $sourceFacts->pending;
        $recorded = $sourceFacts->facts->current?->recordedIndices() ?? [];
        if (count($recorded) !== count(array_unique($recorded)) || ($recorded !== [] && min($recorded) < 0)) {
            throw new RestoreFailedException('The live migration history contains contradictory rows');
        }
        $targets = [];
        $extra = [];
        foreach ($group as $index => $facts) {
            $target = $facts->binding->target;
            $targets[$target->config->database] = $target;
            foreach ($schema->schemas[$index]->objects as $name => $object) {
                $table = new TableName($target->config->database, $name);
                if (!isset($target->dump->objects[$name]) && !$table->isBootstrap()) {
                    $extra[self::key($table)] = $table;
                }
            }
        }
        $sourceIndex = $sourceFacts->binding->source->connection->index;
        $metadata = $schema->servers[$schema->schemas[$sourceIndex]->server];
        $sqlMode = $schema->schemas[$sourceIndex]->structureDefaults->sqlMode;
        if ($pending === null) {
            foreach ($track->files as $file) {
                foreach ($file->statements as $sql) {
                    foreach (MigrationOperationReader::read($sql, $sourceFacts->binding->source->config->database) as $operation) {
                        if ($operation->kind === MigrationOperationKind::UNKNOWN) {
                            throw new RestoreFailedException('Opaque UP operation in ' . basename($file->path));
                        }
                        DependencyProof::assertReplayCallsSupported($operation, $sqlMode, $metadata, basename($file->path));
                        if ($operation->kind === MigrationOperationKind::DATA) {
                            foreach ($operation->references as $table) {
                                self::dataScope($table, $targets);
                            }
                        } elseif ($operation->kind === MigrationOperationKind::DROP_TRIGGER) {
                            self::dropTrigger($operation, self::archive($targets, $schema), $targets, $schema);
                        }
                        if (($operation->table !== null && isset($extra[self::key($operation->table)]))
                            || ($operation->renamedTo !== null && isset($extra[self::key($operation->renamedTo)]))
                            || array_any($operation->references, static fn (TableName $table): bool => isset($extra[self::key($table)]))) {
                            throw new RestoreFailedException('An unknown archive level overlaps target-only objects; cleanup is not proven');
                        }
                    }
                }
            }
            return [];
        }
        if ($sourceFacts->facts->archiveFailed !== [] || ($sourceFacts->facts->current?->failedIndices() ?? []) !== []) {
            throw new RestoreFailedException('Failed migration history cannot establish an unambiguous UP replay');
        }

        $current = self::archive($targets, $schema);
        $successful = $sourceFacts->facts->current?->successfulIndices() ?? [];
        foreach ($pending as $step) {
            if (in_array($step->file->index, $successful, true)) {
                self::replay($current, $step, $targets, $schema, false);
            }
        }
        $cleanup = [];
        $origins = [];
        foreach ($extra as $key => $table) {
            $projected = $current[$key] ?? null;
            if ($projected?->origin === null) {
                continue;
            }
            $target = $targets[$table->database];
            $index = $target->connection->index;
            $group[$index]->facts->requireCleanupLevel();
            $live = $schema->schemas[$index]->objects[$table->table];
            if ($live->type !== self::BASE_TABLE || $projected->structure === null) {
                throw new RestoreFailedException("Object {$table->database}.{$table->table}: cleanup requires a proven base table");
            }
            $actual = TableStructure::read($live->createSql, $table->database, $schema->schemas[$index]->structureDefaults);
            if ($live->rowFormat !== null) {
                $actual->options['row_format'] = strtolower($live->rowFormat);
            }
            if ($actual->fingerprint() !== $projected->structure->fingerprint()) {
                throw new RestoreFailedException("Object {$table->database}.{$table->table}: "
                    . 'live structure differs from its successful CREATE/ALTER lineage');
            }
            $cleanup[$key] = new TableDrop($index, $table->table);
            $origins[self::origin($projected->origin)] = false;
        }
        $future = self::archive($targets, $schema);
        foreach ($extra as $key => $table) {
            if (!isset($cleanup[$key])) {
                $index = $targets[$table->database]->connection->index;
                $live = $schema->schemas[$index]->objects[$table->table];
                $structure = null;
                if ($live->type === self::BASE_TABLE) {
                    try {
                        $structure = TableStructure::read($live->createSql, $table->database, $schema->schemas[$index]->structureDefaults);
                    } catch (RestoreFailedException) {
                        // An independent table may remain opaque until a reference actually needs its structure.
                    }
                }
                $future[$key] = new ProjectedObject($table, $structure, null, true, $live->type === self::BASE_TABLE);
            }
        }
        self::archiveReferences($targets, $future);
        foreach ($pending as $step) {
            DependencyProof::assertReplayCallsSupported($step->operation, $sqlMode, $metadata, basename($step->file->path));
            self::replay($future, $step, $targets, $schema);
            if ($step->operation->kind === MigrationOperationKind::CREATE_TABLE && isset($origins[self::origin($step)])
                && ($future[self::key($step->operation->table)] ?? null)?->origin === $step) {
                $origins[self::origin($step)] = true;
            }
        }
        if (in_array(false, $origins, true)) {
            throw new RestoreFailedException('The post-import UP queue does not recreate every cleanup lineage');
        }
        return array_values($cleanup);
    }

    /**
     * @param array<string, PreparedTarget> $targets Destinations by physical schema on one source server
     * @param FullRestoreSchemaSnapshot $schema Effective structure defaults
     * @return array<string, ProjectedObject> Archive objects before UP
     */
    private static function archive(array $targets, FullRestoreSchemaSnapshot $schema): array
    {
        $objects = [];
        foreach ($targets as $target) {
            foreach ($target->dump->objects as $object) {
                $name = new TableName($target->config->database, $object->name);
                $structure = null;
                if ($object->type === DumpObjectType::TABLE) {
                    try {
                        $structure = TableStructure::read($object->createSql, $name->database,
                            $schema->schemas[$target->connection->index]->structureDefaults);
                    } catch (RestoreFailedException) {
                        // Archive admission is broader than provenance. No cleanup right is inferred here.
                    }
                }
                $objects[self::key($name)] = new ProjectedObject($name, $structure, null, baseTable: $object->type === DumpObjectType::TABLE);
            }
        }
        return $objects;
    }

    /**
     * @param array<string, ProjectedObject> $objects Current replay state, mutated only in this invocation
     * @param MigrationStep $step One source effect in SQL order
     * @param array<string, PreparedTarget> $targets Destinations owned by this source
     * @param FullRestoreSchemaSnapshot $schema Structure defaults
     * @param bool $validateReferences Whether this is the complete future replay rather than current lineage reconstruction
     * @throws RestoreFailedException When an operation collides, touches preserved rows or has unknown effects
     */
    private static function replay(
        array &$objects,
        MigrationStep $step,
        array $targets,
        FullRestoreSchemaSnapshot $schema,
        bool $validateReferences = true,
    ): void
    {
        $operation = $step->operation;
        if ($operation->kind === MigrationOperationKind::OTHER) {
            return;
        }
        if ($operation->kind === MigrationOperationKind::DATA) {
            if ($validateReferences) {
                self::dataReferences($operation, $objects, $targets);
            }
            return;
        }
        if ($operation->kind === MigrationOperationKind::DROP_TRIGGER) {
            self::dropTrigger($operation, $objects, $targets, $schema);
            return;
        }
        if ($operation->kind === MigrationOperationKind::UNKNOWN || $operation->table === null) {
            throw new RestoreFailedException('Opaque UP operation in ' . basename($step->file->path));
        }
        $table = $operation->table;
        if ($table->isBootstrap()) {
            return;
        }
        $key = self::key($table);
        if (!isset($targets[$table->database])) {
            throw new RestoreFailedException("Object {$table->database}.{$table->table}: UP writes outside its prepared source destinations");
        }
        if (($objects[$key] ?? null)?->retained) {
            throw new RestoreFailedException("Object {$table->database}.{$table->table}: UP intersects unproven target-only rows");
        }
        if (isset($objects[$key]) && !$objects[$key]->baseTable) {
            throw new RestoreFailedException("Object {$table->database}.{$table->table}: table UP intersects a view");
        }
        if ($operation->kind === MigrationOperationKind::CREATE_TABLE) {
            if (isset($objects[$key])) {
                if ($operation->ifNotExists) {
                    // These are archive or earlier-UP rows; retained target-only rows were refused above.
                    return;
                }
                throw new RestoreFailedException("Object {$table->database}.{$table->table}: post-archive CREATE collides with existing lineage");
            }
            $target = $targets[$table->database];
            $objects[$key] = new ProjectedObject($table,
                TableStructure::read($operation->sql, $table->database, $schema->schemas[$target->connection->index]->structureDefaults), $step);
        } elseif ($operation->kind === MigrationOperationKind::DROP_TABLE) {
            // A normal DROP of an archived object is ordinary UP, never cleanup authority over its neighbours.
            if (!isset($objects[$key])) {
                $drop = new SqlTokens(TableStructure::tokens($operation->sql));
                $drop->expect('DROP');
                $drop->expect('TABLE');
                if (!$drop->takeIf('IF')) {
                    throw new RestoreFailedException("Object {$table->database}.{$table->table}: UP drops an absent table without IF EXISTS");
                }
            }
            unset($objects[$key]);
        } elseif (!isset($objects[$key])) {
            throw new RestoreFailedException("Object {$table->database}.{$table->table}: UP expects an absent table");
        } elseif ($operation->kind === MigrationOperationKind::RENAME_TABLE) {
            $new = $operation->renamedTo;
            if ($new === null || $new->database !== $table->database || $new->isBootstrap() || isset($objects[self::key($new)])) {
                throw new RestoreFailedException("Object {$table->database}.{$table->table}: ambiguous rename destination");
            }
            $previous = $objects[$key];
            unset($objects[$key]);
            $objects[self::key($new)] = new ProjectedObject($new, $previous->structure, $previous->origin);
            foreach ($objects as $object) {
                $object->structure?->renameReferences($table, $new);
            }
        } elseif ($operation->kind === MigrationOperationKind::ALTER_TABLE) {
            if ($objects[$key]->structure === null) {
                throw new RestoreFailedException("Object {$table->database}.{$table->table}: ALTER structure is opaque");
            }
            $objects[$key]->structure->alter($operation);
        }
        if ($validateReferences) {
            foreach ($objects as $object) {
                foreach ($object->structure?->foreignKeys ?? [] as $reference) {
                    $parent = $objects[self::key($reference->parent)] ?? null;
                    if ($parent === null || !$parent->baseTable) {
                        throw new RestoreFailedException("Object {$object->name->database}.{$object->name->table}: "
                            . 'UP references an absent parent table');
                    }
                    if ($parent->structure === null || array_diff($reference->parentColumns, array_keys($parent->structure->columns)) !== []) {
                        throw new RestoreFailedException("Object {$object->name->database}.{$object->name->table}: "
                            . 'UP parent columns cannot be established');
                    }
                    $indexed = false;
                    foreach ($parent->structure->keys as $index) {
                        if ($index->algorithm === 'BTREE'
                            && array_slice(array_keys($index->columns), 0, count($reference->parentColumns)) === $reference->parentColumns
                            && array_all(array_slice($index->columns, 0, count($reference->parentColumns)),
                                static fn (string $part): bool => json_decode($part, true)[0] === null)) {
                            $indexed = true;
                        }
                    }
                    if (!$indexed || $object->structure->options['engine'] !== 'innodb' || $parent->structure->options['engine'] !== 'innodb') {
                        throw new RestoreFailedException('UP foreign key parent has no proven supporting index');
                    }
                    foreach ($reference->columns as $position => $column) {
                        $childColumn = $object->structure->columns[$column];
                        $parentColumn = $parent->structure->columns[$reference->parentColumns[$position]];
                        if ($childColumn->type !== $parentColumn->type
                            || ($childColumn->attributes['charset'] ?? null) !== ($parentColumn->attributes['charset'] ?? null)
                            || ($childColumn->attributes['collation'] ?? null) !== ($parentColumn->attributes['collation'] ?? null)) {
                            throw new RestoreFailedException('UP foreign key column types or collations do not match');
                        }
                    }
                }
            }
        }
    }

    /**
     * Current successful history proves structural provenance only. These requirements apply to
     * the complete future queue, after cleanup and import, rather than to the live target's rows.
     *
     * @param MigrationOperation $operation Complete direct DML effects
     * @param array<string, ProjectedObject> $objects Tables available at this execution position
     * @param array<string, PreparedTarget> $targets Schemas prepared on this source
     * @throws RestoreFailedException When data access reaches unavailable or unproven rows
     */
    private static function dataReferences(MigrationOperation $operation, array $objects, array $targets): void
    {
        foreach ($operation->references as $table) {
            self::dataScope($table, $targets);
            $object = $objects[self::key($table)] ?? null;
            if ($object === null) {
                throw new RestoreFailedException("Object {$table->database}.{$table->table}: UP data access requires an absent table");
            }
            if ($object->retained) {
                throw new RestoreFailedException("Object {$table->database}.{$table->table}: UP data access intersects unproven target-only rows");
            }
            if (!$object->baseTable) {
                throw new RestoreFailedException("Object {$table->database}.{$table->table}: UP data access through a view is opaque");
            }
        }
    }

    /**
     * @param TableName $table Direct data requirement, including the legacy unknown-level path
     * @param array<string, PreparedTarget> $targets Selected schemas on this execution source
     * @throws RestoreFailedException When DML bypasses bootstrap ownership or prepared bindings
     */
    private static function dataScope(TableName $table, array $targets): void
    {
        if ($table->isBootstrap()) {
            throw new RestoreFailedException("Object {$table->database}.{$table->table}: UP data access touches a bootstrap table");
        }
        if (!isset($targets[$table->database])) {
            throw new RestoreFailedException("Object {$table->database}.{$table->table}: UP data access is outside prepared destinations");
        }
    }

    /**
     * Dumps contain no triggers. Import drops those on archive tables; IF EXISTS also admits
     * an already absent service trigger, while the live catalog still protects retained owners.
     * No trigger removal supplies a table's CREATE provenance or weakens cleanup dependencies.
     *
     * @param MigrationOperation $operation One exact DROP TRIGGER IF EXISTS
     * @param array<string, ProjectedObject> $objects Structural state at this position
     * @param array<string, PreparedTarget> $targets Prepared schemas of this execution source
     * @param FullRestoreSchemaSnapshot $schema Existing physical trigger ownership catalog
     * @throws RestoreFailedException When trigger absence, visibility or owner independence is unproven
     */
    private static function dropTrigger(
        MigrationOperation $operation,
        array $objects,
        array $targets,
        FullRestoreSchemaSnapshot $schema,
    ): void {
        $name = $operation->trigger;
        if ($name === null || !isset($targets[$name->database]) || !$operation->ifExists) {
            throw new RestoreFailedException('UP trigger drop requires a prepared schema and IF EXISTS');
        }
        $index = $targets[$name->database]->connection->index;
        $metadata = $schema->schemas[$index];
        if (!$metadata->completeTriggerVisibility) {
            throw new RestoreFailedException('UP trigger ownership visibility is not proven');
        }
        foreach ($metadata->triggers as $trigger) {
            if (($trigger['TRIGGER_SCHEMA'] ?? null) !== $name->database || $trigger['TRIGGER_NAME'] !== $name->table) {
                continue;
            }
            $owner = new TableName((string)$trigger['EVENT_OBJECT_SCHEMA'], (string)$trigger['EVENT_OBJECT_TABLE']);
            $object = $objects[self::key($owner)] ?? null;
            if (!isset($targets[$owner->database]) || $owner->isBootstrap() || $object === null || $object->retained || !$object->baseTable) {
                throw new RestoreFailedException("Trigger {$name->database}.{$name->table}: UP drop intersects unproven target-only ownership");
            }
        }
    }

    /**
     * @param array<string, PreparedTarget> $targets Source's archive destinations
     * @param array<string, ProjectedObject> $objects Tables present before UP, after cleanup and import
     * @throws RestoreFailedException When an archive FK needs a table that cleanup removes
     */
    private static function archiveReferences(array $targets, array $objects): void
    {
        foreach ($targets as $target) {
            foreach ($target->dump->objects as $object) {
                foreach ($object->foreignKeys as $reference) {
                    $parent = new TableName($reference->referencedDatabase ?? $target->config->database, $reference->referencedTable);
                    if (!isset($objects[self::key($parent)])) {
                        throw new RestoreFailedException("Object {$target->config->database}.{$object->name}: "
                            . "archive references absent {$parent->database}.{$parent->table}");
                    }
                }
            }
        }
    }

    /**
     * @param list<TableDrop> $candidates Proven lineages before sorting
     * @param array<int, MigrationTargetEvidence> $evidence Physical bindings
     * @param FullRestoreSchemaSnapshot $schema Server-wide dependencies, including external schemas
     * @return list<TableDrop> Children before parents, without disabling FK checks
     * @throws RestoreFailedException When visibility, external incoming links or cycles forbid cleanup
     */
    private static function dependencies(array $candidates, array $evidence, FullRestoreSchemaSnapshot $schema): array
    {
        $nodes = [];
        $names = [];
        foreach ($candidates as $drop) {
            $server = $schema->schemas[$drop->index]->server;
            $table = new TableName($evidence[$drop->index]->binding->target->config->database, $drop->table);
            $nodes[$server . self::key($table)] = $drop;
            $names[$server][] = $table;
        }
        $edges = [];
        $incoming = array_fill_keys(array_keys($nodes), 0);
        foreach ($names as $server => $tables) {
            $metadata = $schema->servers[$server];
            $context = 'connection ' . $nodes[$server . self::key($tables[0])]->index . ', object '
                . $tables[0]->database . '.' . $tables[0]->table . ': ';
            if (!$metadata->completeVisibility) {
                throw RestoreFailedException::beforeDestructive($context . 'server-wide dependency visibility is not proven by direct global grants');
            }
            $proof = new DependencyProof($tables, $metadata->routines, array_column($metadata->functions, 'name'));
            $sqlMode = $schema->schemas[$nodes[$server . self::key($tables[0])]->index]->structureDefaults->sqlMode;
            foreach ($metadata->routines as $routine) {
                $proof->assertIndependent($routine[ServerMetadata::CREATE_SQL], (string)$routine['SQL_MODE'],
                    $context . 'routine ' . $routine['ROUTINE_SCHEMA'] . '.' . $routine['ROUTINE_NAME'], false);
            }
            foreach ($metadata->views as $view) {
                $proof->assertIndependent($view[ServerMetadata::CREATE_SQL], $sqlMode,
                    $context . 'view ' . $view['TABLE_SCHEMA'] . '.' . $view['TABLE_NAME']);
            }
            foreach ($metadata->events as $event) {
                $proof->assertIndependent($event[ServerMetadata::CREATE_SQL], (string)$event['SQL_MODE'],
                    $context . 'event ' . $event['EVENT_SCHEMA'] . '.' . $event['EVENT_NAME']);
            }
            foreach ($metadata->triggers as $trigger) {
                foreach ($tables as $table) {
                    if ($trigger['EVENT_OBJECT_SCHEMA'] === $table->database && $trigger['EVENT_OBJECT_TABLE'] === $table->table) {
                        throw RestoreFailedException::beforeDestructive($context . 'trigger ' . $trigger['TRIGGER_NAME']
                            . ' would be removed without a recreation proof');
                    }
                }
                $proof->assertIndependent($trigger[ServerMetadata::CREATE_SQL], (string)$trigger['SQL_MODE'],
                    $context . 'trigger ' . $trigger['TRIGGER_NAME']);
            }
            foreach ($evidence as $facts) {
                $index = $facts->binding->target->connection->index;
                if ($schema->schemas[$index]->server !== $server) {
                    continue;
                }
                foreach ($facts->pending ?? [] as $step) {
                    $proof->assertCallsIndependent($step->operation->sql, $sqlMode, $context . basename($step->file->path));
                }
                foreach ($facts->binding->target->dump->objects as $object) {
                    if ($object->type === DumpObjectType::VIEW) {
                        $proof->assertIndependent($object->createSql, $sqlMode, $context . 'archived view ' . $object->name);
                    }
                }
            }
            foreach ($metadata->foreignKeys as $row) {
                $parent = $server . self::key(new TableName((string)$row['REFERENCED_TABLE_SCHEMA'], (string)$row['REFERENCED_TABLE_NAME']));
                if (!isset($nodes[$parent])) {
                    continue;
                }
                $child = $server . self::key(new TableName((string)$row['TABLE_SCHEMA'], (string)$row['TABLE_NAME']));
                if (!isset($nodes[$child])) {
                    throw RestoreFailedException::beforeDestructive($context . 'incoming FK ' . $row['TABLE_SCHEMA'] . '.'
                        . $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'] . ' belongs to a preserved object (' . $row['DELETE_RULE'] . ')');
                }
                if (!isset($edges[$child][$parent])) {
                    $edges[$child][$parent] = true;
                    $incoming[$parent]++;
                }
            }
        }
        $ordered = [];
        while ($nodes !== []) {
            $progress = false;
            foreach ($nodes as $key => $drop) {
                if ($incoming[$key] !== 0) {
                    continue;
                }
                $ordered[] = $drop;
                unset($nodes[$key]);
                foreach ($edges[$key] ?? [] as $parent => $edge) {
                    $incoming[$parent]--;
                }
                $progress = true;
            }
            if (!$progress) {
                $drop = reset($nodes);
                throw RestoreFailedException::beforeDestructive("connection {$drop->index}, object {$drop->table}: "
                    . 'cleanup dependencies contain a cycle');
            }
        }
        return $ordered;
    }

    /**
     * @param TableName $table Schema and exact table
     * @return string Unambiguous invocation-local physical key, scoped by the caller's server
     */
    private static function key(TableName $table): string
    {
        return json_encode([$table->database, $table->table]);
    }

    /**
     * @param MigrationStep $step Exact direct CREATE source
     * @return string File and execution position, independent of later ALTER/RENAME names
     */
    private static function origin(MigrationStep $step): string
    {
        return json_encode([$step->file->path, $step->statementIndex]);
    }
}
