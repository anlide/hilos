<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Core\Group\DTO\GroupLeaveAllSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SubscriptionRegistry;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Database;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\DataExport\DataExportGroup;
use Hilos\Hilos;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Notification\NotificationGroup;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosConnection;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Two people in one tab: the groups of the first do not follow the second (HIL-1284).
 *
 * Every group a person's tab is let into is walked - the bell, the second factor, the account
 * deletion, the legal agreements and the data export - and the tab changes person on the live
 * connection, as a sign-out, a sign-in, an impersonation or a block does. Two registries are
 * read after it: this process's mirror, and the master's, rebuilt here by replaying the frames
 * this process queued in the order they would arrive. The second is the one every fan-out is
 * resolved against, so a key left in it is a frame of the previous person reaching the tab.
 *
 * The "Access closed" card is walked last: its export membership is the one a tab holds for a
 * person who is not behind it, and it has to join AFTER the change of person and leave with
 * the card.
 */
final class GroupMembershipPersonChangeIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string AGENT_ID = 'group_membership_person_change_test';
    private const string TAB = 'ak-shared-tab';
    private const string OTHER_TAB = 'ak-other-device';
    private const string SESSION_TOKEN = '00112233445566778899aabbccddeeff';
    private const int FIRST = 7;
    private const int SECOND = 8;

    private GroupMembershipTestAgent $agent;

    private SubscriptionRegistry $master;

    private ?SettingsAccessor $previousSetting = null;

    protected function setUp(): void
    {
        parent::setUp();

        Database::sqlRun(
            "INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'First'), (?, 'Second')",
            [self::FIRST, self::SECOND],
        );
        // The handshake response is stamped with the impersonation policy and the theme settings.
        $this->previousSetting = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(GroupMembershipTestSettingsCatalog::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new GroupMembershipTestRtContext();
        Hilos::$rt->configure();
        ExecutionContext::setCurrentAgentId(self::AGENT_ID);
        RtTruthSourceRegistry::register(GroupMembershipTestRtContext::connections, TruthSourceKeys::all(), self::AGENT_ID);
        $this->agent = new GroupMembershipTestAgent();
        $this->master = new SubscriptionRegistry();
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_ID);
        ExecutionContext::setCurrentAgentId(null);
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$setting = $this->previousSetting;

        parent::tearDown();
    }

    /**
     * @return array<string, array{?int}> Who the tab belongs to after the first person
     */
    public static function nextPeople(): array
    {
        return [
            'nobody (sign-out)' => [null],
            'another person (sign-in, impersonation)' => [self::SECOND],
        ];
    }

    #[DataProvider('nextPeople')]
    public function testNoGroupOfThePreviousPersonNamesTheTabAfterTheChange(?int $next): void
    {
        $this->openTab(self::TAB, self::FIRST);
        $this->openTab(self::OTHER_TAB, self::FIRST);
        $this->joinEveryGroup(self::TAB, self::FIRST);
        $this->joinEveryGroup(self::OTHER_TAB, self::FIRST);
        $this->replay();

        $this->connections()[self::TAB]->actions->bindUser($next);
        $this->replay();

        foreach ($this->groupsOf(self::FIRST) as $group) {
            $this->assertNotContains(self::TAB, $this->master->acceptKeysForGroup($group), "master still sends {$group} to the tab");
            $this->assertNotContains(self::TAB, $this->mirrorRecipients($group), "the mirror still sends {$group} to the tab");
            $this->assertSame([self::OTHER_TAB], $this->master->acceptKeysForGroup($group), "{$group} lost the other device");
        }
    }

    public function testTheNextPersonsGroupsReachOnlyTheirOwnTab(): void
    {
        $this->openTab(self::TAB, self::FIRST);
        $this->openTab(self::OTHER_TAB, self::FIRST);
        $this->joinEveryGroup(self::TAB, self::FIRST);
        $this->joinEveryGroup(self::OTHER_TAB, self::FIRST);

        $this->connections()[self::TAB]->actions->bindUser(self::SECOND);
        $this->joinEveryGroup(self::TAB, self::SECOND);
        $this->replay();

        foreach ($this->groupsOf(self::SECOND) as $group) {
            $this->assertSame([self::TAB], $this->master->acceptKeysForGroup($group));
        }
        foreach ($this->groupsOf(self::FIRST) as $group) {
            $this->assertSame([self::OTHER_TAB], $this->master->acceptKeysForGroup($group));
        }
    }

    /**
     * The same person out and back in on the same socket: every join goes through again, none of
     * them turned away by a mirror that still remembered the membership.
     */
    public function testTheSamePersonSigningOutAndBackInIsLetIntoEveryGroupAgain(): void
    {
        $this->openTab(self::TAB, self::FIRST);
        $this->joinEveryGroup(self::TAB, self::FIRST);

        $this->connections()[self::TAB]->actions->bindUser(null);
        $this->connections()[self::TAB]->actions->bindUser(self::FIRST);
        $this->joinEveryGroup(self::TAB, self::FIRST);
        $this->replay();

        foreach ($this->groupsOf(self::FIRST) as $group) {
            $this->assertSame([self::TAB], $this->master->acceptKeysForGroup($group), "{$group} did not take the tab back");
            $this->assertSame([self::TAB], $this->mirrorRecipients($group));
        }
    }

    public function testABlockLeavesTheTabOnlyInTheCardsExportGroupAndClosingTheCardEndsIt(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::FIRST, '2026-10-07 10:00:00', null);
        $this->openTab(self::TAB, self::FIRST);
        $this->joinEveryGroup(self::TAB, self::FIRST);
        $this->replay();

        // The block takes the person off the session and leaves the card behind.
        Database::sqlRun(
            'UPDATE `hilos_session` SET `user_id` = NULL, `blocked_user_id` = ? WHERE `token` = ?',
            [self::FIRST, self::SESSION_TOKEN],
        );
        $this->settle(null, accountBlocked: ['identifier' => null, 'dataExport' => null]);
        $this->replay();

        foreach ($this->groupsOf(self::FIRST) as $group) {
            $expected = $group === DataExportGroup::forUser(self::FIRST) ? [self::TAB] : [];
            $this->assertSame($expected, $this->master->acceptKeysForGroup($group), "after the block: {$group}");
        }

        // The card closes; the person behind the tab does not change, so only the card's own leave ends it.
        $this->settle(null, accountBlocked: null);
        $this->replay();

        foreach ($this->groupsOf(self::FIRST) as $group) {
            $this->assertSame([], $this->master->acceptKeysForGroup($group), "after the card closed: {$group}");
        }
    }

    /**
     * Opens a tab of a person: the connection row a handshake writes.
     *
     * @param string $acceptKey Connection of the tab
     * @param int $userId Person behind it
     */
    private function openTab(string $acceptKey, int $userId): void
    {
        $this->connections()->actions->register($acceptKey, $userId);
    }

    /**
     * Lets one connection into all five groups a person's tab can hold, the way each is joined today.
     *
     * The bell is admitted by its group class on a client frame, which writes the same two things
     * the four server-side joins write: this worker's mirror and the word to the master.
     *
     * @param string $acceptKey Connection being let in
     * @param int $userId Person the groups are addressed by
     */
    private function joinEveryGroup(string $acceptKey, int $userId): void
    {
        $bell = NotificationGroup::forUser($userId);
        Hilos::$sr->subscribeToGroup($bell, new WebSocketGroupSubscribeSignalDTO(acceptKey: $acceptKey, group: $bell, params: []));
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::GROUP_JOIN),
            signalName: new SignalName(SignalTypeConstants::GROUP_JOIN),
            signalData: new GroupJoinSignalData($bell, $acceptKey, []),
        );
        SecondFactorGroup::join($acceptKey, $userId, $this->agent->getAgentSignalSource());
        AccountDeletionGroup::join($acceptKey, $userId, $this->agent->getAgentSignalSource());
        LegalAgreementsGroup::join($acceptKey, $userId, $this->agent->getAgentSignalSource());
        DataExportGroup::join($acceptKey, $userId, $this->agent->getAgentSignalSource());
    }

    /**
     * Runs the holder's part of one session-state frame for the shared tab, in the projects' order:
     * the connection row first, then the handshake response.
     *
     * @param ?int $userId Person the frame says is behind the session now
     * @param ?array{identifier: ?string, dataExport: ?array<string, mixed>} $accountBlocked Card the session holds
     */
    private function settle(?int $userId, ?array $accountBlocked): void
    {
        $connection = $this->connections()[self::TAB];
        if ($connection->userId !== $userId) {
            $connection->actions->bindUser($userId);
        }
        $state = new SessionStateSignalData(
            sessionToken: self::SESSION_TOKEN,
            sessionId: null,
            userId: $userId,
            acceptKeys: [self::TAB],
            accountBlocked: $accountBlocked,
        );
        $this->agent->sendHandshakeResponse('group_membership_test_handshake', self::TAB, new HandshakeResponseSignalData(), $state);
    }

    /**
     * Drains what this process queued and applies the group frames to the master's registry in order.
     *
     * The same three writes the master makes: a join at receipt, a leave-all at receipt, and a
     * group leave of an agent as it is routed.
     */
    private function replay(): void
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) instanceof SignalDTO) {
            $data = $signal->data;
            switch ($signal->signalType->getType()) {
                case SignalTypeConstants::GROUP_JOIN:
                    $this->assertInstanceOf(GroupJoinSignalData::class, $data);
                    $this->master->subscribeToGroup($data->acceptKey, $data->group, $data->params);
                    break;
                case SignalTypeConstants::GROUP_LEAVE_ALL:
                    $this->assertInstanceOf(GroupLeaveAllSignalData::class, $data);
                    foreach ($data->acceptKeys as $acceptKey) {
                        $this->master->unsubscribeFromAllGroups($acceptKey);
                    }
                    break;
                case SignalTypeConstants::GROUP_UNSUBSCRIBE:
                    $this->assertInstanceOf(WebSocketGroupUnsubscribeSignalDTO::class, $data);
                    $this->master->unsubscribeFromGroup($data->acceptKey, $signal->signalName->getName());
                    break;
            }
        }
    }

    /**
     * Resolves who a group frame sent from this process reaches.
     *
     * @param string $group Full group name
     * @return list<string> Accept keys the frame is written to
     */
    private function mirrorRecipients(string $group): array
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_GROUP),
            new SignalName('group_membership_test_frame'),
            new WebSocketSignalData(data: new SignalData([]), targetGroup: $group),
        );

        $recipients = [];
        foreach (Hilos::$sr->localClientDestinations($signal) as $destination) {
            $this->assertInstanceOf(WebSocketDestination::class, $destination);
            $recipients[] = $destination->acceptKey;
        }

        return $recipients;
    }

    /**
     * @param int $userId Person the groups are addressed by
     * @return list<string> Full names of the five groups of that person
     */
    private function groupsOf(int $userId): array
    {
        return [
            NotificationGroup::forUser($userId),
            SecondFactorGroup::forUser($userId),
            AccountDeletionGroup::forUser($userId),
            LegalAgreementsGroup::forUser($userId),
            DataExportGroup::forUser($userId),
        ];
    }

    /**
     * @return GroupMembershipTestConnections Live connection rows of the fixture project
     */
    private function connections(): GroupMembershipTestConnections
    {
        $connections = Hilos::$rt->connectionsRegistry();
        $this->assertInstanceOf(GroupMembershipTestConnections::class, $connections);

        return $connections;
    }
}

