<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Closure;
use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus;
use Demo\Chat\Runtime\State\Item\ChatContext;
use Demo\Chat\Runtime\State\Item\ChatUserState;
use Demo\Chat\Runtime\State\Item\Connection;
use Demo\Chat\Runtime\State\Item\SuperfanAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Runtime\View\DTO\ChatContextUpdateData;
use Generator;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Hilos\SuperfanRunStatus;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowBinding;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\State\Item\RtState;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/** Project codecs are prepared manually; the production applicator remains the legacy path. */
final class RtResumableConnectionTest extends IntegrationTestCase
{
    private ?SignalRouter $previousRouter = null;

    /** Establishes the queue this class observes, independently of other integration cases. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    /** Restores the router after the integration lifecycle has closed its handler. */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Hilos::$sr = $this->previousRouter;
        }
    }

    /** Connection hooks export captured backing; internal clones never share a writable binding. */
    public function testBindingConnectionDraftFreezeAndBaselineStayIndependent(): void
    {
        $arena = new RtRowArena();
        $live = Connection::create('binding-ak', 7, 'token', 31);
        $live->outboundModerationMessage = str_repeat('message-Ж', 400);
        $live->outboundModerationAttachments = ['second', 'first'];
        $live->markRtSyncBaseline();
        $baseline = $live->toArray();
        $draft = $live->draftRow();
        $draft->userId = 99;
        $draft->outboundModerationMessage = null;
        $this->assertSame($baseline, $live->toArray());
        $this->assertSame($live->freezeRow()->incarnation, $draft->freezeRow()->incarnation);
        $this->drainSignals();
        $invalid = RtRowValues::fromNative([
            Connection::userId => 12, Connection::outboundModerationAttachments => ['valid', 12],
        ], $arena);
        try {
            $this->complete($draft->rowCodec()->patch($draft, $invalid->reader()), function () use ($live, $baseline): void {
                $this->assertSame($baseline, $live->toArray());
                $this->assertNull(Hilos::$sr->getNextQueuedSignal());
            });
            $this->fail('An invalid project field must not install a base-stage prefix');
        } catch (InvalidFormatException) {
            $this->assertSame(99, $draft->userId);
            $this->assertNull($draft->outboundModerationMessage);
        }
        $accepted = RtRowValues::fromNative([
            Connection::userId => 12,
            Connection::outboundModerationMessage => str_repeat('new-message-', 400),
        ], $arena);
        $cancel = new RtWorkDriver($draft->rowCodec()->patch($draft, $accepted->reader()));
        $cancel->advance(new RtWorkBudget(100, 11));
        $this->assertFalse($cancel->isFinished());
        $cancel->cancel();
        $this->assertSame(99, $draft->userId);
        $this->assertSame($baseline, $live->toArray());

        $frozen = $live->freezeRow();
        $out = new RtRowWriter($arena);
        $export = $live->rowCodec()->export($live, $out);
        $driver = new RtWorkDriver($export);
        $driver->advance(new RtWorkBudget(1, 1));
        $live->applyDiff([Connection::sessionToken => 'changed-after-export-start']);
        $live->outboundModerationMessage = null;
        $live->outboundModerationAttachments = [];
        while (!$driver->isFinished()) {
            $budget = new RtWorkBudget(7, 29);
            $driver->advance($budget);
            $this->assertLessThanOrEqual(7, $budget->usedSteps());
            $this->assertLessThanOrEqual(29, $budget->usedBytes());
        }
        $this->assertSame($baseline, $this->complete($out->finish())->toNative());
        $baselineOut = new RtRowWriter($arena);
        $this->complete($frozen->exportBaseline($baselineOut));
        $this->assertSame($baseline, $this->complete($baselineOut->finish())->toNative());
        $changes = $this->complete($draft->freezeRow()->changes($arena));
        $this->assertSame([
            Connection::userId => 99, Connection::outboundModerationMessage => null,
        ], $changes->diff->toNative());
        $this->assertSame([
            Connection::userId => 7, Connection::outboundModerationMessage => $baseline[Connection::outboundModerationMessage],
        ], $changes->previous->toNative());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** Null, empty text, omitted fields and normalization retain their existing distinctions. */
    public function testBindingProjectPreviousKeepsDefaultsAndNormalization(): void
    {
        $arena = new RtRowArena();
        $context = ChatContext::create();
        $context->topic = '';
        $changes = $this->complete($context->freezeRow()->changes($arena));
        $this->assertSame([ChatContext::topic => ''], $changes->diff->toNative());
        $this->assertSame([ChatContext::topic => null], $changes->previous->toNative());
        $context->topic = null;
        $changes = $this->complete($context->freezeRow()->changes($arena));
        $this->assertSame([], $changes->diff->toNative());
        $bot = BotAgentStatus::create(7, BotAgentStatus::STATUS_JOINED);
        $patch = RtRowValues::fromNative([BotAgentStatus::status => 'unknown-status'], $arena);
        $this->complete($bot->rowCodec()->patch($bot, $patch->reader()));
        $changes = $this->complete($bot->freezeRow()->changes($arena));
        $this->assertSame([BotAgentStatus::status => BotAgentStatus::STATUS_LEFT], $changes->diff->toNative());
        $this->assertSame([BotAgentStatus::status => BotAgentStatus::STATUS_JOINED], $changes->previous->toNative());
    }

    public function testPortionedProjectValidationNormalizationAndRelease(): void
    {
        $long = str_repeat('project-value-', 1500);
        $arena = new RtRowArena();
        $connectionRow = array_replace(Connection::create('codec-ak', 7, 'token', 31)->toArray(), [
            Connection::acceptKey => $long,
            Connection::sessionToken => $long,
            Connection::outboundModerationPhase => $long,
            Connection::outboundModerationMessage => $long,
            Connection::outboundModerationReason => '',
            Connection::outboundModerationAttachments => ['second', $long, 'first'],
            Connection::outboundModerationPicture => null,
        ]);
        $cases = [
            [Connection::create('codec-ak', null), $connectionRow, $connectionRow,
                [Connection::userId => 99, Connection::outboundModerationAttachments => ['valid', 12]]],
            [ChatContext::create(),
                [ChatContext::topic => '', ChatContext::topicConfidence => 1, ChatContext::summary => $long],
                [ChatContext::topic => '', ChatContext::topicConfidence => 1.0, ChatContext::summary => $long],
                [ChatContext::topic => 'changed', ChatContext::topicConfidence => 'invalid']],
            [ChatUserState::createEmpty(7),
                [ChatUserState::userId => 7, ChatUserState::lastOutboundSubmittedAt => 1],
                [ChatUserState::userId => 7, ChatUserState::lastOutboundSubmittedAt => 1.0],
                [ChatUserState::lastOutboundSubmittedAt => null]],
            [BotAgentStatus::create(7, BotAgentStatus::STATUS_JOINED),
                [BotAgentStatus::botId => 7, BotAgentStatus::status => $long, BotAgentStatus::updatedAt => 12],
                [BotAgentStatus::botId => 7, BotAgentStatus::status => BotAgentStatus::STATUS_LEFT, BotAgentStatus::updatedAt => 12],
                [BotAgentStatus::status => BotAgentStatus::STATUS_JOINED, BotAgentStatus::updatedAt => null]],
            [SuperfanAgentStatus::create('superfan', SuperfanRunStatus::NOT_STARTED),
                [SuperfanAgentStatus::agentId => $long, SuperfanAgentStatus::status => $long, SuperfanAgentStatus::updatedAt => 12],
                [SuperfanAgentStatus::agentId => $long,
                    SuperfanAgentStatus::status => SuperfanRunStatus::NOT_STARTED->value, SuperfanAgentStatus::updatedAt => 12],
                [SuperfanAgentStatus::status => SuperfanRunStatus::NOT_STARTED->value, SuperfanAgentStatus::updatedAt => null]],
        ];
        $this->drainSignals();

        foreach ($cases as [$prototype, $row, $expected, $invalidPatch]) {
            $codec = $prototype->rowCodec();
            $input = RtRowValues::fromNative($row, $arena);
            $state = $this->complete($codec->restore($input->reader()), function (): void {
                $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'Preparation must not announce a fact');
            });
            $this->assertSame($expected, $state->toArray());
            $this->assertSame([], new ReflectionProperty(RtState::class, 'rtSyncBaseline')->getValue($state));

            $out = new RtRowWriter($arena);
            $this->complete($codec->export($state, $out));
            $export = $this->complete($out->finish());
            $this->assertSame($expected, $export->toNative());
            $binding = new ReflectionProperty(RtState::class, 'rowBinding')->getValue($state);
            $this->assertInstanceOf(RtRowBinding::class, $binding);
            $descriptor = $binding->current()->fields;
            foreach ($expected as $key => $value) {
                if (is_string($value) || is_array($value)) {
                    $this->assertInstanceOf(RtRowValues::class, $descriptor[$key]);
                }
            }
            unset($descriptor, $binding);

            $invalid = RtRowValues::fromNative($invalidPatch, $arena);
            try {
                $this->complete($codec->patch($state, $invalid->reader()), function () use ($state, $expected): void {
                    $this->assertSame($expected, $state->toArray(), 'Every suspended patch leaves the draft unchanged');
                });
                $this->fail('The project patch must refuse the wrong type');
            } catch (InvalidFormatException) {
                $this->assertSame($expected, $state->toArray());
            }
            try {
                $broken = RtRowValues::fromNative(array_replace($row, $invalidPatch), $arena);
                $this->complete($codec->restore($broken->reader()));
                $this->fail('The project restore must refuse the wrong type');
            } catch (InvalidFormatException) {
                $this->assertNull(Hilos::$sr->getNextQueuedSignal());
            }

            if ($state instanceof Connection) {
                $accepted = RtRowValues::fromNative([
                    Connection::acceptKey => 'ignored',
                    Connection::userId => null,
                    Connection::connectedAt => 0,
                    Connection::probablyOffline => true,
                    Connection::sessionToken => $long . 'rotated',
                    Connection::sessionId => 99,
                ], $arena);
                $this->complete($codec->patch($state, $accepted->reader()));
                $this->assertSame($long, $state->acceptKey);
                $this->assertNull($state->userId);
                $this->assertSame(0, $state->connectedAt);
                $this->assertTrue($state->probablyOffline);
                $this->assertSame($long . 'rotated', $state->sessionToken);
                $this->assertSame(99, $state->sessionId);
                $beforeCancel = $state->toArray();
                $accepted = RtRowValues::fromNative([Connection::outboundModerationMessage => $long . 'canceled'], $arena);
                $canceled = new RtWorkDriver($codec->patch($state, $accepted->reader()));
                $budget = new RtWorkBudget(1000, 1024);
                $canceled->advance($budget);
                $this->assertLessThanOrEqual(1000, $budget->usedSteps());
                $this->assertLessThanOrEqual(1024, $budget->usedBytes());
                $this->assertFalse($canceled->isFinished());
                $canceled->cancel();
                $this->assertSame($beforeCancel, $state->toArray());
            } elseif ($state instanceof ChatContext) {
                $accepted = RtRowValues::fromNative([
                    ChatContext::topic => null, ChatContext::topicConfidence => 0, ChatContext::summary => '',
                ], $arena);
                $this->complete($codec->patch($state, $accepted->reader()));
                $this->assertSame([ChatContext::topic => null, ChatContext::topicConfidence => 0.0, ChatContext::summary => ''], $state->toArray());
            } elseif ($state instanceof ChatUserState) {
                $accepted = RtRowValues::fromNative([
                    ChatUserState::userId => null, ChatUserState::lastOutboundSubmittedAt => 2,
                ], $arena);
                $this->complete($codec->patch($state, $accepted->reader()));
                $this->assertSame(7, $state->userId);
                $this->assertSame(2.0, $state->lastOutboundSubmittedAt);
            } elseif ($state instanceof BotAgentStatus) {
                $accepted = RtRowValues::fromNative([BotAgentStatus::status => BotAgentStatus::STATUS_JOINED], $arena);
                $this->complete($codec->patch($state, $accepted->reader()));
                $this->assertSame(BotAgentStatus::STATUS_JOINED, $state->status);
                $state->status = $long;
                $accepted = RtRowValues::fromNative([BotAgentStatus::updatedAt => 13], $arena);
                $this->complete($codec->patch($state, $accepted->reader()));
                $this->assertSame(BotAgentStatus::STATUS_LEFT, $state->status);
            } elseif ($state instanceof SuperfanAgentStatus) {
                foreach (SuperfanRunStatus::cases() as $status) {
                    $accepted = RtRowValues::fromNative([SuperfanAgentStatus::status => $status->value], $arena);
                    $this->complete($codec->patch($state, $accepted->reader()));
                    $this->assertSame($status->value, $state->status);
                }
                $state->status = $long;
                $accepted = RtRowValues::fromNative([SuperfanAgentStatus::updatedAt => 13], $arena);
                $this->complete($codec->patch($state, $accepted->reader()));
                $this->assertSame(SuperfanRunStatus::NOT_STARTED->value, $state->status);
            }
        }

        unset($input, $invalid, $broken, $accepted, $canceled, $export, $out, $state);
        $this->assertGreaterThan(0, $arena->retention()->pendingReleases);
        $this->assertGreaterThan(0, $arena->retention()->physicalBytes);
        $pass = 0;
        while ($arena->retention()->pendingReleases > 0) {
            $steps = 3 + $pass % 7;
            $bytes = 31 + $pass++ % 29;
            $budget = new RtWorkBudget($steps, $bytes);
            $arena->advanceRetirement($budget);
            $this->assertLessThanOrEqual($steps, $budget->usedSteps());
            $this->assertLessThanOrEqual($bytes, $budget->usedBytes());
        }
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    public function testNullEmptyOmittedAndAcceptedProjectReactions(): void
    {
        $arena = new RtRowArena();
        $row = Connection::create('resumable-ak', null, 'token', 31)->toArray();
        $input = RtRowValues::fromNative($row, $arena);
        $codec = Connection::create('prototype', null)->rowCodec();
        $draft = $this->complete($codec->restore($input->reader()));
        $patch = RtRowValues::fromNative([
            Connection::acceptKey => 'ignored',
            Connection::outboundModerationMessage => '',
            Connection::outboundModerationReason => '',
            Connection::outboundModerationPicture => '',
            Connection::outboundModerationAttachments => ['u2', 'u1'],
        ], $arena);
        $this->complete($codec->patch($draft, $patch->reader()), function () use ($draft, $row): void {
            $this->assertSame($row, $draft->toArray());
        });
        $this->assertSame('resumable-ak', $draft->acceptKey);
        $this->assertSame('', $draft->outboundModerationMessage);
        $this->assertSame('', $draft->outboundModerationReason);
        $this->assertSame('', $draft->outboundModerationPicture);
        $this->assertSame(['u2', 'u1'], $draft->outboundModerationAttachments);
        $patch = RtRowValues::fromNative([Connection::userId => 12], $arena);
        $this->complete($codec->patch($draft, $patch->reader()));
        $this->assertSame('', $draft->outboundModerationMessage);
        $this->assertSame('', $draft->outboundModerationReason);
        $this->assertSame('', $draft->outboundModerationPicture);
        $this->assertSame(['u2', 'u1'], $draft->outboundModerationAttachments);
        $patch = RtRowValues::fromNative([
            Connection::outboundModerationMessage => null,
            Connection::outboundModerationReason => null,
            Connection::outboundModerationPicture => null,
            Connection::outboundModerationAttachments => [],
        ], $arena);
        $this->complete($codec->patch($draft, $patch->reader()));
        $this->assertNull($draft->outboundModerationMessage);
        $this->assertNull($draft->outboundModerationReason);
        $this->assertNull($draft->outboundModerationPicture);
        $this->assertSame([], $draft->outboundModerationAttachments);

        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        RtTruthSourceRegistry::register(ChatRtContext::chatContext, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->register('resumable-ak', null, 'token', 31);
        try {
            ExecutionContext::run(new ExecutionFrame(acceptKey: 'resumable-ak'), function (): void {
                Hilos::$rt->selfConnection->actions->startOutboundModeration('', ['u2', 'u1']);
                $this->assertSame('', Hilos::$rt->connections['resumable-ak']->outboundModerationMessage);
                $this->assertSame(['u2', 'u1'], Hilos::$rt->selfConnection->outboundModerationAttachments);
                $this->assertSame(31, Hilos::$rt->selfConnection->sessionId);
                $this->assertSame('token', Hilos::$rt->selfConnection->sessionToken);
                Hilos::$rt->selfConnection->actions->failOutboundModeration(
                    ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED, '', '',
                );
                $this->assertSame('', Hilos::$rt->connections['resumable-ak']->outboundModerationReason);
                $this->assertSame('', Hilos::$rt->connections['resumable-ak']->outboundModerationPicture);
                $this->assertSame('', Hilos::$rt->connections['resumable-ak']->outboundModerationMessage, 'An omitted patch key keeps its value');
                $this->assertSame(['u2', 'u1'], Hilos::$rt->selfConnection->outboundModerationAttachments);
                Hilos::$rt->selfConnection->actions->clearOutboundModeration();
                $this->assertNull(Hilos::$rt->connections['resumable-ak']->outboundModerationMessage);
                $this->assertSame([], Hilos::$rt->selfConnection->outboundModerationAttachments);
            });
            $this->assertNull(Hilos::$rt->selfConnection);

            $botId = Hilos::$db->bots->actions->create('Resumable context consumer', active: true)->id;
            $createdBot = new BotAgent((string)$botId);
            $updatedBot = new BotAgent((string)$botId);
            $scheduled = new ReflectionProperty(BotAgent::class, 'scheduledReactAt');
            $contextCodec = ChatContext::create()->rowCodec();
            $input = RtRowValues::fromNative([
                ChatContext::topic => '', ChatContext::topicConfidence => 0.5, ChatContext::summary => '',
            ], $arena);
            $this->drainSignals();
            $context = $this->complete($contextCodec->restore($input->reader()), function () use ($scheduled, $createdBot): void {
                $this->assertSame(0.0, $scheduled->getValue($createdBot));
                $this->assertNull(Hilos::$sr->getNextQueuedSignal());
            });
            $this->assertSame('', $context->topic, 'The state factory does not normalize an empty topic');
            $created = new RtSyncCreatedSignalData(ChatRtContext::chatContext, ChatContext::ID_MAIN, $context->toArray());
            RtSyncApplicator::applyCreated($created, false);
            $createdBot->onSignalRtSyncCreated($created, SignalSource::RT, SignalConstants::RT_SYNC_CREATED);
            $this->assertGreaterThan(0.0, $scheduled->getValue($createdBot));
            $this->assertSame('', Hilos::$rt->chatContext->topic);

            $invalid = RtRowValues::fromNative([ChatContext::topic => 'unaccepted', ChatContext::topicConfidence => null], $arena);
            try {
                $this->complete($contextCodec->patch($context, $invalid->reader()));
                $this->fail('An invalid context must not be accepted');
            } catch (InvalidFormatException) {
                $this->assertSame('', Hilos::$rt->chatContext->topic);
                $this->assertSame(0.0, $scheduled->getValue($updatedBot));
                $this->assertNull(Hilos::$sr->getNextQueuedSignal());
            }

            $patch = RtRowValues::fromNative([ChatContext::summary => 'accepted'], $arena);
            $this->complete($contextCodec->patch($context, $patch->reader()));
            $updated = new RtSyncUpdatedSignalData(ChatRtContext::chatContext, ChatContext::ID_MAIN, $context->toArray());
            RtSyncApplicator::applyUpdated($updated, false);
            $updatedBot->onSignalRtSyncUpdated($updated, SignalSource::RT, SignalConstants::RT_SYNC_UPDATED);
            $this->assertGreaterThan(0.0, $scheduled->getValue($updatedBot));
            $this->assertSame('accepted', Hilos::$rt->chatContext->summary);
            Hilos::$rt->chatContext->actions->update(new ChatContextUpdateData('', 0.5, ''));
            $this->assertNull(Hilos::$rt->chatContext->topic, 'Only the action normalizes an empty topic');
        } finally {
            Hilos::$rt->connections['resumable-ak']->actions->unregister();
        }
    }

    /**
     * @param Generator $operation One codec operation
     * @param ?Closure $boundary Assertions at every suspended boundary
     * @return mixed Completed codec result
     */
    private function complete(Generator $operation, ?Closure $boundary = null): mixed
    {
        $driver = new RtWorkDriver($operation);
        $pass = 0;
        while (!$driver->isFinished()) {
            $steps = 3 + $pass % 7;
            $bytes = 31 + $pass++ % 29;
            $budget = new RtWorkBudget($steps, $bytes);
            $driver->advance($budget);
            $this->assertLessThanOrEqual($steps, $budget->usedSteps());
            $this->assertLessThanOrEqual($bytes, $budget->usedBytes());
            if (!$driver->isFinished()) {
                $boundary?->__invoke();
            }
        }

        return $driver->result();
    }
}
