<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\LLM\Routing\LlmProvider;

/**
 * Integration coverage for the settings-override of chat LLM profiles (HIL-262).
 *
 * Hilos::$llm is wired with ChatLlmProfileOverrideSource, so the bot and
 * moderation profiles resolve from the admin-editable settings, while the
 * analyzer (no settings) keeps resolving from env.
 */
final class ChatLlmProfileOverrideTest extends IntegrationTestCase
{
    public function testBotProfileResolvesFromSettings(): void
    {
        $bot = Hilos::$llm->resolve(ChatLLMConstants::PROFILE_BOT);

        self::assertSame(LlmProvider::LOCAL, $bot->provider);
        self::assertSame(Hilos::$setting[ChatSettingsConstants::CHAT_BOT_MODEL]->string(), $bot->model);
        self::assertNull($bot->apiKey);
    }

    public function testModerationProfileResolvesFromSettings(): void
    {
        $moderation = Hilos::$llm->resolve(ChatLLMConstants::PROFILE_MODERATION);

        self::assertSame(LlmProvider::LOCAL, $moderation->provider);
        self::assertSame(
            Hilos::$setting[ChatSettingsConstants::CHAT_MODERATION_MODEL]->string(),
            $moderation->model,
        );
    }

    public function testAnalyzerProfileStaysEnvDrivenNotOverridden(): void
    {
        // The analyzer has no settings; the override passes it through, so its
        // model comes from env, not a setting.
        $analyzer = Hilos::$llm->resolve(ChatLLMConstants::PROFILE_ANALYZER);

        self::assertSame(LlmProvider::LOCAL, $analyzer->provider);
        self::assertSame(Hilos::$env[EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL], $analyzer->model);
    }
}
