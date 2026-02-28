<?php

declare(strict_types=1);

namespace Hilos\LLM;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\LLM\Contract\ChatLLMInterface;
use Hilos\LLM\Contract\ImageLLMInterface;
use Hilos\LLM\External\Chat\OpenAIChatProvider;
use Hilos\LLM\External\Image\OpenAIImageProvider;
use Hilos\LLM\Local\Chat\OllamaChatProvider;
use Hilos\LLM\Local\Image\PlaceholderLocalImageProvider;
use Hilos\Utils\Env;

/**
 * ClientFactory - Creates LLM clients based on configuration
 *
 * Supports two local models (chat, image) and two external models (chat, image).
 * Provider selection per capability via LLM_CHAT_PROVIDER / LLM_IMAGE_PROVIDER.
 *
 * Env: LLM_CHAT_PROVIDER, LLM_IMAGE_PROVIDER, LLM_LOCAL_*, LLM_EXTERNAL_*
 */
class ClientFactory
{
    /**
     * Create chat LLM client (local or external by env config).
     *
     * @return ChatLLMInterface OllamaChatProvider (local) or OpenAIChatProvider (external)
     */
    public static function createChatClient(): ChatLLMInterface
    {
        $provider = Env::getFilled(EnvConstants::LLM_CHAT_PROVIDER, LLMConstants::PROVIDER_LOCAL);

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
        $provider = Env::getFilled(EnvConstants::LLM_IMAGE_PROVIDER, LLMConstants::PROVIDER_EXTERNAL);

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
     * @return ChatLLMInterface Configured chat client
     */
    public static function createChatClientWithConfig(
        ?string $url = null,
        ?string $model = null,
        ?string $apiKey = null,
    ): ChatLLMInterface {
        if ($apiKey !== null && $apiKey !== '') {
            $url = $url ?? Env::getFilled(EnvConstants::LLM_EXTERNAL_URL, LLMConstants::DEFAULT_EXTERNAL_URL);
            $model = $model ?? Env::getFilled(EnvConstants::LLM_EXTERNAL_CHAT_MODEL, LLMConstants::DEFAULT_EXTERNAL_CHAT_MODEL);

            return new OpenAIChatProvider($url, $apiKey, $model);
        }

        $url = $url ?? Env::getFilled(EnvConstants::LLM_LOCAL_URL, LLMConstants::DEFAULT_LOCAL_URL);
        $model = $model ?? Env::getFilled(EnvConstants::LLM_LOCAL_CHAT_MODEL, LLMConstants::DEFAULT_LOCAL_CHAT_MODEL);

        return new OllamaChatProvider($url, $model);
    }

    /**
     * @return ChatLLMInterface
     */
    private static function createLocalChatProvider(): ChatLLMInterface
    {
        $url = Env::getFilled(EnvConstants::LLM_LOCAL_URL, LLMConstants::DEFAULT_LOCAL_URL);
        $model = Env::getFilled(EnvConstants::LLM_LOCAL_CHAT_MODEL, LLMConstants::DEFAULT_LOCAL_CHAT_MODEL);

        return new OllamaChatProvider($url, $model);
    }

    /**
     * @return ImageLLMInterface
     */
    private static function createLocalImageProvider(): ImageLLMInterface
    {
        $url = Env::getFilled(EnvConstants::LLM_LOCAL_URL, LLMConstants::DEFAULT_LOCAL_URL);
        $model = Env::getFilled(EnvConstants::LLM_LOCAL_IMAGE_MODEL, LLMConstants::DEFAULT_LOCAL_IMAGE_MODEL);

        return new PlaceholderLocalImageProvider($url, $model !== '' ? $model : null);
    }

    /**
     * @return ChatLLMInterface
     */
    private static function createExternalChatProvider(): ChatLLMInterface
    {
        $url = Env::getFilled(EnvConstants::LLM_EXTERNAL_URL, LLMConstants::DEFAULT_EXTERNAL_URL);
        $apiKey = Env::getFilled(EnvConstants::LLM_EXTERNAL_API_KEY, '');
        $model = Env::getFilled(EnvConstants::LLM_EXTERNAL_CHAT_MODEL, LLMConstants::DEFAULT_EXTERNAL_CHAT_MODEL);

        return new OpenAIChatProvider($url, $apiKey, $model);
    }

    /**
     * @return ImageLLMInterface
     */
    private static function createExternalImageProvider(): ImageLLMInterface
    {
        $url = Env::getFilled(EnvConstants::LLM_EXTERNAL_URL, LLMConstants::DEFAULT_EXTERNAL_URL);
        $apiKey = Env::getFilled(EnvConstants::LLM_EXTERNAL_API_KEY, '');
        $model = Env::getFilled(EnvConstants::LLM_EXTERNAL_IMAGE_MODEL, LLMConstants::DEFAULT_EXTERNAL_IMAGE_MODEL);

        return new OpenAIImageProvider($url, $apiKey, $model);
    }
}
