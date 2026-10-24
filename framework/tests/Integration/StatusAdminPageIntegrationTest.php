<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
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
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Cluster\NodeRole;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Hilos;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Notification\HilosNotifier;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusSeverity;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\TimeHelper;
use ReflectionProperty;

/** Production ADMIN page forwarding, real owner commits, independent SQL echo and initial/live viewer wire. */
final class StatusAdminPageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user', 'hilos_user_merge', 'hilos_account_deletion', 'hilos_status_incident', 'hilos_status_incident_update',
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
    private StatusAdminPageWorker $worker;
    private StatusAdminPageRuntime $runtime;
    private PageSignalRouter $gatekeeper;
    private StatusAdminPageCommitProbe $probe;

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
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusAdminPageHilos::class);
        foreach (array_reverse(self::TABLES) as $table) {
            $this->stub($table, true);
        }
        foreach (self::TABLES as $table) {
            $this->stub($table, false);
        }
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin) VALUES (7, 'Admin', 1), (5, 'Viewer', 0)");
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new StatusAdminPageDbContext();
        Hilos::$db->configure();
        $this->runtime = new StatusAdminPageRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->bindStateCollectionNames();
        Hilos::$rt = $this->runtime;
        StatusAdminPageHilos::initBrowser(new IdentityTestBrowser(7, true));
        $this->owner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->owner);
        $this->worker = new StatusAdminPageWorker($this->owner);
        Hilos::$browser->bindPageResender($this->worker);
        $this->gatekeeper = StatusAdminPageWorker::pageRouter(new StatusAdminPageGatekeeperAgent());
        $this->asOwner(fn () => $this->owner->onStart());
        $this->drain();
        $this->probe = new StatusAdminPageCommitProbe();
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
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page('ak-status'));
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
            parent::tearDown();
        }
    }

    public function testProductionPageDefersSuccessUntilTheOwnerCommitsWithOriginalCorrelation(): void
    {
        $incident = $this->candidate();
        $this->drain();
        $this->probe->watch = true;
        $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO('ak-status', HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW,
            ['incidentId' => $incident->id], 'publish-1'), 'websocket');
        $forwarded = $this->drain();
        self::assertCount(1, $forwarded);
        self::assertSame(HilosSignalConstants::STATUS_PUBLISH_NOW, $forwarded[0]->signalName->getName());
        self::assertSame($incident->id, $forwarded[0]->data->data->incidentId);
        self::assertNull($this->row($incident->id)['published_at']);
        $this->deliver($forwarded[0]);
        $answer = $this->answer($this->drain());
        $this->success($answer);
        self::assertSame('publish-1', $answer->requestId);
        self::assertSame(HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW, $answer->action);
        self::assertCount(2, $this->probe->writes);
        foreach ($this->probe->writes as $write) {
            self::assertTrue($write['transaction']);
            self::assertNull($write['queued'], 'No ack or sync before commit');
            self::assertSame('ak-status', $write['origin']);
            self::assertSame('publish-1', $write['requestId']);
        }
        self::assertSame([false, false], $this->probe->afterCommitTransactions);
        self::assertNotNull($this->row($incident->id)['published_at']);
        self::assertSame(['published'], array_column($this->updates($incident->id), 'kind'));
        $this->deliverAnswer($answer);
        $ack = $this->drain();
        self::assertCount(1, $ack);
        self::assertSame(SignalConstants::ACTION_SUCCESS, $ack[0]->signalName->getName());
        self::assertSame('publish-1', $ack[0]->data->data->requestId);
        self::assertSame('Outage published.', $ack[0]->data->data->message);
    }

    public function testCancelTargetsTheOriginalIncidentAndSuppressionRefusesPublication(): void
    {
        $incident = $this->candidate();
        $answer = $this->submit(HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION, ['incidentId' => $incident->id]);
        $this->success($answer);
        self::assertSame('Publication canceled.', $answer->successMessage);
        self::assertNotNull($this->row($incident->id)['suppressed_at']);
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION, ['incidentId' => $incident->id]));
        $this->refusal($this->submit(HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW, ['incidentId' => $incident->id]), 'canceled');
        $this->asOwner(fn () => $incident->actions->resolve(TimeHelper::nowMs()));
        $replacement = $this->candidate();
        foreach ([HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION, HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW,
            HilosSignalConstants::HILOS_STATUS_ADD_UPDATE] as $action) {
            $this->refusal($this->submit($action, ['incidentId' => $incident->id, 'text' => 'Old draft']), 'already ended');
            $this->refusal($this->submit($action, ['incidentId' => 999999, 'text' => 'Old draft']), 'no longer exists');
        }
        self::assertNull($this->row($replacement->id)['published_at']);
        self::assertNull($this->row($replacement->id)['suppressed_at']);
    }

    public function testAppendRetainsUnicodeBoundsPlainTextAndRequestIdIdempotency(): void
    {
        $incident = $this->candidate();
        $this->refusal($this->submit(HilosSignalConstants::HILOS_STATUS_ADD_UPDATE,
            ['incidentId' => $incident->id, 'text' => 'Before']), 'Publish this incident');
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW, ['incidentId' => $incident->id]));
        $payload = ['incidentId' => $incident->id, 'text' => '  <b>Команда</b>  '];
        $answer = $this->submit(HilosSignalConstants::HILOS_STATUS_ADD_UPDATE, $payload, 'append-1');
        $this->success($answer);
        self::assertSame('Update published.', $answer->successMessage);
        $saved = $this->updates($incident->id);
        self::assertSame('<b>Команда</b>', $saved[1]['text']);
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_ADD_UPDATE, $payload, 'append-1'));
        self::assertSame($saved, $this->updates($incident->id));
        $this->refusal($this->submit(HilosSignalConstants::HILOS_STATUS_ADD_UPDATE,
            ['incidentId' => $incident->id, 'text' => 'Changed'], 'append-1'), 'different text');
        foreach (['', '  ', str_repeat('я', 5001), "\xFF"] as $text) {
            $answer = $this->submit(HilosSignalConstants::HILOS_STATUS_ADD_UPDATE,
                ['incidentId' => $incident->id, 'text' => $text], 'invalid-text');
            self::assertNotNull($answer->error);
            self::assertNull($answer->errorDetail);
            self::assertSame($saved, $this->updates($incident->id));
        }
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_ADD_UPDATE,
            ['incidentId' => $incident->id, 'text' => str_repeat('я', 5000)], 'max-text'));
        self::assertSame(5000, mb_strlen($this->updates($incident->id)[2]['text'], 'UTF-8'));
    }

    public function testTimerAndPagePublishRaceShareOnePublicationInEitherOrder(): void
    {
        foreach ([true, false] as $timerFirst) {
            $incident = $this->candidate();
            $this->freshAbsence();
            if ($timerFirst) {
                $this->asOwner(fn () => $this->owner->tickAt($incident->publishAt));
            }
            $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW, ['incidentId' => $incident->id]));
            $this->asOwner(fn () => $this->owner->tickAt($incident->publishAt + 1));
            $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW, ['incidentId' => $incident->id]));
            self::assertSame(['published'], array_column($this->updates($incident->id), 'kind'));
            $this->asOwner(fn () => $incident->actions->resolve(TimeHelper::nowMs()));
        }
    }

    public function testViewerAndMalformedSubmitsNeverReachTheWriter(): void
    {
        $incident = $this->candidate();
        foreach ([false, true] as $viewMode) {
            $this->runtime->viewMode($viewMode);
            StatusAdminPageHilos::initBrowser(new IdentityTestBrowser(5, false));
            foreach (AbstractHilosStatusPage::ACTIONS as $action => $dto) {
                $this->drain();
                $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO('ak-status', $action,
                    ['incidentId' => $incident->id, 'text' => 'No write'], 'denied'), 'websocket');
                $frames = $this->drain();
                self::assertCount(1, $frames);
                self::assertSame(SignalConstants::ACTION_ERROR, $frames[0]->signalName->getName());
                self::assertSame($viewMode ? 'view_mode' : 'forbidden', $frames[0]->data->data->errorCode);
            }
        }
        StatusAdminPageHilos::initBrowser(new IdentityTestBrowser(7, true));
        $this->runtime->viewMode(false);
        foreach ([[], ['incidentId' => 0], ['incidentId' => '1'], ['incidentId' => $incident->id, 'text' => null]] as $payload) {
            $this->drain();
            $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO('ak-status', HilosSignalConstants::HILOS_STATUS_ADD_UPDATE,
                $payload, 'malformed'), 'websocket');
            $frames = $this->drain();
            self::assertCount(1, $frames);
            self::assertSame(SignalConstants::ACTION_ERROR, $frames[0]->signalName->getName());
        }
        self::assertNull($this->row($incident->id)['published_at']);
        self::assertSame([], $this->updates($incident->id));
    }

    public function testViewerTextIsHiddenInInitialAndLiveProductionPageResponses(): void
    {
        $incident = $this->candidate();
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW, ['incidentId' => $incident->id]));
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_ADD_UPDATE,
            ['incidentId' => $incident->id, 'text' => 'Private team text'], 'text-1'));
        $this->runtime->viewMode(true);
        StatusAdminPageHilos::initBrowser(new IdentityTestBrowser(5, false));
        Hilos::$browser->bindPageResender($this->worker);
        Hilos::$sr->subscribeToPage(AbstractHilosStatusPage::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-status', AbstractHilosStatusPage::PAGE));
        Hilos::$sr->bindPageAgent('ak-status', AbstractHilosStatusPage::PAGE, HilosAgentType::HILOS_INDEX, null);
        $this->drain();
        self::assertSame(PageResendOutcome::Answered, $this->worker->resendPage(AbstractHilosStatusPage::PAGE, 'ak-status', []));
        $this->assertHiddenOverview();
        $this->success($this->submitAsAdminUpdate($incident->id));
        Hilos::$browser->record(SourceChange::dbUpdated(HilosDbContext::statusIncidentUpdates, '9', []));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertHiddenOverview();
    }

    /** @param int $id Published incident target
     * @return HandoverAnswerSignalData Outcome after another admin appends text
     */
    private function submitAsAdminUpdate(int $id): HandoverAnswerSignalData
    {
        $viewer = Hilos::$browser;
        StatusAdminPageHilos::initBrowser(new IdentityTestBrowser(7, true));
        try {
            return $this->submit(HilosSignalConstants::HILOS_STATUS_ADD_UPDATE, ['incidentId' => $id, 'text' => 'Later secret'], 'text-2');
        } finally {
            StatusAdminPageHilos::initBrowser($viewer);
        }
    }

    private function assertHiddenOverview(): void
    {
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]->signalName->getName());
        $body = $frames[0]->data->data->toArray()['payload']['data']['status'];
        self::assertSame($this->row($body['incidents'][0]['incidentId'])['id'], (string)$body['incidents'][0]['incidentId']);
        self::assertNotEmpty($body['incidents'][0]['updates']);
        foreach ($body['incidents'][0]['updates'] as $update) {
            self::assertSame(HiddenValue::mark(), $update['text']);
        }
    }

    /**
     * @param string $action Production page action name
     * @param array<string, mixed> $payload Raw client body
     * @param string $requestId Original correlation
     * @return HandoverAnswerSignalData Committed owner result before the gatekeeper ack
     */
    private function submit(string $action, array $payload, string $requestId = 'button-1'): HandoverAnswerSignalData
    {
        $this->drain();
        $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO('ak-status', $action, $payload, $requestId), 'websocket');
        $forwarded = $this->drain();
        self::assertCount(1, $forwarded, 'No ack at forwarding time');
        self::assertSame($requestId, $forwarded[0]->data->data->requestId);
        self::assertSame($payload['incidentId'], $forwarded[0]->data->data->incidentId);
        $this->deliver($forwarded[0]);
        $answer = $this->answer($this->drain());
        self::assertSame($requestId, $answer->requestId);
        self::assertSame($action, $answer->action);
        $this->deliverAnswer($answer);
        $acks = $this->drain();
        self::assertCount(1, $acks);
        self::assertSame($answer->error === null ? SignalConstants::ACTION_SUCCESS : SignalConstants::ACTION_ERROR,
            $acks[0]->signalName->getName());
        self::assertSame($requestId, $acks[0]->data->data->requestId);
        return $answer;
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
            HilosAgentType::HILOS_STATUS, HilosSignalConstants::HILOS_STATUS_WRITE_DONE);
    }

    /**
     * @param list<SignalDTO> $frames Committed sync announcements and exactly one handover answer
     * @return HandoverAnswerSignalData Parsed owner outcome
     */
    private function answer(array $frames): HandoverAnswerSignalData
    {
        $answers = array_values(array_filter($frames,
            static fn (SignalDTO $frame): bool => $frame->signalName->getName() === HilosSignalConstants::HILOS_STATUS_WRITE_DONE));
        self::assertCount(1, $answers);
        self::assertSame(HilosSignalConstants::HILOS_STATUS_WRITE_DONE, $frames[array_key_last($frames)]->signalName->getName(),
            'Committed sync announcements must precede the owner answer');
        self::assertInstanceOf(AgentSignalData::class, $answers[0]->data);
        $answer = $answers[0]->data->data;
        self::assertInstanceOf(HandoverAnswerSignalData::class, $answer);
        self::assertSame('ak-status', $answer->acceptKey);
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
abstract class StatusAdminPageHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_STATUS => [AgentRegistryKey::WORKER => StatusAgent::class],
        StatusAdminPageGatekeeperAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => StatusAdminPageGatekeeperAgent::class],
        'probe' => [AgentRegistryKey::SCOPE => AgentScope::CLUSTER],
    ];
    public const array PAGES = [StatusAdminPageGatekeeperPage::PAGE => StatusAdminPageGatekeeperPage::class];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'service' => ['name' => 'Service', 'description' => null, 'agentType' => 'probe'],
    ];
}

