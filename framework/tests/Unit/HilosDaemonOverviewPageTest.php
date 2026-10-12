<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonConsensusPicture;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;
use Hilos\Pages\Daemon\DTO\HilosDaemonOverviewData;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** Root diagnostics and whole-page fan-out use the existing subscription and guarded delivery path. */
final class HilosDaemonOverviewPageTest extends TestCase
{
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private ?TableContext $previousTable;
    private ?DbContext $previousDb;
    private string $previousAppClass;
    /** @var array<string, mixed> Driver configuration restored after the page cases */
    private array $previousDatabase = [];
    private string $logFile;
    private DaemonOverviewTestBrowser $browser;
    private PageSignalRouter $pageRouter;
    private DaemonOverviewTestResender $resender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousTable = Hilos::$table;
        $this->previousDb = Hilos::$db;
        $this->previousAppClass = Hilos::appClass();
        foreach (['configurations', 'currentIndex', 'connections', 'resultSets', 'lastSql', 'closedByHand'] as $name) {
            $this->previousDatabase[$name] = (new ReflectionProperty(Database::class, $name))->getValue();
        }
        (new ReflectionProperty(Database::class, 'configurations'))->setValue(null, []);
        (new ReflectionProperty(Database::class, 'currentIndex'))->setValue(null, DatabaseConnectionDefaults::PRIMARY_INDEX);
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new DaemonOverviewTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$cluster = null;
        Hilos::$db = null;
        Hilos::$table = new DaemonOverviewTestTableContext();
        Hilos::$table->configure();
        $this->browser = new DaemonOverviewTestBrowser();
        Hilos::$browser = $this->browser;
        $this->browser->bindHilosFacade(DaemonOverviewTestHilos::class);
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, DaemonOverviewTestHilos::class);
        $this->pageRouter = new PageSignalRouter(new DaemonOverviewTestPageFactory(new DaemonOverviewTestAgent()), new ActionRouteConfig());
        $this->resender = new DaemonOverviewTestResender($this->pageRouter);
        $this->browser->bindPageResender($this->resender);
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonPage::onPictureForgotten();
        DaemonOverviewTestPage::$fails = false;
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-daemon-overview-');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$table = $this->previousTable;
        Hilos::$db = $this->previousDb;
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, $this->previousAppClass);
        foreach ($this->previousDatabase as $name => $value) {
            (new ReflectionProperty(Database::class, $name))->setValue(null, $value);
        }
        DaemonOverviewTestPage::$fails = false;
        Logger::resetLogFile();
        unlink($this->logFile);
        parent::tearDown();
    }

    public function testUnknownMirrorAndPartialPortionStayNullButFullEmptySnapshotIsKnown(): void
    {
        self::assertNull(HilosDaemonOverviewData::fromPicture(null, true, false));
        $node = $this->node(new NodeDaemonPicture('n1', NodeRole::Master, 100));
        $this->deliver([$node], false);
        self::assertNull(HilosDaemonOverviewData::fromPicture(ClusterDaemonPictureMirror::picture(), true, false));
        $this->subscribe('root');
        $response = $this->onlyResponse();
        self::assertNull($response->payload->data['daemon']);
        self::assertNull($response->payload->data['database']);

        $this->deliver([]);
        $response = $this->onlyResponse();
        self::assertSame([
            'clustered' => false, 'nodes' => [], 'consensus' => null,
            'environmentComparison' => ['answeringNodes' => 0, 'divergedKeys' => null, 'sourcesDifferKeys' => null],
        ], $response->payload->data['daemon']);
        self::assertSame([HilosClusterNode::RT_COLLECTION], AbstractHilosDaemonPage::READS_RT);
    }

    public function testMissingSectionsAndSilentNodesWithAndWithoutAReportKeepTheirUnknowns(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode(new ClusterDaemonNodeView('z', false, null))
            ->withNode($this->node(new NodeDaemonPicture('a', NodeRole::Master, 100)))
            ->withNode($this->node(new NodeDaemonPicture('s', NodeRole::Slave, 100), false, 90));
        $nodes = $this->projection($picture)['nodes'];
        self::assertSame(['a', 's', 'z'], array_column($nodes, 'nodeId'));
        self::assertSame([
            'nodeId' => 'a', 'online' => true, 'role' => 'master', 'state' => null, 'silentSince' => null,
            'workers' => null, 'workerRestarts24h' => null, 'uptimeMinutes' => null,
            'measurements' => null, 'standing' => null, 'environment' => null,
        ], $nodes[0]);
        self::assertSame('silent', $nodes[1]['state']);
        self::assertSame(90, $nodes[1]['silentSince']);
        self::assertSame('slave', $nodes[1]['role']);
        self::assertSame('silent', $nodes[2]['state']);
        self::assertNull($nodes[2]['silentSince']);
        self::assertNull($nodes[2]['role']);
        self::assertNull($nodes[2]['workers']);
    }

    public function testOnlyActualWorkersCountsAndEnvironmentDiagnosticsLeaveTheProjection(): void
    {
        $roster = new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 11, 1048576, [$this->agent('hidden:1')]),
            new DaemonWorkerPicture(4, WorkerConstants::TYPE_MONOPOLISTIC, null, null, []),
            new DaemonWorkerPicture(8, WorkerConstants::TYPE_MONOPOLISTIC, 18, 1048576, [$this->agent('hidden:2')]),
        ], ['hidden:lost'], 0);
        $environment = new NodeEnvironmentSummary(9, 0, 2, 3, 4, [
            new NodeEnvironmentFingerprint('APP_ENV', 'string', EnvSource::PROCESS, false, 'secret-label'),
        ]);
        $node = $this->projection(ClusterDaemonPicture::empty()->withNode($this->node(new NodeDaemonPicture(
            'n1', NodeRole::Master, 100, $roster, environment: $environment,
            standing: new DaemonNodeStanding(0, 0, false, null),
        ))), false)['nodes'][0];
        self::assertSame([
            ['index' => 1, 'kind' => 'regular', 'agentCount' => 1],
            ['index' => 4, 'kind' => 'monopolistic', 'agentCount' => 0],
            ['index' => 8, 'kind' => 'monopolistic', 'agentCount' => 1],
        ], $node['workers']);
        self::assertSame(0, $node['workerRestarts24h']);
        self::assertSame(['sessions' => 0, 'connections' => 0], $node['standing']);
        self::assertSame(['missingRequired' => 0, 'fromExample' => 2, 'drifted' => 3, 'orphans' => 4], $node['environment']);
        $wire = json_encode($node, JSON_THROW_ON_ERROR);
        foreach (['APP_ENV', 'secret-label', 'fingerprints', 'catalogKeys', 'hidden:', 'memoryBytes', 'pid'] as $private) {
            self::assertStringNotContainsString($private, $wire);
        }
        $empty = $this->projection(ClusterDaemonPicture::empty()->withNode($this->node(new NodeDaemonPicture(
            'n1', NodeRole::Master, 100, new DaemonProcessRoster([], [], 0),
        ))))['nodes'][0];
        self::assertSame([], $empty['workers']);
        self::assertSame(0, $empty['workerRestarts24h']);
    }

    public function testMeasurementsKeepEveryFieldAndByteUnitAndUptimeUsesTheReportedSample(): void
    {
        $measurements = new DaemonMeasurements(100, 2.5, DaemonMeasurements::CORES_QUOTA, 0.0, 0.0, 0,
            1048576, 2097152, 2, 1073741824, DaemonMeasurements::CEILING_CGROUP, 134217728);
        $report = new NodeDaemonPicture('n1', NodeRole::Master, 279, measurements: $measurements);
        $picture = ClusterDaemonPicture::empty()->withNode($this->node($report));
        $node = $this->projection($picture)['nodes'][0];
        self::assertSame(2, $node['uptimeMinutes']);
        self::assertSame(DaemonStatusDTO::measurementsToArray($measurements), $node['measurements']);
        self::assertEquals($measurements, DaemonStatusDTO::measurementsFromArray($node['measurements']));
        self::assertSame(1048576, $node['measurements']['masterMemory']);
        self::assertSame(2097152, $node['measurements']['workersMemory']);
        self::assertSame(1073741824, $node['measurements']['memoryCeiling']);
        $silent = $this->projection($picture->withNode($this->node($report, false, 1000)))['nodes'][0];
        self::assertSame(2, $silent['uptimeMinutes']);
        self::assertSame($node['measurements'], $silent['measurements']);
        $futureStart = $this->projection($picture->withNode($this->node($report->sampledAt(50))))['nodes'][0];
        self::assertSame(0, $futureStart['uptimeMinutes']);
        $unknown = new DaemonMeasurements(100, null, null, null, null, null, null, null, 0, null, null, null);
        $node = $this->projection($picture->withNode($this->node($report->withMeasurements($unknown))))['nodes'][0];
        self::assertNull($node['measurements']['daemonCores']);
        self::assertNull($node['measurements']['masterMemory']);
        self::assertSame(0, $node['measurements']['workersMeasured']);
    }

    public function testStateAndConsensusComeFromTheSourceAndStandbyStillHasBrowserSessions(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->master('b', ConsensusRole::Follower, 5, 'a', 3))
            ->withNode($this->master('a', ConsensusRole::Leader, 5, 'a', 2))
            ->withNode($this->node(new NodeDaemonPicture('data', NodeRole::Slave, 100,
                standing: new DaemonNodeStanding(0, 0, true, null))));
        $projection = $this->projection($picture);
        self::assertSame(['leader', 'standby', 'data'], array_column($projection['nodes'], 'state'));
        self::assertSame(['sessions' => 3, 'connections' => 4], $projection['nodes'][1]['standing']);
        self::assertSame(['sessions' => 0, 'connections' => 0], $projection['nodes'][2]['standing']);
        self::assertSame([
            'nodeId' => 'a', 'term' => 5, 'leaderId' => 'a', 'onlineMasters' => 1, 'masters' => 3, 'quorumSize' => 2,
        ], $projection['consensus']);
        self::assertNull($this->projection($picture, false)['consensus']);

        // A newer candidate removes the leader; the highest term wins, and a tie keeps the first id.
        $picture = $picture->withNode($this->master('c', ConsensusRole::Candidate, 6, null, 1))
            ->withNode($this->master('d', ConsensusRole::Follower, 6, null, 1))
            ->withNode($this->master('silent', ConsensusRole::Leader, 20, 'silent', 1, false));
        self::assertSame('c', $this->projection($picture)['consensus']['nodeId']);
        self::assertSame(6, $this->projection($picture)['consensus']['term']);
        self::assertNull($this->projection($picture)['consensus']['leaderId']);
        self::assertNull($this->projection(ClusterDaemonPicture::empty()->withNode(
            $this->node(new NodeDaemonPicture('data', NodeRole::Slave, 100)),
        ))['consensus']);
    }

    public function testEnvironmentComparisonUsesAnsweringNodesAndExcludesPerNodeKeys(): void
    {
        $picture = ClusterDaemonPicture::empty();
        foreach (['b', 'a'] as $id) {
            $summary = new NodeEnvironmentSummary(3, 0, 0, 0, 0, [
                new NodeEnvironmentFingerprint('DIVERGED', 'string', EnvSource::PROCESS, false, $id),
                new NodeEnvironmentFingerprint('SOURCE', 'string', $id === 'a' ? EnvSource::PROCESS : EnvSource::ENV_FILE, false, 'same'),
                new NodeEnvironmentFingerprint('PER_NODE', 'string', EnvSource::PROCESS, true, $id),
            ]);
            $picture = $picture->withNode($this->node(new NodeDaemonPicture($id, NodeRole::Master, 100, environment: $summary)));
        }
        self::assertSame(['answeringNodes' => 2, 'divergedKeys' => 1, 'sourcesDifferKeys' => 1],
            $this->projection($picture)['environmentComparison']);
        self::assertSame(['answeringNodes' => 2, 'divergedKeys' => null, 'sourcesDifferKeys' => null],
            $this->projection($picture, false)['environmentComparison']);
        $silent = $picture->node('b');
        $picture = $picture->withNode(new ClusterDaemonNodeView('b', false, $silent->slot));
        self::assertSame(['answeringNodes' => 1, 'divergedKeys' => null, 'sourcesDifferKeys' => null],
            $this->projection($picture)['environmentComparison']);
        $picture = $picture->withNode($this->node(new NodeDaemonPicture('b', NodeRole::Master, 100,
            environment: new NodeEnvironmentSummary(3, 0, 0, 0, 0, $picture->node('a')->slot->picture->environment->fingerprints))));
        self::assertSame(['answeringNodes' => 2, 'divergedKeys' => 0, 'sourcesDifferKeys' => 0],
            $this->projection($picture)['environmentComparison']);
    }

    public function testSubscriptionAndChangedOverviewAnswerOnceWithIdentityOwnDataAndExistingWindow(): void
    {
        $report = new NodeDaemonPicture('n1', NodeRole::Master, 100, $this->roster('x:1'));
        $this->deliver([$this->node($report)]);
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, 'primary', 'private-user', 'private-password', 'daemon-db');
        $this->subscribe('root');
        $first = $this->onlyResponse();
        self::assertSame('daemon-db', $first->payload->data['database']['name']);
        self::assertArrayHasKey(PageCatalogConstants::WIRE_PAGE_LABEL, $first->payload->data);
        self::assertArrayHasKey(PageCatalogConstants::WIRE_PAGE_BREADCRUMB, $first->payload->data);
        self::assertArrayHasKey(HilosDaemonPlacementTable::TABLE, $first->payload->windows);
        self::assertEquals($first, PageResponseSignalData::fromArray($first->toArray()));
        $viewport = Hilos::$sr->getTableViewport('root', HilosDaemonPlacementTable::TABLE);
        self::assertNotNull($viewport);
        self::assertSame(['search' => 'x:1'], $viewport->filter);

        $this->subscribe('closed', connected: false);
        $this->drain();
        $this->subscribe('child', HilosPageConstants::HILOS_DAEMON_WORKERS, dispatch: false);
        $this->resender->attempts = [];
        $this->deliver([$this->node($report->withStanding(new DaemonNodeStanding(0, 0, false, null)))]);
        $response = $this->onlyResponse();
        self::assertSame([['hilos_daemon', 'root']], $this->resender->attempts);
        self::assertSame(['sessions' => 0, 'connections' => 0], $response->payload->data['daemon']['nodes'][0]['standing']);
        self::assertSame($first->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL],
            $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertSame($viewport->filter, Hilos::$sr->getTableViewport('root', HilosDaemonPlacementTable::TABLE)->filter);
    }

    public function testPlacementOnlyChangesKeepTheWindowPathAndRepairDoesNotResend(): void
    {
        $report = new NodeDaemonPicture('n1', NodeRole::Master, 100, $this->roster('x:1'));
        $this->deliver([$this->node($report)]);
        $this->subscribe('root');
        $this->drain();
        $this->resender->attempts = [];
        $this->deliver([$this->node($report->withProcesses($this->roster('x:2')))]);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalTypeConstants::TABLE_WINDOW, $signals[0]->signalName->getName());
        self::assertSame([], $this->resender->attempts);

        $repaired = $report->withProcesses($this->roster('x:2'))->sampledAt(500)
            ->withCron(new DaemonCronPicture(null, []));
        $this->deliver([$this->node($repaired, receivedAt: 501)]);
        self::assertSame([], $this->drain());
        self::assertSame([], $this->resender->attempts);
        AbstractHilosDaemonPage::onPictureForgotten();
        $this->deliver([$this->node($repaired, receivedAt: 501)]);
        $this->onlyResponse();
        self::assertSame([['hilos_daemon', 'root']], $this->resender->attempts);
    }

    public function testChangedOverviewAndPlacementHaveNoSecondWindowEvenAfterRefusalOrFailure(): void
    {
        $report = new NodeDaemonPicture('n1', NodeRole::Master, 100, $this->roster('x:1'));
        $this->deliver([$this->node($report)]);
        $this->subscribe('root');
        $this->drain();
        $this->deliver([$this->node($report->withProcesses($this->roster('x:1', 'x:2')))]);
        $this->onlyResponse();

        $this->browser->admin = false;
        $this->deliver([$this->node($report->withProcesses($this->roster('x:1', 'x:2', 'x:3')))]);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $signals[0]->data->data);
        self::assertSame(403, $signals[0]->data->data->httpCode);

        $this->browser->admin = true;
        DaemonOverviewTestPage::$fails = true;
        $this->deliver([$this->node($report->withProcesses($this->roster('x:1', 'x:2', 'x:3', 'x:4')))]);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        self::assertSame(500, $signals[0]->data->data->httpCode);

        DaemonOverviewTestPage::$fails = false;
        $this->deliver([$this->node($report->withProcesses($this->roster('x:1', 'x:2', 'x:3', 'x:4', 'x:5')))]);
        $this->onlyResponse();
        $this->deliver([$this->node($report->withProcesses($this->roster('x:1', 'x:2', 'x:3', 'x:4', 'x:6')))]);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalTypeConstants::TABLE_WINDOW, $signals[0]->signalName->getName());
    }

    public function testPlacementFactRecoversAnOwedPageOnceAndAFailedTrySendsNoWindow(): void
    {
        $report = new NodeDaemonPicture('n1', NodeRole::Master, 100, $this->roster('x:1'));
        $this->deliver([$this->node($report)]);
        $this->subscribe('root');
        $this->drain();
        Hilos::$sr->markPageDeliveryFailure('root');
        DaemonOverviewTestPage::$fails = true;
        $this->deliver([$this->node($report->withProcesses($this->roster('x:2')))]);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        DaemonOverviewTestPage::$fails = false;
        $this->deliver([$this->node($report->withProcesses($this->roster('x:3')))]);
        $this->onlyResponse();
        $this->deliver([$this->node($report->withProcesses($this->roster('x:4')))]);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalTypeConstants::TABLE_WINDOW, $signals[0]->signalName->getName());
    }

    public function testAdminAndViewModeViewerReceiveBothDiagnosticFieldsThroughTheVerdict(): void
    {
        $this->deliver([$this->node(new NodeDaemonPicture('n1', NodeRole::Master, 100, $this->roster('x:1')))]);
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, 'primary', 'private-user', 'private-password', 'daemon-db');
        $this->subscribe('admin');
        $admin = $this->onlyResponse();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(true);
        $this->drain();
        $this->browser->userId = null;
        $this->browser->admin = false;
        $this->subscribe('viewer');
        $viewer = $this->onlyResponse();
        self::assertSame($admin->payload->data['daemon'], $viewer->payload->data['daemon']);
        self::assertSame($admin->payload->data['database'], $viewer->payload->data['database']);
        self::assertArrayHasKey(HilosDaemonPlacementTable::TABLE, $viewer->payload->windows);
        $wire = json_encode($viewer->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-user', $wire);
        self::assertStringNotContainsString('private-password', $wire);
        self::assertStringNotContainsString('APP_ENV', $wire);
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(false);
        $this->drain();
        $this->subscribe('guest');
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(401, $signals[0]->data->data->httpCode);
    }

    public function testPictureHookAndProjectionNeverReadDatabaseMetadata(): void
    {
        foreach ([[AbstractHilosDaemonPage::class, 'onPictureChanged'], [AbstractHilosDaemonPage::class, 'overview']] as [$class, $method]) {
            $reflection = new ReflectionMethod($class, $method);
            $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1));
            self::assertStringNotContainsString('Database::', $source);
            self::assertStringNotContainsString('HilosDaemonDatabaseReader', $source);
        }
    }

    /** @return array<string, mixed> Root wire projection */
    private function projection(ClusterDaemonPicture $picture, bool $clustered = true): array
    {
        $projection = HilosDaemonOverviewData::fromPicture($picture, $clustered, true);
        self::assertNotNull($projection);
        return $projection->toArray();
    }

    /** @return ClusterDaemonNodeView One source slot */
    private function node(NodeDaemonPicture $picture, bool $online = true, int $receivedAt = 100): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView($picture->nodeId, $online, new ClusterDaemonNodeSlot($picture->nodeId, $picture, $receivedAt));
    }

    /** @return ClusterDaemonNodeView Master reporting its own consensus and sessions */
    private function master(string $id, ConsensusRole $role, int $term, ?string $leaderId, int $sessions,
        bool $online = true): ClusterDaemonNodeView
    {
        return $this->node(new NodeDaemonPicture($id, NodeRole::Master, 100, standing: new DaemonNodeStanding(
            $sessions, $sessions + 1, true, new DaemonConsensusPicture($role, $term, $leaderId, 1, 3, 2),
        )), $online);
    }

    /** @return DaemonAgentPicture Started instance */
    private function agent(string $id): DaemonAgentPicture
    {
        return new DaemonAgentPicture($id, DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY);
    }

    /** @return DaemonProcessRoster One actual worker with the supplied started instances */
    private function roster(string ...$ids): DaemonProcessRoster
    {
        return new DaemonProcessRoster([new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 123, 1048576,
            array_map($this->agent(...), $ids))], [], 0);
    }

    /** @param list<ClusterDaemonNodeView> $nodes Nodes carried through the real IPC array roundtrip */
    private function deliver(array $nodes, bool $snapshot = true): void
    {
        $portion = new DaemonClusterPicturePortionSignalData($snapshot, $nodes);
        (new DaemonOverviewTestAgent())->onSignalAgent(
            new AgentSignalData(DaemonClusterPicturePortionSignalData::fromArray($portion->toArray())),
            'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION,
        );
    }

    private function subscribe(string $key, string $page = HilosPageConstants::HILOS_DAEMON,
        bool $connected = true, bool $dispatch = true): void
    {
        $request = new WebSocketPageSubscribeSignalDTO($key, $page, tableWindows: [
            HilosDaemonPlacementTable::TABLE => new TableWindowDescriptorDTO(filter: [TableConstants::FILTER_KEY_SEARCH => 'x:1'], limit: 7),
        ]);
        Hilos::$sr->subscribeToPage($page, $request);
        if ($connected) {
            Hilos::$rt->connectionsSource()->add(DaemonOverviewTestConnection::create($key, null));
        }
        if ($dispatch) {
            $this->pageRouter->dispatchPageSubscribe($request, 'websocket', $page);
        }
    }

    /** @return PageResponseSignalData The single whole response, with no duplicate window or companion signal */
    private function onlyResponse(): PageResponseSignalData
    {
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signals[0]->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        self::assertInstanceOf(PageResponseSignalData::class, $signals[0]->data->data);
        return $signals[0]->data->data;
    }

    /** @return list<SignalDTO> Frames waiting for delivery */
    private function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }
        return $signals;
    }
}

