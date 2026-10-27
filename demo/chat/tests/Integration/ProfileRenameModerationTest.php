<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationKindKey;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Demo\Chat\Database\Entity\Item\UserRename as EntityUserRename;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for user-initiated rename moderation.
 */
final class ProfileRenameModerationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** Admission opens the correlated check before any name or feed write. */
    public function testRenameActionStartsModerationWithoutChangingUserName(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            $oldName = $user->name;
            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->beginProfileNameCheck((int)$user->id, 'rename-start-ak', 'Alice');

            $this->assertSame($oldName, Hilos::$db->users[$user->id]->name);
            $check = Hilos::$rt->hilosModerationChecks->find('rename-start-ak', ModerationKindKey::PROFILE_NAME);
            $this->assertNotNull($check);
            $this->assertSame('Alice', $check->text);
            $this->assertSame($user->id, $check->userId);
            $this->assertSame(HilosAgentType::HILOS_USERS_LIBRARY, $check->verdictTo);
            $this->assertSame(0, $this->deliverPersonAgentFrames());
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    /** Approval reaches the person owner and the terminal session snapshot. */
    public function testApprovedRenameModerationResultRenamesUser(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            $oldName = $user->name;
            Hilos::initSignalRouter(new ChatSignalRouter());
            $check = $this->beginProfileNameCheck((int)$user->id, 'rename-approve-ak', 'Alice');
            $this->dispatchRenameModerationVerdict(
                new ModerationVerdictSignalData(
                    acceptKey: 'rename-approve-ak',
                    kind: ModerationKindKey::PROFILE_NAME,
                    requestId: $check->requestId,
                    allow: true,
                    reason: 'ok',
                ),
            );

            $this->assertSame('Alice', Hilos::$db->users[$user->id]->name);
            $this->assertProfileNamePhase('rename-approve-ak', StateHilosProfileNameChange::PHASE_DONE);
            $this->assertNull(Hilos::$rt->hilosModerationChecks->find('rename-approve-ak', ModerationKindKey::PROFILE_NAME));
            $this->assertUserRenamedEventExists($user->id, $oldName, 'Alice');

            // Success is state-driven: the renamed user fans out over the
            // self-connection data, so no explicit success ack is queued.
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    /** A person merged after admission is refused before an owner hop. */
    public function testApprovedRenameOfAMergedAccountIsRefusedWithoutAHop(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $survivor = Hilos::$db->users->actions->createWithName('Survivor');
            $loser = Hilos::$db->users->actions->createWithName('Loser');
            Hilos::initSignalRouter(new ChatSignalRouter());
            $check = $this->beginProfileNameCheck((int)$loser->id, 'merged-rename-ak', 'Alice');
            $eventsBefore = count(Hilos::$db->events);
            Hilos::$db->userMerges->actions->add((int)$loser->id, (int)$survivor->id);

            $agentSignalData = new AgentSignalData(new ModerationVerdictSignalData(
                acceptKey: 'merged-rename-ak',
                kind: ModerationKindKey::PROFILE_NAME,
                requestId: $check->requestId,
                allow: true,
                reason: 'ok',
            ));
            ExecutionContext::setCurrentAcceptKey('merged-rename-ak');
            try {
                $library = $this->usersLibrary();
                $this->underAgent($library, static fn () => $library->onSignalAgent(
                    $agentSignalData, '', HilosSignalConstants::HILOS_MODERATION_VERDICT,
                ));
                $carried = $this->deliverPersonAgentFrames();
            } finally {
                ExecutionContext::setCurrentAcceptKey(null);
            }

            $this->assertSame(0, $carried);
            $this->assertSame('Loser', Hilos::$db->users[$loser->id]->name);
            $this->assertSame('Survivor', Hilos::$db->users[$survivor->id]->name);
            $this->assertCount(0, EntityUserRename::get([EntityUserRename::user_id => $loser->id]));
            $this->assertCount($eventsBefore, Hilos::$db->events);
            $this->assertNull(Hilos::$rt->hilosModerationChecks->find('merged-rename-ak', ModerationKindKey::PROFILE_NAME));
            $this->assertProfileNamePhase('merged-rename-ak', StateHilosProfileNameChange::PHASE_REFUSED);
            $errorSignal = $this->takeQueuedWebSocketSignal(SignalConstants::ACTION_ERROR);
            $this->assertNotNull($errorSignal);
            $this->assertInstanceOf(PageActionErrorSignalData::class, $errorSignal->data);
            $this->assertSame(HilosSignalConstants::PROFILE_NAME_SET, $errorSignal->data->action);
            $this->assertSame(
                'Failed to update user: ' . AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE,
                $errorSignal->data->reason,
            );
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
        }
    }

    /** A content refusal keeps the name and reports the exact reason to the origin. */
    public function testRejectedRenameModerationResultPreservesNameAndReportsActionError(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            $oldName = $user->name;
            Hilos::initSignalRouter(new ChatSignalRouter());
            $check = $this->beginProfileNameCheck((int)$user->id, 'rename-reject-ak', 'BlockedName');
            $this->dispatchRenameModerationVerdict(
                new ModerationVerdictSignalData(
                    acceptKey: 'rename-reject-ak',
                    kind: ModerationKindKey::PROFILE_NAME,
                    requestId: $check->requestId,
                    allow: false,
                    reason: 'spam',
                ),
            );

            $this->assertSame($oldName, Hilos::$db->users[$user->id]->name);
            $this->assertNull(Hilos::$rt->hilosModerationChecks->find('rename-reject-ak', ModerationKindKey::PROFILE_NAME));
            $this->assertProfileNamePhase('rename-reject-ak', StateHilosProfileNameChange::PHASE_REFUSED);
            $this->assertCount(0, EntityUserRename::get([EntityUserRename::user_id => $user->id]));
            $this->assertCount(0, Hilos::$db->events);

            // The reject is routed back through the framework action_error
            // contract (PageSignalRouter → default onActionException), not a
            // bespoke fail signal.
            $errorSignal = $this->takeQueuedWebSocketSignal(SignalConstants::ACTION_ERROR);
            $this->assertNotNull($errorSignal);
            $this->assertSame('rename-reject-ak', $errorSignal->targetAcceptKey);
            $this->assertInstanceOf(PageActionErrorSignalData::class, $errorSignal->data);
            $this->assertSame(HilosSignalConstants::PROFILE_NAME_SET, $errorSignal->data->action);
            $this->assertSame('This name was not accepted because it appears to be spam.', $errorSignal->data->reason);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    /**
     * Hands the moderator's verdict to the library that owns the account (HIL-771).
     *
     * The verdict comes back to the users library the profile submit asked from, and an approved
     * name goes one hop further: the person's agent writes it and answers the library, which then
     * writes the feed line (HIL-1404). Both hops are carried here, as the workers would carry them.
     *
     * @param ModerationVerdictSignalData $result Verdict as the moderator sends it
     * @throws HilosException When the verdict cannot be applied
     */
    private function dispatchRenameModerationVerdict(ModerationVerdictSignalData $result): void
    {
        $agentSignalData = new AgentSignalData($result);
        ExecutionContext::setCurrentAcceptKey($agentSignalData->getAcceptKey());
        try {
            $library = $this->usersLibrary();
            $this->underAgent($library, static fn () => $library->onSignalAgent(
                $agentSignalData, '', HilosSignalConstants::HILOS_MODERATION_VERDICT,
            ));
            $this->deliverPersonAgentFrames();
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }

    private function takeQueuedWebSocketSignal(string $signalName): ?WebSocketSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== $signalName) {
                continue;
            }

            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);

            return $signal->data;
        }

        return null;
    }

    private function assertUserRenamedEventExists(int $userId, string $oldName, string $newName): void
    {
        foreach (Hilos::$db->events as $event) {
            if (
                $event->type === ChatEventType::USER_RENAMED->value
                && $event->userRename?->userId === $userId
                && $event->userRename->renamedByUserId === $userId
                && $event->userRename->oldName === $oldName
                && $event->userRename->newName === $newName
            ) {
                return;
            }
        }

        $this->fail("Expected user_renamed event for '{$newName}' to exist.");
    }
}