/** Production lazy Status chains are mounted by the active feature. */
final class StatusAdminPageDbContext extends HilosDbContext
{
}

final class StatusAdminPageRuntime extends RtContext
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

final class StatusAdminPageRouter extends SignalRouter
{
    /** @return class-string<Hilos> Test topology with real Status signal declarations */
    protected function hilosClass(): string
    {
        return StatusAdminPageHilos::class;
    }
}

/** Drives the actual worker parsing and HandoverAsk origin scope, without starting a process or socket. */
final class StatusAdminPageWorker extends WorkerManager
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
        return new PageSignalRouter(new HilosPageFactory($agent, StatusAdminPageHilos::class),
            new ActionRouteConfig(StatusAdminPageHilos::getPageActionRoutes()),
            new SignalRouteConfig(StatusAdminPageHilos::getPageSignalRoutes()));
    }

    /** @return SignalRouter Topology-driven router */
    protected function createSignalRouter(): SignalRouter
    {
        return new StatusAdminPageRouter();
    }

    /** @return AgentManager Fixture registry, without any production lifecycle changes */
    protected function createAgentManager(): AgentManager
    {
        $manager = new StatusAdminPageAgentManager();
        $agent = new StatusAdminPageGatekeeperAgent();
        $manager->addAgent($agent->getId(), $agent);
        return $manager;
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

final class StatusAdminPageAgentManager extends AgentManager
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

final class StatusAdminPageGatekeeperAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /** The fixture owns no background work. */
    public function onStop(): void
    {
    }
}

/** The thin project binding contains no forwarding or reply implementation. */
final class StatusAdminPageGatekeeperPage extends AbstractHilosStatusPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusAdminPageCommitProbe implements SourceMirrorSubscriberInterface
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
