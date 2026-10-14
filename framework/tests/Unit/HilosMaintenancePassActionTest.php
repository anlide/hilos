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
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Exception\InvalidFormatException;
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
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Maintenance\DTO\MaintenancePassActionDTO;
use Hilos\ProtectedMode\DTO\ManualMaintenancePassSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenancePassDoneSignalData;
use Hilos\Pages\Maintenance\DTO\MaintenancePassReplyDTO;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
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
final class HilosMaintenancePassActionTest extends TestCase
{
    private const string ACCEPT_KEY = 'maintenance-browser';
    private const string SESSION_TOKEN = 'server-session-token';
    private const string REQUEST_ID = 'maintenance-submit';

    private ?ClusterContext $previousCluster;
    private MaintenancePassTestAgent $agent;
    private HilosPageFactory $factory;
    private PageSignalRouter $pages;
    private StateProtectedModeRuntime $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new MaintenancePassTestRouter();
        Hilos::$browser = new IdentityTestBrowser(7, true);
        Hilos::$rt = new MaintenancePassTestRtContext();
        Hilos::$rt->configure();
        $this->row = StateProtectedModeRuntime::fromRow([
            ...StateProtectedModeRuntime::create()->toArray(),
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::operation => StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
            StateProtectedModeRuntime::entryMode => StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
            StateProtectedModeRuntime::initiatorAgentType => HilosAgentType::HILOS_INDEX,
            StateProtectedModeRuntime::initiatorAcceptKey => self::ACCEPT_KEY,
            StateProtectedModeRuntime::initiatorSessionTokenHash => StateProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            StateProtectedModeRuntime::enteredFromBrowser => true,
        ]);
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, $this->row);
        $this->agent = new MaintenancePassTestAgent();
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
        $this->assertSame(HilosSignalConstants::MAINTENANCE_PASS, $ask->action);
        $this->assertSame(HilosSignalConstants::MAINTENANCE_PASS_DONE, $ask->replySignal);

        $mint = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS)->data;
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $mint);
        $this->assertSame(HilosAgentType::HILOS_INDEX, $mint->initiatorAgentType);
        $this->agent->onTick();
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

        $this->row->passHashes = [$mint->passHash];
        $this->agent->onTick();
        $this->deliverDone();
        $ack = $this->nextBrowserAnswer(SignalConstants::ACTION_SUCCESS);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $this->assertSame('Code issued', $ack->message);
        $this->assertNotEmpty($ack->reply['pass']);
        $this->assertSame($mint->passHash, hash('sha256', $ack->reply['pass']));
        $this->assertStringNotContainsString($ack->reply['pass'], json_encode($state->data->data->toArray()));
        $this->assertStringNotContainsString($ack->reply['pass'], json_encode($this->row->toArray()));
        $this->assertArrayNotHasKey('sessionHash', $ack->toArray());
        $this->row->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $this->agent->onTick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testRefusalArrivesWithTheSameCorrelationAfterPageRecreation(): void
    {
        $this->dispatch();
        $this->row->initiatorAgentType = 'other-agent';
        $this->deliverAsk();
        $this->recreatePages();
        $this->deliverDone();
        $error = $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $error);
        $this->assertSame('manual maintenance was initiated by another agent', $error->reason);
        $this->assertNull($error->errorType);
        $this->assertNull($error->errorDetail);
        $this->row->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $this->agent->onTick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testUnconfirmedOutcomeFailsTheTrackedSubmitAndLateHashStaysSilent(): void
    {
        $this->dispatch();
        $this->deliverAsk();
        $mint = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS)->data;
        $this->recreatePages();
        new ReflectionProperty(AbstractHilosIndexAgent::class, 'manualMaintenanceSince')->setValue(
            $this->agent, microtime(true) - CommandChannelWindows::AGENT_WAIT_SECONDS - 1.0,
        );
        $this->agent->onTick();
        $this->deliverDone();
        $error = $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $error);
        $this->assertSame(
            'The result of issuing a code is unconfirmed. A code may have been issued, but it cannot be recovered.'
            . ' Issue a new code if needed; turn maintenance off and on to invalidate all issued codes. '
            . 'protected mode did not record the pass within 13s; if it lands late, close and reopen the window to void it',
            $error->reason,
        );
        $this->row->passHashes = [$mint->passHash];
        $this->agent->onTick();
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
            $acceptKey, HilosSignalConstants::MAINTENANCE_PASS, [], self::REQUEST_ID,
        ), 'websocket');
        $error = $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR, $acceptKey);
        $this->assertSame('A browser session is required to issue a code', $error->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public static function missingSessions(): array
    {
        return [[false, self::ACCEPT_KEY], [true, 'missing-connection'], [true, 'sessionless'], [true, 'empty-session']];
    }

    #[DataProvider('admittedBrowsers')]
    public function testEveryAdmittedAdminCanPass(bool $browserEntry, string $admission): void
    {
        $this->row->enteredFromBrowser = $browserEntry;
        $hash = StateProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN);
        $this->row->initiatorAcceptKey = $browserEntry ? 'other-browser' : null;
        $this->row->initiatorSessionTokenHash = $browserEntry ? 'other-session' : null;
        if ($admission === 'initiator') {
            $this->row->initiatorAcceptKey = self::ACCEPT_KEY;
            $this->row->initiatorSessionTokenHash = $hash;
        } elseif ($admission === 'circle') {
            $this->row->circleSessionTokenHashes = [$hash];
        } else {
            $this->row->admittedSessionTokenHashes = [$hash];
        }
        $this->dispatch();
        $this->deliverAsk();
        $mint = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS)->data;
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->row->passHashes = [$mint->passHash];
        $this->agent->onTick();
        $this->deliverDone();
        $this->nextBrowserAnswer(SignalConstants::ACTION_SUCCESS);
    }

    public static function admittedBrowsers(): array
    {
        return [[true, 'initiator'], [true, 'circle'], [true, 'code'], [false, 'circle'], [false, 'code']];
    }

    #[DataProvider('invalidWindows')]
    public function testPageRefusesOutsideManualWindow(string $change, string $reason): void
    {
        $this->changeWindow($change);
        $this->dispatch();
        $this->assertSame($reason, $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR)->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public static function invalidWindows(): array
    {
        return [
            ['missing', 'Protected-mode state is unavailable'],
            ['restore', 'The current window is not manual maintenance'],
            ['inactive', 'The current window is not manual maintenance'],
            ['active', 'The current window is not manual maintenance'],
            ['entry', 'The current window is not manual maintenance'],
            ['outside', 'This browser session is not admitted to maintenance'],
        ];
    }

    #[DataProvider('changedBeforeDelivery')]
    public function testAgentRechecksBeforeSendingToCore(string $change): void
    {
        $this->dispatch();
        if ($change === 'rights') {
            Hilos::$browser = new IdentityTestBrowser(7, false);
        } elseif ($change === 'lookup') {
            Hilos::$browser = null;
        } else {
            $this->changeWindow($change);
        }
        $this->deliverAsk();
        $this->deliverDone();
        $error = $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR);
        $this->assertNotEmpty($error->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'Refusal must not enqueue a core pass');
    }

    public static function changedBeforeDelivery(): array
    {
        return [
            ['rights'], ['lookup'], ['session'], ['replaced-session'], ['missing'],
            ['outside'], ['restore'], ['inactive'], ['active'], ['entry'], ['owner'],
        ];
    }

    public function testRightsLookupFailureStillAnswersTheWaitingPageWithoutSendingToCore(): void
    {
        $this->dispatch();
        Hilos::$browser = new class (7, true) extends IdentityTestBrowser {
            /**
             * @param int $userId Acting user (unused)
             * @return bool Never returns
             * @throws DbCollectionNotReadableException When the fixture simulates missing identity wiring
             */
            public function isAdmin(int $userId): bool
            {
                throw new DbCollectionNotReadableException('Identity source is not readable');
            }
        };
        $this->deliverAsk();
        $this->deliverDone();
        $this->assertNotEmpty($this->nextBrowserAnswer(SignalConstants::ACTION_ERROR)->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testAskRoundtripKeepsCorrelationAndNullableSuccessSentence(): void
    {
        $ask = new ManualMaintenancePassSignalData(
            'browser', 'session', self::REQUEST_ID, HilosSignalConstants::MAINTENANCE_PASS,
            HilosSignalConstants::MAINTENANCE_PASS_DONE, null,
        );
        $this->assertEquals($ask, ManualMaintenancePassSignalData::fromArray($ask->toArray()));
    }

    private function changeWindow(string $change): void
    {
        switch ($change) {
            case 'session':
            case 'replaced-session':
                Hilos::$rt = new MaintenancePassTestRtContext($change === 'session' ? null : 'changed-session');
                Hilos::$rt->configure();
                Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, $this->row);
                break;
            case 'missing':
                Hilos::$rt = new MaintenancePassTestRtContext();
                Hilos::$rt->configure();
                break;
            case 'restore':
                $this->row->operation = 'restore';
                break;
            case 'inactive':
            case 'active':
                $this->row->phase = $change;
                break;
            case 'entry':
                $this->row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_FREEZE;
                break;
            case 'outside':
                $this->row->initiatorAcceptKey = 'other-browser';
                $this->row->initiatorSessionTokenHash = 'other-session';
                break;
            case 'owner':
                $this->row->initiatorAgentType = 'other-agent';
                break;
        }
    }

    public function testEmptyDtoIgnoresClientChoices(): void
    {
        $dto = MaintenancePassActionDTO::fromArray(['operation' => 'restore']);
        $this->assertSame([], $dto->toArray());
        $this->assertSame([], $dto::SECRET_FIELDS);
        $this->assertSame(HilosSignalConstants::MAINTENANCE_PASS, $dto->getAction());
    }

    #[DataProvider('invalidAskFields')]
    public function testAskRefusesMissingMistypedAndEmptyIdentity(string $key, mixed $value): void
    {
        $data = new ManualMaintenancePassSignalData(
            'browser', 'session', self::REQUEST_ID, HilosSignalConstants::MAINTENANCE_PASS,
            HilosSignalConstants::MAINTENANCE_PASS_DONE, null,
        )->toArray();
        if ($value === null) {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }
        $this->expectException(InvalidFormatException::class);
        ManualMaintenancePassSignalData::fromArray($data);
    }

    public static function invalidAskFields(): array
    {
        return [
            ['acceptKey', ''], ['sessionHash', ''], ['acceptKey', null], ['sessionHash', null],
            ['sessionHash', 7], ['requestId', null], ['requestId', ''], ['requestId', 7],
        ];
    }

    public function testUntrackedActionCannotMint(): void
    {
        $this->pages->dispatchAction(new WebSocketActionSignalDTO(
            self::ACCEPT_KEY, HilosSignalConstants::MAINTENANCE_PASS, [], null,
        ), 'websocket');
        $error = $this->nextSignal(SignalConstants::ACTION_ERROR)->data->data;
        $this->assertSame('Issuing a code requires a tracked request', $error->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testSecondRequestIsRefusedWhileTheFirstHashIsPending(): void
    {
        $this->dispatch();
        $this->deliverAsk();
        $mint = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS)->data;
        $this->dispatch();
        $this->deliverAsk();
        $this->deliverDone();
        $this->assertSame(
            'another protected-mode request is still in flight',
            $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR)->reason,
        );
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->row->passHashes = [$mint->passHash];
        $this->agent->onTick();
        $this->deliverDone();
        $this->nextBrowserAnswer(SignalConstants::ACTION_SUCCESS);
    }

    #[DataProvider('changedBeforeDisclosure')]
    public function testPageRechecksBeforeDisclosingTheSecret(string $change): void
    {
        $this->dispatch();
        $this->deliverAsk();
        $mint = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS)->data;
        $this->row->passHashes = [$mint->passHash];
        $this->agent->onTick();
        $done = $this->nextSignal(HilosSignalConstants::MAINTENANCE_PASS_DONE)->data->data;
        $this->assertNotEmpty($done->pass);
        if ($change === 'rights') {
            Hilos::$browser = new IdentityTestBrowser(7, false);
        } else {
            $this->changeWindow($change);
        }
        $this->recreatePages();
        $this->pages->dispatchAgentSignal(
            new AgentSignalData(new SignalData($done->toArray())),
            'agent/hilos_index', HilosSignalConstants::MAINTENANCE_PASS_DONE,
        );
        $error = $this->nextBrowserAnswer(SignalConstants::ACTION_ERROR);
        $this->assertNotEmpty($error->reason);
        $this->assertStringNotContainsString($done->pass, json_encode($error->toArray()));
        $this->assertArrayNotHasKey('reply', $error->toArray());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public static function changedBeforeDisclosure(): array
    {
        return [['rights'], ['session'], ['outside'], ['restore'], ['inactive'], ['active'], ['entry'], ['missing']];
    }

    public function testCodeRepliesRoundtripWithoutChangingTheRawValue(): void
    {
        $ask = new ManualMaintenancePassSignalData(
            self::ACCEPT_KEY, 'session-hash', self::REQUEST_ID,
            HilosSignalConstants::MAINTENANCE_PASS, HilosSignalConstants::MAINTENANCE_PASS_DONE, 'Code issued',
        );
        $done = new ManualMaintenancePassDoneSignalData(HandoverAnswerSignalData::to($ask, null), 'raw+code/unchanged');
        $this->assertEquals($done, ManualMaintenancePassDoneSignalData::fromArray($done->toArray()));
        $reply = new MaintenancePassReplyDTO($done->pass);
        $this->assertEquals($reply, MaintenancePassReplyDTO::fromArray($reply->toArray()));
        $refused = new ManualMaintenancePassDoneSignalData(HandoverAnswerSignalData::to($ask, ActionRefusal::said('refused')), null);
        $this->assertEquals($refused, ManualMaintenancePassDoneSignalData::fromArray($refused->toArray()));
    }

    #[DataProvider('invalidOutcomes')]
    public function testDoneRejectsInconsistentOutcomeWithoutLeakingCode(?string $error, ?string $pass, ?string $requestId): void
    {
        $this->expectException(InvalidFormatException::class);
        ManualMaintenancePassDoneSignalData::fromArray([
            'answer' => new HandoverAnswerSignalData(self::ACCEPT_KEY, $requestId, 'maintenance_pass', null, $error, null, null)->toArray(),
            'pass' => $pass,
        ]);
    }

    public static function invalidOutcomes(): array
    {
        return [[null, null, 'tracked'], [null, '', 'tracked'], [null, 'secret', null], [null, 'secret', ''], ['refused', 'secret', 'tracked']];
    }

    #[DataProvider('invalidReplyValues')]
    public function testReplyRequiresANonemptyString(array $data): void
    {
        $this->expectException(InvalidFormatException::class);
        MaintenancePassReplyDTO::fromArray($data);
    }

    public static function invalidReplyValues(): array
    {
        return [[[]], [['pass' => null]], [['pass' => '']], [['pass' => 7]]];
    }

    private function recreatePages(): void
    {
        $this->factory = new HilosPageFactory($this->agent, MaintenancePassTestHilos::class);
        $this->pages = new PageSignalRouter(
            $this->factory,
            new ActionRouteConfig(MaintenancePassTestHilos::getPageActionRoutes()),
            new SignalRouteConfig(MaintenancePassTestHilos::getPageSignalRoutes()),
        );
    }

    private function dispatch(array $data = []): void
    {
        $this->pages->dispatchAction(new WebSocketActionSignalDTO(
            self::ACCEPT_KEY, HilosSignalConstants::MAINTENANCE_PASS, $data, self::REQUEST_ID,
        ), 'websocket');
    }

    private function deliverAsk(): ManualMaintenancePassSignalData
    {
        $signal = $this->nextSignal(HilosSignalConstants::MAINTENANCE_AGENT_PASS);
        $this->assertEquals([new AgentDestination(HilosAgentType::HILOS_INDEX)], Hilos::$sr->getDestinations($signal));
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'No premature ack beside the ask');
        $envelope = Hilos::$sr->createAgentSignalPayloadDTO(
            $signal->signalName->getName(), new AgentSignalData(new SignalData($signal->data->data->toArray())),
        );
        $this->assertInstanceOf(ManualMaintenancePassSignalData::class, $envelope->data);
        $this->agent->onSignalAgent($envelope, SignalSource::describe($signal->signalSource), $signal->signalName->getName());

        return $envelope->data;
    }

    private function deliverDone(): void
    {
        $signal = $this->nextSignal(HilosSignalConstants::MAINTENANCE_PASS_DONE);
        $this->assertEquals([new AgentDestination(HilosAgentType::HILOS_INDEX)], Hilos::$sr->getDestinations($signal));
        $this->assertInstanceOf(ManualMaintenancePassDoneSignalData::class, $signal->data->data);
        if ($signal->data->data->answer->error !== null) {
            $this->assertNull($signal->data->data->pass);
        }
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
        $this->assertSame(HilosSignalConstants::MAINTENANCE_PASS, $signal->data->data->action);

        return $signal->data->data;
    }
}

