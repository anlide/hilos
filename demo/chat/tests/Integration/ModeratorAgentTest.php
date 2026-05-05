<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use ReflectionProperty;

/**
 * Integration tests for moderator runtime request discovery.
 */
final class ModeratorAgentTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testOnTickDiscoversPendingUserModerationFromRuntimeState(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('moderator-tick-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);
            Hilos::$rt->connections['moderator-tick-ak']?->actions->startOutboundModeration(
                'moderate me from runtime',
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $agent = new ModeratorAgent();
            $chatClient = new UnavailableModerationChatClient();
            self::replaceChatClient($agent, $chatClient);

            $agent->onTick();

            $this->assertSame(1, $chatClient->startGenerateCalls);
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

    private static function replaceChatClient(ModeratorAgent $agent, AsyncChatLLMInterface $chatClient): void
    {
        $property = new ReflectionProperty(ModeratorAgent::class, 'chatClient');
        $property->setValue($agent, $chatClient);
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
}

final class UnavailableModerationChatClient implements AsyncChatLLMInterface
{
    public int $startGenerateCalls = 0;

    public function startGenerate(array $messages, ChatGenerateOptions $options): bool
    {
        $this->startGenerateCalls++;

        return false;
    }

    public function tick(float $currentTimeMs): void
    {
    }

    public function hasResult(): bool
    {
        return false;
    }

    public function getResult(): ?string
    {
        return null;
    }

    public function isBusy(): bool
    {
        return false;
    }

    public function reset(): void
    {
    }
}
