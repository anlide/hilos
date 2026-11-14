<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\Row\ChatUserStateRowCodec;
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
 * Per-user chat runtime row: shared outbound message rate-limit state.
 *
 * State id is `(string) userId`. Created lazily at chat WebSocket handshake via
 * `UserStatesActions::ensure()`. Connection-local moderation lives on connections; uploads are the framework uploads agent's.
 */
final class ChatUserState extends RtState
{
    public const string userId = 'userId';
    public const string lastOutboundSubmittedAt = 'lastOutboundSubmittedAt';

    /** Fixed row schema; variable text stays in Runtime pages. */
    private const array FIELD_TYPES = [
        self::userId => 'int',
        self::lastOutboundSubmittedAt => 'float',
    ];

    /** Synchronous property defaults, never defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::userId => 0,
        self::lastOutboundSubmittedAt => 0.0,
    ];

    /** Fields accepted by the legacy patch contract. */
    private const array PATCH_FIELDS = [
        self::lastOutboundSubmittedAt => true,
    ];

    /** User ID (equals collection key as integer). */
    private(set) int $userId {
        get => $this->rowValues()[self::userId] ?? self::FIELD_DEFAULTS[self::userId];
        set {
            $this->setRowValue(self::userId, $value);
        }
    }

    /** Microtime of the last accepted outbound submit. */
    public float $lastOutboundSubmittedAt {
        get => $this->rowValues()[self::lastOutboundSubmittedAt] ?? self::FIELD_DEFAULTS[self::lastOutboundSubmittedAt];
        set {
            $this->setRowValue(self::lastOutboundSubmittedAt, $value);
        }
    }

    /**
     * @param int $userId Database user id
     * @return static Fresh row with no accepted outbound submit
     */
    public static function createEmpty(int $userId): static
    {
        $instance = new static();
        $instance->userId = $userId;
        $instance->lastOutboundSubmittedAt = 0.0;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated per-user row
     * @throws InvalidFormatException When the row is missing a field the row is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->userId = self::requireInt($row, self::userId);
        $instance->lastOutboundSubmittedAt = self::requireFloat($row, self::lastOutboundSubmittedAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for per-user state rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::userStates;
    }

    /**
     * @param array<string, mixed> $diff Partial update using the same string keys as `fromRow()`
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->lastOutboundSubmittedAt = self::patchFloat($diff, self::lastOutboundSubmittedAt, $this->lastOutboundSubmittedAt);
    }

    /**
     * @return string Runtime collection key (`(string) userId`)
     */
    public function getId(): string
    {
        return (string)$this->userId;
    }

    /**
     * @return array<string, mixed> Row suitable for persistence / truth-source sync
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::lastOutboundSubmittedAt => $this->lastOutboundSubmittedAt,
        ];
    }

    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new ChatUserStateRowCodec();
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

    /** @return array<string, mixed> Fixed property defaults in codec export order */
    protected function rowFieldDefaults(): array
    {
        return self::FIELD_DEFAULTS;
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
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }
}
