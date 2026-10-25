<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Cluster\ClusterContext;
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
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreCloseActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreOpenActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestorePassActionDTO;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlDoneSignalData;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Restore action payloads and the ask/done pair survive the wire on their own (HIL-1679). */
final class HilosMaintenanceRestoreActionTest extends TestCase
{
    private const string ACCEPT_KEY = 'restore-browser';

    private const string SESSION_TOKEN = 'restore-session-token';

    private const string OTHER_TOKEN = 'other-session-token';

    private const string REQUEST_ID = 'restore-submit';

    private ?ClusterContext $previousCluster = null;

    private bool $booted = false;

    private BackupAgent $operator;

    private PageSignalRouter $pages;

    private StateProtectedModeRuntime $row;

    protected function tearDown(): void
    {
        if ($this->booted) {
            AbstractHilosMaintenancePage::reset();
            Hilos::$sr = null;
            Hilos::$browser = null;
            Hilos::$rt = null;
            Hilos::$cluster = $this->previousCluster;
            $this->booted = false;
        }

        parent::tearDown();
    }

    /**
     * @param class-string<ActionPayloadDTO> $class Action DTO under test
     * @param string $action Browser action the DTO names
     */
    #[DataProvider('emptyRestoreActions')]
    public function testEmptyRestoreActionRoundTripsAndIgnoresTheBrowser(string $class, string $action): void
    {
        $dto = $class::fromArray([
            'control' => 'open',
            'pass' => 'forged-code',
            'acceptKey' => 'forged-browser',
            'sessionHash' => 'forged-session',
        ]);
        $restored = $class::fromArray(self::overTheWire($dto->toArray()));

        $this->assertInstanceOf($class, $restored);
        $this->assertEquals($dto, $restored);
        $this->assertSame([], $restored->toArray());
        $this->assertSame($action, $restored->getAction());
        $this->assertSame([], $class::SECRET_FIELDS);
    }

    /** @return array<string, array{class-string<ActionPayloadDTO>, string}> */
    public static function emptyRestoreActions(): array
    {
        return [
            'pass' => [MaintenanceRestorePassActionDTO::class, HilosSignalConstants::MAINTENANCE_RESTORE_PASS],
            'open' => [MaintenanceRestoreOpenActionDTO::class, HilosSignalConstants::MAINTENANCE_RESTORE_OPEN],
            'close' => [MaintenanceRestoreCloseActionDTO::class, HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE],
        ];
    }

    #[DataProvider('controls')]
    public function testAskRoundTripsEachControl(
        string $control,
        string $action,
        string $replySignal,
        ?string $successMessage,
    ): void {
        $ask = self::ask($control, $action, $replySignal, $successMessage);
        $restored = MaintenanceRestoreControlSignalData::fromArray(self::overTheWire($ask->toArray()));

        $this->assertInstanceOf(MaintenanceRestoreControlSignalData::class, $restored);
        $this->assertEquals($ask, $restored);
        $this->assertSame($control, $restored->control);
        $this->assertSame($action, $restored->action);
        $this->assertSame($replySignal, $restored->replySignal);
        $this->assertSame($successMessage, $restored->successMessage);
        $this->assertSame('browser', $restored->acceptKey);
        $this->assertSame('session', $restored->sessionHash);
        $this->assertSame('req-1', $restored->requestId);
    }

    /** @return array<string, array{string, string, string, ?string}> */
    public static function controls(): array
    {
        return [
            'pass' => [
                MaintenanceRestoreControlSignalData::CONTROL_PASS,
                HilosSignalConstants::MAINTENANCE_RESTORE_PASS,
                'page-names-the-reply',
                null,
            ],
            'open' => [
                MaintenanceRestoreControlSignalData::CONTROL_OPEN,
                HilosSignalConstants::MAINTENANCE_RESTORE_OPEN,
                HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE,
                'Restore is open',
            ],
            'close' => [
                MaintenanceRestoreControlSignalData::CONTROL_CLOSE,
                HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE,
                'another-reply',
                null,
            ],
        ];
    }

