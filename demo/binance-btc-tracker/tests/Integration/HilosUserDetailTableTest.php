<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tests\Integration;

use Demo\BinanceBtcTracker\Hilos;
use Demo\BinanceBtcTracker\Pages\Hilos\Users\UserPage;
use Demo\BinanceBtcTracker\Runtime\View\Context\BinanceBtcTrackerRtContext;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

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
        Hilos::$db->users[$userId]->actions->setAdmin(true);
        Hilos::$db->users[$userId]->actions->setTimeZone('Pacific/Honolulu');
        Hilos::$db->identities->createPasswordIdentity($userId, "card-{$userId}@example.test", 'correct horse battery');
        $sessionToken = RandomHelper::hex(16);
        $sessionId = (int) Hilos::$db->sessions->actions->createAnonymous($sessionToken)->id;
        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        RtTruthSourceRegistry::register(BinanceBtcTrackerRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $userId, $sessionToken, $sessionId);
        Hilos::$rt->connections->actions->register('card-second-tab', $userId, $sessionToken, $sessionId);
        Hilos::$rt->connections->actions->register('card-third-tab', $userId, $sessionToken, $sessionId);

        try {
            $snapshot = Hilos::$browser?->buildSubscribeSnapshot(
                UserPage::PAGE,
                self::ACCEPT_KEY,
                new PageRouteParams([HilosPageRouteParams::HILOS_USER_USER_ID => (string) $userId]),
            );
            $this->assertNotNull($snapshot);
            $slots = $this->cardSlotsOf($snapshot);

            $storedActivity = Database::sql('SELECT `last_activity` FROM `hilos_user` WHERE `id` = ?', [$userId])->rows()[0]['last_activity'];
            $this->assertIsString($storedActivity);
            $this->assertSame((int)$storedActivity, $slots[HilosDbContext::users]['lastActivity']);
            $this->assertIsInt($slots[HilosDbContext::users]['lastActivity']);
            $this->assertSame('Pacific/Honolulu', $slots[HilosDbContext::users][ObjectUser::timeZone]);

            $connections = $slots[HilosUserDetailBrowserTable::CONNECTIONS] ?? null;
            $this->assertIsArray($connections);
            $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $connections[HilosUserPresenceSummary::presence]);
            $this->assertSame(3, $connections[HilosUserPresenceSummary::onlineSessionCount]);
            $this->assertSame(1, $connections[HilosUserPresenceSummary::openSessionCount]);
            $this->assertSame([
                HilosConnection::userId,
                HilosUserPresenceSummary::presence,
                HilosUserPresenceSummary::onlineSessionCount,
                HilosUserPresenceSummary::openSessionCount,
            ], array_keys($connections));

            $identities = $slots[HilosDbContext::identities] ?? null;
            $this->assertIsArray($identities);
            $this->assertTrue($identities[HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
            $this->assertSame("card-{$userId}@example.test", $identities[HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS]);

            Hilos::$db->users[$userId]->actions->setTimeZone(null);
            $automaticSnapshot = Hilos::$browser?->buildSubscribeSnapshot(
                UserPage::PAGE,
                self::ACCEPT_KEY,
                new PageRouteParams([HilosPageRouteParams::HILOS_USER_USER_ID => (string) $userId]),
            );
            $this->assertNotNull($automaticSnapshot);
            $automaticSlots = $this->cardSlotsOf($automaticSnapshot);
            $this->assertNull($automaticSlots[HilosDbContext::users][ObjectUser::timeZone]);
        } finally {
            Hilos::$rt->connections->actions->clear();
            RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
            Hilos::$sr = null;
        }
    }

    public function testLastCloseAndFreshSnapshotKeepTheKnownZeroConnectionsFragment(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::initBrowser();
        $userId = (int) Hilos::$db->users->actions->createWithName('Closing person')->id;
        $viewerId = (int) Hilos::$db->users->actions->createWithName('Card administrator')->id;
        Hilos::$db->users[$viewerId]->actions->setAdmin(true);
        $sessionToken = RandomHelper::hex(16);
        $sessionId = (int) Hilos::$db->sessions->actions->createAnonymous($sessionToken)->id;
        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        RtTruthSourceRegistry::register(BinanceBtcTrackerRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $viewerId);
        Hilos::$rt->connections->actions->register('closing-tab', $userId, $sessionToken, $sessionId);
        $params = new PageRouteParams([HilosPageRouteParams::HILOS_USER_USER_ID => (string)$userId]);

        try {
            $initial = Hilos::$browser?->buildSubscribeSnapshot(UserPage::PAGE, self::ACCEPT_KEY, $params);
            $this->assertNotNull($initial);
            $this->assertSame(1, $this->cardSlotsOf($initial)[HilosUserDetailBrowserTable::CONNECTIONS][
                HilosUserPresenceSummary::openSessionCount
            ]);
            Hilos::$sr->subscribeToPage(UserPage::PAGE, new WebSocketPageSubscribeSignalDTO(
                self::ACCEPT_KEY, UserPage::PAGE, $params->toArray(),
            ));
            $this->drainSignals();

            Hilos::$rt->connections['closing-tab']->actions->unregister();
            Hilos::$browser?->record(SourceChange::rtDeleted(
                BinanceBtcTrackerRtContext::connections,
                'closing-tab',
                [HilosConnection::userId => $userId],
            ));
            Hilos::$browser?->flushToSignalRouter();
            $livePayloads = [];
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($signal->data instanceof WebSocketSignalData
                    && $signal->data->targetAcceptKey === self::ACCEPT_KEY
                    && $signal->data->data instanceof PageResponseSignalData
                    && $signal->data->data->pageKey === UserPage::PAGE
                ) {
                    $livePayloads[] = $signal->data->data->payload;
                }
            }
            $this->assertCount(1, $livePayloads);
            $expected = [
                HilosConnection::userId => $userId,
                HilosUserPresenceSummary::presence => HilosUserPresenceSummary::PRESENCE_OFFLINE,
                HilosUserPresenceSummary::onlineSessionCount => 0,
                HilosUserPresenceSummary::openSessionCount => 0,
            ];
            $this->assertSame(
                $expected,
                $this->cardSlotsOf($livePayloads[0])[HilosUserDetailBrowserTable::CONNECTIONS],
            );

            $fresh = Hilos::$browser?->buildSubscribeSnapshot(UserPage::PAGE, self::ACCEPT_KEY, $params);
            $this->assertNotNull($fresh);
            $this->assertSame($expected, $this->cardSlotsOf($fresh)[HilosUserDetailBrowserTable::CONNECTIONS]);
        } finally {
            Hilos::$rt->connections->actions->clear();
            RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
            Hilos::initBrowser();
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
