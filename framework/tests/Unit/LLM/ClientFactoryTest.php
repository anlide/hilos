<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\LLM;

use Hilos\LLM\ClientFactory;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Local\Chat\AsyncOllamaChatProvider;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\LLM\Routing\LlmRouter;
use PHPUnit\Framework\TestCase;

/**
 * Tests that an external profile without an API key is refused rather than signed with nothing.
 *
 * {@see LlmRouter::resolveBase()} already holds this invariant for profiles it
 * assembles itself; the factory holds it for every other route into the provider, so a profile
 * built by a project override cannot reach the API as an `Authorization: Bearer ` header
 * (HIL-544).
 */
final class ClientFactoryTest extends TestCase
{
    public function testAnExternalProfileWithNoApiKeyIsRefused(): void
    {
        $this->expectException(LLMConfigurationException::class);
        ClientFactory::createChatClientForProfile($this->profile(LlmProvider::EXTERNAL, null));
    }

    public function testAnExternalProfileWithAnEmptyApiKeyIsRefused(): void
    {
        $this->expectException(LLMConfigurationException::class);
        ClientFactory::createChatClientForProfile($this->profile(LlmProvider::EXTERNAL, ''));
    }

    public function testALocalProfileNeedsNoApiKey(): void
    {
        $client = ClientFactory::createChatClientForProfile($this->profile(LlmProvider::LOCAL, null));

        self::assertInstanceOf(AsyncOllamaChatProvider::class, $client);
    }

    /**
     * @param LlmProvider $provider Provider the profile selects
     * @param ?string $apiKey API key the profile carries
     * @return LlmProfile Profile under test
     */
    private function profile(LlmProvider $provider, ?string $apiKey): LlmProfile
    {
        return new LlmProfile('default', $provider, 'http://llm.example.com', 'qwen2.5:3b', $apiKey, 5.0);
    }
}
