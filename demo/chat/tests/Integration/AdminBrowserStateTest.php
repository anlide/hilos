<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration coverage for admin pages migrated to browser payloads.
 */
final class AdminBrowserStateTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testAdminModeratorSnapshotAndMutationsUseBrowserRows(): void
    {
        $piece = Hilos::$db->moderatorPromptPieces->actions->create(
            'message_rule',
            'admin browser moderator snapshot ' . RandomHelper::hex(8),
        );

        $this->resetFrontendRouter();
        Hilos::$browser->subscribeSnapshot(PageConstants::ADMIN_MODERATOR, 'admin-moderator-ak', new PageRouteParams([]));

        $snapshot = $this->drainSinglePayload(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
            'admin-moderator-ak',
        );
        $rows = $snapshot[BrowserPageSignalData::tables][ChatTableContext::moderatorPromptPieces][BrowserPageSignalData::rows] ?? [];
        $this->assertNotNull($this->findBrowserRowBySourceField(
            $rows,
            ChatDbContext::moderatorPromptPieces,
            'id',
            (int)$piece->id,
        ));

        $this->resetFrontendRouter();
        Hilos::$sr->subscribeToPage(PageConstants::ADMIN_MODERATOR, new WebSocketPageSubscribeSignalDTO(
            'admin-moderator-ak',
            PageConstants::ADMIN_MODERATOR,
            [],
        ));

        $created = Hilos::$db->moderatorPromptPieces->actions->create(
            'name_rule',
            'admin browser moderator created ' . RandomHelper::hex(8),
        );
        $payload = $this->drainSinglePayload(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
            'admin-moderator-ak',
            flushFrontend: true,
        );
        $createdRow = $this->findBrowserRowBySourceField(
            $payload[BrowserPageSignalData::tables][ChatTableContext::moderatorPromptPieces][BrowserPageSignalData::rows] ?? [],
            ChatDbContext::moderatorPromptPieces,
            'id',
            (int)$created->id,
        );
        $this->assertIsArray($createdRow);
        $this->assertSame('name_rule', $createdRow[BrowserPageSignalData::sources][ChatDbContext::moderatorPromptPieces]['section'] ?? null);

        $created->actions->update('message_rule', 'admin browser moderator updated ' . RandomHelper::hex(8));
        $payload = $this->drainSinglePayload(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
            'admin-moderator-ak',
            flushFrontend: true,
        );
        $updatedRow = $this->findBrowserRowBySourceField(
            $payload[BrowserPageSignalData::tables][ChatTableContext::moderatorPromptPieces][BrowserPageSignalData::rows] ?? [],
            ChatDbContext::moderatorPromptPieces,
            'id',
            (int)$created->id,
        );
        $this->assertIsArray($updatedRow);
        $this->assertSame('message_rule', $updatedRow[BrowserPageSignalData::sources][ChatDbContext::moderatorPromptPieces]['section'] ?? null);

        $created->actions->delete();
        $payload = $this->drainSinglePayload(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
            'admin-moderator-ak',
            flushFrontend: true,
        );
        $deleted = $payload[BrowserPageSignalData::tables][ChatTableContext::moderatorPromptPieces][BrowserPageSignalData::deleted] ?? [];
        $this->assertContains((int)$created->id, $deleted);
    }

    public function testAdminBotsSnapshotDbMutationsAndRuntimeStatusUseBrowserRows(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, true, self::TEST_AGENT_ID);
        Hilos::$rt->botAgentStatuses->actions->clear();

        try {
            $bot = Hilos::$db->bots->actions->create('Admin Browser Bot ' . RandomHelper::hex(8));
            Hilos::$rt->botAgentStatuses->actions->create($bot->id, StateBotAgentStatus::STATUS_JOINED);

            $this->resetFrontendRouter();
            Hilos::$browser->subscribeSnapshot(PageConstants::ADMIN_BOTS, 'admin-bots-ak', new PageRouteParams([]));

            $snapshot = $this->drainSinglePayload(ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS, 'admin-bots-ak');
            $snapshotRow = $this->findBrowserRowBySourceField(
                $snapshot[BrowserPageSignalData::tables][ChatTableContext::bots][BrowserPageSignalData::rows] ?? [],
                ChatDbContext::bots,
                'id',
                (int)$bot->id,
            );
            $this->assertIsArray($snapshotRow);
            $this->assertSame(
                StateBotAgentStatus::STATUS_JOINED,
                $snapshotRow[BrowserPageSignalData::sources][ChatRtContext::botAgentStatuses]['status'] ?? null,
            );

            $this->resetFrontendRouter();
            Hilos::$sr->subscribeToPage(PageConstants::ADMIN_BOTS, new WebSocketPageSubscribeSignalDTO(
                'admin-bots-ak',
                PageConstants::ADMIN_BOTS,
                [],
            ));

            $created = Hilos::$db->bots->actions->create('Admin Browser Created Bot ' . RandomHelper::hex(8));
            $payload = $this->drainSinglePayload(
                ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
                'admin-bots-ak',
                flushFrontend: true,
            );
            $createdRow = $this->findBrowserRowBySourceField(
                $payload[BrowserPageSignalData::tables][ChatTableContext::bots][BrowserPageSignalData::rows] ?? [],
                ChatDbContext::bots,
                'id',
                (int)$created->id,
            );
            $this->assertIsArray($createdRow);
            $this->assertSame($created->name, $createdRow[BrowserPageSignalData::sources][ChatDbContext::bots]['name'] ?? null);

            $created->actions->update(name: 'Admin Browser Updated Bot ' . RandomHelper::hex(8));
            $payload = $this->drainSinglePayload(
                ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
                'admin-bots-ak',
                flushFrontend: true,
            );
            $updatedRow = $this->findBrowserRowBySourceField(
                $payload[BrowserPageSignalData::tables][ChatTableContext::bots][BrowserPageSignalData::rows] ?? [],
                ChatDbContext::bots,
                'id',
                (int)$created->id,
            );
            $this->assertIsArray($updatedRow);
            $this->assertSame($created->name, $updatedRow[BrowserPageSignalData::sources][ChatDbContext::bots]['name'] ?? null);

            Hilos::$rt->botAgentStatuses->actions->create($created->id, StateBotAgentStatus::STATUS_JOINED);
            $payload = $this->drainSinglePayload(
                ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
                'admin-bots-ak',
                flushFrontend: true,
            );
            $statusRow = $this->findBrowserRowBySourceField(
                $payload[BrowserPageSignalData::tables][ChatTableContext::bots][BrowserPageSignalData::rows] ?? [],
                ChatDbContext::bots,
                'id',
                (int)$created->id,
            );
            $this->assertIsArray($statusRow);
            $this->assertSame(
                StateBotAgentStatus::STATUS_JOINED,
                $statusRow[BrowserPageSignalData::sources][ChatRtContext::botAgentStatuses]['status'] ?? null,
            );

            Hilos::$rt->botAgentStatuses[$created->id]?->actions->markLeft();
            $payload = $this->drainSinglePayload(
                ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
                'admin-bots-ak',
                flushFrontend: true,
            );
            $leftRow = $this->findBrowserRowBySourceField(
                $payload[BrowserPageSignalData::tables][ChatTableContext::bots][BrowserPageSignalData::rows] ?? [],
                ChatDbContext::bots,
                'id',
                (int)$created->id,
            );
            $this->assertIsArray($leftRow);
            $this->assertSame(
                StateBotAgentStatus::STATUS_LEFT,
                $leftRow[BrowserPageSignalData::sources][ChatRtContext::botAgentStatuses]['status'] ?? null,
            );

            $createdId = (int)$created->id;
            $created->actions->delete();
            $payload = $this->drainSinglePayload(
                ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
                'admin-bots-ak',
                flushFrontend: true,
            );
            $deleted = $payload[BrowserPageSignalData::tables][ChatTableContext::bots][BrowserPageSignalData::deleted] ?? [];
            $this->assertContains($createdId, $deleted);
        } finally {
            Hilos::$rt->botAgentStatuses->actions->clear();
        }
    }

    /**
     * Reinitializes worker-local routers before recording browser source changes.
     */
    private function resetFrontendRouter(): void
    {
        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
    }

    /**
     * Drains one browser payload from the queue.
     *
     * @param string $signalName Browser page signal name
     * @param string $targetAcceptKey Expected target accept key
     * @param bool $flushFrontend Whether queued DB/RT sync signals should be flushed first
     * @return array<string, mixed> Browser payload array
     */
    private function drainSinglePayload(string $signalName, string $targetAcceptKey, bool $flushFrontend = false): array
    {
        if ($flushFrontend) {
            $this->drainSyncSignalsToFrontendBuffers();
            Hilos::$browser?->flushToSignalRouter();
        }

        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if (
                $signal->signalName->getName() === $signalName
                && $signal->data instanceof WebSocketSignalData
                && $signal->data->targetAcceptKey === $targetAcceptKey
            ) {
                $signals[] = $signal;
            }
        }

        $this->assertCount(1, $signals);
        $webSocketData = $signals[0]->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
        $this->assertInstanceOf(BrowserPageSignalData::class, $webSocketData->data);

        return $webSocketData->data->toArray();
    }

    /**
     * Records queued DB/RT sync signals as browser source changes.
     */
    private function drainSyncSignalsToFrontendBuffers(): void
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $change = $this->sourceChangeFromSignal($signal);
            if ($change === null) {
                continue;
            }
            Hilos::$browser?->record($change);
        }
    }

    /**
     * Converts a sync signal to a source change.
     */
    private function sourceChangeFromSignal(SignalDTO $signal): ?SourceChange
    {
        $signalType = $signal->signalType->getType();
        $signalData = $signal->data;

        if ($signalType === SignalTypeConstants::DB_SYNC_CREATED && $signalData instanceof DbSyncCreatedSignalData) {
            return SourceChange::dbCreated($signalData->collectionKey, $signalData->idString, $signalData->row);
        }
        if ($signalType === SignalTypeConstants::DB_SYNC_UPDATED && $signalData instanceof DbSyncUpdatedSignalData) {
            return SourceChange::dbUpdated($signalData->collectionKey, $signalData->idString, $signalData->row);
        }
        if ($signalType === SignalTypeConstants::DB_SYNC_DELETED && $signalData instanceof DbSyncDeletedSignalData) {
            return SourceChange::dbDeleted($signalData->collectionKey, $signalData->idString, $signalData->row);
        }
        if ($signalType === SignalTypeConstants::RT_SYNC_CREATED && $signalData instanceof RtSyncCreatedSignalData) {
            return SourceChange::rtCreated($signalData->collectionKey, $signalData->stateId, $signalData->row);
        }
        if ($signalType === SignalTypeConstants::RT_SYNC_UPDATED && $signalData instanceof RtSyncUpdatedSignalData) {
            return SourceChange::rtUpdated($signalData->collectionKey, $signalData->stateId, $signalData->row);
        }
        if ($signalType === SignalTypeConstants::RT_SYNC_DELETED && $signalData instanceof RtSyncDeletedSignalData) {
            return SourceChange::rtDeleted($signalData->collectionKey, $signalData->stateId, $signalData->row);
        }

        return null;
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
