<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidActionPayloadException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Cluster\NodeRole;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Hilos;
use Hilos\Notification\HilosNotifier;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusPublicFiles;
use Hilos\Status\StatusPublicSnapshot;
use Hilos\Status\StatusPublicHeartbeat;
use Hilos\Tests\Fixtures\StatusPublicVolume;
use Hilos\Status\StatusComponentState;
use Hilos\Status\StatusSeverity;
use Hilos\Status\StatusSnapshot;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real commit, owner dispatch and ADMIN gatekeeper seam; no production browser entry is declared here. */
final class StatusHandoverIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user', 'hilos_user_merge', 'hilos_account_deletion', 'hilos_status_incident', 'hilos_status_incident_update',
        'hilos_status_work', 'hilos_status_work_component', 'hilos_status_maintenance_period',
    ];

    private ?HilosDbContext $previousDb = null;
    private ?RtContext $previousRt = null;
    private ?SignalRouter $previousRouter = null;
    private ?HilosNotifier $previousNotify = null;
    private ?SettingsAccessor $previousSetting = null;
    private ?BrowserContext $previousBrowser = null;
    private string $previousApp;
    /** @var list<SourceMirrorSubscriberInterface> Original source mirrors */
    private array $previousMirrors = [];
    /** @var array Original reactions restored without disturbing their process-wide interests */
    private array $previousReactions = [];
    private SourceChangeProvenance $previousProvenance;
    /** @var array<string, mixed> Account-standing memory restored with the original context */
    private array $previousStanding = [];
    private StatusAgent $owner;
    private StatusPublicVolume $publicVolume;
    private StatusHandoverWorker $worker;
    private StatusHandoverRuntime $runtime;
    private PageSignalRouter $gatekeeper;
    private StatusHandoverCommitProbe $probe;

    /** Creates only Status storage and mounts its real owner with local claims. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousNotify = Hilos::$notify;
        $this->previousSetting = Hilos::$setting;
        $this->previousBrowser = Hilos::$browser;
        $this->previousMirrors = new ReflectionProperty(SourceChangeBus::class, 'mirrors')->getValue();
        $this->previousReactions = new ReflectionProperty(SourceChangeBus::class, 'reactions')->getValue();
        $this->previousProvenance = new ReflectionProperty(SourceChangeBus::class, 'provenance')->getValue();
        foreach (['standings', 'lapsedUserIds', 'epoch'] as $property) {
            $this->previousStanding[$property] = new ReflectionProperty(AccountStandingResolver::class, $property)->getValue();
        }
        new ReflectionProperty(AccountStandingResolver::class, 'standings')->setValue(null, []);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        Hilos::$notify = null;
        Hilos::$setting = null;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusHandoverHilos::class);
        foreach (array_reverse(self::TABLES) as $table) {
            $this->stub($table, true);
        }
        foreach (self::TABLES as $table) {
            $this->stub($table, false);
        }
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin) VALUES (7, 'Admin', 1), (5, 'Viewer', 0)");
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new StatusHandoverDbContext();
        Hilos::$db->configure();
        $this->runtime = new StatusHandoverRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->bindStateCollectionNames();
        Hilos::$rt = $this->runtime;
        Hilos::$browser = new IdentityTestBrowser(7, true);
        $this->publicVolume = new StatusPublicVolume();
        $this->owner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->owner);
        $this->worker = new StatusHandoverWorker($this->owner);
        $this->gatekeeper = StatusHandoverWorker::pageRouter(new StatusHandoverGatekeeperAgent());
        $this->asOwner(fn () => $this->owner->onStart());
        $this->drain();
        $this->probe = new StatusHandoverCommitProbe();
        SourceChangeBus::subscribe($this->probe);
    }

    /** Releases every local claim, listener and facade even after a failed case. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            foreach (array_reverse(self::TABLES) as $table) {
                $this->stub($table, true);
            }
        } finally {
            TruthSourceRegistry::unregisterAgent($this->owner->getId());
            RtTruthSourceRegistry::unregisterAgent($this->owner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
            new ReflectionProperty(SourceChangeBus::class, 'mirrors')->setValue(null, $this->previousMirrors);
            new ReflectionProperty(SourceChangeBus::class, 'reactions')->setValue(null, $this->previousReactions);
            new ReflectionProperty(SourceChangeBus::class, 'provenance')->setValue(null, $this->previousProvenance);
            foreach ($this->previousStanding as $property => $value) {
                new ReflectionProperty(AccountStandingResolver::class, $property)->setValue(null, $value);
            }
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
            Hilos::$db = $this->previousDb;
            Hilos::$rt = $this->previousRt;
            Hilos::$sr = $this->previousRouter;
            Hilos::$notify = $this->previousNotify;
            Hilos::$setting = $this->previousSetting;
            Hilos::$browser = $this->previousBrowser;
            $this->publicVolume->close();
            parent::tearDown();
        }
    }

    public function testStrictDtoRoundtripsAndRequiredNullableFields(): void
    {
        foreach ([StatusCancelPublicationSignalData::class, StatusPublishNowSignalData::class, StatusAddUpdateSignalData::class] as $dto) {
            $payload = [
                'incidentId' => 37, 'replySignal' => 'fixture_done', 'acceptKey' => 'ak-status',
                'requestId' => null, 'action' => 'fixture_write', 'successMessage' => null,
            ];
            if ($dto === StatusAddUpdateSignalData::class) {
                $payload = ['incidentId' => 37, 'text' => '  Team words  ', ...array_slice($payload, 1)];
            }
            self::assertSame($payload, $dto::fromArray($payload)->toArray());
            self::assertSame($payload, $dto::fromJson($dto::fromArray($payload)->toJson())->toArray());
            foreach (array_keys($payload) as $key) {
                $missing = $payload;
                unset($missing[$key]);
                $this->assertMalformed($dto, $missing);
            }
            foreach (['37', 0, -1, true, 1.5, null] as $badId) {
                $this->assertMalformed($dto, [...$payload, 'incidentId' => $badId]);
            }
            foreach (['replySignal', 'acceptKey', 'action'] as $key) {
                foreach (['', '  ', false, null] as $invalid) {
                    $this->assertMalformed($dto, [...$payload, $key => $invalid]);
                }
            }
            $this->assertMalformed($dto, [...$payload, 'requestId' => str_repeat('я', 129)]);
            $this->assertMalformed($dto, [...$payload, 'requestId' => 7]);
            $this->assertMalformed($dto, [...$payload, 'successMessage' => false]);
            self::assertSame(str_repeat('я', 128), $dto::fromArray([...$payload, 'requestId' => str_repeat('я', 128)])->requestId);
            self::assertSame('', $dto::fromArray([...$payload, 'requestId' => ''])->requestId);
            $ask = $dto::fromArray($payload);
            $arguments = array_values($ask->toArray());
            $arguments[0] = 0;
            try {
                new $dto(...$arguments);
                self::fail('Direct construction must validate the same fields');
            } catch (InvalidFormatException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCancelIsIdempotentAndSurvivesOwnerRestartAndDeadline(): void
    {
        $incident = $this->candidate();
        $deadline = $incident->publishAt;
        $this->success($this->ask(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, $incident->id));
        $suppressedAt = $this->row($incident->id)['suppressed_at'];
        self::assertNotNull($suppressedAt);
        $this->success($this->ask(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, $incident->id));
        self::assertSame($suppressedAt, $this->row($incident->id)['suppressed_at']);
        $this->owner = new StatusAgent();
        $this->worker->replaceOwner($this->owner);
        $this->asOwner(fn () => $this->owner->onStart());
        $this->freshAbsence();
        $this->asOwner(fn () => $this->owner->tickAt($deadline + 1));
        self::assertSame((string)$deadline, $this->row($incident->id)['publish_at']);
        self::assertSame($suppressedAt, $this->row($incident->id)['suppressed_at']);
        self::assertNull($this->row($incident->id)['published_at']);
        self::assertSame([], $this->updates($incident->id));
        $this->refusal($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id), 'canceled');
    }

    /** @return iterable<string, array{bool}> Timer and handover serialization orders */
    public static function publicationOrders(): iterable
    {
        yield 'timer then manual' => [true];
        yield 'manual then timer' => [false];
    }

    /** @param bool $timerFirst Which serialized writer runs first */
    #[DataProvider('publicationOrders')]
    public function testTimerAndManualPublicationShareOneTransition(bool $timerFirst): void
    {
        $incident = $this->candidate();
        $before = $this->row($incident->id);
        $this->freshAbsence();
        if ($timerFirst) {
            $this->asOwner(fn () => $this->owner->tickAt($incident->publishAt));
        }
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
        $publishedAt = $this->row($incident->id)['published_at'];
        $this->asOwner(fn () => $this->owner->tickAt($incident->publishAt + 1));
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
        self::assertSame($publishedAt, $this->row($incident->id)['published_at']);
        self::assertSame($before['id'], $this->row($incident->id)['id']);
        self::assertSame($before['detected_at'], $this->row($incident->id)['detected_at']);
        self::assertSame($before['publish_at'], $this->row($incident->id)['publish_at']);
        self::assertSame(['published'], array_column($this->updates($incident->id), 'kind'));
        self::assertSame(StatusComponentState::MAJOR_OUTAGE, StatusSnapshot::read()->components['service']->state);
        $this->refusal($this->ask(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, $incident->id), 'already been published');
    }

    public function testStalePrimaryIdNeverTargetsAReplacementAtTheSameComponent(): void
    {
        $old = $this->candidate();
        $this->asOwner(fn () => $old->actions->resolve(TimeHelper::nowMs()));
        $replacement = $this->candidate();
        foreach ([HilosSignalConstants::STATUS_CANCEL_PUBLICATION, HilosSignalConstants::STATUS_PUBLISH_NOW,
            HilosSignalConstants::STATUS_ADD_UPDATE] as $signal) {
            $this->refusal($this->ask($signal, $old->id, 'Text'), 'already ended');
            $this->refusal($this->ask($signal, 999999, 'Text'), 'no longer exists');
        }
        self::assertNull($this->row($replacement->id)['published_at']);
        self::assertNull($this->row($replacement->id)['suppressed_at']);
        self::assertSame([], $this->updates($replacement->id));
    }

    public function testPublicPairFollowsCheckingPublicationCommentRecoveryAndDatabaseAvailability(): void
    {
        $incident = $this->candidate();
        $this->freshAbsence();
        $at = TimeHelper::nowMs();
        $this->publicationTicks($at);
        $checking = $this->publicPair();
        self::assertSame('checking', $checking->components[0]->state->value);
        self::assertCount(1, $checking->candidates);
        self::assertSame([], $checking->outages);
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
        $this->publicationTicks($at + 100);
        $published = $this->publicPair();
        self::assertSame('major_outage', $published->components[0]->state->value);
        self::assertSame([], $published->candidates);
        self::assertNotSame($checking->revision, $published->revision);
        $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, 'Investigating', 'public-comment'));
        $this->publicationTicks($at + 200);
        $commented = $this->publicPair();
        self::assertNotSame($published->revision, $commented->revision);
        self::assertContains('Investigating', array_column($commented->outages[0]->updates, 'text'));
        $this->healthyReport($at + 300);
        $this->publicationTicks($at + 300);
        $resolved = $this->publicPair();
        self::assertSame('operational', $resolved->components[0]->state->value);
        self::assertSame($at + 300, $resolved->outages[0]->resolvedAt);
        self::assertContains('Resolved.', array_column($resolved->outages[0]->updates, 'text'));
        self::assertSame('resolved', array_column($this->updates($incident->id), 'kind')[2]);
        $this->healthyReport($at + 350);
        $this->publicationTicks($at + 350);
        self::assertSame($resolved->toArray(), $this->publicPair()->toArray(), 'Identical counts with a new report time change no public fact');
        $database = Hilos::$db;
        Hilos::$db = null;
        try {
            $this->publicationTicks($at + 400);
            $unknown = $this->publicPair();
            self::assertSame('unknown', $unknown->components[0]->state->value);
            self::assertSame($resolved->toArray()['outages'], $unknown->toArray()['outages']);
            $this->asOwner(fn () => $this->owner->tickAt($at + 31000));
            self::assertSame($unknown->toArray(), $this->publicPair()->toArray());
            self::assertSame($at + 31000, StatusPublicHeartbeat::fromJson(
                Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->read(),
            )->aliveAt);
        } finally {
            Hilos::$db = $database;
        }
        $this->publicationTicks($at + 32000);
        self::assertSame('operational', $this->publicPair()->components[0]->state->value);
        self::assertSame($resolved->toArray()['outages'], $this->publicPair()->toArray()['outages']);
    }

    public function testFileFailureEmitsNoHintAndOnlyARepairedPairMayAdvanceHeartbeat(): void
    {
        $incident = $this->candidate();
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
        $at = TimeHelper::nowMs();
        $this->publicationTicks($at);
        $first = $this->publicPair();
        $heartbeatPath = Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->getPath();
        $oldHeartbeat = file_get_contents($heartbeatPath);
        rename($heartbeatPath, $heartbeatPath . '.saved');
        mkdir($heartbeatPath);
        try {
            $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, 'Committed before failure', 'failed-file'));
            $this->publicationTicks($at + 31000);
            self::assertNotSame($first->revision, StatusPublicSnapshot::fromJson(
                Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read(),
            )->revision, 'Status installation precedes the failed matching heartbeat');
            self::assertSame($oldHeartbeat, file_get_contents($heartbeatPath . '.saved'));
            self::assertSame([], array_values(array_filter($this->drain(),
                static fn (SignalDTO $frame): bool => $frame->signalName->getName() === HilosSignalConstants::STATUS_FILES_CHANGED)));
        } finally {
            rmdir($heartbeatPath);
            rename($heartbeatPath . '.saved', $heartbeatPath);
        }
        $this->publicationTicks($at + 33000);
        $repaired = $this->publicPair();
        self::assertContains('Committed before failure', array_column($repaired->outages[0]->updates, 'text'));
        $hints = array_values(array_filter($this->drain(),
            static fn (SignalDTO $frame): bool => $frame->signalName->getName() === HilosSignalConstants::STATUS_FILES_CHANGED));
        self::assertCount(1, $hints);
    }

    public function testLiftUsesTheFrameworkDatabaseResetAndRequiresANewWholeRoster(): void
    {
        $incident = $this->candidate();
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
        $at = TimeHelper::nowMs();
        $this->publicationTicks($at);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->publicationTicks($at + 100);
        self::assertSame('maintenance', $this->publicPair()->components[0]->state->value);
        // Model the real replaced DB and the worker's normal re-hydrate handler, before lift.
        Database::sqlRun('UPDATE hilos_status_incident SET resolved_at = ?, active_component_key = NULL WHERE id = ?', [$at + 200, $incident->id]);
        DbSyncApplicator::applyReHydrate();
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->publicationTicks($at + 300);
        self::assertSame('unknown', $this->publicPair()->components[0]->state->value);
        self::assertSame($at + 200, $this->publicPair()->outages[0]->resolvedAt);
        self::assertSame(1, count(Database::sql('SELECT id FROM hilos_status_incident')->rows()));
        $this->healthyReport($at + 400);
        $this->publicationTicks($at + 400);
        self::assertSame('unknown', $this->publicPair()->components[0]->state->value, 'A delta is no membership baseline');
        $picture = new NodeDaemonPicture('node', NodeRole::Master, 9999999999,
            new DaemonProcessRoster([new DaemonWorkerPicture(1, 'regular', null, 4096, [])], null, 0));
        $this->owner->applyPicturePortion(new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('node', true, new ClusterDaemonNodeSlot('node', $picture, $at + 500)),
        ]), $at + 500);
        $this->publicationTicks($at + 500);
        self::assertSame('unknown', $this->publicPair()->components[0]->state->value, 'A full stopped roster only establishes the baseline');
        $this->healthyReport($at + 600);
        $this->publicationTicks($at + 600);
        self::assertSame('operational', $this->publicPair()->components[0]->state->value);
        self::assertCount(1, Database::sql('SELECT id FROM hilos_status_incident')->rows(), 'Parked agents never create a new incident');
    }

    public function testPublicationRestartsAMutatedLargeBuildAndReplayKeepsTheContentRevision(): void
    {
        $incident = $this->candidate();
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
        $at = TimeHelper::nowMs();
        for ($step = 0; $step < 8; ++$step) {
            $this->asOwner(fn () => $this->owner->tickAt($at + $step));
        }
        $first = $this->publicPair();
        self::assertCount(1, $first->outages);
        self::assertCount(1, $first->outages[0]->updates);
        for ($index = 0; $index < 130; ++$index) {
            $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, 'Team ' . $index, 'portion-' . $index));
        }
        for ($step = 0; $step < 2; ++$step) {
            $this->asOwner(fn () => $this->owner->tickAt($at + 10 + $step));
            self::assertSame($first->revision, $this->publicPair()->revision, 'No partial timeline can replace the first whole');
        }
        $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, 'Newest committed text', 'newest'));
        for ($step = 0; $step < 30; ++$step) {
            $this->asOwner(fn () => $this->owner->tickAt($at + 20 + $step));
        }
        $published = $this->publicPair();
        self::assertNotSame($first->revision, $published->revision);
        self::assertCount(132, $published->outages[0]->updates);
        self::assertContains('Newest committed text', array_column($published->outages[0]->updates, 'text'));
        self::assertCount(132, $this->updates($incident->id), 'The whole file follows actual committed timeline rows');
        $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, 'Newest committed text', 'newest'));
        for ($step = 0; $step < 10; ++$step) {
            $this->asOwner(fn () => $this->owner->tickAt($at + 100 + $step));
        }
        self::assertSame($published->toArray(), $this->publicPair()->toArray(), 'A replay changes no public content');
        $this->drain();
        $this->asOwner(fn () => $this->owner->tickAt($at + 30100));
        self::assertSame($published->toArray(), $this->publicPair()->toArray(), 'Heartbeat changes neither revision nor generatedAt');
        $hints = array_values(array_filter($this->drain(),
            static fn (SignalDTO $frame): bool => $frame->signalName->getName() === HilosSignalConstants::STATUS_FILES_CHANGED));
        self::assertCount(1, $hints);
        self::assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $hints[0]->signalType->getType());
        self::assertSame([], $hints[0]->data->data->toArray());
    }

    public function testTeamTextIsNormalizedImmutableAndReplayedByTheExistingLookup(): void
    {
        $incident = $this->candidate();
        $this->refusal($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, 'Before'), 'Publish this incident');
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
        $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, '  <b>Team</b>  ', 'team-1'));
        $saved = $this->updates($incident->id);
        $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, '<b>Team</b>', 'team-1'));
        self::assertSame($saved, $this->updates($incident->id));
        $this->refusal($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, 'Changed', 'team-1'), 'different text');
        self::assertSame($saved, $this->updates($incident->id));
        self::assertSame('<b>Team</b>', $saved[1]['text']);
        self::assertSame('team', $saved[1]['kind']);
        foreach (['  ', str_repeat('я', 5001), "\xff"] as $invalid) {
            // Invalid UTF-8 is a domain test through a typed ask; JSON cannot carry it.
            $this->refusal($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, $invalid, 'bad-text'), '1 to 5000');
        }
        $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, str_repeat('я', 5000), 'unicode'));
        $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, 'Untracked', null));
        $this->success($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, 'Untracked', null));
        $updates = $this->updates($incident->id);
        self::assertCount(5, $updates);
        self::assertSame(str_repeat('я', 5000), $updates[2]['text']);
        self::assertNull($updates[3]['request_id']);
        self::assertNull($updates[4]['request_id']);
        self::assertNotSame($updates[3]['id'], $updates[4]['id']);
        $this->asOwner(fn () => $incident->actions->resolve(TimeHelper::nowMs()));
        $this->refusal($this->ask(HilosSignalConstants::STATUS_ADD_UPDATE, $incident->id, '<b>Team</b>', 'team-1'), 'already ended');
        self::assertSame($updates, $this->updates($incident->id));
    }

    public function testAdminGatekeeperDefersTheAckAndTheOwnerCommitsWithTheAskersOrigin(): void
    {
        $incident = $this->candidate();
        $this->drain();
        $this->probe->watch = true;
        $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO(
            'ak-status', StatusHandoverGatekeeperPage::ACTION, ['incidentId' => $incident->id], 'button-1',
        ), 'websocket');
        $forwarded = $this->drain();
        self::assertCount(1, $forwarded, 'Forwarding must not send an early successful ack');
        self::assertSame(HilosSignalConstants::STATUS_PUBLISH_NOW, $forwarded[0]->signalName->getName());
        self::assertNull($this->row($incident->id)['published_at']);
        $this->deliver($forwarded[0]);
        $frames = $this->drain();
        $answer = $this->answer($frames);
        $this->success($answer);
        self::assertNotEmpty($this->probe->writes);
        foreach ($this->probe->writes as $write) {
            self::assertTrue($write['transaction']);
            self::assertNull($write['queued'], 'No handover answer or sync frame before commit');
            self::assertSame('ak-status', $write['origin']);
            self::assertSame('button-1', $write['requestId']);
        }
        self::assertSame([false, false], $this->probe->afterCommitTransactions);
        self::assertNotNull($this->row($incident->id)['published_at']);
        self::assertCount(1, $this->updates($incident->id));
        $this->deliverAnswer($answer);
        $acks = $this->drain();
        self::assertCount(1, $acks);
        self::assertSame(SignalConstants::ACTION_SUCCESS, $acks[0]->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $acks[0]->data);
        self::assertSame('ak-status', $acks[0]->data->targetAcceptKey);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $acks[0]->data->data);
        self::assertSame('button-1', $acks[0]->data->data->requestId);
        self::assertSame('Published.', $acks[0]->data->data->message);
    }

    /** @return iterable<string, array{bool}> Non-admin modes */
    public static function viewers(): iterable
    {
        yield 'ordinary non-admin' => [false];
        yield 'admin view-mode viewer' => [true];
    }

    /** @param bool $viewMode Whether a non-admin may view ADMIN pages */
    #[DataProvider('viewers')]
    public function testNonAdminCannotForwardTheWrite(bool $viewMode): void
    {
        $incident = $this->candidate();
        $this->runtime->viewMode($viewMode);
        Hilos::$browser = new IdentityTestBrowser(5, false);
        $this->drain();
        $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO(
            'ak-status', StatusHandoverGatekeeperPage::ACTION, ['incidentId' => $incident->id], 'denied',
        ), 'websocket');
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertSame(SignalConstants::ACTION_ERROR, $frames[0]->signalName->getName());
        $error = $frames[0]->data->data;
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame($viewMode ? 'view_mode' : 'forbidden', $error->errorCode);
        self::assertNull($error->errorDetail);
        self::assertNull($this->row($incident->id)['published_at']);
        self::assertSame([], $this->updates($incident->id));
    }

    public function testDatabaseFailureRollsBackPublicationAndReturnsDiagnosticsWithoutSuccess(): void
    {
        $incident = $this->candidate();
        $before = $this->row($incident->id);
        $this->stub('hilos_status_incident_update', true);
        $answer = $this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id);
        self::assertSame(SignalConstants::ACTION_FAILED_REASON, $answer->error);
        self::assertSame('TableNotFoundException', $answer->errorType);
        self::assertNotNull($answer->errorDetail);
        self::assertStringContainsString('hilos_status_incident_update', $answer->errorDetail);
        self::assertSame($before, $this->row($incident->id));
        self::assertNull(Hilos::$db->statusIncidents[$incident->id]->publishedAt);
        self::assertFalse(Database::hasOpenTransactions());
        $this->deliverAnswer($answer);
        $acks = $this->drain();
        self::assertSame([SignalConstants::ACTION_ERROR], array_map(static fn (SignalDTO $frame): string => $frame->signalName->getName(), $acks));
        self::assertSame($answer->errorType, $acks[0]->data->data->errorType);
        self::assertSame($answer->errorDetail, $acks[0]->data->data->errorDetail);
        $this->stub('hilos_status_incident_update', false);
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
        self::assertSame(['published'], array_column($this->updates($incident->id), 'kind'));
    }

    public function testFailureAfterTimelineAndRuntimeWritesLeavesNoHalfStateOrSuccess(): void
    {
        $incident = $this->candidate();
        $this->asOwner(function (): void {
            Hilos::$rt->hilosStatusComponents->actions->ensure('service', 1);
            Hilos::$rt->hilosStatusComponents['service']->actions->update(1, 0, 1000);
        });
        $this->probe->watch = true;
        $this->probe->failAfterTimeline = true;
        $answer = $this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id);
        self::assertSame(SignalConstants::ACTION_FAILED_REASON, $answer->error);
        self::assertSame('SourceChangeSubscriberException', $answer->errorType);
        self::assertNotNull($answer->errorDetail);
        self::assertNull($this->row($incident->id)['published_at']);
        self::assertNull(Hilos::$db->statusIncidents[$incident->id]->publishedAt);
        self::assertSame([], $this->updates($incident->id));
        self::assertSame(0, Hilos::$rt->hilosStatusComponents['service']->liveInstances);
        self::assertSame([], $this->probe->afterCommitTransactions);
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
        self::assertCount(1, $this->updates($incident->id));
    }

    public function testFreezeAndUnavailableRuntimeRefuseBeforeAnyDatabaseOrDeliveryWork(): void
    {
        $incident = $this->candidate();
        $before = $this->row($incident->id);
        $db = Hilos::$db;
        foreach ([ProtectedModeRuntime::PHASE_ACTIVATING, ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::PHASE_VERIFYING, ProtectedModeRuntime::PHASE_DEACTIVATING] as $phase) {
            $this->runtime->phase($phase);
            Hilos::$db = null;
            self::assertSame(StatusComponentState::MAINTENANCE, StatusSnapshot::read()->components['service']->state);
            foreach ([HilosSignalConstants::STATUS_CANCEL_PUBLICATION, HilosSignalConstants::STATUS_PUBLISH_NOW,
                HilosSignalConstants::STATUS_ADD_UPDATE] as $signal) {
                $this->refusal($this->ask($signal, $incident->id, 'Frozen'), 'unavailable');
            }
        }
        Hilos::$rt = null;
        $this->refusal($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id), 'unavailable');
        Hilos::$rt = new StatusHandoverRuntime();
        $this->refusal($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id), 'unavailable');
        Hilos::$rt = $this->runtime;
        Hilos::$db = $db;
        self::assertSame($before, $this->row($incident->id));
        self::assertSame([], $this->updates($incident->id));
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->asOwner(fn () => $this->owner->tickAt(TimeHelper::nowMs()));
        self::assertNull($this->row($incident->id)['published_at'], 'Lift waits for fresh collector observation');
        $this->success($this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id));
    }

    public function testUntrackedHandoverEchoesNullAndRefusalKeepsTheReason(): void
    {
        $incident = $this->candidate();
        $this->success($answer = $this->ask(HilosSignalConstants::STATUS_PUBLISH_NOW, $incident->id, requestId: null));
        self::assertNull($answer->requestId);
        self::assertNull($answer->successMessage);
        $this->deliverAnswer($answer);
        self::assertSame([], $this->drain());
        $answer = $this->ask(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, $incident->id, requestId: null);
        $this->refusal($answer, 'published');
        self::assertNull($answer->requestId);
        $this->deliverAnswer($answer);
        $acks = $this->drain();
        self::assertCount(1, $acks);
        self::assertSame(SignalConstants::ACTION_ERROR, $acks[0]->signalName->getName());
        self::assertSame($answer->error, $acks[0]->data->data->reason);
        self::assertNull($acks[0]->data->data->requestId);
    }

    /**
     * @param string $signal Approved internal operation name
     * @param int $id Persisted target primary key
     * @param string $text Team text, unused by the other operations
     * @param ?string $requestId Null remains an untracked submit
     * @return HandoverAnswerSignalData Owner's answer after real dispatch
     */
    private function ask(string $signal, int $id, string $text = 'Team update', ?string $requestId = 'request-1'): HandoverAnswerSignalData
    {
        $fields = [
            'incidentId' => $id, 'replySignal' => StatusHandoverGatekeeperPage::DONE,
            'acceptKey' => 'ak-status', 'requestId' => $requestId, 'action' => StatusHandoverGatekeeperPage::ACTION,
            'successMessage' => null,
        ];
        if ($signal === HilosSignalConstants::STATUS_ADD_UPDATE) {
            $fields['text'] = $text;
        }
        // Generic transport data forces the real topology hydrator to build the declared DTO.
        $this->drain();
        $this->deliver(new SignalDTO(new SignalSource(SignalSource::AGENT, StatusHandoverGatekeeperAgent::AGENT_TYPE),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL), new SignalName($signal), new AgentSignalData(new SignalData($fields))));
        return $this->answer($this->drain());
    }

    /** @param SignalDTO $frame Transport frame to route and deliver to its declared Status owner */
    private function deliver(SignalDTO $frame): void
    {
        $destinations = Hilos::$sr->getDestinations($frame);
        self::assertCount(1, $destinations);
        self::assertInstanceOf(AgentDestination::class, $destinations[0]);
        self::assertSame(HilosAgentType::HILOS_STATUS, $destinations[0]->agentType);
        self::assertNull($destinations[0]->agentIndex);
        $this->worker->deliver($frame);
        self::assertNull(ExecutionContext::currentAcceptKey());
        self::assertNull(ExecutionContext::currentRequestId());
    }

    /** @param HandoverAnswerSignalData $answer Real owner's outcome sent to the page-owned reply name */
    private function deliverAnswer(HandoverAnswerSignalData $answer): void
    {
        $this->gatekeeper->dispatchAgentSignal(AgentSignalData::fromArray(new AgentSignalData($answer)->toArray()),
            HilosAgentType::HILOS_STATUS, StatusHandoverGatekeeperPage::DONE);
    }

    /**
     * @param list<SignalDTO> $frames Committed sync announcements and exactly one handover answer
     * @return HandoverAnswerSignalData Parsed owner outcome
     */
    private function answer(array $frames): HandoverAnswerSignalData
    {
        $answers = array_values(array_filter($frames,
            static fn (SignalDTO $frame): bool => $frame->signalName->getName() === StatusHandoverGatekeeperPage::DONE));
        self::assertCount(1, $answers);
        self::assertSame(StatusHandoverGatekeeperPage::DONE, $frames[array_key_last($frames)]->signalName->getName(),
            'Committed sync announcements must precede the owner answer');
        self::assertInstanceOf(AgentSignalData::class, $answers[0]->data);
        $answer = $answers[0]->data->data;
        self::assertInstanceOf(HandoverAnswerSignalData::class, $answer);
        self::assertSame('ak-status', $answer->acceptKey);
        self::assertSame(StatusHandoverGatekeeperPage::ACTION, $answer->action);
        self::assertFalse(Database::hasOpenTransactions());
        if ($answer->error !== null) {
            self::assertCount(1, $frames, 'A failed transaction must release no DB/RT announcements');
        }
        self::assertNotContains(SignalConstants::ACTION_SUCCESS,
            array_map(static fn (SignalDTO $frame): string => $frame->signalName->getName(), $frames));
        return $answer;
    }

    /** @param HandoverAnswerSignalData $answer Expected success */
    private function success(HandoverAnswerSignalData $answer): void
    {
        self::assertNull($answer->error, $answer->errorDetail ?? $answer->error ?? 'Status write refused');
        self::assertNull($answer->errorType);
        self::assertNull($answer->errorDetail);
    }

    /**
     * @param HandoverAnswerSignalData $answer Expected domain refusal
     * @param string $reason Readable reason fragment
     */
    private function refusal(HandoverAnswerSignalData $answer, string $reason): void
    {
        self::assertNotNull($answer->error);
        self::assertStringContainsString($reason, $answer->error);
        self::assertNull($answer->errorType);
        self::assertNull($answer->errorDetail);
    }

    /** @param int $at Clock used for a finite publication budget */
    private function publicationTicks(int $at): void
    {
        for ($step = 0; $step < 12; ++$step) {
            $this->asOwner(fn () => $this->owner->tickAt($at + $step));
        }
    }

    /** @param int $at Distinct process report identity and local acceptance clock */
    private function healthyReport(int $at): void
    {
        $picture = new NodeDaemonPicture('node', NodeRole::Master, 9999999999,
            new DaemonProcessRoster([new DaemonWorkerPicture(1, 'regular', null, 4096, [
                new DaemonAgentPicture('probe', 'cluster', 'leader'),
            ])], null, 0));
        $this->owner->applyPicturePortion(new DaemonClusterPicturePortionSignalData(false, [
            new ClusterDaemonNodeView('node', true, new ClusterDaemonNodeSlot('node', $picture, $at)),
        ]), $at);
    }

    /** @return StatusPublicSnapshot Actual coherent pair served to a public reader */
    private function publicPair(): StatusPublicSnapshot
    {
        $snapshot = StatusPublicSnapshot::fromJson(Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read());
        $heartbeat = StatusPublicHeartbeat::fromJson(Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->read());
        self::assertSame($snapshot->revision, $heartbeat->revision);
        return $snapshot;
    }

    /** @return StatusIncident Candidate written under the real owner's claim */
    private function candidate(): StatusIncident
    {
        $nowMs = TimeHelper::nowMs();
        return $this->asOwner(fn () => Database::transaction(DatabaseNames::PRIMARY,
            fn () => Hilos::$db->statusIncidents->actions->candidate(
                'service', 'Service', StatusSeverity::MAJOR_OUTAGE, $nowMs - 1000, $nowMs + 300000,
            )));
    }

    /**
     * @template T
     * @param Closure(): T $operation Fixture work under the Status owner's identity
     * @return T Operation result
     */
    private function asOwner(Closure $operation): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->owner->getId()), $operation);
    }

    /** A full empty roster is unknown until a later, distinct report proves absence. */
    private function freshAbsence(): void
    {
        foreach ([true, false] as $snapshot) {
            $at = $snapshot ? 1000 : 2000;
            $picture = new NodeDaemonPicture('node', NodeRole::Master, 9999999999,
                new DaemonProcessRoster([new DaemonWorkerPicture(1, 'regular', null, 4096, [])], null, 0));
            $this->owner->applyPicturePortion(new DaemonClusterPicturePortionSignalData($snapshot, [
                new ClusterDaemonNodeView('node', true, new ClusterDaemonNodeSlot('node', $picture, $at)),
            ]), $at);
        }
    }

    /**
     * @param int $id Target primary id
     * @return array<string, ?string> Independent persisted incident
     */
    private function row(int $id): array
    {
        return Database::sql('SELECT * FROM hilos_status_incident WHERE id = ?', [$id])->rows()[0];
    }

    /**
     * @param int $id Incident primary id
     * @return list<array<string, ?string>> Independent persisted immutable rows
     */
    private function updates(int $id): array
    {
        return Database::sql('SELECT * FROM hilos_status_incident_update WHERE incident_id = ? ORDER BY id', [$id])->rows();
    }

    /** @return list<SignalDTO> All queued frames */
    private function drain(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $frame;
        }
        return $frames;
    }

    /**
     * @param class-string<HandoverAskInterface> $dto Strict signal DTO
     * @param array<string, mixed> $payload Malformed input
     */
    private function assertMalformed(string $dto, array $payload): void
    {
        try {
            $dto::fromArray($payload);
            self::fail('Malformed Status ask must refuse');
        } catch (InvalidFormatException) {
            self::assertTrue(true);
        }
    }

    /**
     * @param string $table Status table with an existing migration stub
     * @param bool $down Whether to drop rather than create
     */
    private function stub(string $table, bool $down): void
    {
        $name = 'create_' . $table;
        if ($down) {
            $name .= '_down';
        }
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name . '.sql'));
    }
}

