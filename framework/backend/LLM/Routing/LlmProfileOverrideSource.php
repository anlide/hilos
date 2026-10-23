<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\LLM\Exception\LLMConfigurationException;

/**
 * Post-resolution override seam for LLM profiles.
 *
 * The router resolves a profile from the env-backed catalog, then hands it to the
 * project's override source (if one is set) and after it to the framework's own,
 * {@see FrameworkLlmProfileOverrideSource}; either may return a modified profile.
 * This is the runtime settings-override hook the admin LLM config (HIL-262) plugs
 * into: a project's source owns its own keys, the framework's owns the built-in
 * ones and runs last.
 */
interface LlmProfileOverrideSource
{
    /**
     * Returns the effective profile, applying any runtime overrides.
     *
     * @param LlmProfile $profile Profile resolved from the env-backed catalog
     * @return LlmProfile Effective profile (unchanged when there is no override)
     * @throws EnvException When a required environment value is unavailable
     * @throws LLMConfigurationException When provider configuration is incomplete
     * @throws SettingException When a setting cannot be read
     */
    public function override(LlmProfile $profile): LlmProfile;
}
