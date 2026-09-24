<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Identity;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Users\AbstractHilosMergeCandidatesTable;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Integration coverage for password presence on the Hilos user detail row. */
final class HilosUserDetailPasswordPresenceTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'ak-hilos-user-password-presence';
    private const string TEST_AGENT_ID = 'test-hilos-user-password-presence';

    public function testPasswordPresenceArrivesAndIsRecomputedWhenAnIdentityChanges(): void
    {
        Hilos::$sr = new SignalRouter();
        $userId = (int) Hilos::$db->users->actions->createWithName('Merge survivor')->id;
        Hilos::$db->users[$userId]?->actions->setAdmin(true);
        Hilos::$db->identities->createMagicLinkIdentity($userId, "survivor-{$userId}@example.test");
        $params = [HilosPageRouteParams::HILOS_USER_USER_ID => (string) $userId];
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $userId);

        try {
            Hilos::$browser?->subscribeSnapshot(
                UserPage::PAGE,
                self::ACCEPT_KEY,
                new PageRouteParams($params),
            );
            $this->assertFalse($this->passwordPresenceOfNextPageResponse());

            Hilos::$sr->subscribeToPage(
                UserPage::PAGE,
                new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, UserPage::PAGE, $params),
            );
            $password = Hilos::$db->identities->createPasswordIdentity(
                $userId,
                "survivor-{$userId}@example.test",
                'correct horse battery',
            );
            Hilos::$browser?->record(SourceChange::dbCreated(
                HilosDbContext::identities,
                (string) $password->id,
                [Identity::userId => $userId],
            ));
            $failures = Hilos::$browser?->flushToSignalRouter() ?? [];
            $this->assertSame([], $failures);

            $this->assertTrue($this->passwordPresenceOfNextPageResponse());
        } finally {
            Hilos::$rt->connections->actions->clear();
            RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
            Hilos::$sr = null;
        }
    }

    /**
     * @return bool Password-presence field from the next user-detail page response
     */
    private function passwordPresenceOfNextPageResponse(): bool
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $payload = $signal->data->data->toArray()[PageResponseSignalData::payload];
            $rows = $payload[PagePayload::tables][ChatBrowserTable::USER_DETAIL][PagePayload::rows] ?? [];
            $this->assertCount(1, $rows);
            $identity = $rows[0][PagePayload::slots][HilosDbContext::identities] ?? null;
            $this->assertIsArray($identity);

            return $identity[AbstractHilosMergeCandidatesTable::FIELD_HAS_PASSWORD] ?? false;
        }

        $this->fail('The user-detail subscription answered with no page response.');
    }
}
