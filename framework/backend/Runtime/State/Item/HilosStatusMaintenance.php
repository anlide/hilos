<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosStatusMaintenanceRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Status\StatusComponentDefinition;
use LogicException;

/** Shared current maintenance observation; the protected-mode owner alone controls the freeze. */
final class HilosStatusMaintenance extends RtState
{
    public const string RT_COLLECTION = 'hilosStatusMaintenance';
    public const string CURRENT = 'current';
    public const string id = 'id';
    public const string available = 'available';
    public const string active = 'active';
    public const string announcementKnown = 'announcementKnown';
    public const string periodId = 'periodId';
    public const string workPublicId = 'workPublicId';
    public const string title = 'title';
    public const string componentKeys = 'componentKeys';
    public const string startsAt = 'startsAt';
    public const string endsAt = 'endsAt';
    public const string startedAt = 'startedAt';
    public const string lastObservedAt = 'lastObservedAt';

    private const int PUBLIC_ID_BYTES = 32;
    private const int COMPONENT_KEY_MAX_BYTES = 128;

    /** Fixed row schema; variable values live in segmented pages. */
    private const array FIELD_TYPES = [
        self::id => 'text',
        self::available => 'bool',
        self::active => 'bool',
        self::announcementKnown => 'bool',
        self::periodId => '?text',
        self::workPublicId => '?text',
        self::title => '?text',
        self::componentKeys => 'list',
        self::startsAt => '?int',
        self::endsAt => '?int',
        self::startedAt => '?int',
        self::lastObservedAt => '?int',
    ];

    /** Application property defaults, not defaults for missing required incoming fields. */
    private const array FIELD_DEFAULTS = [
        self::id => self::CURRENT,
        self::available => false,
        self::active => false,
        self::announcementKnown => false,
        self::periodId => null,
        self::workPublicId => null,
        self::title => null,
        self::componentKeys => [],
        self::startsAt => null,
        self::endsAt => null,
        self::startedAt => null,
        self::lastObservedAt => null,
    ];

    /** Exactly the fields accepted by the legacy patch door. */
    private const array PATCH_FIELDS = [
        self::id => true,
        self::available => true,
        self::active => true,
        self::announcementKnown => true,
        self::periodId => true,
        self::workPublicId => true,
        self::title => true,
        self::componentKeys => true,
        self::startsAt => true,
        self::endsAt => true,
        self::startedAt => true,
        self::lastObservedAt => true,
    ];

