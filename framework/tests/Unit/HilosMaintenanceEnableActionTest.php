<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\ProtectedMode\DTO\ManualMaintenanceEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Exercises the real action dispatch, both declared signal routes and page recreation before the ack. */
final class HilosMaintenanceEnableActionTest extends TestCase
{
    private const string ACCEPT_KEY = 'maintenance-browser';
    private const string SESSION_TOKEN = 'server-session-token';
    private const string REQUEST_ID = 'maintenance-submit';

    private ?ClusterContext $previousCluster;
    private MaintenanceEnableTestAgent $agent;
    private HilosPageFactory $factory;
    private PageSignalRouter $pages;
    private StateProtectedModeRuntime $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new MaintenanceEnableTestRouter();
        Hilos::$browser = new IdentityTestBrowser(7, true);
        Hilos::$rt = new MaintenanceEnableTestRtContext();
        Hilos::$rt->configure();
        $this->row = StateProtectedModeRuntime::create();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, $this->row);
        $this->agent = new MaintenanceEnableTestAgent();
        $this->recreatePages();
        AbstractHilosMaintenancePage::reset();
    }

    protected function tearDown(): void
    {
        AbstractHilosMaintenancePage::reset();
        Hilos::$sr = null;
        Hilos::$browser = null;
        Hilos::$rt = null;
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    public function testServerIdentityTravelsThroughDeclaredAskAndDoneRoutesAfterPageRecreation(): void
    {
        $this->dispatch([
            'acceptKey' => 'forged-browser', 'sessionHash' => 'forged-session', 'requestId' => 'forged-request',
            'operation' => 'restore', 'entryMode' => 'freeze', 'replySignal' => 'forged-done', 'successMessage' => 'forged-success',
        ]);
        $ask = $this->deliverAsk();
        $this->assertSame(self::ACCEPT_KEY, $ask->acceptKey);
        $this->assertSame(StateProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN), $ask->sessionHash);
        $this->assertSame(self::REQUEST_ID, $ask->requestId);
        $this->assertSame(HilosSignalConstants::MAINTENANCE_ENABLE, $ask->action);
        $this->assertSame(HilosSignalConstants::MAINTENANCE_ENABLE_DONE, $ask->replySignal);

        $entry = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE)->data;
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $entry);
        $this->assertSame(self::ACCEPT_KEY, $entry->initiatorAcceptKey);
        $this->assertSame($ask->sessionHash, $entry->initiatorSessionTokenHash);
        $this->assertSame(StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE, $entry->operation);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, $entry->entryMode);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'The request itself is not a success');

        $oldPage = $this->factory->getPage(HilosPageConstants::HILOS_MAINTENANCE);
        $this->assertFalse($oldPage->actionReplyDeferred(), 'Dispatch-local deferral is cleared; the ask carries the pending correlation');
        $this->row->phase = StateProtectedModeRuntime::PHASE_VERIFYING;
        $this->row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW;
        $this->row->operation = StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE;
        $this->row->initiatorAcceptKey = self::ACCEPT_KEY;
        $this->row->initiatorSessionTokenHash = $ask->sessionHash;
        $this->recreatePages();
        $this->assertNotSame($oldPage, $this->factory->getPage(HilosPageConstants::HILOS_MAINTENANCE));
        $this->pages->dispatchPageSubscribe(new WebSocketPageSubscribeSignalDTO(
            self::ACCEPT_KEY, HilosPageConstants::HILOS_MAINTENANCE, [],
        ), 'websocket', HilosPageConstants::HILOS_MAINTENANCE);
        $state = $this->nextSignal(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE);
        $this->assertTrue($state->data->data->toArray()['manual']);
        $this->nextSignal(SignalTypeConstants::PAGE_RESPONSE);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'A manual state and page response do not ack the action');

        $this->agent->onProtectedModeReady();
        $this->deliverDone();
        $ack = $this->nextBrowserAnswer(SignalConstants::ACTION_SUCCESS);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $this->assertSame('Maintenance is on — the system is closed to visitors', $ack->message);
        $this->assertNull($ack->reply);
        $this->assertArrayNotHasKey('sessionHash', $ack->toArray());
        $this->agent->onProtectedModeReady();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testRefusalArrivesWithTheSameCorrelationAfterPageRecreation(): void
    {
        $this->dispatch();
        $this->deliverAsk();
        $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->recreatePages();
        $this->agent->onProtectedModeRefused('cluster refuses direct entry');
        $this->deliverDone();
        $error = $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $error);
        $this->assertSame('cluster refuses direct entry', $error->reason);
        $this->assertNull($error->errorType);
        $this->assertNull($error->errorDetail);
        $this->agent->onProtectedModeReady();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testUnconfirmedOutcomeFailsTheTrackedSubmitAndLateReadyStaysSilent(): void
    {
        $this->dispatch();
        $this->deliverAsk();
        $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->recreatePages();
        new ReflectionProperty(AbstractHilosIndexAgent::class, 'manualMaintenanceSince')->setValue(
            $this->agent, microtime(true) - CommandChannelWindows::AGENT_WAIT_SECONDS - 1.0,
        );
        $this->agent->onTick();
        $this->deliverDone();
        $error = $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $error);
        $this->assertSame(
            'The result of turning on maintenance is unconfirmed. Check the state in Maintenance before trying again. '
            . 'protected mode did not complete enable within 13s',
            $error->reason,
        );
        $this->agent->onProtectedModeReady();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    #[DataProvider('deniedActors')]
    public function testNonAdminAndViewModeCannotForward(?int $userId, bool $viewMode, string $errorCode): void
    {
        Hilos::$browser = new IdentityTestBrowser($userId, false);
        Hilos::$rt->mountFeatureItem(AdminViewModeRuntime::RT_ITEM, AdminViewModeRuntime::fromRow([
            AdminViewModeRuntime::enabled => $viewMode,
        ]));
        $this->dispatch();
        $error = $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR);
        $this->assertSame($errorCode, $error->errorCode);
        $this->assertNull($error->errorType);
        $this->assertNull($error->errorDetail);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public static function deniedActors(): array
    {
        return [[7, false, 'forbidden'], [7, true, 'view_mode'], [null, true, 'view_mode'], [null, false, 'unauthorized']];
    }

    #[DataProvider('missingSessions')]
    public function testMissingSessionRefusesBeforeForwarding(bool $runtimeExists, string $acceptKey): void
    {
        if (!$runtimeExists) {
            Hilos::$rt = null;
        }
        $this->pages->dispatchAction(new WebSocketActionSignalDTO(
            $acceptKey, HilosSignalConstants::MAINTENANCE_ENABLE, [], self::REQUEST_ID,
        ), 'websocket');
        $error = $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR, $acceptKey);
        $this->assertSame('A browser session is required to turn on maintenance', $error->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public static function missingSessions(): array
    {
        return [[false, self::ACCEPT_KEY], [true, 'missing-connection'], [true, 'sessionless'], [true, 'empty-session']];
    }

    private function recreatePages(): void
    {
        $this->factory = new HilosPageFactory($this->agent, MaintenanceEnableTestHilos::class);
        $this->pages = new PageSignalRouter(
            $this->factory,
            new ActionRouteConfig(MaintenanceEnableTestHilos::getPageActionRoutes()),
            new SignalRouteConfig(MaintenanceEnableTestHilos::getPageSignalRoutes()),
        );
    }

    private function dispatch(array $data = []): void
    {
        $this->pages->dispatchAction(new WebSocketActionSignalDTO(
            self::ACCEPT_KEY, HilosSignalConstants::MAINTENANCE_ENABLE, $data, self::REQUEST_ID,
        ), 'websocket');
    }

    private function deliverAsk(): ManualMaintenanceEnableSignalData
    {
        $signal = $this->nextSignal(HilosSignalConstants::MAINTENANCE_AGENT_ENABLE);
        $this->assertEquals([new AgentDestination(HilosAgentType::HILOS_INDEX)], Hilos::$sr->getDestinations($signal));
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'No premature ack beside the ask');
        $envelope = Hilos::$sr->createAgentSignalPayloadDTO(
            $signal->signalName->getName(), new AgentSignalData(new SignalData($signal->data->data->toArray())),
        );
        $this->assertInstanceOf(ManualMaintenanceEnableSignalData::class, $envelope->data);
        $this->agent->onSignalAgent($envelope, SignalSource::describe($signal->signalSource), $signal->signalName->getName());

        return $envelope->data;
    }

    private function deliverDone(): void
    {
        $signal = $this->nextSignal(HilosSignalConstants::MAINTENANCE_ENABLE_DONE);
        $this->assertEquals([new AgentDestination(HilosAgentType::HILOS_INDEX)], Hilos::$sr->getDestinations($signal));
        $this->assertInstanceOf(HandoverAnswerSignalData::class, $signal->data->data);
        $this->assertArrayNotHasKey('sessionHash', $signal->data->data->toArray());
        $this->pages->dispatchAgentSignal(
            new AgentSignalData(new SignalData($signal->data->data->toArray())),
            SignalSource::describe($signal->signalSource), $signal->signalName->getName(),
        );
    }

    private function nextSignal(string $name): SignalDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame($name, $signal->signalName->getName());

        return $signal;
    }

    private function nextBrowserAnswer(string $name, string $acceptKey = self::ACCEPT_KEY): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $signal = $this->nextSignal($name);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame($acceptKey, $signal->data->targetAcceptKey);
        $this->assertSame(self::REQUEST_ID, $signal->data->data->requestId);
        $this->assertSame(HilosSignalConstants::MAINTENANCE_ENABLE, $signal->data->data->action);

        return $signal->data->data;
    }
}

