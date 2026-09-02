<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Preset;

use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingPresetIncompleteException;
use Hilos\Database\Settings\Exception\SettingPresetUnknownException;
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Reads and applies the presets of one group — the whole of the mechanism (HIL-762).
 *
 * Three questions and one operation: which preset was applied, where the settings have drifted
 * from it since, and put them back. The section that offers presets holds one of these over its
 * own group; nothing else about the section is known here, which is what makes the next section
 * cost a recipe rather than a mechanism.
 *
 * Values are written through the doors of the settings layer ({@see HilosSettingsTable}) and never
 * around them into the ORM. A preset writing its own way would be a second way to store a setting,
 * which is exactly what HIL-780 spent itself closing.
 *
 * Applying a preset writes a row for EVERY member, including one whose value already equals the
 * catalog default. The default of these keys is an environment variable — per node — while a
 * settings row is shared by the whole database; a member left without a row would sit at a
 * different value on every node, and a preset is a statement about the installation.
 */
final readonly class SettingPresetResolver
{
    /**
     * Creates a resolver over one preset group.
     *
     * @param SettingPresetGroup $group Group whose presets this resolver reads and applies
     */
    public function __construct(
        private SettingPresetGroup $group,
    ) {
    }

    /**
     * Returns the name of the applied preset.
     *
     * @return ?string Applied preset name, or null when the stored name is not one of the group's
     * @throws DatabaseException When the persisted setting lookup fails
     * @throws SettingException When the settings accessor is unavailable or the selection key is not cataloged
     */
    public function selectedName(): ?string
    {
        return $this->selectedPreset()?->name;
    }

    /**
     * Returns the members whose effective value differs from what the applied preset declares.
     *
     * @return list<SettingPresetDifference> Differences of the applied preset, empty when none is applied
     * @throws DatabaseException When a persisted setting lookup fails
     * @throws SettingException When the settings accessor is unavailable or a member key is not cataloged
     */
    public function differences(): array
    {
        $preset = $this->selectedPreset();
        if ($preset === null) {
            return [];
        }

        $accessor = $this->accessor();
        $differences = [];
        foreach ($preset->values as $key => $presetValue) {
            $type = $accessor->typeFor($key);
            $declared = $this->normalizedValue($presetValue, $type);
            $current = $this->normalizedValue($accessor->effectiveValueFor($key), $type);
            if ($declared !== $current) {
                $differences[] = new SettingPresetDifference($key, $declared, $current);
            }
        }

        return $differences;
    }

    /**
     * Applies a preset: writes the value of every member, then the name of the preset itself.
     *
     * Every value is checked before the first of them is written, so a refusal leaves nothing
     * written. A half-applied preset is the worst state the screen could be in: the card would say
     * it is on while half the values stayed behind, and the differences would explain that as an
     * administrator's own edit.
     *
     * Two things are checked, and the absent value is the one a rule cannot catch. A key that
     * declares no rule accepts anything, null included - and null reaches the settings table as a
     * reset, which it refuses as an action of its own. Refused there, it would be refused with
     * earlier members already written, which is the very state this paragraph is about.
     *
     * The selection is written last for the same reason it is written at all: it is a signature
     * under work already done. An interruption in the middle leaves "the old preset, with
     * differences", which the screen knows how to show, rather than "the new preset, untouched
     * values", which would read as somebody's hand edit.
     *
     * Applying a preset that is already on and has no differences is a success that changes
     * nothing — a second administrator may have pressed the same card first, and a race between
     * two of them must not turn into an error on either screen.
     *
     * @param string $name Machine name of the preset to apply
     * @throws SettingPresetUnknownException When the group declares no preset under that name
     * @throws SettingPresetIncompleteException When the preset declares no value for one of its members
     * @throws SettingValueRefusedException When a value of the preset fails the rule its key declares
     * @throws SettingInvalidValueException When the catalog names a rule that is not one
     * @throws TableActionException When the settings table is not registered in the table context
     * @throws DatabaseException When the active database context is not a HilosDbContext
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws HilosException When catalog validation or settings persistence fails
     */
    public function apply(string $name): void
    {
        $preset = $this->group->presetByName($name)
            ?? throw new SettingPresetUnknownException(
                "Setting preset '{$name}' is not declared in group '{$this->group->key}'",
            );

        foreach ($preset->values as $key => $value) {
            if ($value === null) {
                throw new SettingPresetIncompleteException(
                    "Setting preset '{$preset->name}' declares no value for '{$key}'",
                );
            }
            SettingValueRules::assertValid($key, $value);
        }
        SettingValueRules::assertValid($this->group->selectionSettingKey, $preset->name);

        $table = $this->settingsTable();
        foreach ($preset->values as $key => $value) {
            $table->actions->add($key, $value);
        }
        $table->actions->add($this->group->selectionSettingKey, $preset->name);
    }

    /**
     * Returns the preset the stored selection names.
     *
     * @return ?SettingPreset Applied preset, or null when the stored name is not one of the group's
     * @throws DatabaseException When the persisted setting lookup fails
     * @throws SettingException When the settings accessor is unavailable or the selection key is not cataloged
     */
    private function selectedPreset(): ?SettingPreset
    {
        $stored = $this->accessor()->effectiveValueFor($this->group->selectionSettingKey);
        if (!is_string($stored)) {
            return null;
        }

        return $this->group->presetByName($stored);
    }

    /**
     * Reads a value as the type its key is cataloged with, so the comparison is not a textual one.
     *
     * @param mixed $value Value declared by a preset or read from the settings
     * @param string $type Catalog type of the key
     * @return mixed Value in the catalog type of the key
     */
    private function normalizedValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => (int)$value,
            SettingsCatalogConstants::TYPE_FLOAT => (float)$value,
            SettingsCatalogConstants::TYPE_BOOLEAN => (bool)$value,
            default => is_scalar($value) ? (string)$value : $value,
        };
    }

    /**
     * Resolves the settings accessor the group's keys are cataloged in.
     *
     * @return SettingsAccessor Settings accessor bound to the facade
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     */
    private function accessor(): SettingsAccessor
    {
        return Hilos::$setting
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');
    }

    /**
     * Resolves the framework settings table presets write through.
     *
     * @return HilosSettingsTable Registered settings table definition
     * @throws TableActionException When the settings table is not registered in the table context
     */
    private function settingsTable(): HilosSettingsTable
    {
        $table = Hilos::$table?->get(HilosSettingsTable::TABLE);
        if (!$table instanceof HilosSettingsTable) {
            throw new TableActionException('Settings table is not registered in the table context');
        }

        return $table;
    }
}
