<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\State\Collection\HilosSessionConnections as StateHilosSessionConnections;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateHilosSessionConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\Collection\HilosConnections;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\Collection\HilosSessionConnections;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Runtime\View\Item\HilosConnection;
use Hilos\Runtime\View\Item\HilosSessionConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework-owned view layer of connections (HIL-509).
 *
 * The reads a project used to copy into its own collection now live on the base,
 * and they are split across the same two stages the rows are: presence carries
 * the per-user reads and the presence contract, the session stage carries the
 * token lookup. What each case asserts is that a project gets them by standing
 * on the stage, having written none of them.
 */
final class HilosConnectionsViewTest extends TestCase
{
    private const string COLLECTION = 'presenceConnections';

    protected function tearDown(): void
    {
        RtStaleness::reset();

        parent::tearDown();
    }

    public function testPresenceStageFiltersByUserAndSummarizesPresence(): void
    {
        $connections = $this->presenceConnections();

        $this->assertInstanceOf(HilosPresenceSource::class, $connections);
        $this->assertCount(2, $connections->forUser(1));
        $this->assertCount(0, $connections->forUser(2));
        $this->assertCount(0, $connections->forUser(null));

        $online = $connections->summaryForUser(1);
        $this->assertSame(2, $online->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $online->presence);

        $offline = $connections->summaryForUser(2);
        $this->assertSame(0, $offline->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_OFFLINE, $offline->presence);
    }

    public function testFilteredCopyHoldsTheLiveRowsOfTheSameCollectionClass(): void
    {
        $connections = $this->presenceConnections();
        $filtered = $connections->forUser(1);

        $this->assertInstanceOf(PresenceViewConnections::class, $filtered);
        $this->assertSame(
            ['view-ak-1' => 'view-ak-1', 'view-ak-2' => 'view-ak-2'],
            array_map(static fn(HilosConnection $item): string => $item->acceptKey, iterator_to_array($filtered)),
        );
    }

    public function testPresenceStageHasNoTokenLookup(): void
    {
        $this->assertFalse(method_exists(PresenceViewConnections::class, 'acceptKeysForSessionToken'));
    }

    public function testSessionStageReadsTheAcceptKeysOfAToken(): void
    {
        $connections = $this->sessionConnections();

        $this->assertSame(['view-ak-1', 'view-ak-2'], $connections->acceptKeysForSessionToken('tok-x'));
        $this->assertSame(['view-ak-3'], $connections->acceptKeysForSessionToken('tok-y'));
        $this->assertSame([], $connections->acceptKeysForSessionToken('no-such-token'));
        $this->assertSame([], $connections->acceptKeysForSessionToken(''));
        $this->assertCount(2, $connections->forUser(1));
    }

    /**
     * One connection of a user frozen makes the user's whole summary a frozen one: the count
     * it qualifies is a single number over all of them, and it is no longer moving (HIL-800).
     */
    public function testTheSummaryOfAUserWithAFrozenConnectionSaysSo(): void
    {
        $connections = $this->presenceConnections();
        RtStaleness::mark(self::COLLECTION, ['view-ak-2'], 1000.0);

        $summary = $connections->summaryForUser(1);

        $this->assertTrue($summary->stale);
        $this->assertSame(2, $summary->onlineSessionCount, 'a frozen copy is still served, and still counted');
        $this->assertFalse(
            $connections->summaryForUser(2)->stale,
            'a user with no connection of that collection is not dragged into it',
        );
    }

    public function testAUserWhoseConnectionsAreAllCurrentIsNotCalledFrozen(): void
    {
        $connections = $this->presenceConnections();
        RtStaleness::mark(self::COLLECTION, ['view-ak-3'], 1000.0);

        $this->assertFalse($connections->summaryForUser(1)->stale);
    }

    /**
     * The trap the summary must not fall into, pinned from the outside: the filtered copy is
     * detached from the collection name the marks are kept under, so every item of it answers
     * "fresh" whatever the link is doing. A summary counted off that copy would be a green
     * light wired to nothing, which is why it is counted off the collection itself.
     */
    public function testTheFilteredCopyAnswersFreshWhileTheSummaryDoesNot(): void
    {
        $connections = $this->presenceConnections();
        RtStaleness::mark(self::COLLECTION, ['view-ak-1', 'view-ak-2'], 1000.0);

        foreach ($connections->forUser(1) as $item) {
            $this->assertNull($item->staleSince(), 'the copy carries no collection name to ask under');
        }

        $this->assertTrue($connections->summaryForUser(1)->stale);
    }

