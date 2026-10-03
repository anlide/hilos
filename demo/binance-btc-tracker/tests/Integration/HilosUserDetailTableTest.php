<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tests\Integration;

use Demo\BinanceBtcTracker\Hilos;
use Demo\BinanceBtcTracker\Pages\Hilos\Users\UserPage;
use Demo\BinanceBtcTracker\Runtime\View\Context\BinanceBtcTrackerRtContext;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration coverage for the framework card of one person in a project that is not chat (HIL-1254).
 *
 * The demo registers the card as it is and computes none of its fields: presence and the session
 * count come out of the connections under the framework's key, and whether a password is set
 * reaches this demo's card for the first time - it used to be chat's alone.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class HilosUserDetailTableTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'ak-binance-hilos-user-detail';
    private const string TEST_AGENT_ID = 'test-binance-hilos-user-detail';

    /**
     * The card of a connected person with an unconfirmed password carries the framework's computed fields.
     *
     * @throws HilosException On database or runtime error
     */
    public function testTheCardCarriesPresenceAndThePasswordOfAConnectedPerson(): void
    {
        Hilos::$sr = new SignalRouter();
        $userId = (int) Hilos::$db->users->actions->createWithName('Card person')->id;
        Hilos::$db->users[$userId]?->actions->setAdmin(true);
        Hilos::$db->identities->createPasswordIdentity($userId, "card-{$userId}@example.test", 'correct horse battery');
        RtTruthSourceRegistry::register(BinanceBtcTrackerRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $userId);

        try {
            $snapshot = Hilos::$browser?->buildSubscribeSnapshot(
                UserPage::PAGE,
                self::ACCEPT_KEY,
                new PageRouteParams([HilosPageRouteParams::HILOS_USER_USER_ID => (string) $userId]),
            );
            $this->assertNotNull($snapshot);
            $slots = $this->cardSlotsOf($snapshot);

            $connections = $slots[HilosUserDetailBrowserTable::CONNECTIONS] ?? null;
            $this->assertIsArray($connections);
            $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $connections[HilosUserPresenceSummary::presence]);
            $this->assertSame(1, $connections[HilosUserPresenceSummary::onlineSessionCount]);

            $identities = $slots[HilosDbContext::identities] ?? null;
            $this->assertIsArray($identities);
            $this->assertTrue($identities[HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
            $this->assertSame("card-{$userId}@example.test", $identities[HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS]);
        } finally {
            Hilos::$rt->connections->actions->clear();
            RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
            Hilos::$sr = null;
        }
    }

    /**
     * @param PagePayload $snapshot Browser part of the user page's answer
     * @return array<string, mixed> Slots of the one card row it carries
     */
    private function cardSlotsOf(PagePayload $snapshot): array
    {
        $rows = $snapshot->tables[HilosUserDetailBrowserTable::TABLE][PagePayload::rows] ?? [];
        $this->assertCount(1, $rows);
        $slots = $rows[0][PagePayload::slots] ?? null;
        $this->assertIsArray($slots);

        return $slots;
    }
}