/** Only the feature under test and a test-local ADMIN page; production route registries are untouched. */
abstract class StatusHandoverHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_STATUS => [AgentRegistryKey::WORKER => StatusAgent::class],
        StatusHandoverGatekeeperAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => StatusHandoverGatekeeperAgent::class],
        'probe' => [AgentRegistryKey::SCOPE => AgentScope::CLUSTER],
    ];
    public const array PAGES = [StatusHandoverGatekeeperPage::PAGE => StatusHandoverGatekeeperPage::class];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'service' => ['name' => 'Service', 'description' => null, 'agentType' => 'probe'],
    ];
}

/** Production lazy Status chains are mounted by the active feature. */
final class StatusHandoverDbContext extends HilosDbContext
{
}

final class StatusHandoverRuntime extends RtContext
{
    /** No project collections; feature mounting supplies the real bindings. */
    public function configure(): void
    {
    }

    /** @param string $phase Isolated row projection without running a destructive operation */
    public function phase(string $phase): void
    {
        $row = ProtectedModeRuntime::create();
        $row->phase = $phase;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $row);
    }

    /** @param bool $enabled Whether the node permits looking at ADMIN pages */
    public function viewMode(bool $enabled): void
    {
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->hilosAdminViewModeRuntime->actions->set($enabled);
    }
}

