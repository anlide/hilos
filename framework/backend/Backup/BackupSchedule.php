<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\BackupScheduleException;
use Hilos\Hilos;

/**
 * BackupSchedule - the project's backup schedule, read from the backup catalog.
 *
 * A project declares its schedule as a list of {name, cron, scope, mechanism} entries under
 * the {@see BackupConstants::CATALOG_SCHEDULE} key of its backup catalog. An absent or empty
 * schedule (or no backup catalog at all) falls back to {@see default()}: a single daily full
 * backup on the agent mechanism, so activating backups needs zero schedule wiring.
 *
 * The schedule is partitioned by mechanism ({@see agentEntries()}/{@see daemonEntries()}) so
 * each entry is evaluated exactly once: the agent-mechanism entries drive the backup agent's
 * own cron rules, the daemon-mechanism entries drive the daemon's cron rules, and both
 * converge on the one guarded create path. {@see scopeForName()} maps a fired daemon cron
 * name back to its scope.
 */
final class BackupSchedule
{
    /**
     * @param list<BackupScheduleEntry> $entries Validated schedule entries in declaration order
     */
    private function __construct(private readonly array $entries)
    {
    }

    /**
     * Builds the schedule from the active project's backup catalog, or the default when absent.
     *
     * Reads {@see BackupConstants::CATALOG_SCHEDULE} from the catalog named by
     * {@see Hilos::getBackupCatalogClass()}. A missing catalog, a missing schedule section, or
     * an empty schedule yields {@see default()}.
     *
     * @return self Schedule over the declared entries, or the default single-entry schedule
     * @throws BackupScheduleException When a declared entry is malformed or a name is duplicated
     */
    public static function fromCatalog(): self
    {
        $catalogClass = Hilos::getBackupCatalogClass();
        if ($catalogClass === null) {
            return self::default();
        }

        $schedule = $catalogClass::getCatalog()[BackupConstants::CATALOG_SCHEDULE] ?? null;
        if (!is_array($schedule) || $schedule === []) {
            return self::default();
        }

        return self::fromArray($schedule);
    }

    /**
     * Builds and validates the schedule from a raw list of catalog schedule rows.
     *
     * @param list<array<string, mixed>> $rawEntries Raw catalog schedule rows
     * @return self Schedule over the validated entries
     * @throws BackupScheduleException When an entry is malformed or a name is duplicated
     */
    public static function fromArray(array $rawEntries): self
    {
        $entries = [];
        $seen = [];
        foreach ($rawEntries as $raw) {
            if (!is_array($raw)) {
                throw new BackupScheduleException('Backup schedule entry is not an array');
            }

            $entry = BackupScheduleEntry::fromArray($raw);
            if (isset($seen[$entry->name])) {
                throw new BackupScheduleException("Duplicate backup schedule entry name '{$entry->name}'");
            }
            $seen[$entry->name] = true;
            $entries[] = $entry;
        }

        return new self($entries);
    }

    /**
     * The default schedule: one daily full backup at 03:00 server time on the agent mechanism.
     *
     * @return self Single-entry default schedule
     */
    public static function default(): self
    {
        return new self([
            new BackupScheduleEntry(
                BackupConstants::DEFAULT_SCHEDULE_NAME,
                BackupConstants::DEFAULT_SCHEDULE_CRON,
                BackupScope::FULL,
                BackupScheduleMechanism::AGENT,
            ),
        ]);
    }

    /**
     * @return list<BackupScheduleEntry> All schedule entries in declaration order
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<BackupScheduleEntry> Entries owned by the agent mechanism
     */
    public function agentEntries(): array
    {
        return $this->entriesForMechanism(BackupScheduleMechanism::AGENT);
    }

    /**
     * @return list<BackupScheduleEntry> Entries owned by the daemon mechanism
     */
    public function daemonEntries(): array
    {
        return $this->entriesForMechanism(BackupScheduleMechanism::DAEMON);
    }

    /**
     * Resolves the scope of the entry with the given name, or null when no entry matches.
     *
     * @param string $name Schedule entry name (e.g. a fired daemon cron name)
     * @return ?BackupScope Scope of the matching entry, or null when unknown
     */
    public function scopeForName(string $name): ?BackupScope
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return $entry->scope;
            }
        }

        return null;
    }

    /**
     * @param BackupScheduleMechanism $mechanism Mechanism to filter by
     * @return list<BackupScheduleEntry> Entries owned by that mechanism, in declaration order
     */
    private function entriesForMechanism(BackupScheduleMechanism $mechanism): array
    {
        $filtered = [];
        foreach ($this->entries as $entry) {
            if ($entry->mechanism === $mechanism) {
                $filtered[] = $entry;
            }
        }

        return $filtered;
    }
}
