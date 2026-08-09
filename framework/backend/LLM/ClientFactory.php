<?php

declare(strict_types=1);

namespace Hilos\LLM;

use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\External\Chat\AsyncOpenAIChatProvider;
use Hilos\LLM\Local\Chat\AsyncOllamaChatProvider;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProvider;
use Hilos\LLM\Routing\LlmRouter;

/**
 * Builds an async chat client from a resolved LLM profile.
 *
 * The low-level provider constructor beneath the routing policy: the
 * {@see LlmRouter} resolves a profile and calls this to get a
 * ready client. Provider comes from the profile's explicit provider field.
 */
class ClientFactory
{
    /**
     * Create an async chat client from a resolved LLM profile.
     *
     * @param LlmProfile $profile Resolved LLM profile
     * @return AsyncChatLLMInterface AsyncOpenAIChatProvider (external) or AsyncOllamaChatProvider (local)
     * @throws LLMConfigurationException When an external profile carries no API key
     */
    public static function createChatClientForProfile(LlmProfile $profile): AsyncChatLLMInterface
    {
        if ($profile->provider !== LlmProvider::EXTERNAL) {
            return new AsyncOllamaChatProvider($profile->url, $profile->model);
        }

        $apiKey = $profile->apiKey;
        if ($apiKey === null || $apiKey === '') {
            // The same invariant LlmRouter::resolveBase() holds; a profile assembled anywhere else
            // must not slip past it and reach the provider as an `Authorization: Bearer ` header.
            throw new LLMConfigurationException(
                "LLM profile '{$profile->key}' selects the external provider but has no API key",
            );
        }

        return new AsyncOpenAIChatProvider($profile->url, $apiKey, $profile->model);
    }
}