final class StatusHandoverRouter extends SignalRouter
{
    /** @return class-string<Hilos> Test topology with real Status signal declarations */
    protected function hilosClass(): string
    {
        return StatusHandoverHilos::class;
    }
}

/** Drives the actual worker parsing and HandoverAsk origin scope, without starting a process or socket. */
final class StatusHandoverWorker extends WorkerManager
{
    /** @param StatusAgent $owner Actual production owner to register */
    public function __construct(StatusAgent $owner)
    {
        parent::__construct(1);
        $this->replaceOwner($owner);
    }

    /** @param StatusAgent $owner Replacement instance retaining the same claims and durable data */
    public function replaceOwner(StatusAgent $owner): void
    {
        $this->agentManager->addAgent($owner->getId(), $owner);
    }

    /** @param SignalDTO $frame Real agent frame, passed through the worker's normal entry */
    public function deliver(SignalDTO $frame): void
    {
        $dispatch = Closure::bind(static function (self $worker, SignalDTO $frame): void {
            $worker->handleAgentMessage(new DaemonAgentMessageDTO(HilosAgentType::HILOS_STATUS, $frame));
        }, null, WorkerManager::class);
        ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_STATUS), fn () => $dispatch($this, $frame));
    }

    /**
     * @param AgentInterface $agent Agent serving the fixture page
     * @return PageSignalRouter Existing page machinery with declarations computed from the test topology
     */
    public static function pageRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(new HilosPageFactory($agent, StatusHandoverHilos::class),
            new ActionRouteConfig(StatusHandoverHilos::getPageActionRoutes()),
            new SignalRouteConfig(StatusHandoverHilos::getPageSignalRoutes()));
    }

    /** @return SignalRouter Topology-driven router */
    protected function createSignalRouter(): SignalRouter
    {
        return new StatusHandoverRouter();
    }

    /** @return AgentManager Fixture registry, without any production lifecycle changes */
    protected function createAgentManager(): AgentManager
    {
        return new StatusHandoverAgentManager();
    }

    /**
     * @param AgentInterface $agent Page-serving agent
     * @return PageSignalRouter Normal fixture page router
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return self::pageRouter($agent);
    }
}

final class StatusHandoverAgentManager extends AgentManager
{
    /**
     * @param string $agentType Requested type, unused
     * @param ?string $agentIndex Requested index, unused
     * @return AgentInterface Production Status owner
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new StatusAgent();
    }
}

final class StatusHandoverGatekeeperAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'fixture_status_gatekeeper';

    /** The fixture owns no background work. */
    public function onStop(): void
    {
    }
}

