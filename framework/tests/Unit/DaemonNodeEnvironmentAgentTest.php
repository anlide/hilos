<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Fs\Watch\FsRescanSchedule;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** A watched dotenv change re-answers only live subscribers of this node. */
final class DaemonNodeEnvironmentAgentTest extends TestCase
{
    private const string KEY = 'HILOS_NODE_AGENT_TEST';

    private ?EnvAccessor $previousEnv = null;
    private ?ClusterContext $previousCluster = null;
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private ?RtContext $previousRt = null;
    private ?DaemonNodeAgent $agent = null;
    private ?string $root = null;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        Hilos::$cluster = null;
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonNodeEnvironmentTestBrowser();
        Hilos::$rt = new DaemonNodeEnvironmentRtContext();
        Hilos::$rt->configure();
        DaemonNodeEnvironmentCatalog::$catalog = [
            self::KEY => [EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING],
        ];
        $this->root = sys_get_temp_dir() . '/hilos-node-agent-env-' . uniqid();
        mkdir($this->root);
        file_put_contents($this->root . '/.env', self::KEY . "=first\n");
        Hilos::$env = new EnvAccessor(DaemonNodeEnvironmentCatalog::class);
        Hilos::$env->init($this->root);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->agent?->onStop();
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        if ($this->root !== null) {
            foreach (array_diff(scandir($this->root) ?: [], ['.', '..']) as $name) {
                unlink($this->root . '/' . $name);
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testRescanReanswersLiveViewersAndPreservesTheProcessValue(): void
    {
        $this->agent = new DaemonNodeAgent();
        $this->agent->onStart();
        $firstFrame = Hilos::$sr->getNextQueuedSignal()?->data?->data;
        $this->assertInstanceOf(DaemonNodePictureSignalData::class, $firstFrame);
        $this->assertSame(1, $firstFrame->picture->environment?->catalogKeys);

        Hilos::$sr->subscribeToPage(
            HilosPageConstants::HILOS_DAEMON_ENV,
            new WebSocketPageSubscribeSignalDTO('ak-live', HilosPageConstants::HILOS_DAEMON_ENV,
                [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 'standalone']),
        );
        Hilos::$rt->connectionsSource()?->add(DaemonNodeEnvironmentConnection::create('ak-live', null));

        file_put_contents($this->root . '/.env', self::KEY . "=second\n");
        $this->forceWatchWindowDue();
        $this->agent->onTick();

        $reading = $this->agent->environmentReading();
        $this->assertSame('first', $reading?->keys[0]->process->value);
        $this->assertSame('second', $reading?->keys[0]->disk?->value);
        $this->assertSame(1, $reading?->summary()->drifted);
        $this->assertSame([['ak-live', HilosPageConstants::HILOS_DAEMON_ENV]], Hilos::$browser->resent);

        $this->forceWatchWindowDue();
        $this->agent->onTick();
        $this->assertCount(1, Hilos::$browser->resent);

        Hilos::$rt->connectionsSource()?->remove('ak-live');
        file_put_contents($this->root . '/.env', self::KEY . "=third\n");
        $this->forceWatchWindowDue();
        $this->agent->onTick();
        $this->assertCount(1, Hilos::$browser->resent);
    }

    private function forceWatchWindowDue(): void
    {
        $schedule = new FsRescanSchedule(microtime(true) - 2.0);
        $schedule->noteChanges(microtime(true) - 2.0);
        new ReflectionProperty(DaemonNodeAgent::class, 'directoryRescanSchedule')
            ->setValue($this->agent, $schedule);
    }
}

final class DaemonNodeEnvironmentTestBrowser extends BrowserContext
{
    /** @var list<array{string, string}> Page and connection pairs re-answered */
    public array $resent = [];

    public function resendPageWhole(string $page, string $acceptKey): void
    {
        $this->resent[] = [$acceptKey, $page];
    }
}

final class DaemonNodeEnvironmentCatalog implements CatalogProviderInterface
{
    /** @var array<string, array<string, mixed>> Test environment catalog */
    public static array $catalog = [];

    /** @return array<string, array<string, mixed>> Test catalog */
    public static function getCatalog(): array
    {
        return self::$catalog;
    }
}

final class DaemonNodeEnvironmentConnection extends HilosConnection
{
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** @extends HilosConnections<DaemonNodeEnvironmentConnection> */
final class DaemonNodeEnvironmentConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonNodeEnvironmentConnection::class;
}

final class DaemonNodeEnvironmentRtContext extends RtContext
{
    public const string CONNECTIONS = 'daemonNodeEnvironmentConnections';

    public function configure(): void
    {
        $this->_stateCollections[self::CONNECTIONS] = DaemonNodeEnvironmentConnections::init();
    }
}
