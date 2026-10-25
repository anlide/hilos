<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\BackupReopenSignalData;
use Hilos\Backup\RestoreReleaseGate;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Maintenance\DTO\HilosMaintenanceStateSignalData;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlDoneSignalData;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Drives restore controls through the real maintenance gatekeeper and the real backup operator.
 *
 * Success is the operator's own wait: a tick before the row shows the hash, inactive or active
 * answers nothing, and the ack appears only on the tick that sees it.
 */
final class MaintenanceRestoreControlIntegrationTest extends TestCase
{
    private const string ACCEPT_KEY = 'restore-browser';

    private const string SESSION_TOKEN = 'restore-session-token';

    private const string REQUEST_ID = 'restore-submit';

    private ?ClusterContext $previousCluster = null;

    private BackupAgent $operator;

    private PageSignalRouter $pages;

    private StateProtectedModeRuntime $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new MaintenanceRestoreControlRouter();
        Hilos::$browser = new IdentityTestBrowser(7, true);
        $rt = new MaintenanceRestoreControlRtContext(self::SESSION_TOKEN);
        $rt->configure();
        $this->row = StateProtectedModeRuntime::fromRow([
            ...StateProtectedModeRuntime::create()->toArray(),
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::operation => BackupAgent::RESTORE_OPERATION,
            StateProtectedModeRuntime::entryMode => StateProtectedModeRuntime::ENTRY_MODE_FREEZE,
            StateProtectedModeRuntime::initiatorAgentType => HilosAgentType::HILOS_BACKUP,
            StateProtectedModeRuntime::initiatorAcceptKey => self::ACCEPT_KEY,
            StateProtectedModeRuntime::initiatorSessionTokenHash =>
                StateProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            StateProtectedModeRuntime::enteredFromBrowser => true,
            StateProtectedModeRuntime::diagnosticsNodeId => 'node-restore',
            StateProtectedModeRuntime::stoppedAgentCount => 4,
            StateProtectedModeRuntime::preservedAgentTypes => [HilosAgentType::HILOS_BACKUP],
            StateProtectedModeRuntime::agentStartGateClosed => true,
        ]);
        $rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, $this->row);
        Hilos::$rt = $rt;
        $this->operator = new BackupAgent();
        $this->pages = new PageSignalRouter(
            new HilosPageFactory(new MaintenanceRestoreControlIndex(), MaintenanceRestoreControlHilos::class),
            new ActionRouteConfig(MaintenanceRestoreControlHilos::getPageActionRoutes()),
            new SignalRouteConfig(MaintenanceRestoreControlHilos::getPageSignalRoutes()),
        );
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

    public function testPassOpenAndCloseWaitForTheRowBeforeThePageAnswers(): void
    {
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $this->deliverAsk();
        $mint = $this->nextNamed(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $mint->data);
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'A pass that has not landed is not an answer');

        $this->row->passHashes = [$mint->data->passHash];
        $this->tick();
        $passDone = $this->takeDone();
        $this->assertIsString($passDone->pass);
        $this->assertSame($mint->data->passHash, hash('sha256', $passDone->pass));
        $this->giveToPage($passDone);
        $passAck = $this->success(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $this->assertSame('Code issued', $passAck->message);
        $this->assertSame(['pass' => $passDone->pass], $passAck->reply);

        $this->row->phase = StateProtectedModeRuntime::PHASE_VERIFYING;
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_OPEN);
        $this->deliverAsk();
        $this->assertSame(SignalTypeConstants::PROTECTED_MODE_DISABLE, $this->nextNamed(
            SignalTypeConstants::PROTECTED_MODE_DISABLE,
        )->signalName->getName());
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'Still verifying, so open has nothing to report');
        $this->row->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $this->tick();
        $openDone = $this->takeDone();
        $this->assertNull($openDone->pass);
        $this->giveToPage($openDone);
        $openAck = $this->success(HilosSignalConstants::MAINTENANCE_RESTORE_OPEN);
        $this->assertSame('The system is open', $openAck->message);
        $this->assertNull($openAck->reply);

        $this->row->phase = StateProtectedModeRuntime::PHASE_VERIFYING;
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE);
        $this->deliverAsk();
        $this->assertSame(SignalTypeConstants::PROTECTED_MODE_REFREEZE, $this->nextNamed(
            SignalTypeConstants::PROTECTED_MODE_REFREEZE,
        )->signalName->getName());
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'Still verifying, so close has nothing to report');
        $this->row->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        $this->tick();
        $closeDone = $this->takeDone();
        $this->assertNull($closeDone->pass);
        $this->giveToPage($closeDone);
        $closeAck = $this->success(HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE);
        $this->assertSame('The window is closed again', $closeAck->message);
        $this->assertNull($closeAck->reply);
    }

    public function testABrowserAskDoesNotReplaceACliWait(): void
    {
        $this->operator->onSignalCommand(
            new CommandRequestDTO(correlationId: 'cli-pass', command: CliCommands::PROTECTED_MODE_PASS, payload: []),
            'test',
            'test',
        );
        $mint = $this->nextNamed(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $mint->data);

        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_OPEN);
        $this->deliverAsk();
        $done = $this->takeDone();
        $this->assertStringContainsString('still in flight', (string)$done->answer->error);
        $this->assertNull($done->pass);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->giveToPage($done);
        $this->assertStringContainsString('still in flight', $this->failure()->reason);

        $this->row->passHashes = [$mint->data->passHash];
        $this->tick();
        $reply = $this->commandReply();
        $this->assertSame(CommandConstants::STATUS_OK, $reply->status);
        $this->assertSame(
            $mint->data->passHash,
            hash('sha256', (string)$reply->payload[ProtectedModeCommandConstants::FIELD_PASS]),
        );
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testACliCommandDoesNotReplaceABrowserWait(): void
    {
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $this->deliverAsk();
        $mint = $this->nextNamed(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $mint->data);

        $this->operator->onSignalCommand(
            new CommandRequestDTO(correlationId: 'cli-open', command: CliCommands::PROTECTED_MODE_OPEN, payload: []),
            'test',
            'test',
        );
        $reply = $this->commandReply();
        $this->assertSame(CommandConstants::STATUS_ERROR, $reply->status);
        $this->assertStringContainsString('still in flight', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        $this->row->passHashes = [$mint->data->passHash];
        $this->tick();
        $done = $this->takeDone();
        $this->assertNull($done->answer->error);
        $this->assertIsString($done->pass);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testAForeignAgentIsRefusedWithoutBeginning(): void
    {
        $this->row->initiatorAgentType = HilosAgentType::HILOS_INDEX;
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $this->deliverAsk();
        $done = $this->takeDone();

        $this->assertSame('This agent did not start the restore', $done->answer->error);
        $this->assertNull($done->pass);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testALateHashDoesNotAnswerTheExpiredPass(): void
    {
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $this->deliverAsk();
        $mint = $this->nextNamed(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $mint->data);
        $since = new ReflectionProperty(BackupAgent::class, 'protectedModeOperatorSince');
        $since->setValue($this->operator, microtime(true) - CommandChannelWindows::AGENT_WAIT_SECONDS - 1.0);
        $this->tick();
        $done = $this->takeDone();
        $this->assertStringContainsString('did not record the pass', (string)$done->answer->error);
        $this->assertNull($done->pass);
        $this->giveToPage($done);
        $this->assertStringContainsString('did not record the pass', $this->failure()->reason);

        $this->row->passHashes = [$mint->data->passHash];
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testCloseDropsAHeldReleaseAndAnswersOnlyOnceActive(): void
    {
        $gate = (new ReflectionProperty(BackupAgent::class, 'restoreReleaseGate'))->getValue($this->operator);
        $this->assertInstanceOf(RestoreReleaseGate::class, $gate);
        $gate->noteSessionsDeferred(2);
        $this->operator->onSignalAgent(
            new AgentSignalData(new BackupReopenSignalData(self::ACCEPT_KEY)),
            'test',
            HilosSignalConstants::BACKUP_AGENT_REOPEN,
        );
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'A release owed logins stays parked');

        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE);
        $this->deliverAsk();
        $this->assertSame(SignalTypeConstants::PROTECTED_MODE_REFREEZE, $this->nextNamed(
            SignalTypeConstants::PROTECTED_MODE_REFREEZE,
        )->signalName->getName());
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->row->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        $this->tick();
        $done = $this->takeDone();
        $this->assertNull($done->answer->error);
        $this->assertNull($done->pass);
        $this->giveToPage($done);
        $ack = $this->success(HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE);
        $this->assertSame('The window is closed again', $ack->message);

        $release = new ReflectionMethod(BackupAgent::class, 'releaseProtectedModeIfDue');
        $release->invoke($this->operator);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'The close dropped the parked release');
    }

    public function testTheDiagnosticTupleReachesTheInitiatorsFrame(): void
    {
        $this->pages->dispatchPageSubscribe(new WebSocketPageSubscribeSignalDTO(
            self::ACCEPT_KEY,
            HilosPageConstants::HILOS_MAINTENANCE,
            [],
        ), 'websocket', HilosPageConstants::HILOS_MAINTENANCE);
        $signal = $this->nextNamed(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $frame = $signal->data->data;
        $this->assertInstanceOf(HilosMaintenanceStateSignalData::class, $frame);
        $wire = $frame->toArray();

        $this->assertTrue($wire[HilosMaintenanceStateSignalData::isInitiator]);
        $this->assertSame('node-restore', $wire[HilosMaintenanceStateSignalData::diagnosticsNodeId]);
        $this->assertSame(4, $wire[HilosMaintenanceStateSignalData::stoppedAgentCount]);
        $this->assertSame(
            [HilosAgentType::HILOS_BACKUP],
            $wire[HilosMaintenanceStateSignalData::preservedAgentTypes],
        );
        $this->assertTrue($wire[HilosMaintenanceStateSignalData::agentStartGateClosed]);
        $this->assertFalse($wire[HilosMaintenanceStateSignalData::manual]);
    }

    private function dispatch(string $action): void
    {
        $this->pages->dispatchAction(new WebSocketActionSignalDTO(
            self::ACCEPT_KEY,
            $action,
            [],
            self::REQUEST_ID,
        ), 'websocket');
    }

    private function deliverAsk(): void
    {
        $signal = $this->nextNamed(HilosSignalConstants::MAINTENANCE_AGENT_RESTORE_CONTROL);
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(MaintenanceRestoreControlSignalData::class, $signal->data->data);
        $envelope = Hilos::$sr->createAgentSignalPayloadDTO(
            $signal->signalName->getName(),
            new AgentSignalData(new SignalData($signal->data->data->toArray())),
        );
        $this->assertInstanceOf(MaintenanceRestoreControlSignalData::class, $envelope->data);
        $this->operator->onSignalAgent(
            $envelope,
            SignalSource::describe($signal->signalSource),
            $signal->signalName->getName(),
        );
    }

    private function takeDone(): MaintenanceRestoreControlDoneSignalData
    {
        $signal = $this->nextNamed(HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE);
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $done = $signal->data->data;
        $this->assertInstanceOf(MaintenanceRestoreControlDoneSignalData::class, $done);

        return $done;
    }

    private function giveToPage(MaintenanceRestoreControlDoneSignalData $done): void
    {
        $this->pages->dispatchAgentSignal(
            new AgentSignalData(new SignalData($done->toArray())),
            'agent/' . HilosAgentType::HILOS_BACKUP,
            HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE,
        );
    }

    private function tick(): void
    {
        $tick = new ReflectionMethod(BackupAgent::class, 'tickProtectedModeOperator');
        $tick->invoke($this->operator);
    }

    private function nextNamed(string $name): SignalDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame($name, $signal->signalName->getName());

        return $signal;
    }

    private function success(string $action): PageActionSuccessSignalData
    {
        $signal = $this->nextNamed(SignalConstants::ACTION_SUCCESS);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $signal->data->data);
        $this->assertSame($action, $signal->data->data->action);
        $this->assertSame(self::REQUEST_ID, $signal->data->data->requestId);

        return $signal->data->data;
    }

    private function failure(): PageActionErrorSignalData
    {
        $signal = $this->nextNamed(SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    private function commandReply(): CommandReplyDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(CommandReplyDTO::class, $signal->data);

        return $signal->data;
    }
}