final class MaintenancePassTestPage extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class MaintenancePassTestAgent extends AbstractHilosIndexAgent
{
    /** No worker boot is needed to exercise the real signal handler and protected-mode request. */
    public function __construct()
    {
    }
}

abstract class MaintenancePassTestHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_MAINTENANCE => MaintenancePassTestPage::class];
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => [AgentRegistryKey::WORKER => MaintenancePassTestAgent::class]];
}

final class MaintenancePassTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Topology used for real destination resolution and payload hydration */
    protected function hilosClass(): string
    {
        return MaintenancePassTestHilos::class;
    }
}

final class MaintenancePassTestRtContext extends RtContext
{
    /** @param ?string $sessionToken Browser session to expose through the session source */
    public function __construct(private ?string $sessionToken = 'server-session-token')
    {
        parent::__construct();
    }

    /** Seeds session rows so the page reads the real session-source seam. */
    public function configure(): void
    {
        $connections = MaintenancePassTestConnections::init();
        $connections->add(MaintenancePassTestConnection::create(
            'maintenance-browser', 7, $this->sessionToken,
        ));
        $connections->add(MaintenancePassTestConnection::create('sessionless', 7, null));
        $connections->add(MaintenancePassTestConnection::create('empty-session', 7, ''));
        $this->_stateCollections[MaintenancePassTestConnections::RT_COLLECTION] = $connections;
    }
}

final class MaintenancePassTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'maintenancePassConnections';
    public const string STATE_CLASS = MaintenancePassTestConnection::class;
}

final class MaintenancePassTestConnection extends HilosSessionConnection
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