final class DaemonOverviewTestAgent extends AbstractHilosDaemonAgent
{
}

final class DaemonOverviewTestPage extends AbstractHilosDaemonPage
{
    public static bool $fails = false;

    /** @return PagePayload Real root data; @throws HilosException On an injected assembly failure */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        if (self::$fails) {
            throw new HilosException('Root assembly failed');
        }
        return parent::buildPagePayload($acceptKey, $params);
    }
}

final class DaemonOverviewTestPageFactory extends AbstractPageFactory
{
    public function hasPage(string $pageName): bool
    {
        return $pageName === HilosPageConstants::HILOS_DAEMON;
    }

    /** @return AbstractPage Root page; @throws PageNotFoundException For an unexpected test page */
    protected function createPage(string $pageName): AbstractPage
    {
        if (!$this->hasPage($pageName)) {
            throw new PageNotFoundException($pageName);
        }
        return new DaemonOverviewTestPage($this->agent);
    }
}

final class DaemonOverviewTestResender implements PageResender
{
    /** @var list<array{string, string}> Whole-page attempts, including contained refusals and failures */
    public array $attempts = [];

    public function __construct(private readonly PageSignalRouter $router)
    {
    }

    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome
    {
        $this->attempts[] = [$page, $acceptKey];
        return $this->router->resendPage($page, $acceptKey, $params);
    }
}

final class DaemonOverviewTestBrowser extends BrowserContext
{
    public ?int $userId = 1;
    public bool $admin = true;

    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->userId);
    }

    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }
}

abstract class DaemonOverviewTestHilos extends Hilos
{
    public const array AGENTS = ['x' => []];
    public const array PAGES = [HilosPageConstants::HILOS_DAEMON => DaemonOverviewTestPage::class];
    public const array PAGE_TABLES = [HilosPageConstants::HILOS_DAEMON => [HilosDaemonPlacementTable::TABLE => []]];
}

final class DaemonOverviewTestTableContext extends TableContext
{
    public function configure(): void
    {
        $this->register(HilosDaemonPlacementTable::TABLE, new HilosDaemonPlacementTable());
    }
}

final class DaemonOverviewTestConnection extends HilosConnection
{
    protected function initOwn(): void
    {
    }

    protected function hydrateOwn(array $row): void
    {
    }

    protected function ownToArray(): array
    {
        return [];
    }

    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** @extends HilosConnections<DaemonOverviewTestConnection> */
final class DaemonOverviewTestConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonOverviewTestConnection::class;
}

final class DaemonOverviewTestRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections['daemonOverviewTestConnections'] = DaemonOverviewTestConnections::init();
    }
}
