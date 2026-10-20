// @hilos/core/admin/settings: lazy section APIs.

export {
  resolveHilosSettingRow,
  isOrphanSetting,
  hasCustomValue,
  createHilosSettingsTable,
  createHilosSettingsActions,
  SETTING_KEY_FIELD,
  SETTING_VALUE_FIELD,
  type SettingValueSource,
  type HilosSettingRow,
  type HilosSettingsContext,
  type HilosSettingsTable,
  type HilosSettingsActions,
} from './hilosSettings.js'
export {
  createHilosSettingEdit,
  type HilosSettingEditFields,
  type HilosSettingEditForm,
} from './hilosSettingEdit.js'
export {
  settingPresetsSignalSchemas,
  PRESET_GROUP_FIELD,
  PRESET_SELECTED_FIELD,
  PRESET_PRESETS_FIELD,
  PRESET_DIFFERENCES_FIELD,
  PRESET_NAME_FIELD,
  PRESET_VALUES_FIELD,
  PRESET_DIFFERENCE_KEY_FIELD,
  PRESET_DIFFERENCE_PRESET_VALUE_FIELD,
  PRESET_DIFFERENCE_CURRENT_VALUE_FIELD,
  type HilosSettingPreset,
  type HilosSettingPresetDifference,
  type HilosSettingPresetsState,
} from '../../protocol/settingPresetsSignals.js'
export {
  createHilosSettingPresets,
  createHilosSettingPresetsActions,
  presetsOf,
  selectedPresetOf,
  differencesOf,
  isPresetApplied,
  hasDifferences,
  isSelectionUnknown,
  SETTING_PRESETS_APPLY_ACTION,
  type HilosSettingPresetsContext,
  type HilosSettingPresetsHandle,
  type HilosSettingPresetsActions,
  type HilosSettingPresetsVocabulary,
} from './hilosSettingPresets.js'
