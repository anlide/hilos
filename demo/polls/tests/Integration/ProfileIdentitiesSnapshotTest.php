<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Hilos;
use Demo\Polls\Pages\Hilos\ProfilePage;
use Demo\Polls\Runtime\View\Context\PollsRtContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Object\Item\Identity;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\TruthSource\RtTruthSourceRegistry;

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
