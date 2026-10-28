<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\TimeConstants;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\StatusComponentState;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Status\StatusSnapshot;

/** Temporary typed read boundary for the Status overview's data section. */
final readonly class StatusOverviewData
{
    public const string DATA = 'status';
    public const string healthKnown = 'healthKnown';
    public const string components = 'components';
    public const string operationalCount = 'operationalCount';
    public const string totalCount = 'totalCount';
    public const string publishDelaySeconds = 'publishDelaySeconds';
    public const string maintenance = 'maintenance';
    public const string phase = 'phase';
    public const string operation = 'operation';
    public const string startedAt = 'startedAt';
    public const string incidents = 'incidents';
    public const string subscribers = 'subscribers';

    /**
     * @param bool $healthKnown All component verdicts and DB facts are available
     * @param int $operationalCount Components strictly operational
     * @param int $totalCount Declared components, including the Service fallback
     * @param ?int $publishDelaySeconds Current setting, or null when reading it is unavailable
     * @param ?string $maintenancePhase Occupied protected-mode phase, or null when inactive
     * @param ?string $maintenanceOperation Current protected operation, or null
     * @param ?int $maintenanceStartedAt Start epoch milliseconds UTC, or null
     * @param ?list<StatusIncidentData> $incidents Open facts in catalog order, or null when unavailable
     * @param ?StatusSubscriberCountsData $subscribers Current subscriber counts, or null when unavailable
     */
    public function __construct(
        public bool $healthKnown,
        public int $operationalCount,
        public int $totalCount,
        public ?int $publishDelaySeconds,
        public ?string $maintenancePhase,
        public ?string $maintenanceOperation,
        public ?int $maintenanceStartedAt,
        public ?array $incidents,
        public ?StatusSubscriberCountsData $subscribers,
    ) {
    }

    /**
     * Readers declare interest in counts, incidents, their updates, settings and protected mode.
     * Freeze silence prevents DB and setting reads; no source is retained between calls.
     *
     * @return self One complete overview projection
     * @throws HilosException When source declarations or catalog metadata are invalid
     * @throws SettingException When the publication setting's catalog contract is invalid
     */
    public static function read(): self
    {
        $snapshot = StatusSnapshot::read();
        $known = $snapshot->incidents !== null;
        $operational = 0;
        $incidents = $snapshot->incidents === null ? null : [];
        foreach ($snapshot->components as $component) {
            $known = $known && $component->state !== StatusComponentState::UNKNOWN;
            $operational += (int) ($component->state === StatusComponentState::OPERATIONAL);
            if ($incidents !== null && $component->incident !== null) {
                try {
                    $incidents[] = StatusIncidentData::fromIncident($component->incident, $component);
                } catch (DatabaseException) {
                    $incidents = null;
                    $known = false;
                }
            }
        }

        $delay = null;
        if (Hilos::$db !== null && Hilos::$setting !== null && Hilos::$rt?->hilosProtectedModeRuntime !== null
            && !Hilos::$rt->hilosProtectedModeRuntime->silencesUnstoppedWriters()) {
            try {
                $delay = Hilos::$setting[StatusSettingsCatalog::PUBLISH_DELAY_SECONDS]->int();
            } catch (DatabaseException) {
                $delay = null;
            }
        }
        $maintenance = Hilos::$rt?->hilosProtectedModeRuntime !== null
            && Hilos::$rt->hilosProtectedModeRuntime->phase !== ProtectedModeRuntime::PHASE_INACTIVE;

        return new self(
            $known, $operational, count($snapshot->components), $delay,
            $maintenance ? Hilos::$rt->hilosProtectedModeRuntime->phase : null,
            $maintenance ? Hilos::$rt->hilosProtectedModeRuntime->operation : null,
            $maintenance && Hilos::$rt->hilosProtectedModeRuntime->startedAt !== null
                ? Hilos::$rt->hilosProtectedModeRuntime->startedAt * TimeConstants::MS_PER_SECOND : null,
            $incidents,
            StatusSubscriberCountsData::read(),
        );
    }

    /** @return array<string, WireField> Fields inside status, ready for the page's nested declaration */
    public static function wireFields(): array
    {
        return [
            self::healthKnown => WireField::notPersonal(),
            self::components => WireField::each([
                self::operationalCount => WireField::notPersonal(), self::totalCount => WireField::notPersonal(),
            ]),
            self::publishDelaySeconds => WireField::setting(StatusSettingsCatalog::PUBLISH_DELAY_SECONDS),
            self::maintenance => WireField::each([
                self::phase => WireField::notPersonal(), self::operation => WireField::notPersonal(), self::startedAt => WireField::notPersonal(),
            ]),
            self::incidents => WireField::each(StatusIncidentData::wireFields()),
            self::subscribers => WireField::each(StatusSubscriberCountsData::wireFields()),
        ];
    }

    /** @return array<string, mixed> Overview body with null distinct from known empty facts */
    public function toArray(): array
    {
        return [
            self::healthKnown => $this->healthKnown,
            self::components => [self::operationalCount => $this->operationalCount, self::totalCount => $this->totalCount],
            self::publishDelaySeconds => $this->publishDelaySeconds,
            self::maintenance => $this->maintenancePhase === null ? null : [
                self::phase => $this->maintenancePhase, self::operation => $this->maintenanceOperation,
                self::startedAt => $this->maintenanceStartedAt,
            ],
            self::incidents => $this->incidents === null ? null
                : array_map(static fn (StatusIncidentData $incident): array => $incident->toArray(), $this->incidents),
            self::subscribers => $this->subscribers?->toArray(),
        ];
    }
}
