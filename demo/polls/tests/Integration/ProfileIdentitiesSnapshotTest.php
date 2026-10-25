<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Hilos;
use Demo\Polls\Pages\Hilos\ProfilePage;
use Demo\Polls\Runtime\View\Context\PollsRtContext;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Object\Item\Identity;
use Hilos\Database\Object\Item\PasskeyCredential;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;

/**
 * The framework profile list is present on the project profile page (HIL-1277).
 *
 * The worker has no in-memory identity rows when the person opens the profile.
 * The browser list must still query both identities from its DB source.
 */
final class ProfileIdentitiesSnapshotTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string ACCEPT_KEY = 'ak-profile-identities';

    public function testTheProfileListArrivesWhenTheWorkerHoldsNoneOfTheIdentityRows(): void
    {
        RtTruthSourceRegistry::register(PollsRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        // The harness runs no worker, so nothing has set up the router the snapshot is built against.
        Hilos::$sr = new SignalRouter();

        try {
            $userId = (int) Hilos::$db->users->actions->createWithName('Profile owner')->id;
            $oauth = Hilos::$db->identities->createOauthIdentity($userId, 'github', 'subject-' . $userId);
            $sms = Hilos::$db->identities->createSmsIdentity($userId, '+1000000' . $userId);
            Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $userId);

            // The worker has not fetched either identity by key.
            Hilos::$db->getObjectCollection(PollsDbContext::identities)?->clearInMemory();
            $snapshot = Hilos::$browser?->buildSubscribeSnapshot(
                ProfilePage::PAGE,
                self::ACCEPT_KEY,
                new PageRouteParams([]),
            );
            $this->assertNotNull($snapshot);

            $this->assertSame(
                [(int) $oauth->id, (int) $sms->id],
                array_map(
                    static fn (array $identity): int => (int) $identity[Identity::id],
                    $this->identitiesOfSnapshotItem($snapshot),
                ),
            );
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$sr = null;
        }
    }

    public function testPasskeyEnrollmentUsesMillisecondsInInitialAndLiveRows(): void
    {
        RtTruthSourceRegistry::register(PollsRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = new SignalRouter();
        Hilos::$sr->subscribeToPage(
            ProfilePage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, ProfilePage::PAGE),
        );

        try {
            $userId = (int)Hilos::$db->users->actions->createWithName('Passkey clock owner')->id;
            $identity = Hilos::$db->identities->createPasskeyIdentity($userId, 'clock-credential-' . $userId);
            $credential = Hilos::$db->passkeyCredentials->createFromRegistration(
                (int)$identity->id,
                $userId,
                'clock-credential-' . $userId,
                'private-key-material',
                PasskeyAlgorithm::Es256,
                0,
                null,
                null,
                'private-user-handle',
                'Laptop',
            );
            // Fixture-only enrollment clock: the normal registration API always stamps now.
            $credential->createdAt = '2026-01-01 00:30:00';
            $credential->sync();
            Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $userId);
            $snapshot = Hilos::$browser?->buildSubscribeSnapshot(ProfilePage::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));
            self::assertNotNull($snapshot);
            $rows = $snapshot->lists[HilosProfileIdentitiesBrowserList::LIST][PagePayload::items][0]
                [PagePayload::slots][PollsDbContext::passkeyCredentials];
            self::assertSame([
                [
                    PasskeyCredential::id => (int)$credential->id,
                    PasskeyCredential::userId => $userId,
                    PasskeyCredential::identityId => (int)$identity->id,
                    PasskeyCredential::label => 'Laptop',
                    PasskeyCredential::createdAt => 1767227400000,
                ],
            ], $rows);
            foreach ($this->identitiesOfSnapshotItem($snapshot) as $row) {
                self::assertArrayNotHasKey('secret', $row);
            }
            $this->drainSignals();

            $credential->createdAt = '2026-01-02 00:30:00';
            $credential->sync();
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($signal->data instanceof DbSyncUpdatedSignalData && $signal->data->collectionKey === PollsDbContext::passkeyCredentials) {
                    Hilos::$browser?->record(SourceChange::dbUpdated(
                        $signal->data->collectionKey,
                        $signal->data->idString,
                        $signal->data->row,
                        previous: $signal->data->previous,
                    ));
                }
            }
            Hilos::$browser?->flushToSignalRouter();
            $liveRows = null;
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                    continue;
                }
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                $liveRows = $signal->data->data->payload->lists[HilosProfileIdentitiesBrowserList::LIST][PagePayload::items][0]
                    [PagePayload::slots][PollsDbContext::passkeyCredentials];
            }
            self::assertSame([array_replace($rows[0], [PasskeyCredential::createdAt => 1767313800000])], $liveRows);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$sr = null;
        }
    }

    /**
     * Reads the identities block of the one item the profile list answers with.
     *
     * @param PagePayload $snapshot Browser part of the profile page's answer
     * @return list<array<string, mixed>> Projected identity fragments
     */
    private function identitiesOfSnapshotItem(PagePayload $snapshot): array
    {
        $items = $snapshot->lists[HilosProfileIdentitiesBrowserList::LIST][PagePayload::items];
        $this->assertCount(1, $items);

        return $items[0][PagePayload::slots][PollsDbContext::identities];
    }
}
