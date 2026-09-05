<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Hilos;
use Demo\Polls\Runtime\State\Item\Connection as ConnectionState;
use Demo\Polls\Runtime\View\Context\PollsRtContext;
use Demo\Polls\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for the polls Hilos users table — the merge of DB
 * profile fields with RT connection presence.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class HilosUsersTableTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(PollsRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        parent::tearDown();
    }

    /**
     * A row with no active connection carries the DB profile and offline presence.
     *
     * @throws HilosException On database or runtime error
     */
    public function testRowFromUserMergesProfileAndOfflinePresence(): void
    {
        $user = Hilos::$db->users->actions->registerAdmin();
        // The only mint this demo has makes administrators (HIL-609); the row under
        // test is an ordinary one, so the flag comes straight back off.
        $user->actions->setAdmin(false);
        $row = new HilosUsersTable()->rowFromUser(Hilos::$db->users[$user->id]);

        $this->assertSame((int) $user->id, $row->id);
        $this->assertSame($user->name, $row->name);
        $this->assertFalse($row->admin);
        $this->assertFalse($row->block);
        $this->assertSame(0, $row->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_OFFLINE, $row->presence);
    }

    /**
     * Active connections raise the row's session count and online presence.
     *
     * @throws HilosException On database or runtime error
     */
    public function testRowFromUserReflectsOnlinePresence(): void
    {
        $user = Hilos::$db->users->actions->registerAdmin();
        $userId = (int) $user->id;
        Hilos::$rt->connections->actions->register('polls-ak-a', $userId);
        Hilos::$rt->connections->actions->register('polls-ak-b', $userId);

        $row = new HilosUsersTable()->rowFromUser(Hilos::$db->users[$userId]);

        $this->assertSame(2, $row->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $row->presence);
    }

    /**
     * A connection source change resolves to a row update for the owning user.
     *
     * @throws HilosException On database or runtime error
     */
    public function testPresenceChangeBuildsUserRowUpdate(): void
    {
        $user = Hilos::$db->users->actions->registerAdmin();
        $userId = (int) $user->id;
        Hilos::$rt->connections->actions->register('polls-ak-a', $userId);

        $change = SourceChange::rtUpdated(
            PollsRtContext::connections,
            'polls-ak-a',
            [ConnectionState::userId => $userId],
        );

        $this->assertNotNull(new HilosUsersTable()->buildMutationForSourceEvent($change));
    }
}
