<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

/**
 * Post-resolution override seam for LLM profiles.
 *
 * The router resolves a profile from the env-backed catalog, then hands it to an
 * override source (if one is set) that may return a modified profile. This is
 * the runtime settings-override hook the admin LLM config (HIL-262) plugs into;
 * the framework wires no source by default, so env is the only source today.
 */
interface LlmProfileOverrideSource
{
    /**
     * Returns the effective profile, applying any runtime overrides.
     *
     * @param LlmProfile $profile Profile resolved from the env-backed catalog
     * @return LlmProfile Effective profile (unchanged when there is no override)
     */
    public function override(LlmProfile $profile): LlmProfile;
}
