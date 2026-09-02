<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Preset;

/**
 * The presets one admin section offers, plus the setting key remembering which one was applied
 * (HIL-762).
 *
 * Selection and values are two separate facts and are stored as two: the chosen name under
 * {@see $selectionSettingKey}, the values under the keys the presets name. Deriving the choice
 * from the values instead — "which set matches?" — would lose it the moment an administrator
 * edited one value by hand, and the button offering to put the set back would have nowhere to
 * put it back to.
 */
final readonly class SettingPresetGroup
{
    /**
     * Creates a preset group.
     *
     * @param string $key Machine key of the group, as it travels the wire
     * @param string $selectionSettingKey Setting key the applied preset name is stored under
     * @param list<SettingPreset> $presets Presets in declaration order, which is the order of the cards
     */
    public function __construct(
        public string $key,
        public string $selectionSettingKey,
        public array $presets,
    ) {
    }

    /**
     * Returns the preset a name belongs to.
     *
     * A name outside the group is an answer and not a failure here: the stored selection is
     * ordinary setting text, and a recipe that dropped a preset since it was written has to be
     * readable rather than fatal.
     *
     * @param string $name Machine name of a preset
     * @return ?SettingPreset Preset carrying that name, or null when the group declares none
     */
    public function presetByName(string $name): ?SettingPreset
    {
        foreach ($this->presets as $preset) {
            if ($preset->name === $name) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Returns every setting key any preset of the group declares, in declaration order.
     *
     * @return list<string> Union of the member keys of all presets
     */
    public function memberKeys(): array
    {
        $keys = [];
        foreach ($this->presets as $preset) {
            foreach (array_keys($preset->values) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}
