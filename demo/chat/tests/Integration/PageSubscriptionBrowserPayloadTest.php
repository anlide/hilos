<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Guardian\GuardianAgentPage;
use Demo\Chat\Pages\Hilos\GuardianPage;
use Demo\Chat\Pages\AdminPage;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\ModeratorPage;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;
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
        $this->assertSame([], $adminPayload->data->toArray());

        (new ModeratorPage($this->pageAgent()))->onSubscribe('moderator-subscribe-ak', new PageRouteParams([]));
        $moderatorPayload = $this->drainSingleWebSocketPayload(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MODERATOR,
            'moderator-subscribe-ak',
        );

        $this->assertInstanceOf(BrowserPageSignalData::class, $moderatorPayload->data);
        $this->assertSame([], $moderatorPayload->data->toArray());
    }

    public function testBotSubscriptionReturnsBrowserSnapshotPayload(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, true, 'test-page-agent');
        Hilos::$rt->botAgentStatuses->actions->clear();
        $bot = Hilos::$db->bots->actions->create('Browser Subscribe Bot ' . RandomHelper::hex(8));
        Hilos::$rt->botAgentStatuses->actions->create($bot->id, StateBotAgentStatus::STATUS_JOINED);

        try {
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

            $tables = $payload->data->toArray()[BrowserPageSignalData::tables] ?? [];
            $rows = $tables[ChatBrowserTable::BOT_DETAIL][BrowserPageSignalData::rows] ?? [];
            $botRow = $this->findBrowserRowBySourceField($rows, ChatDbContext::bots, 'id', (int)$bot->id);

            $this->assertIsArray($botRow);
            $this->assertSame($bot->name, $botRow[BrowserPageSignalData::sources][ChatDbContext::bots]['name'] ?? null);
            $this->assertSame(
                StateBotAgentStatus::STATUS_JOINED,
                $botRow[BrowserPageSignalData::sources][ChatRtContext::botAgentStatuses]['status'] ?? null,
            );
        } finally {
            Hilos::$rt->botAgentStatuses->actions->clear();
        }
    }

    public function testGuardianSubscriptionsReturnBrowserStatusRows(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::guardianAgentStatuses, true, 'test-page-agent');
        Hilos::$rt->guardianAgentStatuses->actions->clear();

        try {
            Hilos::$rt->guardianAgentStatuses->actions->create('static_analysis', GuardianRunStatus::IN_PROGRESS);
            $this->resetFrontendRouter();

            (new GuardianPage($this->pageAgent()))->onSubscribe('guardian-subscribe-ak', new PageRouteParams([]));
            $guardianPayload = $this->drainSingleWebSocketPayload(
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN,
                'guardian-subscribe-ak',
            );
            $guardianRows = $guardianPayload->data->toArray()[BrowserPageSignalData::tables]
                [ChatBrowserTable::GUARDIAN_AGENT_STATUSES][BrowserPageSignalData::rows] ?? [];
            $guardianRow = $this->findBrowserRowBySourceField(
                $guardianRows,
                ChatRtContext::guardianAgentStatuses,
                'agentId',
                'static_analysis',
            );
            $this->assertIsArray($guardianRow);
            $this->assertSame(
                GuardianRunStatus::IN_PROGRESS->value,
                $guardianRow[BrowserPageSignalData::sources][ChatRtContext::guardianAgentStatuses]['status'] ?? null,
            );

            (new GuardianAgentPage($this->pageAgent()))->onSubscribe(
                'guardian-agent-subscribe-ak',
                new PageRouteParams([HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID => 'static_analysis']),
            );
            $detailPayload = $this->drainSingleWebSocketPayload(
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT,
                'guardian-agent-subscribe-ak',
            );
            $detailRows = $detailPayload->data->toArray()[BrowserPageSignalData::tables]
                [ChatBrowserTable::GUARDIAN_AGENT_STATUS_DETAIL][BrowserPageSignalData::rows] ?? [];
            $detailRow = $this->findBrowserRowBySourceField(
                $detailRows,
                ChatRtContext::guardianAgentStatuses,
                'agentId',
                'static_analysis',
            );
            $this->assertIsArray($detailRow);
            $this->assertSame(
                GuardianRunStatus::IN_PROGRESS->value,
                $detailRow[BrowserPageSignalData::sources][ChatRtContext::guardianAgentStatuses]['status'] ?? null,
            );
        } finally {
            Hilos::$rt->guardianAgentStatuses->actions->clear();
        }
    }

    /**
     * Reinitializes worker-local routers before page subscribe dispatch.
     */
    private function resetFrontendRouter(): void
    {
        Hilos::initSignalRouter(new ChatSignalRouter());
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
