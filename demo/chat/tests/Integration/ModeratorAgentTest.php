<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\LLM\Exception\LLMResultUnavailableException;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMClientBusyException;
use Hilos\LLM\Exception\LLMRequestException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Runtime\State\Item\HilosProfilePhotoCheck;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Users\DTO\ProfilePhotoVerdictSignalData;
use ReflectionProperty;

/**
 * Integration tests for moderator runtime request discovery.
 */
final class ModeratorAgentTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testOnTickDiscoversPendingUserModerationFromRuntimeState(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('moderator-tick-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);
            Hilos::$rt->connections['moderator-tick-ak']?->actions->startOutboundModeration(
                'moderate me from runtime',
                [],
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $agent = new ModeratorAgent();
            $chatClient = new UnavailableModerationChatClient();
            self::replaceChatClient($agent, $chatClient);

            $agent->onTick();

            $this->assertSame(1, $chatClient->startGenerateCalls);
            $this->assertNotNull($chatClient->lastOptions);
            $this->assertNull($chatClient->lastOptions->temperature);
            $result = $this->takeQueuedModerationResult();
            $this->assertNotNull($result);
            $this->assertSame('moderator-tick-ak', $result->acceptKey);
            $this->assertSame($user->id, $result->userId);
            $this->assertSame('moderate me from runtime', $result->message);
            $this->assertFalse($result->allow);
            $this->assertSame('service_unavailable', $result->reason);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
        }
    }

    public function testOnTickDiscoversPendingRenameModerationFromRuntimeState(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('moderator-rename-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);
            Hilos::$rt->connections['moderator-rename-ak']?->actions->startRenameModeration(
                'Blocked Name',
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $agent = new ModeratorAgent();
            $chatClient = new CompletedModerationChatClient('{"allow": false, "reason": "insult"}');
            self::replaceChatClient($agent, $chatClient);

            $agent->onTick();
            $agent->onTick();

            $this->assertSame(1, $chatClient->startGenerateCalls);
            $result = $this->takeQueuedRenameModerationResult();
            $this->assertNotNull($result);
            $this->assertSame('moderator-rename-ak', $result->acceptKey);
            $this->assertSame($user->id, $result->userId);
            $this->assertSame('Blocked Name', $result->newName);
            $this->assertFalse($result->allow);
            $this->assertSame('insult', $result->reason);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
        }
    }

    public function testOnTickSendsACompletedPhotoToTheVisionClientAndRoutesItsVerdict(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(HilosProfilePhotoCheck::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        $tmpIndex = Hilos::$fs?->getTmp()->create();
        $this->assertNotNull($tmpIndex);
        Hilos::$fs?->getTmp()[$tmpIndex]->append('jpeg-fixture');

        try {
            $user = Hilos::$db->users->actions->createWithName('Photo User');
            Hilos::$rt->connections->actions->register('moderator-photo-ak', $user->id);
            $upload = Hilos::$rt->hilosUploads->actions->open(
                'moderator-photo-ak',
                'photo-upload',
                ProfilePhotoUploadTarget::NAME,
                $user->id,
                'photo.jpg',
                'image/jpeg',
                strlen('jpeg-fixture'),
                $tmpIndex,
            );
            $upload->actions->complete();
            Hilos::$rt->hilosProfilePhotoChecks->actions->open('moderator-photo-ak', $user->id, 'photo-upload');

            Hilos::initSignalRouter(new ChatSignalRouter());
            $agent = new ModeratorAgent();
            $photoClient = new CompletedModerationChatClient('{"allow": false, "reason": "nudity"}');
            self::replaceChatClient($agent, $photoClient, 'photoClient');

            $agent->onTick();
            $agent->onTick();

            $this->assertSame(1, $photoClient->startGenerateCalls);
            $this->assertNotNull($photoClient->lastOptions);
            $this->assertNull($photoClient->lastOptions->temperature);
            $this->assertCount(2, $photoClient->messages);
            $this->assertSame(Message::ROLE_USER, $photoClient->messages[1]->role);
            $this->assertStringContainsString('User: Photo User', $photoClient->messages[1]->content);
            $this->assertSame(base64_encode('jpeg-fixture'), $photoClient->messages[1]->images[0]->base64);
            $result = $this->takeQueuedPhotoVerdict();
            $this->assertNotNull($result);
            $this->assertSame('moderator-photo-ak', $result->acceptKey);
            $this->assertSame('photo-upload', $result->clientUploadId);
            $this->assertFalse($result->allow);
            $this->assertSame('nudity', $result->reason);

            Hilos::$rt->hilosProfilePhotoChecks['moderator-photo-ak']?->actions->forget();
            $agent->onSignalRtSyncDeleted(
                new RtSyncDeletedSignalData(HilosProfilePhotoCheck::RT_COLLECTION, 'moderator-photo-ak'),
                'rt',
                'rt_sync_deleted',
            );
            $this->assertSame(1, $photoClient->resetCalls);
        } finally {
            Hilos::$rt->hilosProfilePhotoChecks['moderator-photo-ak']?->actions->forget();
            Hilos::$rt->hilosUploads->find('moderator-photo-ak', 'photo-upload')?->actions->forgetWithFile();
            Hilos::$rt->connections->actions->clear();
            Hilos::$fs?->getTmp()[$tmpIndex]->unlink();
        }
    }

    public function testOnTickConvertsInvalidModerationModelOutputToUnknownDenial(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('moderator-invalid-output-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);
            Hilos::$rt->connections['moderator-invalid-output-ak']?->actions->startOutboundModeration(
                'bad model response should deny',
                [],
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $agent = new ModeratorAgent();
            $chatClient = new CompletedModerationChatClient('not json');
            self::replaceChatClient($agent, $chatClient);

            $agent->onTick();
            $agent->onTick();

            $this->assertSame(1, $chatClient->startGenerateCalls);
            $result = $this->takeQueuedModerationResult();
            $this->assertNotNull($result);
            $this->assertSame('moderator-invalid-output-ak', $result->acceptKey);
            $this->assertFalse($result->allow);
            $this->assertSame('unknown', $result->reason);

            Hilos::$rt->connections['moderator-invalid-output-ak']?->actions->clearOutboundModeration();
            $connection = Hilos::$rt->connections['moderator-invalid-output-ak'];
            $this->assertNotNull($connection);
            $agent->onSignalRtSyncUpdated(
                new RtSyncUpdatedSignalData(
                    collectionKey: ChatRtContext::connections,
                    stateId: 'moderator-invalid-output-ak',
                    row: [
                        StateConnection::outboundModerationPhase => $connection->outboundModerationPhase,
                        StateConnection::outboundModerationUpdatedAt => $connection->outboundModerationUpdatedAt,
                    ],
                ),
                'rt',
                'rt_sync_updated',
            );

            Hilos::$rt->connections['moderator-invalid-output-ak']?->actions->startOutboundModeration(
                'second moderation request',
                [],
            );
            $agent->onTick();

            $this->assertSame(2, $chatClient->startGenerateCalls);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
        }
    }

    public function testRtUpdateCancelsInFlightModerationRequest(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('moderator-in-flight-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);
            Hilos::$rt->connections['moderator-in-flight-ak']?->actions->startOutboundModeration(
                'first moderation request',
                [],
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $agent = new ModeratorAgent();
            $chatClient = new CompletedModerationChatClient('{"allow": true, "reason": "ok"}');
            self::replaceChatClient($agent, $chatClient);

            $agent->onTick();
            $this->assertSame(1, $chatClient->startGenerateCalls);

            $agent->onSignalRtSyncDeleted(
                new RtSyncDeletedSignalData(HilosProfilePhotoCheck::RT_COLLECTION, 'moderator-in-flight-ak'),
                'rt',
                'rt_sync_deleted',
            );
            $this->assertSame(0, $chatClient->resetCalls);

            Hilos::$rt->connections['moderator-in-flight-ak']?->actions->clearOutboundModeration();
            $connection = Hilos::$rt->connections['moderator-in-flight-ak'];
            $this->assertNotNull($connection);
            $agent->onSignalRtSyncUpdated(
                new RtSyncUpdatedSignalData(
                    collectionKey: ChatRtContext::connections,
                    stateId: 'moderator-in-flight-ak',
                    row: [
                        StateConnection::outboundModerationPhase => $connection->outboundModerationPhase,
                        StateConnection::outboundModerationUpdatedAt => $connection->outboundModerationUpdatedAt,
                    ],
                ),
                'rt',
                'rt_sync_updated',
            );

            $this->assertSame(1, $chatClient->resetCalls);

            Hilos::$rt->connections['moderator-in-flight-ak']?->actions->startOutboundModeration(
                'second moderation request',
                [],
            );
            $agent->onTick();

            $this->assertSame(2, $chatClient->startGenerateCalls);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
        }
    }

    private static function replaceChatClient(
        ModeratorAgent $agent,
        AsyncChatLLMInterface $chatClient,
        string $propertyName = 'chatClient',
    ): void
    {
        $property = new ReflectionProperty(ModeratorAgent::class, $propertyName);
        $property->setValue($agent, $chatClient);
    }

    private function takeQueuedPhotoVerdict(): ?ProfilePhotoVerdictSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT) {
                continue;
            }

            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $this->assertInstanceOf(ProfilePhotoVerdictSignalData::class, $signal->data->data);

            return $signal->data->data;
        }

        return null;
    }

    private function takeQueuedModerationResult(): ?ModerationResultSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== ChatSignalConstants::MODERATION_RESULT) {
                continue;
            }

            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $this->assertInstanceOf(ModerationResultSignalData::class, $signal->data->data);

            return $signal->data->data;
        }

        return null;
    }

    private function takeQueuedRenameModerationResult(): ?RenameModerationResultSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== ChatSignalConstants::RENAME_MODERATION_RESULT) {
                continue;
            }

            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $this->assertInstanceOf(RenameModerationResultSignalData::class, $signal->data->data);

            return $signal->data->data;
        }

        return null;
    }
}