    /**
     * @return array<string, array{list<array{string, ?int, bool}>, list<string>, string, int, bool}>
     *     Rows (accept key, person, doubted), rows frozen, then the presence, count and freshness of person 1
     */
    public static function doubtCases(): array
    {
        $cases = [
            'no connection' => [[['ak-x', 2, true]], [], HilosUserPresenceSummary::PRESENCE_OFFLINE, 0, false],
            'one healthy' => [[['ak-1', 1, false]], [], HilosUserPresenceSummary::PRESENCE_ONLINE, 1, false],
            'one doubted' => [[['ak-1', 1, true]], [], HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE, 1, false],
            'two sessions, both healthy' => [
                [['ak-1', 1, false], ['ak-2', 1, false]],
                [],
                HilosUserPresenceSummary::PRESENCE_ONLINE,
                2,
                false,
            ],
            'two sessions, both doubted' => [
                [['ak-1', 1, true], ['ak-2', 1, true]],
                [],
                HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE,
                2,
                false,
            ],
            'one doubted, one healthy' => [
                [['ak-1', 1, true], ['ak-2', 1, false]],
                [],
                HilosUserPresenceSummary::PRESENCE_ONLINE,
                2,
                false,
            ],
            'another person\'s doubt' => [
                [['ak-1', 1, false], ['ak-x', 2, true]],
                [],
                HilosUserPresenceSummary::PRESENCE_ONLINE,
                1,
                false,
            ],
        ];
        $frozen = [
            'one healthy, frozen' => [[['ak-1', 1, false]], ['ak-1'], HilosUserPresenceSummary::PRESENCE_ONLINE, 1, true],
            'one doubted, frozen' => [
                [['ak-1', 1, true]],
                ['ak-1'],
                HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE,
                1,
                true,
            ],
            'two sessions, both doubted, one frozen' => [
                [['ak-1', 1, true], ['ak-2', 1, true]],
                ['ak-2'],
                HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE,
                2,
                true,
            ],
            'the doubted one frozen first, the healthy one after it' => [
                [['ak-1', 1, true], ['ak-2', 1, false]],
                ['ak-1'],
                HilosUserPresenceSummary::PRESENCE_ONLINE,
                2,
                true,
            ],
            'the healthy one frozen, the doubted one after it' => [
                [['ak-1', 1, false], ['ak-2', 1, true]],
                ['ak-1'],
                HilosUserPresenceSummary::PRESENCE_ONLINE,
                2,
                true,
            ],
            'only another person frozen' => [
                [['ak-1', 1, true], ['ak-x', 2, false]],
                ['ak-x'],
                HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE,
                1,
                false,
            ],
        ];

        return $cases + $frozen;
    }

    /**
     * The doubt and the freshness are two answers read off the same rows, and neither moves the
     * other: a person is probably offline only when every connection counted is doubted, the
     * doubted ones stay in the count, and a frozen copy marks the summary stale whatever the
     * presence. The frozen row standing first pins that the walk does not stop at it before the
     * doubt of the rows after it is read.
     *
     * @param list<array{string, ?int, bool}> $rows Accept key, person and doubt of each row
     * @param list<string> $frozenKeys Rows whose copy stopped being updated
     * @param string $presence Presence expected for person 1
     * @param int $count Session count expected for person 1
     * @param bool $stale Freshness expected for person 1
     */
    #[DataProvider('doubtCases')]
    public function testTheSummaryCallsAPersonProbablyOfflineOnlyWhenEveryConnectionIsDoubted(
        array $rows,
        array $frozenKeys,
        string $presence,
        int $count,
        bool $stale,
    ): void {
        $connections = $this->doubtedConnections($rows);
        if ($frozenKeys !== []) {
            RtStaleness::mark(self::COLLECTION, $frozenKeys, 1000.0);
        }

        $summary = $connections->summaryForUser(1);

        $this->assertSame($presence, $summary->presence);
        $this->assertSame($count, $summary->onlineSessionCount);
        $this->assertSame($stale, $summary->stale);
    }

    /**
     * A doubted connection that changes person takes its doubt along: the person it left is
     * judged by what remains, the person it joined by what it brings.
     */
    public function testTheDoubtFollowsAConnectionToItsNewPerson(): void
    {
        $state = PresenceViewStates::init();
        $healthy = PresenceViewConnection::create('ak-1', 1);
        $doubted = PresenceViewConnection::create('ak-2', 1);
        $doubted->probablyOffline = true;
        $state->add($healthy);
        $state->add($doubted);
        $connections = PresenceViewConnections::init();
        $connections->setStateCollection($state);
        $connections->setCollectionName(self::COLLECTION);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $connections->summaryForUser(1)->presence);

        $healthy->userId = 2;

