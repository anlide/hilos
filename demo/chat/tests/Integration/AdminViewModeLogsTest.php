<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Chat\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Chat\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\Chat\Pages\Hilos\Logs\LogsViewPage;
use Demo\Chat\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Database;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsFollowStartSignalData;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogRecentEntry;
use Hilos\Log\LogWorkerSummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;
use Hilos\Pages\Logs\AbstractHilosLogsPage;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use Hilos\Pages\Logs\AbstractHilosLogsWorkersPage;
use Hilos\Pages\Logs\DTO\HilosLogsOverviewSignalData;
use Hilos\Pages\Logs\DTO\HilosLogsRotationsSignalData;
use Hilos\Pages\Logs\DTO\HilosLogsViewCatalogSignalData;
use Hilos\Pages\Logs\DTO\LogsFollowStartActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The five log screens and their reading actions under the real chat view-mode verdict (HIL-1257). */
final class AdminViewModeLogsTest extends IntegrationTestCase
{
    private const string TEST_AGENT = 'admin-view-mode-logs-test';
    private const string ANONYMOUS_KEY = 'admin-view-mode-logs-anonymous';
    private const string VISITOR_KEY = 'admin-view-mode-logs-visitor';
    private const string ADMIN_KEY = 'admin-view-mode-logs-admin';
    private const int T0 = 1_800_000_000;
    private const int BATCH = 1_799_000_000;

