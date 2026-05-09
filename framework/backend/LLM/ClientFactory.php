<?php

declare(strict_types=1);

namespace Hilos\LLM;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\Contract\ImageLLMInterface;
use Hilos\LLM\External\Chat\AsyncOpenAIChatProvider;
use Hilos\LLM\External\Image\OpenAIImageProvider;
use Hilos\LLM\Local\Chat\AsyncOllamaChatProvider;
use Hilos\LLM\Local\Image\PlaceholderLocalImageProvider;
use Hilos\Hilos;

/**
 * ClientFactory - Creates LLM clients based on configuration.
 *
 * Chat clients are async (AsyncChatLLMInterface). Image clients remain sync.
 * Provider selection per capability via LLM_CHAT_PROVIDER / LLM_IMAGE_PROVIDER.
 *
 * Env: LLM_CHAT_PROVIDER, LLM_IMAGE_PROVIDER, LLM_LOCAL_*, LLM_EXTERNAL_*
 */
class ClientFactory
{
    /**
     * Create async chat LLM client (local or external by env config).
     *
     * @return AsyncChatLLMInterface AsyncOllamaChatProvider (local) or AsyncOpenAIChatProvider (external)
     */
    public static function createChatClient(): AsyncChatLLMInterface
    {
        $provider = Hilos::$env[EnvConstants::LLM_CHAT_PROVIDER];

        return $provider === LLMConstants::PROVIDER_EXTERNAL
            ? self::createExternalChatProvider()
            : self::createLocalChatProvider();
    }

    /**
     * Create image LLM client (local or external by env config).
     *
     * @return ImageLLMInterface PlaceholderLocalImageProvider (local) or OpenAIImageProvider (external)
     */
    public static function createImageClient(): ImageLLMInterface
    {
        $provider = Hilos::$env[EnvConstants::LLM_IMAGE_PROVIDER];

        return $provider === LLMConstants::PROVIDER_EXTERNAL
            ? self::createExternalImageProvider()
            : self::createLocalImageProvider();
    }

    /**
     * Create chat client with explicit config (e.g. for moderation overrides).
     *
     * When url/model/apiKey are provided, they override env. Non-null apiKey
     * selects external provider; otherwise uses local provider.
     *
     * @param ?string $url Base URL (overrides env when provided)
     * @param ?string $model Model name (overrides env when provided)
     * @param ?string $apiKey API key (non-empty selects external provider)
     * @return AsyncChatLLMInterface Configured async chat client
     */
    public static function createChatClientWithConfig(
        ?string $url = null,
        ?string $model = null,
        ?string $apiKey = null,
    ): AsyncChatLLMInterface {
        if ($apiKey !== null && $apiKey !== '') {
            $url = $url ?? Hilos::$env[EnvConstants::LLM_EXTERNAL_URL];
            $model = $model ?? Hilos::$env[EnvConstants::LLM_EXTERNAL_CHAT_MODEL];

            return new AsyncOpenAIChatProvider($url, $apiKey, $model);
        }

        $url = $url ?? Hilos::$env[EnvConstants::LLM_LOCAL_URL];
        $model = $model ?? Hilos::$env[EnvConstants::LLM_LOCAL_CHAT_MODEL];

        return new AsyncOllamaChatProvider($url, $model);
    }

    /**
     * Creates local (Ollama) async chat provider from env config.
     *
     * @return AsyncChatLLMInterface Local chat client
     */
    private static function createLocalChatProvider(): AsyncChatLLMInterface
    {
        $url = Hilos::$env[EnvConstants::LLM_LOCAL_URL];
        $model = Hilos::$env[EnvConstants::LLM_LOCAL_CHAT_MODEL];

        return new AsyncOllamaChatProvider($url, $model);
    }

    /**
     * Creates local image provider (placeholder) from env config.
     *
     * @return ImageLLMInterface Local image client
     */
    private static function createLocalImageProvider(): ImageLLMInterface
    {
        $url = Hilos::$env[EnvConstants::LLM_LOCAL_URL];
        $model = Hilos::$env[EnvConstants::LLM_LOCAL_IMAGE_MODEL];

        return new PlaceholderLocalImageProvider($url, $model !== '' ? $model : null);
    }

    /**
     * Creates external (OpenAI) async chat provider from env config.
     *
     * @return AsyncChatLLMInterface External chat client
     */
    private static function createExternalChatProvider(): AsyncChatLLMInterface
    {
        $url = Hilos::$env[EnvConstants::LLM_EXTERNAL_URL];
        $apiKey = Hilos::$env[EnvConstants::LLM_EXTERNAL_API_KEY];
        $model = Hilos::$env[EnvConstants::LLM_EXTERNAL_CHAT_MODEL];

        return new AsyncOpenAIChatProvider($url, $apiKey, $model);
    }

    /**
     * Creates external (OpenAI) image provider from env config.
     *
     * @return ImageLLMInterface External image client
     */
    private static function createExternalImageProvider(): ImageLLMInterface
    {
        $url = Hilos::$env[EnvConstants::LLM_EXTERNAL_URL];
        $apiKey = Hilos::$env[EnvConstants::LLM_EXTERNAL_API_KEY];
        $model = Hilos::$env[EnvConstants::LLM_EXTERNAL_IMAGE_MODEL];

        return new OpenAIImageProvider($url, $apiKey, $model);
    }
}
