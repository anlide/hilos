<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Integration coverage for durable push-device ownership and expiry state.
 */
final class PushSubscriptionsIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string AGENT = 'push-subscriptions-integration';

    /** Owner of person 41's subscription set, standing in for that person's agent. */
    private const string SET_OWNER = 'push-subscriptions-set-owner';

    /** A claim of adding and updating, the notifications library's grant on this table. */
    private const string ADD_UPDATE = 'push-subscriptions-add-update';

    private const string ENDPOINT = 'https://push.example/device-a';

    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (41, 'Owner')");
        Schema::reset();
        Schema::initialize();
        $this->previousDb = Hilos::$db;
        Hilos::$db = new PushSubscriptionsIntegrationDbContext();
        Hilos::$db->configure();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        TruthSourceRegistry::register(HilosDbContext::pushSubscriptions, TruthSourceKeys::all(), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::AGENT);
        SourceChangeBus::reset();
        Hilos::$db = $this->previousDb;
        self::runStubs(down: true);
        Schema::reset();

        parent::tearDown();
    }

    public function testSubscribeMarksGoneAndResubscribeRestoresTheEndpoint(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(
            41,
            self::ENDPOINT,
            'public-key',
            'auth-secret',
            'Mozilla/5.0 (Macintosh) Chrome/120 Safari/537.36',
        );

        $created = Database::sql('SELECT created_at, last_seen_at, gone_at, revision FROM hilos_push_subscription')->firstRow();
        self::assertSame($created['created_at'], $created['last_seen_at']);
        self::assertNull($created['gone_at']);
        self::assertSame('1', $created['revision']);
        self::assertGreaterThan(1_000_000_000_000, (int)$created['created_at']);
        $subscriptions = $this->subscriptions();
        self::assertNotNull(Database::sql(
            'SELECT `id` FROM `hilos_push_subscription` WHERE `endpoint` = ?',
            [self::ENDPOINT],
        )->firstRow());
        self::assertNotNull(Schema::getTable('hilos_push_subscription'));
        $active = $subscriptions->forUser(41);
        self::assertCount(1, $active);
        self::assertSame('Chrome on macOS', $active[0]->deviceName);
        self::assertSame(hash('sha256', self::ENDPOINT), $active[0]->endpointHash);
        self::assertSame(1, $active[0]->revision);
        self::assertSame('1', new PushDeliveryChannel()->resolveAddress(41));

        $id = $active[0]->id;
        self::assertNotNull($id);
        $revision = $active[0]->revision;
        self::assertFalse(Hilos::$db->pushSubscriptions->actions->markGoneOwned(99, $id, $revision));
        self::assertCount(1, $subscriptions->forUser(41));
        self::assertTrue(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $id, $revision));
        self::assertSame([], $subscriptions->forUser(41)->toArray());
        self::assertNull(new PushDeliveryChannel()->resolveAddress(41));
        $gone = Database::sql('SELECT gone_at FROM hilos_push_subscription')->firstRow()['gone_at'];
        self::assertGreaterThanOrEqual((int)$created['created_at'], (int)$gone);
        self::assertLessThanOrEqual(TimeHelper::nowMs(), (int)$gone);
        self::assertFalse(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $id, $revision));
        self::assertSame($gone, Database::sql('SELECT gone_at FROM hilos_push_subscription')->firstRow()['gone_at']);
        // Fixed old moments make a refreshed last-seen value observable without a clock wait.
        Hilos::$db->pushSubscriptions->getObjectCollection()[$id]->createdAt = 1767225600123;
        Hilos::$db->pushSubscriptions->getObjectCollection()[$id]->lastSeenAt = 1767225600456;
        Hilos::$db->pushSubscriptions->getObjectCollection()[$id]->sync();

        Hilos::$db->pushSubscriptions->actions->subscribe(
            41,
            self::ENDPOINT,
            'rotated-public-key',
            'rotated-auth-secret',
            'Mozilla/5.0 (Macintosh) Chrome/120 Safari/537.36',
        );
        self::assertCount(1, $subscriptions->forUser(41));
        self::assertNull($subscriptions->forUser(41)[0]->goneAt);
        self::assertSame(2, $subscriptions->forUser(41)[0]->revision);
        $resubscribed = Database::sql('SELECT id, created_at, last_seen_at, gone_at, revision FROM hilos_push_subscription')->firstRow();
        self::assertSame((string)$id, $resubscribed['id']);
        self::assertSame('1767225600123', $resubscribed['created_at']);
        self::assertGreaterThan(1767225600456, (int)$resubscribed['last_seen_at']);
        self::assertLessThanOrEqual(TimeHelper::nowMs(), (int)$resubscribed['last_seen_at']);
        self::assertNull($resubscribed['gone_at']);
        self::assertSame('2', $resubscribed['revision']);

        // A third subscribe with same keys strictly increments revision with the same id
        Hilos::$db->pushSubscriptions->actions->subscribe(
            41,
            self::ENDPOINT,
            'rotated-public-key',
            'rotated-auth-secret',
            'Mozilla/5.0 (Macintosh) Chrome/120 Safari/537.36',
        );
        $third = Database::sql('SELECT id, revision FROM hilos_push_subscription')->firstRow();
        self::assertSame((string)$id, $third['id']);
        self::assertSame('3', $third['revision']);
    }

    public function testOwnedGoneChecksTheFreshRevisionEvenWhenTheHeldDeviceHasNotReceivedTheRenewal(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $subscription = $this->subscriptions()->forUser(41)[0];
        self::assertNotNull($subscription->id);
        $id = $subscription->id;
        $capturedRevision = $subscription->revision;
        self::assertSame(1, $capturedRevision);

        // Another worker has renewed the row, while this worker still holds its captured revision.
        Database::sqlRun('UPDATE `hilos_push_subscription` SET `revision` = ? WHERE `id` = ?', [$capturedRevision + 1, $id]);
        $renewed = Database::sql('SELECT * FROM `hilos_push_subscription` WHERE `id` = ?', [$id])->firstRow();
        self::assertSame($capturedRevision, $subscription->revision);

        self::assertFalse(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $id, $capturedRevision));
        self::assertSame($renewed, Database::sql('SELECT * FROM `hilos_push_subscription` WHERE `id` = ?', [$id])->firstRow());
        self::assertFalse(Hilos::$db->pushSubscriptions->actions->markGoneOwned(99, $id, $capturedRevision + 1));
        self::assertFalse(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $id + 1, $capturedRevision));
        self::assertSame($renewed, Database::sql('SELECT * FROM `hilos_push_subscription` WHERE `id` = ?', [$id])->firstRow());
        self::assertTrue(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $id, $capturedRevision + 1));
    }

    public function testSubscribeAdvancesPersistedRevisionWhenHeldObjectLagsBehind(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $id = $this->subscriptionId();
        self::assertSame(1, $this->subscriptions()[$id]->revision);

        // Another worker updated the revision in the database to 5
        Database::sqlRun('UPDATE `hilos_push_subscription` SET `revision` = 5 WHERE `id` = ?', [$id]);

        // Stale frame with revision 1 is rejected by owner-check even though held object has revision 1
        self::assertFalse(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $id, 1));

        // Calling subscribe advances the persisted value 5 to 6, rather than held 1 to 2
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $persisted = Database::sql('SELECT revision FROM `hilos_push_subscription` WHERE `id` = ?', [$id])->firstRow();
        self::assertSame('6', $persisted['revision']);
    }

    public function testDeleteAndRecreateEndpointResetsRevisionAndRejectsOldFrame(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $oldId = $this->subscriptionId();
        self::assertTrue(Hilos::$db->pushSubscriptions->actions->removeOwned(41, $oldId));
        self::assertSame([], $this->subscriptions()->forUser(41)->toArray());

        // Recreating the same endpoint creates a new row with revision 1
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $newId = $this->subscriptionId();
        self::assertNotSame($oldId, $newId);
        $newRow = Database::sql('SELECT id, revision FROM `hilos_push_subscription` WHERE `id` = ?', [$newId])->firstRow();
        self::assertSame('1', $newRow['revision']);

        // An old gone frame with old id is rejected and does not affect the new row
        self::assertFalse(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $oldId, 1));
        self::assertCount(1, $this->subscriptions()->forUser(41));
        self::assertNull($this->subscriptions()->forUser(41)[0]->goneAt);
    }

    public function testOwnedGoneRefusesWrongRevisionAndRetainsFirstSavedMomentOnDuplicate(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $id = $this->subscriptionId();

        self::assertFalse(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $id, 999));
        self::assertTrue(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $id, 1));
        $this->subscriptions()[$id]->goneAt = 1767225600456;
        $this->subscriptions()[$id]->sync();
        self::assertFalse(Hilos::$db->pushSubscriptions->actions->markGoneOwned(41, $id, 1));
        self::assertSame('1767225600456', Database::sql('SELECT `gone_at` FROM `hilos_push_subscription` WHERE `id` = ?', [$id])
            ->firstRow()['gone_at']);
        self::assertSame([], $this->subscriptions()->forUser(41)->toArray());
    }

    public function testOwnedRemoveAndUnsubscribeCannotDeleteAnotherUsersRow(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $subscription = $this->subscriptions()->forUser(41)[0];
        self::assertNotNull($subscription->id);

        self::assertFalse(Hilos::$db->pushSubscriptions->actions->removeOwned(99, $subscription->id));
        Hilos::$db->pushSubscriptions->actions->unsubscribeOwned(99, self::ENDPOINT);
        self::assertCount(1, $this->subscriptions()->forUser(41));

        self::assertTrue(Hilos::$db->pushSubscriptions->actions->removeOwned(41, $subscription->id));
        self::assertSame([], $this->subscriptions()->forUser(41)->toArray());
    }

    /**
     * The owner of person 41's set removes that person's device; adding and updating cannot.
     *
     * @throws DatabaseException When a fixture row cannot be written or read
     */
    public function testTheOwnerOfASetRemovesItsOwnAndAnAddUpdateClaimCannot(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $id = $this->subscriptionId();
        TruthSourceRegistry::register(
            HilosDbContext::pushSubscriptions,
            TruthSourceKeys::set('41'),
            self::SET_OWNER,
        );
        TruthSourceRegistry::register(
            HilosDbContext::pushSubscriptions,
            TruthSourceKeys::all(),
            self::ADD_UPDATE,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Update),
        );

        try {
            ExecutionContext::setCurrentAgentId(self::SET_OWNER);
            self::assertTrue(Hilos::$db->pushSubscriptions->actions->removeOwned(41, $id));
            self::assertSame([], $this->subscriptions()->forUser(41)->toArray());

            ExecutionContext::setCurrentAgentId(self::AGENT);
            Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
            $again = $this->subscriptionId();

            ExecutionContext::setCurrentAgentId(self::ADD_UPDATE);
            try {
                Hilos::$db->pushSubscriptions->actions->removeOwned(41, $again);
                self::fail('An add-and-update claim must not remove a device');
            } catch (WriteNotAllowedException $refusal) {
                self::assertStringContainsString(self::ADD_UPDATE, $refusal->getMessage());
            }
            self::assertCount(1, $this->subscriptions()->forUser(41));
        } finally {
            ExecutionContext::setCurrentAgentId(self::AGENT);
            TruthSourceRegistry::unregister(HilosDbContext::pushSubscriptions, self::SET_OWNER);
            TruthSourceRegistry::unregister(HilosDbContext::pushSubscriptions, self::ADD_UPDATE);
        }
    }

    /** @return int Id of person 41's one subscription */
    private function subscriptionId(): int
    {
        $subscription = $this->subscriptions()->forUser(41)[0];
        self::assertNotNull($subscription->id);

        return $subscription->id;
    }

    /** @return ObjectPushSubscriptions Push-subscription object collection */
    private function subscriptions(): ObjectPushSubscriptions
    {
        $subscriptions = Hilos::$db?->getObjectCollection(HilosDbContext::pushSubscriptions);
        self::assertInstanceOf(ObjectPushSubscriptions::class, $subscriptions);

        return $subscriptions;
    }

    /**
     * @param bool $down Whether to run the drop stubs
     * @throws DatabaseException When a schema statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the create stub carries no filename suffix
        $suffix = $down ? '_down' : '';
        foreach ($down
            ? ['hilos_push_subscription', 'hilos_setting', 'hilos_user']
            : ['hilos_user', 'hilos_setting', 'hilos_push_subscription'] as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

final class PushSubscriptionsIntegrationDbContext extends HilosDbContext
{
}
