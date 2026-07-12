<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Environment\ChatEnvCatalog;
use Demo\Chat\Environment\ChatLlmProfileCatalog;
use Hilos\Constants\LLMConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\LLM\Routing\LlmRouter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the chat LLM profile catalog resolution (HIL-258).
 */
final class ChatLlmProfileCatalogTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor(ChatEnvCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        foreach ([
            'CHAT_BOT_PROVIDER', 'CHAT_BOT_URL', 'CHAT_MODERATION_PROVIDER',
            'CHAT_CONTEXT_ANALYZER_PROVIDER', 'LLM_EXTERNAL_API_KEY',
        ] as $key) {
            putenv($key);
        }
    }

    private function router(): LlmRouter
    {
        return new LlmRouter(ChatLlmProfileCatalog::class);
    }

    public function testResolvesEachRoleModelFromChatEnv(): void
    {
        $bot = $this->router()->resolve('chat.bot');
        self::assertSame(LlmProvider::LOCAL, $bot->provider);
        self::assertSame(ChatLLMConstants::MODEL_BOT, $bot->model);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $bot->url);
        self::assertNull($bot->apiKey);

        self::assertSame(ChatLLMConstants::MODEL_MODERATION, $this->router()->resolve('chat.moderation')->model);
        self::assertSame(ChatLLMConstants::MODEL_CONTEXT_ANALYZER, $this->router()->resolve('chat.analyzer')->model);
    }

    public function testLocalUrlFallsBackToGlobalLocalUrl(): void
    {
        // The per-role URL is empty by default, so it falls back to LLM_LOCAL_URL.
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $this->router()->resolve('chat.bot')->url);
    }

    public function testPerRoleLocalUrlOverridesFallback(): void
    {
        putenv('CHAT_BOT_URL=http://ollama.internal:11434');

        self::assertSame('http://ollama.internal:11434', $this->router()->resolve('chat.bot')->url);
    }

    public function testExternalRoleUsesPerRoleModelAndGlobalExternalConfig(): void
    {
        putenv('CHAT_BOT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=sk-test');

        $bot = $this->router()->resolve('chat.bot');

        self::assertSame(LlmProvider::EXTERNAL, $bot->provider);
        self::assertSame('sk-test', $bot->apiKey);
        self::assertSame(LLMConstants::DEFAULT_EXTERNAL_URL, $bot->url);
        self::assertSame(ChatLLMConstants::MODEL_BOT, $bot->model);
    }

    public function testFrameworkDefaultProfileStillResolves(): void
    {
        self::assertSame('default', $this->router()->resolve('default')->key);
    }
}
