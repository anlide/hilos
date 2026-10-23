<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportInterface;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationDraft;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusComponentState;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Status\StatusSnapshot;
use Hilos\Status\StatusSeverity;
use Hilos\Status\StatusUpdateKind;
use Hilos\Status\StatusUpdateText;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real Status commits, real notification library and delivery journal; only the mail transport is a double. */
final class StatusAdminNotificationIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user', 'hilos_user_merge', 'hilos_identity', 'hilos_setting', 'hilos_notification',
        'hilos_notification_delivery', 'hilos_notification_preference', 'hilos_status_incident', 'hilos_status_incident_update',
    ];
    private const int AT = 1791722096789;

    /** @var array<string, mixed> Original facade instances */
    private array $globals = [];
    /** @var array<string, mixed> Original source bus state */
    private array $bus = [];
    /** @var array<string, mixed> Original reader interests and mode */
    private array $interests = [];
    /** @var array<string, string|false> Original process env values */
    private array $environment = [];
    private string $app;
    private StatusAgent $owner;
    private StatusNoticeLibrary $library;
    private StatusNoticeMailAgent $mail;
    private StatusNoticeSettingsOwner $settingsOwner;
    private StatusNoticeRuntime $runtime;
    private StatusNoticeCommitProbe $probe;

    /** Mounts production chains with each write attributed to its actual owner. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = Hilos::appClass();
        foreach (['db', 'rt', 'sr', 'notify', 'setting', 'env'] as $name) {
            $this->globals[$name] = new ReflectionProperty(Hilos::class, $name)->getValue();
        }
        foreach (['mirrors', 'reactions', 'provenance'] as $name) {
            $this->bus[$name] = new ReflectionProperty(SourceChangeBus::class, $name)->getValue();
        }
        foreach (['byConsumer', 'states', 'readsWhatIsDelivered'] as $name) {
            $this->interests[$name] = new ReflectionProperty(SourceInterestRegistry::class, $name)->getValue();
        }
        foreach (['HILOS_SERVER_TEXT_TIME_ZONE_MODE', 'HILOS_PROJECT_TIME_ZONE', 'MAIL_WORKER_COUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('HILOS_SERVER_TEXT_TIME_ZONE_MODE=recipient');
        putenv('HILOS_PROJECT_TIME_ZONE=Europe/Warsaw');
        putenv('MAIL_WORKER_COUNT=1');
        Hilos::$env = new EnvAccessor();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusNoticeHilos::class);
        $this->stubs(true);
        $this->stubs(false);
        $this->seedUsers(2);
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new StatusNoticeDbContext();
        Hilos::$db->configure();
        Hilos::$db->declareProcessWideReads();
        foreach (Hilos::$db->processWideReadKeys() as $key) {
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
        }
        $this->runtime = new StatusNoticeRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->bindStateCollectionNames();
        $this->runtime->declareProcessWideReads();
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, ProtectedModeRuntime::RT_ITEM);
        SourceInterestRegistry::readsWhatIsDelivered();
        Hilos::$rt = $this->runtime;
        Hilos::$sr = new StatusNoticeRouter();
        Hilos::$notify = new HilosNotifier();
        Hilos::$setting = new SettingsAccessor(StatusNoticeSettingsCatalog::class);
        $this->owner = new StatusAgent();
        $this->library = new StatusNoticeLibrary();
        $this->mail = new StatusNoticeMailAgent('1');
        $this->settingsOwner = new StatusNoticeSettingsOwner();
        foreach ([$this->owner, $this->library, $this->mail, $this->settingsOwner] as $agent) {
            OwnershipDeclaration::claimAll($agent);
            foreach ($agent::READS_DB as $key) {
                SourceInterestRegistry::register(SourceChange::KIND_DB, $key, SourceConsumer::agent($agent->getId()));
                SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
            }
            foreach ($agent::READS_RT as $key) {
                SourceInterestRegistry::register(SourceChange::KIND_RT, $key, SourceConsumer::agent($agent->getId()));
                SourceInterestRegistry::markReady(SourceChange::KIND_RT, $key);
            }
        }
        $this->setting(DeliveryChannelSettings::enabledKey('email'), true);
        $this->setting(DeliveryChannelSettings::enabledKey('other'), true);
        $this->asAgent($this->owner, fn () => $this->owner->onStart());
        $this->frames();
        $this->probe = new StatusNoticeCommitProbe($this->owner);
        SourceChangeBus::subscribe($this->probe);
    }

    /** Restores facades, source listeners, claims, interests and env even after a failing case. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->asAgent($this->mail, fn () => $this->mail->onStop());
            $this->stubs(true);
        } finally {
            foreach ([$this->owner, $this->library, $this->mail, $this->settingsOwner] as $agent) {
                TruthSourceRegistry::unregisterAgent($agent->getId());
                RtTruthSourceRegistry::unregisterAgent($agent->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
            }
            foreach ($this->bus as $name => $value) {
                new ReflectionProperty(SourceChangeBus::class, $name)->setValue(null, $value);
            }
            foreach ($this->interests as $name => $value) {
                new ReflectionProperty(SourceInterestRegistry::class, $name)->setValue(null, $value);
            }
            foreach ($this->globals as $name => $value) {
                new ReflectionProperty(Hilos::class, $name)->setValue(null, $value);
            }
            foreach ($this->environment as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->app);
            parent::tearDown();
        }
    }

    public function testRealCommitQueuesOnceThenTheLibraryPersistsBellAndSendsEmail(): void
    {
        $this->fresh([false, false]);
        $this->tick(self::AT);
        self::assertSame([['open' => true, 'tasks' => 0, 'frame' => null]], $this->probe->candidateWrites);
        self::assertCount(1, $this->incidents());
        self::assertSame(1, $this->tasks());
        self::assertSame([], $this->notices());
        self::assertSame([], $this->emitFrames($this->frames()), 'afterCommit only enqueues a task');
        $this->tick(self::AT + 1);
        $drafts = $this->deliver();
        self::assertCount(2, $drafts);
        self::assertSame(['1', '2'], array_column($this->notices(), 'user_id'));
        foreach ($drafts as $draft) {
            self::assertSame(['email'], $draft->channels);
            self::assertSame('status.possible_outage', $draft->type);
            self::assertSame('warning', $draft->severity);
            self::assertSame('Possible outage: Service', $draft->title);
        }
        self::assertSame(['email', 'email'], array_column($this->deliveries(), 'channel'));
        self::assertSame(['pending', 'pending'], array_column($this->deliveries(), 'status'));
        $this->sendMail();
        self::assertSame(['sent', 'sent'], array_column($this->deliveries(), 'status'));
        self::assertSame(['1', '1'], array_column($this->deliveries(), 'attempts'));
        self::assertCount(2, $this->mail->messages);
        self::assertSame('admin1@example.test', $this->mail->messages[0]->to);
        self::assertSame('Possible outage: Service', $this->mail->messages[0]->subject);
        self::assertStringContainsString('/hilos/status', $this->mail->messages[0]->text);
        self::assertSame([null, null], array_column($this->notices(), 'read_at'), 'Ordinary unread bells remain available');
    }

    public function testRollbackDropsNoticeAndRestoresIncidentAndRuntimeTogether(): void
    {
        $this->setting(StatusSettingsCatalog::PUBLISH_DELAY_SECONDS, 0);
        $this->probe->failTimeline = true;
        $this->fresh([false, false]);
        try {
            $this->tick(self::AT);
            self::fail('Injected rollback must escape the machine');
        } catch (SourceChangeSubscriberException) {
            self::assertSame([], $this->incidents());
            self::assertSame(0, $this->tasks());
            self::assertCount(0, Hilos::$rt->hilosStatusComponents);
            self::assertSame([], $this->emitFrames($this->frames()));
        }
        $this->tick(self::AT + 1);
        $this->tick(self::AT + 2);
        self::assertCount(2, $this->deliver());
        self::assertCount(1, $this->incidents());
        self::assertCount(2, $this->notices());
    }

    public function testFramesSeverityPublicationTeamRecoveryAndRestartNeverRepeatTheCreateEffect(): void
    {
        $this->fresh([true, false]);
        $this->tick(self::AT);
        $this->tick(self::AT + 1);
        $this->deliver();
        foreach ([[false, false], [true, false], [true, false]] as $index => $healthy) {
            $this->report(false, self::AT + 10 + $index, $healthy);
            $this->tick(self::AT + 10 + $index);
            self::assertSame([], $this->deliver());
        }
        $this->restart();
        $this->fresh([false, false], self::AT + 100);
        $this->tick(self::AT + 300000);
        $incidentId = (int)$this->incidents()[0]['id'];
        $this->asAgent($this->owner, fn () => Hilos::$db->statusIncidentUpdates->actions->append(
            $incidentId, StatusUpdateKind::TEAM, StatusSeverity::MAJOR_OUTAGE,
            new StatusUpdateText('Investigating.'), self::AT + 300001,
        ));
        $this->report(false, self::AT + 300002, [true, true]);
        $this->tick(self::AT + 300002);
        self::assertSame([], $this->deliver());
        self::assertCount(2, $this->notices());
        $this->report(false, self::AT + 300003, [false, false]);
        $this->tick(self::AT + 300003);
        $this->tick(self::AT + 300004);
        self::assertCount(2, $this->deliver());
        self::assertCount(2, $this->incidents());
        self::assertCount(4, $this->notices());
    }

    public function testAudienceExcludesBlockedMergedAndNonAdminAndKeepsBellWhenEmailIsMutedOrMissing(): void
    {
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin, block) VALUES
            (3, 'Blocked', 1, 1), (4, 'Merged', 1, 0), (5, 'Viewer', 0, 0), (6, 'No address', 1, 0)");
        Database::sqlRun('INSERT INTO hilos_user_merge (user_id, survivor_user_id) VALUES (4, 1)');
        Database::sqlRun("INSERT INTO hilos_notification_preference (user_id, channel) VALUES (2, 'email')");
        $this->fresh([false, false]);
        $this->tick(self::AT);
        $this->tick(self::AT + 1);
        self::assertCount(3, $this->deliver());
        self::assertSame([1, 2, 6], array_map('intval', array_column($this->notices(), 'user_id')));
        self::assertCount(1, $this->deliveries());
        $this->sendMail();
        self::assertCount(1, $this->mail->messages);
    }

    public function testGlobalEmailDisableDoesNotBecomeForcedByNarrowing(): void
    {
        $this->setting(DeliveryChannelSettings::enabledKey('email'), false);
        $this->fresh([false, false]);
        $this->tick(self::AT);
        $this->tick(self::AT + 1);
        self::assertCount(2, $this->deliver());
        self::assertCount(2, $this->notices());
        self::assertSame([], $this->deliveries());
        self::assertSame([], $this->mail->messages);
    }

    /** @return iterable<string, array{string, string, string}> Exact recipient/project/UTC text, with seconds and named offset */
    public static function zones(): iterable
    {
        yield 'recipient' => ['recipient', '2026-10-11 08:34:56 -04:00 (America/New_York)',
            '2026-10-11 08:39:56 -04:00 (America/New_York)'];
        yield 'project' => ['project', '2026-10-11 14:34:56 +02:00 (Europe/Warsaw)',
            '2026-10-11 14:39:56 +02:00 (Europe/Warsaw)'];
        yield 'utc' => ['utc', '2026-10-11 12:34:56 +00:00 (UTC)', '2026-10-11 12:39:56 +00:00 (UTC)'];
    }

    /**
     * @param string $mode Existing server text policy
     * @param string $detected Expected detection text
     * @param string $publish Expected deadline text
     */
    #[DataProvider('zones')]
    public function testRecipientFormattingLeavesMachineMillisecondsUnchanged(string $mode, string $detected, string $publish): void
    {
        putenv('HILOS_SERVER_TEXT_TIME_ZONE_MODE=' . $mode);
        $this->fresh([false, false]);
        $this->tick(self::AT);
        $this->tick(self::AT + 1);
        $drafts = $this->deliver();
        self::assertSame('Detected at ' . $detected . '. Publication scheduled for ' . $publish . '. See /hilos/status.', $drafts[0]->body);
        $payload = ['url' => '/hilos/status', 'incidentId' => (int)$this->incidents()[0]['id'], 'componentKey' => 'service',
            'detectedAt' => self::AT, 'publishAt' => self::AT + 300000];
        self::assertSame($payload, $drafts[0]->data);
        self::assertSame($payload, json_decode($this->notices()[0]['data'], true, flags: JSON_THROW_ON_ERROR));
        if ($mode === 'recipient') {
            self::assertStringContainsString('2026-10-11 21:34:56 +09:00 (Asia/Tokyo)', $drafts[1]->body);
        }
    }

    public function testAudienceLargerThanTheBoundProgressesWhileTheMachineIsIdle(): void
    {
        $this->seedUsers(40, 3);
        $this->fresh([false, false]);
        $this->tick(self::AT);
        self::assertSame([], $this->deliver());
        $this->tick(self::AT + 1);
        self::assertCount(32, $this->deliver());
        self::assertCount(32, $this->notices());
        $this->tick(self::AT + 2);
        self::assertCount(8, $this->deliver());
        self::assertCount(40, $this->notices());
        $this->tick(self::AT + 3);
        self::assertSame([], $this->deliver());
        self::assertCount(1, $this->incidents());
    }

    public function testFreezeDropsTheUnsentTailOfAnAlreadyStartedAudience(): void
    {
        $this->seedUsers(40, 3);
        $this->fresh([false, false]);
        $this->tick(self::AT);
        $this->tick(self::AT + 1);
        self::assertCount(32, $this->deliver());
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $db = Hilos::$db;
        Hilos::$db = null;
        $this->tick(self::AT + 2);
        self::assertSame(0, $this->tasks());
        Hilos::$db = $db;
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->fresh([false, false], self::AT + 3);
        $this->tick(self::AT + 3);
        self::assertSame([], $this->deliver());
        self::assertCount(32, $this->notices());
    }

    /** @return iterable<string, array{string}> Every occupied freeze phase */
    public static function phases(): iterable
    {
        foreach (['activating', 'active', 'verifying', 'deactivating'] as $phase) {
            yield $phase => [$phase];
        }
    }

    /** @param string $phase Current protected-mode phase */
    #[DataProvider('phases')]
    public function testFreezeDiscardsQueuedWorkWithoutDbReadsAndLiftWaitsForFreshObservation(string $phase): void
    {
        $this->fresh([false, false]);
        $this->tick(self::AT);
        self::assertSame(1, $this->tasks());
        $this->runtime->phase($phase);
        Hilos::$db = null;
        $this->frames();
        $this->tick(self::AT + 1);
        self::assertSame([], $this->frames());
        self::assertSame(0, $this->tasks());
        self::assertSame(StatusComponentState::MAINTENANCE, StatusSnapshot::read()->components['service']->state);
        $this->asAgent($this->owner, fn () => $this->owner->onStop());
        Hilos::$db = new StatusNoticeDbContext();
        Hilos::$db->configure();
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->asAgent($this->owner, fn () => $this->owner->onStart());
        $this->tick(self::AT + 300001);
        self::assertNull($this->incidents()[0]['published_at']);
        $this->fresh([false, false], self::AT + 300002);
        $this->tick(self::AT + 300002);
        $this->tick(self::AT + 300003);
        self::assertSame([], $this->deliver());
        self::assertCount(1, $this->incidents());
        self::assertNotNull($this->incidents()[0]['published_at']);
    }

    public function testStopAndRestartBeforeDrainDoNotAnnounceTheRecordedCandidateAgain(): void
    {
        $this->fresh([false, false]);
        $this->tick(self::AT);
        $this->asAgent($this->owner, fn () => $this->owner->onStop());
        self::assertSame(0, $this->tasks());
        $this->restart();
        $this->fresh([false, false], self::AT + 100);
        $this->tick(self::AT + 100);
        $this->tick(self::AT + 101);
        self::assertSame([], $this->deliver());
        self::assertCount(1, $this->incidents());
    }

    public function testFailedEmitRemainsVisibleAndIsConsumedWithoutRecreatingTheIncident(): void
    {
        $this->fresh([false, false]);
        $this->tick(self::AT);
        Hilos::$notify = new class extends HilosNotifier {
            public function emit(NotificationDraft $draft): void
            {
                throw new DatabaseException('Injected post-commit emit failure');
            }
        };
        try {
            $this->tick(self::AT + 1);
            self::fail('Post-commit emit failure must remain visible');
        } catch (DatabaseException $failure) {
            self::assertSame('Injected post-commit emit failure', $failure->getMessage());
        }
        self::assertCount(1, $this->incidents());
        Hilos::$notify = new HilosNotifier();
        $this->tick(self::AT + 2);
        self::assertCount(1, $drafts = $this->deliver());
        self::assertSame(2, $drafts[0]->userId, 'An ambiguous emit to admin 1 is never replayed');
        $this->report(false, self::AT + 3, [false, false]);
        $this->tick(self::AT + 3);
        self::assertSame([], $this->deliver());
        self::assertCount(1, $this->incidents());
    }

    public function testAfterCommitFailureCannotRollBackOrReplayTheAcceptedFact(): void
    {
        $this->probe->failAnnouncement = true;
        $this->fresh([false, false]);
        $this->tick(self::AT);
        self::assertCount(1, $this->incidents());
        $owed = Database::handlerEnd();
        self::assertCount(1, $owed);
        self::assertInstanceOf(AnnouncementFailedException::class, $owed[0]);
        Database::handlerStart();
        $this->tick(self::AT + 1);
        self::assertCount(2, $this->deliver());
        $this->report(false, self::AT + 2, [false, false]);
        $this->tick(self::AT + 2);
        self::assertSame([], $this->deliver());
        self::assertCount(1, $this->incidents());
    }

    /** @param int $at Local transition moment */
    private function tick(int $at): void
    {
        $this->asAgent($this->owner, fn () => $this->owner->tickAt($at));
    }

    /**
     * @template T
     * @param AbstractAgent $agent Actual owner whose grants apply
     * @param Closure(): T $operation One synchronous handler
     * @return T Handler result
     */
    private function asAgent(AbstractAgent $agent, Closure $operation): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), $operation);
    }

    /** @return int Queued candidate tasks, inspected only to prove the commit boundary */
    private function tasks(): int
    {
        return new ReflectionProperty(StatusAgent::class, 'candidateNotices')->getValue($this->owner)->count();
    }

    /** Restarts the actual instance, retaining only durable facts and its declared claims. */
    private function restart(): void
    {
        $this->owner = new StatusAgent();
        $this->asAgent($this->owner, fn () => $this->owner->onStart());
    }

    /**
     * @param list<bool> $healthy Reported presence on each node
     * @param int $at Fresh picture moment
     */
    private function fresh(array $healthy, int $at = self::AT): void
    {
        $this->report(true, $at - 1, $healthy);
        $this->report(false, $at, $healthy);
    }

    /**
     * @param bool $snapshot Whether this is full membership
     * @param int $at Distinct report identity
     * @param list<bool> $healthy Presence per online node
     */
    private function report(bool $snapshot, int $at, array $healthy): void
    {
        $nodes = [];
        foreach ($healthy as $index => $present) {
            $id = 'node' . $index;
            $roster = new DaemonProcessRoster([new DaemonWorkerPicture(1, 'regular', null, 4096,
                $present ? [new DaemonAgentPicture('probe', 'node', 'node')] : [])], null, 0);
            $nodes[] = new ClusterDaemonNodeView($id, true,
                new ClusterDaemonNodeSlot($id, new NodeDaemonPicture($id, NodeRole::Master, 9999999999, $roster), $at));
        }
        $this->owner->applyPicturePortion(new DaemonClusterPicturePortionSignalData($snapshot, $nodes), $at);
    }

    /** @return list<NotificationEmitSignalData> Real DTOs delivered through the library and channel intakes */
    private function deliver(): array
    {
        $drafts = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $frame->signalName->getName();
            if ($name === HilosSignalConstants::HILOS_NOTIFICATION_EMIT) {
                $destinations = Hilos::$sr->getDestinations($frame);
                self::assertCount(1, $destinations);
                self::assertInstanceOf(AgentDestination::class, $destinations[0]);
                self::assertSame($this->library::AGENT_TYPE, $destinations[0]->agentType);
                $dto = NotificationEmitSignalData::fromArray($frame->data->data->toArray());
                $drafts[] = $dto;
                $this->asAgent($this->library, fn () => $this->library->onSignalAgent(new AgentSignalData($dto), 'status', $name));
            } elseif ($name === HilosSignalConstants::HILOS_MAIL_DELIVER) {
                $this->asAgent($this->mail, fn () => $this->mail->onSignalAgent($frame->data, 'notifications', $name));
            }
        }
        return $drafts;
    }

    /** Pumps the existing async email pipeline to a terminal journal result. */
    private function sendMail(): void
    {
        for ($tick = 0; $tick < 24; $tick++) {
            $this->asAgent($this->mail, fn () => $this->mail->onTick());
        }
    }

    /** @return list<SignalDTO> Drained process frames */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $frame;
        }
        return $frames;
    }

    /**
     * @param list<SignalDTO> $frames Queued process frames
     * @return list<SignalDTO> Only notification-emitter frames
     */
    private function emitFrames(array $frames): array
    {
        return array_values(array_filter($frames,
            static fn (SignalDTO $frame): bool => $frame->signalName->getName() === HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
    }

    /** @return list<array<string, ?string>> Independent durable incidents */
    private function incidents(): array
    {
        return Database::sql('SELECT * FROM hilos_status_incident ORDER BY id')->rows();
    }

    /** @return list<array<string, ?string>> Independent persisted bells */
    private function notices(): array
    {
        return Database::sql('SELECT * FROM hilos_notification ORDER BY id')->rows();
    }

    /** @return list<array<string, ?string>> Independent email journal */
    private function deliveries(): array
    {
        return Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
    }

    /**
     * @param int $last Last administrator id
     * @param int $first First id to seed before any users/identities read
     */
    private function seedUsers(int $last, int $first = 1): void
    {
        for ($id = $first; $id <= $last; $id++) {
            Database::sqlRun('INSERT INTO hilos_user (id, name, admin, time_zone) VALUES (?, ?, 1, ?)',
                [$id, 'Admin ' . $id, $id === 1 ? 'America/New_York' : 'Asia/Tokyo']);
            Database::sqlRun("INSERT INTO hilos_identity (user_id, type, identifier, verified) VALUES (?, 'password', ?, 1)",
                [$id, 'admin' . $id . '@example.test']);
        }
    }

    /**
     * @param string $key Existing catalog setting
     * @param bool|int $value Fixture override
     */
    private function setting(string $key, bool|int $value): void
    {
        $this->asAgent($this->settingsOwner, static function () use ($key, $value): void {
            if (isset(Hilos::$db->settings[$key])) {
                Hilos::$db->settings[$key]->actions->updateValue($value);
            } else {
                Hilos::$db->settings->actions->add($key, $value, StatusNoticeSettingsCatalog::getCatalog());
            }
        });
    }

    /** @param bool $down Drop fixtures rather than creating them */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            $name = 'create_' . $table;
            if ($down) {
                $name .= '_down';
            }
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name . '.sql'));
        }
    }
}

