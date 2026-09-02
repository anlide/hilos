<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Preset;

/**
 * One named set of setting values — a "mode" as the administrator's screen calls it (HIL-762).
 *
 * The name is machine-readable and is what travels the wire; the title a card shows above it is
 * written by the frontend, so no text meant for a human lives here. That split is what lets the
 * same preset serve a screen in any language without the mechanism knowing one.
 */
final readonly class SettingPreset
{
    /**
     * Creates a named preset.
     *
     * @param string $name Machine name of the preset, as stored under the group's selection key
     * @param array<string, mixed> $values Value this preset declares, keyed by setting key
     */
    public function __construct(
        public string $name,
        public array $values,
    ) {
    }
}
