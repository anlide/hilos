<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatContextAnalyzerAgent;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Runtime\View\DTO\ChatContextUpdateData;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\Exception\LLMClientBusyException;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/**
 * Integration coverage for chat context analyzer runtime ownership.
 */
final class ChatContextAnalyzerAgentTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testRuntimeChatContextAliasExposesSingleton(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::chatContext, true, self::TEST_AGENT_ID);

        Hilos::$rt->chatContext->actions->update(
            new ChatContextUpdateData('AI', 0.75, 'Alias summary'),
        );

        $this->assertSame('AI', Hilos::$rt->chatContext->topic);
        $this->assertSame(0.75, Hilos::$rt->chatContext->topicConfidence);
        $this->assertSame('Alias summary', Hilos::$rt->chatContext->summary);
    }

    public function testRuntimeChatContextsCollectionIsNotPublicApi(): void
    {
        $this->expectException(RtCollectionNotFoundException::class);

        Hilos::$rt->chatContexts;
    }

    public function testRuntimeChatContextAppliesRtSyncAsStandaloneItem(): void
    {
        RtSyncApplicator::applyUpdated(
            new RtSyncUpdatedSignalData(
                collectionKey: ChatRtContext::chatContext,
                stateId: StateChatContext::ID_MAIN,
                row: [
                    ChatContextUpdateData::topic => 'AI',
                    ChatContextUpdateData::topicConfidence => 0.9,
                    ChatContextUpdateData::summary => 'Synced summary',
                ],
            ),
            skipSelfBroadcastCheck: false,
        );

        $this->assertSame('AI', Hilos::$rt->chatContext->topic);
        $this->assertSame(0.9, Hilos::$rt->chatContext->topicConfidence);
        $this->assertSame('Synced summary', Hilos::$rt->chatContext->summary);
    }

    public function testChatClearResetsInFlightSummarizationAndRuntimeContext(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::chatContext, true, self::TEST_AGENT_ID);
        Hilos::$db->events->actions->deleteAll();

        try {
            $agent = new ChatContextAnalyzerAgent();
            $chatClient = new ControlledAnalyzerChatClient();
            self::replaceChatClient($agent, $chatClient);

            $agent->onStart();
            Hilos::$rt->chatContext->actions->update(
                new ChatContextUpdateData('AI', 0.75, 'Old summary'),
            );
            Hilos::$db->events->actions->addMessage(
                'first message',
                userId: Hilos::$db->users->actions->createWithName('User')->id,
            );

            $agent->onSignalDbSyncCreated(
                $this->eventSignal(ChatEventType::MESSAGE_SENT),
                SignalSource::DB,
                SignalConstants::DB_SYNC_CREATED,
            );
            $agent->onTick();

            $this->assertSame(1, $chatClient->startGenerateCalls);

            $agent->onSignalDbSyncCreated(
                $this->eventSignal(ChatEventType::CHAT_CLEARED),
                SignalSource::DB,
                SignalConstants::DB_SYNC_CREATED,
            );

            $this->assertSame(1, $chatClient->resetCalls);
            $this->assertNull(Hilos::$rt->chatContext->topic);
            $this->assertSame(0.0, Hilos::$rt->chatContext->topicConfidence);
            $this->assertSame('', Hilos::$rt->chatContext->summary);
        } finally {
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testPendingMessageDuringInFlightSummarizationStartsFollowUpRequest(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::chatContext, true, self::TEST_AGENT_ID);
        Hilos::$db->events->actions->deleteAll();

        try {
            $agent = new ChatContextAnalyzerAgent();
            $chatClient = new ControlledAnalyzerChatClient();
            self::replaceChatClient($agent, $chatClient);

            $agent->onStart();
            Hilos::$db->events->actions->addMessage(
                'first message',
                userId: Hilos::$db->users->actions->createWithName('User')->id,
            );
            $agent->onSignalDbSyncCreated(
                $this->eventSignal(ChatEventType::MESSAGE_SENT),
                SignalSource::DB,
                SignalConstants::DB_SYNC_CREATED,
            );
            $agent->onTick();

            Hilos::$db->events->actions->addMessage(
                'second message',
                userId: Hilos::$db->users->actions->createWithName('User')->id,
            );
            $agent->onSignalDbSyncCreated(
                $this->eventSignal(ChatEventType::MESSAGE_SENT),
                SignalSource::DB,
                SignalConstants::DB_SYNC_CREATED,
            );
            $chatClient->completeWith((string)json_encode([
                ChatContextUpdateData::topic => 'AI',
                ChatContextUpdateData::topicConfidence => 0.8,
                ChatContextUpdateData::summary => 'Updated summary',
            ]));
            $agent->onTick();

            $this->assertSame(2, $chatClient->startGenerateCalls);
            $this->assertSame('Updated summary', Hilos::$rt->chatContext->summary);
        } finally {
            Hilos::$db->events->actions->deleteAll();
        }
    }

    private static function replaceChatClient(
        ChatContextAnalyzerAgent $agent,
        AsyncChatLLMInterface $chatClient,
    ): void {
        $property = new ReflectionProperty(ChatContextAnalyzerAgent::class, 'chatClient');
        $property->setValue($agent, $chatClient);
    }

    private function eventSignal(ChatEventType $eventType): DbSyncCreatedSignalData
    {
        return new DbSyncCreatedSignalData(
            collectionKey: ChatDbContext::events,
            idString: '1',
            row: [ObjectEvent::type => $eventType->value],
        );
    }
}

final class ControlledAnalyzerChatClient implements AsyncChatLLMInterface
{
    public int $startGenerateCalls = 0;

    public int $resetCalls = 0;

    private bool $busy = false;

    private bool $hasResult = false;

    private ?string $result = null;

    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->startGenerateCalls++;

        if ($this->busy) {
            throw new LLMClientBusyException();
        }

        $this->busy = true;
    }

    public function tick(float $currentTimeMs): void
    {
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

        return $result ?? '';
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
        $this->result = null;
    }

    public function completeWith(string $result): void
    {
        $this->busy = false;
        $this->hasResult = true;
        $this->result = $result;
    }
}
