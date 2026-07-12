<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\LLMConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\LLM\External\Chat\AsyncOpenAIChatProvider;
use Hilos\LLM\Local\Chat\AsyncOllamaChatProvider;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProfileOverrideSource;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\LLM\Routing\LlmRouter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the LLM profile router (HIL-257).
 */
final class LlmRouterTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        foreach (['LLM_CHAT_PROVIDER', 'LLM_EXTERNAL_API_KEY'] as $key) {
            putenv($key);
        }
    }

    public function testResolvesLocalDefaultProfile(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_LOCAL);

        $profile = (new LlmRouter())->resolve('default');

        self::assertSame('default', $profile->key);
        self::assertSame(LlmProvider::LOCAL, $profile->provider);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_URL, $profile->url);
        self::assertSame(LLMConstants::DEFAULT_LOCAL_CHAT_MODEL, $profile->model);
        self::assertNull($profile->apiKey);
        self::assertSame(LLMConstants::DEFAULT_TIMEOUT_SEC, $profile->timeoutSec);
        self::assertNull($profile->placement);
    }

    public function testResolvesExternalProfileWithApiKey(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=sk-test');

        $profile = (new LlmRouter())->resolve('default');

        self::assertSame(LlmProvider::EXTERNAL, $profile->provider);
        self::assertSame('sk-test', $profile->apiKey);
        self::assertSame(LLMConstants::DEFAULT_EXTERNAL_URL, $profile->url);
        self::assertSame(LLMConstants::DEFAULT_EXTERNAL_CHAT_MODEL, $profile->model);
    }

    public function testExternalWithoutApiKeyIsConfigurationError(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=');

        $this->expectException(LLMConfigurationException::class);

        (new LlmRouter())->resolve('default');
    }

    public function testUnknownProfileIsConfigurationError(): void
    {
        $this->expectException(LLMConfigurationException::class);

        (new LlmRouter())->resolve('does-not-exist');
    }

    public function testUnknownProviderIsConfigurationError(): void
    {
        putenv('LLM_CHAT_PROVIDER=frontier');

        $this->expectException(LLMConfigurationException::class);

        (new LlmRouter())->resolve('default');
    }

    public function testChatClientForLocalBuildsOllamaProvider(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_LOCAL);

        self::assertInstanceOf(AsyncOllamaChatProvider::class, (new LlmRouter())->chatClientFor('default'));
    }

    public function testChatClientForExternalBuildsOpenAiProvider(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_EXTERNAL);
        putenv('LLM_EXTERNAL_API_KEY=sk-test');

        self::assertInstanceOf(AsyncOpenAIChatProvider::class, (new LlmRouter())->chatClientFor('default'));
    }

    public function testOverrideSourceReplacesResolvedProfile(): void
    {
        putenv('LLM_CHAT_PROVIDER=' . LLMConstants::PROVIDER_LOCAL);

        $override = new class implements LlmProfileOverrideSource {
            public function override(LlmProfile $profile): LlmProfile
            {
                return new LlmProfile(
                    $profile->key,
                    $profile->provider,
                    $profile->url,
                    'overridden-model',
                    $profile->apiKey,
                    $profile->timeoutSec,
                    $profile->placement,
                );
            }
        };

        $profile = (new LlmRouter(overrides: $override))->resolve('default');

        self::assertSame('overridden-model', $profile->model);
    }
}
