<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Group\DTO\GroupLeaveAllSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosConnection;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A change of person on a connection ends every group it held (HIL-1284).
 *
 * The rule hangs on the one write that puts a new person on a connection, so these cases drive
 * that write and read what it leaves behind: this worker's mirror is cleared at once, and exactly
 * one leave-all naming the connection is queued for the master. Writing the same person again is
 * not a change - a repeated session frame must not throw the tab out of its groups.
 */
final class HilosConnectionBindUserGroupsTest extends TestCase
{
    private const string AGENT_ID = 'bind_user_groups_test_agent';
    private const string ACCEPT_KEY = 'ak-tab';
    private const string GROUP = 'hilos_notifications:7';

    protected function tearDown(): void
    {
        Hilos::$rt = null;
        Hilos::$sr = null;
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_ID);

        parent::tearDown();
    }

    /**
     * @return array<string, array{?int, ?int}> Person before and after the write
     */
    public static function changesOfPerson(): array
    {
        return [
            'sign-out' => [7, null],
            'sign-in' => [null, 8],
            'another person' => [7, 8],
        ];
    }

    #[DataProvider('changesOfPerson')]
    public function testAChangeOfPersonClearsTheMirrorAndQueuesOneLeaveAll(?int $before, ?int $after): void
    {
        $connections = $this->arrangeConnection($before);

        $connections[self::ACCEPT_KEY]->actions->bindUser($after);

        $this->assertNull(Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, 'hilos_notifications'));
        $leaves = $this->queuedLeaveAlls();
        $this->assertCount(1, $leaves);
        $this->assertSame([self::ACCEPT_KEY], $leaves[0]->acceptKeys);
        $this->assertNull($leaves[0]->exceptWorkerIndex, 'the sending worker names nobody to skip');
    }

    public function testTheSamePersonAgainLeavesTheGroupsAlone(): void
    {
        $connections = $this->arrangeConnection(7);

        $connections[self::ACCEPT_KEY]->actions->bindUser(7);

        $this->assertSame(self::GROUP, Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, 'hilos_notifications'));
        $this->assertSame([], $this->queuedLeaveAlls());
    }

    /**
     * Mounts one connection of the given person, holding one group in this worker's mirror.
     *
     * @param ?int $userId Person behind the connection
     * @return BindUserGroupsConnections Collection the connection lives in
     */
    private function arrangeConnection(?int $userId): BindUserGroupsConnections
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BindUserGroupsRtContext();
        Hilos::$rt->configure();
        ExecutionContext::setCurrentAgentId(self::AGENT_ID);
        RtTruthSourceRegistry::register(BindUserGroupsRtContext::connections, TruthSourceKeys::all(), self::AGENT_ID);

        $connections = Hilos::$rt->connectionsRegistry();
        $this->assertInstanceOf(BindUserGroupsConnections::class, $connections);
        $connections->actions->register(self::ACCEPT_KEY, $userId);
        Hilos::$sr->subscribeToGroup(self::GROUP, new WebSocketGroupSubscribeSignalDTO(
            acceptKey: self::ACCEPT_KEY,
            group: self::GROUP,
            params: [],
        ));
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
            // The row's own creation sync is not what these cases are about.
        }

        return $connections;
    }

    /**
     * Drains the queue and keeps the leave-all announcements.
     *
     * @return list<GroupLeaveAllSignalData> Queued leave-alls, in order
     */
    private function queuedLeaveAlls(): array
    {
        $leaves = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->signalType->getType() !== SignalTypeConstants::GROUP_LEAVE_ALL) {
                continue;
            }
            $this->assertInstanceOf(GroupLeaveAllSignalData::class, $signal->data);
            $leaves[] = $signal->data;
        }

        return $leaves;
    }
}

/**
 * Presence-stage row with nothing of its own.
 */
final class BindUserGroupsConnection extends StateHilosConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * @extends StateHilosConnections<BindUserGroupsConnection>
 */
final class BindUserGroupsStates extends StateHilosConnections
{
    public const string STATE_CLASS = BindUserGroupsConnection::class;
}

/**
 * @extends HilosConnection<BindUserGroupsConnection>
 */
final class BindUserGroupsItem extends HilosConnection
{
}

/**
 * @extends HilosConnectionActions<BindUserGroupsItem>
 */
final class BindUserGroupsItemActions extends HilosConnectionActions
{
}

/**
 * @extends HilosConnectionsActions<BindUserGroupsItem, BindUserGroupsConnections>
 */
final class BindUserGroupsCollectionActions extends HilosConnectionsActions
{
}

/**
 * @extends HilosConnections<BindUserGroupsItem, BindUserGroupsCollectionActions>
 */
final class BindUserGroupsConnections extends HilosConnections
{
    /**
     * @param RtState $state Backing state row
     * @return BindUserGroupsItem View item over the row
     */
    protected function createRtItem(RtState $state): BindUserGroupsItem
    {
        /** @var BindUserGroupsConnection $state */
        return new BindUserGroupsItem($state);
    }
}

/**
 * Runtime context of a project that mounts and represents its connections.
 */
final class BindUserGroupsRtContext extends RtContext
{
    public const string connections = 'bindUserGroupsTestConnections';

    /**
     * Mounts the connections collection and gives it the write API the change of person goes through.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = BindUserGroupsStates::init();
        $this->setRepresent(
            self::connections,
            BindUserGroupsConnections::class,
            BindUserGroupsCollectionActions::class,
            BindUserGroupsItemActions::class,
        );
    }
}