    /** @var list<int> People created by the fixture */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        $visitor = Hilos::$db->users->actions->createWithName('Log visitor');
        $admin = Hilos::$db->users->actions->createWithName('Log admin');
        $admin->actions->setAdmin(true);
        $this->userIds = [(int) $visitor->id, (int) $admin->id];
        Hilos::$rt->connections->actions->register(self::ANONYMOUS_KEY, null);
        Hilos::$rt->connections->actions->register(self::VISITOR_KEY, (int) $visitor->id);
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, (int) $admin->id);

        ClusterLogIndexMirror::applyPortion(ClusterLogIndexPortionSignalData::ofSlots([
            new ClusterLogNodeSlot('node-1', new NodeLogIndex(
                nodeId: 'node-1',
                available: true,
                sampledAt: self::T0,
                batches: [new LogBatchSummary(self::BATCH, 1, 100, 1, 200, 0, 0, 1, 300)],
                keys: [new LogKeySummary('worker-0.log', LogKeySummary::CLASS_WORKER, true, [self::BATCH], 200)],
                workers: [new LogWorkerSummary('worker-0.log', false, true, [self::BATCH], 200)],
                growthBytesPerDay: ['worker-0.log' => 20],
                logDirectory: '/var/log/hilos',
                recentErrors: [new LogRecentEntry(self::T0 * 1000, 'worker-0.log', 'private failure', 2)],
                recentWarnings: [new LogRecentEntry(self::T0 * 1000, 'worker-0.log', 'private warning', null)],
                filesystemFreeBytes: 1_000_000,
                filesystemTotalBytes: 2_000_000,
                freeSpaceThresholdPercent: 10,
            ), self::T0),
        ], true));
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY, self::ADMIN_KEY] as $acceptKey) {
            AbstractHilosLogsPage::removeSubscriber($acceptKey);
            AbstractHilosLogsKeysPage::removeSubscriber($acceptKey);
            AbstractHilosLogsWorkersPage::removeSubscriber($acceptKey);
            AbstractHilosLogsRotationsPage::removeSubscriber($acceptKey);
            AbstractHilosLogsViewPage::removeSubscriber($acceptKey);
        }
        ClusterLogIndexMirror::forgetPicture();
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::initBrowser();
        $this->drainSignals();
        Hilos::$sr = null;
        if ($this->userIds !== []) {
            Database::sqlRun('DELETE FROM hilos_session WHERE user_id IN (' . implode(',', $this->userIds) . ')');
            Database::sqlRun('DELETE FROM hilos_user WHERE id IN (' . implode(',', $this->userIds) . ')');
        }
        parent::tearDown();
    }

    public function testViewersSeeClusterFactsButNotLogTextOrSettingsAcrossTheScreens(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $overview = $this->frame($this->subscribe(LogsOverviewPage::class, $acceptKey), HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS);
            self::assertFalse(HiddenValue::isMark($overview[HilosLogsOverviewSignalData::available]));
            self::assertTrue(HiddenValue::isMark($overview[HilosLogsOverviewSignalData::freeSpaceThresholdPercent]));
            $node = $overview[HilosLogsOverviewSignalData::nodes][0];
            $recentError = $overview[HilosLogsOverviewSignalData::recentErrors][0];
            self::assertTrue(HiddenValue::isMark($node[HilosLogsOverviewSignalData::freeSpaceThresholdPercent]));
            self::assertTrue(HiddenValue::isMark($recentError[HilosLogsOverviewSignalData::message]));
            self::assertSame(2, $overview[HilosLogsOverviewSignalData::recentErrors][0][HilosLogsOverviewSignalData::traceFrames]);
            self::assertTrue(HiddenValue::isMark(
                $overview[HilosLogsOverviewSignalData::recentWarnings][0][HilosLogsOverviewSignalData::message],
            ));

            foreach ([
                [LogsKeysPage::class, ChatTableContext::hilosLogKeys],
                [LogsWorkersPage::class, ChatTableContext::hilosLogWorkers],
                [LogsRotationsPage::class, ChatTableContext::hilosLogRotations],
            ] as [$pageClass, $tableKey]) {
                $frames = $this->subscribe($pageClass, $acceptKey, $tableKey);
                if ($pageClass !== LogsRotationsPage::class) {
                    $headerSignal = $pageClass === LogsKeysPage::class
                        ? HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS
                        : HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS;
                    self::assertStringNotContainsString(
                        HiddenValue::KEY,
                        json_encode($this->frame($frames, $headerSignal), JSON_THROW_ON_ERROR),
                    );
                }
                $rows = $this->windowRows($frames, $tableKey);
                self::assertNotEmpty($rows);
                foreach ($rows as $row) {
                    $slot = reset($row[PagePayload::slots]);
                    self::assertStringNotContainsString(HiddenValue::KEY, json_encode($slot, JSON_THROW_ON_ERROR));
                }
            }

            $rotation = $this->frame(
                $this->subscribe(LogsRotationsPage::class, $acceptKey),
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
            );
            self::assertFalse(HiddenValue::isMark($rotation[HilosLogsRotationsSignalData::available]));
            foreach ([
                HilosLogsRotationsSignalData::rotationCron,
                HilosLogsRotationsSignalData::rotationMaxAgeSeconds,
                HilosLogsRotationsSignalData::rotationMaxLiveSizeBytes,
                HilosLogsRotationsSignalData::retentionKeepBatches,
                HilosLogsRotationsSignalData::retentionMaxAgeSeconds,
            ] as $field) {
                self::assertTrue(HiddenValue::isMark($rotation[$field]));
            }

            $catalog = $this->frame($this->subscribe(LogsViewPage::class, $acceptKey), HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW);
            self::assertFalse(HiddenValue::isMark($catalog[HilosLogsViewCatalogSignalData::available]));
            self::assertFalse(HiddenValue::isMark($catalog[HilosLogsViewCatalogSignalData::nodes]));
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($catalog, JSON_THROW_ON_ERROR));
        }

        $pages = [LogsOverviewPage::class, LogsKeysPage::class, LogsWorkersPage::class, LogsRotationsPage::class, LogsViewPage::class];
        foreach ($pages as $pageClass) {
            $frames = $this->subscribe($pageClass, self::ADMIN_KEY);
            $payloads = array_map(static fn (array $frame): array => $frame['data']->toArray(), $frames);
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($payloads, JSON_THROW_ON_ERROR));
        }
    }

    public function testReadingActionsReachTheOwnerWithTheCurrentVerdict(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY, self::ADMIN_KEY] as $acceptKey) {
            foreach ([HilosSignalConstants::LOGS_READ_LINES, HilosSignalConstants::LOGS_FOLLOW_START] as $action) {
                $payload = $action === HilosSignalConstants::LOGS_READ_LINES
                    ? new LogsReadLinesActionDTO('', LogsReadLinesActionDTO::SOURCE_LIVE, null, 'worker-0.log', null, 'secret', null)
                    : new LogsFollowStartActionDTO('', 'worker-0.log', null, 'secret');
                $this->drainSignals();
                $agent = new DemoHilosLegalAgent();
                $router = new PageSignalRouter(new HilosPageFactory($agent, Hilos::class), new ActionRouteConfig([$action => LogsViewPage::PAGE]));
                $this->underAgent($agent, static fn () => $router->dispatchAction(
                    new WebSocketActionSignalDTO($acceptKey, $action, $payload->toArray(), 'req-logs-view'),
                    'websocket',
                ));
                $forwarded = null;
                while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                    if ($signal->data instanceof AgentSignalData) {
                        $forwarded = $signal->data->data;
                        break;
                    }
                }
                self::assertNotNull($forwarded, "{$action} from {$acceptKey} reached no owner");
                self::assertSame($acceptKey !== self::ADMIN_KEY, $forwarded->hideText);
                self::assertSame($acceptKey === self::ADMIN_KEY ? 'secret' : null, $forwarded->substring);
                self::assertTrue($forwarded instanceof LogsReadLinesSignalData || $forwarded instanceof LogsFollowStartSignalData);
            }
        }
    }

    /**
     * @param class-string<AbstractPage> $pageClass Page opened by the connection
     * @param string $acceptKey Connection opening the page
     * @param ?string $tableKey Table window to include, or null for a header only
     * @return list<array{name: string, data: SignalDataInterface}> Browser frames in order
     */
    private function subscribe(string $pageClass, string $acceptKey, ?string $tableKey = null): array
    {
        $this->drainSignals();
        $windows = $tableKey === null ? [] : [$tableKey => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)];
        Hilos::$sr->subscribeToPage($pageClass::PAGE, new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, [], $windows));
        if ($windows !== []) {
            Hilos::$sr->reportTableWindows($acceptKey, $windows);
        }
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($pageClass, $acceptKey): void {
            new $pageClass(new DemoHilosAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
        });

        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }

        return $frames;
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Browser frames
     * @param string $name Name of the subscription frame
     * @return array<string, mixed> Its wire payload
     */
    private function frame(array $frames, string $name): array
    {
        foreach ($frames as $frame) {
            if ($frame['name'] === $name) {
                return $frame['data']->toArray();
            }
        }
        self::fail("No {$name} frame was sent");
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Browser frames
     * @param string $tableKey Table whose rows are needed
     * @return list<array<string, mixed>> Its browser rows
     */
    private function windowRows(array $frames, string $tableKey): array
    {
        foreach ($frames as $frame) {
            $rows = $frame['data']->toArray()[PageResponseSignalData::payload][PagePayload::windows][$tableKey][TableWindowSignalData::rows] ?? null;
            if (is_array($rows)) {
                return $rows;
            }
        }
        self::fail("No window for {$tableKey} was sent");
    }
}
