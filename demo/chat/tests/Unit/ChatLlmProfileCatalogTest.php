<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Environment\ChatEnvCatalog;
use Demo\Chat\Environment\ChatLlmProfileCatalog;
use Demo\Chat\Environment\ChatLlmProfileOverrideSource;
use Hilos\Constants\LLMConstants;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\LLM\Routing\LlmProfileCatalogConstants;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\LLM\Routing\LlmRouter;
use Hilos\Moderation\ModerationLlmConstants;
use Hilos\Moderation\ModerationSettingsCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the chat LLM profile catalog resolution (HIL-258), with the framework moderator profiles beside it (HIL-1625).
 */
final class ChatLlmProfileCatalogTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?SettingsAccessor $previousSetting = null;
    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousSetting = Hilos::$setting;
        $this->previousDb = Hilos::$db;
        Hilos::$env = new EnvAccessor(ChatEnvCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$setting = $this->previousSetting;
        Hilos::$db = $this->previousDb;
        foreach ([
            'CHAT_BOT_PROVIDER', 'CHAT_BOT_URL', 'CHAT_CONTEXT_ANALYZER_PROVIDER',
            'LLM_EXTERNAL_API_KEY',
        ] as $key) {
            putenv($key);
        }
    }

    private function router(): LlmRouter
    {
        return new LlmRouter(ChatLlmProfileCatalog::class);
    }

    public function testResolvesBotAndAnalyzerModelsFromChatEnv(): void
    {
        $bot = $this->router()->resolve(ChatLLMConstants::PROFILE_BOT);
        self::assertSame(LlmProvider::LOCAL, $bot->provider);
        self::assertSame(ChatLLMConstants::MODEL_BOT, $bot->model);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $bot->url);
        self::assertNull($bot->apiKey);

        self::assertSame(ChatLLMConstants::MODEL_CONTEXT_ANALYZER, $this->router()->resolve(ChatLLMConstants::PROFILE_ANALYZER)->model);
    }

    public function testLocalUrlFallsBackToGlobalLocalUrl(): void
    {
        // The per-role URL is empty by default, so it falls back to LLM_LOCAL_URL.
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $this->router()->resolve(ChatLLMConstants::PROFILE_BOT)->url);
    }

    public function testPerRoleLocalUrlOverridesFallback(): void
    {
        putenv('CHAT_BOT_URL=http://ollama.internal:11434');

        self::assertSame('http://ollama.internal:11434', $this->router()->resolve(ChatLLMConstants::PROFILE_BOT)->url);
    }

    public function testExternalRoleUsesPerRoleModelAndGlobalExternalConfig(): void
    {
        putenv('CHAT_BOT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=sk-test');

        $bot = $this->router()->resolve(ChatLLMConstants::PROFILE_BOT);

        self::assertSame(LlmProvider::EXTERNAL, $bot->provider);
        self::assertSame('sk-test', $bot->apiKey);
        self::assertSame(LLMConstants::DEFAULT_EXTERNAL_URL, $bot->url);
        self::assertSame(ChatLLMConstants::MODEL_BOT, $bot->model);
    }

    public function testFrameworkDefaultProfileStillResolves(): void
    {
        self::assertSame('default', $this->router()->resolve(LlmProfileCatalogConstants::DEFAULT_PROFILE)->key);
    }

    public function testTheFrameworkModerationProfilesAreDeclaredBesideTheChatRoles(): void
    {
        $router = $this->router();
        $text = $router->resolveBase(ModerationLlmConstants::PROFILE_TEXT);
        $image = $router->resolveBase(ModerationLlmConstants::PROFILE_IMAGE);

        self::assertArrayHasKey(ModerationLlmConstants::PROFILE_TEXT, $router->catalog());
        self::assertArrayHasKey(ModerationLlmConstants::PROFILE_IMAGE, $router->catalog());
        self::assertSame(LlmProvider::LOCAL, $text->provider);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $text->url);
        self::assertSame(ModerationLlmConstants::DEFAULT_TEXT_MODEL, $text->model);
        self::assertSame(LlmProvider::LOCAL, $image->provider);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $image->url);
        self::assertSame(ModerationLlmConstants::DEFAULT_IMAGE_MODEL, $image->model);
    }

    public function testTheChatOverrideKeepsItsRolesWhileTheFrameworkSettingsHaveTheBuiltInOnes(): void
    {
        // No database: each setting reads the value saved below, or else its catalog default.
        Hilos::$db = null;
        $settings = new class (SettingsCatalog::class) extends SettingsAccessor {
            /** @var array<string, mixed> Saved setting values keyed by setting key */
            public array $saved = [];

            public function effectiveValueFor(string $key): mixed
            {
                return array_key_exists($key, $this->saved) ? $this->saved[$key] : parent::effectiveValueFor($key);
            }
        };
        $settings->saved = [
            ChatSettingsConstants::CHAT_BOT_PROVIDER => LLMConstants::PROVIDER_LOCAL,
            ChatSettingsConstants::CHAT_BOT_MODEL => 'bot-saved',
            ModerationSettingsCatalog::TEXT_MODEL => 'text-saved',
            ModerationSettingsCatalog::IMAGE_MODEL => 'image-saved',
        ];
        Hilos::$setting = $settings;
        $router = new LlmRouter(ChatLlmProfileCatalog::class, new ChatLlmProfileOverrideSource());

        self::assertSame('bot-saved', $router->resolve(ChatLLMConstants::PROFILE_BOT)->model);
        self::assertSame('text-saved', $router->resolve(ModerationLlmConstants::PROFILE_TEXT)->model);
        self::assertSame('image-saved', $router->resolve(ModerationLlmConstants::PROFILE_IMAGE)->model);
    }
}
