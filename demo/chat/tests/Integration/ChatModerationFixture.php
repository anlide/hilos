<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Hilos;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Files\Upload\DTO\UploadHoldSettleSignalData;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\View\Item\HilosModerationCheck as ModerationCheck;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Real chat routes and the fixture writer's chat-only check set. */
trait ChatModerationFixture
{
    private function claimChatChecks(): void
    {
        RtTruthSourceRegistry::register(
            HilosModerationCheck::RT_COLLECTION,
            TruthSourceKeys::set(AgentType::CHAT),
            'test-agent',
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
        );
        Hilos::initSignalRouter(new ChatSignalRouter());
    }

    private function forgetChatChecks(): void
    {
        foreach (Hilos::$rt->hilosModerationChecks as $check) {
            if ($check->verdictTo === AgentType::CHAT) {
                $check->actions->forget();
            }
        }
    }

    private function deliverChatSignal(ChatAgent $agent, string $name, SignalDataInterface $payload): void
    {
        $router = new ChatSignalRouter();
        // Hydrate from a loose transport payload, just as the worker does.
        $signal = new AgentSignalData(new SignalData($payload->toArray()));
        self::assertEquals([new AgentDestination(AgentType::CHAT)], $router->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($name),
            $signal,
        )));
        $agent->onSignalAgent($router->createAgentSignalPayloadDTO($name, $signal), 'moderator', $name);
    }

    private function decideCheck(ChatAgent $agent, ModerationCheck $check, bool $allow = true, string $reason = 'ok'): void
    {
        $this->deliverChatSignal($agent, HilosSignalConstants::HILOS_MODERATION_VERDICT, new ModerationVerdictSignalData(
            $check->acceptKey,
            $check->kind,
            $check->requestId,
            $allow,
            $reason,
        ));
    }

    private function deliverMessageDecision(
        ChatAgent $agent,
        string $acceptKey,
        bool $allow,
        string $reason,
        ?string $picture = null,
    ): void {
        $agent->startMessageModeration($acceptKey);
        $check = Hilos::$rt->hilosModerationChecks->find($acceptKey, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($check);
        $this->acknowledgeChatUploads($check);
        if ($picture !== null) {
            $this->decideCheck($agent, $check);
            $check = Hilos::$rt->hilosModerationChecks->find($acceptKey, ChatModerationKindKey::MESSAGE_PICTURE);
            self::assertNotNull($check);
            self::assertSame($picture, $check->clientUploadId);
        }
        $this->decideCheck($agent, $check, $allow, $reason);
    }

    private function completeUpload(
        string $acceptKey,
        int $userId,
        string $clientUploadId,
        string $filename,
        string $mimeType,
        string $bytes,
    ): void {
        RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), 'test-agent');
        $tmpIndex = Hilos::$fs?->getTmp()->create();
        self::assertNotNull($tmpIndex);
        Hilos::$fs?->getTmp()[$tmpIndex]->append($bytes);
        Hilos::$rt->hilosUploads->actions->open(
            $acceptKey,
            $clientUploadId,
            ChatAttachmentUploadTarget::NAME,
            $userId,
            $filename,
            $mimeType,
            strlen($bytes),
            $tmpIndex,
        )->actions->inspect(hash('sha256', $bytes), $mimeType);
        Hilos::$rt->hilosUploads->find($acceptKey, $clientUploadId)->actions->complete();
    }

    /**
     * Runs real owner admission before a synthetic model outcome. This stays in one process
     * and proves the consumer contract, not inter-worker ordering.
     *
     * @param ModerationCheck $check Actual initial check with its immutable snapshot
     */
    private function acknowledgeChatUploads(ModerationCheck $check): void
    {
        $this->withUploadsOwner(fn (UploadsAgent $owner) => $owner->onSignalRtSyncCreated(
            new RtSyncCreatedSignalData(HilosModerationCheck::RT_COLLECTION, $check->getId(), $check->toArray()), '', '',
        ));
    }

    /** @param UploadHoldSettleSignalData $settlement Actual queued consumer outcome */
    private function applyUploadSettlement(UploadHoldSettleSignalData $settlement): void
    {
        $router = new ChatSignalRouter();
        $signal = $router->createAgentSignalPayloadDTO(
            HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE,
            new AgentSignalData(new SignalData($settlement->toArray())),
        );
        $this->withUploadsOwner(fn (UploadsAgent $owner) => $owner->onSignalAgent(
            $signal, SignalSource::AGENT, HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE,
        ));
    }

    /** @param callable(UploadsAgent): void $dispatch Owner's real handler, under its own claim */
    private function withUploadsOwner(callable $dispatch): void
    {
        $owner = new UploadsAgent();
        $alreadyClaimed = in_array(HilosUpload::RT_COLLECTION, RtTruthSourceRegistry::collectionsOf($owner->getId()), true);
        if (!$alreadyClaimed) {
            RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), $owner->getId());
        }
        try {
            $this->underAgent($owner, fn () => $dispatch($owner));
        } finally {
            if (!$alreadyClaimed) {
                RtTruthSourceRegistry::unregister(HilosUpload::RT_COLLECTION, $owner->getId());
            }
        }
    }
}
