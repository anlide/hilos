<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

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
    protected function createRtItem(RtState &$state): PresenceViewItem
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
    protected function createRtItem(RtState &$state): SessionViewItem
    {
        /** @var SessionViewConnection $state */
        return new SessionViewItem($state);
    }
}