    private(set) string $id {
        get => isset($this->rowValues()[self::id])
            ? $this->rowValues()[self::id]->toNative()
            : self::FIELD_DEFAULTS[self::id];
        set {
            $this->setRowValue(self::id, RtRowValues::fromNative($value));
        }
    }
    public bool $available {
        get => $this->rowValues()[self::available] ?? self::FIELD_DEFAULTS[self::available];
        set {
            $this->setRowValue(self::available, $value);
        }
    }
    public bool $active {
        get => $this->rowValues()[self::active] ?? self::FIELD_DEFAULTS[self::active];
        set {
            $this->setRowValue(self::active, $value);
        }
    }
    public bool $announcementKnown {
        get => $this->rowValues()[self::announcementKnown] ?? self::FIELD_DEFAULTS[self::announcementKnown];
        set {
            $this->setRowValue(self::announcementKnown, $value);
        }
    }
    public ?string $periodId {
        get => ($this->rowValues()[self::periodId] ?? null)?->toNative();
        set {
            $this->setRowValue(self::periodId, $value === null ? null : RtRowValues::fromNative($value));
        }
    }
    public ?string $workPublicId {
        get => ($this->rowValues()[self::workPublicId] ?? null)?->toNative();
        set {
            $this->setRowValue(self::workPublicId, $value === null ? null : RtRowValues::fromNative($value));
        }
    }
    public ?string $title {
        get => ($this->rowValues()[self::title] ?? null)?->toNative();
        set {
            $this->setRowValue(self::title, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** @var list<string> Sanitized catalog keys */
    public array $componentKeys {
        get => isset($this->rowValues()[self::componentKeys])
            ? $this->rowValues()[self::componentKeys]->toNative()
            : self::FIELD_DEFAULTS[self::componentKeys];
        set {
            $this->setRowValue(self::componentKeys, RtRowValues::fromNative($value));
        }
    }
    public ?int $startsAt {
        get => $this->rowValues()[self::startsAt] ?? self::FIELD_DEFAULTS[self::startsAt];
        set {
            $this->setRowValue(self::startsAt, $value);
        }
    }
    public ?int $endsAt {
        get => $this->rowValues()[self::endsAt] ?? self::FIELD_DEFAULTS[self::endsAt];
        set {
            $this->setRowValue(self::endsAt, $value);
        }
    }
    public ?int $startedAt {
        get => $this->rowValues()[self::startedAt] ?? self::FIELD_DEFAULTS[self::startedAt];
        set {
            $this->setRowValue(self::startedAt, $value);
        }
    }
    public ?int $lastObservedAt {
        get => $this->rowValues()[self::lastObservedAt] ?? self::FIELD_DEFAULTS[self::lastObservedAt];
        set {
            $this->setRowValue(self::lastObservedAt, $value);
        }
    }

    /**
     * @param bool $available Whether the unconditional freeze source is known
     * @param bool $active Last known occupied fact; unavailable does not imply inactive
     * @param bool $announcementKnown Whether announcement classification is proven
     * @param ?string $periodId Opaque public period identity
     * @param ?string $workPublicId Historical public announcement identity
     * @param ?string $title Sanitized title snapshot
     * @param list<string> $componentKeys Sanitized component keys
     * @param ?int $startsAt Planned inclusive start in epoch milliseconds UTC
     * @param ?int $endsAt Planned exclusive end in epoch milliseconds UTC
     * @param ?int $startedAt First occupied fact in epoch milliseconds UTC
     * @param ?int $lastObservedAt Last occupied fact in epoch milliseconds UTC
     * @return static Current maintenance facts, initially unavailable
     * @throws InvalidFormatException When a field is outside its domain
     */
    public static function create(
        bool $available = false,
        bool $active = false,
        bool $announcementKnown = false,
        ?string $periodId = null,
        ?string $workPublicId = null,
        ?string $title = null,
        array $componentKeys = [],
        ?int $startsAt = null,
        ?int $endsAt = null,
        ?int $startedAt = null,
        ?int $lastObservedAt = null,
    ): static
    {
        $instance = new static();
        $instance->available = $available;
        $instance->active = $active;
        $instance->announcementKnown = $announcementKnown;
        $instance->periodId = $periodId;
        $instance->workPublicId = $workPublicId;
        $instance->title = $title;
        $instance->componentKeys = $componentKeys;
        $instance->startsAt = $startsAt;
        $instance->endsAt = $endsAt;
        $instance->startedAt = $startedAt;
        $instance->lastObservedAt = $lastObservedAt;
        $instance->validate();
        $instance->markRtSyncBaseline();
        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized current observation
     * @return static Strictly hydrated maintenance facts
     * @throws InvalidFormatException When fields or the singleton key are invalid
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = self::requireString($row, self::id);
        $instance->available = self::requireBool($row, self::available);
        $instance->active = self::requireBool($row, self::active);
        $instance->announcementKnown = self::requireBool($row, self::announcementKnown);
        $instance->periodId = self::optionalString($row, self::periodId);
        $instance->workPublicId = self::optionalString($row, self::workPublicId);
        $instance->title = self::optionalString($row, self::title);
        $instance->componentKeys = self::requireStringList($row, self::componentKeys);
        $instance->startsAt = self::optionalInt($row, self::startsAt);
        $instance->endsAt = self::optionalInt($row, self::endsAt);
        $instance->startedAt = self::optionalInt($row, self::startedAt);
        $instance->lastObservedAt = self::optionalInt($row, self::lastObservedAt);
        $instance->validate();
        $instance->markRtSyncBaseline();
        return $instance;
    }

    /** @return string Feature-owned collection key */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /** @return string Immutable singleton key */
    public function getId(): string
    {
        return $this->id;
    }

    /** @return array<string, mixed> Complete current observation, with explicit nulls */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::available => $this->available,
            self::active => $this->active,
            self::announcementKnown => $this->announcementKnown,
            self::periodId => $this->periodId,
            self::workPublicId => $this->workPublicId,
            self::title => $this->title,
            self::componentKeys => $this->componentKeys,
            self::startsAt => $this->startsAt,
            self::endsAt => $this->endsAt,
            self::startedAt => $this->startedAt,
            self::lastObservedAt => $this->lastObservedAt,
        ];
    }

    /**
     * A rejected patch leaves every field intact.
     *
     * @param array<string, mixed> $diff Changed observation fields
     * @throws InvalidFormatException When a field is malformed or the singleton key changes
     */
    public function applyDiff(array $diff): void
    {
        $next = clone $this;
        $next->id = self::patchString($diff, self::id, $this->id);
        $next->available = self::patchBool($diff, self::available, $this->available);
        $next->active = self::patchBool($diff, self::active, $this->active);
        $next->announcementKnown = self::patchBool($diff, self::announcementKnown, $this->announcementKnown);
        $next->periodId = self::patchOptionalString($diff, self::periodId, $this->periodId);
        $next->workPublicId = self::patchOptionalString($diff, self::workPublicId, $this->workPublicId);
        $next->title = self::patchOptionalString($diff, self::title, $this->title);
        $next->componentKeys = self::patchStringList($diff, self::componentKeys, $this->componentKeys);
        $next->startsAt = self::patchOptionalInt($diff, self::startsAt, $this->startsAt);
        $next->endsAt = self::patchOptionalInt($diff, self::endsAt, $this->endsAt);
        $next->startedAt = self::patchOptionalInt($diff, self::startedAt, $this->startedAt);
        $next->lastObservedAt = self::patchOptionalInt($diff, self::lastObservedAt, $this->lastObservedAt);
        $next->validate();
        $this->available = $next->available;
        $this->active = $next->active;
        $this->announcementKnown = $next->announcementKnown;
        $this->periodId = $next->periodId;
        $this->workPublicId = $next->workPublicId;
        $this->title = $next->title;
        $this->componentKeys = $next->componentKeys;
        $this->startsAt = $next->startsAt;
        $this->endsAt = $next->endsAt;
        $this->startedAt = $next->startedAt;
        $this->lastObservedAt = $next->lastObservedAt;
    }

    /** @return RtRowCodec Segmented codec preserving this row's nested-value contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosStatusMaintenanceRowCodec();
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

    /** @throws InvalidFormatException When the key, identities, keys or moments are invalid */
    private function validate(): void
    {
        if ($this->id !== self::CURRENT) {
            throw new InvalidFormatException('The current maintenance key cannot change.');
        }
        foreach ([$this->periodId, $this->workPublicId] as $identity) {
            if ($identity !== null && preg_match('/^[a-f0-9]{32}$/D', $identity) !== 1) {
                throw new InvalidFormatException('Maintenance identities must be opaque public ids.');
            }
        }
        foreach ($this->componentKeys as $key) {
            if (!is_string($key) || preg_match(StatusComponentDefinition::KEY_PATTERN, $key) !== 1) {
                throw new InvalidFormatException('Maintenance component keys must be valid catalog keys.');
            }
        }
        if (!array_is_list($this->componentKeys) || count(array_unique($this->componentKeys)) !== count($this->componentKeys)) {
            throw new InvalidFormatException('Maintenance component keys must be a unique list.');
        }
        foreach ([$this->startsAt, $this->endsAt, $this->startedAt, $this->lastObservedAt] as $moment) {
            if ($moment !== null && $moment < 0) {
                throw new InvalidFormatException('Maintenance moments must be nonnegative epoch milliseconds.');
            }
        }
        if (($this->startsAt !== null && $this->endsAt !== null && $this->endsAt <= $this->startsAt)
            || ($this->startedAt !== null && $this->lastObservedAt !== null && $this->lastObservedAt < $this->startedAt)
            || (!$this->announcementKnown && $this->workPublicId !== null)) {
            throw new InvalidFormatException('Maintenance must preserve announcement knowledge and observation order.');
        }
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
                $prepared[$key] = yield from self::prepareList($reader);
                continue;
            }
            if ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text', 'moment', '?moment'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        yield from self::validateValues($prepared);

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

    /**
     * @param array<string, RtRowValues|int|float|bool|null> $prepared Fixed candidate descriptor
     * @return Generator<int, RtWork, int, void> Existing cross-field and domain validation
     * @throws InvalidFormatException When the key, identities, list or moments are invalid
     * @throws LogicException When a prepared value lease has closed
     */
    private static function validateValues(array $prepared): Generator
    {
        if (isset($prepared[self::id]) && !(yield from $prepared[self::id]->reader()->equals(self::CURRENT))) {
            throw new InvalidFormatException('The current maintenance key cannot change.');
        }
        foreach ([self::periodId, self::workPublicId] as $key) {
            yield new RtWork();
            if (isset($prepared[$key])) {
                $identity = yield from $prepared[$key]->reader()->boundedText(self::PUBLIC_ID_BYTES);
                $remaining = strlen($identity);
                while ($remaining > 0) {
                    $remaining -= yield new RtWork($remaining);
                }
                if (preg_match('/^[a-f0-9]{32}$/D', $identity) !== 1) {
                    throw new InvalidFormatException('Maintenance identities must be opaque public ids.');
                }
            }
        }
        if (isset($prepared[self::componentKeys])) {
            yield from self::validateComponentKeys($prepared[self::componentKeys]->reader());
        }
        foreach ([self::startsAt, self::endsAt, self::startedAt, self::lastObservedAt] as $key) {
            yield new RtWork();
            if (isset($prepared[$key]) && $prepared[$key] < 0) {
                throw new InvalidFormatException('Maintenance moments must be nonnegative epoch milliseconds.');
            }
        }
        yield new RtWork();
        if ((isset($prepared[self::startsAt], $prepared[self::endsAt]) && $prepared[self::endsAt] <= $prepared[self::startsAt])
            || (isset($prepared[self::startedAt], $prepared[self::lastObservedAt])
                && $prepared[self::lastObservedAt] < $prepared[self::startedAt])
            || (!($prepared[self::announcementKnown] ?? self::FIELD_DEFAULTS[self::announcementKnown])
                && isset($prepared[self::workPublicId]))) {
            throw new InvalidFormatException('Maintenance must preserve announcement knowledge and observation order.');
        }
    }

    /**
     * Walk the immutable prefix again for uniqueness, without a growing native index to retire.
     * Comparisons of even the longest accepted key pass through the byte cursor.
     *
     * @param RtRowReader $input Already type-checked string list
     * @return Generator<int, RtWork, int, void> Catalog keys validated in order
     * @throws InvalidFormatException When a key is invalid or duplicated
     * @throws LogicException When a prepared value lease has closed
     */
    private static function validateComponentKeys(RtRowReader $input): Generator
    {
        $cursor = $input->entries();
        $position = 0;
        while (($entry = yield from $cursor->advance()) !== null) {
            $key = yield from $entry->value->reader()->boundedText(self::COMPONENT_KEY_MAX_BYTES);
            $remaining = strlen($key);
            while ($remaining > 0) {
                $remaining -= yield new RtWork($remaining);
            }
            if (preg_match(StatusComponentDefinition::KEY_PATTERN, $key) !== 1) {
                throw new InvalidFormatException('Maintenance component keys must be valid catalog keys.');
            }
            $previous = $input->entries();
            for ($index = 0; $index < $position; ++$index) {
                $other = yield from $previous->advance();
                if (yield from $other->value->reader()->equals($key)) {
                    throw new InvalidFormatException('Maintenance component keys must be a unique list.');
                }
            }
            ++$position;
        }
    }
}
