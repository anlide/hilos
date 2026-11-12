<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration coverage for durable push-device ownership and expiry state.
 */
final class PushSubscriptionsIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string AGENT = 'push-subscriptions-integration';

    /** Owner of person 41's subscription set, standing in for that person's agent. */
    private const string SET_OWNER = 'push-subscriptions-set-owner';

    /** A whole-table claim of adding and updating, which stops short of removing a device. */
    private const string ADD_UPDATE = 'push-subscriptions-add-update';

    private const string ENDPOINT = 'https://push.example/device-a';

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousRouter = null;

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
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
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
        Hilos::$sr = $this->previousRouter;
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

        Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(
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
        Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(
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

        // Renewal advances the persisted value 5 to 6, rather than held 1 to 2.
        self::assertTrue(Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(41, self::ENDPOINT, 'pk', 'auth', null));
        $persisted = Database::sql('SELECT revision FROM `hilos_push_subscription` WHERE `id` = ?', [$id])->firstRow();
        self::assertSame('6', $persisted['revision']);
    }

    /**
     * Exercises the persistence primitive with a deliberately stale held baseline. The interleaving
     * is shorter than a stand tick, so this does not claim to reproduce two live workers.
     */
    public function testRenewalClearsIndependentlyPersistedGoneWithAStaleNullBaseline(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $id = $this->subscriptionId();
        $held = $this->subscriptions()[$id];
        self::assertNull($held->goneAt);
        self::assertSame(1, $held->revision);

        Database::sqlRun(
            'UPDATE `hilos_push_subscription` SET `gone_at` = ?, `revision` = ? WHERE `id` = ?',
            [1767225600456, 5, $id],
        );
        self::assertNull($held->goneAt);
        self::assertSame(1, $held->revision);

        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $changes = new PushRenewalChanges();
        SourceChangeBus::subscribe($changes);
        self::assertTrue(Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(41, self::ENDPOINT, 'pk', 'auth', null));

        $saved = Database::sql('SELECT gone_at, revision FROM `hilos_push_subscription` WHERE `id` = ?', [$id])->firstRow();
        self::assertNull($saved['gone_at']);
        self::assertSame('6', $saved['revision']);
        self::assertCount(1, $changes->changes);
        self::assertNull($changes->changes[0]->row['gone_at']);
        self::assertSame(1767225600456, $changes->changes[0]->previous['gone_at']);
        self::assertSame(6, $changes->changes[0]->row['revision']);
        self::assertSame(5, $changes->changes[0]->previous['revision']);
        self::assertSame($held, $this->subscriptions()[$id]);
        $sync = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($sync);
        self::assertSame(SignalConstants::DB_SYNC_UPDATED, $sync->signalName->getName());
        self::assertInstanceOf(DbSyncUpdatedSignalData::class, $sync->data);
        self::assertSame($changes->changes[0]->row, $sync->data->row);
        self::assertSame($changes->changes[0]->previous, $sync->data->previous);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
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

    public function testReleaseRemovesTheOwnersOwnRowAndAnnouncesTheDelete(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $id = $this->subscriptionId();
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }

        self::assertTrue(Hilos::$db->pushSubscriptions->actions->releaseOwned(41, $id, self::ENDPOINT));

        self::assertNull(Database::sql('SELECT `id` FROM `hilos_push_subscription` WHERE `id` = ?', [$id])->firstRow());
        self::assertSame([], $this->subscriptions()->forUser(41)->toArray());
        $sync = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($sync);
        self::assertSame(SignalConstants::DB_SYNC_DELETED, $sync->signalName->getName());
        self::assertInstanceOf(DbSyncDeletedSignalData::class, $sync->data);
        self::assertSame(HilosDbContext::pushSubscriptions, $sync->data->collectionKey);
        self::assertSame((string)$id, $sync->data->idString);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * A missing row, a row the saved state gives to another person and a row at another endpoint
     * are left alone, whatever the held object still says.
     *
     * @throws DatabaseException When a fixture row cannot be written or read
     */
    public function testReleaseLeavesAMissingForeignOrMovedRowAlone(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        Database::sqlRun("INSERT INTO hilos_user (id, name) VALUES (99, 'Other')");
        $id = $this->subscriptionId();
        $before = Database::sql('SELECT * FROM hilos_push_subscription')->firstRow();

        self::assertFalse(Hilos::$db->pushSubscriptions->actions->releaseOwned(41, $id + 1, self::ENDPOINT));
        self::assertFalse(Hilos::$db->pushSubscriptions->actions->releaseOwned(41, $id, 'https://push.example/device-b'));
        self::assertSame($before, Database::sql('SELECT * FROM hilos_push_subscription')->firstRow());

        self::assertSame(41, $this->subscriptions()[$id]->userId);
        Database::sqlRun('UPDATE `hilos_push_subscription` SET `user_id` = ? WHERE `id` = ?', [99, $id]);
        $foreign = Database::sql('SELECT * FROM hilos_push_subscription')->firstRow();
        self::assertFalse(Hilos::$db->pushSubscriptions->actions->releaseOwned(41, $id, self::ENDPOINT));
        self::assertSame($foreign, Database::sql('SELECT * FROM hilos_push_subscription')->firstRow());
    }

    public function testNeitherACreatorNorAnotherSetOwnerCanRelease(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $id = $this->subscriptionId();
        $before = Database::sql('SELECT * FROM hilos_push_subscription')->firstRow();
        TruthSourceRegistry::registerCreate(HilosDbContext::pushSubscriptions, 'creator-only');
        TruthSourceRegistry::register(HilosDbContext::pushSubscriptions, TruthSourceKeys::set('99'), 'foreign-owner');
        TruthSourceRegistry::register(HilosDbContext::pushSubscriptions, TruthSourceKeys::set('41'), self::SET_OWNER);
        try {
            foreach (['creator-only', 'foreign-owner'] as $writer) {
                ExecutionContext::setCurrentAgentId($writer);
                try {
                    Hilos::$db->pushSubscriptions->actions->releaseOwned(41, $id, self::ENDPOINT);
                    self::fail('A creator or a foreign owner must not release this row');
                } catch (WriteNotAllowedException $refusal) {
                    self::assertStringContainsString($writer, $refusal->getMessage());
                }
                self::assertSame($before, Database::sql('SELECT * FROM hilos_push_subscription')->firstRow());
            }
            ExecutionContext::setCurrentAgentId(self::SET_OWNER);
            self::assertTrue(Hilos::$db->pushSubscriptions->actions->releaseOwned(41, $id, self::ENDPOINT));
            self::assertNull(Database::sql('SELECT * FROM hilos_push_subscription')->firstRow());
        } finally {
            ExecutionContext::setCurrentAgentId(self::AGENT);
            foreach (['creator-only', 'foreign-owner', self::SET_OWNER] as $writer) {
                TruthSourceRegistry::unregisterAgent($writer);
            }
        }
    }

    public function testReleaseOfAHeldDeviceDeletedOutsideAnswersFalseWithoutWriting(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $id = $this->subscriptionId();
        self::assertSame(self::ENDPOINT, $this->subscriptions()[$id]->endpoint);
        Database::sqlRun('DELETE FROM `hilos_push_subscription` WHERE `id` = ?', [$id]);
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }

        self::assertFalse(Hilos::$db->pushSubscriptions->actions->releaseOwned(41, $id, self::ENDPOINT));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testCreatorCollisionLeavesBothOwnedAndForeignDevicesUntouched(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $before = Database::sql('SELECT * FROM hilos_push_subscription')->firstRow();
        foreach ([41, 99] as $userId) {
            try {
                Hilos::$db->pushSubscriptions->actions->subscribe($userId, self::ENDPOINT, 'new', 'new', 'new');
                self::fail('The creator must refuse an existing endpoint');
            } catch (ValidationException $refusal) {
                self::assertSame('Push subscription already exists', $refusal->getMessage());
            }
            self::assertSame($before, Database::sql('SELECT * FROM hilos_push_subscription')->firstRow());
        }
    }

    public function testOnlyTheOwnedSetCanRenew(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        Database::sqlRun("INSERT INTO hilos_user (id, name) VALUES (99, 'Other')");
        $id = $this->subscriptionId();
        $before = Database::sql('SELECT * FROM hilos_push_subscription')->firstRow();
        TruthSourceRegistry::registerCreate(HilosDbContext::pushSubscriptions, 'creator-only');
        TruthSourceRegistry::register(HilosDbContext::pushSubscriptions, TruthSourceKeys::set('99'), 'foreign-owner');
        TruthSourceRegistry::register(HilosDbContext::pushSubscriptions, TruthSourceKeys::set('41'), self::SET_OWNER);
        try {
            foreach (['creator-only', 'foreign-owner'] as $writer) {
                ExecutionContext::setCurrentAgentId($writer);
                try {
                    Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(41, self::ENDPOINT, 'new', 'new', null);
                    self::fail('A creator or a foreign owner must not renew this row');
                } catch (WriteNotAllowedException $refusal) {
                    self::assertStringContainsString($writer, $refusal->getMessage());
                }
                self::assertSame($before, Database::sql('SELECT * FROM hilos_push_subscription')->firstRow());
            }
            ExecutionContext::setCurrentAgentId(self::SET_OWNER);
            self::assertTrue(Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(41, self::ENDPOINT, 'new', 'new', null));
            self::assertSame('2', Database::sql('SELECT revision FROM hilos_push_subscription')->firstRow()['revision']);
        } finally {
            ExecutionContext::setCurrentAgentId(self::AGENT);
            foreach (['creator-only', 'foreign-owner', self::SET_OWNER] as $writer) {
                TruthSourceRegistry::unregisterAgent($writer);
            }
        }
    }

    /**
     * Exercises the persistence primitive before a pending owner-change sync arrives. The
     * interleaving is shorter than a stand tick, so it does not claim to reproduce two live workers.
     */
    public function testFreshSetOwnerCanRenewBeforeTheHeldOwnerReceivesDbSync(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        Database::sqlRun("INSERT INTO hilos_user (id, name) VALUES (99, 'New owner')");
        $id = $this->subscriptionId();
        $held = $this->subscriptions()[$id];
        Database::sqlRun(
            'UPDATE hilos_push_subscription SET user_id = 99, revision = 5, gone_at = ?, last_seen_at = ? WHERE id = ?',
            [1767225600456, 1767225600123, $id],
        );
        $before = Database::sql('SELECT * FROM hilos_push_subscription WHERE id = ?', [$id])->firstRow();
        $identity = Hilos::$db->pushSubscriptions->readIdentityByEndpoint(self::ENDPOINT);
        self::assertNotNull($identity);
        self::assertSame($id, $identity->subscriptionId);
        self::assertSame(99, $identity->userId);
        self::assertSame(41, $held->userId);
        self::assertSame(1, $held->revision);
        self::assertNull($held->goneAt);
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $changes = new PushRenewalChanges();
        SourceChangeBus::subscribe($changes);
        TruthSourceRegistry::register(
            HilosDbContext::pushSubscriptions,
            TruthSourceKeys::set('99'),
            self::SET_OWNER,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        try {
            self::assertTrue(ExecutionContext::run(
                new ExecutionFrame(self::SET_OWNER, 'renewal-connection', 'renewal-request'),
                static fn (): bool => Hilos::$db->pushSubscriptions[$identity->subscriptionId]->actions->renewOwned(
                    $identity->userId,
                    self::ENDPOINT,
                    'renewed-key',
                    'renewed-auth',
                    'Mozilla/5.0 (Macintosh) Chrome/120 Safari/537.36',
                ),
            ));
        } finally {
            TruthSourceRegistry::unregisterAgent(self::SET_OWNER);
        }

        $saved = Database::sql('SELECT * FROM hilos_push_subscription WHERE id = ?', [$id])->firstRow();
        foreach (['id', 'user_id', 'endpoint', 'endpoint_hash', 'created_at'] as $column) {
            self::assertSame($before[$column], $saved[$column]);
        }
        self::assertSame('renewed-key', $saved['p256dh']);
        self::assertSame('renewed-auth', $saved['auth']);
        self::assertSame('Mozilla/5.0 (Macintosh) Chrome/120 Safari/537.36', $saved['user_agent']);
        self::assertSame('Chrome on macOS', $saved['device_name']);
        self::assertSame('6', $saved['revision']);
        self::assertNull($saved['gone_at']);
        self::assertGreaterThan(1767225600123, (int)$saved['last_seen_at']);
        self::assertLessThanOrEqual(TimeHelper::nowMs(), (int)$saved['last_seen_at']);
        self::assertSame($held, $this->subscriptions()[$id]);
        self::assertSame(99, $held->userId);
        self::assertSame(6, $held->revision);
        self::assertNull($held->goneAt);
        self::assertCount(1, $changes->changes);
        self::assertSame(5, $changes->changes[0]->previous['revision']);
        self::assertSame(6, $changes->changes[0]->row['revision']);
        self::assertSame(1767225600456, $changes->changes[0]->previous['gone_at']);
        self::assertNull($changes->changes[0]->row['gone_at']);
        self::assertArrayNotHasKey('user_id', $changes->changes[0]->row);
        self::assertSame('renewal-connection', $changes->changes[0]->origin);
        self::assertSame('renewal-request', $changes->changes[0]->originRequestId);
        $sync = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($sync);
        self::assertSame(SignalConstants::DB_SYNC_UPDATED, $sync->signalName->getName());
        self::assertInstanceOf(DbSyncUpdatedSignalData::class, $sync->data);
        self::assertSame($changes->changes[0]->row, $sync->data->row);
        self::assertSame($changes->changes[0]->previous, $sync->data->previous);
        self::assertSame('renewal-connection', $sync->data->origin);
        self::assertSame('renewal-request', $sync->data->originRequestId);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Keeps a stale owner in memory while the persisted owner is already different, within
     * the shorter-than-a-stand-tick window exercised by the renewal persistence primitive.
     *
     * @param ?string $setKey Writer's set, or null for an Add-only creator
     */
    #[DataProvider('staleOwnerRefusals')]
    public function testStaleHeldOwnerDoesNotGrantRenewal(?string $setKey): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        Database::sqlRun("INSERT INTO hilos_user (id, name) VALUES (99, 'New owner')");
        $id = $this->subscriptionId();
        Database::sqlRun('UPDATE hilos_push_subscription SET user_id = 99, revision = 5 WHERE id = ?', [$id]);
        self::assertSame(41, Hilos::$db->pushSubscriptions[$id]->userId);
        $before = Database::sql('SELECT * FROM hilos_push_subscription WHERE id = ?', [$id])->firstRow();
        if ($setKey === null) {
            TruthSourceRegistry::registerCreate(HilosDbContext::pushSubscriptions, self::SET_OWNER);
        } else {
            TruthSourceRegistry::register(
                HilosDbContext::pushSubscriptions,
                TruthSourceKeys::set($setKey),
                self::SET_OWNER,
                TruthSourceOperations::of(TruthSourceOperation::Update),
            );
        }
        try {
            try {
                ExecutionContext::run(new ExecutionFrame(self::SET_OWNER), static function () use ($id): void {
                    Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(99, self::ENDPOINT, 'new', 'new', null);
                });
                self::fail('A stale held owner must not permit a foreign write');
            } catch (WriteNotAllowedException $refusal) {
                self::assertStringContainsString(self::SET_OWNER, $refusal->getMessage());
            }
            self::assertSame($before, Database::sql('SELECT * FROM hilos_push_subscription WHERE id = ?', [$id])->firstRow());
        } finally {
            TruthSourceRegistry::unregisterAgent(self::SET_OWNER);
        }
    }

    /** @return array<string, array{?string}> Claims whose renewal must remain refused */
    public static function staleOwnerRefusals(): array
    {
        return [
            'Add-only renewal' => [null],
            'old-set renewal' => ['41'],
            'unrelated-set renewal' => ['100'],
        ];
    }

    public function testEachAcceptedRenewalAdvancesRevisionEvenWhenKeysAndSavedMomentMatch(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $id = $this->subscriptionId();
        $moment = Hilos::$db->pushSubscriptions[$id]->lastSeenAt;
        for ($revision = 2; $revision <= 3; ++$revision) {
            // The fixed persisted moment equals the held one; only revision is guaranteed to change.
            Database::sqlRun('UPDATE hilos_push_subscription SET last_seen_at = ? WHERE id = ?', [$moment, $id]);
            self::assertTrue(Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(41, self::ENDPOINT, 'pk', 'auth', null));
            $saved = Database::sql('SELECT revision, last_seen_at FROM hilos_push_subscription')->firstRow();
            self::assertSame((string)$revision, $saved['revision']);
            self::assertGreaterThanOrEqual($moment, (int)$saved['last_seen_at']);
        }
    }

    public function testCapturedItemCannotRenewARecreatedEndpoint(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $id = $this->subscriptionId();
        $old = Hilos::$db->pushSubscriptions[$id];
        self::assertTrue(Hilos::$db->pushSubscriptions->actions->removeOwned(41, $id));
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        $before = Database::sql('SELECT * FROM hilos_push_subscription')->firstRow();
        self::assertNotSame((string)$id, $before['id']);
        self::assertFalse($old->actions->renewOwned(41, self::ENDPOINT, 'new', 'new', null));
        self::assertSame($before, Database::sql('SELECT * FROM hilos_push_subscription')->firstRow());
    }

    public function testRenewalRequiresTheFreshOwnedIdentityAndPreservesTheRow(): void
    {
        Hilos::$db->pushSubscriptions->actions->subscribe(41, self::ENDPOINT, 'pk', 'auth', null);
        Database::sqlRun("INSERT INTO hilos_user (id, name) VALUES (99, 'Other')");
        $id = $this->subscriptionId();
        $before = Database::sql('SELECT * FROM hilos_push_subscription')->firstRow();
        self::assertSame($id, Hilos::$db->pushSubscriptions->readIdentityByEndpoint(self::ENDPOINT)->subscriptionId);
        self::assertSame(41, Hilos::$db->pushSubscriptions->readIdentityByEndpoint(self::ENDPOINT)->userId);
        self::assertNull(Hilos::$db->pushSubscriptions->readIdentityByEndpoint('https://push.example/missing'));
        self::assertFalse(Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(99, self::ENDPOINT, 'new', 'new', null));
        self::assertFalse(Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(41, self::ENDPOINT . '/wrong', 'new', 'new', null));
        self::assertSame($before, Database::sql('SELECT * FROM hilos_push_subscription')->firstRow());
        // The held owner lags the persisted owner, which input dispatch must read fresh.
        Database::sqlRun('UPDATE hilos_push_subscription SET user_id = 99, revision = 5 WHERE id = ?', [$id]);
        $moved = Database::sql('SELECT * FROM hilos_push_subscription')->firstRow();
        self::assertSame(41, Hilos::$db->pushSubscriptions[$id]->userId);
        self::assertSame(99, Hilos::$db->pushSubscriptions->readIdentityByEndpoint(self::ENDPOINT)->userId);
        self::assertFalse(Hilos::$db->pushSubscriptions[$id]->actions->renewOwned(41, self::ENDPOINT, 'new', 'new', null));
        self::assertSame($moved, Database::sql('SELECT * FROM hilos_push_subscription')->firstRow());
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

/** Records the ordinary local source changes without replacing the persistence path. */
final class PushRenewalChanges implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Observed changes */
    public array $changes = [];

    /**
     * @param SourceChange $change Persisted source fact
     * @param SourceChangeProvenance $provenance Origin of that fact
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->changes[] = $change;
    }
}
