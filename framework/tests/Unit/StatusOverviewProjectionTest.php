<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\AdminViewMode\WireField;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\Entity\Item\StatusIncidentUpdate as EntityStatusIncidentUpdate;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\HilosStatusMaintenance;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\DTO\StatusOverviewData;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Tests\Unit\Status\StatusFallbackHilos;
use Hilos\Tests\Unit\Status\StatusReadTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Browser read projection keeps unavailable, empty, unknown and hidden facts distinct. */
final class StatusOverviewProjectionTest extends StatusReadTestCase
{
    public function testKnownEmptyFactsAndStrictOperationalCount(): void
    {
        $this->observe('zeta', 2);
        $this->observe('alpha', 2);
        self::assertSame([
            'healthKnown' => true,
            'components' => ['operationalCount' => 2, 'totalCount' => 2],
            'publishDelaySeconds' => 300,
            'maintenance' => null,
            'incidents' => [],
            'subscribers' => ['confirmedCount' => 0, 'pendingCount' => 0],
            'historyCount' => 0,
            'scheduledWorks' => null,
        ], StatusOverviewData::read()->toArray());
        self::assertSame([EntityStatusIncident::active_component_key, ['zeta', 'alpha']], $this->database->statusIncidents->selection);
    }

    public function testHistoryCountHasNoViewportCeilingAndBothSourcesMustBeAvailable(): void
    {
        self::assertSame(0, StatusOverviewData::read()->historyCount);
        $this->database->statusIncidents->completed = 501;
        $this->database->statusMaintenancePeriods->completed = 207;
        self::assertSame(708, StatusOverviewData::read()->historyCount);
        $this->database->statusIncidents->unavailable = true;
        self::assertNull(StatusOverviewData::read()->historyCount);
        $this->database->statusIncidents->unavailable = false;
        $this->database->statusMaintenancePeriods->unavailable = true;
        self::assertNull(StatusOverviewData::read()->historyCount);
        $this->database->statusMaintenancePeriods->unavailable = false;
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $reads = [$this->database->statusIncidents->historyReads, $this->database->statusMaintenancePeriods->historyReads];
        self::assertNull(StatusOverviewData::read()->historyCount);
        self::assertSame($reads, [$this->database->statusIncidents->historyReads, $this->database->statusMaintenancePeriods->historyReads]);
    }

    public function testMissingAndStaleCountsAreUnknownWithoutLosingAvailableIncidents(): void
    {
        $this->observe('zeta', 0);
        $this->incident(5, 'zeta', 1700000000050);
        $overview = StatusOverviewData::read()->toArray();
        self::assertFalse($overview['healthKnown']);
        self::assertSame(['operationalCount' => 0, 'totalCount' => 2], $overview['components']);
        self::assertSame(5, $overview['incidents'][0]['incidentId']);
        $this->observe('alpha', 2);
        self::assertTrue(StatusOverviewData::read()->healthKnown);
        RtStaleness::mark(HilosStatusComponent::RT_COLLECTION, ['alpha'], 10.0);
        self::assertFalse(StatusOverviewData::read()->healthKnown);
        self::assertSame(0, StatusOverviewData::read()->operationalCount);
        self::assertCount(1, StatusOverviewData::read()->incidents);
    }

    public function testUnavailableFactsAreNullAndNeverCountAsHealthy(): void
    {
        $this->observe('zeta', 2);
        $this->observe('alpha', 2);
        $this->database->statusIncidents->unavailable = true;
        $overview = StatusOverviewData::read()->toArray();
        self::assertNull($overview['incidents']);
        self::assertFalse($overview['healthKnown']);
        self::assertSame(0, $overview['components']['operationalCount']);
        Hilos::$db = null;
        self::assertNull(StatusOverviewData::read()->publishDelaySeconds);
        self::assertNull(StatusOverviewData::read()->incidents);
        Hilos::$rt = null;
        self::assertFalse(StatusOverviewData::read()->healthKnown);
    }

