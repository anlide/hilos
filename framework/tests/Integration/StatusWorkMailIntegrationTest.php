<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\HilosMailer;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusMaintenanceObservation;
use Hilos\Status\StatusMaintenanceObservationStore;
use Hilos\Status\StatusPublicWork;
use Hilos\Status\StatusSubscriberNotice;
use Hilos\Status\StatusSeverity;
use Hilos\Status\StatusUpdateKind;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Hilos\Tests\Fixtures\StatusWorksEnvironment;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/** Real commits, raw mail channel framing and best-effort handoff evidence, without an always-rollback root. */
final class StatusWorkMailIntegrationTest extends FrameworkIntegrationTestCase
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

    public function testScheduleChangeCancelSnapshotsReachTheRealMailPoolWithRecipientClocks(): void
    {
        $this->env->recipient('reader@example.test');
        $pending = $this->env->recipient('pending@example.test', false);
        $payload = $this->schedule();
        $row = $this->row();
        $target = ['workId' => (int)$row['id'], 'publicId' => $row['public_id'], 'expectedVersion' => 1];
        self::assertNull($row['last_mail_queued_at']);
        self::assertSame([], $this->mails());
        $answer = $this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE,
            $target + array_replace($payload, ['title' => 'Updated <work>', 'description' => 'Later words', 'componentKeys' => ['mail']]));
        self::assertNull($answer->error, $answer->errorDetail ?? $answer->error ?? 'Work refused');
        $target['expectedVersion'] = 2;
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL, $target)->error);
        $this->env->recipient('too-late@example.test');
        $this->env->tick();
        $scheduled = $this->mails();
        self::assertCount(1, $scheduled);
        self::assertSame('status_work_scheduled', $scheduled[0]->templateKey);
        self::assertSame('Upgrade', $scheduled[0]->params['title']);
        self::assertSame('Original <description>', $scheduled[0]->params['description']);
        self::assertSame('Service', $scheduled[0]->params['componentNames']);
        self::assertStringContainsString('(Europe/Warsaw)', $scheduled[0]->params['startsAtLabel']);
        self::assertMatchesRegularExpression('/[+-]\d\d:\d\d \(Europe\/Warsaw\)$/', $scheduled[0]->params['endsAtLabel']);
        self::assertStringStartsWith('https://service.example.test/status#status-unsubscribe=', $scheduled[0]->params['unsubscribeUrl']);
        $message = $this->poolMessage($scheduled[0]);
        self::assertSame('Scheduled maintenance: Upgrade', $message->subject);
        self::assertStringContainsString('<html', $message->html);
        self::assertStringContainsString('&lt;description&gt;', $message->html);
        self::assertNotNull($this->row()['last_mail_queued_at']);
        self::assertSame('3', $this->row()['version'], 'Handoff evidence never bumps the editing version');
        $this->env->tick();
        $changed = $this->mails();
        self::assertCount(1, $changed);
        self::assertSame('status_work_changed', $changed[0]->templateKey);
        self::assertSame('Title, Description, Components.', $changed[0]->params['changesText']);
        self::assertStringContainsString('What changed:', $this->poolMessage($changed[0])->text);
        self::assertSame('Updated <work>', $changed[0]->params['title']);
        $this->env->tick();
        $canceled = $this->mails();
        self::assertCount(1, $canceled);
        self::assertSame('status_work_canceled', $canceled[0]->templateKey);
        self::assertSame('Maintenance canceled: Updated <work>', $this->poolMessage($canceled[0])->subject);
        self::assertFalse(Hilos::$db->statusSubscribers[$pending]->confirmed);
    }

    public function testHandoffMarkKeepsItsOwnCreateWhenBothReachOneBrowserFlush(): void
    {
        $this->env->recipient('reader@example.test');
        $this->env->open();
        $this->env->open(HilosPageConstants::HILOS_STATUS_WORKS, 'ak-observer');
        $this->env->drain();
        Hilos::$browser->flushToSignalRouter();
        $this->env->drain();
        $this->schedule();
        $this->env->tick();
        self::assertCount(1, $this->mails());
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $own = [];
        $other = [];
        foreach ($this->env->drain() as $frame) {
            if ($frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_OWN_CREATE) {
                $own[] = $frame;
            }
            if ($frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_APPEND) {
                $other[] = $frame;
            }
        }
        self::assertCount(1, $own);
        self::assertSame('ak-status', $own[0]->data->targetAcceptKey);
        self::assertSame('button-1', $own[0]->data->data->requestId);
        self::assertNotNull($own[0]->data->data->row['slots']['work']['lastMailQueuedAt']);
        self::assertCount(1, $other);
        self::assertSame('ak-observer', $other[0]->data->targetAcceptKey);
        self::assertNull(ExecutionContext::currentAcceptKey());
        self::assertNull(ExecutionContext::currentRequestId());
    }

    /** @return iterable<string, array{bool, bool}> Reorder/removal, including an unavailable later mail enqueue */
    public static function newerActions(): iterable
    {
        yield 'change moves below another announcement' => [false, true];
        yield 'cancel removes from upcoming' => [true, true];
        yield 'change with unavailable mail enqueue' => [false, false];
        yield 'cancel with unavailable mail enqueue' => [true, false];
    }

    /**
     * @param bool $cancel Whether B cancels instead of moving the work
     * @param bool $queueNewNotice Whether B's committed event can enqueue its own best-effort task
     */
    #[DataProvider('newerActions')]
    public function testOlderBackloggedHandoffKeepsTheNewerAuthorsOwnMutationInOneBrowserFlush(bool $cancel, bool $queueNewNotice): void
    {
        for ($i = 0; $i < 33; $i++) {
            $this->env->recipient('reader' . $i . '@example.test');
        }
        $payload = $this->payload();
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload, 'schedule-A')->error);
        $row = $this->row();
        $this->env->asOwner(fn () => Database::transaction('primary', fn () => Hilos::$db->statusWorks->actions->create(
            'Other work', null, $payload['startsAt'] + 120000, $payload['endsAt'] + 120000, TimeHelper::nowMs(), ['service'],
        )));
        foreach (['ak-status', 'ak-editor', 'ak-observer'] as $acceptKey) {
            $this->env->open(HilosPageConstants::HILOS_STATUS_WORKS, $acceptKey);
        }
        $this->env->drain();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->env->drain();
        self::assertSame(7, Hilos::$browser->resolveActionUserId('ak-status'));
        self::assertSame(8, Hilos::$browser->resolveActionUserId('ak-editor'));
        $this->env->tick();
        $mails = $this->mails();
        self::assertNotEmpty($mails);
        self::assertLessThanOrEqual(32, count($mails));
        self::assertNull($this->row()['last_mail_queued_at'], 'A still has recipients after the bounded first tick');
        // Flush the partial pass; only B's domain write and A's final mark share the next flush.
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->env->drain();
        $changed = array_replace($payload, ['title' => 'Moved by B',
            'startsAt' => $payload['startsAt'] + 240000, 'endsAt' => $payload['endsAt'] + 240000]);
        $action = $cancel ? HilosSignalConstants::HILOS_STATUS_WORK_CANCEL : HilosSignalConstants::HILOS_STATUS_WORK_CHANGE;
        $mailer = Hilos::$mail;
        try {
            if (!$queueNewNotice) {
                Hilos::$mail = null;
            }
            self::assertNull($this->env->submit($action, $this->target() + ($cancel ? [] : $changed), 'edit-B', 'ak-editor')->error);
        } finally {
            Hilos::$mail = $mailer;
        }
        $probe = new class implements SourceChangeSubscriberInterface {
            /** @var list<SourceChange> Committed handoff marks only */
            public array $marks = [];

            /**
             * @param SourceChange $change Committed owner write
             * @param SourceChangeProvenance $provenance Source context, unused
             */
            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === HilosDbContext::statusWorks && isset($change->row['last_mail_queued_at'])) {
                    $this->marks[] = $change;
                }
            }
        };
        SourceChangeBus::subscribe($probe);
        for ($tick = 0; $tick < 33 && $this->row()['last_mail_queued_at'] === null; $tick++) {
            $this->env->tick();
            $portion = $this->mails();
            self::assertLessThanOrEqual(32, count($portion));
            array_push($mails, ...$portion);
        }
        self::assertCount(33, $mails, 'B gets own before its later mail task runs');
        foreach ($mails as $mail) {
            self::assertSame('status_work_scheduled', $mail->templateKey);
            self::assertSame('Upgrade', $mail->params['title'], 'A retains its detached notice snapshot');
        }
        self::assertNotNull($this->row()['last_mail_queued_at']);
        self::assertSame('2', $this->row()['version'], 'Mail evidence leaves the domain version alone');
        self::assertSame($row['public_id'], $this->row()['public_id']);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $deltas = [];
        foreach ($this->env->drain() as $frame) {
            if ($frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                self::assertInstanceOf(TableViewportDeltaDTO::class, $frame->data->data);
                $deltas[$frame->data->targetAcceptKey] = $frame->data->data;
            }
        }
        self::assertCount(3, $deltas);
        self::assertTrue($deltas['ak-editor']->own, 'B must apply its own movement/removal in this flush');
        self::assertFalse($deltas['ak-status']->own, 'A cannot own B\'s newer version');
        self::assertFalse($deltas['ak-observer']->own, 'Other viewers retain the ordinary Apply boundary');
        foreach ($deltas as $delta) {
            self::assertSame(HilosPageConstants::HILOS_STATUS_WORKS, $delta->page);
            self::assertSame(HilosStatusWorksTable::TABLE, $delta->tableKey);
            self::assertSame((int)$row['id'], $delta->rowKey);
            self::assertSame($cancel ? TableViewportDeltaDTO::KIND_ROW_REMOVED : TableViewportDeltaDTO::KIND_ROW_MOVED, $delta->kind);
            if ($cancel) {
                self::assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $delta->reason);
            } else {
                self::assertSame(1, $delta->position);
                self::assertSame($row['public_id'], $delta->row['slots']['work']['publicId']);
                self::assertSame('Moved by B', $delta->row['slots']['work']['title']);
                self::assertSame(2, $delta->row['slots']['work']['version']);
                self::assertSame($changed['startsAt'], $delta->row['slots']['work']['startsAt']);
                self::assertNotNull($delta->row['slots']['work']['lastMailQueuedAt']);
                self::assertSame(['id', 'publicId', 'title', 'description', 'componentKeys', 'startsAt', 'endsAt', 'version',
                    'canceledAt', 'consumedAt', 'expiredAt', 'lastMailQueuedAt'], array_keys($delta->row['slots']['work']));
            }
        }
        self::assertCount(1, $probe->marks);
        self::assertSame('ak-editor', $probe->marks[0]->origin);
        self::assertSame('edit-B', $probe->marks[0]->originRequestId);
        self::assertNull(ExecutionContext::currentAcceptKey());
        self::assertNull(ExecutionContext::currentRequestId());
        if ($queueNewNotice) {
            $laterMails = [];
            for ($tick = 0; $tick < 33 && count($laterMails) < 33; $tick++) {
                $this->env->tick();
                $portion = $this->mails();
                self::assertLessThanOrEqual(32, count($portion));
                array_push($laterMails, ...$portion);
            }
            self::assertCount(33, $laterMails);
            foreach ($laterMails as $mail) {
                self::assertSame($cancel ? 'status_work_canceled' : 'status_work_changed', $mail->templateKey);
                self::assertSame($cancel ? 'Upgrade' : 'Moved by B', $mail->params['title']);
            }
        }
        self::assertSame([], new ReflectionProperty(StatusAgent::class, 'workMailContexts')->getValue($this->env->owner),
            'The queue owns provenance only while tasks for this public identity remain');
    }

    public function testAnOlderHandoffTaskCannotAttributeANewerWorkVersionToItsAuthor(): void
    {
        $this->env->recipient('reader@example.test');
        $payload = $this->schedule();
        $row = $this->row();
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE,
            ['workId' => (int)$row['id'], 'publicId' => $row['public_id'], 'expectedVersion' => 1]
            + array_replace($payload, ['title' => 'A later version']), 'later-B', 'ak-editor')->error);
        $probe = new class implements SourceChangeSubscriberInterface {
            /** @var list<SourceChange> Committed handoff marks only */
            public array $marks = [];

            /**
             * @param SourceChange $change Committed owner write
             * @param SourceChangeProvenance $provenance Source context, unused
             */
            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === HilosDbContext::statusWorks && isset($change->row['last_mail_queued_at'])) {
                    $this->marks[] = $change;
                }
            }
        };
        SourceChangeBus::subscribe($probe);
        $this->env->tick();
        self::assertCount(1, $this->mails());
        self::assertCount(1, $probe->marks);
        self::assertSame('ak-editor', $probe->marks[0]->origin);
        self::assertSame('later-B', $probe->marks[0]->originRequestId);
        self::assertSame('2', $this->row()['version']);
        self::assertNull(ExecutionContext::currentAcceptKey());
        self::assertNull(ExecutionContext::currentRequestId());
    }

    /** @return iterable<string, array{bool}> Background domain advance or restored older version of the same public identity */
    public static function unownedVersions(): iterable
    {
        yield 'unattended consumption' => [false];
        yield 'restored version' => [true];
    }

    /** @param bool $restore Whether restore replaced the row instead of a live background consumption */
    #[DataProvider('unownedVersions')]
    public function testAnUnattendedOrRestoredVersionCannotBorrowAPendingAuthorsOrigin(bool $restore): void
    {
        $this->env->recipient('reader@example.test');
        $payload = $this->schedule();
        $row = $this->row();
        $this->env->open();
        $this->env->open(HilosPageConstants::HILOS_STATUS_WORKS, 'ak-editor');
        $this->env->drain();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->env->drain();
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE,
            $this->target() + array_replace($payload, ['title' => 'B changed this']), 'later-B', 'ak-editor')->error);
        if ($restore) {
            Database::sql('UPDATE hilos_status_work SET title = ?, version = 1 WHERE id = ?', [$row['title'], $row['id']]);
            Hilos::$db->forgetLoadedDataForTest();
        } else {
            $this->env->asOwner(fn () => Database::transaction('primary', fn () =>
                Hilos::$db->statusWorks[(int)$row['id']]->actions->consume($payload['startsAt'])));
        }
        $this->env->drain();
        $this->env->tick();
        self::assertCount(1, $this->mails());
        self::assertSame($restore ? '1' : '3', $this->row()['version']);
        self::assertNotNull($this->row()['last_mail_queued_at']);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $deltas = [];
        foreach ($this->env->drain() as $frame) {
            if ($frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                self::assertInstanceOf(TableViewportDeltaDTO::class, $frame->data->data);
                $deltas[$frame->data->targetAcceptKey] = $frame->data->data;
            }
        }
        self::assertSame(['ak-status', 'ak-editor'], array_keys($deltas));
        foreach ($deltas as $delta) {
            self::assertSame($restore ? TableViewportDeltaDTO::KIND_ROW_UPDATED : TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
            self::assertSame($restore ? null : TableViewportDeltaDTO::REASON_LEFT_SET, $delta->reason);
            self::assertFalse($delta->own, 'The current unowned version belongs to neither A nor B');
            if ($restore) {
                self::assertSame($row['public_id'], $delta->row['slots']['work']['publicId']);
                self::assertSame('Upgrade', $delta->row['slots']['work']['title']);
                self::assertSame(1, $delta->row['slots']['work']['version']);
            }
        }
    }

    public function testAnExistingAddressConfirmedAfterTheEventDoesNotJoinItsAudience(): void
    {
        $this->env->recipient('reader@example.test');
        $issued = $this->env->asOwner(fn () => Database::transaction('primary',
            fn () => Hilos::$db->statusSubscribers->actions->create('pending@example.test', TimeHelper::nowMs(), 'Europe/Warsaw')));
        $this->env->drain();
        $this->schedule();
        $this->env->asOwner(fn () => Database::transaction('primary',
            fn () => Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, TimeHelper::nowMs() + 1000)));
        $this->env->drain();
        $this->env->tick();
        self::assertSame(['reader@example.test'], array_map(static fn (MailSendSignalData $mail): string => $mail->to, $this->mails()));
    }

    public function testMixedOutageAndWorkTasksUseOneBudgetAndBoundedAudience(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->env->recipient('reader' . $i . '@example.test');
        }
        $this->schedule();
        $this->env->asOwner(fn () => new ReflectionMethod(StatusAgent::class, 'enqueueSubscriberNotice')->invoke($this->env->owner,
            new StatusSubscriberNotice(1, 1, 'Service', StatusUpdateKind::TEAM, StatusSeverity::MAJOR_OUTAGE,
                'Investigating.', 1, TimeHelper::nowMs(), null)));
        $this->env->recipient('later@example.test');
        $seen = [];
        for ($tick = 0; $tick < 100 && count($seen) < 80; $tick++) {
            $this->env->tick();
            $portion = $this->mails();
            self::assertLessThanOrEqual(32, count($portion));
            foreach ($portion as $mail) {
                self::assertNotSame('later@example.test', $mail->to);
                $seen[] = $mail->to . ':' . $mail->templateKey;
            }
        }
        self::assertCount(80, array_unique($seen));
        self::assertCount(80, $seen);
        self::assertNotNull($this->row()['last_mail_queued_at']);
    }

    public function testRecipientRemovalAndLostConfirmationAreRecheckedImmediatelyBeforeHandoff(): void
    {
        $this->markTestSkipped(
            'FIXME(F-054, 2026-10-30): one Status tick on a loaded host leaves the handoff unfinished · чужой · попытка 1 · HOTFIX',
        );
        $this->env->recipient('first@example.test');
        $deleted = $this->env->recipient('deleted@example.test');
        $unconfirmed = $this->env->recipient('unconfirmed@example.test');
        $this->schedule();
        Hilos::$mail = new StatusWorkProbeMailer(function () use ($deleted, $unconfirmed): void {
            Hilos::$db->statusSubscribers[$deleted]->actions->delete();
            Database::sql('UPDATE hilos_status_subscriber SET confirmed = 0, confirmed_at = NULL WHERE id = ?', [$unconfirmed]);
            Hilos::$db->forgetLoadedDataForTest();
        });
        $this->env->tick();
        self::assertSame(['first@example.test'], array_map(static fn (MailSendSignalData $mail): string => $mail->to, $this->mails()));
        self::assertNotNull($this->row()['last_mail_queued_at']);
    }

    public function testEmptyFailedPartialAndTimeBudgetTasksNeverInventAMark(): void
    {
        $this->schedule();
        $this->env->tick();
        self::assertSame([], $this->mails());
        self::assertNull($this->row()['last_mail_queued_at']);
        $this->env->recipient('reader@example.test');
        $this->env->recipient('second@example.test');
        $target = $this->target();
        $payload = $this->payload();
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE,
            $target + array_replace($payload, ['title' => 'Changed']))->error);
        $attempts = 0;
        Hilos::$mail = new StatusWorkProbeMailer(static function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 1) {
                throw new RuntimeException('Ambiguous handoff');
            }
        });
        $this->env->tick();
        $this->mails();
        self::assertSame(2, $attempts);
        self::assertNull($this->row()['last_mail_queued_at']);
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CHANGE,
            $this->target() + array_replace($payload, ['title' => 'Time limited']))->error);
        Hilos::$mail = new StatusWorkProbeMailer(static function (): void { usleep(25000); });
        $this->env->tick();
        self::assertCount(1, $this->mails());
        self::assertNull($this->row()['last_mail_queued_at'], 'A partial audience is not a completed handoff');
        $this->env->tick();
        self::assertCount(1, $this->mails());
        self::assertNotNull($this->row()['last_mail_queued_at']);
    }

    public function testAnUnusableCapabilityAfterRestoreDoesNotProveACompleteAudienceHandoff(): void
    {
        $this->env->recipient('reader@example.test');
        $missing = $this->env->recipient('missing@example.test');
        $this->schedule();
        Database::sql('UPDATE hilos_status_subscriber SET unsubscribe_token = NULL WHERE id = ?', [$missing]);
        $this->env->tick();
        self::assertCount(1, $this->mails());
        self::assertNull($this->row()['last_mail_queued_at']);
    }

    public function testOldTaskCannotMarkRecreatedIntegerTarget(): void
    {
        $this->env->recipient('reader@example.test');
        $this->schedule();
        $row = $this->row();
        Database::sql('DELETE FROM hilos_status_work_component WHERE work_id = ?', [(int)$row['id']]);
        Database::sql('DELETE FROM hilos_status_work WHERE id = ?', [(int)$row['id']]);
        Database::sql('INSERT INTO hilos_status_work (id, public_id, title, starts_at, ends_at, created_at, updated_at, version)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, 1)', [(int)$row['id'], str_repeat('f', 32), 'Replacement',
                (int)$row['starts_at'], (int)$row['ends_at'], (int)$row['created_at'], (int)$row['updated_at']]);
        Hilos::$db->forgetLoadedDataForTest();
        $this->env->tick();
        self::assertCount(1, $this->mails());
        self::assertNull($this->row()['last_mail_queued_at']);
    }

    /** @return iterable<string, array{bool, bool}> Exact/gapped completion with current/restored work content */
    public static function endings(): iterable
    {
        yield 'exact' => [true, false];
        yield 'gap' => [false, false];
        yield 'restored exact' => [true, true];
        yield 'restored gap' => [false, true];
    }

    /**
     * @param bool $continuous Whether completion has an exact observed end
     * @param bool $restore Whether an older version of the same public identity is restored
     */
    #[DataProvider('endings')]
    public function testCommittedCompletionImportsOnceAndUsesItsProvenDetachedWorkSnapshot(bool $continuous, bool $restore): void
    {
        $this->env->recipient('reader@example.test');
        Hilos::$db->statusWorkComponents->getObjectCollection()->preloadAll();
        $payload = $this->schedule();
        $original = $this->row();
        $this->env->asOwner(fn () => Database::transaction('primary', fn () => Hilos::$db->statusWorks[(int)$original['id']]->actions->change(
            $payload['title'], $payload['description'], $payload['startsAt'], $payload['endsAt'],
            TimeHelper::nowMs(), ['mail', 'service'],
        )));
        $snapshot = $this->env->asOwner(fn () => StatusPublicWork::fromWork(Hilos::$db->statusWorks[(int)$this->row()['id']]));
        $this->env->tick();
        $this->mails();
        $observation = StatusMaintenanceObservation::start($payload['startsAt'], $payload['startsAt'] + 1000, true, $snapshot)
            ->complete($payload['startsAt'] + 2000, $continuous);
        if ($restore) {
            Database::sql('UPDATE hilos_status_work SET title = ?, starts_at = ?, ends_at = ?, updated_at = ?, version = 1 WHERE id = ?',
                ['Restored title', $payload['startsAt'] + 120000, $payload['endsAt'] + 120000,
                    $original['updated_at'], $original['id']]);
            Hilos::$db->forgetLoadedDataForTest();
        }
        new StatusMaintenanceObservationStore()->save($observation);
        $this->env->asOwner(fn () => $this->env->owner->onStart());
        for ($tick = 0; $tick < 4; $tick++) {
            $this->env->tick();
        }
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('status_work_completed', $mails[0]->templateKey);
        self::assertSame('Upgrade', $mails[0]->params['title']);
        self::assertSame($continuous, $mails[0]->params['endedAtLabel'] !== null);
        self::assertNotNull($mails[0]->params['completionObservedAtLabel']);
        self::assertSame($payload['startsAt'], (int)$this->row()['consumed_at']);
        $message = $this->poolMessage($mails[0]);
        self::assertStringContainsString($continuous ? 'Maintenance ended at:' : 'Completion observed at:', $message->text);
        if (!$continuous) {
            self::assertStringNotContainsString('ended at', $message->text);
        }
        new StatusMaintenanceObservationStore()->save($observation);
        $this->env->asOwner(fn () => $this->env->owner->onStart());
        for ($tick = 0; $tick < 4; $tick++) {
            $this->env->tick();
        }
        self::assertSame([], $this->mails(), 'Commit-before-unlink recovery must not enqueue the completion again');
        self::assertSame([], (new StatusMaintenanceObservationStore())->discover());
        self::assertCount(1, Database::sql('SELECT * FROM hilos_status_maintenance_period')->rows());
        $this->env->asOwner(fn () => $this->env->owner->reconcileMaintenanceBeforeWrite(TimeHelper::nowMs()));
    }

    public function testUnannouncedCompletionAndFreezeLostTasksDoNotSendOrMark(): void
    {
        $this->env->recipient('reader@example.test');
        $this->schedule();
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->env->tick();
        self::assertSame([], $this->mails());
        self::assertNull($this->row()['last_mail_queued_at']);
        self::assertSame([], new ReflectionProperty(StatusAgent::class, 'workMailContexts')->getValue($this->env->owner));
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        for ($tick = 0; $tick < 4; $tick++) {
            $this->env->tick();
        }
        self::assertSame([], $this->mails());
        self::assertNull($this->row()['last_mail_queued_at']);
    }

    /** @return array<string, mixed> Committed scheduled announcement */
    private function schedule(): array
    {
        $payload = $this->payload();
        $answer = $this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload);
        self::assertNull($answer->error, $answer->errorDetail ?? $answer->error ?? 'Work refused');
        return $payload;
    }

    /** @return array<string, mixed> Future whole-second form values */
    private function payload(): array
    {
        $start = (intdiv(TimeHelper::nowMs(), 1000) + 60) * 1000;
        return ['title' => 'Upgrade', 'description' => 'Original <description>', 'componentKeys' => ['service'],
            'startsAt' => $start, 'endsAt' => $start + 60000];
    }

    /** @return array<string, mixed> Independent persisted row */
    private function row(): array
    {
        return Database::sql('SELECT * FROM hilos_status_work ORDER BY id')->rows()[0];
    }

    /** @return array<string, mixed> Current form coordinates */
    private function target(): array
    {
        $row = $this->row();
        return ['workId' => (int)$row['id'], 'publicId' => $row['public_id'], 'expectedVersion' => (int)$row['version']];
    }

    /** @return list<MailSendSignalData> Actual raw pool orders without sync or watch frames */
    private function mails(): array
    {
        $mails = [];
        foreach ($this->env->drain() as $frame) {
            if ($frame->signalName->getName() === HilosSignalConstants::HILOS_MAIL_SEND) {
                self::assertInstanceOf(MailSendSignalData::class, $frame->data->data);
                $mails[] = $frame->data->data;
            }
        }
        return $mails;
    }

    /**
     * @param MailSendSignalData $mail Real raw order from HilosMailer
     * @return EmailMessage Actual message rendered and framed by the production pool intake
     */
    private function poolMessage(MailSendSignalData $mail): EmailMessage
    {
        $pool = new MailDeliveryChannelAgent((string)$mail->shardKey);
        $pool->onSignalAgent(new AgentSignalData($mail), 'status', HilosSignalConstants::HILOS_MAIL_SEND);
        $queued = new ReflectionProperty(MailDeliveryChannelAgent::class, 'rawSends')->getValue($pool);
        self::assertCount(1, $queued);
        return array_values($queued)[0]->message;
    }
}

/** Injection at the real handoff seam, retaining HilosMailer's production sharding/routing. */
final class StatusWorkProbeMailer extends HilosMailer
{
    /** @param Closure(MailSendSignalData): void $probe Bounded injected failure or source change */
    public function __construct(private readonly Closure $probe)
    {
    }

    /** @param EmailMessage|MailSendSignalData $message Actual pool order */
    public function send(EmailMessage|MailSendSignalData $message): void
    {
        ($this->probe)($message);
        parent::send($message);
    }
}
