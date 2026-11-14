<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Files\Upload\UploadSnapshotCollection;
use Hilos\Moderation\ModerationKind;
use Hilos\Runtime\Row\HilosModerationCheckRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosModerationChecks;
use LogicException;

/**
 * One check a connection is waiting on: what the moderator is handed, of which kind, and who
 * receives the verdict (HIL-1625).
 *
 * The row is keyed by the connection and the kind, so a connection holds one check of a kind at a
 * time. An opening draws a fresh request id or keeps an accepted session name-change request id.
 * A verdict applies only while it names the request id the row still holds. Changed content is a
 * new attempt, never an edit of the old one, and the row carries no field a write could rewrite.
 *
 * The collection is cut into sets by the agent that receives the verdict ({@see self::SET_VIA}),
 * taken from the kind and not from the caller: each initiator owns the checks it opened, and the
 * moderator only reads them. Framework-owned runtime state mounted by the moderation feature
 * ({@see ModerationFeature}, {@see HilosModerationChecks}).
 */
final class HilosModerationCheck extends RtState
{
    /** Runtime collection key mounted by the moderation feature and used for RT sync. */
    public const string RT_COLLECTION = 'hilosModerationChecks';

    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';
    public const string kind = 'kind';
    public const string requestId = 'requestId';
    public const string verdictTo = 'verdictTo';
    public const string text = 'text';
    public const string clientUploadId = 'clientUploadId';
    public const string startedAt = 'startedAt';
    public const string deadlineAt = 'deadlineAt';
    public const string sessionTokenHash = 'sessionTokenHash';
    public const string clientUploadIds = 'clientUploadIds';
    public const string imageCopy = 'imageCopy';
    public const string uploadHoldId = 'uploadHoldId';
    public const string uploadSnapshots = 'uploadSnapshots';

    /** The set a check is in is the agent its verdict goes to. */
    public const string SET_VIA = self::verdictTo;

    /** Separator of the two halves of the row id; a kind key never contains it. */
    private const string KEY_SEPARATOR = '|';

    /** Fixed row schema; variable values live in segmented pages. */
    private const array FIELD_TYPES = [
        self::acceptKey => 'text',
        self::userId => 'int',
        self::kind => 'text',
        self::requestId => 'text',
        self::verdictTo => 'text',
        self::text => '?text',
        self::clientUploadId => '?text',
        self::startedAt => 'int',
        self::deadlineAt => 'int',
        self::sessionTokenHash => '?text',
        self::clientUploadIds => 'list',
        self::imageCopy => 'bool',
        self::uploadHoldId => '?text',
        self::uploadSnapshots => 'snapshots',
    ];

    /** Application property defaults, not defaults for missing required incoming fields. */
    private const array FIELD_DEFAULTS = [
        self::acceptKey => '',
        self::userId => 0,
        self::kind => '',
        self::requestId => '',
        self::verdictTo => '',
        self::text => null,
        self::clientUploadId => null,
        self::startedAt => 0,
        self::deadlineAt => 0,
        self::sessionTokenHash => null,
        self::clientUploadIds => [],
        self::imageCopy => false,
        self::uploadHoldId => null,
        self::uploadSnapshots => [],
    ];

    /** Exactly the fields accepted by the legacy patch door. */
    private const array PATCH_FIELDS = [
    ];

    /** Accept key of the connection awaiting the verdict. */
    private(set) string $acceptKey {
        get => isset($this->rowValues()[self::acceptKey])
            ? $this->rowValues()[self::acceptKey]->toNative()
            : self::FIELD_DEFAULTS[self::acceptKey];
        set {
            $this->setRowValue(self::acceptKey, RtRowValues::fromNative($value));
        }
    }

    /** Person whose content is checked. */
    private(set) int $userId {
        get => $this->rowValues()[self::userId] ?? self::FIELD_DEFAULTS[self::userId];
        set {
            $this->setRowValue(self::userId, $value);
        }
    }

    /** Key of the moderation kind ({@see ModerationKind::$key}). */
    private(set) string $kind {
        get => isset($this->rowValues()[self::kind])
            ? $this->rowValues()[self::kind]->toNative()
            : self::FIELD_DEFAULTS[self::kind];
        set {
            $this->setRowValue(self::kind, RtRowValues::fromNative($value));
        }
    }

    /** Id of this attempt; a verdict naming another is stale. */
    private(set) string $requestId {
        get => isset($this->rowValues()[self::requestId])
            ? $this->rowValues()[self::requestId]->toNative()
            : self::FIELD_DEFAULTS[self::requestId];
        set {
            $this->setRowValue(self::requestId, RtRowValues::fromNative($value));
        }
    }

