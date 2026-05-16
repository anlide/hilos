<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
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
 * Integration tests for browser state of main chat page.
 */
final class ChatBrowserStateTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testMainPageBrowserSnapshotContainsAllTablesAndScopedRows(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$rt->botAgentStatuses->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            $event = Hilos::$db->events->actions->addMessage('browser snapshot message', userId: $user->id);
            $bot = Hilos::$db->bots->actions->create('Browser Snapshot Bot ' . RandomHelper::hex(6));
            Hilos::$rt->botAgentStatuses->actions->create($bot->id, StateBotAgentStatus::STATUS_JOINED);
            Hilos::$rt->connections->actions->register('main-browser-ak', $user->id);
            Hilos::$rt->connections->actions->register('other-browser-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);
            Hilos::$rt->attachmentDrafts->actions->create(
                'main-browser-draft',
                'main-browser-ak',
                $user->id,
                '',
                'main.txt',
                'text/plain',
                12,
                'main.txt',
                time(),
            );
            Hilos::$rt->attachmentDrafts->actions->create(
                'other-browser-draft',
                'other-browser-ak',
                $user->id,
                '',
                'other.txt',
                'text/plain',
                13,
                'other.txt',
                time(),
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            Hilos::$browser->subscribeSnapshot(PageConstants::MAIN, 'main-browser-ak', new PageRouteParams([]));

            $signal = Hilos::$sr->getNextQueuedSignal();
            $this->assertNotNull($signal);
            $this->assertSame(ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN, $signal->signalName->getName());
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertSame('main-browser-ak', $signal->data->targetAcceptKey);
            $this->assertInstanceOf(BrowserPageSignalData::class, $signal->data->data);

            $tables = $signal->data->data->toArray()[BrowserPageSignalData::tables];
            foreach ([
                ChatBrowserTable::MAIN_EVENTS,
                ChatBrowserTable::MAIN_USERS,
                ChatBrowserTable::MAIN_BOTS,
                ChatBrowserTable::SELF_CONNECTION,
                ChatBrowserTable::ATTACHMENT_DRAFTS,
            ] as $tableKey) {
                $this->assertArrayHasKey($tableKey, $tables);
            }

            $eventRow = $this->findBrowserRowBySourceId(
                $tables[ChatBrowserTable::MAIN_EVENTS][BrowserPageSignalData::rows],
                ChatDbContext::events,
                (int)$event->id,
            );
            $this->assertIsArray($eventRow);
            $this->assertSame(
                'browser snapshot message',
                $eventRow[BrowserPageSignalData::sources][ChatDbContext::eventMessages]['message'] ?? null,
            );

            $userRow = $this->findBrowserRowBySourceId(
                $tables[ChatBrowserTable::MAIN_USERS][BrowserPageSignalData::rows],
                ChatDbContext::users,
                $user->id,
            );
            $this->assertIsArray($userRow);
            $connection = $userRow[BrowserPageSignalData::sources][ChatRtContext::connections] ?? null;
            $this->assertIsArray($connection);
            $this->assertSame('online', $connection['presence'] ?? null);
            $this->assertSame(2, $connection['onlineSessionCount'] ?? null);

            $botRow = $this->findBrowserRowBySourceId(
                $tables[ChatBrowserTable::MAIN_BOTS][BrowserPageSignalData::rows],
                ChatDbContext::bots,
                $bot->id,
            );
            $this->assertIsArray($botRow);
            $this->assertSame(
                StateBotAgentStatus::STATUS_JOINED,
                $botRow[BrowserPageSignalData::sources][ChatRtContext::botAgentStatuses]['status'] ?? null,
            );

            $selfRows = $tables[ChatBrowserTable::SELF_CONNECTION][BrowserPageSignalData::rows];
            $this->assertCount(1, $selfRows);
            $this->assertSame($user->id, $selfRows[0][BrowserPageSignalData::rowKey]);
            $this->assertSame(
                $user->id,
                $selfRows[0][BrowserPageSignalData::sources][ChatRtContext::connections]['userId'] ?? null,
            );
            $this->assertSame(
                $user->name,
                $selfRows[0][BrowserPageSignalData::sources][ChatDbContext::users]['name'] ?? null,
            );

            $draftRows = $tables[ChatBrowserTable::ATTACHMENT_DRAFTS][BrowserPageSignalData::rows];
            $this->assertNotNull($this->findBrowserRowBySourceField($draftRows, ChatRtContext::attachmentDrafts, 'draftId', 'main-browser-draft'));
            $this->assertNull($this->findBrowserRowBySourceField($draftRows, ChatRtContext::attachmentDrafts, 'draftId', 'other-browser-draft'));

            $this->assertNoAcceptKeyInBrowserTables($tables);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
            Hilos::$rt->botAgentStatuses->actions->clear();
        }
    }

    public function testOutboundModerationBrowserUpdateTargetsSelfConnectionTable(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('moderation-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);

            $this->resetFrontendRouter();
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'moderation-ak',
                PageConstants::MAIN,
                [],
            ));

            Hilos::$rt->connections['moderation-ak']?->actions->startOutboundModeration('pending text');

            $payload = $this->drainSingleMainBrowserPayload('moderation-ak');
            $selfRows = $payload[BrowserPageSignalData::tables][ChatBrowserTable::SELF_CONNECTION][BrowserPageSignalData::rows] ?? [];
            $this->assertCount(1, $selfRows);
            $connection = $selfRows[0][BrowserPageSignalData::sources][ChatRtContext::connections] ?? null;
            $this->assertIsArray($connection);
            $this->assertSame($user->id, $selfRows[0][BrowserPageSignalData::rowKey]);
            $this->assertSame('checking', $connection[SelfConnectionSignalData::outboundModerationState]['phase'] ?? null);
            $this->assertSame('pending text', $connection[SelfConnectionSignalData::outboundModerationState]['text'] ?? null);
            $this->assertNoAcceptKeyInBrowserTables($payload[BrowserPageSignalData::tables]);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
        }
    }

    public function testAttachmentDraftBrowserUpdateTargetsOwningConnection(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('draft-ak', $user->id);

            $this->resetFrontendRouter();
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'draft-ak',
                PageConstants::MAIN,
                [],
            ));

            Hilos::$rt->attachmentDrafts->actions->create(
                'draft-browser',
                'draft-ak',
                $user->id,
                '',
                'browser.txt',
                'text/plain',
                12,
                'browser.txt',
                time(),
            );

            $payload = $this->drainSingleMainBrowserPayload('draft-ak');
            $draftRows = $payload[BrowserPageSignalData::tables][ChatBrowserTable::ATTACHMENT_DRAFTS][BrowserPageSignalData::rows] ?? [];
            $draftRow = $this->findBrowserRowBySourceField(
                $draftRows,
                ChatRtContext::attachmentDrafts,
                'draftId',
                'draft-browser',
            );

            $this->assertIsArray($draftRow);
            $this->assertSame('browser.txt', $draftRow[BrowserPageSignalData::sources][ChatRtContext::attachmentDrafts]['filename'] ?? null);
            $this->assertNoAcceptKeyInBrowserTables($payload[BrowserPageSignalData::tables]);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        }
    }

    public function testUploadProgressBrowserUpdateTargetsSelfConnectionTable(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('upload-ak', $user->id);

            $this->resetFrontendRouter();
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'upload-ak',
                PageConstants::MAIN,
                [],
            ));

            Hilos::$rt->connections['upload-ak']?->actions->beginBinaryFileUpload(
                'upload-browser',
                1024,
                'tmp-upload-browser',
                'browser.txt',
                'text/plain',
                'client-upload-browser',
                'browser.txt',
                'browser.txt',
                1024,
            );

            $payload = $this->drainSingleMainBrowserPayload('upload-ak');
            $connection = $payload[BrowserPageSignalData::tables][ChatBrowserTable::SELF_CONNECTION][BrowserPageSignalData::rows][0][BrowserPageSignalData::sources][ChatRtContext::connections] ?? [];
            $this->assertSame(
                [
                    SelfConnectionSignalData::phase => ConnectionRuntimeConstants::FILE_UPLOAD_PHASE_READY,
                    SelfConnectionSignalData::clientUploadId => 'client-upload-browser',
                    SelfConnectionSignalData::errorCode => null,
                    SelfConnectionSignalData::errorMessage => null,
                ],
                $connection[SelfConnectionSignalData::fileUploadState] ?? null,
            );
            $this->assertSame(
                [
                    SelfConnectionSignalData::filename => 'browser.txt',
                    SelfConnectionSignalData::uploadedBytes => 0,
                    SelfConnectionSignalData::totalBytes => 1024,
                ],
                $connection[SelfConnectionSignalData::fileUploadProgress] ?? null,
            );

            Hilos::$rt->connections['upload-ak']?->actions->applyStoredBinaryChunkProgress(512);
            $this->assertSame([], $this->drainMainBrowserSignals());

            Hilos::$rt->connections['upload-ak']?->actions->noteUploadProgressSentAt();
            $payload = $this->drainSingleMainBrowserPayload('upload-ak');
            $connection = $payload[BrowserPageSignalData::tables][ChatBrowserTable::SELF_CONNECTION][BrowserPageSignalData::rows][0][BrowserPageSignalData::sources][ChatRtContext::connections] ?? [];
            $this->assertSame(
                ConnectionRuntimeConstants::FILE_UPLOAD_PHASE_UPLOADING,
                $connection[SelfConnectionSignalData::fileUploadState][SelfConnectionSignalData::phase] ?? null,
            );
            $this->assertSame(
                512,
                $connection[SelfConnectionSignalData::fileUploadProgress][SelfConnectionSignalData::uploadedBytes] ?? null,
            );
        } finally {
            Hilos::$rt->connections->actions->clear();
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
     * Drains main browser signals and returns the only payload for the given target.
     *
     * @param string $targetAcceptKey Expected WebSocket accept key
     * @return array<string, mixed> Browser payload array
     */
    private function drainSingleMainBrowserPayload(string $targetAcceptKey): array
    {
        $signals = $this->drainMainBrowserSignals();
        $matching = array_values(array_filter(
            $signals,
            static fn(SignalDTO $signal): bool =>
                $signal->data instanceof WebSocketSignalData
                && $signal->data->targetAcceptKey === $targetAcceptKey,
        ));

        $this->assertCount(1, $matching);
        $webSocketData = $matching[0]->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
        $this->assertInstanceOf(BrowserPageSignalData::class, $webSocketData->data);

        return $webSocketData->data->toArray();
    }

    /**
     * Drains queued sync signals through browser and returns main browser signals.
     *
     * @return list<SignalDTO>
     */
    private function drainMainBrowserSignals(): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $this->recordFrontendSourceChange($signal);
        }
        Hilos::$browser?->flushToSignalRouter();

        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->signalName->getName() === ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * Records one queued DB/RT sync signal as a browser source change.
     *
     * @param SignalDTO $signal Queued sync signal to inspect
     */
    private function recordFrontendSourceChange(SignalDTO $signal): void
    {
        $change = $this->sourceChangeFromSignal($signal);
        if ($change === null) {
            return;
        }
        Hilos::$browser?->record($change);
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
     * Finds a browser row by numeric id in one source fragment.
     *
     * @param list<array<string, mixed>> $rows Browser rows
     * @param string $sourceKey Source fragment key
     * @param int $id Source id to match
     * @return ?array<string, mixed> Matching row, or null
     */
    private function findBrowserRowBySourceId(array $rows, string $sourceKey, int $id): ?array
    {
        return $this->findBrowserRowBySourceField($rows, $sourceKey, 'id', $id);
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

    /**
     * Asserts browser public payloads do not include acceptKey as a row key or field.
     *
     * @param array<string, mixed> $tables Browser tables payload
     */
    private function assertNoAcceptKeyInBrowserTables(array $tables): void
    {
        $this->assertStringNotContainsString('acceptKey', json_encode($tables, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('-ak', json_encode($tables, JSON_THROW_ON_ERROR));
    }
}
