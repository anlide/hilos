<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Integration;

use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Runtime\State\Item\Connection as ConnectionState;
use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Demo\SimplePoll\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Source\SourceChange;
use Hilos\HilosException;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the simple-poll Hilos users table — the merge of DB
 * profile fields with RT connection presence.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class HilosUsersTableTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(PollRtContext::connections, true, self::TEST_AGENT_ID);
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
        $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
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
        $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
        $userId = (int) $user->id;
        Hilos::$rt->connections->actions->register('poll-ak-a', $userId);
        Hilos::$rt->connections->actions->register('poll-ak-b', $userId);

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
        $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
        $userId = (int) $user->id;
        Hilos::$rt->connections->actions->register('poll-ak-a', $userId);

        $change = SourceChange::rtUpdated(
            PollRtContext::connections,
            'poll-ak-a',
            [ConnectionState::userId => $userId],
        );

        $this->assertNotNull(new HilosUsersTable()->buildMutationForSourceEvent($change));
    }
}
