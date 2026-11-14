<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosSessionToastStackRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosSessionToastStacks;
use Hilos\Runtime\View\Actions\Collection\HilosSessionToastStacksActions;
use LogicException;

/**
 * HilosSessionToastStack - the toasts one browser session is still being shown (HIL-768).
 *
 * The row that makes the tabs of one session agree about a card the SERVER raised. A toast of
 * somebody's own action belongs to the tab it was pressed in and never reaches here; one
 * addressed to the session has to outlive the tab, because closing it in the second window and
 * meeting it again in the first is the disagreement this row exists to end.
 *
 * ONE ROW PER SESSION, and the id is the hash of its cookie token - the same form the freeze
 * stores and {@see AbstractAgent::resolveInitiatorSessionTokenHash()} hands out, so a sender
 * that knows who asked can name the row without ever holding the token itself. A row per
 * CONNECTION would have to copy the list into every tab and copy every answer back out again.
 *
 * Framework-owned runtime state mounted for every project ({@see HilosSessionToastStacks}),
 * written by the agent that owns the session seam and by nobody else. It is runtime rather
 * than durable on purpose: a toast lives only while there is somebody looking at it, and a
 * restart that loses one loses a sentence nobody was reading.
 *
 * The two sets on it are answers from tabs, and they are asked in different shapes because
 * they mean different things: {@see self::TOAST_EXPIRED_BY} is per card - a countdown runs in
 * each tab separately - while {@see self::readingBy} is per row, since a cursor resting on the
 * stack holds the whole stack. Neither is ever pruned when a tab dies: the rule intersects
 * both with the live sockets when it runs ({@see HilosSessionToastStacksActions::settle()}),
 * so a closed tab neither vetoes forever nor expires on behalf of the living.
 */
final class HilosSessionToastStack extends RtState
{
    /** Runtime collection key mounted by the framework and used for RT sync. */
    public const string RT_COLLECTION = 'hilosSessionToastStacks';

    public const string sessionTokenHash = 'sessionTokenHash';
    public const string toasts = 'toasts';
    public const string readingBy = 'readingBy';

    /** Server-minted name of one toast; what a tab answers about. */
    public const string TOAST_KEY = 'key';

    /** The sentence the person reads. */
    public const string TOAST_MESSAGE = 'message';

    /** One of {@see SessionToastSeverity}, as its value. */
    public const string TOAST_SEVERITY = 'severity';

    /** Who is speaking, drawn above the sentence. */
    public const string TOAST_SOURCE = 'source';

    /** Where clicking the card takes the person. */
    public const string TOAST_DESTINATION = 'destination';

    /** How many times this exact card has been raised; drawn as xN from two upwards. */
    public const string TOAST_REPEATS = 'repeats';

    /** Accept keys of the tabs whose countdown for this card has burned down. */
    public const string TOAST_EXPIRED_BY = 'expiredBy';

    /** Fixed row schema; variable values live in segmented pages. */
    private const array FIELD_TYPES = [
        self::sessionTokenHash => 'text',
        self::toasts => 'array',
        self::readingBy => 'list',
    ];

    /** Application property defaults, not defaults for missing required incoming fields. */
    private const array FIELD_DEFAULTS = [
        self::sessionTokenHash => '',
        self::toasts => [],
        self::readingBy => [],
    ];

    /** Exactly the fields accepted by the legacy patch door. */
    private const array PATCH_FIELDS = [
        self::toasts => true,
        self::readingBy => true,
    ];

    /** Hash of the session cookie token these toasts are addressed to; also the row id. */
    private(set) string $sessionTokenHash {
        get => isset($this->rowValues()[self::sessionTokenHash])
            ? $this->rowValues()[self::sessionTokenHash]->toNative()
            : self::FIELD_DEFAULTS[self::sessionTokenHash];
        set {
            $this->setRowValue(self::sessionTokenHash, RtRowValues::fromNative($value));
        }
    }

