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

        self::assertSame(1, Hilos::$db->pushSubscriptions->actions->markGone([self::ENDPOINT]));
        self::assertSame([], $subscriptions->forUser(41));

        Hilos::$db->pushSubscriptions->actions->subscribe(
            41,
            self::ENDPOINT,
            'rotated-public-key',
            'rotated-auth-secret',
            'Mozilla/5.0 (Macintosh) Chrome/120 Safari/537.36',
        );
        self::assertCount(1, $subscriptions->forUser(41));
        self::assertNull($subscriptions->forUser(41)[0]->goneAt);
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
        self::assertSame([], $this->subscriptions()->forUser(41));
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
            self::assertSame([], $this->subscriptions()->forUser(41));

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