/** Test-local write action; it is deliberately absent from every production page. */
final class StatusHandoverGatekeeperPage extends AbstractPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = 'fixture_status_gatekeeper';
    public const string ACTION = 'fixture_status_publish';
    public const string DONE = 'fixture_status_done';
    public const string SUBSCRIPTION_AGENT_TYPE = StatusHandoverGatekeeperAgent::AGENT_TYPE;
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
    public const array ACTIONS = [self::ACTION => StatusHandoverActionDTO::class];
    public const array SIGNALS = [SignalTypeConstants::AGENT_SIGNAL => [self::DONE => HandoverAnswerSignalData::class]];

    /**
     * @param string $acceptKey Initiating connection
     * @param string $action Fixture action name
     * @param ActionPayloadDTO $dto Typed fixture payload
     * @return ?ActionReplyDTO No immediate reply from the forwarding gatekeeper
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case self::ACTION:
                if (!$dto instanceof StatusHandoverActionDTO) {
                    throw new InvalidActionPayloadException($action, StatusHandoverActionDTO::class, $dto);
                }
                $this->forward(HilosSignalConstants::STATUS_PUBLISH_NOW, new StatusPublishNowSignalData(
                    $dto->incidentId, self::DONE, $acceptKey, $this->currentActionRequestId(), $action, 'Published.',
                ));
                return null;

            default:
                throw new AgentUnknownActionException($action);
        }
    }

    /**
     * @param AgentSignalData $data Parsed owner outcome
     * @param string $sender Status owner address
     * @param string $name Fixture reply name
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case self::DONE:
                $this->answerHandover($data->data);
                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }
}

final class StatusHandoverActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /** @param int $incidentId Fixture target primary key */
    public function __construct(public readonly int $incidentId)
    {
    }

    /** @return string Fixture action name */
    public function getAction(): string
    {
        return StatusHandoverGatekeeperPage::ACTION;
    }

    /** @return array<string, mixed> Fixture action payload */
    public function toArray(): array
    {
        return ['incidentId' => $this->incidentId];
    }

    /**
     * @param array<string, mixed> $data Fixture action payload
     * @return static Typed target
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, 'incidentId'));
    }
}

/** Records write-time/commit-time ordering and can fail after both timeline and runtime writes. */
final class StatusHandoverCommitProbe implements SourceMirrorSubscriberInterface
{
    public bool $watch = false;
    public bool $failAfterTimeline = false;
    /** @var list<array{transaction: bool, queued: ?SignalDTO, origin: ?string, requestId: ?string}> Write-time observations */
    public array $writes = [];
    /** @var list<bool> Transaction state when real afterCommit callbacks run */
    public array $afterCommitTransactions = [];

    /**
     * @param SourceChange $change Actual ORM write
     * @param SourceChangeProvenance $provenance Local write or rollback
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if (!$this->watch || !in_array($change->sourceKey, [HilosDbContext::statusIncidents, HilosDbContext::statusIncidentUpdates], true)) {
            return;
        }
        $this->writes[] = ['transaction' => Database::hasOpenTransactions(), 'queued' => Hilos::$sr->getNextQueuedSignal(),
            'origin' => $change->origin, 'requestId' => $change->originRequestId];
        if (Database::hasOpenTransactions()) {
            Database::afterCommit(function (): void {
                $this->afterCommitTransactions[] = Database::hasOpenTransactions();
            });
        }
        if ($this->failAfterTimeline && $change->sourceKey === HilosDbContext::statusIncidentUpdates
            && $change->mutationType === TableMutationType::Create) {
            $this->failAfterTimeline = false;
            Hilos::$rt->hilosStatusComponents['service']->actions->update(1, 1, 2000);
            throw new DatabaseException('Injected failure after timeline and runtime writes.');
        }
    }
}
