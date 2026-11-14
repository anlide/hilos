<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\Row\BotAgentStatusRowCodec;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Item\RtState;
use LogicException;

/**
 * Runtime lifecycle status for one bot agent.
 */
final class BotAgentStatus extends RtState
{
    public const string STATUS_JOINED = 'joined';
    public const string STATUS_LEFT = 'left';

    public const string botId = 'botId';
    public const string status = 'status';
    public const string updatedAt = 'updatedAt';

    /** Fixed row schema; variable text stays in Runtime pages. */
    private const array FIELD_TYPES = [
        self::botId => 'int',
        self::status => 'text',
        self::updatedAt => 'int',
    ];

    /** Synchronous property defaults, never defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::botId => 0,
        self::status => self::STATUS_LEFT,
        self::updatedAt => 0,
    ];

    /** Fields accepted by the legacy patch contract. */
    private const array PATCH_FIELDS = [
        self::status => true,
        self::updatedAt => true,
    ];

    /** Bot database id. */
    private(set) int $botId {
        get => $this->rowValues()[self::botId] ?? self::FIELD_DEFAULTS[self::botId];
        set {
            $this->setRowValue(self::botId, $value);
        }
    }

    /** Lifecycle marker: joined or left. */
    public string $status {
        get => isset($this->rowValues()[self::status]) ? $this->rowValues()[self::status]->toNative() : self::FIELD_DEFAULTS[self::status];
        set {
            $this->setRowValue(self::status, RtRowValues::fromNative($value));
        }
    }

    /** Unix time when the lifecycle marker changed. */
    public int $updatedAt {
        get => $this->rowValues()[self::updatedAt] ?? self::FIELD_DEFAULTS[self::updatedAt];
        set {
            $this->setRowValue(self::updatedAt, $value);
        }
    }

    /**
     * @param int $botId Bot database id
     * @param string $status Lifecycle marker
     * @return static Fresh status row
     */
    public static function create(int $botId, string $status): static
    {
        $instance = new static();
        $instance->botId = $botId;
        $instance->status = self::normalizeStatus($status);
        $instance->updatedAt = time();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated status row
     * @throws InvalidFormatException When the row is missing a field the status is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->botId = self::requireInt($row, self::botId);
        $instance->status = self::normalizeStatus(self::requireString($row, self::status));
        $instance->updatedAt = self::requireInt($row, self::updatedAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for bot lifecycle status rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::botAgentStatuses;
    }

    /**
     * A diff carries only the fields that changed, so an absent key leaves the field
     * as it was; a key that is present is read at its declared type. Wrapping the
     * patched marker in {@see self::normalizeStatus()} is safe either way - the
     * function is idempotent, so a diff about some other field re-normalizes a value
     * that already passed through it.
     *
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->status = self::normalizeStatus(self::patchString($diff, self::status, $this->status));
        $this->updatedAt = self::patchInt($diff, self::updatedAt, $this->updatedAt);
    }

    /**
     * @return string Runtime row id, `(string) botId`
     */
    public function getId(): string
    {
        return (string)$this->botId;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::botId => $this->botId,
            self::status => $this->status,
            self::updatedAt => $this->updatedAt,
        ];
    }

    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new BotAgentStatusRowCodec();
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
     * Every accepted field is validated before the fixed descriptor is replaced.
     *
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Atomic candidate patch
     * @throws InvalidFormatException When a changed field violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function patchValues(RtRowReader $diff): Generator
    {
        $prepared = yield from self::prepareValues($diff, $this->rowValues());
        yield new RtWork();
        $this->replaceRowValues($prepared);
    }

    /** @return array<string, mixed> Fixed property defaults in codec export order */
    protected function rowFieldDefaults(): array
    {
        return self::FIELD_DEFAULTS;
    }

    /**
     * @param RtRowWriter $out Unpublished output in any Runtime arena
     * @return Generator<int, RtWork, int, void> Bounded scalar/text export
     * @throws InvalidFormatException When a stored segmented value is malformed
     * @throws LogicException When the output has already closed
     */
    public function exportValues(RtRowWriter $out): Generator
    {
        return $this->frozenValues()->export($out);
    }

    private static function normalizeStatus(string $status): string
    {
        return $status === self::STATUS_JOINED ? self::STATUS_JOINED : self::STATUS_LEFT;
    }
    /**
     * @param RtRowReader $input Segmented full row or diff
     * @param ?array<string, RtRowValues|int|float|bool|null> $current Fixed descriptor, null for restore
     * @return Generator<int, RtWork, int, array<string, RtRowValues|int|float|bool|null>> Validated fixed descriptor
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
                    if ($key === self::status) {
                        $stored = $prepared[$key] ?? self::FIELD_DEFAULTS[$key];
                        $stored = $stored instanceof RtRowValues
                            ? $stored
                            : yield from RtRowWriter::encode($stored, $input->arena());
                        $prepared[$key] = yield from self::normalizeStatusValues($stored->reader(), $input);
                    }
                    continue;
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
            if ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            $prepared[$key] = $key === self::status
                ? yield from self::normalizeStatusValues($reader, $input)
                : yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }

    /**
     * @param RtRowReader $statusReader Text marker, possibly longer than any known status
     * @param RtRowReader $input Destination arena owner
     * @return Generator<int, RtWork, int, RtRowValues> Existing domain normalization, without collecting the input
     * @throws InvalidFormatException When stored text is malformed
     * @throws LogicException When page preparation violates writer lifetime
     */
    private static function normalizeStatusValues(RtRowReader $statusReader, RtRowReader $input): Generator
    {
        $status = (yield from $statusReader->equals(self::STATUS_JOINED)) ? self::STATUS_JOINED : self::STATUS_LEFT;

        return yield from RtRowWriter::encode($status, $input->arena(), RtRowRole::Candidate);
    }
}
