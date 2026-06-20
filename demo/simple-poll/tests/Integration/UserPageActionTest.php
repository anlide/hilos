<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Integration;

use Demo\SimplePoll\Agents\Hilos\DemoHilosAgent;
use Demo\SimplePoll\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Pages\Hilos\Users\UserPage;
use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Demo\SimplePoll\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Router\SignalRouter;
use Hilos\HilosException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

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
        RtTruthSourceRegistry::register(PollRtContext::connections, true, self::TEST_AGENT_ID);
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
        $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
        $userId = (int) $user->id;
        $originalName = (string) $user->name;

        (new UserPage(new DemoHilosAgent()))->onAction(
            'poll-ak',
            HilosSignalConstants::HILOS_USER_UPDATE,
            new HilosUserUpdateActionDTO($userId, 'Renamed'),
        );

        $this->assertSame('Renamed', Hilos::$db->users[$userId]?->name);

        $audit = EntityUserRename::get([EntityUserRename::target_user_id => $userId])->first();
        $this->assertNotNull($audit);
        $this->assertSame($originalName, $audit->old_name);
        $this->assertSame('Renamed', $audit->new_name);
    }

    /**
     * An unknown action name is rejected.
     *
     * @throws HilosException On database or runtime error
     */
    public function testUnknownActionThrows(): void
    {
        $this->expectException(AgentUnknownActionException::class);

        (new UserPage(new DemoHilosAgent()))->onAction('poll-ak', 'nope', new HilosUserUpdateActionDTO(1, 'x'));
    }
}