    /**
     * @param string $key Field replaced on a valid ask
     * @param mixed $value Replacement, or null to drop the key
     */
    #[DataProvider('refusedAsks')]
    public function testAskRefusesEmptyIdentityForeignControlAndMismatchedAction(string $key, mixed $value): void
    {
        $data = self::ask(
            MaintenanceRestoreControlSignalData::CONTROL_PASS,
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS,
            'page-names-the-reply',
            null,
        )->toArray();
        if ($value === null) {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }

        $this->expectException(InvalidFormatException::class);
        MaintenanceRestoreControlSignalData::fromArray($data);
    }

    /** @return array<string, array{string, mixed}> */
    public static function refusedAsks(): array
    {
        return [
            'empty accept key' => ['acceptKey', ''],
            'empty session' => ['sessionHash', ''],
            'missing accept key' => ['acceptKey', null],
            'missing session' => ['sessionHash', null],
            'mistyped session' => ['sessionHash', 7],
            'missing request' => ['requestId', null],
            'empty request' => ['requestId', ''],
            'mistyped request' => ['requestId', 7],
            'empty reply' => ['replySignal', ''],
            'foreign control' => ['control', 'freeze'],
            'mistyped control' => ['control', 7],
            'action of another control' => ['action', HilosSignalConstants::MAINTENANCE_RESTORE_OPEN],
        ];
    }

    #[DataProvider('doneOutcomes')]
    public function testDoneRoundTripsEachOutcome(
        string $control,
        string $action,
        bool $refused,
        ?string $pass,
    ): void {
        $done = new MaintenanceRestoreControlDoneSignalData(
            HandoverAnswerSignalData::to(
                self::ask($control, $action, 'page-names-the-reply', null),
                $refused ? ActionRefusal::said('refused') : null,
            ),
            $pass,
        );
        $restored = MaintenanceRestoreControlDoneSignalData::fromArray(self::overTheWire($done->toArray()));

        $this->assertInstanceOf(MaintenanceRestoreControlDoneSignalData::class, $restored);
        $this->assertInstanceOf(HandoverAnswerSignalData::class, $restored->answer);
        $this->assertEquals($done, $restored);
        $this->assertSame($pass, $restored->pass);
        $this->assertSame($action, $restored->answer->action);
        $this->assertSame($refused ? 'refused' : null, $restored->answer->error);
    }

    /** @return array<string, array{string, string, bool, ?string}> */
    public static function doneOutcomes(): array
    {
        return [
            'pass success' => [
                MaintenanceRestoreControlSignalData::CONTROL_PASS,
                HilosSignalConstants::MAINTENANCE_RESTORE_PASS,
                false,
                'raw+code/unchanged',
            ],
            'pass refusal' => [
                MaintenanceRestoreControlSignalData::CONTROL_PASS,
                HilosSignalConstants::MAINTENANCE_RESTORE_PASS,
                true,
                null,
            ],
            'open success' => [
                MaintenanceRestoreControlSignalData::CONTROL_OPEN,
                HilosSignalConstants::MAINTENANCE_RESTORE_OPEN,
                false,
                null,
            ],
            'open refusal' => [
                MaintenanceRestoreControlSignalData::CONTROL_OPEN,
                HilosSignalConstants::MAINTENANCE_RESTORE_OPEN,
                true,
                null,
            ],
            'close success' => [
                MaintenanceRestoreControlSignalData::CONTROL_CLOSE,
                HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE,
                false,
                null,
            ],
            'close refusal' => [
                MaintenanceRestoreControlSignalData::CONTROL_CLOSE,
                HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE,
                true,
                null,
            ],
        ];
    }

    public function testSuccessfulPassWithoutACodeIsRefused(): void
    {
        $missing = self::successfulPass();
        unset($missing['pass']);
        $empty = self::successfulPass();
        $empty['pass'] = '';

        $this->assertRefused($missing);
        $this->assertRefused($empty);
    }

    public function testSuccessfulPassWithoutCorrelationIsRefused(): void
    {
        $missing = self::successfulPass();
        unset($missing['answer']['requestId']);
        $empty = self::successfulPass();
        $empty['answer']['requestId'] = '';

        $this->assertRefused($missing);
        $this->assertRefused($empty);
    }

    public function testPassRefusalCannotCarryACode(): void
    {
        $payload = self::successfulPass();
        $payload['answer']['error'] = 'refused';

        $this->assertRefused($payload);
    }

