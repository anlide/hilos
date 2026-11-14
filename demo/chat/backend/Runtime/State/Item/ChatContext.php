<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\Row\ChatContextRowCodec;
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
 * ChatContext - Singleton runtime data for chat context analysis.
 *
 * Stores only LLM-produced data: topic, summary, topicConfidence.
 * Id is always "main".
 */
final class ChatContext extends RtState
{
    public const string ID_MAIN = 'main';
    public const string topic = 'topic';
    public const string topicConfidence = 'topicConfidence';
    public const string summary = 'summary';

    /** Fixed row schema; variable text stays in Runtime pages. */
    private const array FIELD_TYPES = [
        self::topic => '?text',
        self::topicConfidence => 'float',
        self::summary => '?text',
    ];

    /** Synchronous property defaults, never defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::topic => null,
        self::topicConfidence => 0.0,
        self::summary => null,
    ];

    /** Fields accepted by the legacy patch contract. */
    private const array PATCH_FIELDS = [
        self::topic => true,
        self::topicConfidence => true,
        self::summary => true,
    ];

    /** Current conversation topic (null if none). */
    public ?string $topic {
        get => ($this->rowValues()[self::topic] ?? null)?->toNative();
        set {
            $this->setRowValue(self::topic, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Topic confidence 0..1. */
    public float $topicConfidence {
        get => $this->rowValues()[self::topicConfidence] ?? self::FIELD_DEFAULTS[self::topicConfidence];
        set {
            $this->setRowValue(self::topicConfidence, $value);
        }
    }

    /** LLM-generated summary of recent messages (null until one is produced). */
    public ?string $summary {
        get => ($this->rowValues()[self::summary] ?? null)?->toNative();
        set {
            $this->setRowValue(self::summary, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /**
     * Create empty chat context instance.
     *
     * @return static New instance
     */
    public static function create(): static
    {
        $instance = new static();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Create instance from row data (e.g. from persistence).
     *
     * @param array<string, mixed> $row Row data with topic, topicConfidence, summary
     * @return static New instance
     * @throws InvalidFormatException When the row is missing a field the context is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->topic = self::optionalString($row, self::topic);
        $instance->topicConfidence = self::requireFloat($row, self::topicConfidence);
        $instance->summary = self::optionalString($row, self::summary);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime singleton key for the shared chat context row.
     *
     * @return string Runtime singleton item key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::chatContext;
    }

    /**
     * Apply diff to state (partial update).
     *
     * @param array<string, mixed> $diff Fields to update (topic, topicConfidence, summary)
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->topic = self::patchOptionalString($diff, self::topic, $this->topic);
        $this->topicConfidence = self::patchFloat($diff, self::topicConfidence, $this->topicConfidence);
        $this->summary = self::patchOptionalString($diff, self::summary, $this->summary);
    }

    /**
     * Get state ID (always "main" for chat context).
     *
     * @return string State ID
     */
    public function getId(): string
    {
        return self::ID_MAIN;
    }

    /**
     * Convert state to array for persistence/serialization.
     *
     * @return array<string, mixed> State as associative array
     */
    public function toArray(): array
    {
        return [
            self::topic => $this->topic,
            self::topicConfidence => $this->topicConfidence,
            self::summary => $this->summary,
        ];
    }

    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new ChatContextRowCodec();
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
