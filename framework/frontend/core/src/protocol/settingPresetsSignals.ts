// Framework wire definitions shared by eager connections and lazy admin models.
import { z } from 'zod'
import { hideable } from '../state/hideableSchema.js'

/** Payload key of the group these presets belong to. */
export const PRESET_GROUP_FIELD = 'group'

/** Payload key of the applied preset's name, null when the stored one is unknown. */
export const PRESET_SELECTED_FIELD = 'selected'

/** Payload key of the presets, in the order their cards are drawn. */
export const PRESET_PRESETS_FIELD = 'presets'

/** Payload key of the members of the applied preset whose value differs today. */
export const PRESET_DIFFERENCES_FIELD = 'differences'

/** Payload key of a preset entry: its machine name. */
export const PRESET_NAME_FIELD = 'name'

/** Payload key of a preset entry: the values it declares, by setting key. */
export const PRESET_VALUES_FIELD = 'values'

/** Payload key of a difference entry: the setting key the two values disagree on. */
export const PRESET_DIFFERENCE_KEY_FIELD = 'key'

/** Payload key of a difference entry: the value the applied preset declares. */
export const PRESET_DIFFERENCE_PRESET_VALUE_FIELD = 'presetValue'

/** Payload key of a difference entry: the value the setting reads today. */
export const PRESET_DIFFERENCE_CURRENT_VALUE_FIELD = 'currentValue'

/** One preset: a name and every value it writes (PHP `SettingPreset`). */
export const presetSchema = z.looseObject({
  [PRESET_NAME_FIELD]: z.string(),
  [PRESET_VALUES_FIELD]: hideable(z.record(z.string(), z.unknown())),
})

/**
 * One drift from the applied preset (PHP `SettingPresetDifference`).
 *
 * Both values are unknown on purpose: a setting is worth whatever its own catalog
 * says, and a group whose members are counted in seconds sits beside one counted in
 * bytes. Reading them is the section vocabulary's job.
 */
export const differenceSchema = z.looseObject({
  [PRESET_DIFFERENCE_KEY_FIELD]: z.string(),
  [PRESET_DIFFERENCE_PRESET_VALUE_FIELD]: z.unknown(),
  [PRESET_DIFFERENCE_CURRENT_VALUE_FIELD]: z.unknown(),
})

/**
 * Payload of the whole screen: which preset is applied, what each of them declares,
 * and where the settings have drifted from the applied one (PHP
 * `HilosSettingPresetsSignalData`).
 */
export const settingPresetsSchema = z.looseObject({
  [PRESET_GROUP_FIELD]: z.string(),
  [PRESET_SELECTED_FIELD]: hideable(z.string().nullable()),
  [PRESET_PRESETS_FIELD]: z.array(presetSchema),
  [PRESET_DIFFERENCES_FIELD]: hideable(z.array(differenceSchema)),
})

/** One preset as its card draws it. */
export type HilosSettingPreset = z.infer<typeof presetSchema>

/** One member of the applied preset whose value differs today. */
export type HilosSettingPresetDifference = z.infer<typeof differenceSchema>

/** The group as the page answers a subscription with it. */
export type HilosSettingPresetsState = z.infer<typeof settingPresetsSchema>

/**
 * The group frame's schema keyed for a connection's `projectSchemas` under the signal
 * name of one section, so the parse boundary validates the frames
 * {@link createHilosSettingPresets} ingests. A section exports its own set and
 * {@link createHilosConnection} merges it in, so a project never restates it.
 *
 * A function rather than a constant because the payload is one per mechanism while
 * the signal name is one per section. A set of its own per section, rather than
 * entries in a neighbour's: two independent screens must not depend on which of them
 * landed first.
 *
 * @param signal Server→client signal `type` this section's group frame arrives under.
 */
export function settingPresetsSignalSchemas(
  signal: string,
): Record<string, typeof settingPresetsSchema> {
  return { [signal]: settingPresetsSchema }
}