    public function testOpenAndCloseCannotCarryACode(): void
    {
        foreach ([
            HilosSignalConstants::MAINTENANCE_RESTORE_OPEN,
            HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE,
        ] as $action) {
            foreach ([null, 'refused'] as $error) {
                $payload = self::successfulPass();
                $payload['answer']['action'] = $action;
                $payload['answer']['error'] = $error;
                $this->assertRefused($payload);
            }
        }
    }

    /**
     * @param string $control Restore control name
     * @param string $action Browser action paired with that control
     * @param string $replySignal Page-owned reply name; any nonempty string
     * @param ?string $successMessage Sentence spoken on success, or null
     */
    private static function ask(
        string $control,
        string $action,
        string $replySignal,
        ?string $successMessage,
    ): MaintenanceRestoreControlSignalData {
        return new MaintenanceRestoreControlSignalData(
            $control,
            'browser',
            'session',
            'req-1',
            $action,
            $replySignal,
            $successMessage,
        );
    }

    /** @return array<string, mixed> A successful pass carrying a code */
    private static function successfulPass(): array
    {
        return (new MaintenanceRestoreControlDoneSignalData(
            HandoverAnswerSignalData::to(
                self::ask(
                    MaintenanceRestoreControlSignalData::CONTROL_PASS,
                    HilosSignalConstants::MAINTENANCE_RESTORE_PASS,
                    'page-names-the-reply',
                    null,
                ),
                null,
            ),
            'raw+code/unchanged',
        ))->toArray();
    }

