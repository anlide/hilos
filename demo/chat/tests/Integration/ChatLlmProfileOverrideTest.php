<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\Moderation\ModerationLlmConstants;
use Hilos\Moderation\ModerationSettingsCatalog;

/**
 * Integration coverage for the settings-override of chat LLM profiles (HIL-262).
 *
 * Hilos::$llm is wired with ChatLlmProfileOverrideSource for the bot profile;
 * framework moderation profiles resolve from their own settings, while the
 * analyzer keeps resolving from env.
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

    public function testFrameworkModerationProfilesResolveFromSettings(): void
    {
        $text = Hilos::$llm->resolve(ModerationLlmConstants::PROFILE_TEXT);
        $image = Hilos::$llm->resolve(ModerationLlmConstants::PROFILE_IMAGE);

        self::assertSame(LlmProvider::LOCAL, $text->provider);
        self::assertSame(
            Hilos::$setting[ModerationSettingsCatalog::TEXT_MODEL]->string(),
            $text->model,
        );
        self::assertSame(LlmProvider::LOCAL, $image->provider);
        self::assertSame(
            Hilos::$setting[ModerationSettingsCatalog::IMAGE_MODEL]->string(),
            $image->model,
        );
    }

    public function testAnalyzerProfileStaysEnvDrivenNotOverridden(): void
    {
        // The analyzer has no settings; the override passes it through, so its
        // model comes from env, not a setting.
        $analyzer = Hilos::$llm->resolve(ChatLLMConstants::PROFILE_ANALYZER);

        self::assertSame(LlmProvider::LOCAL, $analyzer->provider);
        self::assertSame(Hilos::$env[EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL]->string(), $analyzer->model);
    }
}
