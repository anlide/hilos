<?php

declare(strict_types=1);

namespace Demo\Chat\Utils;

use Demo\Chat\Constants\ChatLLMConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Utils\Env;

/**
 * ContextAnalyzerEnv - Reads context analyzer LLM config from env via Env
 */
class ContextAnalyzerEnv
{
    public static function getModel(): string
    {
        return Env::getFilled(EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL, ChatLLMConstants::MODEL_CONTEXT_ANALYZER);
    }

    public static function getUrl(): string
    {
        $url = Env::getFilled(EnvConstants::CHAT_CONTEXT_ANALYZER_URL, LLMConstants::DEFAULT_LOCAL_URL);
        $url = rtrim($url, '/');
        if (str_ends_with($url, '/api/generate')) {
            $url = substr($url, 0, -strlen('/api/generate'));
        }

        return $url;
    }

    public static function getTimeoutSec(): float
    {
        $timeout = Env::getFloat(EnvConstants::CHAT_CONTEXT_ANALYZER_TIMEOUT_SEC, LLMConstants::DEFAULT_TIMEOUT_SEC);

        return $timeout > 0 ? $timeout : LLMConstants::DEFAULT_TIMEOUT_SEC;
    }

    public static function useExternalProvider(): bool
    {
        $value = Env::getFilled(EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER, 'local');

        return strtolower($value) === 'external';
    }
}
