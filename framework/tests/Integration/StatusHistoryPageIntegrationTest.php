<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Pages\Status\AbstractHilosStatusHistoryPage;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Status\DTO\StatusHistoryData;
use Hilos\Status\DTO\StatusOverviewData;
use Hilos\Status\StatusSeverity;
use Hilos\Tables\Status\HilosStatusHistoryTable;
use Hilos\Tests\Fixtures\StatusWorksEnvironment;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;

/** Production page, real commits, worker resends and held windows require the ordinary lifecycle. */
final class StatusHistoryPageIntegrationTest extends FrameworkIntegrationTestCase
{
    private StatusWorksEnvironment $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = new StatusWorksEnvironment();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->env)) {
                $this->env->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testFirstAnswerCarriesTheCompleteHistoricalCatalogAndTheInitialWindow(): void
    {
        $this->completedIncident('retired');
        $this->env->asOwner(fn () => Database::transaction('primary', static fn () => Hilos::$db->statusMaintenancePeriods->actions->import(
            str_repeat('a', 32), null, null, false, 'Saved maintenance', ['retired-work', 'service'], 1000, 2000, null, 3000)));
        $this->env->open(HilosPageConstants::HILOS_STATUS_HISTORY);
        $pages = $this->pages($this->env->drain());
        self::assertCount(1, $pages);
        $payload = $pages[0]->data->data->payload;
        self::assertSame(['components' => [
            ['componentKey' => 'service', 'name' => 'Service'], ['componentKey' => 'mail', 'name' => 'Mail'],
            ['componentKey' => 'retired', 'name' => 'retired'], ['componentKey' => 'retired-work', 'name' => 'retired-work'],
        ]], $payload->data['statusHistory']);
        self::assertSame([], $payload->refusedWindows);
        self::assertCount(2, $payload->windows[HilosStatusHistoryTable::TABLE]['rows']);
        self::assertSame(['Saved maintenance', 'Saved component'], array_map(
            static fn (array $row): string => $row['slots']['history']['title'], $payload->windows[HilosStatusHistoryTable::TABLE]['rows']));
        self::assertSame(HilosPageConstants::HILOS_STATUS, $payload->data['pageHeadOwner']['page']);
        self::assertSame(['page' => HilosPageConstants::HILOS_STATUS_HISTORY, 'label' => 'History'],
            $payload->data['pageTabs'][array_key_last($payload->data['pageTabs'])]);
        foreach ([HilosDbContext::statusIncidents, HilosDbContext::statusMaintenancePeriods] as $source) {
            self::assertContains($source, SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::page('ak-status'), SourceChange::KIND_DB));
        }
        self::assertContains(ProtectedModeRuntime::RT_ITEM,
            SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::page('ak-status'), SourceChange::KIND_RT));
    }

    public function testViewerReceivesTheSameNonpersonalCatalogAndHistoryRows(): void
    {
        $this->completedIncident('service');
        $expected = new HilosStatusHistoryTable()->getPage(new TableQueryDTO())->rows[0]->toArray();
        $this->env->runtime->viewer();
        Hilos::appClass()::initBrowser(new IdentityTestBrowser(5, false));
        $this->env->open(HilosPageConstants::HILOS_STATUS_HISTORY);
        $pages = $this->pages($this->env->drain());
        self::assertCount(1, $pages);
        $payload = $pages[0]->data->data->payload;
        self::assertTrue(Hilos::$browser->isAdminViewModeViewer(AbstractHilosStatusHistoryPage::class, 'ak-status'));
        self::assertSame(StatusHistoryData::read()->toArray(), $payload->data['statusHistory']);
        self::assertSame($expected, $payload->windows[HilosStatusHistoryTable::TABLE]['rows'][0]['slots']['history']);
        self::assertSame([], AbstractHilosStatusHistoryPage::ACTIONS);
    }

    public function testOverviewCountsAllCompletedFactsAboveTheViewportCeiling(): void
    {
        self::assertSame(0, StatusOverviewData::read()->historyCount);
        $this->env->asOwner(fn () => Database::transaction('primary', static function (): void {
            for ($i = 0; $i < 501; ++$i) {
                $incident = Hilos::$db->statusIncidents->actions->candidate('retired-' . $i, 'Saved', StatusSeverity::PARTIAL_OUTAGE, 1000, 2000);
                $incident->actions->resolve(3000);
            }
            Hilos::$db->statusMaintenancePeriods->actions->import(
                str_repeat('a', 32), null, null, false, 'Saved', ['mail'], 1000, 2000, null, 3000);
        }));
        $this->env->open(HilosPageConstants::HILOS_STATUS, 'ak-overview');
        $pages = $this->pages($this->env->drain());
        self::assertCount(1, $pages);
        self::assertSame(502, $pages[0]->data->data->payload->data['status']['historyCount']);
    }

    public function testBothCompletionsRefreshPageDataWithoutApplyingPendingMembership(): void
    {
        $this->completedIncident('service');
        $this->flush();
        $this->env->open(HilosPageConstants::HILOS_STATUS_HISTORY);
        $this->env->open(HilosPageConstants::HILOS_STATUS, 'ak-overview');
        $this->env->drain();
        $held = Hilos::$sr->getTableViewport('ak-status', HilosStatusHistoryTable::TABLE);
        $first = $held->firstAnchor();
        $last = $held->lastAnchor();
        $incident = $this->env->asOwner(fn () => Database::transaction('primary', static fn () => Hilos::$db->statusIncidents->actions->candidate(
            'new-retired', 'Saved second', StatusSeverity::MAJOR_OUTAGE, 4000, 5000)));
        $this->flush();
        $this->env->asOwner(fn () => Database::transaction('primary', static fn () => $incident->actions->resolve(6000)));
        $frames = $this->flush();
        $this->assertPendingDelivery($frames, 'incident:' . $incident->id, 2, 'new-retired');
        self::assertSame($held, Hilos::$sr->getTableViewport('ak-status', HilosStatusHistoryTable::TABLE));
        self::assertSame($first, $held->firstAnchor());
        self::assertSame($last, $held->lastAnchor());
        self::assertFalse($held->hasRow('incident:' . $incident->id));

        $period = $this->env->asOwner(fn () => Database::transaction('primary', static fn () => Hilos::$db->statusMaintenancePeriods->actions->import(
            str_repeat('b', 32), null, null, false, 'Saved period', ['new-period'], 7000, 7000, null, null)));
        $this->flush();
        $this->env->asOwner(fn () => Database::transaction('primary', static fn () => $period->actions->reconcile(8000, null, 9000)));
        $frames = $this->flush();
        $this->assertPendingDelivery($frames, 'maintenance:' . $period->id, 3, 'new-period');
        self::assertFalse($held->hasRow('maintenance:' . $period->id));
        self::assertFalse($held->hasRow('incident:' . $incident->id));
        self::assertSame($first, $held->firstAnchor());
        self::assertSame($last, $held->lastAnchor());
        self::assertSame([], $this->flush());
    }

    public function testOverviewAndCatalogDoNotInheritTheHistoryWindowFilter(): void
    {
        $this->env->open(HilosPageConstants::HILOS_STATUS_HISTORY);
        $this->env->open(HilosPageConstants::HILOS_STATUS, 'ak-overview');
        $this->env->drain();
        $this->flush();
        $this->env->gatekeeper->dispatchTableViewport(new WebSocketTableViewportSignalDTO(
            'ak-status', HilosPageConstants::HILOS_STATUS_HISTORY, HilosStatusHistoryTable::TABLE,
            filter: ['componentKey' => 'service'], limit: 2), 'websocket', HilosPageConstants::HILOS_STATUS_HISTORY);
        $this->env->drain();
        $this->completedIncident('retired');
        $frames = $this->flush();
        $pages = $this->pages($frames);
        self::assertCount(2, $pages);
        foreach ($pages as $page) {
            $reply = $page->data->data;
            if ($reply->pageKey === HilosPageConstants::HILOS_STATUS_HISTORY) {
                self::assertContains('retired', array_column($reply->payload->data['statusHistory']['components'], 'componentKey'));
                self::assertSame([], $reply->payload->windows);
            } else {
                self::assertSame(1, $reply->payload->data['status']['historyCount']);
            }
        }
        self::assertSame([], array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_ANNOUNCE));
        self::assertSame(0, Hilos::$sr->getTableViewport('ak-status', HilosStatusHistoryTable::TABLE)->totalCount());
    }

    public function testSourceFactsAndDatabaseBackCoalesceIntoOnePageDataDelivery(): void
    {
        $this->env->open(HilosPageConstants::HILOS_STATUS_HISTORY);
        $this->env->drain();
        $this->flush();
        Hilos::$browser->record(SourceChange::dbUpdated(HilosDbContext::statusIncidents, '9999', []));
        Hilos::$browser->record(SourceChange::dbUpdated(HilosDbContext::statusMaintenancePeriods, '9999', []));
        Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
        Hilos::$browser->recordDatabaseBack();
        $frames = $this->flush();
        self::assertCount(1, $this->pages($frames));
        self::assertSame([], array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::TABLE_WINDOW));
    }

    public function testFreezeMissingRuntimeAndDatabaseOutageKeepBothProjectionsUnavailable(): void
    {
        $this->completedIncident('retired');
        foreach ([ProtectedModeRuntime::PHASE_ACTIVE, ProtectedModeRuntime::PHASE_ACTIVATING, null] as $phase) {
            $this->env->runtime->phase($phase);
            Database::close();
            try {
                self::assertNull(StatusHistoryData::read());
                self::assertNull(StatusOverviewData::read()->historyCount);
            } finally {
                Database::connect();
            }
        }
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        self::assertSame(1, StatusOverviewData::read()->historyCount);
        self::assertContains('retired', array_column(StatusHistoryData::read()->components, 'componentKey'));
    }

    /** @return iterable<string, array{string}> Neither successful half may stand in for the whole */
    public static function sources(): iterable
    {
        yield 'incidents' => [HilosDbContext::statusIncidents];
        yield 'maintenance' => [HilosDbContext::statusMaintenancePeriods];
    }

    /** @param string $source Source whose delivery has not arrived */
    #[DataProvider('sources')]
    public function testEitherUnreadySourceMakesTheCompleteCatalogAndCountUnknown(string $source): void
    {
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->env->owner->getId()));
        foreach ([SourceChange::KIND_DB => AbstractHilosStatusPage::READS_DB,
            SourceChange::KIND_RT => AbstractHilosStatusPage::READS_RT] as $kind => $keys) {
            foreach ($keys as $key) {
                SourceInterestRegistry::register($kind, $key, SourceConsumer::page('ak-status'));
                if ($kind !== SourceChange::KIND_DB || $key !== $source) {
                    SourceInterestRegistry::markReady($kind, $key);
                }
            }
        }
        SourceInterestRegistry::readsWhatIsDelivered();
        try {
            self::assertNull(StatusHistoryData::read());
            self::assertNull(StatusOverviewData::read()->historyCount);
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $source);
            $this->env->worker->pageInterests(HilosPageConstants::HILOS_STATUS_HISTORY, 'ak-status');
            self::assertSame(0, StatusOverviewData::read()->historyCount);
            self::assertSame(['service', 'mail'], array_column(StatusHistoryData::read()->components, 'componentKey'));
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
        }
    }

    public function testOutagePublishesNullAndDatabaseBackRecoversWithoutReplacingAHeldWindow(): void
    {
        $this->env->open(HilosPageConstants::HILOS_STATUS_HISTORY);
        $this->env->open(HilosPageConstants::HILOS_STATUS, 'ak-overview');
        $this->env->drain();
        $this->flush();
        Database::close();
        try {
            $this->env->gatekeeper->dispatchTableViewport(new WebSocketTableViewportSignalDTO(
                'ak-status', HilosPageConstants::HILOS_STATUS_HISTORY, HilosStatusHistoryTable::TABLE, limit: 2),
                'websocket', HilosPageConstants::HILOS_STATUS_HISTORY);
            self::assertSame([SignalTypeConstants::TABLE_WINDOW_REFUSED],
                array_map(static fn (SignalDTO $frame): string => $frame->signalName->getName(), $this->env->drain()));
            Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
            $pages = $this->pages($this->flush());
            self::assertCount(2, $pages);
            foreach ($pages as $page) {
                $reply = $page->data->data;
                self::assertNull($reply->pageKey === HilosPageConstants::HILOS_STATUS_HISTORY
                    ? $reply->payload->data['statusHistory'] : $reply->payload->data['status']['historyCount']);
            }
        } finally {
            Database::connect();
        }
        Hilos::$browser->recordDatabaseBack();
        $frames = $this->flush();
        self::assertCount(2, $this->pages($frames));
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak-status', HilosStatusHistoryTable::TABLE));
        self::assertSame(0, StatusOverviewData::read()->historyCount);
        self::assertSame([], $this->flush());
    }

    public function testDeniedGuardEmitsNeitherPageDataNorHistoryRows(): void
    {
        $this->env->open(HilosPageConstants::HILOS_STATUS_HISTORY);
        $this->env->drain();
        $this->flush();
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
        Hilos::$browser->record(SourceChange::dbUpdated(HilosDbContext::statusIncidents, '9999', []));
        self::assertSame([], $this->flush());
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
        self::assertCount(1, $this->pages($this->flush()));
    }

    /** @param string $key Historical component key */
    private function completedIncident(string $key): void
    {
        $this->env->asOwner(fn () => Database::transaction('primary', static function () use ($key): void {
            $incident = Hilos::$db->statusIncidents->actions->candidate($key, 'Saved component', StatusSeverity::PARTIAL_OUTAGE, 1000, 2000);
            $incident->actions->resolve(3000);
        }));
    }

    /** @return list<SignalDTO> Flushed frames with DB transport consumed by the fixture */
    private function flush(): array
    {
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        return $this->env->drain();
    }

    /**
     * @param list<SignalDTO> $frames All queued frames
     * @return list<SignalDTO> Ordinary page responses
     */
    private function pages(array $frames): array
    {
        return array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE));
    }

    /**
     * @param list<SignalDTO> $frames Completion flush
     * @param string $rowKey Pending row identity
     * @param int $count Exact completed count
     * @param string $key New historical filter key
     */
    private function assertPendingDelivery(array $frames, string $rowKey, int $count, string $key): void
    {
        $announcements = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_ANNOUNCE));
        self::assertCount(1, $announcements);
        self::assertSame($rowKey, $announcements[0]->data->data->rowKey);
        $pages = $this->pages($frames);
        self::assertCount(2, $pages);
        foreach ($pages as $page) {
            $reply = $page->data->data;
            if ($reply->pageKey === HilosPageConstants::HILOS_STATUS_HISTORY) {
                self::assertSame([], $reply->payload->windows);
                self::assertSame(['statusHistory'], array_keys($reply->payload->data));
                self::assertContains($key, array_column($reply->payload->data['statusHistory']['components'], 'componentKey'));
            } else {
                self::assertSame($count, $reply->payload->data['status']['historyCount']);
            }
        }
        self::assertSame([], array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::TABLE_WINDOW));
    }
}
