<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Status\StatusComponentDefinition;

/** Current counts only; durable Status facts and deadlines belong to the incident tables. */
final class HilosStatusComponent extends RtState
{
    public const string RT_COLLECTION = 'hilosStatusComponents';

    public const string componentKey = 'componentKey';
    public const string expectedInstances = 'expectedInstances';
    public const string liveInstances = 'liveInstances';
    public const string observedAt = 'observedAt';

    private(set) string $componentKey;
    public int $expectedInstances;
    public ?int $liveInstances = null;
    public ?int $observedAt = null;

    /**
     * @param string $componentKey Stable component id
     * @param int $expectedInstances Expected slots, at least one
     * @return static Unknown component counts
     * @throws InvalidFormatException When the key or expected count is invalid
     */
    public static function create(string $componentKey, int $expectedInstances): static
    {
        self::validate($componentKey, $expectedInstances, null);
        $instance = new static();
        $instance->componentKey = $componentKey;
        $instance->expectedInstances = $expectedInstances;
        $instance->markRtSyncBaseline();
        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized current counts
     * @return static Hydrated counts without invented observations
     * @throws InvalidFormatException When fields have invalid types or counts
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->componentKey = self::requireString($row, self::componentKey);
        $instance->expectedInstances = self::requireInt($row, self::expectedInstances);
        $instance->liveInstances = self::optionalInt($row, self::liveInstances);
        $instance->observedAt = self::optionalInt($row, self::observedAt);
        self::validate($instance->componentKey, $instance->expectedInstances, $instance->liveInstances);
        $instance->markRtSyncBaseline();
        return $instance;
    }

    /** @return string Runtime collection key */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /** @return string Immutable component key */
    public function getId(): string
    {
        return $this->componentKey;
    }

    /** @return array<string, mixed> The four-field runtime sync row */
    public function toArray(): array
    {
        return [
            self::componentKey => $this->componentKey,
            self::expectedInstances => $this->expectedInstances,
            self::liveInstances => $this->liveInstances,
            self::observedAt => $this->observedAt,
        ];
    }

    /**
     * @param array<string, mixed> $diff Changed fields only
     * @throws InvalidFormatException When the key changes or counts/types are invalid
     */
    public function applyDiff(array $diff): void
    {
        $componentKey = self::patchString($diff, self::componentKey, $this->componentKey);
        $expectedInstances = self::patchInt($diff, self::expectedInstances, $this->expectedInstances);
        $liveInstances = self::patchOptionalInt($diff, self::liveInstances, $this->liveInstances);
        $observedAt = self::patchOptionalInt($diff, self::observedAt, $this->observedAt);
        if ($componentKey !== $this->componentKey) {
            throw new InvalidFormatException('A Status component key cannot change.');
        }
        self::validate($componentKey, $expectedInstances, $liveInstances);
        $this->expectedInstances = $expectedInstances;
        $this->liveInstances = $liveInstances;
        $this->observedAt = $observedAt;
    }

    /**
     * @param string $componentKey Stable component key
     * @param int $expectedInstances Expected slots
     * @param ?int $liveInstances Observed live slots, when known
     * @throws InvalidFormatException When a key or count is outside its domain
     */
    private static function validate(string $componentKey, int $expectedInstances, ?int $liveInstances): void
    {
        if (preg_match(StatusComponentDefinition::KEY_PATTERN, $componentKey) !== 1
            || $expectedInstances < 1 || ($liveInstances !== null && $liveInstances < 0)) {
            throw new InvalidFormatException('Status counts require a valid component key, positive expected slots and nonnegative live slots.');
        }
    }
}