        $left = $connections->summaryForUser(1);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE, $left->presence);
        $this->assertSame(1, $left->onlineSessionCount);
        $joined = $connections->summaryForUser(2);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $joined->presence);
        $this->assertSame(1, $joined->onlineSessionCount);
    }

    public function testTheSessionStageSummarizesTheDoubtTheSameWay(): void
    {
        $state = SessionViewStates::init();
        foreach (['view-ak-1', 'view-ak-2'] as $acceptKey) {
            $doubted = SessionViewConnection::create($acceptKey, 1, 'tok-x');
            $doubted->probablyOffline = true;
            $state->add($doubted);
        }
        $state->add(SessionViewConnection::create('view-ak-3', 2, 'tok-y'));
        $connections = SessionViewConnections::init();
        $connections->setStateCollection($state);

        $summary = $connections->summaryForUser(1);

        $this->assertSame(HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE, $summary->presence);
        $this->assertSame(2, $summary->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $connections->summaryForUser(2)->presence);
    }

    /**
     * Builds a presence-stage view over the given rows, each with its own doubt.
     *
     * @param list<array{string, ?int, bool}> $rows Accept key, person and doubt of each row
     * @return PresenceViewConnections View collection over the rows
     */
    private function doubtedConnections(array $rows): PresenceViewConnections
    {
        $state = PresenceViewStates::init();
        foreach ($rows as [$acceptKey, $userId, $doubted]) {
            $connection = PresenceViewConnection::create($acceptKey, $userId);
            $connection->probablyOffline = $doubted;
            $state->add($connection);
        }

        $view = PresenceViewConnections::init();
        $view->setStateCollection($state);
        $view->setCollectionName(self::COLLECTION);

        return $view;
    }

    /**
     * Builds a presence-stage view over three rows, two of them one user's.
     *
     * @return PresenceViewConnections View collection over the fixture rows
     */
    private function presenceConnections(): PresenceViewConnections
    {
        $state = PresenceViewStates::init();
        $state->add(PresenceViewConnection::create('view-ak-1', 1));
        $state->add(PresenceViewConnection::create('view-ak-2', 1));
        $state->add(PresenceViewConnection::create('view-ak-3', null));

        $view = PresenceViewConnections::init();
        $view->setStateCollection($state);
        $view->setCollectionName(self::COLLECTION);

        return $view;
    }

    /**
     * Builds a session-stage view over three rows, two of them one token's.
     *
     * @return SessionViewConnections View collection over the fixture rows
     */
    private function sessionConnections(): SessionViewConnections
    {
        $state = SessionViewStates::init();
        $state->add(SessionViewConnection::create('view-ak-1', 1, 'tok-x'));
        $state->add(SessionViewConnection::create('view-ak-2', 1, 'tok-x'));
        $state->add(SessionViewConnection::create('view-ak-3', 2, 'tok-y'));

        $view = SessionViewConnections::init();
        $view->setStateCollection($state);

        return $view;
    }
}

/**
 * Presence-stage row with nothing of its own, as the two simple demos have.
 */
final class PresenceViewConnection extends StateHilosConnection
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
 * Session-stage row with nothing of its own.
 */
final class SessionViewConnection extends StateHilosSessionConnection
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
 * @extends StateHilosConnections<PresenceViewConnection>
 */
final class PresenceViewStates extends StateHilosConnections
{
    public const string STATE_CLASS = PresenceViewConnection::class;
}

/**
 * @extends StateHilosSessionConnections<SessionViewConnection>
 */
final class SessionViewStates extends StateHilosSessionConnections
{
    public const string STATE_CLASS = SessionViewConnection::class;
}

/**
 * @extends HilosConnection<PresenceViewConnection>
 */
final class PresenceViewItem extends HilosConnection
{
}

/**
 * @extends HilosSessionConnection<SessionViewConnection>
 */
final class SessionViewItem extends HilosSessionConnection
{
}

/**
 * Presence-stage view collection: the project half is the item type and nothing else.
 *
 * @extends HilosConnections<PresenceViewItem, HilosConnectionsActions>
 */
final class PresenceViewConnections extends HilosConnections
{
    /**
     * @param RtState $state Backing state row
     * @return PresenceViewItem View item over the row
     */
    protected function createRtItem(RtState $state): PresenceViewItem
    {
        /** @var PresenceViewConnection $state */
        return new PresenceViewItem($state);
    }
}

/**
 * Session-stage view collection: the project half is the item type and nothing else.
 *
 * @extends HilosSessionConnections<SessionViewItem, HilosConnectionsActions>
 */
final class SessionViewConnections extends HilosSessionConnections
{
    /**
     * @param RtState $state Backing state row
     * @return SessionViewItem View item over the row
     */
    protected function createRtItem(RtState $state): SessionViewItem
    {
        /** @var SessionViewConnection $state */
        return new SessionViewItem($state);
    }
}