final class MaintenanceEnableTestPage extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class MaintenanceEnableTestAgent extends AbstractHilosIndexAgent
{
    /** No worker boot is needed to exercise the real signal handler and protected-mode request. */
    public function __construct()
    {
    }
}

abstract class MaintenanceEnableTestHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_MAINTENANCE => MaintenanceEnableTestPage::class];
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => [AgentRegistryKey::WORKER => MaintenanceEnableTestAgent::class]];
}

final class MaintenanceEnableTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Topology used for real destination resolution and payload hydration */
    protected function hilosClass(): string
    {
        return MaintenanceEnableTestHilos::class;
    }
}

final class MaintenanceEnableTestRtContext extends RtContext
{
    /** Seeds session rows so the page reads the real session-source seam. */
    public function configure(): void
    {
        $connections = MaintenanceEnableTestConnections::init();
        $connections->add(MaintenanceEnableTestConnection::create('maintenance-browser', 7, 'server-session-token'));
        $connections->add(MaintenanceEnableTestConnection::create('sessionless', 7, null));
        $connections->add(MaintenanceEnableTestConnection::create('empty-session', 7, ''));
        $this->_stateCollections[MaintenanceEnableTestConnections::RT_COLLECTION] = $connections;
    }
}

final class MaintenanceEnableTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'maintenanceEnableConnections';
    public const string STATE_CLASS = MaintenanceEnableTestConnection::class;
}

final class MaintenanceEnableTestConnection extends HilosSessionConnection
{
    /** This fixture adds no fields. */
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Fields unused by this fixture */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Fields unused by this fixture */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
