<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMResultUnavailableException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/**
 * Request boundaries on one running bot agent.
 */
final class BotAgentRefreshTest extends IntegrationTestCase
{
    public function testChangedModelFailsThenRecoversOnLaterRequest(): void
    {
        $setting = EntitySetting::get([EntitySetting::key => ChatSettingsConstants::DEFAULT_BOT_MODEL])->first();
        $this->assertNotNull($setting);
        $original = $setting->value;
        $bot = Hilos::$db->bots->actions->create('Refresh Bot');
        $this->assertNotNull($bot->id);
        $bot->getObject()->reactionDelayMin = 0;
        $bot->getObject()->reactionDelayMax = 0;
        $bot->getObject()->reactionChance = 100;
        $bot->getObject()->cooldownAfterMessage = 0;
        $bot->getObject()->sync();

        $agentId = 'bot:' . $bot->id . ':refresh-test';
        RtTruthSourceRegistry::register(ChatRtContext::botAgentStatuses, TruthSourceKeys::listed((string)$bot->id), $agentId);
        ExecutionContext::setCurrentAgentId($agentId);

        try {
            Hilos::initSignalRouter(new ChatSignalRouter());
            $agent = new BotAgent((string)$bot->id);
            $firstClient = new CompletedBotChatClient();
            (new ReflectionProperty($agent, 'chatClient'))->setValue($agent, $firstClient);
            $agent->onStart();
            $agent->onTick();
            $this->assertSame($original, $firstClient->lastOptions?->model);

            $setting->value = '';
            $setting->save();
            $agent->onTick();
            $this->assertSame($original, (new ReflectionProperty($agent, 'profile'))->getValue($agent)->model);
            $this->assertSame(1, $firstClient->startGenerateCalls);

            $this->scheduleAgain($agent);
            $agent->onTick();
            $this->assertSame(1, $firstClient->startGenerateCalls);
            $this->assertSame('', (new ReflectionProperty($agent, 'profile'))->getValue($agent)->model);

            $setting->value = 'recovered-model';
            $setting->save();
            $this->scheduleAgain($agent);
            $agent->onTick();
            $this->assertSame('recovered-model', (new ReflectionProperty($agent, 'profile'))->getValue($agent)->model);
            $this->assertNotSame($firstClient, (new ReflectionProperty($agent, 'chatClient'))->getValue($agent));
        } finally {
            $setting->value = $original;
            $setting->save();
            if (isset($agent)) {
                $agent->onStop();
            }
            RtTruthSourceRegistry::unregisterAgent($agentId);
            ExecutionContext::setCurrentAgentId(null);
            $bot->actions->delete();
        }
    }

    private function scheduleAgain(BotAgent $agent): void
    {
        $agent->onSignalRtSyncUpdated(
            new RtSyncUpdatedSignalData(ChatRtContext::chatContext, 'main', []),
            'rt',
            'rt_sync_updated',
        );
    }
}

final class CompletedBotChatClient implements AsyncChatLLMInterface
{
    public int $startGenerateCalls = 0;
    public ?ChatGenerateOptions $lastOptions = null;
    private bool $busy = false;
    private bool $hasResult = false;

    /** @param list<Message> $messages Prompt messages */
    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->startGenerateCalls++;
        $this->lastOptions = $options;
        $this->busy = true;
    }

    public function tick(float $currentTimeMs): void
    {
        if ($this->busy) {
            $this->busy = false;
            $this->hasResult = true;
        }
    }

    public function hasResult(): bool
    {
        return $this->hasResult;
    }

    public function consumeResult(): string
    {
        if (!$this->hasResult) {
            throw new LLMResultUnavailableException('No bot result');
        }
        $this->hasResult = false;

        return 'A bot reply';
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function reset(): void
    {
        $this->busy = false;
        $this->hasResult = false;
    }
}