abstract class StatusNoticeHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_STATUS => [AgentRegistryKey::WORKER => StatusAgent::class],
        HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY => [AgentRegistryKey::WORKER => StatusNoticeLibrary::class],
        'probe' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const string NOTIFICATION_CHANNEL_REGISTRY = StatusNoticeChannels::class;
    protected const array STATUS_COMPONENTS = [
        'service' => ['name' => 'Service', 'description' => null, 'agentType' => 'probe'],
    ];
}

final class StatusNoticeDbContext extends HilosDbContext
{
}

final class StatusNoticeLibrary extends AbstractNotificationsLibraryAgent
{
}

final class StatusNoticeSettingsOwner extends AbstractAgent
{
    public const string AGENT_TYPE = 'fixture_status_notice_settings';
    public const array OWNS_DB = [HilosDbContext::settings => TruthSourceOperation::ALL];

    /** The fixture owns no background work. */
    public function onStop(): void
    {
    }
}

final class StatusNoticeSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Existing Status and delivery settings */
    public static function getCatalog(): array
    {
        return array_replace(StatusSettingsCatalog::getCatalog(), ChannelSettingsCatalog::getCatalog());
    }
}

final class StatusNoticeChannels extends DeliveryChannelRegistry
{
    /** @return array<string, MailDeliveryChannel> Email and another enabled channel to prove narrowing */
    protected static function channels(): array
    {
        return ['email' => new MailDeliveryChannel(), 'other' => new class extends MailDeliveryChannel {
            public function name(): string
            {
                return 'other';
            }
        }];
    }
}

