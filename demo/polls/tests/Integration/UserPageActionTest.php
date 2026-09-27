<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Agents\Hilos\DemoHilosAgent;
use Demo\Polls\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\Polls\Hilos;
use Demo\Polls\Pages\Hilos\Users\UserPage;
use Demo\Polls\Runtime\View\Context\PollsRtContext;
use Demo\Polls\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Users\DTO\AccountBlockSetSignalData;
use Hilos\Users\DTO\AccountDeletionSetSignalData;
use Demo\Polls\Agents\Hilos\UsersLibraryAgent;
use Demo\Polls\Users\PollsAdminAudience;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for the Hilos user-detail page rename action.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UserPageActionTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(PollsRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt->connections->actions->clear();
        parent::tearDown();
    }

    /**
     * The update action renames the target user through the users table action.
     *
     * @throws HilosException On database or runtime error
     */
    public function testUpdateActionRenamesUser(): void
    {
        $user = Hilos::$db->users->actions->registerAdmin();
        $userId = (int) $user->id;
        $originalName = (string) $user->name;

        new UserPage(new DemoHilosAgent())->onAction(
            'polls-ak',
            HilosSignalConstants::HILOS_USER_UPDATE,
            new HilosUserUpdateActionDTO($userId, 'Renamed'),
        );
        $this->deliverHilosLibraryFrames();

        $this->assertSame('Renamed', Hilos::$db->users[$userId]?->name);

        $audit = EntityUserRename::get([EntityUserRename::target_user_id => $userId])->first();
        $this->assertNotNull($audit);
        $this->assertSame($originalName, $audit->old_name);
        $this->assertSame('Renamed', $audit->new_name);
    }

    public function testTheProjectWritesBlocksAndItsAudienceExcludesBlockedAdministrators(): void
    {
        $adminId = (int) Hilos::$db->users->actions->registerAdmin()->id;
        $targetId = (int) Hilos::$db->users->actions->createWithName('Target administrator')->id;
        Hilos::$db->users[$targetId]->actions->setAdmin(true);
        Hilos::$rt->connections->actions->register('lifecycle-admin', $adminId);
        self::assertContains($adminId, PollsAdminAudience::all());
        self::assertContains($targetId, PollsAdminAudience::all());
        $library = $this->sessionsLibrary();

        foreach ([true, false] as $block) {
            ExecutionContext::run(new ExecutionFrame(agentId: $library->getId()), static fn () => $library->onSignalAgent(
                new AgentSignalData(new AccountBlockSetSignalData(
                    $targetId, $block, HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
                    'lifecycle-admin', 'lifecycle-request', HilosSignalConstants::HILOS_USER_BLOCK_SET, null,
                )),
                '',
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET,
            ));
            $reply = null;
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                    $reply = $signal->data->data;
                }
            }
            self::assertNotNull($reply);
            self::assertNull($reply->error);
            self::assertSame($block, Hilos::$db->users[$targetId]->block);
            self::assertSame(!$block, in_array($targetId, PollsAdminAudience::all(), true));
        }
    }

    public function testAnAdministratorAccountMustLoseItsRightsBeforeDeletion(): void
    {
        $adminId = (int) Hilos::$db->users->actions->registerAdmin()->id;
        $targetId = (int) Hilos::$db->users->actions->createWithName('Target administrator')->id;
        Hilos::$db->users[$targetId]->actions->setAdmin(true);
        Hilos::$rt->connections->actions->register('lifecycle-admin', $adminId);
        $library = new UsersLibraryAgent();
        $this->startAgent($library);
        ExecutionContext::run(new ExecutionFrame(agentId: $library->getId()), static fn () => $library->onSignalAgent(
            new AgentSignalData(new AccountDeletionSetSignalData(
                $targetId, true, HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE,
                'lifecycle-admin', 'lifecycle-request', HilosSignalConstants::HILOS_USER_DELETION_SET, null,
            )),
            '',
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET,
        ));
        $reply = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                $reply = $signal->data->data;
            }
        }
        self::assertNotNull($reply);
        self::assertSame('Remove the admin rights first', $reply->error);
        self::assertNull(Hilos::$db->accountDeletions->liveOf($targetId));
    }

    /**
     * An unknown action name is rejected.
     *
     * @throws HilosException On database or runtime error
     */
    public function testUnknownActionThrows(): void
    {
        $this->expectException(AgentUnknownActionException::class);

        new UserPage(new DemoHilosAgent())->onAction('polls-ak', 'nope', new HilosUserUpdateActionDTO(1, 'x'));
    }
}
