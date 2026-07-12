<?php

declare(strict_types=1);

namespace Hilos\LLM;

use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\External\Chat\AsyncOpenAIChatProvider;
use Hilos\LLM\Local\Chat\AsyncOllamaChatProvider;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProvider;

/**
 * Builds an async chat client from a resolved LLM profile.
 *
 * The low-level provider constructor beneath the routing policy: the
 * {@see \Hilos\LLM\Routing\LlmRouter} resolves a profile and calls this to get a
 * ready client. Provider comes from the profile's explicit provider field.
 */
class ClientFactory
{
    /**
     * Create an async chat client from a resolved LLM profile.
     *
     * @param LlmProfile $profile Resolved LLM profile
     * @return AsyncChatLLMInterface AsyncOpenAIChatProvider (external) or AsyncOllamaChatProvider (local)
     */
    public static function createChatClientForProfile(LlmProfile $profile): AsyncChatLLMInterface
    {
        return $profile->provider === LlmProvider::EXTERNAL
            ? new AsyncOpenAIChatProvider($profile->url, $profile->apiKey ?? '', $profile->model)
            : new AsyncOllamaChatProvider($profile->url, $profile->model);
    }
}