final class StatusNoticeRuntime extends RtContext
{
    /** Framework feature mounts supply all runtime rows used here. */
    public function configure(): void
    {
    }

    /** @param string $phase Isolated freeze projection without running an operation */
    public function phase(string $phase): void
    {
        $row = ProtectedModeRuntime::create();
        $row->phase = $phase;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $row);
    }
}

final class StatusNoticeRouter extends SignalRouter
{
    /** @return class-string<Hilos> Production agent declarations in the local topology */
    protected function hilosClass(): string
    {
        return StatusNoticeHilos::class;
    }
}

final class StatusNoticeMailAgent extends MailDeliveryChannelAgent
{
    /** @var list<EmailMessage> Messages rendered by the real generic notification template */
    public array $messages = [];

    /** @return MailTransportInterface Test transport at the existing pipeline seam */
    protected function createTransport(): MailTransportInterface
    {
        return new StatusNoticeTransport(function (EmailMessage $message): void {
            $this->messages[] = $message;
        });
    }
}

final class StatusNoticeTransport implements MailTransportInterface
{
    private bool $busy = false;
    private bool $ready = false;

    /** @param Closure(EmailMessage): void $capture Records messages at the existing transport boundary */
    public function __construct(private readonly Closure $capture)
    {
    }

    /**
     * @param EmailMessage $message Rendered recipient email
     * @param float $nowMs Transport clock, unused
     */
    public function start(EmailMessage $message, float $nowMs): void
    {
        ($this->capture)($message);
        $this->busy = true;
    }

