<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\TruthSourceKeys;
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
    private const string ENDPOINT = 'https://push.example/device-a';

    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);
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
        foreach (['hilos_setting', 'hilos_push_subscription'] as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

final class PushSubscriptionsIntegrationDbContext extends HilosDbContext
{
}
