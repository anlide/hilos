<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
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
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\Template\AbstractStatusOutageMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusSeverity;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Tests\Fixtures\StatusPublicVolume;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/** Real commits and lifecycle: rollback-root fixtures cannot prove afterCommit mail work. */
final class StatusSubscriberMailIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = ['hilos_setting', 'hilos_status_incident', 'hilos_status_incident_update', 'hilos_status_subscriber'];

    private StatusAgent $owner;
    private StatusSubscriberMailRuntime $runtime;
    private StatusPublicVolume $volume;
    private ?int $ownerFrame = null;
    private string $previousApp;
    /** @var array Facades restored after the real-commit case */
    private array $previousGlobals;
    /** @var array Source bus registries restored after probes */
    private array $previousBus;
    /** @var array<string, string|false> Environment entries restored after each case */
    private array $previousEnvironment = [];

    /** Mounts production storage, claims, raw mail and public-file lifecycle on isolated tables. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousGlobals = [Hilos::$db, Hilos::$rt, Hilos::$sr, Hilos::$mail, Hilos::$env,
            Hilos::$setting, Hilos::$notify, Hilos::$browser];
        foreach (['mirrors', 'reactions', 'provenance'] as $property) {
            $this->previousBus[$property] = new ReflectionProperty(SourceChangeBus::class, $property)->getValue();
        }
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusSubscriberMailHilos::class);
        foreach ([
            EnvConstants::HILOS_STATUS_URL->name => 'https://service.example.test/status',
            EnvConstants::MAIL_FROM_NAME->name => 'Example Service',
            EnvConstants::MAIL_WORKER_COUNT->name => '4',
            EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name => 'recipient',
            EnvConstants::HILOS_PROJECT_TIME_ZONE->name => 'UTC',
        ] as $key => $value) {
            $this->previousEnvironment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$notify = null;
        Hilos::$browser = null;
        foreach (array_reverse(self::TABLES) as $table) {
            $this->stub($table, true);
        }
        foreach (self::TABLES as $table) {
            $this->stub($table, false);
        }
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new StatusSubscriberMailDbContext();
        Hilos::$db->configure();
        Hilos::$setting = new SettingsAccessor(StatusSettingsCatalog::class);
        Hilos::$sr = new StatusSubscriberMailSignalRouter();
        Hilos::$mail = new HilosMailer();
        $this->volume = new StatusPublicVolume();
        $this->runtime = new StatusSubscriberMailRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        Hilos::$rt = $this->runtime;
        $this->startOwner();
    }

    /** Drops only fixture tables and restores claims, sources and process globals. */
    protected function tearDown(): void
    {
        try {
            SourceInterestRegistry::readsWhatItMounts();
            $this->releaseOwner();
            foreach (array_reverse(self::TABLES) as $table) {
                $this->stub($table, true);
            }
            Schema::reset();
            $this->volume->close();
            foreach ($this->previousBus as $property => $value) {
                new ReflectionProperty(SourceChangeBus::class, $property)->setValue(null, $value);
            }
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
            [Hilos::$db, Hilos::$rt, Hilos::$sr, Hilos::$mail, Hilos::$env,
                Hilos::$setting, Hilos::$notify, Hilos::$browser] = $this->previousGlobals;
            foreach ($this->previousEnvironment as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testNewCommittedEventsSendOnceAndKeepTheirOriginalSnapshot(): void
    {
        $this->recipient('reader@example.test', zone: 'Europe/Warsaw');
        $pending = $this->recipient('pending@example.test', confirmed: false);
        $id = $this->candidate();
        self::assertSame([], $this->mails());
        $this->owner->tickAt(301000);
        self::assertSame([], $this->mails(), 'Commit enqueues work; it does not send the audience in the handler');
        $this->publish($id);
        $this->publish($id);
        Hilos::$db->statusIncidents[$id]->actions->changeSeverity(StatusSeverity::PARTIAL_OUTAGE);
        $this->team($id, 'Investigating the cause.', 'one');
        $this->team($id, 'Investigating the cause.', 'one');
        $this->team($id, 'A later public update.', 'two');
        $this->report(false, 302000, [true, false]);
        $this->owner->tickAt(302000);
        $published = $this->mails();
        self::assertCount(1, $published);
        self::assertSame(MailTemplateCatalogConstants::STATUS_OUTAGE_PUBLISHED, $published[0]->templateKey);
        self::assertSame('major_outage', $published[0]->params[AbstractStatusOutageMailTemplate::PARAM_SEVERITY]);
        self::assertSame('Major outage.', $published[0]->params[AbstractStatusOutageMailTemplate::PARAM_TEXT]);
        self::assertSame('1970-01-01 01:05:01 +01:00 (Europe/Warsaw)', $published[0]->params[AbstractStatusOutageMailTemplate::PARAM_EVENT_AT_LABEL]);
        self::assertSame('1970-01-01 01:00:01 +01:00 (Europe/Warsaw)',
            $published[0]->params[AbstractStatusOutageMailTemplate::PARAM_DETECTED_AT_LABEL]);
        self::assertNull($published[0]->params[AbstractStatusOutageMailTemplate::PARAM_RESOLVED_AT_LABEL]);
        self::assertNull($published[0]->locale, 'Explicit null means project default by the MailTemplate contract');
        self::assertSame(HilosMailer::shardKeyForAddress('reader@example.test'), $published[0]->shardKey);
        self::assertSame('Example Service', $published[0]->params[AbstractStatusOutageMailTemplate::PARAM_PROJECT_NAME]);
        self::assertSame('https://service.example.test/status', $published[0]->params[AbstractStatusOutageMailTemplate::PARAM_STATUS_URL]);
        $token = Database::sql('SELECT unsubscribe_token FROM hilos_status_subscriber WHERE email = ?', ['reader@example.test'])->rows()[0];
        self::assertSame('https://service.example.test/status#status-unsubscribe=' . $token['unsubscribe_token'],
            $published[0]->params[AbstractStatusOutageMailTemplate::PARAM_UNSUBSCRIBE_URL]);
        self::assertCount(3, Database::sql('SELECT id FROM hilos_status_incident_update')->rows(), 'Timer/button and request replay append once');
        $this->owner->tickAt(302001);
        $team = $this->mails();
        self::assertCount(1, $team);
        self::assertSame(MailTemplateCatalogConstants::STATUS_OUTAGE_UPDATE, $team[0]->templateKey);
        self::assertSame('Investigating the cause.', $team[0]->params[AbstractStatusOutageMailTemplate::PARAM_TEXT]);
        self::assertSame('partial_outage', $team[0]->params[AbstractStatusOutageMailTemplate::PARAM_SEVERITY]);
        $this->owner->tickAt(302002);
        self::assertSame('A later public update.', $this->mails()[0]->params[AbstractStatusOutageMailTemplate::PARAM_TEXT]);
        $this->report(false, 302003, [false, false]);
        $this->owner->tickAt(302003);
        self::assertSame([], $this->mails(), 'An automatic severity change sends no subscriber letter');
        self::assertSame('severity_changed',
            Database::sql('SELECT kind FROM hilos_status_incident_update ORDER BY id DESC LIMIT 1')->rows()[0]['kind']);
        $at = TimeHelper::nowMs() + 1000;
        $this->report(false, $at, [true, true]);
        $this->owner->tickAt($at);
        self::assertSame([], $this->mails());
        $this->owner->tickAt($at + 1);
        $resolved = $this->mails();
        self::assertCount(1, $resolved);
        self::assertSame(MailTemplateCatalogConstants::STATUS_OUTAGE_RESOLVED, $resolved[0]->templateKey);
        self::assertSame('Resolved.', $resolved[0]->params[AbstractStatusOutageMailTemplate::PARAM_TEXT]);
        self::assertSame($resolved[0]->params[AbstractStatusOutageMailTemplate::PARAM_EVENT_AT_LABEL],
            $resolved[0]->params[AbstractStatusOutageMailTemplate::PARAM_RESOLVED_AT_LABEL]);
        self::assertSame('reader@example.test', $resolved[0]->to);
        self::assertFalse(Hilos::$db->statusSubscribers[$pending]->confirmed);
        for ($tick = 1; $tick <= 3; $tick++) {
            $this->owner->tickAt($at + $tick + 1);
            self::assertSame([], $this->mails());
        }
    }

    public function testPublishNowWinsBeforeTheTimerAndTheTimerCannotEnqueueItAgain(): void
    {
        $this->recipient('reader@example.test');
        $id = $this->candidate();
        $this->publish($id);
        self::assertNotNull(Database::sql('SELECT published_at FROM hilos_status_incident WHERE id = ?', [$id])->rows()[0]['published_at']);
        self::assertSame([], $this->mails(), 'The button commits only the event and its queued work');
        $this->owner->tickAt(400000);
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(MailTemplateCatalogConstants::STATUS_OUTAGE_PUBLISHED, $mails[0]->templateKey);
        $this->publish($id);
        $this->owner->tickAt(400001);
        self::assertSame([], $this->mails());
        self::assertCount(1, Database::sql('SELECT id FROM hilos_status_incident_update')->rows());
    }

    public function testKeysetBoundCutoffAndDeletionAcrossSeveralNotices(): void
    {
        $ids = [];
        for ($index = 0; $index < 70; $index++) {
            $ids[] = $this->recipient('reader' . $index . '@example.test');
        }
        $late = Database::transaction('primary', static fn () => Hilos::$db->statusSubscribers->actions->create('late@example.test', 10));
        $id = $this->candidate();
        $this->owner->tickAt(301000);
        Database::transaction('primary', static fn () => Hilos::$db->statusSubscribers[$late->subscriberId]->actions->confirm($late->token, 301001));
        $this->recipient('new@example.test', at: 10);
        $this->team($id, 'Update for its own audience.', 'audience');
        $seen = [];
        $publishedDone = false;
        for ($tick = 0; $tick < 20; $tick++) {
            $this->owner->tickAt(301001 + $tick);
            $mails = $this->mails();
            self::assertLessThanOrEqual(32, count($mails), 'The entire tick shares one attempt ceiling');
            foreach ($mails as $mail) {
                if ($mail->templateKey === MailTemplateCatalogConstants::STATUS_OUTAGE_PUBLISHED) {
                    self::assertFalse($publishedDone);
                    $seen[] = $mail->to;
                } else {
                    $publishedDone = true;
                }
            }
            if ($tick === 0) {
                self::assertNotEmpty($seen);
                Hilos::$db->statusSubscribers[$ids[69]]->actions->delete();
                $this->recipient('reader69@example.test', at: 10);
            }
        }
        self::assertTrue($publishedDone, 'Later notices progress when reconciliation has no new picture');
        self::assertSame(array_map(static fn (int $index): string => 'reader' . $index . '@example.test', range(0, 68)), $seen);
        self::assertCount(69, array_unique($seen));
    }

    public function testFailedHandoffConsumesRecipientAndRechecksRemovalInsideAPortion(): void
    {
        $this->recipient('first@example.test');
        $second = $this->recipient('second@example.test');
        $this->recipient('third@example.test');
        $this->candidate();
        $this->owner->tickAt(301000);
        $attempts = [];
        Hilos::$mail = new StatusSubscriberProbeMailer(function (MailSendSignalData $mail) use (&$attempts, $second): void {
            $attempts[] = $mail->to;
            if ($mail->to === 'first@example.test') {
                Hilos::$db->statusSubscribers[$second]->actions->delete();
                throw new RuntimeException('Ambiguous handoff');
            }
        });
        for ($tick = 0; $tick < 5; $tick++) {
            $this->owner->tickAt(301001 + $tick);
        }
        self::assertSame(['first@example.test', 'third@example.test'], $attempts);
        self::assertSame(['third@example.test'], array_map(static fn (MailSendSignalData $mail): string => $mail->to, $this->mails()));
    }

    public function testFailedAttemptsShareTheTickCeilingAndAnEmptyNoticeCannotDrainTheWholeQueue(): void
    {
        $id = $this->candidate();
        $this->owner->tickAt(301000);
        $this->team($id, 'No audience either.', 'empty');
        $queue = new ReflectionProperty(StatusAgent::class, 'subscriberNotices')->getValue($this->owner);
        self::assertCount(2, $queue);
        $this->owner->tickAt(301001);
        self::assertCount(1, $queue, 'Even empty work consumes a whole task slot this tick');
        $this->owner->tickAt(301002);
        self::assertCount(0, $queue);
        for ($index = 0; $index < 40; $index++) {
            $this->recipient('reader' . $index . '@example.test');
        }
        $this->team($id, 'First populated audience.', 'first');
        $this->team($id, 'Second populated audience.', 'second');
        $attempts = [];
        Hilos::$mail = new StatusSubscriberProbeMailer(static function (MailSendSignalData $mail) use (&$attempts): void {
            $attempts[] = $mail->to . ':' . $mail->params[AbstractStatusOutageMailTemplate::PARAM_TEXT];
            if ($mail->to === 'reader0@example.test') {
                throw new RuntimeException('Ambiguous first handoff');
            }
        });
        for ($tick = 0; $tick < 20; $tick++) {
            $before = count($attempts);
            $this->owner->tickAt(301003 + $tick);
            self::assertLessThanOrEqual(32, count($attempts) - $before);
        }
        self::assertCount(80, $attempts);
        self::assertCount(80, array_unique($attempts), 'Failures consume the id just like successful handoffs');
        self::assertCount(78, $this->mails());
    }

    /** @return iterable<string, array{?string}> Every held phase, unavailable runtime, and normal stop */
    public static function discardReasons(): iterable
    {
        foreach (['activating', 'active', 'verifying', 'deactivating', null, 'stop'] as $phase) {
            yield $phase ?? 'lost runtime' => [$phase];
        }
    }

    /** @param ?string $phase Reason the best-effort queue must disappear */
    #[DataProvider('discardReasons')]
    public function testStopFreezeAndRuntimeLossDiscardWorkWithoutHistoryReplay(?string $phase): void
    {
        $this->recipient('reader@example.test');
        $this->candidate();
        $this->owner->tickAt(301000);
        if ($phase === 'stop') {
            $this->owner->onStop();
        } else {
            $this->runtime->phase($phase);
            $this->owner->tickAt(301001);
            $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
            $this->owner->tickAt(301002);
        }
        self::assertSame([], $this->mails());
        $this->releaseOwner();
        $this->startOwner();
        $this->owner->tickAt(400000);
        self::assertSame([], $this->mails());
        self::assertCount(1, Database::sql('SELECT id FROM hilos_status_incident_update')->rows());
    }

    public function testFreezeEnteringBetweenRecipientsStopsTheSamePortion(): void
    {
        $this->recipient('first@example.test');
        $this->recipient('second@example.test');
        $this->candidate();
        $this->owner->tickAt(301000);
        $database = Hilos::$db;
        Hilos::$mail = new StatusSubscriberProbeMailer(function (): void {
            $this->runtime->phase(ProtectedModeRuntime::PHASE_VERIFYING);
            Hilos::$db = null;
        });
        try {
            $this->owner->tickAt(301001);
        } finally {
            Hilos::$db = $database;
        }
        self::assertSame(['first@example.test'], array_map(static fn (MailSendSignalData $mail): string => $mail->to, $this->mails()));
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->owner->tickAt(301002);
        self::assertSame([], $this->mails());
    }

    public function testCallerRollbackDropsAnAlreadyRegisteredPublicationCallback(): void
    {
        $this->recipient('reader@example.test');
        $id = $this->candidate();
        Database::transactionStart();
        try {
            // The same writer joins timer/button transactions; stop after its callback registration.
            new ReflectionMethod(StatusAgent::class, 'publishIncident')->invoke($this->owner, Hilos::$db->statusIncidents[$id], 2000);
            self::assertCount(1, Database::sql('SELECT id FROM hilos_status_incident_update')->rows());
            self::assertSame([], $this->mails());
        } finally {
            Database::transactionRollback();
        }
        self::assertNull(Hilos::$db->statusIncidents[$id]->publishedAt);
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_incident_update')->rows());
        Hilos::$db->statusIncidents[$id]->actions->suppress(2001);
        $this->owner->tickAt(400000);
        self::assertSame([], $this->mails(), 'A registered but rolled-back event cannot reach the audience');
    }

    public function testRollbackEmptyTransactionShortAndSuppressedCandidatesNeverEnqueue(): void
    {
        $this->recipient('reader@example.test');
        $id = $this->candidate();
        $failure = new StatusSubscriberTimelineFailure();
        SourceChangeBus::subscribe($failure);
        try {
            $this->owner->tickAt(301000);
            self::fail('A failed timeline insertion must roll back');
        } catch (SourceChangeSubscriberException) {
            self::assertSame([], Database::sql('SELECT id FROM hilos_status_incident_update')->rows());
            self::assertNull(Hilos::$db->statusIncidents[$id]->publishedAt);
            self::assertSame([], $this->mails());
        }
        Hilos::$db->statusIncidents[$id]->actions->suppress(301001);
        Database::transaction('primary', static function (): void {});
        $this->owner->tickAt(301002);
        $this->report(false, 301003, [true, true]);
        $this->owner->tickAt(301003);
        $this->report(false, 301004, [false, false]);
        $this->owner->tickAt(301004);
        $this->report(false, 301005, [true, true]);
        $this->owner->tickAt(301005);
        $this->owner->tickAt(700000);
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_incident_update')->rows());
        self::assertSame([], $this->mails());
    }

    /** @return iterable<string, array{string, ?string, string, string}> Shared server-text policy, never a Status-specific fallback */
    public static function zones(): iterable
    {
        yield 'recipient' => ['recipient', 'Europe/Warsaw', 'UTC', '+01:00 (Europe/Warsaw)'];
        yield 'missing recipient' => ['recipient', null, 'Asia/Tokyo', '+09:00 (Asia/Tokyo)'];
        yield 'project' => ['project', 'Europe/Warsaw', 'Asia/Tokyo', '+09:00 (Asia/Tokyo)'];
        yield 'utc' => ['utc', 'Europe/Warsaw', 'Asia/Tokyo', '+00:00 (UTC)'];
    }

    /**
     * @param string $mode Existing server-text policy
     * @param ?string $zone Saved subscription zone
     * @param string $project Existing project zone
     * @param string $suffix Offset and zone expected in every event label
     */
    #[DataProvider('zones')]
    public function testRecipientZonePolicyAndCapturedProjectMetadata(string $mode, ?string $zone, string $project, string $suffix): void
    {
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=' . $mode);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . '=' . $project);
        putenv(EnvConstants::MAIL_FROM_NAME->name . '= ');
        $this->recipient('reader@example.test', zone: $zone);
        $this->candidate();
        $this->owner->tickAt(301000);
        putenv(EnvConstants::HILOS_STATUS_URL->name . '=');
        putenv(EnvConstants::MAIL_FROM_NAME->name . '=Later name');
        $this->owner->tickAt(301001);
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('Service', $mails[0]->params[AbstractStatusOutageMailTemplate::PARAM_PROJECT_NAME]);
        self::assertSame('https://service.example.test/status', $mails[0]->params[AbstractStatusOutageMailTemplate::PARAM_STATUS_URL]);
        self::assertStringEndsWith($suffix, $mails[0]->params[AbstractStatusOutageMailTemplate::PARAM_DETECTED_AT_LABEL]);
        self::assertStringEndsWith($suffix, $mails[0]->params[AbstractStatusOutageMailTemplate::PARAM_EVENT_AT_LABEL]);
        self::assertSame(1, substr_count($mails[0]->params[AbstractStatusOutageMailTemplate::PARAM_EVENT_AT_LABEL], '('));
    }

    public function testUnavailableSubscriberSourceDropsWorkWithoutAnOpenSend(): void
    {
        $this->recipient('reader@example.test');
        $this->candidate();
        $this->owner->tickAt(301000);
        SourceInterestRegistry::readsWhatIsDelivered();
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
        foreach ([HilosDbContext::statusIncidents, HilosDbContext::statusIncidentUpdates, HilosDbContext::settings] as $key) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $key, 'subscriber-mail-other-sources');
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
        }
        foreach ([HilosClusterNode::RT_COLLECTION, HilosStatusComponent::RT_COLLECTION] as $key) {
            SourceInterestRegistry::register(SourceChange::KIND_RT, $key, 'subscriber-mail-other-sources');
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, $key);
        }
        $this->owner->tickAt(301001);
        self::assertSame([], $this->mails());
        SourceInterestRegistry::readsWhatItMounts();
        OwnershipDeclaration::claimAll($this->owner);
        $this->owner->tickAt(301002);
        self::assertSame([], $this->mails());
        SourceInterestRegistry::releaseConsumer('subscriber-mail-other-sources');
    }

    /** Starts a new production owner and leaves durable facts untouched. */
    private function startOwner(): void
    {
        $this->owner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->owner);
        $this->ownerFrame = ExecutionContext::push(new ExecutionFrame(agentId: $this->owner->getId()));
        $this->owner->onStart();
    }

    /** Releases only this instance's execution and source claims. */
    private function releaseOwner(): void
    {
        if ($this->ownerFrame !== null) {
            ExecutionContext::pop($this->ownerFrame);
            $this->ownerFrame = null;
        }
        TruthSourceRegistry::unregisterAgent($this->owner->getId());
        RtTruthSourceRegistry::unregisterAgent($this->owner->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
    }

    /**
     * @param string $email New subscription address
     * @param bool $confirmed Whether it may receive outage mail
     * @param ?string $zone Saved browser report
     * @param int $at Creation and confirmation time
     * @return int Persisted subscription primary id
     */
    private function recipient(string $email, bool $confirmed = true, ?string $zone = null, int $at = 10): int
    {
        return Database::transaction('primary', static function () use ($email, $confirmed, $zone, $at): int {
            $issued = Hilos::$db->statusSubscribers->actions->create($email, $at, $zone);
            if ($confirmed) {
                self::assertSame($issued->subscriberId, Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, $at));
            }
            return $issued->subscriberId;
        });
    }

    /** @return int Candidate made by the real collector-driven machine, still before publication */
    private function candidate(): int
    {
        $this->report(true, 999, [false, false]);
        $this->report(false, 1000, [false, false]);
        $this->owner->tickAt(1000);
        $row = Database::sql('SELECT id, published_at FROM hilos_status_incident')->rows()[0];
        self::assertNull($row['published_at']);
        return (int)$row['id'];
    }

    /** @param int $id Candidate primary id */
    private function publish(int $id): void
    {
        $this->owner->onSignalAgent(new AgentSignalData(new StatusPublishNowSignalData(
            $id, 'status_test_answer', 'test', 'publish', 'publish', null,
        )), SignalSource::AGENT, HilosSignalConstants::STATUS_PUBLISH_NOW);
    }

    /**
     * @param int $id Published incident primary id
     * @param string $text Public team words
     * @param string $requestId Replay identity
     */
    private function team(int $id, string $text, string $requestId): void
    {
        $this->owner->onSignalAgent(new AgentSignalData(new StatusAddUpdateSignalData(
            $id, $text, 'status_test_answer', 'test', $requestId, 'update', null,
        )), SignalSource::AGENT, HilosSignalConstants::STATUS_ADD_UPDATE);
    }

    /**
     * @param bool $snapshot Initial baseline or later fresh slots
     * @param int $at Observation clock and report identity
     * @param list<bool> $healthy Process presence per online node
     */
    private function report(bool $snapshot, int $at, array $healthy): void
    {
        $nodes = [];
        foreach ($healthy as $index => $present) {
            $node = 'n' . $index;
            $roster = new DaemonProcessRoster([new DaemonWorkerPicture(1, 'regular', null, 4096,
                $present ? [new DaemonAgentPicture('probe', 'node', 'node')] : [])], null, 0);
            $nodes[] = new ClusterDaemonNodeView($node, true, new ClusterDaemonNodeSlot(
                $node, new NodeDaemonPicture($node, NodeRole::Master, 9999999999, $roster), $at,
            ));
        }
        $this->owner->applyPicturePortion(new DaemonClusterPicturePortionSignalData($snapshot, $nodes), $at);
    }

    /** @return list<MailSendSignalData> Actual raw-mail orders, never mock SMTP or notification rows */
    private function mails(): array
    {
        $mails = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === HilosSignalConstants::HILOS_MAIL_SEND) {
                self::assertInstanceOf(AgentSignalData::class, $frame->data);
                self::assertInstanceOf(MailSendSignalData::class, $frame->data->data);
                $mails[] = $frame->data->data;
            } elseif ($frame->signalName->getName() === 'status_test_answer') {
                self::assertNull($frame->data->data->error);
            }
        }
        return $mails;
    }

    /**
     * @param string $table Framework stub table
     * @param bool $down Drop rather than create
     */
    private function stub(string $table, bool $down): void
    {
        // external-boundary: create stubs have no suffix
        $suffix = $down ? '_down' : '';
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
    }
}

