<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminPage;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\ModeratorPage;
use Demo\Chat\Projection\ChatProjectionContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration coverage for page subscription payloads migrated to browser data.
 */
final class PageSubscriptionBrowserPayloadTest extends IntegrationTestCase
{
    public function testAdminAndModeratorSubscriptionsReturnEmptyBrowserPayloads(): void
    {
        $this->resetFrontendRouter();

        (new AdminPage($this->pageAgent()))->onSubscribe('admin-subscribe-ak', new PageRouteParams([]));
        $adminPayload = $this->drainSingleWebSocketPayload(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN,
            'admin-subscribe-ak',
        );

        $this->assertInstanceOf(BrowserPageSignalData::class, $adminPayload->data);
        $this->assertNotInstanceOf(ChatEventSignalDTO::class, $adminPayload->data);
        $this->assertSame([], $adminPayload->data->toArray());

        (new ModeratorPage($this->pageAgent()))->onSubscribe('moderator-subscribe-ak', new PageRouteParams([]));
        $moderatorPayload = $this->drainSingleWebSocketPayload(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MODERATOR,
            'moderator-subscribe-ak',
        );

        $this->assertInstanceOf(BrowserPageSignalData::class, $moderatorPayload->data);
        $this->assertNotInstanceOf(ChatEventSignalDTO::class, $moderatorPayload->data);
        $this->assertSame([], $moderatorPayload->data->toArray());
    }

    public function testBotSubscriptionReturnsBrowserSnapshotPayload(): void
    {
        $bot = Hilos::$db->bots->actions->create('Browser Subscribe Bot ' . RandomHelper::hex(8));

        $this->resetFrontendRouter();

        (new BotPage($this->pageAgent()))->onSubscribe(
            'bot-subscribe-ak',
            new PageRouteParams(['id' => (string)$bot->id]),
        );

        $payload = $this->drainSingleWebSocketPayload(
            ChatSignalConstants::SUBSCRIPTION_PAGE_BOT,
            'bot-subscribe-ak',
        );

        $this->assertInstanceOf(BrowserPageSignalData::class, $payload->data);
        $this->assertNotInstanceOf(ChatEventSignalDTO::class, $payload->data);

        $tables = $payload->data->toArray()[BrowserPageSignalData::tables] ?? [];
        $rows = $tables[ChatBrowserTable::BOT_DETAIL][BrowserPageSignalData::rows] ?? [];
        $botRow = $this->findBrowserRowBySourceField($rows, ChatDbContext::bots, 'id', (int)$bot->id);

        $this->assertIsArray($botRow);
        $this->assertSame($bot->name, $botRow[BrowserPageSignalData::sources][ChatDbContext::bots]['name'] ?? null);
    }

    /**
     * Reinitializes worker-local routers before page subscribe dispatch.
     */
    private function resetFrontendRouter(): void
    {
        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initProjection(new ChatProjectionContext());
        Hilos::initBrowser();
    }

    /**
     * Creates a minimal page agent for direct page subscription dispatch.
     */
    private function pageAgent(): PageAgentInterface
    {
        return new class implements PageAgentInterface {
            /**
             * Return the fixture agent id.
             *
             * @return string Agent id
             */
            public function getId(): string
            {
                return 'test-page-agent';
            }

            /**
             * Return the fixture signal source for page helpers.
             *
             * @return SignalSourceInterface Signal source
             */
            public function getAgentSignalSource(): SignalSourceInterface
            {
                return new SignalSource(SignalSource::AGENT, 'test-page-agent');
            }
        };
    }

    /**
     * Drains one WebSocket payload for the expected page subscription signal.
     */
    private function drainSingleWebSocketPayload(string $signalName, string $targetAcceptKey): WebSocketSignalData
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $signals[] = $signal;
        }

        $this->assertCount(1, $signals);
        $this->assertSame($signalName, $signals[0]->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        $this->assertSame($targetAcceptKey, $signals[0]->data->targetAcceptKey);

        return $signals[0]->data;
    }

    /**
     * Finds a browser row by field value in one source fragment.
     *
     * @param list<array<string, mixed>> $rows Browser rows
     * @param string $sourceKey Source fragment key
     * @param string $field Source field to match
     * @param mixed $value Source field value
     * @return ?array<string, mixed> Matching row, or null
     */
    private function findBrowserRowBySourceField(array $rows, string $sourceKey, string $field, mixed $value): ?array
    {
        foreach ($rows as $row) {
            $source = $row[BrowserPageSignalData::sources][$sourceKey] ?? null;
            if (is_array($source) && ($source[$field] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }
}
