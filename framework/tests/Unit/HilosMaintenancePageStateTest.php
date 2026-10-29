<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Maintenance\DTO\HilosMaintenanceStateSignalData;
use Hilos\ProtectedMode\ProtectedModeSettingsCatalog;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        $this->previousSetting = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(ProtectedModeSettingsCatalog::class);
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
        Hilos::$setting = $this->previousSetting;
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
            HilosMaintenanceStateSignalData::watchdogVerdict => null,
            HilosMaintenanceStateSignalData::watchdogPendingNodeIds => [],
            HilosMaintenanceStateSignalData::watchdogQuiesceTimeoutSeconds => null,
            HilosMaintenanceStateSignalData::watchdogSilenceTimeoutSeconds => null,
            HilosMaintenanceStateSignalData::watchdogAlertIntervalSeconds => null,
            HilosMaintenanceStateSignalData::watchdogAlertEmails => null,
            HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal => null,
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
        $this->assertCount(24, $wire);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $wire[HilosMaintenanceStateSignalData::phase]);
        $this->assertNull($wire[HilosMaintenanceStateSignalData::preservedAgentTypes]);
        $this->assertSame([
            HilosMaintenanceStateSignalData::watchdogAlertEmails => HiddenValue::mark(),
        ], array_filter($wire, HiddenValue::isMark(...)));
    }

    public function testTwoSessionsShareTheFrameAndDifferOnlyByInitiator(): void
    {
        $row = $this->mountRestore();
        $this->publishWatchdogState($row);
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
        $this->assertWatchdogState($initiator);
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
        $this->assertSame([
            HilosMaintenanceStateSignalData::watchdogAlertEmails => HiddenValue::mark(),
        ], array_filter($wire, HiddenValue::isMark(...)));
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
        $this->assertNull($restored->watchdogVerdict);
        $this->assertSame([], $restored->watchdogPendingNodeIds);
        $this->assertNull($restored->watchdogQuiesceTimeoutSeconds);
        $this->assertNull($restored->watchdogSilenceTimeoutSeconds);
        $this->assertNull($restored->watchdogAlertIntervalSeconds);
        $this->assertNull($restored->watchdogAlertEmails);
        $this->assertNull($restored->watchdogManualRestartIsNormal);
        $this->assertSame($this->stateData()->toArray(), $restored->toArray());
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

    /**
     * @param string $field Watchdog field changed in isolation
     * @param mixed $value New published value
     */
    #[DataProvider('watchdogChanges')]
    public function testWatchdogChangePushesOnceAndSubscribeDoesNotConsumeTheBroadcast(string $field, mixed $value): void
    {
        $row = $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $page = $this->page();
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->drain();
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->drain();

        $default = $row->{$field};
        $row->{$field} = $value;
        AbstractHilosMaintenancePage::markStale();
        $page->onSubscribe(self::OTHER_KEY, new PageRouteParams([]));
        $this->assertSame($value, $this->payload(Hilos::$sr?->getNextQueuedSignal())[$field]);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        foreach ([self::ACCEPT_KEY, self::OTHER_KEY] as $acceptKey) {
            $signal = Hilos::$sr?->getNextQueuedSignal();
            $this->assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE, $signal?->signalName->getName());
            $this->assertSame($acceptKey, $signal?->data->targetAcceptKey);
            $this->assertSame($value, $this->payload($signal)[$field]);
        }
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $row->{$field} = $default;
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        foreach ([self::ACCEPT_KEY, self::OTHER_KEY] as $acceptKey) {
            $signal = Hilos::$sr?->getNextQueuedSignal();
            $this->assertSame($acceptKey, $signal?->data->targetAcceptKey);
            $this->assertSame($default, $this->payload($signal)[$field]);
        }
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /** @return iterable<string, array{string, mixed}> Independent changes of every watchdog field */
    public static function watchdogChanges(): iterable
    {
        yield 'verdict' => [HilosMaintenanceStateSignalData::watchdogVerdict, 'silent'];
        yield 'pending nodes' => [HilosMaintenanceStateSignalData::watchdogPendingNodeIds, ['node-2', 'node-3']];
        yield 'quiesce threshold' => [HilosMaintenanceStateSignalData::watchdogQuiesceTimeoutSeconds, 31];
        yield 'silence threshold' => [HilosMaintenanceStateSignalData::watchdogSilenceTimeoutSeconds, 301];
        yield 'reminder interval' => [HilosMaintenanceStateSignalData::watchdogAlertIntervalSeconds, 601];
        yield 'recipients' => [HilosMaintenanceStateSignalData::watchdogAlertEmails, ['operator@example.test']];
        yield 'manual restart policy' => [HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal, false];
    }

    /**
     * @param ?list<string> $emails Recipients before viewer masking
     * @param ?bool $policy Worker-reported manual restart policy
     */
    #[DataProvider('viewerWatchdogValues')]
    public function testViewerGetsRecipientsHiddenWholeOnFirstAnswerAndPush(?array $emails, ?bool $policy): void
    {
        Hilos::$browser = new MaintenanceStateViewerBrowser();
        $row = $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->publishWatchdogState($row);
        $row->watchdogAlertEmails = $emails;
        $row->watchdogManualRestartIsNormal = $policy;
        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $first = Hilos::$sr?->getNextQueuedSignal();
        $wire = $this->payload($first);
        $this->assertInstanceOf(SignalData::class, $first?->data->data);
        $this->assertWatchdogState($wire, HiddenValue::mark(), $policy);
        foreach ($emails ?? [] as $email) {
            $this->assertStringNotContainsString($email, $this->encoded($wire));
        }
        $this->assertSame($wire, WebSocketSignalData::fromArray($first->data->toArray())->data->toArray());
        $this->drain();

        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $pushed = Hilos::$sr?->getNextQueuedSignal();
        $this->assertInstanceOf(SignalData::class, $pushed?->data->data);
        $this->assertSame($wire, $this->payload($pushed));
        $this->assertSame($wire, WebSocketSignalData::fromArray($pushed->data->toArray())->data->toArray());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /** @return iterable<string, array{?list<string>, ?bool}> Hidden null, empty and populated recipient fields */
    public static function viewerWatchdogValues(): iterable
    {
        yield 'unpublished' => [null, null];
        yield 'configured empty' => [[], false];
        yield 'configured recipients' => [['operator@example.test', 'backup@example.test'], true];
    }

    public function testManualRestartPolicyUsesItsCatalogVisibilityOnBothDeliveryPaths(): void
    {
        $fields = HilosMaintenanceStateSignalData::wireFields();
        $this->assertArrayNotHasKey(HilosMaintenanceStateSignalData::watchdogAlertEmails, $fields);
        $this->assertSame(
            [ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL],
            $fields[HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal]->settingKeys,
        );
        $this->assertTrue(Hilos::$setting->visibleInAdminViewMode(ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL));
        foreach ([
            HilosMaintenanceStateSignalData::watchdogVerdict,
            HilosMaintenanceStateSignalData::watchdogPendingNodeIds,
            HilosMaintenanceStateSignalData::watchdogQuiesceTimeoutSeconds,
            HilosMaintenanceStateSignalData::watchdogSilenceTimeoutSeconds,
            HilosMaintenanceStateSignalData::watchdogAlertIntervalSeconds,
        ] as $field) {
            $this->assertFalse($fields[$field]->isSetting());
            $this->assertFalse($fields[$field]->isColumn());
            $this->assertNull($fields[$field]->fields);
        }

        Hilos::$setting = new class extends SettingsAccessor {
            /** @return array<string, array<string, mixed>> Catalog with the manual restart policy closed to viewers */
            protected function getCatalog(): array
            {
                $catalog = ProtectedModeSettingsCatalog::getCatalog();
                $catalog[ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL][SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE] = false;

                return $catalog;
            }
        };
        Hilos::$browser = new MaintenanceStateViewerBrowser();
        $row = $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->publishWatchdogState($row);
        $this->page()->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $wire = $this->payload(Hilos::$sr?->getNextQueuedSignal());
        $this->assertSame(HiddenValue::mark(), $wire[HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal]);
        $this->drain();

        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertSame($wire, $this->payload(Hilos::$sr?->getNextQueuedSignal()));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /** @param bool $revokeAdmin Whether page access rather than freeze admission is revoked */
    #[DataProvider('revokedStateGuards')]
    public function testRevokedGuardClosesFirstAnswerAndWatchdogPush(bool $revokeAdmin): void
    {
        $row = $this->mount(StateProtectedModeRuntime::PHASE_INACTIVE);
        $this->publishWatchdogState($row);
        $page = $this->page();
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->drain();
        if ($revokeAdmin) {
            Hilos::$browser = new class(1, false) extends IdentityTestBrowser {
                /**
                 * @param string $pageClass Page whose viewer verdict is asked (unused)
                 * @param string $acceptKey Connection whose rights were revoked (unused)
                 * @return bool The view mode is off for this guard check
                 */
                public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
                {
                    return false;
                }
            };
        } else {
            $row->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        }

        $row->watchdogVerdict = 'silent';
        $page->onSubscribe(self::OTHER_KEY, new PageRouteParams([]));
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr?->getNextQueuedSignal()?->signalName->getName());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        AbstractHilosMaintenancePage::markStale();
        AbstractHilosMaintenancePage::onAgentTick(new MaintenanceStateTestAgent());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /** @return iterable<string, array{bool}> Each gate that must close state delivery */
    public static function revokedStateGuards(): iterable
    {
        yield 'admin rights revoked' => [true];
        yield 'freeze locks out' => [false];
    }

    /** @param ?string $verdict Each legal watchdog cause, including the cleared state */
    #[DataProvider('watchdogVerdicts')]
    public function testWatchdogDtoRoundTripAndInitiatorCopyCarryEveryField(?string $verdict): void
    {
        $wire = array_replace($this->stateData()->toArray(), [
            HilosMaintenanceStateSignalData::watchdogVerdict => $verdict,
            HilosMaintenanceStateSignalData::watchdogPendingNodeIds => ['node-2', 'node-3'],
            HilosMaintenanceStateSignalData::watchdogQuiesceTimeoutSeconds => 31,
            HilosMaintenanceStateSignalData::watchdogSilenceTimeoutSeconds => 301,
            HilosMaintenanceStateSignalData::watchdogAlertIntervalSeconds => 601,
            HilosMaintenanceStateSignalData::watchdogAlertEmails => ['operator@example.test', 'backup@example.test'],
            HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal => false,
        ]);
        $dto = HilosMaintenanceStateSignalData::fromArray($wire);
        $this->assertSame($wire, $dto->toArray());
        $this->assertSame($wire, HilosMaintenanceStateSignalData::fromJson($dto->toJson())->toArray());
        $this->assertSame(
            array_replace($wire, [HilosMaintenanceStateSignalData::isInitiator => true]),
            $dto->withIsInitiator(true)->toArray(),
        );
        $this->assertSame($wire, $dto->withIsInitiator(true)->withIsInitiator(false)->toArray());
        $this->assertSame($wire, $dto->toArray());
    }

    /** @return iterable<string, array{?string}> All legal verdicts and no alarm */
    public static function watchdogVerdicts(): iterable
    {
        yield 'clear' => [null];
        yield 'initiator lost' => ['initiator-lost'];
        yield 'restored from disk' => ['restored-from-disk'];
        yield 'quiesce overdue' => ['quiesce-overdue'];
        yield 'silent' => ['silent'];
    }

    /**
     * @param ?list<string> $emails Nullable or configured recipients
     * @param ?bool $policy Unknown or published manual restart policy
     */
    #[DataProvider('viewerWatchdogValues')]
    public function testWatchdogDtoKeepsNullableAndEmptyValues(?array $emails, ?bool $policy): void
    {
        $wire = array_replace($this->stateData()->toArray(), [
            HilosMaintenanceStateSignalData::watchdogAlertEmails => $emails,
            HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal => $policy,
        ]);
        $dto = HilosMaintenanceStateSignalData::fromArray($wire);
        $this->assertSame($wire, $dto->toArray());
        $this->assertSame($wire, HilosMaintenanceStateSignalData::fromJson($dto->toJson())->toArray());
        $this->assertSame(
            array_replace($wire, [HilosMaintenanceStateSignalData::isInitiator => true]),
            $dto->withIsInitiator(true)->toArray(),
        );
    }

    /**
     * @param string $field Payload field holding malformed input
     * @param mixed $value Rejected value
     */
    #[DataProvider('malformedWatchdogFields')]
    public function testWatchdogDtoRejectsMalformedFields(string $field, mixed $value): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosMaintenanceStateSignalData::fromArray(array_replace($this->stateData()->toArray(), [$field => $value]));
    }

    /** @return iterable<string, array{string, mixed}> Wrong scalar, list, enum and hidden-mark shapes */
    public static function malformedWatchdogFields(): iterable
    {
        yield 'verdict type' => [HilosMaintenanceStateSignalData::watchdogVerdict, false];
        yield 'unknown verdict' => [HilosMaintenanceStateSignalData::watchdogVerdict, 'unknown'];
        yield 'empty verdict' => [HilosMaintenanceStateSignalData::watchdogVerdict, ''];
        yield 'pending null' => [HilosMaintenanceStateSignalData::watchdogPendingNodeIds, null];
        yield 'pending scalar' => [HilosMaintenanceStateSignalData::watchdogPendingNodeIds, 'node-2'];
        yield 'pending map' => [HilosMaintenanceStateSignalData::watchdogPendingNodeIds, ['node' => 'node-2']];
        yield 'pending element' => [HilosMaintenanceStateSignalData::watchdogPendingNodeIds, ['node-2', 2]];
        yield 'quiesce string' => [HilosMaintenanceStateSignalData::watchdogQuiesceTimeoutSeconds, '31'];
        yield 'silence float' => [HilosMaintenanceStateSignalData::watchdogSilenceTimeoutSeconds, 301.0];
        yield 'interval boolean' => [HilosMaintenanceStateSignalData::watchdogAlertIntervalSeconds, false];
        yield 'recipients scalar' => [HilosMaintenanceStateSignalData::watchdogAlertEmails, 'operator@example.test'];
        yield 'recipients map' => [HilosMaintenanceStateSignalData::watchdogAlertEmails, ['email' => 'operator@example.test']];
        yield 'recipients element' => [HilosMaintenanceStateSignalData::watchdogAlertEmails, ['operator@example.test', 2]];
        yield 'recipients hidden mark' => [HilosMaintenanceStateSignalData::watchdogAlertEmails, HiddenValue::mark()];
        yield 'policy integer' => [HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal, 1];
        yield 'policy string' => [HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal, 'false'];
        yield 'policy hidden mark' => [HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal, HiddenValue::mark()];
    }

    public function testMalformedRecipientsRefusalDoesNotIncludeAnAddress(): void
    {
        try {
            HilosMaintenanceStateSignalData::fromArray(array_replace($this->stateData()->toArray(), [
                HilosMaintenanceStateSignalData::watchdogAlertEmails => ['operator@example.test', false],
            ]));
            $this->fail('Malformed recipient list must be refused');
        } catch (InvalidFormatException $e) {
            $this->assertStringNotContainsString('operator@example.test', $e->getMessage());
            $this->assertStringContainsString(HilosMaintenanceStateSignalData::watchdogAlertEmails, $e->getMessage());
        }
    }

    /** @return HilosMaintenanceStateSignalData Legacy constructor with unpublished watchdog defaults */
    private function stateData(): HilosMaintenanceStateSignalData
    {
        return new HilosMaintenanceStateSignalData(
            phase: StateProtectedModeRuntime::PHASE_INACTIVE,
            operation: null,
            manual: false,
            startedAt: null,
            enteredFromBrowser: false,
            passCount: 0,
            circleNamed: 0,
            circleAdmitted: 0,
            initiatorAgentType: null,
            initiatorNodeId: null,
            activatedAt: null,
            progressAt: null,
            isInitiator: false,
            diagnosticsNodeId: null,
            stoppedAgentCount: null,
            preservedAgentTypes: null,
            agentStartGateClosed: null,
        );
    }

    /** @param StateProtectedModeRuntime $row Runtime fixture receiving a complete published watchdog state */
    private function publishWatchdogState(StateProtectedModeRuntime $row): void
    {
        $row->watchdogVerdict = 'quiesce-overdue';
        $row->watchdogPendingNodeIds = ['node-2', 'node-3'];
        $row->watchdogQuiesceTimeoutSeconds = 31;
        $row->watchdogSilenceTimeoutSeconds = 301;
        $row->watchdogAlertIntervalSeconds = 601;
        $row->watchdogAlertEmails = ['operator@example.test', 'backup@example.test'];
        $row->watchdogManualRestartIsNormal = false;
    }

    /**
     * @param array<string, mixed> $wire Complete state frame
     * @param mixed $emails Recipients as visible to this connection, including a hidden mark
     * @param ?bool $policy Worker-reported manual restart policy
     */
    private function assertWatchdogState(
        array $wire,
        mixed $emails = ['operator@example.test', 'backup@example.test'],
        ?bool $policy = false,
    ): void
    {
        $this->assertSame('quiesce-overdue', $wire[HilosMaintenanceStateSignalData::watchdogVerdict]);
        $this->assertSame(['node-2', 'node-3'], $wire[HilosMaintenanceStateSignalData::watchdogPendingNodeIds]);
        $this->assertSame(31, $wire[HilosMaintenanceStateSignalData::watchdogQuiesceTimeoutSeconds]);
        $this->assertSame(301, $wire[HilosMaintenanceStateSignalData::watchdogSilenceTimeoutSeconds]);
        $this->assertSame(601, $wire[HilosMaintenanceStateSignalData::watchdogAlertIntervalSeconds]);
        $this->assertSame($emails, $wire[HilosMaintenanceStateSignalData::watchdogAlertEmails]);
        $this->assertSame($policy, $wire[HilosMaintenanceStateSignalData::watchdogManualRestartIsNormal]);
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
