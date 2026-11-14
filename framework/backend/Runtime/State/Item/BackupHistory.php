<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\BackupHistoryRowCodec;
use Hilos\Runtime\Row\RtRowIntegerCast;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Utils\Helpers\UtcMoment;
use LogicException;

/**
 * BackupHistory - one runtime index row for a stored backup.
 *
 * Framework-owned runtime state: the project registers the backing
 * {@see BackupHistories} collection under
 * {@see RT_COLLECTION}, and the monopoly backup agent is its single writer.
 * Rows mirror the {@see BackupMetadata} sidecar (files=truth, RT=index). The
 * browser view/representation lands in HIL-278.
 */
final class BackupHistory extends RtState
{
    /** Runtime collection key registered by the project and used for RT sync. */
    public const string RT_COLLECTION = 'hilosBackupHistories';

    public const string id = 'id';
    public const string createdAt = 'createdAt';
    public const string env = 'env';
    public const string scope = 'scope';
    public const string connections = 'connections';
    public const string sizeBytes = 'sizeBytes';
    public const string durationSeconds = 'durationSeconds';
    public const string keep = 'keep';
    public const string status = 'status';
    public const string failureReason = 'failureReason';
    public const string dumpBytes = 'dumpBytes';
    public const string sha256 = 'sha256';
    public const string verifiedAt = 'verifiedAt';
    public const string verifyOutcome = 'verifyOutcome';
    public const string restoredAt = 'restoredAt';
    public const string restoreDurationSeconds = 'restoreDurationSeconds';
    public const string shippedAt = 'shippedAt';
    public const string shipOutcome = 'shipOutcome';
    public const string shipError = 'shipError';
    public const string shipEncryption = 'shipEncryption';
    public const string nodeId = 'nodeId';
    public const string reachable = 'reachable';

    private const int MOMENT_MAX_BYTES = 32;

    /** Fixed row schema, not an index whose size grows with runtime data. */
    private const array FIELD_TYPES = [
        self::id => 'text',
        self::createdAt => 'moment',
        self::env => 'text',
        self::scope => '?text',
        self::connections => 'connections',
        self::sizeBytes => 'int',
        self::durationSeconds => 'int',
        self::keep => 'bool',
        self::status => 'text',
        self::failureReason => '?text',
        self::dumpBytes => 'int',
        self::sha256 => '?text',
        self::verifiedAt => '?moment',
        self::verifyOutcome => '?text',
        self::restoredAt => '?moment',
        self::restoreDurationSeconds => 'int',
        self::shippedAt => '?moment',
        self::shipOutcome => '?text',
        self::shipError => '?text',
        self::shipEncryption => '?text',
        self::nodeId => '?text',
        self::reachable => 'bool',
    ];

    /** Existing property defaults, also used before a synchronous factory fills a row. */
    private const array FIELD_DEFAULTS = [
        self::id => '',
        self::createdAt => '',
        self::env => '',
        self::scope => null,
        self::connections => [],
        self::sizeBytes => 0,
        self::durationSeconds => 0,
        self::keep => false,
        self::status => '',
        self::failureReason => null,
        self::dumpBytes => 0,
        self::sha256 => null,
        self::verifiedAt => null,
        self::verifyOutcome => null,
        self::restoredAt => null,
        self::restoreDurationSeconds => 0,
        self::shippedAt => null,
        self::shipOutcome => null,
        self::shipError => null,
        self::shipEncryption => null,
        self::nodeId => null,
        self::reachable => true,
    ];

    /** Backup id (also the archive/sidecar base name). */
    private(set) string $id {
        get => isset($this->rowValues()[self::id]) ? $this->rowValues()[self::id]->toNative() : self::FIELD_DEFAULTS[self::id];
        set {
            $this->setRowValue(self::id, RtRowValues::fromNative($value));
        }
    }

    /** ISO-8601 UTC (Z) creation timestamp. */
    public string $createdAt {
        get => isset($this->rowValues()[self::createdAt]) ? $this->rowValues()[self::createdAt]->toNative() : self::FIELD_DEFAULTS[self::createdAt];
        set {
            $this->setRowValue(self::createdAt, RtRowValues::fromNative($value));
        }
    }

