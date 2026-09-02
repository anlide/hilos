<?php

declare(strict_types=1);

namespace Hilos\Pages\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\Settings\Preset\SettingPreset;
use Hilos\Database\Settings\Preset\SettingPresetDifference;

/**
 * State of one preset group as its screen draws it (server → client, HIL-762).
 *
 * Three facts and no phrases. Which preset is applied, what each of them declares, and where the
 * settings have drifted from the applied one. The titles above the cards and the sentence a
 * difference is read as ("keep for 14 days instead of 30") are the frontend's, built out of these
 * fields; units, plurals and language folded in here would have made the mechanism useless to the
 * next section, whose settings are counted in something else.
 *
 * {@see $selected} is null when the stored name is not one the recipe declares — a preset that was
 * renamed or dropped after somebody applied it. No card is lit, there are no differences, and any
 * click repairs it. Guessing a preset instead would put a signature under work nobody did, and
 * refusing the subscription would take the screen down over a line of text.
 */
final class HilosSettingPresetsSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: machine key of the group these presets belong to. */
    public const string group = 'group';

    /** Payload key: name of the applied preset, null when the stored one is unknown to the recipe. */
    public const string selected = 'selected';

    /** Payload key: the presets, in declaration order, which is the order of the cards. */
    public const string presets = 'presets';

    /** Payload key: members of the applied preset whose value differs today; empty when none do. */
    public const string differences = 'differences';

    /** Payload key of a preset entry: its machine name. */
    public const string name = 'name';

    /** Payload key of a preset entry: the value it declares, by setting key. */
    public const string values = 'values';

    /** Payload key of a difference entry: the setting key the two values disagree on. */
    public const string key = 'key';

    /** Payload key of a difference entry: the value the applied preset declares. */
    public const string presetValue = 'presetValue';

    /** Payload key of a difference entry: the value the setting reads today. */
    public const string currentValue = 'currentValue';

    /**
     * @param string $group Machine key of the group
     * @param ?string $selected Applied preset name, null when the stored name is unknown to the recipe
     * @param list<SettingPreset> $presets Presets in the order their cards are drawn
     * @param list<SettingPresetDifference> $differences Members of the applied preset that have drifted
     */
    public function __construct(
        public readonly string $group,
        public readonly ?string $selected,
        public readonly array $presets,
        public readonly array $differences,
    ) {
    }

    /**
     * @return array<string, mixed> Group state as it goes out to the browser
     */
    public function toArray(): array
    {
        return [
            self::group => $this->group,
            self::selected => $this->selected,
            self::presets => array_map(
                static fn (SettingPreset $preset): array => [
                    self::name => $preset->name,
                    self::values => $preset->values,
                ],
                $this->presets,
            ),
            self::differences => array_map(
                static fn (SettingPresetDifference $difference): array => [
                    self::key => $difference->key,
                    self::presetValue => $difference->presetValue,
                    self::currentValue => $difference->currentValue,
                ],
                $this->differences,
            ),
        ];
    }

    /**
     * Reads the group state back from its wire form.
     *
     * @param array<string, mixed> $data Wire form of the group state
     * @return static Restored group state
     * @throws InvalidFormatException When a field the state has no meaning without is absent or of the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            group: self::requireString($data, self::group),
            selected: self::optionalString($data, self::selected),
            presets: self::presetsFromArray(self::requireArray($data, self::presets)),
            differences: self::differencesFromArray(self::requireArray($data, self::differences)),
        );
    }

    /**
     * Reads the preset entries back, dropping anything that is not one.
     *
     * @param array<int|string, mixed> $entries Wire form of the preset list
     * @return list<SettingPreset> Restored presets, in the order they arrived
     * @throws InvalidFormatException When an entry names no preset or carries no values
     */
    private static function presetsFromArray(array $entries): array
    {
        $presets = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $presets[] = new SettingPreset(
                self::requireString($entry, self::name),
                self::requireArray($entry, self::values),
            );
        }

        return $presets;
    }

    /**
     * Reads the difference entries back, dropping anything that is not one.
     *
     * @param array<int|string, mixed> $entries Wire form of the difference list
     * @return list<SettingPresetDifference> Restored differences, in the order they arrived
     * @throws InvalidFormatException When an entry names no setting key
     */
    private static function differencesFromArray(array $entries): array
    {
        $differences = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $differences[] = new SettingPresetDifference(
                self::requireString($entry, self::key),
                $entry[self::presetValue] ?? null,
                $entry[self::currentValue] ?? null,
            );
        }

        return $differences;
    }
}
