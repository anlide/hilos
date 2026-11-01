<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\List\UserProfileBrowserList;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Pages\UserPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Object\Item\User;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration coverage for UserPage profile browser list and delta handling.
 */
final class UserPageTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'user-page-guest';

    private const string CONNECTIONS_OWNER = 'user-page-connections';

    private ?ChatAgent $chat = null;

    /** @var list<int> Users this case created, removed with it */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::initSignalRouter(new SignalRouter());
        $this->chat = new ChatAgent();
        $this->startAgent($this->chat);
        $this->holdConnections();
        ExecutionContext::setCurrentAgentId(null);
        $this->userIds = [];
    }

    protected function tearDown(): void
    {
        $this->releaseGuest();
        RtTruthSourceRegistry::unregisterAgent(self::CONNECTIONS_OWNER);
        ExecutionContext::setCurrentAgentId(null);
        foreach ($this->userIds as $userId) {
            if (isset(Hilos::$db->users[$userId])) {
                Hilos::$db->users[$userId]->actions->delete();
            }
        }
        if ($this->chat !== null) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->chat->getId()));
            TruthSourceRegistry::unregisterAgent($this->chat->getId());
        }
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testExistingIdAnswersWithTheProfileAndTheUserProfileList(): void
    {
        $userId = $this->createUser('Target user');
        $this->createUser('Neighbour user');

        $response = $this->pageResponse($this->subscribe($userId));

        $expectedProfile = $this->profileOf($userId);
        self::assertSame($expectedProfile, $response->payload->entities[UserPage::ENTITY_SLOT]);
        self::assertSame($this->profileItemsOf($userId), $this->profileItems($response));
        self::assertCount(1, $this->profileItems($response));
        self::assertSame($userId, $this->profileItems($response)[0][PagePayload::itemKey]);

        $listedSlot = $this->profileItems($response)[0][PagePayload::slots][ChatDbContext::users];
        self::assertSame($expectedProfile, $listedSlot);
        self::assertSame($response->payload->entities[UserPage::ENTITY_SLOT], $listedSlot);

        self::assertTrue(
            is_int($expectedProfile[User::lastActivity]) || $expectedProfile[User::lastActivity] === null,
            'lastActivity in profile must be int or null',
        );
        self::assertTrue(
            is_int($listedSlot[User::lastActivity]) || $listedSlot[User::lastActivity] === null,
            'lastActivity in lists must be int or null',
        );
    }

    public function testRenamingTheOpenUserReachesTheCardAsAUserProfileDelta(): void
    {
        $userId = $this->createUser('Before rename');
        $initial = $this->pageResponse($this->subscribe($userId));
        $initialType = gettype($initial->payload->entities[UserPage::ENTITY_SLOT][User::lastActivity]);

        Hilos::$db->users[$userId]->actions->rename('After rename');
        $delta = $this->pageResponse($this->flushUserSync($userId));

        self::assertSame($this->profileItemsOf($userId), $this->profileItems($delta));
        $profile = $this->profileItems($delta)[0][PagePayload::slots][ChatDbContext::users];
        self::assertSame('After rename', $profile[User::name]);
        self::assertSame($initialType, gettype($profile[User::lastActivity]));
        self::assertTrue(
            is_int($profile[User::lastActivity]) || $profile[User::lastActivity] === null,
            'lastActivity in delta must be int or null',
        );
    }

    public function testAnotherUsersChangeDoesNotOpenTheirProfileOnTheCard(): void
    {
        $userId = $this->createUser('Open user');
        $otherUserId = $this->createUser('Other user');
        $this->pageResponse($this->subscribe($userId));

        Hilos::$db->users[$otherUserId]->actions->rename('Other renamed');
        $signals = $this->flushUserSync($otherUserId);

        foreach ($signals as $signal) {
            $frame = json_encode($signal->data->toArray(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('Other renamed', $frame);
            self::assertStringNotContainsString('Other user', $frame);
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $payload = $signal->data->data->payload;
            self::assertSame([], $payload->entities);
            self::assertSame([], $payload->data);
            foreach ($payload->lists as $list) {
                foreach ($list[PagePayload::items] ?? [] as $item) {
                    self::assertNotSame($otherUserId, $item[PagePayload::itemKey]);
                    self::assertNotSame($otherUserId, $item[PagePayload::slots][ChatDbContext::users][User::id]);
                }
                if (array_key_exists(PagePayload::deleted, $list)) {
                    self::assertSame([$otherUserId], $list[PagePayload::deleted]);
                }
            }
        }
    }

    private function holdConnections(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::CONNECTIONS_OWNER);
        $previous = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId(self::CONNECTIONS_OWNER);
        try {
            Hilos::$rt->connections[self::ACCEPT_KEY]?->actions->unregister();
            Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, null);
        } finally {
            ExecutionContext::setCurrentAgentId($previous);
        }
    }

    private function releaseGuest(): void
    {
        $previous = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId(self::CONNECTIONS_OWNER);
        try {
            Hilos::$rt->connections[self::ACCEPT_KEY]?->actions->unregister();
        } finally {
            ExecutionContext::setCurrentAgentId($previous);
        }
    }

    private function createUser(string $name): int
    {
        $user = Hilos::$db->users->actions->createWithName($name);
        self::assertIsInt($user->id);
        $this->userIds[] = $user->id;

        return $user->id;
    }

    /**
     * @param int $userId User whose card is opened
     * @return list<SignalDTO> Signals the subscribe queued
     */
    private function subscribe(int $userId): array
    {
        return $this->subscribeRaw([UserPageSubscribeParams::USER_ID => (string) $userId]);
    }

    /**
     * @param array<string, string> $params Route params of the subscribe frame
     * @return list<SignalDTO> Signals the subscribe queued
     */
    private function subscribeRaw(array $params): array
    {
        $this->takeQueuedSignals();
        $subscription = new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, UserPage::PAGE, $params);
        Hilos::$sr->subscribeToPage(UserPage::PAGE, $subscription);
        $this->router()->dispatchPageSubscribe($subscription, SignalSource::WEBSOCKET, UserPage::PAGE);

        return $this->takeQueuedSignals();
    }

    /**
     * Takes the DB sync the user's update queued and passes it through the browser the way the
     * daemon does, so the open card receives whatever the browser fans out for it.
     *
     * @param int $userId User whose update was just written
     * @return list<SignalDTO> Signals the browser flush queued
     */
    private function flushUserSync(int $userId): array
    {
        $sync = null;
        foreach ($this->takeQueuedSignals() as $signal) {
            if (
                $signal->data instanceof DbSyncUpdatedSignalData
                && $signal->data->collectionKey === ChatDbContext::users
                && $signal->data->idString === (string) $userId
            ) {
                $sync = $signal->data;
            }
        }
        self::assertInstanceOf(DbSyncUpdatedSignalData::class, $sync);

        $browser = Hilos::$browser ?? self::fail('The browser was not initialized');
        $browser->record(SourceChange::dbUpdated(
            $sync->collectionKey,
            $sync->idString,
            $sync->row,
            previous: $sync->previous,
        ));
        $browser->flushToSignalRouter();

        return $this->takeQueuedSignals();
    }

    /**
     * @return PageSignalRouter Router serving the demo's pages from the chat agent
     */
    private function router(): PageSignalRouter
    {
        $chat = $this->chat ?? self::fail('The chat agent was not started');

        return new PageSignalRouter(new HilosPageFactory($chat, Hilos::class), new ActionRouteConfig());
    }

    /** @return list<SignalDTO> Signals taken off the queue, in delivery order */
    private function takeQueuedSignals(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param list<SignalDTO> $signals Signals one subscribe queued
     * @return PageResponseSignalData The one page response among them
     */
    private function pageResponse(array $signals): PageResponseSignalData
    {
        $response = null;
        foreach ($signals as $signal) {
            $name = $signal->signalName->getName();
            self::assertNotSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $name);
            if ($name !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            self::assertNull($response);
            $response = $signal->data->data;
        }
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(UserPage::PAGE, $response->pageKey);

        return $response;
    }

    /**
     * @param PageResponseSignalData $response The one page response of a subscribe
     * @return list<array<string, mixed>> Items of the `userProfile` list it carries
     */
    private function profileItems(PageResponseSignalData $response): array
    {
        return $response->payload->lists[UserProfileBrowserList::LIST][PagePayload::items];
    }

    /**
     * @param int $userId User whose profile the card carries
     * @return list<array<string, mixed>> The one `userProfile` item the card expects, keyed by that user
     */
    private function profileItemsOf(int $userId): array
    {
        return [[
            PagePayload::itemKey => $userId,
            PagePayload::slots => [ChatDbContext::users => $this->profileOf($userId)],
        ]];
    }

    /**
     * @param int $userId User whose profile the card carries
     * @return array<string, mixed> Profile fields of the page entity slot
     */
    private function profileOf(int $userId): array
    {
        self::assertArrayHasKey($userId, Hilos::$db->users);
        $user = Hilos::$db->users[$userId];

        return [
            User::id => $user->id,
            User::name => $user->name,
            User::lastActivity => $user->lastActivity,
        ];
    }
}
