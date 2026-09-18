<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Environment\ChatLlmProfileOverrideSource;
use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProvider;

/**
 * Integration coverage for the settings-override of chat LLM profiles (HIL-262).
 *
 * Hilos::$llm is wired with ChatLlmProfileOverrideSource, so the bot and
 * moderation profiles resolve from the admin-editable settings, while the
 * analyzer (no settings) keeps resolving from env. An empty URL setting is not
 * an address of its own: the role keeps the local address env gave it (HIL-927).
 */
final class ChatLlmProfileOverrideTest extends IntegrationTestCase
{
    /** The address the test stand gives the moderation role alone, the way env resolves it. */
    private const string ROLE_URL_FROM_ENV = 'https://stand-gateway:18000/model';

    private const string EXTERNAL_URL_FROM_ENV = 'https://external.invalid/v1';

    private const string EXTERNAL_API_KEY_FROM_ENV = 'external-api-key';

    private const string MODEL_FROM_ENV = 'stand';

    private const float TIMEOUT_FROM_ENV_SEC = 30.0;

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
        self::assertSame(Hilos::$env[EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL]->string(), $analyzer->model);
    }

    public function testEmptyUrlSettingKeepsTheRoleAddressFromEnv(): void
    {
        // The premise: nobody set the moderation URL, so the override has nothing of its own to say.
        self::assertSame('', Hilos::$setting[ChatSettingsConstants::CHAT_MODERATION_URL]->string());

        $moderation = (new ChatLlmProfileOverrideSource())->override(new LlmProfile(
            ChatLLMConstants::PROFILE_MODERATION,
            LlmProvider::LOCAL,
            self::ROLE_URL_FROM_ENV,
            self::MODEL_FROM_ENV,
            null,
            self::TIMEOUT_FROM_ENV_SEC,
        ));

        self::assertSame(self::ROLE_URL_FROM_ENV, $moderation->url);
    }

    public function testEmptyUrlSettingNeverHandsALocalClientTheExternalEndpoint(): void
    {
        // Env resolved the role as external, the provider setting keeps it local: the env
        // profile's address is the external endpoint, and a local client cannot talk to it.
        $moderation = (new ChatLlmProfileOverrideSource())->override(new LlmProfile(
            ChatLLMConstants::PROFILE_MODERATION,
            LlmProvider::EXTERNAL,
            self::EXTERNAL_URL_FROM_ENV,
            self::MODEL_FROM_ENV,
            self::EXTERNAL_API_KEY_FROM_ENV,
            self::TIMEOUT_FROM_ENV_SEC,
        ));

        self::assertSame(LlmProvider::LOCAL, $moderation->provider);
        self::assertSame(Hilos::$env[EnvConstants::LLM_LOCAL_URL]->string(), $moderation->url);
    }
}