/**
 * The settings a handshake response is stamped with.
 */
final class GroupMembershipTestSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Impersonation and theme settings at their defaults
     */
    public static function getCatalog(): array
    {
        return [...ImpersonationSettingsCatalog::getCatalog(), ...ThemeSettingsCatalog::getCatalog()];
    }
}

/**
 * The project agent holding the sockets: it settles the rows and sends the handshake responses.
 */
final class GroupMembershipTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'group_membership_test';

    public function onStop(): void
    {
    }
}

/**
 * Presence-stage row with nothing of its own.
 */
final class GroupMembershipTestConnection extends StateHilosConnection
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
 * @extends StateHilosConnections<GroupMembershipTestConnection>
 */
final class GroupMembershipTestStates extends StateHilosConnections
{
    public const string STATE_CLASS = GroupMembershipTestConnection::class;
}

/**
 * @extends HilosConnection<GroupMembershipTestConnection>
 */
final class GroupMembershipTestItem extends HilosConnection
{
}

/**
 * @extends HilosConnectionActions<GroupMembershipTestItem>
 */
final class GroupMembershipTestItemActions extends HilosConnectionActions
{
}

/**
 * @extends HilosConnectionsActions<GroupMembershipTestItem, GroupMembershipTestConnections>
 */
final class GroupMembershipTestCollectionActions extends HilosConnectionsActions
{
}

/**
 * @extends HilosConnections<GroupMembershipTestItem, GroupMembershipTestCollectionActions>
 */
final class GroupMembershipTestConnections extends HilosConnections
{
    /**
     * @param RtState $state Backing state row
     * @return GroupMembershipTestItem View item over the row
     */
    protected function createRtItem(RtState $state): GroupMembershipTestItem
    {
        /** @var GroupMembershipTestConnection $state */
        return new GroupMembershipTestItem($state);
    }
}

/**
 * Runtime context of a project that mounts and represents its connections.
 */
final class GroupMembershipTestRtContext extends RtContext
{
    public const string connections = 'groupMembershipTestConnections';

    /**
     * Mounts the connections collection and gives it the write API a change of person goes through.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = GroupMembershipTestStates::init();
        $this->setRepresent(
            self::connections,
            GroupMembershipTestConnections::class,
            GroupMembershipTestCollectionActions::class,
            GroupMembershipTestItemActions::class,
        );
    }
}