    public function testOnlyActiveCatalogFactsAndTheirOwnTimelinesTravelInDeclarationOrder(): void
    {
        $this->observe('alpha', 0);
        $this->observe('zeta', 1);
        $this->incident(10, 'alpha', 1700000000050);
        $first = $this->incident(20, 'zeta', 1700000000040);
        $first->severity = 'partial_outage';
        $this->incident(30, 'zeta', active: false);
        $this->incident(40, 'foreign', 1700000000060);
        $this->update(1, 20, 1700000000100, 'published', 'Partial outage.');
        $this->update(8, 20, 1700000000200);
        $this->update(9, 20, 1700000000200);
        $this->update(11, 10, 1700000000300, 'severity_changed');
        $this->update(12, 30, 1700000000400, 'resolved');
        $this->update(13, 40, 1700000000500);
        $overview = StatusOverviewData::read()->toArray();
        self::assertTrue($overview['healthKnown']);
        self::assertSame([20, 10], array_column($overview['incidents'], 'incidentId'));
        self::assertSame([
            'incidentId' => 20, 'componentKey' => 'zeta', 'componentName' => 'Stored zeta', 'agentType' => 'hilos_index',
            'severity' => 'partial_outage', 'detectedAt' => 1700000000000, 'publishAt' => 1700000300000,
            'publishedAt' => 1700000000040, 'suppressedAt' => null, 'expectedInstances' => 2, 'liveInstances' => 1,
            'observedAt' => 1700000000123, 'updates' => [
                ['id' => 9, 'kind' => 'team', 'severity' => 'major_outage', 'text' => 'Team <text>', 'at' => 1700000000200],
                ['id' => 8, 'kind' => 'team', 'severity' => 'major_outage', 'text' => 'Team <text>', 'at' => 1700000000200],
                ['id' => 1, 'kind' => 'published', 'severity' => 'major_outage', 'text' => 'Partial outage.', 'at' => 1700000000100],
            ],
        ], $overview['incidents'][0]);
        self::assertSame([11], array_column($overview['incidents'][1]['updates'], 'id'));
    }

    public function testSuppressedCandidateKeepsItsFactsAndOriginalDeadline(): void
    {
        $this->observe('zeta', 0);
        $this->observe('alpha', 2);
        $this->incident(7, 'zeta', suppressedAt: 1700000000020);
        $overview = StatusOverviewData::read();
        self::assertTrue($overview->healthKnown);
        self::assertSame(1, $overview->operationalCount);
        self::assertSame([], $overview->incidents[0]->updates);
        self::assertNull($overview->incidents[0]->publishedAt);
        self::assertSame(1700000000020, $overview->incidents[0]->suppressedAt);
        self::assertSame(1700000300000, $overview->incidents[0]->publishAt);
        Hilos::$setting = null;
        self::assertNull(StatusOverviewData::read()->publishDelaySeconds);
        self::assertSame(1700000300000, StatusOverviewData::read()->incidents[0]->publishAt);
    }

    /** @return iterable<string, array{string, bool}> Freeze phases and whether DB facts are silenced */
    public static function freezePhases(): iterable
    {
        yield 'activating' => [ProtectedModeRuntime::PHASE_ACTIVATING, true];
        yield 'active' => [ProtectedModeRuntime::PHASE_ACTIVE, true];
        yield 'verifying' => [ProtectedModeRuntime::PHASE_VERIFYING, false];
        yield 'deactivating' => [ProtectedModeRuntime::PHASE_DEACTIVATING, false];
    }

    /**
     * @param string $phase Current freeze phase
     * @param bool $silenced Whether DB reads are prohibited
     */
    #[DataProvider('freezePhases')]
    public function testMaintenanceExposesOnlyItsPublicFactsAndConvertsSecondsOnce(string $phase, bool $silenced): void
    {
        $this->observe('zeta', 2);
        $this->observe('alpha', 2);
        $this->incident(3, 'zeta', 1700000000050);
        $this->runtime->phase($phase, 1700000000, ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE);
        $overview = StatusOverviewData::read()->toArray();
        self::assertSame(['phase' => $phase, 'operation' => 'manual_maintenance',
            'startedAt' => 1700000000000, 'announcement' => null], $overview['maintenance']);
        self::assertSame(0, $overview['components']['operationalCount']);
        self::assertSame(!$silenced, $overview['healthKnown']);
        self::assertSame($silenced ? 0 : 1, $this->database->statusIncidents->reads);
        self::assertSame($silenced ? null : 300, $overview['publishDelaySeconds']);
        self::assertSame($silenced ? null : 3, $overview['incidents'][0]['incidentId'] ?? null);
    }