    /** @param array<string, mixed> $payload Outcome that must be refused */
    private function assertRefused(array $payload): void
    {
        try {
            MaintenanceRestoreControlDoneSignalData::fromArray($payload);
            $this->fail('A restore outcome that breaks the code rule was accepted');
        } catch (InvalidFormatException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param array<string, mixed> $payload Payload as the sender serialized it
     * @return array<string, mixed> The same payload after a JSON trip
     */
    private static function overTheWire(array $payload): array
    {
        return json_decode(json_encode($payload), true);
    }

    #[DataProvider('forwardedControls')]
    public function testPageAsksWithTheServerSessionAndTheControlSentence(
        string $action,
        string $control,
        string $sentence,
    ): void {
        $this->boot();
        $this->dispatch($action, ['pass' => 'forged-code', 'sessionHash' => 'forged-session']);
        $ask = $this->readAsk();

        $this->assertSame($control, $ask->control);
        $this->assertSame($action, $ask->action);
        $this->assertSame(self::ACCEPT_KEY, $ask->acceptKey);
        $this->assertSame(StateProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN), $ask->sessionHash);
        $this->assertSame(self::REQUEST_ID, $ask->requestId);
        $this->assertSame(HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE, $ask->replySignal);
        $this->assertSame($sentence, $ask->successMessage);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return array<string, array{string, string, string}> */
    public static function forwardedControls(): array
    {
        return [
            'pass' => [
                HilosSignalConstants::MAINTENANCE_RESTORE_PASS,
                MaintenanceRestoreControlSignalData::CONTROL_PASS,
                'Code issued',
            ],
            'open' => [
                HilosSignalConstants::MAINTENANCE_RESTORE_OPEN,
                MaintenanceRestoreControlSignalData::CONTROL_OPEN,
                'The system is open',
            ],
            'close' => [
                HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE,
                MaintenanceRestoreControlSignalData::CONTROL_CLOSE,
                'The window is closed again',
            ],
        ];
    }

    public function testUntrackedRequestRefusesBeforeTheAsk(): void
    {
        $this->boot();
        $this->pages->dispatchAction(new WebSocketActionSignalDTO(
            self::ACCEPT_KEY,
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS,
            [],
            null,
        ), 'websocket');

        $this->assertSame('A restore control requires a tracked request', $this->refusal()->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testMissingSessionRefusesBeforeTheAsk(): void
    {
        $this->boot();
        $this->remountConnections(null);

        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_OPEN);
        $this->assertSame('A browser session is required to control a restore', $this->refusal()->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testMissingRuntimeRefusesBeforeTheAsk(): void
    {
        $this->boot();
        $rt = new MaintenanceRestoreTestRtContext(self::SESSION_TOKEN);
        $rt->configure();
        Hilos::$rt = $rt;

        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_OPEN);
        $this->assertSame('Protected-mode state is unavailable', $this->refusal()->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testManualWindowRefusesBeforeTheAsk(): void
    {
        $this->boot();
        $this->row->operation = StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE;
        $this->row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW;

        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE);
        $this->assertSame('The current window is manual maintenance', $this->refusal()->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testForeignOperationRefusesBeforeTheAsk(): void
    {
        $this->boot();
        $this->row->operation = 'export';

        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $this->assertSame('The current window is not a restore', $this->refusal()->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testWrongPhaseRefusesBeforeTheAsk(): void
    {
        $this->boot();
        $this->row->phase = StateProtectedModeRuntime::PHASE_ACTIVE;

        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_OPEN);
        $this->assertSame('The current window is not verifying', $this->refusal()->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testAnotherSessionOfTheSameUserRefusesBeforeTheAsk(): void
    {
        $this->boot();
        $this->remountConnections(self::OTHER_TOKEN);

        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $error = $this->refusal();
        $this->assertSame('This browser session did not start the restore', $error->reason);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    #[DataProvider('deniedActors')]
    public function testNonAdminAndViewModeCannotForward(?int $userId, bool $viewMode, string $errorCode): void
    {
        $this->boot();
        Hilos::$browser = new IdentityTestBrowser($userId, false);
        Hilos::$rt->mountFeatureItem(AdminViewModeRuntime::RT_ITEM, AdminViewModeRuntime::fromRow([
            AdminViewModeRuntime::enabled => $viewMode,
        ]));

        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $error = $this->refusal();
        $this->assertSame($errorCode, $error->errorCode);
        $this->assertNull($error->errorType);
        $this->assertNull($error->errorDetail);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return array<string, array{?int, bool, string}> */
    public static function deniedActors(): array
    {
        return [
            'stranger' => [7, false, 'forbidden'],
            'viewer' => [7, true, 'view_mode'],
            'anonymous viewer' => [null, true, 'view_mode'],
            'anonymous' => [null, false, 'unauthorized'],
        ];
    }

    public function testAgentRefusesAChangedSessionWithoutBeginning(): void
    {
        $this->boot();
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $this->remountConnections(self::OTHER_TOKEN);

        $this->deliverAsk();
        $done = $this->takeDone();
        $this->assertNull($done->pass);
        $this->assertSame(
            'The browser session changed before the restore could be controlled',
            $done->answer->error,
        );
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->giveDoneToPage($done);
        $this->assertSame(
            'The browser session changed before the restore could be controlled',
            $this->refusal()->reason,
        );
    }

    public function testAgentRefusesAForeignInitiatorWithoutBeginning(): void
    {
        $this->boot();
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_OPEN);
        $this->row->initiatorAgentType = HilosAgentType::HILOS_INDEX;

        $this->deliverAsk();
        $done = $this->takeDone();
        $this->assertNull($done->pass);
        $this->assertSame('This agent did not start the restore', $done->answer->error);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testAgentRefusesWhenTheRecordedInitiatorIsMissing(): void
    {
        $this->boot();
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE);
        $this->row->initiatorAgentType = null;
        $this->row->initiatorAgentIndex = null;

        $this->deliverAsk();
        $done = $this->takeDone();
        $this->assertSame('This agent did not start the restore', $done->answer->error);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testPageWithholdsTheCodeWhenAdmissionChangesBeforeDisclosure(): void
    {
        $this->boot();
        $this->dispatch(HilosSignalConstants::MAINTENANCE_RESTORE_PASS);
        $this->deliverAsk();
        $mint = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $mint->data);
        $this->row->passHashes = [$mint->data->passHash];
        $this->tickOperator();
        $done = $this->takeDone();
        $this->assertIsString($done->pass);
        $this->row->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        $this->giveDoneToPage($done);

        $error = $this->refusal();
        $this->assertSame('The current window is not verifying', $error->reason);
        $this->assertStringNotContainsString($done->pass, json_encode($error->toArray()));
        $this->assertArrayNotHasKey('reply', $error->toArray());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    private function boot(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->booted = true;
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new MaintenanceRestoreTestRouter();
        Hilos::$browser = new IdentityTestBrowser(7, true);
        $rt = new MaintenanceRestoreTestRtContext(self::SESSION_TOKEN);
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
        ]);
        $rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, $this->row);
        Hilos::$rt = $rt;
        $this->operator = new BackupAgent();
        $this->pages = new PageSignalRouter(
            new HilosPageFactory(new MaintenanceRestoreTestIndex(), MaintenanceRestoreTestHilos::class),
            new ActionRouteConfig(MaintenanceRestoreTestHilos::getPageActionRoutes()),
            new SignalRouteConfig(MaintenanceRestoreTestHilos::getPageSignalRoutes()),
        );
        AbstractHilosMaintenancePage::reset();
    }

    private function remountConnections(?string $token): void
    {
        $rt = new MaintenanceRestoreTestRtContext($token);
        $rt->configure();
        $rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, $this->row);
        Hilos::$rt = $rt;
    }

    /** @param array<string, mixed> $data Browser payload the empty DTO ignores */
    private function dispatch(string $action, array $data = []): void
    {
        $this->pages->dispatchAction(new WebSocketActionSignalDTO(
            self::ACCEPT_KEY,
            $action,
            $data,
            self::REQUEST_ID,
        ), 'websocket');
    }

    private function readAsk(): MaintenanceRestoreControlSignalData
    {
        $signal = $this->nextSignal(HilosSignalConstants::MAINTENANCE_AGENT_RESTORE_CONTROL);
        $this->assertEquals(
            [new AgentDestination(HilosAgentType::HILOS_BACKUP)],
            Hilos::$sr->getDestinations($signal),
        );
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $ask = $signal->data->data;
        $this->assertInstanceOf(MaintenanceRestoreControlSignalData::class, $ask);

        return $ask;
    }

    private function deliverAsk(): void
    {
        $signal = $this->nextSignal(HilosSignalConstants::MAINTENANCE_AGENT_RESTORE_CONTROL);
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
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
        $signal = $this->nextSignal(HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE);
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $done = $signal->data->data;
        $this->assertInstanceOf(MaintenanceRestoreControlDoneSignalData::class, $done);

        return $done;
    }

    private function giveDoneToPage(MaintenanceRestoreControlDoneSignalData $done): void
    {
        $this->pages->dispatchAgentSignal(
            new AgentSignalData(new SignalData($done->toArray())),
            'agent/' . HilosAgentType::HILOS_BACKUP,
            HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE,
        );
    }

    private function tickOperator(): void
    {
        $tick = new ReflectionMethod(BackupAgent::class, 'tickProtectedModeOperator');
        $tick->invoke($this->operator);
    }

    private function nextSignal(string $name): SignalDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame($name, $signal->signalName->getName());

        return $signal;
    }

    private function refusal(): PageActionErrorSignalData
    {
        $signal = $this->nextSignal(SignalConstants::ACTION_ERROR);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $signal->data->data);

        return $signal->data->data;
    }
}

final class MaintenanceRestoreTestPage extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class MaintenanceRestoreTestIndex extends AbstractHilosIndexAgent
{
    /** No worker boot is needed; the backup agent is the operator under test. */
    public function __construct()
    {
    }
}

abstract class MaintenanceRestoreTestHilos extends Hilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_MAINTENANCE => MaintenanceRestoreTestPage::class,
    ];

    public const array AGENTS = [
        HilosAgentType::HILOS_INDEX => [AgentRegistryKey::WORKER => MaintenanceRestoreTestIndex::class],
        HilosAgentType::HILOS_BACKUP => [AgentRegistryKey::WORKER => BackupAgent::class],
    ];
}

final class MaintenanceRestoreTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Topology used for real destination resolution and payload hydration */
    protected function hilosClass(): string
    {
        return MaintenanceRestoreTestHilos::class;
    }
}

final class MaintenanceRestoreTestRtContext extends RtContext
{
    /** @param ?string $sessionToken Browser session to expose through the session source */
    public function __construct(private ?string $sessionToken)
    {
        parent::__construct();
    }

    /** Seeds the acting connection so the page reads the real session-source seam. */
    public function configure(): void
    {
        $connections = MaintenanceRestoreTestConnections::init();
        $connections->add(MaintenanceRestoreTestConnection::create(
            'restore-browser',
            7,
            $this->sessionToken,
        ));
        $this->_stateCollections[MaintenanceRestoreTestConnections::RT_COLLECTION] = $connections;
    }
}

final class MaintenanceRestoreTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'maintenanceRestoreConnections';

    public const string STATE_CLASS = MaintenanceRestoreTestConnection::class;
}

final class MaintenanceRestoreTestConnection extends HilosSessionConnection
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
