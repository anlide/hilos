<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Frontend\ChatFrontendProjection;
use Demo\Chat\Frontend\FrontendStateCollectionKey;
use Demo\Chat\Frontend\SelfConnectionFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Frontend\SourceChange;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for worker-local projection of chat runtime state.
 */
final class ChatFrontendProjectionTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testOutboundModerationProjectionTargetsOriginConnection(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('moderation-ak', $user->id);
            Hilos::$rt->connections->actions->register('other-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);

            $this->resetProjectionRouter();
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'moderation-ak',
                PageConstants::MAIN,
                [],
            ));
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'other-ak',
                PageConstants::MAIN,
                [],
            ));

            Hilos::$rt->connections['moderation-ak']?->actions->startOutboundModeration(
                'pending text',
            );

            $signals = $this->drainProjectedSignals(ChatSignalConstants::SELF_CONNECTION_UPDATE);

            $this->assertCount(1, $signals);
            $webSocketData = $signals[0]->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
            $this->assertSame('moderation-ak', $webSocketData->targetAcceptKey);
            $payload = $webSocketData->data;
            $this->assertInstanceOf(SelfConnectionSignalData::class, $payload);
            $payloadData = $payload->toArray();
            $selfConnection = $payloadData['frontend']['full'][FrontendStateCollectionKey::SELF_CONNECTION][0] ?? [];
            $this->assertSame(SelfConnectionFrontendStateProjector::ID_SELF, $selfConnection['id'] ?? null);
            $this->assertSame(
                'checking',
                $selfConnection['outboundModerationState']['phase'] ?? null,
            );
            $this->assertSame(
                'pending text',
                $selfConnection['outboundModerationState']['text'] ?? null,
            );
            $this->assertSame(
                [FrontendStateCollectionKey::SELF_CONNECTION],
                $payloadData['frontend']['replaceFull'] ?? null,
            );
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
        }
    }

    public function testAttachmentDraftProjectionTargetsOwningConnection(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('draft-ak', $user->id);

            $this->resetProjectionRouter();
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'draft-ak',
                PageConstants::MAIN,
                [],
            ));

            Hilos::$rt->attachmentDrafts->actions->create(
                'draft-projection',
                'draft-ak',
                $user->id,
                '',
                'projection.txt',
                'text/plain',
                12,
                'projection.txt',
                time(),
            );

            $signals = $this->drainProjectedSignals(ChatSignalConstants::SELF_CONNECTION_UPDATE);

            $this->assertCount(1, $signals);
            $webSocketData = $signals[0]->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
            $this->assertSame('draft-ak', $webSocketData->targetAcceptKey);
            $payload = $webSocketData->data;
            $this->assertInstanceOf(SelfConnectionSignalData::class, $payload);
            $payloadData = $payload->toArray();
            $this->assertSame(
                'draft-projection',
                $payloadData['frontend']['full'][FrontendStateCollectionKey::ATTACHMENT_DRAFTS][0]['draftId'] ?? null,
            );
            $this->assertSame(
                [FrontendStateCollectionKey::ATTACHMENT_DRAFTS],
                $payloadData['frontend']['replaceFull'] ?? null,
            );
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        }
    }

    public function testUploadFailureProjectionTargetsOriginConnection(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('upload-fail-ak', $user->id);

            $this->resetProjectionRouter();
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'upload-fail-ak',
                PageConstants::MAIN,
                [],
            ));

            Hilos::$rt->connections['upload-fail-ak']?->actions->failBinaryFileUpload(
                'client-upload-fail',
                'size_limit',
                'File exceeds maximum allowed size',
            );

            $signals = $this->drainProjectedSignals(ChatSignalConstants::SELF_CONNECTION_UPDATE);

            $this->assertCount(1, $signals);
            $payload = $signals[0]->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $payload);
            $selfConnection = $payload->data->toArray()['frontend']['full'][FrontendStateCollectionKey::SELF_CONNECTION][0] ?? [];
            $this->assertSame(
                [
                    SelfConnectionSignalData::phase => Connection::FILE_UPLOAD_PHASE_FAILED,
                    SelfConnectionSignalData::clientUploadId => 'client-upload-fail',
                    SelfConnectionSignalData::errorCode => 'size_limit',
                    SelfConnectionSignalData::errorMessage => 'File exceeds maximum allowed size',
                ],
                $selfConnection[SelfConnectionSignalData::fileUploadState] ?? null,
            );
            $this->assertNull($selfConnection[SelfConnectionSignalData::fileUploadProgress] ?? null);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    public function testUploadProgressProjectionUsesThrottleMarker(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('upload-ak', $user->id);

            $this->resetProjectionRouter();
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'upload-ak',
                PageConstants::MAIN,
                [],
            ));

            Hilos::$rt->connections['upload-ak']?->actions->beginBinaryFileUpload(
                'upload-projection',
                1024,
                'tmp-upload-projection',
                'projection.txt',
                'text/plain',
                'client-upload-projection',
                'projection.txt',
                'projection.txt',
                1024,
            );
            $signals = $this->drainProjectedSignals(ChatSignalConstants::SELF_CONNECTION_UPDATE);

            $this->assertCount(1, $signals);
            $payload = $signals[0]->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $payload);
            $selfConnection = $payload->data->toArray()['frontend']['full'][FrontendStateCollectionKey::SELF_CONNECTION][0] ?? [];
            $this->assertSame(
                [
                    SelfConnectionSignalData::phase => Connection::FILE_UPLOAD_PHASE_READY,
                    SelfConnectionSignalData::clientUploadId => 'client-upload-projection',
                    SelfConnectionSignalData::errorCode => null,
                    SelfConnectionSignalData::errorMessage => null,
                ],
                $selfConnection[SelfConnectionSignalData::fileUploadState] ?? null,
            );
            $this->assertSame(
                [
                    SelfConnectionSignalData::filename => 'projection.txt',
                    SelfConnectionSignalData::uploadedBytes => 0,
                    SelfConnectionSignalData::totalBytes => 1024,
                ],
                $selfConnection[SelfConnectionSignalData::fileUploadProgress] ?? null,
            );

            $beforeFirstProgressMarker = microtime(true);
            Hilos::$rt->connections['upload-ak']?->actions->noteUploadProgressSentAt();
            $afterFirstProgressMarker = microtime(true);
            $signals = $this->drainProjectedSignals(ChatSignalConstants::SELF_CONNECTION_UPDATE);

            $this->assertGreaterThanOrEqual(
                $beforeFirstProgressMarker,
                Hilos::$rt->connections['upload-ak']?->uploadProgressLastSentAt,
            );
            $this->assertLessThanOrEqual(
                $afterFirstProgressMarker,
                Hilos::$rt->connections['upload-ak']?->uploadProgressLastSentAt,
            );
            $this->assertCount(1, $signals);
            $payload = $signals[0]->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $payload);
            $selfConnection = $payload->data->toArray()['frontend']['full'][FrontendStateCollectionKey::SELF_CONNECTION][0] ?? [];
            $this->assertSame(
                [
                    SelfConnectionSignalData::filename => 'projection.txt',
                    SelfConnectionSignalData::uploadedBytes => 0,
                    SelfConnectionSignalData::totalBytes => 1024,
                ],
                $selfConnection[SelfConnectionSignalData::fileUploadProgress] ?? null,
            );

            Hilos::$rt->connections['upload-ak']?->actions->applyStoredBinaryChunkProgress(512);
            $this->assertSame([], $this->drainProjectedSignals(ChatSignalConstants::SELF_CONNECTION_UPDATE));

            $previousProgressMarker = Hilos::$rt->connections['upload-ak']?->uploadProgressLastSentAt;
            Hilos::$rt->connections['upload-ak']?->actions->noteUploadProgressSentAt();
            $signals = $this->drainProjectedSignals(ChatSignalConstants::SELF_CONNECTION_UPDATE);

            $this->assertGreaterThan(
                $previousProgressMarker,
                Hilos::$rt->connections['upload-ak']?->uploadProgressLastSentAt,
            );
            $this->assertCount(1, $signals);
            $payload = $signals[0]->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $payload);
            $selfConnection = $payload->data->toArray()['frontend']['full'][FrontendStateCollectionKey::SELF_CONNECTION][0] ?? [];
            $this->assertSame(
                Connection::FILE_UPLOAD_PHASE_UPLOADING,
                $selfConnection[SelfConnectionSignalData::fileUploadState][SelfConnectionSignalData::phase] ?? null,
            );
            $this->assertSame(
                512,
                $selfConnection[SelfConnectionSignalData::fileUploadProgress][SelfConnectionSignalData::uploadedBytes]
                    ?? null,
            );
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    private function resetProjectionRouter(): void
    {
        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::$frontend = new ChatFrontendProjection();
    }

    /**
     * @return list<SignalDTO>
     */
    private function drainProjectedSignals(string $signalName): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $this->recordProjectionSourceChange($signal);
        }

        Hilos::$frontend?->flushToSignalRouter();

        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->signalName->getName() === $signalName) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function recordProjectionSourceChange(SignalDTO $signal): void
    {
        $signalType = $signal->signalType->getType();
        $signalData = $signal->data;

        if ($signalType === SignalTypeConstants::RT_SYNC_CREATED && $signalData instanceof RtSyncCreatedSignalData) {
            Hilos::$frontend?->record(
                SourceChange::rtCreated($signalData->collectionKey, $signalData->stateId, $signalData->row),
            );
            return;
        }

        if ($signalType === SignalTypeConstants::RT_SYNC_UPDATED && $signalData instanceof RtSyncUpdatedSignalData) {
            Hilos::$frontend?->record(
                SourceChange::rtUpdated($signalData->collectionKey, $signalData->stateId, $signalData->row),
            );
            return;
        }

        if ($signalType === SignalTypeConstants::RT_SYNC_DELETED && $signalData instanceof RtSyncDeletedSignalData) {
            Hilos::$frontend?->record(
                SourceChange::rtDeleted($signalData->collectionKey, $signalData->stateId, $signalData->row),
            );
        }
    }
}
