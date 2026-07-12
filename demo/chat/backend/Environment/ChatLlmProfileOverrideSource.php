<?php

declare(strict_types=1);

namespace Demo\Chat\Environment;

use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\LLM\Routing\LlmProfileOverrideSource;
use Hilos\LLM\Routing\LlmProvider;

/**
 * Applies admin-editable settings on top of the env-resolved chat LLM profiles.
 *
 * The runtime settings-override the router's ADR leaves as a seam: the bot and
 * moderation profiles become authoritative from the CHAT_*_PROVIDER/MODEL/URL/
 * TIMEOUT settings (admin-editable via the settings page), while the analyzer
 * (env-only, no settings) and the framework default profile pass through.
 * Provider is taken from the setting, so an admin can switch a role local <->
 * external; the external URL/API key still come from env (never a settings row).
 */
final class ChatLlmProfileOverrideSource implements LlmProfileOverrideSource
{
    public function override(LlmProfile $profile): LlmProfile
    {
        return match ($profile->key) {
            ChatLLMConstants::PROFILE_BOT => $this->fromSettings(
                $profile,
                ChatSettingsConstants::CHAT_BOT_PROVIDER,
                ChatSettingsConstants::CHAT_BOT_URL,
                ChatSettingsConstants::CHAT_BOT_MODEL,
                ChatSettingsConstants::CHAT_BOT_TIMEOUT_SEC,
            ),
            ChatLLMConstants::PROFILE_MODERATION => $this->fromSettings(
                $profile,
                ChatSettingsConstants::CHAT_MODERATION_PROVIDER,
                ChatSettingsConstants::CHAT_MODERATION_URL,
                ChatSettingsConstants::CHAT_MODERATION_MODEL,
                ChatSettingsConstants::CHAT_MODERATION_TIMEOUT_SEC,
            ),
            default => $profile,
        };
    }

    /**
     * Rebuilds a profile from its role settings, provider-aware.
     *
     * @param LlmProfile $profile Env-resolved profile (source of placement and the timeout fallback)
     * @param string $providerKey Provider setting key
     * @param string $urlKey Local URL setting key
     * @param string $modelKey Model setting key
     * @param string $timeoutKey Timeout setting key
     * @return LlmProfile Effective profile with settings applied
     * @throws LLMConfigurationException When the setting selects external without an API key
     */
    private function fromSettings(
        LlmProfile $profile,
        string $providerKey,
        string $urlKey,
        string $modelKey,
        string $timeoutKey,
    ): LlmProfile {
        $provider = LlmProvider::fromValue(Hilos::$setting[$providerKey]->string());
        $model = Hilos::$setting[$modelKey]->string();
        $timeout = Hilos::$setting[$timeoutKey]->float();
        $timeout = $timeout > 0.0 ? $timeout : $profile->timeoutSec;

        if ($provider === LlmProvider::EXTERNAL) {
            $url = Hilos::$env[EnvConstants::LLM_EXTERNAL_URL];
            $apiKey = Hilos::$env[EnvConstants::LLM_EXTERNAL_API_KEY];
            if ($apiKey === '') {
                throw new LLMConfigurationException(
                    "LLM profile '{$profile->key}' selects the external provider via settings but has no API key",
                );
            }
        } else {
            $url = Hilos::$setting[$urlKey]->string() ?: Hilos::$env[EnvConstants::LLM_LOCAL_URL];
            $apiKey = null;
        }

        return new LlmProfile($profile->key, $provider, $url, $model, $apiKey, $timeout, $profile->placement);
    }
}
