<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\ProfileFlowStepSignalData;
use Hilos\Auth\Session\DTO\ProfileFlowCancelActionDTO;
use Hilos\Auth\Session\DTO\ProfileFlowsSignalData;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Collection\HilosProfileFlows;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for how the session holder keeps a profile window's step (HIL-1182).
 *
 * The users library runs a step and reports it by frame; the holder writes the session's record
 * and tells every tab of the session the whole list. What is pinned here is the part that needs no
 * database: a step written and told, a flow that ended taken away and told, a flow whose code died
 * left out of the list, and the Discard ending the flow for the whole session. The answer to the
 * submitting tab, the handshake and the change of person read and write session rows, so they are
 * pinned against a real database by the integration suite's ProfileFlowSessionIntegrationTest.
 */
final class ProfileFlowHolderTest extends TestCase
{
    /** Cookie token of the browser whose windows the cases move. */
    private const string SESSION_TOKEN = 'aaaabbbbccccddddeeeeffff00001182';

    /** Its tab that is on the wire. */
    private const string ACCEPT_KEY = 'accept-profile-flow';

    private const int USER_ID = 1182;

    private const string CURRENT = 'current@example.test';

    private const string NEW_EMAIL = 'new@example.test';

    /** Long enough that no case outlives the code its flow stands on. */
    private const int CODE_LIFETIME_MS = 900_000;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    /** @var ?class-string<Hilos> Facade class bound before the case declared the sign-in feature */
    private ?string $boundAppClass = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousRt = Hilos::$rt;
        Hilos::$rt = new ProfileFlowHolderTestRtContext();
        Hilos::$rt->mountFeatureRuntime([new AuthFeature()]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosProfileFlow::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        if ($this->boundAppClass !== null) {
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
            $this->boundAppClass = null;
        }
        RtTruthSourceRegistry::unregisterDaemon(StateHilosProfileFlow::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
    }

    public function testAStepIsWrittenAndToldToEveryTabOfTheSession(): void
    {
        $agent = new ProfileFlowHolderTestAgent();

        $this->step($agent, StateHilosProfileFlow::STEP_NEW_SENT, self::NEW_EMAIL);

        $flow = $this->flows()[$this->id()];
        $this->assertSame(self::USER_ID, $flow?->userId);
        $this->assertSame(StateHilosProfileFlow::STEP_NEW_SENT, $flow?->step);
        $this->assertSame(self::NEW_EMAIL, $flow?->target);
        $this->assertSame(
            [[
                'operation' => StepUpOperationKey::CHANGE_EMAIL,
                'step' => StateHilosProfileFlow::STEP_NEW_SENT,
                'address' => self::CURRENT,
                'target' => self::NEW_EMAIL,
            ]],
            $this->lastList()?->flows,
        );
    }

    public function testAFinishedFlowIsTakenAwayAndTheEmptyListIsTold(): void
    {
        $agent = new ProfileFlowHolderTestAgent();
        $this->step($agent, StateHilosProfileFlow::STEP_NEW_SENT, self::NEW_EMAIL);
        $this->drain();

        $this->step($agent, null);

        // The empty list is the sentence that closes the window in every other tab.
        $this->assertNull($this->flows()[$this->id()]);
        $this->assertSame([], $this->lastList()?->flows);
    }

    public function testAFlowWhoseCodeDiedIsLeftOutOfTheList(): void
    {
        $agent = new ProfileFlowHolderTestAgent();

        $this->step($agent, StateHilosProfileFlow::STEP_CURRENT_PROVEN, null, TimeHelper::nowMs() - 1);

        // The row waits for the tick; a tab must not open a window on a code nobody can enter.
        $this->assertNotNull($this->flows()[$this->id()]);
        $this->assertSame([], $this->lastList()?->flows);
    }

    public function testADiscardEndsTheFlowForTheWholeSession(): void
    {
        $this->declareSignInSurface();
        $agent = new ProfileFlowHolderTestAgent();
        $this->step($agent, StateHilosProfileFlow::STEP_CURRENT_SENT);
        $this->drain();

        $reply = $this->discard($agent);

        $this->assertNull($reply);
        $this->assertNull($this->flows()[$this->id()]);
        $this->assertSame([], $this->lastList()?->flows);
    }

    public function testADiscardOfAFlowAlreadyGoneIsAQuietSuccessThatStillTellsTheList(): void
    {
        $this->declareSignInSurface();
        $agent = new ProfileFlowHolderTestAgent();

        $this->assertNull($this->discard($agent));

        // Finished in another tab, or reclaimed by the tick without a frame: the tab discarding
        // may still hold the flow, and this is the moment it learns there is none.
        $this->assertSame([], $this->lastList()?->flows);
    }

    public function testAProjectWithNoSignInSurfaceDiscardsNothing(): void
    {
        $agent = new ProfileFlowHolderTestAgent();
        $this->step($agent, StateHilosProfileFlow::STEP_CURRENT_SENT);
        $this->drain();

        $this->assertNull($this->discard($agent));

        $this->assertNotNull($this->flows()[$this->id()]);
        $this->assertSame([], $this->drain());
    }

    public function testAStepWithoutTheProofItStandsOnIsRefusedOffTheWire(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProfileFlowStepSignalData::fromArray([
            'sessionToken' => self::SESSION_TOKEN,
            'userId' => self::USER_ID,
            'operation' => StepUpOperationKey::CHANGE_EMAIL,
            'step' => StateHilosProfileFlow::STEP_CURRENT_PROVEN,
            'address' => self::CURRENT,
            'initiatorAcceptKey' => self::ACCEPT_KEY,
        ]);
    }

    public function testTheEndOfAFlowCrossesTheWireWithNothingButItsWindow(): void
    {
        $frame = new ProfileFlowStepSignalData(
            self::SESSION_TOKEN,
            self::USER_ID,
            StepUpOperationKey::CHANGE_PASSWORD,
            null,
            null,
            null,
            null,
            self::ACCEPT_KEY,
            'request-1',
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
            ['sent' => false, 'resendAt' => 1_900_000_000_000, 'expiresAt' => null],
        );

        $this->assertEquals($frame, ProfileFlowStepSignalData::fromArray($frame->toArray()));
    }

    public function testADiscardNamesItsWindow(): void
    {
        $this->assertSame(
            StepUpOperationKey::CHANGE_PASSWORD,
            ProfileFlowCancelActionDTO::fromArray(['operation' => StepUpOperationKey::CHANGE_PASSWORD])->operation,
        );

        $this->expectException(InvalidFormatException::class);

        ProfileFlowCancelActionDTO::fromArray([]);
    }

    /**
     * Reports one step of the email window, as the users library does.
     *
     * @param ProfileFlowHolderTestAgent $agent Holder under test
     * @param ?string $step Step reached, or null when the flow is over
     * @param ?string $target New address, on the last step alone
     * @param ?int $expiresAt Moment the code of the proof dies, in epoch ms; a live one when null
     */
    private function step(
        ProfileFlowHolderTestAgent $agent,
        ?string $step,
        ?string $target = null,
        ?int $expiresAt = null,
    ): void {
        $agent->onSignalAgent(
            new AgentSignalData(data: new ProfileFlowStepSignalData(
                self::SESSION_TOKEN,
                self::USER_ID,
                StepUpOperationKey::CHANGE_EMAIL,
                $step,
                $step === null ? null : self::CURRENT,
                $target,
                $step === null ? null : $expiresAt ?? TimeHelper::nowMs() + self::CODE_LIFETIME_MS,
                self::ACCEPT_KEY,
            )),
            'test',
            HilosSignalConstants::HILOS_PROFILE_FLOW_STEP,
        );
    }

    /**
     * Presses Discard on the email window from the session's live tab.
     *
     * @param ProfileFlowHolderTestAgent $agent Holder under test
     * @return mixed What the holder answered
     */
    private function discard(ProfileFlowHolderTestAgent $agent): mixed
    {
        return $agent->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_PROFILE_FLOW_CANCEL,
            new ProfileFlowCancelActionDTO(StepUpOperationKey::CHANGE_EMAIL),
        );
    }

