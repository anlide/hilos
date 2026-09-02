import { describe, expect, it } from 'vitest'

import {
  createHilosSettingsActions,
  hasCustomValue,
  isOrphanSetting,
  resolveHilosSettingRow,
  type HilosSettingRow,
  type HilosSettingsContext,
  type SettingValueSource,
} from '../../../src/admin/settings/hilosSettings.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** Build a settings table row whose inline `settings` slot carries the given fields. */
function settingsRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { settings: slot } }
}

/** Build a resolved settings view-model with the given value source and override. */
function rowWithSource(
  valueSource: SettingValueSource,
  overrideValue: string | null = null,
): HilosSettingRow {
  return {
    key: 'k',
    type: 'string',
    value: 'v',
    overrideValue,
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
        overrideValue: '1.5',
        defaultValue: '1.0',
        defaultReferenceKey: 'default_bot_timeout_sec',
        valueSource: 'reference',
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

  it('narrows an unknown valueSource to orphan', () => {
    const row = resolveHilosSettingRow(
      settingsRow('weird', { key: 'weird', valueSource: 'not-a-source' }),
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

describe('hasCustomValue', () => {
  it('reads the override value, not the value source', () => {
    expect(hasCustomValue(rowWithSource('override', 'mine'))).toBe(true)
    expect(hasCustomValue(rowWithSource('orphan', 'mine'))).toBe(true)
    expect(hasCustomValue(rowWithSource('default'))).toBe(false)
    expect(hasCustomValue(rowWithSource('reference'))).toBe(false)
  })
})

describe('createHilosSettingsActions', () => {
  /** A context whose action lifecycle records every dispatch. */
  function recordingContext(): {
    context: HilosSettingsContext
    calls: Array<{ action: string; payload: Record<string, unknown> }>
  } {
    const calls: Array<{
      action: string
      payload: Record<string, unknown>
    }> = []
    const context = {
      connection: {},
      scopes: {},
      actions: {
        dispatch(
          action: string,
          payload: Record<string, unknown>,
        ): ActionHandle {
          calls.push({ action, payload })

          return {} as ActionHandle
        },
      },
    } as unknown as HilosSettingsContext

    return { context, calls }
  }

  it('dispatches reset with the key only', () => {
    const { context, calls } = recordingContext()
    createHilosSettingsActions(context).sendSettingReset('example_string')

    expect(calls).toEqual([
      {
        action: 'setting_reset',
        payload: { key: 'example_string' },
      },
    ])
  })
})
