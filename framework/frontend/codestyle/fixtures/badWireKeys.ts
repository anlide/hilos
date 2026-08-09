// Deliberately broken sample: every key below is declared in one of the two forms
// wire-key-ownership.md prescribes and then spelled in another case, so
// WIRE-KEY-CASE must report each one — the flat constant, the entries a row-key
// map spells out itself, and one frozen with `as const`.
//
// This file sits outside every scanned root, so only the fixture test reads it.

/** Row payload key of the persisted override value. */
const SETTING_OVERRIDE_VALUE_FIELD = 'override_value'

/** Row payload key of the effective value's origin. */
export const SETTING_VALUE_SOURCE_FIELD = 'ValueSource'

/** Payload keys of a row slot whose map spells its own keys out. */
export const HilosSettingRowKey = {
  key: 'key',
  defaultValue: 'default_value',
  defaultReferenceKey: 'default_reference_key',
  overrideValue: SETTING_OVERRIDE_VALUE_FIELD,
} as const

/** Row payload key frozen the way a map of keys is frozen. */
export const SETTING_CREATED_AT_FIELD = 'created_at' as const
