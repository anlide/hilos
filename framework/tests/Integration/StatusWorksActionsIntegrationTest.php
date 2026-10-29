<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Hilos\Status\DTO\StatusWorksData;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\DTO\StatusChangeWorkSignalData;
use Hilos\Status\StatusAgent;
use Hilos\Tests\Fixtures\StatusWorksEnvironment;
use Hilos\Tests\Fixtures\StatusWorksHilos;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\Utils\Helpers\TimeHelper;
use ReflectionProperty;

/** Real page -> worker -> owner -> SQL -> page ack; real commits are essential for mail/announcement assertions. */
final class StatusWorksActionsIntegrationTest extends FrameworkIntegrationTestCase
{
    private StatusWorksEnvironment $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = new StatusWorksEnvironment();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->env)) {
                $this->env->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testScheduleChangeNoopConflictCancelAndStaleIdentityUseRealHandover(): void
    {
        $payload = $this->payload();
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload));
        $row = $this->row();
        self::assertSame('Upgrade', $row['title']);
        self::assertSame('Plain description', $row['description']);
        self::assertSame('1', $row['version']);
        self::assertNull($row['last_mail_queued_at']);
        $target = ['workId' => (int)$row['id'], 'publicId' => $row['public_id'], 'expectedVersion' => 1];
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE, $target + $payload));
        self::assertSame('1', $this->row()['version']);
        self::assertCount(1, new ReflectionProperty(StatusAgent::class, 'subscriberNotices')->getValue($this->env->owner));
        $changed = array_replace($payload, ['title' => 'New title', 'componentKeys' => ['mail']]);
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE, $target + $changed));
        self::assertSame('2', $this->row()['version']);
        self::assertSame([['component_key' => 'mail']], Database::sql('SELECT component_key FROM hilos_status_work_component')->rows());
        $this->refusal($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE, $target + $payload), 'another window');
        $this->refusal($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL,
            array_replace($target, ['publicId' => str_repeat('f', 32)])), 'no longer exists');
        self::assertSame('New title', $this->row()['title']);
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL, array_replace($target, ['expectedVersion' => 2])));
        self::assertNotNull($this->row()['canceled_at']);
        self::assertSame('3', $this->row()['version']);
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL, $target));
        self::assertCount(3, new ReflectionProperty(StatusAgent::class, 'subscriberNotices')->getValue($this->env->owner));
        $this->refusal($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE,
            array_replace($target, ['expectedVersion' => 3]) + $payload), 'canceled');
    }

    public function testOneOwnerSerializesCompetingCreatesAndChangesWithHalfOpenGlobalWindows(): void
    {
        $payload = $this->payload();
        $first = $this->env->forward(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload, 'first');
        $second = $this->env->forward(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE,
            array_replace($payload, ['componentKeys' => ['mail']]), 'second');
        $this->success($this->env->land($first));
        $this->refusal($this->env->land($second), 'overlaps');
        self::assertCount(1, Database::sql('SELECT id FROM hilos_status_work')->rows());
        $target = $this->target();
        $changeA = $this->env->forward(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE, $target + array_replace($payload, ['title' => 'A']));
        $changeB = $this->env->forward(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE, $target + array_replace($payload, ['title' => 'B']));
        $this->success($this->env->land($changeA));
        $this->refusal($this->env->land($changeB), 'another window');
        $adjacent = array_replace($payload, ['startsAt' => $payload['endsAt'], 'endsAt' => $payload['endsAt'] + 60000]);
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $adjacent));
        self::assertCount(2, Database::sql('SELECT id FROM hilos_status_work')->rows());
    }

    public function testServerValidationRejectsBadTextClockAndComponentSetsWithoutWrites(): void
    {
        $payload = $this->payload();
        foreach ([['title' => '  '], ['title' => str_repeat('я', 201)], ['title' => "\xff"],
            ['description' => str_repeat('я', 2001)], ['description' => "\xff"], ['componentKeys' => []],
            ['componentKeys' => ['service', 'service']], ['componentKeys' => ['unknown']],
            ['startsAt' => 1000], ['startsAt' => $payload['startsAt'] + 1], ['endsAt' => $payload['endsAt'] + 1],
            ['endsAt' => $payload['startsAt']]] as $invalid) {
            $answer = $this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, array_replace($payload, $invalid));
            self::assertNotNull($answer->error);
            self::assertSame([], Database::sql('SELECT id FROM hilos_status_work')->rows());
            self::assertCount(0, new ReflectionProperty(StatusAgent::class, 'subscriberNotices')->getValue($this->env->owner));
        }
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE,
            array_replace($payload, ['title' => str_repeat('я', 200), 'description' => '   '])));
        self::assertNull($this->row()['description']);
    }

    public function testBegunClockCanKeepItsStartButConsumedAndExpiredTargetsRefuse(): void
    {
        $now = TimeHelper::nowMs();
        $start = intdiv($now, 1000) * 1000 - 1000;
        $work = $this->env->asOwner(fn () => Database::transaction('primary', fn () => Hilos::$db->statusWorks->actions->create(
            'Begun window', null, $start, $start + 60000, $now - 2000, ['service'],
        )));
        $this->env->drain();
        $payload = array_replace($this->payload(), ['startsAt' => $start, 'endsAt' => $start + 60000]);
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE, $this->target() + $payload));
        $this->refusal($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE,
            $this->target() + array_replace($payload, ['startsAt' => $start - 1000])), 'future start');
        $this->env->asOwner(fn () => Hilos::$db->statusWorks[$work->id]->actions->consume(TimeHelper::nowMs()));
        $this->env->drain();
        $this->refusal($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL, $this->target()), 'started');
        $expired = $this->env->asOwner(fn () => Database::transaction('primary', fn () => Hilos::$db->statusWorks->actions->create(
            'Expired window', null, $start - 10000, $start - 1000, $now - 20000, ['service'],
        )));
        $this->env->drain();
        $this->refusal($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL,
            ['workId' => $expired->id, 'publicId' => $expired->publicId, 'expectedVersion' => $expired->version]), 'expired');
    }

    public function testOwnerRechecksAdminGateAndFreezeBeforeReadingAnyDatabaseOrRights(): void
    {
        $payload = $this->payload();
        $frame = $this->env->forward(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload);
        $this->env->runtime->viewer();
        Hilos::$browser = new IdentityTestBrowser(null, false);
        $this->refusal($this->env->land($frame), 'View mode');
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_work')->rows());
        Hilos::$browser = new IdentityTestBrowser(7, true);
        foreach ([ProtectedModeRuntime::PHASE_ACTIVE, ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::PHASE_DEACTIVATING, null] as $phase) {
            $frame = $this->env->forward(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload);
            $this->env->runtime->phase($phase);
            $db = Hilos::$db;
            Hilos::$db = null;
            try {
                $this->refusal($this->env->land($frame), 'unavailable');
            } finally {
                Hilos::$db = $db;
                $this->env->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
            }
        }
    }

    public function testForgedMetadataDoesNotChangeWhichOperationTheOwnerGates(): void
    {
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $this->payload()));
        $frame = $this->env->forward(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE, $this->target() + $this->payload());
        $frame = new SignalDTO($frame->signalSource, $frame->signalType, $frame->signalName,
            new AgentSignalData(StatusChangeWorkSignalData::fromArray(array_replace($frame->data->data->toArray(),
                ['action' => HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW]))));
        $this->refusal($this->env->land($frame), 'no longer matches');
        self::assertSame('1', $this->row()['version']);
    }

    public function testDeletedAndRecreatedPrimaryIdCannotInheritAnOldForm(): void
    {
        $payload = $this->payload();
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload));
        $target = $this->target();
        $row = $this->row();
        Database::sql('DELETE FROM hilos_status_work_component WHERE work_id = ?', [$target['workId']]);
        Database::sql('DELETE FROM hilos_status_work WHERE id = ?', [$target['workId']]);
        Database::sql('INSERT INTO hilos_status_work (id, public_id, title, description, starts_at, ends_at, created_at, updated_at, version)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)', [$target['workId'], str_repeat('f', 32), $row['title'], $row['description'],
                (int)$row['starts_at'], (int)$row['ends_at'], (int)$row['created_at'], (int)$row['updated_at']]);
        Database::sql('INSERT INTO hilos_status_work_component (work_id, component_key) VALUES (?, ?)', [$target['workId'], 'service']);
        Hilos::$db->forgetLoadedDataForTest();
        $this->refusal($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL, $target), 'no longer exists');
        $this->refusal($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE, $target + $payload), 'no longer exists');
        self::assertSame('1', $this->row()['version']);
        self::assertNull($this->row()['canceled_at']);
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL, $this->target()));
    }

    public function testRollbackReleasesNoMailNoFilesAndAnswerFollowsCommit(): void
    {
        $beforeFiles = Hilos::$fs->status->entries();
        $probe = new class implements SourceMirrorSubscriberInterface {
            public bool $fail = true;
            public array $commits = [];

            /**
             * @param SourceChange $change Actual ORM announcement
             * @param SourceChangeProvenance $provenance Local write or rollback
             */
            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey !== HilosDbContext::statusWorkComponents || !Database::hasOpenTransactions()) {
                    return;
                }
                self::assertNoAck();
                Database::afterCommit(function (): void {
                    $this->commits[] = Database::hasOpenTransactions();
                });
                if ($this->fail) {
                    $this->fail = false;
                    throw new DatabaseException('Injected work/component failure.');
                }
            }

            /** Records the absence of any premature tracked reply during writes. */
            private static function assertNoAck(): void
            {
                StatusWorksActionsIntegrationTest::assertNull(Hilos::$sr->getNextQueuedSignal());
            }
        };
        SourceChangeBus::subscribe($probe);
        self::assertNotNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $this->payload())->error);
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_work')->rows());
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_work_component')->rows());
        self::assertSame([], $probe->commits);
        self::assertSame($beforeFiles, Hilos::$fs->status->entries());
        self::assertCount(0, new ReflectionProperty(StatusAgent::class, 'subscriberNotices')->getValue($this->env->owner));
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $this->payload()));
        self::assertSame([false], $probe->commits);
    }

    public function testCommittedBridgeReplacementUpdatesOneParentAndIndependentSummariesWithoutApplyingTheWindow(): void
    {
        $payload = $this->payload();
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload));
        $id = (int)$this->row()['id'];
        $this->env->open();
        $this->env->open(HilosPageConstants::HILOS_STATUS, 'ak-overview');
        $this->env->drain();
        Hilos::$browser->flushToSignalRouter();
        $this->env->drain();
        $held = Hilos::$sr->getTableViewport('ak-status', HilosStatusWorksTable::TABLE);
        self::assertTrue($held->hasRow((string)$id));
        $this->env->asOwner(fn () => Database::transaction('primary',
            fn () => Hilos::$db->statusWorkComponents->actions->replaceForWork($id, 'mail')));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $frames = $this->env->drain();
        $deltas = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        self::assertCount(1, $deltas);
        self::assertSame('row_updated', $deltas[0]->data->data->kind);
        self::assertSame($id, $deltas[0]->data->data->row['rowKey']);
        self::assertSame(['mail'], $deltas[0]->data->data->row['slots']['work']['componentKeys']);
        self::assertFalse($deltas[0]->data->data->own);
        $pages = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE));
        self::assertCount(2, $pages, 'One coalesced summary per subscribed page');
        foreach ($pages as $page) {
            $data = $page->data->data;
            if ($data->pageKey === HilosPageConstants::HILOS_STATUS_WORKS) {
                self::assertSame([], $data->payload->windows, 'A count does not Apply the held window');
                self::assertSame(['mail'], $data->payload->data['statusWorks']['nextWork']['componentKeys']);
            } else {
                self::assertSame(['mail'], $data->payload->data['status']['scheduledWorks']['nextWork']['componentKeys']);
            }
        }
        $version = Hilos::$db->statusWorks[$id]->version;
        $this->env->asOwner(fn () => Hilos::$db->statusWorks[$id]->actions->markMailQueued(TimeHelper::nowMs()));
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->env->drain();
        $deltas = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        self::assertCount(1, $deltas);
        self::assertSame($version, $deltas[0]->data->data->row['slots']['work']['version']);
        self::assertNotNull($deltas[0]->data->data->row['slots']['work']['lastMailQueuedAt']);
        $this->env->asOwner(fn () => Hilos::$db->statusWorks[$id]->actions->cancel(TimeHelper::nowMs()));
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->env->drain();
        self::assertSame($held, Hilos::$sr->getTableViewport('ak-status', HilosStatusWorksTable::TABLE));
        $deltas = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        self::assertCount(1, $deltas);
        self::assertSame('row_removed', $deltas[0]->data->data->kind);
        self::assertSame('left_set', $deltas[0]->data->data->reason);
        self::assertFalse($deltas[0]->data->data->own, 'The client retains the ordinary Apply gate');
        self::assertFalse(Hilos::$table->get(HilosStatusWorksTable::TABLE)->containsRow($id, new TableQueryDTO()));
        $pages = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE));
        self::assertCount(2, $pages);
        self::assertSame(0, StatusWorksData::read()->upcomingCount);
    }

    public function testViewerFirstAnswerShowsOnlyTheNonpersonalWorkAndRefusesAForgedWrite(): void
    {
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $this->payload()));
        $row = $this->row();
        $this->env->runtime->viewer();
        StatusWorksHilos::initBrowser(new IdentityTestBrowser(5, false));
        $this->env->open();
        $pages = array_values(array_filter($this->env->drain(), static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE));
        self::assertCount(1, $pages);
        $reply = $pages[0]->data->data;
        self::assertTrue(Hilos::$browser->isAdminViewModeViewer(AbstractHilosStatusWorksPage::class, 'ak-status'));
        self::assertSame(1, $reply->payload->data['statusWorks']['upcomingCount']);
        $work = $reply->payload->windows[HilosStatusWorksTable::TABLE]['rows'][0]['slots']['work'];
        self::assertSame($row['public_id'], $work['publicId']);
        self::assertSame('Upgrade', $work['title']);
        self::assertArrayNotHasKey('actorId', $work);
        self::assertArrayNotHasKey('audience', $work);
        $this->env->gatekeeper->dispatchAction(new WebSocketActionSignalDTO('ak-status', HilosSignalConstants::HILOS_STATUS_WORK_CANCEL,
            ['workId' => (int)$row['id'], 'publicId' => $row['public_id'], 'expectedVersion' => 1], 'viewer'), 'websocket');
        $denied = $this->env->drain();
        self::assertCount(1, $denied);
        self::assertSame('view_mode', $denied[0]->data->data->errorCode);
        self::assertNull($this->row()['canceled_at']);
    }

    public function testDatabaseRecoveryRepaysARefusedWindowAndNullSummariesWithoutReload(): void
    {
        $this->env->open();
        $this->env->open(HilosPageConstants::HILOS_STATUS, 'ak-overview');
        $this->env->drain();
        Hilos::$browser->flushToSignalRouter();
        $this->env->drain();
        Database::close();
        try {
            $this->env->gatekeeper->dispatchTableViewport(new WebSocketTableViewportSignalDTO(
                'ak-status', HilosPageConstants::HILOS_STATUS_WORKS, HilosStatusWorksTable::TABLE, limit: 2,
            ), 'websocket', HilosPageConstants::HILOS_STATUS_WORKS);
            $refused = $this->env->drain();
            self::assertCount(1, $refused);
            self::assertSame(SignalTypeConstants::TABLE_WINDOW_REFUSED, $refused[0]->signalName->getName());
            Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $pages = array_values(array_filter($this->env->drain(), static fn (SignalDTO $frame): bool =>
                $frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE));
            self::assertCount(2, $pages);
            foreach ($pages as $page) {
                $reply = $page->data->data;
                self::assertNull($reply->pageKey === HilosPageConstants::HILOS_STATUS_WORKS
                    ? $reply->payload->data['statusWorks'] : $reply->payload->data['status']['scheduledWorks']);
            }
        } finally {
            Database::connect();
        }
        Hilos::$browser->recordDatabaseBack();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $frames = $this->env->drain();
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak-status', HilosStatusWorksTable::TABLE));
        self::assertCount(1, array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::TABLE_WINDOW));
        self::assertSame(0, StatusWorksData::read()->upcomingCount);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertSame([], $this->env->drain());
    }

    public function testOwnCancellationCarriesTheStandardImmediateFlag(): void
    {
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $this->payload()));
        $row = $this->row();
        $this->env->open();
        $this->env->drain();
        Hilos::$browser->flushToSignalRouter();
        $this->env->drain();
        $this->success($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL,
            ['workId' => (int)$row['id'], 'publicId' => $row['public_id'], 'expectedVersion' => 1]));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $deltas = array_values(array_filter($this->env->drain(), static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        self::assertCount(1, $deltas);
        self::assertSame('row_removed', $deltas[0]->data->data->kind);
        self::assertTrue($deltas[0]->data->data->own);
    }

    public function testUnreadySummaryRecoversWithoutReloadAndRollbackPublishesNoSourceFacts(): void
    {
        $this->env->open();
        $this->env->open(HilosPageConstants::HILOS_STATUS, 'ak-overview');
        $this->env->drain();
        Hilos::$browser->flushToSignalRouter();
        $this->env->drain();
        foreach ([SourceConsumer::agent($this->env->owner->getId()), SourceConsumer::page('ak-status'),
            SourceConsumer::page('ak-overview')] as $consumer) {
            SourceInterestRegistry::releaseConsumer($consumer);
        }
        $this->env->worker->pageInterests(HilosPageConstants::HILOS_STATUS_WORKS, 'ak-status', false);
        $this->env->worker->pageInterests(HilosPageConstants::HILOS_STATUS, 'ak-overview', false);
        SourceInterestRegistry::readsWhatIsDelivered();
        try {
            self::assertNull(StatusWorksData::read());
            $held = Hilos::$sr->getTableViewport('ak-status', HilosStatusWorksTable::TABLE);
            Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $pages = array_values(array_filter($this->env->drain(), static fn (SignalDTO $frame): bool =>
                $frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE));
            self::assertCount(2, $pages);
            foreach ($pages as $page) {
                $reply = $page->data->data;
                self::assertNull($reply->pageKey === HilosPageConstants::HILOS_STATUS_WORKS
                    ? $reply->payload->data['statusWorks'] : $reply->payload->data['status']['scheduledWorks']);
            }
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::statusWorks);
            self::assertNull(StatusWorksData::read(), 'The bridge must be ready too');
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::statusWorkComponents);
            self::assertSame(0, StatusWorksData::read()->upcomingCount);
            Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $pages = array_values(array_filter($this->env->drain(), static fn (SignalDTO $frame): bool =>
                $frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE));
            self::assertCount(2, $pages);
            foreach ($pages as $page) {
                $reply = $page->data->data;
                self::assertSame(0, $reply->pageKey === HilosPageConstants::HILOS_STATUS_WORKS
                    ? $reply->payload->data['statusWorks']['upcomingCount'] : $reply->payload->data['status']['scheduledWorks']['count']);
            }
            self::assertSame($held, Hilos::$sr->getTableViewport('ak-status', HilosStatusWorksTable::TABLE));
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
            $this->env->worker->pageInterests(HilosPageConstants::HILOS_STATUS_WORKS, 'ak-status');
            $this->env->worker->pageInterests(HilosPageConstants::HILOS_STATUS, 'ak-overview');
        }
        $this->env->asOwner(function (): void {
            Database::transactionStart();
            try {
                $now = TimeHelper::nowMs();
                Hilos::$db->statusWorks->actions->create('Rolled back', null, $now + 10000, $now + 20000, $now, ['mail']);
            } finally {
                Database::transactionRollback();
            }
        });
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertSame([], $this->env->drain());
        self::assertSame(0, StatusWorksData::read()->upcomingCount);
    }

    /** @return array<string, mixed> Whole-second future form values */
    private function payload(): array
    {
        $start = (intdiv(TimeHelper::nowMs(), 1000) + 60) * 1000;
        return ['title' => '  Upgrade  ', 'description' => ' Plain description ', 'componentKeys' => ['service'],
            'startsAt' => $start, 'endsAt' => $start + 60000];
    }

    /** @return array<string, mixed> Current persisted row independently of the ORM */
    private function row(): array
    {
        return Database::sql('SELECT * FROM hilos_status_work ORDER BY id')->rows()[0];
    }

    /** @return array<string, mixed> Persisted row coordinates held by an edit form */
    private function target(): array
    {
        $row = $this->row();
        return ['workId' => (int)$row['id'], 'publicId' => $row['public_id'], 'expectedVersion' => (int)$row['version']];
    }

    /** @param HandoverAnswerSignalData $answer Expected committed success */
    private function success(HandoverAnswerSignalData $answer): void
    {
        self::assertNull($answer->error, $answer->errorDetail ?? $answer->error ?? 'Work refused');
    }

    /**
     * @param HandoverAnswerSignalData $answer Expected safe domain refusal
     * @param string $reason Readable reason fragment
     */
    private function refusal(HandoverAnswerSignalData $answer, string $reason): void
    {
        self::assertNotNull($answer->error);
        self::assertStringContainsString($reason, $answer->error);
        self::assertNull($answer->errorType);
    }
}