    /** Application environment the backup was taken in. */
    public string $env {
        get => isset($this->rowValues()[self::env]) ? $this->rowValues()[self::env]->toNative() : self::FIELD_DEFAULTS[self::env];
        set {
            $this->setRowValue(self::env, RtRowValues::fromNative($value));
        }
    }

    /** Scope value ({@see BackupScope}). */
    public ?string $scope {
        get => ($this->rowValues()[self::scope] ?? null)?->toNative();
        set {
            $this->setRowValue(self::scope, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** @var list<BackupConnectionMeta> Connections captured in the backup. */
    public array $connections {
        get => $this->materializeConnections();
        set {
            $this->setRowValue(self::connections, RtRowValues::fromNative(array_map(
                static fn(BackupConnectionMeta $connection): array => $connection->toArray(),
                $value,
            )));
        }
    }

    /** Archive size in bytes. */
    public int $sizeBytes {
        get => $this->rowValues()[self::sizeBytes] ?? self::FIELD_DEFAULTS[self::sizeBytes];
        set {
            $this->setRowValue(self::sizeBytes, $value);
        }
    }

    /** Wall-clock capture duration in seconds. */
    public int $durationSeconds {
        get => $this->rowValues()[self::durationSeconds] ?? self::FIELD_DEFAULTS[self::durationSeconds];
        set {
            $this->setRowValue(self::durationSeconds, $value);
        }
    }

    /** Retention pin: true excludes the backup from rotation. */
    public bool $keep {
        get => $this->rowValues()[self::keep] ?? self::FIELD_DEFAULTS[self::keep];
        set {
            $this->setRowValue(self::keep, $value);
        }
    }

    /** Status value ({@see BackupStatus}). */
    public string $status {
        get => isset($this->rowValues()[self::status]) ? $this->rowValues()[self::status]->toNative() : self::FIELD_DEFAULTS[self::status];
        set {
            $this->setRowValue(self::status, RtRowValues::fromNative($value));
        }
    }

    /** Why the run failed (error rows only); null for success rows and legacy sidecars. */
    public ?string $failureReason {
        get => ($this->rowValues()[self::failureReason] ?? null)?->toNative();
        set {
            $this->setRowValue(self::failureReason, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /**
     * Uncompressed dump volume in bytes; 0 for error rows and legacy sidecars. The field must
     * reach runtime because the space guard estimates a run's size from the index, not from the
     * sidecars on disk. Not carried into the browser table: the operator sees the archive size.
     */
    public int $dumpBytes {
        get => $this->rowValues()[self::dumpBytes] ?? self::FIELD_DEFAULTS[self::dumpBytes];
        set {
            $this->setRowValue(self::dumpBytes, $value);
        }
    }

    /** Archive digest from the sidecar; null for error rows and backups written before digests. */
    public ?string $sha256 {
        get => ($this->rowValues()[self::sha256] ?? null)?->toNative();
        set {
            $this->setRowValue(self::sha256, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** ISO-8601 UTC (Z) instant of the last verification; null means never verified. */
    public ?string $verifiedAt {
        get => ($this->rowValues()[self::verifiedAt] ?? null)?->toNative();
        set {
            $this->setRowValue(self::verifiedAt, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Outcome value of that verification ({@see BackupVerifyOutcome}); null if never verified. */
    public ?string $verifyOutcome {
        get => ($this->rowValues()[self::verifyOutcome] ?? null)?->toNative();
        set {
            $this->setRowValue(self::verifyOutcome, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** ISO-8601 UTC (Z) instant this archive was last restored from; null means never restored. */
    public ?string $restoredAt {
        get => ($this->rowValues()[self::restoredAt] ?? null)?->toNative();
        set {
            $this->setRowValue(self::restoredAt, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /**
     * How long that restore took in seconds; 0 means "no data". Restores have no history of their
     * own - the runtime row of a restore holds only the run in flight - so the archive keeps it,
     * and the estimate for the next restore is read from these rows.
     */
    public int $restoreDurationSeconds {
        get => $this->rowValues()[self::restoreDurationSeconds] ?? self::FIELD_DEFAULTS[self::restoreDurationSeconds];
        set {
            $this->setRowValue(self::restoreDurationSeconds, $value);
        }
    }

    /** ISO-8601 UTC (Z) instant this backup was last copied off the machine; null means never. */
    public ?string $shippedAt {
        get => ($this->rowValues()[self::shippedAt] ?? null)?->toNative();
        set {
            $this->setRowValue(self::shippedAt, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Outcome value of that copy ({@see BackupShipOutcome}); null if never attempted. */
    public ?string $shipOutcome {
        get => ($this->rowValues()[self::shipOutcome] ?? null)?->toNative();
        set {
            $this->setRowValue(self::shipOutcome, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Why the last copy attempt failed; null when none has. */
    public ?string $shipError {
        get => ($this->rowValues()[self::shipError] ?? null)?->toNative();
        set {
            $this->setRowValue(self::shipError, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /**
     * Fingerprint of the recipient set the last copy was encrypted to; null means it left in the
     * clear. The shipping planner compares it with the fingerprint configured now, which is what
     * makes turning a key on, off, or over re-send the store without a migration command of its
     * own.
     */
    public ?string $shipEncryption {
        get => ($this->rowValues()[self::shipEncryption] ?? null)?->toNative();
        set {
            $this->setRowValue(self::shipEncryption, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Cluster node whose disk holds this archive; null on an installation without clustering. */
    private(set) ?string $nodeId {
        get => ($this->rowValues()[self::nodeId] ?? null)?->toNative();
        set {
            $this->setRowValue(self::nodeId, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /**
     * Whether the agent that keeps this index can act on the archive - false once it has moved to
     * another node. Stored rather than derived from the node the agent runs on: moving the agent
     * changes no row, and a table whose rows did not change tells no open page anything.
     */
    public bool $reachable {
        get => $this->rowValues()[self::reachable] ?? self::FIELD_DEFAULTS[self::reachable];
        set {
            $this->setRowValue(self::reachable, $value);
        }
    }

    /**
     * Builds a history row from a scanned sidecar's metadata.
     *
     * The row is reachable by construction: the scan that found the sidecar ran on the node whose
     * disk holds it.
     *
     * @param BackupMetadata $metadata Sidecar metadata
     * @param ?string $nodeId Node that ran the scan, or null on an installation without clustering
     * @return static Fresh history row
     * @throws InvalidFormatException When metadata carries an ambiguous or invalid moment
     */
    public static function fromMetadata(BackupMetadata $metadata, ?string $nodeId): static
    {
        $instance = new static();
        $instance->id = $metadata->id;
        $instance->createdAt = UtcMoment::normalize($metadata->createdAt);
        $instance->env = $metadata->env;
        $instance->scope = $metadata->scope->value;
        $instance->connections = $metadata->connections;
        $instance->sizeBytes = $metadata->sizeBytes;
        $instance->durationSeconds = $metadata->durationSeconds;
        $instance->keep = $metadata->keep;
        $instance->status = $metadata->status->value;
        $instance->failureReason = $metadata->failureReason;
        $instance->dumpBytes = $metadata->dumpBytes;
        $instance->sha256 = $metadata->sha256;
        $instance->verifiedAt = $metadata->verifiedAt === null ? null : UtcMoment::normalize($metadata->verifiedAt);
        $instance->verifyOutcome = $metadata->verifyOutcome?->value;
        $instance->restoredAt = $metadata->restoredAt === null ? null : UtcMoment::normalize($metadata->restoredAt);
        $instance->restoreDurationSeconds = $metadata->restoreDurationSeconds;
        $instance->shippedAt = $metadata->shippedAt === null ? null : UtcMoment::normalize($metadata->shippedAt);
        $instance->shipOutcome = $metadata->shipOutcome?->value;
        $instance->shipError = $metadata->shipError;
        $instance->shipEncryption = $metadata->shipEncryption;
        $instance->nodeId = $nodeId;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static History row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the index is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = self::requireString($row, self::id);
        $instance->createdAt = UtcMoment::normalize(self::requireString($row, self::createdAt));
        $instance->env = self::requireString($row, self::env);
        $instance->scope = self::optionalString($row, self::scope);
        $instance->connections = self::readConnections($row);
        $instance->sizeBytes = self::requireInt($row, self::sizeBytes);
        $instance->durationSeconds = self::requireInt($row, self::durationSeconds);
        $instance->keep = self::requireBool($row, self::keep);
        $instance->status = self::requireString($row, self::status);
        $instance->failureReason = self::optionalString($row, self::failureReason);
        $instance->dumpBytes = self::requireInt($row, self::dumpBytes);
        $instance->sha256 = self::optionalString($row, self::sha256);
        $instance->verifiedAt = self::optionalString($row, self::verifiedAt);
        $instance->verifiedAt = $instance->verifiedAt === null ? null : UtcMoment::normalize($instance->verifiedAt);
        $instance->verifyOutcome = self::optionalString($row, self::verifyOutcome);
        $instance->restoredAt = self::optionalString($row, self::restoredAt);
        $instance->restoredAt = $instance->restoredAt === null ? null : UtcMoment::normalize($instance->restoredAt);
        $instance->restoreDurationSeconds = self::requireInt($row, self::restoreDurationSeconds);
        $instance->shippedAt = self::optionalString($row, self::shippedAt);
        $instance->shippedAt = $instance->shippedAt === null ? null : UtcMoment::normalize($instance->shippedAt);
        $instance->shipOutcome = self::optionalString($row, self::shipOutcome);
        $instance->shipError = self::optionalString($row, self::shipError);
        $instance->shipEncryption = self::optionalString($row, self::shipEncryption);
        $instance->nodeId = self::optionalString($row, self::nodeId);
        $instance->reachable = self::requireBool($row, self::reachable);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * Without it a synced update is silently dropped, and every worker but the writer keeps
     * the row it first received - a pinned backup would stay unpinned everywhere else.
     *
     * @param array<string, mixed> $diff Changed fields and values from another worker
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::createdAt, $diff)) {
            $this->createdAt = UtcMoment::normalize(self::patchString($diff, self::createdAt, $this->createdAt));
        }
        $this->env = self::patchString($diff, self::env, $this->env);
        $this->scope = self::patchOptionalString($diff, self::scope, $this->scope);
        if (array_key_exists(self::connections, $diff)) {
            $this->connections = self::readConnections($diff);
        }
        $this->sizeBytes = self::patchInt($diff, self::sizeBytes, $this->sizeBytes);
        $this->durationSeconds = self::patchInt($diff, self::durationSeconds, $this->durationSeconds);
        $this->keep = self::patchBool($diff, self::keep, $this->keep);
        $this->status = self::patchString($diff, self::status, $this->status);
        $this->failureReason = self::patchOptionalString($diff, self::failureReason, $this->failureReason);
        $this->dumpBytes = self::patchInt($diff, self::dumpBytes, $this->dumpBytes);
        $this->sha256 = self::patchOptionalString($diff, self::sha256, $this->sha256);
        if (array_key_exists(self::verifiedAt, $diff)) {
            $moment = self::patchOptionalString($diff, self::verifiedAt, $this->verifiedAt);
            $this->verifiedAt = $moment === null ? null : UtcMoment::normalize($moment);
        }
        $this->verifyOutcome = self::patchOptionalString($diff, self::verifyOutcome, $this->verifyOutcome);
        if (array_key_exists(self::restoredAt, $diff)) {
            $moment = self::patchOptionalString($diff, self::restoredAt, $this->restoredAt);
            $this->restoredAt = $moment === null ? null : UtcMoment::normalize($moment);
        }
        $this->restoreDurationSeconds = self::patchInt($diff, self::restoreDurationSeconds, $this->restoreDurationSeconds);
        if (array_key_exists(self::shippedAt, $diff)) {
            $moment = self::patchOptionalString($diff, self::shippedAt, $this->shippedAt);
            $this->shippedAt = $moment === null ? null : UtcMoment::normalize($moment);
        }
        $this->shipOutcome = self::patchOptionalString($diff, self::shipOutcome, $this->shipOutcome);
        $this->shipError = self::patchOptionalString($diff, self::shipError, $this->shipError);
        $this->shipEncryption = self::patchOptionalString($diff, self::shipEncryption, $this->shipEncryption);
        $this->nodeId = self::patchOptionalString($diff, self::nodeId, $this->nodeId);
        $this->reachable = self::patchBool($diff, self::reachable, $this->reachable);
    }

    /**
     * @return string Runtime collection key for backup history rows
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /** @return RtRowCodec Segmented codec preserving this row's own normalization */
    public function rowCodec(): RtRowCodec
    {
        return new BackupHistoryRowCodec();
    }

    /**
     * Prepares an invisible candidate without a native row or synchronous baseline.
     * The committed-root layer will attach its segmented baseline before publication.
     *
     * @param RtRowReader $in Segmented full row
     * @return Generator<int, RtWork, int, static> Fully validated candidate
     * @throws InvalidFormatException When the row violates the existing reader contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public static function fromValues(RtRowReader $in): Generator
    {
        $prepared = yield from self::prepareValues($in, null);
        yield new RtWork();
        $instance = new static();
        $instance->replaceRowValues($prepared);

        return $instance;
    }

    /**
     * No changed field is installed until every included field has passed validation.
     * Like applyDiff(), id is ignored and an absent nullable key stays unchanged.
     *
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Atomic candidate preparation
     * @throws InvalidFormatException When a changed field violates its existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function patchValues(RtRowReader $diff): Generator
    {
        $prepared = yield from self::prepareValues($diff, $this->rowValues());
        yield new RtWork();
        $this->replaceRowValues($prepared);
    }

    /**
     * @param RtRowWriter $out Unpublished output in any Runtime arena
     * @return Generator<int, RtWork, int, void> Bounded export, including inside strings/lists
     * @throws InvalidFormatException When a stored segmented value is malformed
     * @throws LogicException When the output has already closed
     */
    public function exportValues(RtRowWriter $out): Generator
    {
        return $this->frozenValues()->export($out);
    }

    /**
     * @return string Runtime row id, the backup id
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::createdAt => $this->createdAt,
            self::env => $this->env,
            self::scope => $this->scope,
            self::connections => array_map(
                static fn(BackupConnectionMeta $connection): array => $connection->toArray(),
                $this->connections,
            ),
            self::sizeBytes => $this->sizeBytes,
            self::durationSeconds => $this->durationSeconds,
            self::keep => $this->keep,
            self::status => $this->status,
            self::failureReason => $this->failureReason,
            self::dumpBytes => $this->dumpBytes,
            self::sha256 => $this->sha256,
            self::verifiedAt => $this->verifiedAt,
            self::verifyOutcome => $this->verifyOutcome,
            self::restoredAt => $this->restoredAt,
            self::restoreDurationSeconds => $this->restoreDurationSeconds,
            self::shippedAt => $this->shippedAt,
            self::shipOutcome => $this->shipOutcome,
            self::shipError => $this->shipError,
            self::shipEncryption => $this->shipEncryption,
            self::nodeId => $this->nodeId,
            self::reachable => $this->reachable,
        ];
    }

    /** @return array<string, mixed> Fixed property defaults in codec export order */
    protected function rowFieldDefaults(): array
    {
        return self::FIELD_DEFAULTS;
    }

    /**
     * @param RtRowReader $input Segmented full row or diff
     * @param ?array<string, RtRowValues|int|bool|null> $current Fixed descriptor, null for restore
     * @return Generator<int, RtWork, int, array<string, RtRowValues|int|bool|null>> Validated fixed descriptor
     * @throws InvalidFormatException When a field violates its existing contract
     * @throws LogicException When a writer lifetime is violated
     */
    private static function prepareValues(RtRowReader $input, ?array $current): Generator
    {
        $prepared = $current ?? [];
        foreach (self::FIELD_TYPES as $key => $type) {
            yield new RtWork();
            if ($current !== null && $key === self::id) {
                continue;
            }
            $value = yield from $input->field($key);
            if ($value === null) {
                if ($current !== null) {
                    continue;
                }
                if (str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                throw new InvalidFormatException('Runtime row is missing required key ' . $key);
            }
            $reader = $value->reader();
            if ($type === 'connections') {
                $prepared[$key] = yield from self::prepareConnections($reader);
                continue;
            }
            if ($reader->kind() === RtRowKind::Scalar) {
                $scalar = yield from $reader->scalar();
                if (
                    ($type === 'int' && is_int($scalar)) || ($type === 'bool' && is_bool($scalar))
                    || ($scalar === null && str_starts_with($type, '?'))
                ) {
                    $prepared[$key] = $scalar;
                    continue;
                }
            }
            if ($reader->kind() !== RtRowKind::Text || $type === 'int' || $type === 'bool') {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            if ($type === 'moment' || $type === '?moment') {
                $moment = yield from $reader->boundedText(self::MOMENT_MAX_BYTES);
                $remaining = strlen($moment);
                while ($remaining > 0) {
                    $remaining -= yield new RtWork($remaining);
                }
                $prepared[$key] = yield from RtRowWriter::encode(
                    UtcMoment::normalize($moment),
                    $input->arena(),
                    RtRowRole::Candidate,
                );
            } else {
                $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
            }
        }

        return $prepared;
    }

    /**
     * Input keys need not form a list: the legacy reader normalizes their order.
     * Each record retains its legacy cast/default semantics instead of stricter DTO readers.
     *
     * @param RtRowReader $input Array of connection records
     * @return Generator<int, RtWork, int, RtRowValues> Canonical segmented list
     * @throws InvalidFormatException When the enclosing value or an element is not an array, or a connection database is no scalar
     * @throws LogicException When a writer lifetime is violated
     */
    private static function prepareConnections(RtRowReader $input): Generator
    {
        $cursor = $input->entries();
        $connections = new RtRowWriter($input->arena(), RtRowRole::Candidate);
        $position = 0;
        while (($entry = yield from $cursor->advance()) !== null) {
            $record = $entry->value->reader();
            if ($record->kind() !== RtRowKind::Map) {
                throw new InvalidFormatException('Runtime row carries a non-array connection under key ' . self::connections);
            }
            $writer = new RtRowWriter($input->arena());
            $index = yield from $record->field(BackupConnectionMeta::index);
            $indexValue = $index === null ? 0 : yield from RtRowIntegerCast::read($index->reader());
            yield from $writer->write(
                BackupConnectionMeta::index,
                yield from RtRowWriter::encode($indexValue, $input->arena()),
            );
            $database = yield from $record->field(BackupConnectionMeta::database);
            if ($database !== null && $database->reader()->kind() === RtRowKind::Text) {
                $databaseValue = yield from RtRowWriter::copy($database->reader(), $input->arena());
            } elseif ($database !== null && $database->reader()->kind() === RtRowKind::Map) {
                throw new InvalidFormatException('Runtime row carries a non-scalar connection database under key ' . self::connections);
            } else {
                // The sidecar's existing string cast maps null to '' and a scalar to its text.
                $databaseValue = yield from RtRowWriter::encode(
                    $database === null ? '' : (string)(yield from $database->reader()->scalar()),
                    $input->arena(),
                );
            }
            yield from $writer->write(BackupConnectionMeta::database, $databaseValue);
            $migrationIndex = yield from $record->field(BackupConnectionMeta::migrationIndex);
            $migrationValue = null;
            if ($migrationIndex !== null) {
                $migrationReader = $migrationIndex->reader();
                if ($migrationReader->kind() !== RtRowKind::Scalar || (yield from $migrationReader->scalar()) !== null) {
                    $migrationValue = yield from RtRowIntegerCast::read($migrationReader);
                }
            }
            yield from $writer->write(
                BackupConnectionMeta::migrationIndex,
                yield from RtRowWriter::encode($migrationValue, $input->arena()),
            );
            yield from $connections->write($position++, yield from $writer->finish());
        }

        return yield from $connections->finish();
    }

    /** @return list<BackupConnectionMeta> Explicit application materialization */
    private function materializeConnections(): array
    {
        if (!isset($this->rowValues()[self::connections])) {
            return [];
        }

        return array_map(BackupConnectionMeta::fromArray(...), $this->rowValues()[self::connections]->toNative());
    }

    /**
     * Reads the connection list, the one field of this row that is a list of objects.
     *
     * The base readers stop at scalars and lists of strings, so the shape gets a reader of its
     * own rather than a cast: a row whose connections are not a list of arrays is a row this
     * state cannot describe, and the refusal is the same one every other field raises.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @return list<BackupConnectionMeta> Connections captured in the backup
     * @throws InvalidFormatException When the key is absent, holds a non-array, or holds a non-array element
     */
    private static function readConnections(array $source): array
    {
        $connections = [];
        foreach (self::requireArray($source, self::connections) as $connectionRow) {
            if (!is_array($connectionRow)) {
                throw new InvalidFormatException(
                    'Runtime row carries a non-array connection under key ' . self::connections,
                );
            }
            $connections[] = BackupConnectionMeta::fromArray($connectionRow);
        }

        return $connections;
    }
}
