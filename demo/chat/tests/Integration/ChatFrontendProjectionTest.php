<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Frontend\ChatFrontendProjection;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
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
                'request-projection',
                'pending text',
            );

            $signals = $this->drainProjectedSignals(ChatSignalConstants::SELF_CONNECTION_UPDATE);

            $this->assertCount(1, $signals);
            $webSocketData = $signals[0]->data;
            $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
            $this->assertSame('moderation-ak', $webSocketData->targetAcceptKey);
            $payload = $webSocketData->data;
            $this->assertInstanceOf(SelfConnectionSignalData::class, $payload);
            $this->assertSame(
                'request-projection',
                $payload->selfConnection['outboundModerationState']['requestId'] ?? null,
            );
            $this->assertSame(
                'pending text',
                $payload->selfConnection['outboundModerationState']['text'] ?? null,
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
                $payloadData['frontend']['full']['attachmentDrafts'][0]['draftId'] ?? null,
            );
            $this->assertSame(['attachmentDrafts'], $payloadData['frontend']['replaceFull'] ?? null);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
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
