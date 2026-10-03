<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

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
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
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
            $initialIdentity = $this->identitySlotOf(Hilos::$browser?->buildSubscribeSnapshot(
                UserPage::PAGE,
                self::ACCEPT_KEY,
                new PageRouteParams($params),
            )->toArray() ?? []);
            $this->assertFalse($initialIdentity[HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
            $this->assertNull($initialIdentity[HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS]);

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

            $unverifiedIdentity = $this->identitySlotOfNextPageResponse();
            $this->assertTrue($unverifiedIdentity[HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
            $this->assertSame("survivor-{$userId}@example.test", $unverifiedIdentity[HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS]);

            $password->markVerified();
            Hilos::$browser?->record(SourceChange::dbUpdated(
                HilosDbContext::identities,
                (string) $password->id,
                [Identity::userId => $userId, Identity::verified => true],
            ));
            $failures = Hilos::$browser?->flushToSignalRouter() ?? [];
            $this->assertSame([], $failures);

            $verifiedIdentity = $this->identitySlotOfNextPageResponse();
            $this->assertTrue($verifiedIdentity[HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
            $this->assertNull($verifiedIdentity[HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS]);
        } finally {
            Hilos::$rt->connections->actions->clear();
            RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
            Hilos::$sr = null;
        }
    }

    /**
     * @return array<string, mixed> Identity slot payload from the next user-detail page response
     */
    private function identitySlotOfNextPageResponse(): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

            return $this->identitySlotOf($signal->data->data->toArray()[PageResponseSignalData::payload]);
        }

        $this->fail('The user-detail subscription answered with no page response.');
    }

    /**
     * @param array<string, mixed> $payload Page payload of a user-detail answer or update
     * @return array<string, mixed> Identity slot payload of the one user-detail row it carries
     */
    private function identitySlotOf(array $payload): array
    {
        $rows = $payload[PagePayload::tables][HilosUserDetailBrowserTable::TABLE][PagePayload::rows] ?? [];
        $this->assertCount(1, $rows);
        $identity = $rows[0][PagePayload::slots][HilosDbContext::identities] ?? null;
        $this->assertIsArray($identity);

        return $identity;
    }

    /**
     * @return bool Password-presence field from the next user-detail page response
     */
    private function passwordPresenceOfNextPageResponse(): bool
    {
        return (bool) ($this->identitySlotOfNextPageResponse()[HilosMergeCandidatesTable::FIELD_HAS_PASSWORD] ?? false);
    }
}
