<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\LLM\Exception\LLMConfigurationException;
use Hilos\Moderation\ModerationLlmConstants;
use Hilos\Moderation\ModerationSettingsCatalog;

/**
 * Applies the framework settings on top of the env-resolved built-in LLM profiles (HIL-1625).
 *
 * The router runs this source on every resolve, after the project's own override source if the
 * project set one, so for the built-in keys the framework settings have the last word. The two
 * profiles of the common moderator, `hilos.moderation.text` and `hilos.moderation.image`, take
 * their provider, model, local address and timeout from the `moderation.text.*` and
 * `moderation.image.*` keys of {@see ModerationSettingsCatalog}; every other key, `default`
 * included, passes through unchanged.
 *
 * Each resolve reads the settings afresh through `Hilos::$setting`, with no copy kept here, so
 * the next resolve after a saved change already sees it. A secret never comes from a setting: an
 * external provider takes the global external endpoint and API key from env and refuses to
 * resolve without the key. An empty URL setting is no override: the role's own local address
 * from env stands, and LLM_LOCAL_URL behind it, also for a role the settings switched from
 * external to local.
 */
final class FrameworkLlmProfileOverrideSource implements LlmProfileOverrideSource
{
    /**
     * @param LlmProfile $profile Profile resolved from the catalog, after any project override
     * @return LlmProfile Effective profile with the framework settings applied to a built-in key
     * @throws EnvException When an env variable the profile falls back to is missing from the catalog or of the wrong type
     * @throws LLMConfigurationException When a setting names an unknown provider, or selects external without an API key
     * @throws SettingException When the settings accessor is not initialized or a profile setting cannot be read
     */
    public function override(LlmProfile $profile): LlmProfile
    {
        return match ($profile->key) {
            ModerationLlmConstants::PROFILE_TEXT => $this->fromSettings(
                $profile,
                ModerationSettingsCatalog::TEXT_PROVIDER,
                ModerationSettingsCatalog::TEXT_URL,
                ModerationSettingsCatalog::TEXT_MODEL,
                ModerationSettingsCatalog::TEXT_TIMEOUT_SEC,
                EnvConstants::HILOS_MODERATION_TEXT_URL,
            ),
            ModerationLlmConstants::PROFILE_IMAGE => $this->fromSettings(
                $profile,
                ModerationSettingsCatalog::IMAGE_PROVIDER,
                ModerationSettingsCatalog::IMAGE_URL,
                ModerationSettingsCatalog::IMAGE_MODEL,
                ModerationSettingsCatalog::IMAGE_TIMEOUT_SEC,
                EnvConstants::HILOS_MODERATION_IMAGE_URL,
            ),
            default => $profile,
        };
    }

    /**
     * Rebuilds a built-in profile from its role settings, provider-aware.
     *
     * @param LlmProfile $profile Profile the settings replace (source of the key and placement)
     * @param string $providerKey Provider setting key
     * @param string $urlKey Local URL setting key
     * @param string $modelKey Model setting key
     * @param string $timeoutKey Timeout setting key
     * @param EnvConstants $roleUrlEnv Role local URL env key an empty URL setting falls back to
     * @return LlmProfile Effective profile with the settings applied
     * @throws EnvException When an env variable the fallbacks name is missing from the catalog or of the wrong type
     * @throws LLMConfigurationException When the provider setting is unknown, or selects external without an API key
     * @throws SettingException When the settings accessor is not initialized or a profile setting cannot be read
     */
    private function fromSettings(
        LlmProfile $profile,
        string $providerKey,
        string $urlKey,
        string $modelKey,
        string $timeoutKey,
        EnvConstants $roleUrlEnv,
    ): LlmProfile {
        $settings = Hilos::$setting
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');

        $provider = LlmProvider::fromValue($settings[$providerKey]->string());

        if ($provider === LlmProvider::EXTERNAL) {
            $url = Hilos::$env[EnvConstants::LLM_EXTERNAL_URL]->string();
            $apiKey = Hilos::$env[EnvConstants::LLM_EXTERNAL_API_KEY]->string();
            if ($apiKey === '') {
                throw new LLMConfigurationException(
                    "LLM profile '{$profile->key}' selects the external provider via settings but has no API key",
                );
            }
        } else {
            $url = $settings[$urlKey]->string();
            if ($url === '') {
                $url = Hilos::$env->normalizedLlmUrl($roleUrlEnv, EnvConstants::LLM_LOCAL_URL);
            }
            $apiKey = null;
        }

        return new LlmProfile(
            $profile->key,
            $provider,
            $url,
            $settings[$modelKey]->string(),
            $apiKey,
            $settings[$timeoutKey]->float(),
            $profile->placement,
        );
    }
}