    public function testMaintenanceNullableFieldsAndFallbackServiceRemainExplicit(): void
    {
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, StatusFallbackHilos::class);
        self::assertSame(['operationalCount' => 0, 'totalCount' => 1], StatusOverviewData::read()->toArray()['components']);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_VERIFYING);
        self::assertSame(['phase' => 'verifying', 'operation' => null, 'startedAt' => null, 'announcement' => null],
            StatusOverviewData::read()->toArray()['maintenance']);
    }

    public function testAnnouncementDistinguishesUnavailableUnknownUnannouncedLinkedAndStaleWithoutReadingDb(): void
    {
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->database->statusIncidents->unavailable = true;
        self::assertNull(StatusOverviewData::read()->toArray()['maintenance']['announcement']);
        Hilos::$rt->hilosStatusMaintenance->actions->ensure();
        Hilos::$rt->hilosStatusMaintenance[HilosStatusMaintenance::CURRENT]->actions->update(available: true, active: true);
        self::assertSame(['known' => false, 'work' => null], StatusOverviewData::read()->toArray()['maintenance']['announcement']);
        Hilos::$rt->hilosStatusMaintenance[HilosStatusMaintenance::CURRENT]->actions->update(
            available: true, active: true, announcementKnown: true);
        self::assertSame(['known' => true, 'work' => null], StatusOverviewData::read()->toArray()['maintenance']['announcement']);
        Hilos::$rt->hilosStatusMaintenance[HilosStatusMaintenance::CURRENT]->actions->update(available: true, active: true, announcementKnown: true,
            workPublicId: str_repeat('a', 32), title: 'Upgrade', componentKeys: ['zeta'], startsAt: 10000, endsAt: 20000);
        self::assertSame(['known' => true, 'work' => ['id' => str_repeat('a', 32), 'title' => 'Upgrade',
            'description' => null, 'componentKeys' => ['zeta'], 'startsAt' => 10000, 'endsAt' => 20000]],
            StatusOverviewData::read()->toArray()['maintenance']['announcement']);
        RtStaleness::mark(HilosStatusMaintenance::RT_COLLECTION, [HilosStatusMaintenance::CURRENT], 1700000000);
        try {
            self::assertNull(StatusOverviewData::read()->toArray()['maintenance']['announcement']);
            self::assertNull(StatusOverviewData::read()->toArray()['scheduledWorks']);
            self::assertSame(0, $this->database->statusIncidents->reads);
        } finally {
            RtStaleness::clear(HilosStatusMaintenance::RT_COLLECTION, [HilosStatusMaintenance::CURRENT]);
        }
    }

    public function testViewerUsesColumnAndSettingVerdictsWithHiddenValuesDistinctFromNull(): void
    {
        $this->incident(3, 'zeta', 1700000000050);
        $this->update(4, 3, 1700000000060);
        $payload = [StatusOverviewData::DATA => StatusOverviewData::read()->toArray()];
        $fields = [StatusOverviewData::DATA => WireField::each(StatusOverviewData::wireFields())];
        $columnShown = static fn (string $collection, string $field): bool => in_array($field, match ($collection) {
            HilosDbContext::statusIncidents => EntityStatusIncident::_piiNotPersonal,
            HilosDbContext::statusIncidentUpdates => EntityStatusIncidentUpdate::_piiNotPersonal,
            default => [],
        }, true);
        $visible = ViewerFields::hide($payload, $fields, $columnShown,
            static fn (string $key): bool => Hilos::$setting->visibleInAdminViewMode($key));
        self::assertSame(300, $visible['status']['publishDelaySeconds']);
        self::assertSame(['confirmedCount' => 0, 'pendingCount' => 0], $visible['status']['subscribers']);
        self::assertSame(HiddenValue::mark(), $visible['status']['incidents'][0]['updates'][0]['text']);
        self::assertSame('Team <text>', $payload['status']['incidents'][0]['updates'][0]['text']);
        self::assertNull($visible['status']['maintenance']);
        self::assertNull($visible['status']['incidents'][0]['suppressedAt']);
        $hiddenSetting = ViewerFields::hide($payload, $fields, $columnShown, static fn (string $key): bool => false);
        self::assertSame(HiddenValue::mark(), $hiddenSetting['status']['publishDelaySeconds']);
        self::assertSame(StatusSettingsCatalog::PUBLISH_DELAY_SECONDS, StatusOverviewData::wireFields()['publishDelaySeconds']->settingKeys[0]);
        self::assertSame('text', StatusOverviewData::wireFields()['incidents']->fields['updates']->fields['text']->field);
    }
}