    /**
     * Binds a facade that declares the sign-in feature, which the Discard asks before it touches a flow.
     */
    private function declareSignInSurface(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ProfileFlowHolderTestHilos::class);
    }

    /**
     * @return ?ProfileFlowsSignalData The last list told to the session since the last drain, or null when none was
     */
    private function lastList(): ?ProfileFlowsSignalData
    {
        $last = null;
        foreach ($this->drain() as $payload) {
            if (
                $payload instanceof WebSocketSignalData
                && $payload->data instanceof ProfileFlowsSignalData
                && $payload->targetSessionTokenHash === ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN)
            ) {
                $last = $payload->data;
            }
        }

        return $last;
    }

    /**
     * @return list<mixed> Payloads of every signal queued since the last drain
     */
    private function drain(): array
    {
        $payloads = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payloads[] = $signal->data;
        }

        return $payloads;
    }

    /**
     * @return HilosProfileFlows Mounted collection under test
     */
    private function flows(): HilosProfileFlows
    {
        $flows = Hilos::$rt?->hilosProfileFlows;
        $this->assertInstanceOf(HilosProfileFlows::class, $flows);

        return $flows;
    }

    /**
     * @return string Row id of the email window of the session under test
     */
    private function id(): string
    {
        return StateHilosProfileFlow::idFor(
            ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            StepUpOperationKey::CHANGE_EMAIL,
        );
    }
}

/**
 * Runtime with the sign-in feature and the one live tab of the session the cases move.
 */
final class ProfileFlowHolderTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = ProfileFlowHolderTestConnections::init();
        $connections->add(ProfileFlowHolderTestConnection::create('accept-profile-flow', 1182, 'aaaabbbbccccddddeeeeffff00001182'));
        $this->_stateCollections[ProfileFlowHolderTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Facade of a fixture project that draws a sign-in surface.
 */
abstract class ProfileFlowHolderTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}

/**
 * Sessions library of a fixture project, which needs nothing beyond being instantiable.
 */
final class ProfileFlowHolderTestAgent extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class ProfileFlowHolderTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'profileFlowHolderTestConnections';

    public const string STATE_CLASS = ProfileFlowHolderTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class ProfileFlowHolderTestConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Own fields, of which this fixture has none
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Incoming field changes
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
