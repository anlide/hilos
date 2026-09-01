<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Browser\List\ProfileIdentitiesBrowserList;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Object\Item\Identity;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * The reported symptom itself: the profile's identity list arrives (HIL-781).
 *
 * Six rows in hilos_identity, an empty list on the screen, nothing in the log. The list joins
 * identities in by their owner's id, and that join used to be a walk over the identities
 * collection - which is key-lazy, so it answers with the rows this worker fetched by key and
 * with nothing else. On a worker that fetched none of them, that is an empty list nobody
 * refused.
 *
 * The case therefore empties the collection before subscribing: that is the state of a worker
 * the person's identities never passed through, and it is the state the profile was opened in.
 */
final class ProfileIdentitiesSnapshotTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string ACCEPT_KEY = 'ak-profile-identities';

    public function testTheProfileListArrivesWhenTheWorkerHoldsNoneOfTheIdentityRows(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        // The harness runs no worker, so nothing has queued the router the snapshot answers into.
        Hilos::$sr = new SignalRouter();

        try {
            $userId = (int) Hilos::$db->users->actions->createWithName('Profile owner')->id;
            $oauth = Hilos::$db->identities->createOauthIdentity($userId, 'github', 'subject-' . $userId);
            $sms = Hilos::$db->identities->createSmsIdentity($userId, '+1000000' . $userId);
            Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $userId);

            // What a worker the rows never passed through holds, which is what the profile met.
            Hilos::$db->getObjectCollection(ChatDbContext::identities)?->clearInMemory();

            Hilos::$browser?->subscribeSnapshot(
                ProfilePage::PAGE,
                self::ACCEPT_KEY,
                new PageRouteParams([]),
            );

            $this->assertSame(
                [(int) $oauth->id, (int) $sms->id],
                array_map(
                    static fn (array $identity): int => (int) $identity[Identity::id],
                    $this->identitiesOfSnapshotItem(),
                ),
            );
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$sr = null;
        }
    }

    /**
     * Reads the identities block of the one item the profile list answers with.
     *
     * @return list<array<string, mixed>> Projected identity fragments
     */
    private function identitiesOfSnapshotItem(): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $payload = $signal->data->data->toArray()[PageResponseSignalData::payload];
            $items = $payload[PagePayload::lists][ProfileIdentitiesBrowserList::LIST][PagePayload::items];
            $this->assertCount(1, $items);

            return $items[0][PagePayload::slots][ChatDbContext::identities];
        }

        $this->fail('The profile subscription answered with no page response.');
    }
}
