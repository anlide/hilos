// Negative sample: nothing here is a field key spelled in the wrong case. A signal
// type, an action name, a table key and a slot name are other contracts with other
// conventions, however snake their values look; a row-key entry that points at a
// `_FIELD` constant is judged where that constant spells the key out; and the rest
// are the shape the rule asks for.
//
// This file sits outside every scanned root, so only the fixture test reads it.

/** Not a field key: the name of a signal, which is snake by its own convention. */
export const SIGNAL_TYPE_SETTING_CHANGED = 'setting_changed'

/** Not a field key: the name of a client action. */
export const SETTING_UPDATE_ACTION = 'setting_update'

/** Not a field key: the key of a browser table. */
export const HILOS_SETTINGS_TABLE = 'hilos_settings'

/** Not a field key: the name of a page entity slot. */
export const HILOS_SETTINGS_SLOT = 'hilos_settings'

/** Row payload key of the setting key. */
export const SETTING_KEY_FIELD = 'key'

/** Row payload key of the persisted override value. */
export const SETTING_OVERRIDE_VALUE_FIELD = 'overrideValue'

/** Payload keys of the settings row slot, each one owned by a constant above. */
export const HilosSettingRowKey = {
  key: SETTING_KEY_FIELD,
  overrideValue: SETTING_OVERRIDE_VALUE_FIELD,
  valueSource: 'valueSource',
} as const