    /** Agent type the verdict is sent to, as the kind names it. */
    private(set) string $verdictTo {
        get => isset($this->rowValues()[self::verdictTo])
            ? $this->rowValues()[self::verdictTo]->toNative()
            : self::FIELD_DEFAULTS[self::verdictTo];
        set {
            $this->setRowValue(self::verdictTo, RtRowValues::fromNative($value));
        }
    }

    /** Text to check, for a kind that looks at text; null otherwise. */
    private(set) ?string $text {
        get => ($this->rowValues()[self::text] ?? null)?->toNative();
        set {
            $this->setRowValue(self::text, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Id of the complete upload on the same connection, for a kind that looks at an image; null otherwise. */
    private(set) ?string $clientUploadId {
        get => ($this->rowValues()[self::clientUploadId] ?? null)?->toNative();
        set {
            $this->setRowValue(self::clientUploadId, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Moment the check was opened, in epoch milliseconds. */
    private(set) int $startedAt {
        get => $this->rowValues()[self::startedAt] ?? self::FIELD_DEFAULTS[self::startedAt];
        set {
            $this->setRowValue(self::startedAt, $value);
        }
    }

    /** Moment the check stops waiting for a verdict, in epoch milliseconds; fixed at the opening. */
    private(set) int $deadlineAt {
        get => $this->rowValues()[self::deadlineAt] ?? self::FIELD_DEFAULTS[self::deadlineAt];
        set {
            $this->setRowValue(self::deadlineAt, $value);
        }
    }

    /** Session name-change correlation, or null for a connection-bound check. */
    private(set) ?string $sessionTokenHash {
        get => ($this->rowValues()[self::sessionTokenHash] ?? null)?->toNative();
        set {
            $this->setRowValue(self::sessionTokenHash, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** @var list<string> Ordered upload ids accompanying a text-with-files check */
    private(set) array $clientUploadIds {
        get => isset($this->rowValues()[self::clientUploadIds])
            ? $this->rowValues()[self::clientUploadIds]->toNative()
            : self::FIELD_DEFAULTS[self::clientUploadIds];
        set {
            $this->setRowValue(self::clientUploadIds, RtRowValues::fromNative($value));
        }
    }

    /** Whether the image is checked through a 512 by 512 CONTAIN JPEG copy. */
    private(set) bool $imageCopy {
        get => $this->rowValues()[self::imageCopy] ?? self::FIELD_DEFAULTS[self::imageCopy];
        set {
            $this->setRowValue(self::imageCopy, $value);
        }
    }

    /** First stage request id shared by all stages holding these bytes, or null without snapshots. */
    private(set) ?string $uploadHoldId {
        get => ($this->rowValues()[self::uploadHoldId] ?? null)?->toNative();
        set {
            $this->setRowValue(self::uploadHoldId, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Immutable identities of all attachments admitted for this attempt. */
    private(set) UploadSnapshotCollection $uploadSnapshots {
        get => $this->rowValues()[self::uploadSnapshots];
        set {
            $this->setRowValue(self::uploadSnapshots, $value);
        }
    }

    /**
     * Composes the row id from the connection and the kind.
     *
     * @param string $acceptKey Accept key of the connection
     * @param string $kind Moderation kind key
     * @return string Runtime row id `acceptKey|kind`
     */
    public static function keyFor(string $acceptKey, string $kind): string
    {
        return $acceptKey . self::KEY_SEPARATOR . $kind;
    }

    /**
     * Opens a check that has just been asked for.
     *
     * @param string $acceptKey Accept key of the connection awaiting the verdict
     * @param int $userId Person whose content is checked
     * @param string $kind Moderation kind key
     * @param string $requestId Id of this attempt
     * @param string $verdictTo Agent type the verdict is sent to
     * @param ?string $text Text to check, or null for a kind that looks at no text
     * @param ?string $clientUploadId Complete upload to check, or null for a kind that looks at no image
     * @param int $startedAt Opening moment in epoch milliseconds
     * @param int $deadlineAt Moment the wait ends, in epoch milliseconds
     * @param ?string $sessionTokenHash Session name-change correlation, or null for a connection-bound check
     * @param list<string> $clientUploadIds Ordered upload ids for a text-with-files check
     * @param bool $imageCopy Whether to check a CONTAIN JPEG copy of the image
     * @param ?UploadSnapshotCollection $uploadSnapshots Admitted byte identities, or null for an empty snapshot
     * @param ?string $uploadHoldId First stage request id, or null to use this request id for nonempty snapshots
     * @return static Fresh check row
     * @throws InvalidFormatException When the hold token and snapshots do not agree
     */
    public static function create(
        string $acceptKey,
        int $userId,
        string $kind,
        string $requestId,
        string $verdictTo,
        ?string $text,
        ?string $clientUploadId,
        int $startedAt,
        int $deadlineAt,
        ?string $sessionTokenHash = null,
        array $clientUploadIds = [],
        bool $imageCopy = false,
        ?UploadSnapshotCollection $uploadSnapshots = null,
        ?string $uploadHoldId = null,
    ): static {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->userId = $userId;
        $instance->kind = $kind;
        $instance->requestId = $requestId;
        $instance->verdictTo = $verdictTo;
        $instance->text = $text;
        $instance->clientUploadId = $clientUploadId;
        $instance->startedAt = $startedAt;
        $instance->deadlineAt = $deadlineAt;
        $instance->sessionTokenHash = $sessionTokenHash;
        $instance->clientUploadIds = $clientUploadIds;
        $instance->imageCopy = $imageCopy;
        $instance->uploadSnapshots = $uploadSnapshots ?? new UploadSnapshotCollection();
        $instance->uploadHoldId = $uploadHoldId ?? (count($instance->uploadSnapshots) > 0 ? $requestId : null);
        $instance->assertUploadHold();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Check row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the check is built from, or carries one as the wrong type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = self::requireString($row, self::acceptKey);
        $instance->userId = self::requireInt($row, self::userId);
        $instance->kind = self::requireString($row, self::kind);
        $instance->requestId = self::requireString($row, self::requestId);
        $instance->verdictTo = self::requireString($row, self::verdictTo);
        $instance->text = self::optionalString($row, self::text);
        $instance->clientUploadId = self::optionalString($row, self::clientUploadId);
        $instance->startedAt = self::requireInt($row, self::startedAt);
        $instance->deadlineAt = self::requireInt($row, self::deadlineAt);
        $instance->sessionTokenHash = self::optionalString($row, self::sessionTokenHash);
        $instance->clientUploadIds = self::requireStringList($row, self::clientUploadIds);
        $instance->imageCopy = self::requireBool($row, self::imageCopy);
        if (!array_key_exists(self::uploadHoldId, $row)) {
            throw new InvalidFormatException("Missing runtime field: " . self::uploadHoldId);
        }
        $instance->uploadHoldId = self::optionalString($row, self::uploadHoldId);
        try {
            $instance->uploadSnapshots = UploadSnapshotCollection::fromArray(self::requireArray($row, self::uploadSnapshots));
        } catch (InvalidFormatException $invalidSnapshot) {
            throw new InvalidFormatException('Invalid uploadSnapshots: ' . $invalidSnapshot->getMessage(), 0, $invalidSnapshot);
        }
        $instance->assertUploadHold();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Runtime collection key for moderation checks
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id `acceptKey|kind`
     */
    public function getId(): string
    {
        return self::keyFor($this->acceptKey, $this->kind);
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::kind => $this->kind,
            self::requestId => $this->requestId,
            self::verdictTo => $this->verdictTo,
            self::text => $this->text,
            self::clientUploadId => $this->clientUploadId,
            self::startedAt => $this->startedAt,
            self::deadlineAt => $this->deadlineAt,
            self::sessionTokenHash => $this->sessionTokenHash,
            self::clientUploadIds => $this->clientUploadIds,
            self::imageCopy => $this->imageCopy,
            self::uploadHoldId => $this->uploadHoldId,
            self::uploadSnapshots => $this->uploadSnapshots->toArray(),
        ];
    }

    /** @return RtRowCodec Segmented codec preserving this row's nested-value contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosModerationCheckRowCodec();
    }

    /**
     * Prepares an unpublished row; the committed-root layer supplies its baseline later.
     *
     * @param RtRowReader $in Segmented full row
     * @return Generator<int, RtWork, int, static> Fully validated candidate
     * @throws InvalidFormatException When a field violates the existing row contract
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
     * Like the legacy applyDiff, this immutable check ignores every diff field.
     *
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Unchanged immutable draft
     */
    public function patchValues(RtRowReader $diff): Generator
    {
        // The legacy base applyDiff deliberately ignores all fields for this immutable row.
        yield new RtWork();
    }

    /**
     * @param RtRowWriter $out Unpublished output in any Runtime arena
     * @return Generator<int, RtWork, int, void> Bounded nested-value export
     * @throws InvalidFormatException When a stored segmented value is malformed
     * @throws LogicException When the output has already closed
     */
    public function exportValues(RtRowWriter $out): Generator
    {
        return $this->frozenValues()->export($out);
    }

    /** @return array<string, mixed> Fixed property defaults in codec export order */
    protected function rowFieldDefaults(): array
    {
        return self::FIELD_DEFAULTS;
    }

    /** @throws InvalidFormatException When a hold token is empty or does not accompany snapshots */
    private function assertUploadHold(): void
    {
        if (
            $this->uploadHoldId === ''
            || (count($this->uploadSnapshots) > 0) !== ($this->uploadHoldId !== null)
        ) {
            throw new InvalidFormatException('The uploadHoldId must accompany nonempty uploadSnapshots.');
        }
    }

    /**
     * @param RtRowReader $input Segmented full row or diff
     * @param ?array<string, RtRowValues|UploadSnapshotCollection|int|float|bool|null> $current Fixed descriptor, null for restore
     * @return Generator<int, RtWork, int, array<string, RtRowValues|UploadSnapshotCollection|int|float|bool|null>> Validated fixed descriptor
     * @throws InvalidFormatException When a field violates its existing contract
     * @throws LogicException When a writer lifetime is violated
     */
    private static function prepareValues(RtRowReader $input, ?array $current): Generator
    {
        $prepared = $current ?? [];
        foreach (self::FIELD_TYPES as $key => $type) {
            yield new RtWork();
            if ($current !== null && !isset(self::PATCH_FIELDS[$key])) {
                continue;
            }
            $value = yield from $input->field($key);
            if ($value === null) {
                if ($current !== null) {
                    continue;
                }
                if ($key === self::uploadHoldId) {
                    throw new InvalidFormatException('Missing runtime field: ' . self::uploadHoldId);
                }
                if (str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                throw new InvalidFormatException('Runtime row is missing required key ' . $key);
            }
            $reader = $value->reader();
            if ($reader->kind() === RtRowKind::Scalar) {
                $scalar = yield from $reader->scalar();
                if ($scalar === null && str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                $scalarType = ltrim($type, '?');
                if (($scalarType === 'int' && is_int($scalar)) || ($scalarType === 'bool' && is_bool($scalar))) {
                    $prepared[$key] = $scalar;
                    continue;
                }
                if ($scalarType === 'float' && (is_int($scalar) || is_float($scalar))) {
                    $prepared[$key] = (float)$scalar;
                    continue;
                }
            }
            if (in_array($type, ['list', '?list', 'array'], true)) {
                $prepared[$key] = yield from self::prepareList($reader);
                continue;
            }
            if ($type === 'snapshots') {
                try {
                    $prepared[$key] = yield from UploadSnapshotCollection::fromValues($reader);
                } catch (InvalidFormatException $invalidSnapshot) {
                    throw new InvalidFormatException('Invalid uploadSnapshots: ' . $invalidSnapshot->getMessage(), 0, $invalidSnapshot);
                }
                continue;
            }
            if ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text', 'moment', '?moment'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        $hasSnapshots = count($prepared[self::uploadSnapshots]) > 0;
        if ((isset($prepared[self::uploadHoldId]) && (yield from $prepared[self::uploadHoldId]->reader()->equals('')))
            || $hasSnapshots !== isset($prepared[self::uploadHoldId])) {
            throw new InvalidFormatException('The uploadHoldId must accompany nonempty uploadSnapshots.');
        }

        return $prepared;
    }

    /**
     * Strict string lists retain their order and duplicates without a native list.
     *
     * @param RtRowReader $input Incoming array
     * @return Generator<int, RtWork, int, RtRowValues> Validated segmented list
     * @throws InvalidFormatException When a list key or element violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    private static function prepareList(RtRowReader $input): Generator
    {
        $cursor = $input->entries();
        $writer = new RtRowWriter($input->arena(), RtRowRole::Candidate);
        $position = 0;
        while (($entry = yield from $cursor->advance()) !== null) {
            if ($entry->key->reader()->kind() !== RtRowKind::Scalar
                || (yield from $entry->key->reader()->scalar()) !== $position
                || $entry->value->reader()->kind() !== RtRowKind::Text) {
                throw new InvalidFormatException('Runtime row carries no list of strings');
            }
            $value = yield from RtRowWriter::copy($entry->value->reader(), $input->arena());
            yield from $writer->write($position++, $value);
        }

        return yield from $writer->finish();
    }
}