final class UnavailableModerationChatClient implements AsyncChatLLMInterface
{
    public int $startGenerateCalls = 0;

    public ?ChatGenerateOptions $lastOptions = null;

    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->startGenerateCalls++;
        $this->lastOptions = $options;

        throw new LLMRequestException('Moderation test client unavailable');
    }

    public function tick(float $currentTimeMs): void
    {
    }

    public function hasResult(): bool
    {
        return false;
    }

    public function consumeResult(): string
    {
        throw new LLMResultUnavailableException('This client never produces a result');
    }

    public function isBusy(): bool
    {
        return false;
    }

    public function reset(): void
    {
    }
}

final class CompletedModerationChatClient implements AsyncChatLLMInterface
{
    public int $startGenerateCalls = 0;

    public int $resetCalls = 0;

    /** @var list<Message> */
    public array $messages = [];

    public ?ChatGenerateOptions $lastOptions = null;

    private bool $busy = false;

    private bool $hasResult = false;

    public function __construct(
        private ?string $result,
    ) {
    }

    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->startGenerateCalls++;
        $this->messages = $messages;
        $this->lastOptions = $options;

        if ($this->busy) {
            throw new LLMClientBusyException();
        }

        $this->busy = true;
    }

    public function tick(float $currentTimeMs): void
    {
        if (!$this->busy) {
            return;
        }

        $this->busy = false;
        $this->hasResult = true;
    }

    public function hasResult(): bool
    {
        return $this->hasResult;
    }

    public function consumeResult(): string
    {
        $result = $this->result;
        $this->hasResult = false;
        $this->result = null;
        if ($result === null) {
            throw new LLMResultUnavailableException('No scripted result is available');
        }

        return $result;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function reset(): void
    {
        $this->resetCalls++;
        $this->busy = false;
        $this->hasResult = false;
    }
}