/** Production feature mount and the node-scoped component used for Partial/Major observations. */
abstract class StatusSubscriberMailHilos extends Hilos
{
    public const array AGENTS = [
        'probe' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        HilosAgentType::HILOS_STATUS => [AgentRegistryKey::WORKER => StatusAgent::class],
    ];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'service' => ['name' => 'Service', 'description' => null, 'agentType' => 'probe'],
    ];
}

/** Uses the feature's real conditional collection mounts. */
final class StatusSubscriberMailDbContext extends HilosDbContext
{
}

final class StatusSubscriberMailSignalRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture topology for actual Status lifecycle signals */
    protected function hilosClass(): string
    {
        return StatusSubscriberMailHilos::class;
    }
}

final class StatusSubscriberMailRuntime extends RtContext
{
    /** The real unconditional row starts inactive. */
    public function configure(): void
    {
        $this->phase(ProtectedModeRuntime::PHASE_INACTIVE);
    }

    /** @param ?string $phase Held state or missing runtime */
    public function phase(?string $phase): void
    {
        if ($phase === null) {
            unset($this->_stateItems[ProtectedModeRuntime::RT_ITEM]);
            return;
        }
        $freeze = ProtectedModeRuntime::create();
        $freeze->phase = $phase;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $freeze);
    }
}

/** Injects failure or a source change at the real raw-send boundary. */
final class StatusSubscriberProbeMailer extends HilosMailer
{
    /** @param Closure(MailSendSignalData): void $probe One handoff observation or failure */
    public function __construct(private readonly Closure $probe)
    {
    }

    /** @param EmailMessage|MailSendSignalData $message Actual templated raw-send order */
    public function send(EmailMessage|MailSendSignalData $message): void
    {
        ($this->probe)($message);
        parent::send($message);
    }
}

/** Fails after a real insert, before the enclosing transaction commits. */
final class StatusSubscriberTimelineFailure implements SourceMirrorSubscriberInterface
{
    private bool $armed = true;

    /**
     * @param SourceChange $change New immutable timeline row
     * @param SourceChangeProvenance $provenance Unused write origin
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->armed && $change->sourceKey === HilosDbContext::statusIncidentUpdates) {
            $this->armed = false;
            throw new RuntimeException('Injected timeline failure');
        }
    }
}