final class MaintenanceRestoreControlPage extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class MaintenanceRestoreControlIndex extends AbstractHilosIndexAgent
{
    /** No worker boot is needed; the backup agent is the operator under test. */
    public function __construct()
    {
    }
}

abstract class MaintenanceRestoreControlHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_MAINTENANCE => MaintenanceRestoreControlPage::class,
    ];

    public const array AGENTS = [
        HilosAgentType::HILOS_INDEX => [AgentRegistryKey::WORKER => MaintenanceRestoreControlIndex::class],
        HilosAgentType::HILOS_BACKUP => [AgentRegistryKey::WORKER => BackupAgent::class],
    ];
}

final class MaintenanceRestoreControlRouter extends SignalRouter
{
    /** @return class-string<Hilos> Topology used for real destination resolution and payload hydration */
    protected function hilosClass(): string
    {
        return MaintenanceRestoreControlHilos::class;
    }
}

final class MaintenanceRestoreControlRtContext extends RtContext
{
    /** @param string $sessionToken Browser session to expose through the session source */
    public function __construct(private string $sessionToken)
    {
        parent::__construct();
    }

    /** Seeds the acting connection so the page reads the real session-source seam. */
    public function configure(): void
    {
        $connections = MaintenanceRestoreControlConnections::init();
        $connections->add(MaintenanceRestoreControlConnection::create(
            'restore-browser',
            7,
            $this->sessionToken,
        ));
        $this->_stateCollections[MaintenanceRestoreControlConnections::RT_COLLECTION] = $connections;
    }
}

final class MaintenanceRestoreControlConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'maintenanceRestoreControlConnections';

    public const string STATE_CLASS = MaintenanceRestoreControlConnection::class;
}

final class MaintenanceRestoreControlConnection extends HilosSessionConnection
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
