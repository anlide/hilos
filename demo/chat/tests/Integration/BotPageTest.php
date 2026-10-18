<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\LibraryAgent;
use Demo\Chat\Browser\Data\BotStatusBrowserData;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Database\Object\Item\Bot;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * The bot card answers one page response: the profile, and the lifecycle status
 * the browser snapshot already holds for that bot.
 */
final class BotPageTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'bot-page-guest';

    private const string CONNECTIONS_OWNER = 'bot-page-connections';

    private ?LibraryAgent $library = null;

    /** @var list<int> Bots this case created, removed with it */
    private array $botIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::initSignalRouter(new SignalRouter());
        $this->library = new LibraryAgent();
        $this->startAgent($this->library);
        $this->holdConnections();
        ExecutionContext::setCurrentAgentId(null);
        $this->botIds = [];
    }

    protected function tearDown(): void
    {
        $this->releaseGuest();
        RtTruthSourceRegistry::unregisterAgent(self::CONNECTIONS_OWNER);
        ExecutionContext::setCurrentAgentId(null);
        foreach ($this->botIds as $botId) {
            Hilos::$db->bots[$botId]?->actions->delete();
        }
        if ($this->library !== null) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
            TruthSourceRegistry::unregisterAgent($this->library->getId());
        }
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testExistingIdAnswersWithTheProfileAndTheCurrentStatus(): void
    {
        $botId = $this->createBot('Present bot', true);
        $this->writeStatus($botId, StateBotAgentStatus::STATUS_JOINED);
        $before = Hilos::$rt->botAgentStatuses[(string)$botId]->toArray();

        $response = $this->pageResponse($this->subscribe($botId));

        self::assertSame($this->profileOf($botId), $response->payload->entities[BotPage::ENTITY_SLOT]);
        self::assertSame($before, $response->payload->data[BotStatusBrowserData::DATA]);
        self::assertSame($before, Hilos::$rt->botAgentStatuses[(string)$botId]->toArray());
    }

    public function testNoStatusRowArrivesEmptyAndDoesNotStartTheBot(): void
    {
        $botId = $this->createBot('Quiet bot', false);

        $signals = $this->subscribe($botId);
        $response = $this->pageResponse($signals);

        self::assertSame($this->profileOf($botId), $response->payload->entities[BotPage::ENTITY_SLOT]);
        self::assertFalse($response->payload->entities[BotPage::ENTITY_SLOT][Bot::active]);
        self::assertSame([], $response->payload->data[BotStatusBrowserData::DATA]);
        self::assertNull(Hilos::$rt->botAgentStatuses[(string)$botId]);
        self::assertFalse(Hilos::$db->bots[$botId]->active);
        self::assertNotContains(
            ChatSignalConstants::BOT_AGENT_START,
            array_map(static fn (SignalDTO $signal): string => $signal->signalName->getName(), $signals),
        );
    }

    public function testJoinedAndLeftStayOnThisBotAndTheReaderDoesNotRewriteThem(): void
    {
        $botId = $this->createBot('Joined bot', true);
        $otherBotId = $this->createBot('Other bot', true);
        $this->writeStatus($otherBotId, StateBotAgentStatus::STATUS_JOINED);
        $otherBefore = Hilos::$rt->botAgentStatuses[(string)$otherBotId]->toArray();

        $this->writeStatus($botId, StateBotAgentStatus::STATUS_JOINED);
        $joinedBefore = Hilos::$rt->botAgentStatuses[(string)$botId]->toArray();
        $joined = $this->pageResponse($this->subscribe($botId));
        self::assertSame($joinedBefore, $joined->payload->data[BotStatusBrowserData::DATA]);
        self::assertSame(StateBotAgentStatus::STATUS_JOINED, $joined->payload->data[BotStatusBrowserData::DATA][StateBotAgentStatus::status]);
        self::assertSame($botId, $joined->payload->data[BotStatusBrowserData::DATA][StateBotAgentStatus::botId]);
        self::assertSame($joinedBefore, Hilos::$rt->botAgentStatuses[(string)$botId]->toArray());

        $this->writeStatus($botId, StateBotAgentStatus::STATUS_LEFT);
        $leftBefore = Hilos::$rt->botAgentStatuses[(string)$botId]->toArray();
        $left = $this->pageResponse($this->subscribe($botId));
        self::assertSame($leftBefore, $left->payload->data[BotStatusBrowserData::DATA]);
        self::assertSame(StateBotAgentStatus::STATUS_LEFT, $left->payload->data[BotStatusBrowserData::DATA][StateBotAgentStatus::status]);
        self::assertSame($botId, $left->payload->data[BotStatusBrowserData::DATA][StateBotAgentStatus::botId]);
        self::assertSame($leftBefore, Hilos::$rt->botAgentStatuses[(string)$botId]->toArray());
        self::assertSame($otherBefore, Hilos::$rt->botAgentStatuses[(string)$otherBotId]->toArray());
    }

    public function testMissingAndInvalidIdsAre400AndAnUnknownIdIs404(): void
    {
        $missing = $this->refusal($this->subscribeRaw([]));
        self::assertSame(HttpConstants::HTTP_BAD_REQUEST, $missing->httpCode);
        self::assertSame(BotPage::PAGE, $missing->page);

        $invalid = $this->refusal($this->subscribeRaw([BotPageSubscribeParams::BOT_ID => '0']));
        self::assertSame(HttpConstants::HTTP_BAD_REQUEST, $invalid->httpCode);

        $malformed = $this->refusal($this->subscribeRaw([BotPageSubscribeParams::BOT_ID => 'abc']));
        self::assertSame(HttpConstants::HTTP_BAD_REQUEST, $malformed->httpCode);

        $goneId = $this->createBot('Gone bot', true);
        Hilos::$db->bots[$goneId]?->actions->delete();
        $this->botIds = array_values(array_diff($this->botIds, [$goneId]));
        $unknown = $this->refusal($this->subscribeRaw([BotPageSubscribeParams::BOT_ID => (string)$goneId]));
        self::assertSame(HttpConstants::HTTP_NOT_FOUND, $unknown->httpCode);
        self::assertSame(BotPage::PAGE, $unknown->page);
    }

    public function testRtSourcesNameTheStatusWithoutReadsRt(): void
    {
        self::assertNotNull(Hilos::$browser);
        self::assertContains(
            ChatRtContext::botAgentStatuses,
            Hilos::$browser->rtSourceKeysOfPage(PageConstants::BOT),
        );
        self::assertNotContains(ChatRtContext::botAgentStatuses, BotPage::READS_RT);
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

    /**
     * @param string $name Display name stored on the profile
     * @param bool $active Whether the bot is active
     * @return int Created bot id
     */
    private function createBot(string $name, bool $active): int
    {
        $bot = Hilos::$db->bots->actions->create($name, 'Described for the card', 'plain', 'testing', 'patient', $active);
        self::assertIsInt($bot->id);
        $this->botIds[] = $bot->id;

        return $bot->id;
    }

    /**
     * @param int $botId Bot the row belongs to
     * @param string $status Lifecycle marker
     */
    private function writeStatus(int $botId, string $status): void
    {
        $ownerId = 'bot:' . $botId;
        RtTruthSourceRegistry::register(
            ChatRtContext::botAgentStatuses,
            TruthSourceKeys::listed((string)$botId),
            $ownerId,
        );
        $previous = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId($ownerId);
        try {
            $statusRow = Hilos::$rt->botAgentStatuses->actions->ensure($botId);
            if ($status === StateBotAgentStatus::STATUS_JOINED) {
                $statusRow->actions->markJoined();
            } else {
                $statusRow->actions->markLeft();
            }
        } finally {
            RtTruthSourceRegistry::unregisterAgent($ownerId);
            ExecutionContext::setCurrentAgentId($previous);
        }
    }

    /**
     * @param int $botId Bot whose card is opened
     * @return list<SignalDTO> Signals the subscribe queued
     */
    private function subscribe(int $botId): array
    {
        return $this->subscribeRaw([BotPageSubscribeParams::BOT_ID => (string)$botId]);
    }

    /**
     * @param array<string, string> $params Route params of the subscribe frame
     * @return list<SignalDTO> Signals the subscribe queued
     */
    private function subscribeRaw(array $params): array
    {
        $this->takeQueuedSignals();
        $subscription = new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, BotPage::PAGE, $params);
        Hilos::$sr->subscribeToPage(BotPage::PAGE, $subscription);
        $this->router()->dispatchPageSubscribe($subscription, SignalSource::WEBSOCKET, BotPage::PAGE);

        return $this->takeQueuedSignals();
    }

    /**
     * @return PageSignalRouter Router serving the demo's pages from the library
     */
    private function router(): PageSignalRouter
    {
        $library = $this->library ?? self::fail('The library agent was not started');

        return new PageSignalRouter(new HilosPageFactory($library, Hilos::class), new ActionRouteConfig());
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
            self::assertNotSame(ChatSignalConstants::BOT_AGENT_START, $name);
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
        self::assertSame(BotPage::PAGE, $response->pageKey);

        return $response;
    }

    /**
     * @param list<SignalDTO> $signals Signals one subscribe queued
     * @return PageSubscriptionErrorSignalData The refusal, with no page response beside it
     */
    private function refusal(array $signals): PageSubscriptionErrorSignalData
    {
        $error = null;
        foreach ($signals as $signal) {
            $name = $signal->signalName->getName();
            self::assertNotSame(SignalTypeConstants::PAGE_RESPONSE, $name);
            if ($name !== SignalConstants::SUBSCRIPTION_PAGE_ERROR) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
            self::assertNull($error);
            $error = $signal->data->data;
        }
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);

        return $error;
    }

    /**
     * @param int $botId Bot whose profile the card carries
     * @return array<string, mixed> Profile fields of the page entity slot
     */
    private function profileOf(int $botId): array
    {
        $bot = Hilos::$db->bots[$botId];
        self::assertNotNull($bot);

        return [
            Bot::id => $bot->id,
            Bot::name => $bot->name,
            Bot::description => $bot->description,
            Bot::style => $bot->style,
            Bot::topics => $bot->topics,
            Bot::personality => $bot->personality,
            Bot::active => $bot->active,
        ];
    }
}
