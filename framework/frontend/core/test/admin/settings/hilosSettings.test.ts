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

/** Build a resolved settings view-model with the given value source and backing. */
function rowWithSource(
  valueSource: SettingValueSource,
  persisted = false,
): HilosSettingRow {
  return {
    key: 'k',
    type: 'string',
    value: 'v',
    overrideValue: null,
    defaultValue: null,
    defaultReferenceKey: null,
    valueSource,
    persisted,
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
        persisted: true,
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
      persisted: true,
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
    expect(row.persisted).toBe(false)
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
  it('reads the row flag, not the value source', () => {
    expect(isPersistedSetting(rowWithSource('override', true))).toBe(true)
    expect(isPersistedSetting(rowWithSource('orphan', true))).toBe(true)
    expect(isPersistedSetting(rowWithSource('default', false))).toBe(false)
    expect(isPersistedSetting(rowWithSource('reference', false))).toBe(false)
  })

  it('is true for a stored row that inherits its value', () => {
    // A row storing NULL resolves to default/reference and still exists: editing
    // it must update, not insert a duplicate.
    expect(isPersistedSetting(rowWithSource('reference', true))).toBe(true)
    expect(isPersistedSetting(rowWithSource('default', true))).toBe(true)
  })
})