    /** @param float $nowMs Transport clock, unused */
    public function tick(float $nowMs): void
    {
        $this->busy = false;
        $this->ready = true;
    }

    /** @return bool Whether the one scripted send is pending */
    public function isBusy(): bool
    {
        return $this->busy;
    }

    /** @return bool Whether the scripted send has settled */
    public function hasResult(): bool
    {
        return $this->ready;
    }

    /** @return MailSendOutcome Accepted test send, without a file transport */
    public function consumeResult(): MailSendOutcome
    {
        $this->ready = false;
        return MailSendOutcome::delivered();
    }

    /** No external resource is held. */
    public function close(): void
    {
    }
}

final class StatusNoticeCommitProbe implements SourceMirrorSubscriberInterface
{
    public bool $failTimeline = false;
    public bool $failAnnouncement = false;
    /** @var list<array{open: bool, tasks: int, frame: ?SignalDTO}> Observations inside the candidate transaction */
    public array $candidateWrites = [];

    /** @param StatusAgent $owner Real owner whose private queue is inspected at the write boundary */
    public function __construct(private readonly StatusAgent $owner)
    {
    }

    /**
     * @param SourceChange $change Local ORM write
     * @param SourceChangeProvenance $provenance Source provenance, unused
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->sourceKey === HilosDbContext::statusIncidents && $change->mutationType === TableMutationType::Create) {
            $this->candidateWrites[] = ['open' => Database::hasOpenTransactions(),
                'tasks' => new ReflectionProperty(StatusAgent::class, 'candidateNotices')->getValue($this->owner)->count(),
                'frame' => Hilos::$sr->getNextQueuedSignal()];
            if ($this->failAnnouncement) {
                $this->failAnnouncement = false;
                Database::afterCommit(static function (): void {
                    throw new DatabaseException('Injected announcement failure after durable commit');
                });
            }
        }
        if ($this->failTimeline && $change->sourceKey === HilosDbContext::statusIncidentUpdates
            && $change->mutationType === TableMutationType::Create) {
            $this->failTimeline = false;
            throw new DatabaseException('Injected timeline failure before commit');
        }
    }
}
