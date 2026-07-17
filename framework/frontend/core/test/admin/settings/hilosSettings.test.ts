import { describe, expect, it } from 'vitest'

import {
  isOrphanSetting,
  isPersistedSetting,
  resolveHilosSettingRow,
  type HilosSettingRow,
  type SettingValueSource,
} from '../../../src/admin/settings/hilosSettings.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** Build a settings table row whose inline `settings` slot carries the given fields. */
function settingsRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { settings: slot } }
}

/** Build a resolved settings view-model with the given value source. */
function rowWithSource(valueSource: SettingValueSource): HilosSettingRow {
  return {
    key: 'k',
    type: 'string',
    value: 'v',
    overrideValue: null,
    defaultValue: null,
    defaultReferenceKey: null,
    valueSource,
  }
}

describe('resolveHilosSettingRow', () => {
  it('maps every slot field onto the view-model', () => {
    const row = resolveHilosSettingRow(
      settingsRow('chat_bot_timeout_sec', {
        key: 'chat_bot_timeout_sec',
        type: 'float',
        value: '1.5',
        override_value: '1.5',
        default_value: '1.0',
        default_reference_key: 'default_bot_timeout_sec',
        value_source: 'reference',
      }),
    )

    expect(row).toEqual({
      key: 'chat_bot_timeout_sec',
      type: 'float',
      value: '1.5',
      overrideValue: '1.5',
      defaultValue: '1.0',
      defaultReferenceKey: 'default_bot_timeout_sec',
      valueSource: 'reference',
    })
  })

  it('falls back to the row key and orphan source when the slot is absent', () => {
    const row = resolveHilosSettingRow(settingsRow('stray_key', undefined))

    expect(row.key).toBe('stray_key')
    expect(row.type).toBe('')
    expect(row.value).toBeNull()
    expect(row.overrideValue).toBeNull()
    expect(row.defaultValue).toBeNull()
    expect(row.defaultReferenceKey).toBeNull()
    expect(row.valueSource).toBe('orphan')
  })

  it('narrows an unknown value_source to orphan', () => {
    const row = resolveHilosSettingRow(
      settingsRow('weird', { key: 'weird', value_source: 'not-a-source' }),
    )

    expect(row.valueSource).toBe('orphan')
  })
})

describe('isOrphanSetting', () => {
  it('is true only for an orphan source', () => {
    expect(isOrphanSetting(rowWithSource('orphan'))).toBe(true)
    expect(isOrphanSetting(rowWithSource('override'))).toBe(false)
    expect(isOrphanSetting(rowWithSource('default'))).toBe(false)
    expect(isOrphanSetting(rowWithSource('reference'))).toBe(false)
  })
})

describe('isPersistedSetting', () => {
  it('is true for an override or an orphan (both back a DB row)', () => {
    expect(isPersistedSetting(rowWithSource('override'))).toBe(true)
    expect(isPersistedSetting(rowWithSource('orphan'))).toBe(true)
    expect(isPersistedSetting(rowWithSource('default'))).toBe(false)
    expect(isPersistedSetting(rowWithSource('reference'))).toBe(false)
  })
})