    /**
     * @var list<array{key: string, message: string, severity: string, source: string,
     *     destination: string, repeats: int, expiredBy: list<string>}> Cards the session is still owed
     */
    private(set) array $toasts {
        get => isset($this->rowValues()[self::toasts])
            ? $this->rowValues()[self::toasts]->toNative()
            : self::FIELD_DEFAULTS[self::toasts];
        set {
            $this->setRowValue(self::toasts, RtRowValues::fromNative($value));
        }
    }

    /** @var list<string> Accept keys of the tabs where the stack is being read right now */
    private(set) array $readingBy {
        get => isset($this->rowValues()[self::readingBy])
            ? $this->rowValues()[self::readingBy]->toNative()
            : self::FIELD_DEFAULTS[self::readingBy];
        set {
            $this->setRowValue(self::readingBy, RtRowValues::fromNative($value));
        }
    }

    /**
     * Builds a stack row for one session.
     *
     * It takes the cards it is born with rather than starting empty, because a row with no
     * cards is a row that should not exist: the collection is emptied of it the moment its
     * last card goes, and creating one to fill it a line later would put that shape on the
     * wire in between.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param list<array{key: string, message: string, severity: string, source: string,
     *     destination: string, repeats: int, expiredBy: list<string>}> $toasts Cards the row is born with
     * @return static Fresh stack row
     */
    public static function create(string $sessionTokenHash, array $toasts): static
    {
        $instance = new static();
        $instance->sessionTokenHash = $sessionTokenHash;
        $instance->toasts = $toasts;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Stack row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the stack is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->sessionTokenHash = self::requireString($row, self::sessionTokenHash);
        /** @var list<array{key: string, message: string, severity: string, source: string,
         *     destination: string, repeats: int, expiredBy: list<string>}> $toasts */
        $toasts = array_values(self::requireArray($row, self::toasts));
        $instance->toasts = $toasts;
        $instance->readingBy = self::requireStringList($row, self::readingBy);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * The id is not among the patchable fields: a stack is the session's, and a row that could
     * be re-addressed mid-flight would deliver one browser's sentence to another.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        /** @var list<array{key: string, message: string, severity: string, source: string,
         *     destination: string, repeats: int, expiredBy: list<string>}> $toasts */
        $toasts = array_values(self::patchArray($diff, self::toasts, $this->toasts));
        $this->toasts = $toasts;
        $this->readingBy = self::patchStringList($diff, self::readingBy, $this->readingBy);
    }

    /**
     * @return string Runtime collection key for session toast stacks
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the session token hash
     */
    public function getId(): string
    {
        return $this->sessionTokenHash;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::sessionTokenHash => $this->sessionTokenHash,
            self::toasts => $this->toasts,
            self::readingBy => $this->readingBy,
        ];
    }

    /** @return RtRowCodec Segmented codec preserving this row's nested-value contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosSessionToastStackRowCodec();
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
            if (in_array($type, ['list', '?list', 'array'], true)) {
                $prepared[$key] = yield from self::prepareList($reader, $type !== 'array');
                continue;
            }
            if ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text', 'moment', '?moment'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }

    /**
     * Strict string lists keep their keys, order and duplicates; toasts only normalize outer keys.
     *
     * @param RtRowReader $input Incoming array
     * @param bool $strings Whether the existing reader requires a sequential string list
     * @return Generator<int, RtWork, int, RtRowValues> Validated segmented list
     * @throws InvalidFormatException When a list key or element violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    private static function prepareList(RtRowReader $input, bool $strings): Generator
    {
        $cursor = $input->entries();
        $writer = new RtRowWriter($input->arena(), RtRowRole::Candidate);
        $position = 0;
        while (($entry = yield from $cursor->advance()) !== null) {
            if ($strings && ($entry->key->reader()->kind() !== RtRowKind::Scalar
                || (yield from $entry->key->reader()->scalar()) !== $position
                || $entry->value->reader()->kind() !== RtRowKind::Text)) {
                throw new InvalidFormatException('Runtime row carries no list of strings');
            }
            $value = yield from RtRowWriter::copy($entry->value->reader(), $input->arena());
            yield from $writer->write($position++, $value);
        }

        return yield from $writer->finish();
    }
}
