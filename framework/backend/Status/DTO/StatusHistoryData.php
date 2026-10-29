<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\Hilos;
use Hilos\HilosException;

/** One short-lived complete filter catalog over current and completed historical components. */
final readonly class StatusHistoryData
{
    public const string DATA = 'statusHistory';
    public const string components = 'components';
    public const string componentKey = 'componentKey';
    public const string name = 'name';

    /** @param list<array{componentKey: string, name: string}> $components Stable deduplicated filter entries */
    public function __construct(public array $components)
    {
    }

    /**
     * @return ?self Complete catalog, or null when either historical source cannot be read
     * @throws HilosException When source declarations or current catalog contracts are invalid
     */
    public static function read(): ?self
    {
        if (Hilos::$db === null || Hilos::$rt?->hilosProtectedModeRuntime === null
            || Hilos::$rt->hilosProtectedModeRuntime->silencesUnstoppedWriters()) {
            return null;
        }
        foreach ([HilosDbContext::statusIncidents, HilosDbContext::statusMaintenancePeriods] as $source) {
            if (SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, $source)
                && !SourceInterestRegistry::isReady(SourceChange::KIND_DB, $source)) {
                return null;
            }
        }
        try {
            $historical = [...Hilos::$db->statusIncidents->historyComponentKeys(), ...Hilos::$db->statusMaintenancePeriods->historyComponentKeys()];
        } catch (DatabaseException | CollectionNotFullyLoadedException) {
            return null;
        }
        $components = [];
        foreach (Hilos::statusComponents() as $component) {
            $components[$component->componentKey] = [self::componentKey => $component->componentKey, self::name => $component->name];
        }
        sort($historical, SORT_STRING);
        foreach ($historical as $key) {
            $components[$key] ??= [self::componentKey => $key, self::name => $key];
        }
        return new self(array_values($components));
    }

    /** @return array<string, WireField> Nonpersonal component filter entries */
    public static function wireFields(): array
    {
        return [self::components => WireField::each([
            self::componentKey => WireField::notPersonal(), self::name => WireField::notPersonal(),
        ])];
    }

    /** @return array<string, mixed> Complete first-response filter catalog */
    public function toArray(): array
    {
        return [self::components => $this->components];
    }
}
