<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Maintenance\DTO\HilosMaintenanceStateSignalData;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** Holds the Maintenance state frame's first answer, live changes and admission guard. */
final class HilosMaintenancePageStateTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-maintenance';

    private const string OTHER_KEY = 'ak-maintenance-other';

    private const int STARTED_AT = 1_700_000_000;

    private const int ACTIVATED_AT = 1_700_000_100;

    private const int PROGRESS_AT = 1_700_000_200;

    private const string SESSION_A = 'maintenance-session-a';

    private const string SESSION_B = 'maintenance-session-b';

    private const string SESSION_C = 'maintenance-session-c';

    private const string OFF_FRAME_PASS = 'pass-must-stay-off-the-frame';

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new SignalRouter();
        Hilos::initBrowser();
        Hilos::$browser = new IdentityTestBrowser(userId: 1, admin: true);
        AbstractHilosMaintenancePage::reset();
    }

    protected function tearDown(): void
    {
        AbstractHilosMaintenancePage::reset();
        SourceInterestRegistry::releaseConsumer(
            SourceConsumer::feature(MaintenanceStateTestRtContext::SESSIONS),
        );
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::$browser = null;
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    public function testOpenStatePrecedesThePageResponse(): void
    {
        $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE, $signal?->signalName->getName());
        $this->assertSame([
            HilosMaintenanceStateSignalData::phase => StateProtectedModeRuntime::PHASE_INACTIVE,
            HilosMaintenanceStateSignalData::operation => null,
            HilosMaintenanceStateSignalData::manual => false,
            HilosMaintenanceStateSignalData::startedAt => null,
            HilosMaintenanceStateSignalData::enteredFromBrowser => false,
            HilosMaintenanceStateSignalData::passCount => 0,
            HilosMaintenanceStateSignalData::circleNamed => 0,
            HilosMaintenanceStateSignalData::circleAdmitted => 0,
            HilosMaintenanceStateSignalData::initiatorAgentType => null,
            HilosMaintenanceStateSignalData::initiatorNodeId => null,
            HilosMaintenanceStateSignalData::activatedAt => null,
            HilosMaintenanceStateSignalData::progressAt => null,
            HilosMaintenanceStateSignalData::isInitiator => false,
            HilosMaintenanceStateSignalData::diagnosticsNodeId => null,
            HilosMaintenanceStateSignalData::stoppedAgentCount => null,
            HilosMaintenanceStateSignalData::preservedAgentTypes => null,
            HilosMaintenanceStateSignalData::agentStartGateClosed => null,
        ], $this->payload($signal));
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
    }

    public function testNoRuntimeRowSendsNoStateFrame(): void
    {
        Hilos::$rt = null;
        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testManualAndOperationFramesCarryTheirOwnFacts(): void
    {
        $row = $this->mount(StateProtectedModeRuntime::PHASE_VERIFYING);
        $row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW;
        $row->operation = StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE;
        $row->initiatorAcceptKey = self::ACCEPT_KEY;
        $row->startedAt = self::STARTED_AT;
        $row->enteredFromBrowser = true;
        $row->passHashes = ['a', 'b'];
        $row->circleNamedCount = 3;
        $row->circleSessionTokenHashes = ['session-a'];

        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $manual = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertTrue($manual[HilosMaintenanceStateSignalData::manual]);
        $this->assertSame('manual_maintenance', $manual[HilosMaintenanceStateSignalData::operation]);
        $this->assertSame('2023-11-14T22:13:20.000000Z', $manual[HilosMaintenanceStateSignalData::startedAt]);
        $this->assertTrue($manual[HilosMaintenanceStateSignalData::enteredFromBrowser]);
        $this->assertSame(2, $manual[HilosMaintenanceStateSignalData::passCount]);
        $this->assertSame(3, $manual[HilosMaintenanceStateSignalData::circleNamed]);
        $this->assertSame(1, $manual[HilosMaintenanceStateSignalData::circleAdmitted]);
        $this->drain();

        $row->operation = 'restore';
        $row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_FREEZE;
        $this->page()->onSubscribe(self::OTHER_KEY, new PageRouteParams([]));
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
        $row->initiatorAcceptKey = self::OTHER_KEY;
        $this->page()->onSubscribe(self::OTHER_KEY, new PageRouteParams([]));
        $operation = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertFalse($operation[HilosMaintenanceStateSignalData::manual]);
        $this->assertSame('restore', $operation[HilosMaintenanceStateSignalData::operation]);
    }

    public function testStaleTickPushesChangedCodesOnlyAndSkipsLockedOutSubscriber(): void
    {
        $row = $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $page = $this->page();
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $page->onSubscribe(self::OTHER_KEY, new PageRouteParams([]));
        $this->drain();

        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertSame(2, $this->countStateSignals());

        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $row->phase = StateProtectedModeRuntime::PHASE_VERIFYING;
        $row->operation = StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE;
        $row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW;
        $row->initiatorAcceptKey = self::ACCEPT_KEY;
        $row->passHashes = ['first'];
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertSame(self::ACCEPT_KEY, $signal?->data->targetAcceptKey);
        $this->assertSame(1, $this->payload($signal)[HilosMaintenanceStateSignalData::passCount]);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $row->passHashes[] = 'second';
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertSame(2, $this->payload(Hilos::$sr?->getNextQueuedSignal())[HilosMaintenanceStateSignalData::passCount]);
    }

    public function testViewModeViewerReceivesEveryNonPersonalField(): void
    {
        Hilos::$browser = new MaintenanceStateViewerBrowser();
        $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $wire = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertCount(17, $wire);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $wire[HilosMaintenanceStateSignalData::phase]);
        $this->assertNull($wire[HilosMaintenanceStateSignalData::preservedAgentTypes]);
        $this->assertNotContains(HiddenValue::mark(), $wire);
    }

    public function testTwoSessionsShareTheFrameAndDifferOnlyByInitiator(): void
    {
        $row = $this->mountRestore();
        $connections = $this->mountSessions();
        $connections->add(MaintenanceStateSessionConnection::create(self::ACCEPT_KEY, 1, self::SESSION_A, 1));
        $connections->add(MaintenanceStateSessionConnection::create(self::OTHER_KEY, 2, self::SESSION_B, 2));
        $row->initiatorSessionTokenHash = StateProtectedModeRuntime::hashSessionToken(self::SESSION_A);
        $row->admittedSessionTokenHashes = [
            StateProtectedModeRuntime::hashSessionToken(self::SESSION_A),
            StateProtectedModeRuntime::hashSessionToken(self::SESSION_B),
        ];

        $page = $this->page();
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $page->onSubscribe(self::OTHER_KEY, new PageRouteParams([]));
        $initiator = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
        $other = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->drain();

        $this->assertTrue($initiator[HilosMaintenanceStateSignalData::isInitiator]);
        $this->assertFalse($other[HilosMaintenanceStateSignalData::isInitiator]);
        $this->assertSame($this->withoutInitiator($initiator), $this->withoutInitiator($other));
        $this->assertStringNotContainsString(self::SESSION_A, $this->encoded($initiator));
        $this->assertStringNotContainsString(
            StateProtectedModeRuntime::hashSessionToken(self::SESSION_A),
            $this->encoded($initiator),
        );

        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertTrue($this->payload(Hilos::$sr?->getNextQueuedSignal())[HilosMaintenanceStateSignalData::isInitiator]);
        $this->assertFalse($this->payload(Hilos::$sr?->getNextQueuedSignal())[HilosMaintenanceStateSignalData::isInitiator]);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $row->initiatorSessionTokenHash = StateProtectedModeRuntime::hashSessionToken(self::SESSION_B);
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $pushedInitiator = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $pushedOther = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $this->assertFalse($pushedInitiator[HilosMaintenanceStateSignalData::isInitiator]);
        $this->assertTrue($pushedOther[HilosMaintenanceStateSignalData::isInitiator]);
        $this->assertSame($this->withoutInitiator($initiator), $this->withoutInitiator($pushedInitiator));
        $this->assertSame($this->withoutInitiator($pushedInitiator), $this->withoutInitiator($pushedOther));
    }

    public function testNewRowsKeepUtcNullAndEmptyListAndOmitSecrets(): void
    {
        $row = $this->mountRestore();
        $row->startedAt = self::STARTED_AT;
        $row->activatedAt = self::ACTIVATED_AT;
        $row->progressAt = self::PROGRESS_AT;
        $row->initiatorAgentType = 'hilos_backup';
        $row->initiatorNodeId = 'node-1';
        $row->diagnosticsNodeId = 'node-1';
        $row->stoppedAgentCount = null;
        $row->preservedAgentTypes = null;
        $row->agentStartGateClosed = null;
        $row->initiatorAcceptKey = self::ACCEPT_KEY;
        $row->initiatorSessionTokenHash = StateProtectedModeRuntime::hashSessionToken(self::SESSION_A);
        $row->passHashes = [self::OFF_FRAME_PASS];

        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $wire = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->drain();

        $this->assertSame('hilos_backup', $wire[HilosMaintenanceStateSignalData::initiatorAgentType]);
        $this->assertSame('node-1', $wire[HilosMaintenanceStateSignalData::initiatorNodeId]);
        $this->assertSame('2023-11-14T22:13:20.000000Z', $wire[HilosMaintenanceStateSignalData::startedAt]);
        $this->assertSame('2023-11-14T22:15:00.000000Z', $wire[HilosMaintenanceStateSignalData::activatedAt]);
        $this->assertSame('2023-11-14T22:16:40.000000Z', $wire[HilosMaintenanceStateSignalData::progressAt]);
        $this->assertFalse($wire[HilosMaintenanceStateSignalData::isInitiator]);
        $this->assertSame('node-1', $wire[HilosMaintenanceStateSignalData::diagnosticsNodeId]);
        $this->assertNull($wire[HilosMaintenanceStateSignalData::stoppedAgentCount]);
        $this->assertNull($wire[HilosMaintenanceStateSignalData::preservedAgentTypes]);
        $this->assertNull($wire[HilosMaintenanceStateSignalData::agentStartGateClosed]);
        $this->assertSame(self::ACTIVATED_AT, $row->activatedAt);
        $this->assertSame(self::PROGRESS_AT, $row->progressAt);
        $encoded = $this->encoded($wire);
        $this->assertStringNotContainsString(self::OFF_FRAME_PASS, $encoded);
        $this->assertStringNotContainsString(self::ACCEPT_KEY, $encoded);
        $this->assertStringNotContainsString(self::SESSION_A, $encoded);
        $this->assertStringNotContainsString(
            StateProtectedModeRuntime::hashSessionToken(self::SESSION_A),
            $encoded,
        );

        $row->stoppedAgentCount = 0;
        $row->preservedAgentTypes = [];
        $row->agentStartGateClosed = false;
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $empty = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertSame(0, $empty[HilosMaintenanceStateSignalData::stoppedAgentCount]);
        $this->assertSame([], $empty[HilosMaintenanceStateSignalData::preservedAgentTypes]);
        $this->assertFalse($empty[HilosMaintenanceStateSignalData::agentStartGateClosed]);
    }

    public function testViewerReceivesThePreservedTypeListUnhidden(): void
    {
        Hilos::$browser = new MaintenanceStateViewerBrowser();
        $row = $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $row->preservedAgentTypes = ['hilos_mail'];
        $row->stoppedAgentCount = 6;
        $row->agentStartGateClosed = true;
        $row->passHashes = [self::OFF_FRAME_PASS];
        $row->initiatorSessionTokenHash = StateProtectedModeRuntime::hashSessionToken(self::SESSION_A);

        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $wire = $this->payload(Hilos::$sr?->getNextQueuedSignal());

        $this->assertSame(['hilos_mail'], $wire[HilosMaintenanceStateSignalData::preservedAgentTypes]);
        $this->assertSame(6, $wire[HilosMaintenanceStateSignalData::stoppedAgentCount]);
        $this->assertTrue($wire[HilosMaintenanceStateSignalData::agentStartGateClosed]);
        $this->assertNotContains(HiddenValue::mark(), $wire);
        $this->assertStringNotContainsString(self::OFF_FRAME_PASS, $this->encoded($wire));
        $this->assertStringNotContainsString(
            StateProtectedModeRuntime::hashSessionToken(self::SESSION_A),
            $this->encoded($wire),
        );
    }

    public function testResubscribeRecomputesInitiatorFromTheCurrentSession(): void
    {
        $row = $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $connections = $this->mountSessions();
        $connection = MaintenanceStateSessionConnection::create(self::ACCEPT_KEY, 1, self::SESSION_A, 1);
        $connections->add($connection);
        $row->initiatorSessionTokenHash = StateProtectedModeRuntime::hashSessionToken(self::SESSION_A);

        $page = $this->page();
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->assertTrue($this->payload(Hilos::$sr?->getNextQueuedSignal())[HilosMaintenanceStateSignalData::isInitiator]);
        $this->drain();

        $page->onUnsubscribe(self::ACCEPT_KEY);
        $connection->applyDiff([HilosSessionConnection::sessionToken => self::SESSION_C]);
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->assertFalse($this->payload(Hilos::$sr?->getNextQueuedSignal())[HilosMaintenanceStateSignalData::isInitiator]);
    }

    public function testOldPayloadReadsNewFieldsAsAbsent(): void
    {
        $restored = HilosMaintenanceStateSignalData::fromArray([
            HilosMaintenanceStateSignalData::phase => StateProtectedModeRuntime::PHASE_INACTIVE,
            HilosMaintenanceStateSignalData::operation => null,
            HilosMaintenanceStateSignalData::manual => false,
            HilosMaintenanceStateSignalData::startedAt => null,
            HilosMaintenanceStateSignalData::enteredFromBrowser => false,
            HilosMaintenanceStateSignalData::passCount => 0,
            HilosMaintenanceStateSignalData::circleNamed => 0,
            HilosMaintenanceStateSignalData::circleAdmitted => 0,
        ]);

        $this->assertFalse($restored->isInitiator);
        $this->assertNull($restored->initiatorAgentType);
        $this->assertNull($restored->stoppedAgentCount);
        $this->assertNull($restored->preservedAgentTypes);
        $this->assertNull($restored->agentStartGateClosed);
        $this->assertArrayHasKey(
            HilosMaintenanceStateSignalData::isInitiator,
            $restored->toArray(),
        );
        $this->assertSame([], HilosMaintenanceStateSignalData::fromArray([
            HilosMaintenanceStateSignalData::phase => StateProtectedModeRuntime::PHASE_INACTIVE,
            HilosMaintenanceStateSignalData::manual => false,
            HilosMaintenanceStateSignalData::enteredFromBrowser => false,
            HilosMaintenanceStateSignalData::passCount => 0,
            HilosMaintenanceStateSignalData::circleNamed => 0,
            HilosMaintenanceStateSignalData::circleAdmitted => 0,
            HilosMaintenanceStateSignalData::preservedAgentTypes => [],
        ])->preservedAgentTypes);
    }

    private function page(): MaintenanceStateTestPage
    {
        return new MaintenanceStateTestPage(new MaintenanceStateTestAgent());
    }

    private function mount(string $phase): StateProtectedModeRuntime
    {
        $row = StateProtectedModeRuntime::create();
        $row->phase = $phase;
        Hilos::$rt = new MaintenanceStateTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, $row);

        return $row;
    }

    private function mountRestore(): StateProtectedModeRuntime
    {
        $row = $this->mount(StateProtectedModeRuntime::PHASE_VERIFYING);
        $row->operation = 'restore';
        $row->entryMode = StateProtectedModeRuntime::ENTRY_MODE_FREEZE;

        return $row;
    }

    private function mountSessions(): MaintenanceStateSessionConnections
    {
        $connections = MaintenanceStateSessionConnections::init();
        $context = Hilos::$rt;
        $this->assertInstanceOf(MaintenanceStateTestRtContext::class, $context);
        $context->mountSessions($connections);

        return $connections;
    }

    /**
     * @param array<string, mixed> $payload One viewer's frame
     * @return array<string, mixed> The same frame without the personal flag
     */
    private function withoutInitiator(array $payload): array
    {
        unset($payload[HilosMaintenanceStateSignalData::isInitiator]);

        return $payload;
    }

    /** @param array<string, mixed> $payload One viewer's frame */
    private function encoded(array $payload): string
    {
        $encoded = json_encode($payload);
        $this->assertIsString($encoded);

        return $encoded;
    }

    /** @return array<string, mixed> Wire payload of one state signal */
    private function payload(mixed $signal): array
    {
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);

        return $signal->data->data->toArray();
    }

    private function drain(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            continue;
        }
    }

    private function countStateSignals(): int
    {
        $count = 0;
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            $count++;
        }

        return $count;
    }
}

final class MaintenanceStateTestPage extends AbstractHilosMaintenancePage
{
}

final class MaintenanceStateTestRtContext extends RtContext
{
    public const string SESSIONS = 'maintenanceStateSessions';

    public function configure(): void
    {
    }

    public function mountSessions(MaintenanceStateSessionConnections $connections): void
    {
        $this->mountFeatureCollection(self::SESSIONS, $connections);
    }
}

/**
 * Session-stage connection with no fields of its own, so a test can hang a token on an accept key.
 */
final class MaintenanceStateSessionConnection extends HilosSessionConnection
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
     * @return array<string, mixed> Subclass-owned half of the row
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Changed fields => values
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * @extends HilosSessionConnections<MaintenanceStateSessionConnection>
 */
final class MaintenanceStateSessionConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = MaintenanceStateSessionConnection::class;
}

final class MaintenanceStateViewerBrowser extends IdentityTestBrowser
{
    public function __construct()
    {
        parent::__construct(null, false);
    }

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return true;
    }
}

final class MaintenanceStateTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_index';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'hilos_index');
    }
}
