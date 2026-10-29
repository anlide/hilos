<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Status\StatusComponentDefinition;

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

    private(set) string $id = self::CURRENT;
    public bool $available = false;
    public bool $active = false;
    public bool $announcementKnown = false;
    public ?string $periodId = null;
    public ?string $workPublicId = null;
    public ?string $title = null;
    /** @var list<string> Sanitized catalog keys */
    public array $componentKeys = [];
    public ?int $startsAt = null;
    public ?int $endsAt = null;
    public ?int $startedAt = null;
    public ?int $lastObservedAt = null;

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
}
