<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Preset;

/**
 * One setting on which the current state and the applied preset disagree (HIL-762).
 *
 * A fact and not a phrase: "keep for 14 days instead of 30" is built by the frontend out of these
 * three fields. Units, plurals and language are not part of the protocol — folded into the payload
 * they would make the mechanism useless to the next section, whose settings are counted in
 * something else.
 */
final readonly class SettingPresetDifference
{
    /**
     * Creates a difference between the preset and what the setting reads today.
     *
     * @param string $key Setting key the two values disagree on
     * @param mixed $presetValue Value the preset declares, normalized to the catalog type of the key
     * @param mixed $currentValue Effective value today, normalized to the catalog type of the key
     */
    public function __construct(
        public string $key,
        public mixed $presetValue,
        public mixed $currentValue,
    ) {
    }
}
