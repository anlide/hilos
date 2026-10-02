// Covers the impersonation settings admin headless (HIL-1170): a settings row
// read off its inline slot — a switch is on only for the text `true` — the row
// key it falls back to, which of the seven settings are switches, the scope read
// as one of its two values, the row the scope's modal holds in focus, and the
// two writes sent under the keys the page reads.
import { describe, expect, it } from 'vitest'

import {
  createHilosSecurityImpersonationActions,
  createHilosSecurityImpersonationTable,
  HILOS_IMPERSONATION_SCOPE_COPY,
  HILOS_IMPERSONATION_SCOPE_VALUES,
  HILOS_IMPERSONATION_SETTING_COPY,
  hilosImpersonationScopeOf,
  HilosImpersonationSettingKey,
  isHilosImpersonationSwitch,
  resolveHilosImpersonationSettingRow,
} from '../../../src/admin/security/hilosSecurityImpersonation.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

/**
 * A raw impersonation row as the table window carries it.
 *
 * @param rowKey The setting key.
 * @param value The value in force, as text.
 * @param defaultValue The catalog default, as text.
 */
function rawRow(rowKey: string, value: string, defaultValue: string) {
  return { rowKey, slots: { setting: { rowKey, value, defaultValue } } }
}

describe('resolveHilosImpersonationSettingRow', () => {
  it('reads a switch as on only for the text true', () => {
    expect(
      resolveHilosImpersonationSettingRow(
        rawRow(HilosImpersonationSettingKey.equal, 'true', 'true'),
      ),
    ).toStrictEqual({
      rowKey: 'auth.impersonation.equal',
      value: 'true',
      defaultValue: 'true',
      enabled: true,
    })
    expect(
      resolveHilosImpersonationSettingRow(
        rawRow(HilosImpersonationSettingKey.carryAdmin, 'false', 'false'),
      ).enabled,
    ).toBe(false)
    expect(
      resolveHilosImpersonationSettingRow(
        rawRow(HilosImpersonationSettingKey.allowed, '1', 'true'),
      ).enabled,
    ).toBe(false)
  })

  it('preserves hidden value marks on value and defaultValue, and sets enabled to HIDDEN_VALUE for switches', () => {
    const row = resolveHilosImpersonationSettingRow({
      rowKey: HilosImpersonationSettingKey.equal,
      slots: {
        setting: {
          rowKey: HilosImpersonationSettingKey.equal,
          value: { _hidden: true },
          defaultValue: { _hidden: true },
        },
      },
    })

    expect(row.value).toBe(HIDDEN_VALUE)
    expect(row.defaultValue).toBe(HIDDEN_VALUE)
    expect(row.enabled).toBe(HIDDEN_VALUE)
  })

  it('never reads the scope as a switch that is on', () => {
    expect(
      resolveHilosImpersonationSettingRow(
        rawRow(HilosImpersonationSettingKey.scope, 'true', 'act'),
      ),
    ).toStrictEqual({
      rowKey: 'auth.impersonation.scope',
      value: 'true',
      defaultValue: 'act',
      enabled: false,
    })
  })

  it('falls back to the table row key when the slot is absent', () => {
    expect(
      resolveHilosImpersonationSettingRow({
        rowKey: HilosImpersonationSettingKey.blocked,
        slots: {},
      }),
    ).toStrictEqual({
      rowKey: 'auth.impersonation.blocked',
      value: '',
      defaultValue: '',
      enabled: false,
    })
  })
})

describe('isHilosImpersonationSwitch', () => {
  it('names six switches and the scope as the one that is not', () => {
    const keys = Object.values(HilosImpersonationSettingKey)

    expect(keys).toHaveLength(7)
    expect(keys.filter(isHilosImpersonationSwitch)).toEqual([
      'auth.impersonation.allowed',
      'auth.impersonation.account_access',
      'auth.impersonation.carry_admin',
      'auth.impersonation.blocked',
      'auth.impersonation.frozen',
      'auth.impersonation.equal',
    ])
  })

  it('has words for every setting and both scope values', () => {
    for (const key of Object.values(HilosImpersonationSettingKey)) {
      expect(HILOS_IMPERSONATION_SETTING_COPY[key]?.label).toBeTruthy()
      expect(HILOS_IMPERSONATION_SETTING_COPY[key]?.hint).toBeTruthy()
    }
    expect(
      HILOS_IMPERSONATION_SCOPE_VALUES.map(
        (value) => HILOS_IMPERSONATION_SCOPE_COPY[value],
      ),
    ).toEqual(['View only', 'View and act'])
  })
})

describe('hilosImpersonationScopeOf', () => {
  it('reads view as view and anything else as the default, act', () => {
    const scopeRow = (value: string) =>
      resolveHilosImpersonationSettingRow(
        rawRow(HilosImpersonationSettingKey.scope, value, 'act'),
      )

    expect(hilosImpersonationScopeOf(scopeRow('view'))).toBe('view')
    expect(hilosImpersonationScopeOf(scopeRow('act'))).toBe('act')
    expect(hilosImpersonationScopeOf(scopeRow(''))).toBe('act')
    expect(hilosImpersonationScopeOf(scopeRow('VIEW'))).toBe('act')
  })
})

describe('createHilosSecurityImpersonationTable', () => {
  it('opens its window on its own page and hands the scope row into focus to the modal', () => {
    const viewports: Array<{ page: string; tableKey: string }> = []
    const focus: Array<{ page: string; tableKey: string; rowKey: string }> = []
    const connection = {
      sendTableViewport: (page: string, tableKey: string) =>
        viewports.push({ page, tableKey }) > 0,
      sendTableRendered: () => true,
      sendTableRowFocus: (page: string, tableKey: string, rowKey: string) =>
        focus.push({ page, tableKey, rowKey }) > 0,
    } as unknown as HilosConnection
    const table = createHilosSecurityImpersonationTable({
      connection,
      scopes: new ScopeManager(),
      actions: {} as unknown as ActionLifecycle,
    })
    table.controller.ingestSubscriptionWindow(
      [rawRow(HilosImpersonationSettingKey.scope, 'view', 'act')],
      1,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )

    expect(
      table.controller.focusRow(HilosImpersonationSettingKey.scope)?.value,
    ).toBe('view')
    table.controller.releaseFocus()

    expect(focus).toEqual([
      {
        page: 'hilos_security_impersonation',
        tableKey: 'hilosSecurityImpersonation',
        rowKey: 'auth.impersonation.scope',
      },
      {
        page: 'hilos_security_impersonation',
        tableKey: 'hilosSecurityImpersonation',
        rowKey: '',
      },
    ])
    for (const viewport of viewports) {
      expect(viewport).toEqual({
        page: 'hilos_security_impersonation',
        tableKey: 'hilosSecurityImpersonation',
      })
    }
  })
})

describe('createHilosSecurityImpersonationActions', () => {
  it('sends a switch and the scope under the keys the page reads', () => {
    const sent: Array<{ name: string; payload: unknown }> = []
    const actions = {
      dispatch: (name: string, payload: unknown) => {
        sent.push({ name, payload })

        return { done: Promise.resolve({}) }
      },
    } as unknown as ActionLifecycle
    const writes = createHilosSecurityImpersonationActions({ actions })

    writes.sendSwitchSet(HilosImpersonationSettingKey.carryAdmin, true)
    writes.sendScopeSet('view')

    expect(sent).toStrictEqual([
      {
        name: 'security_impersonation_switch_set',
        payload: { key: 'auth.impersonation.carry_admin', enabled: true },
      },
      {
        name: 'security_impersonation_scope_set',
        payload: { scope: 'view' },
      },
    ])
  })
})
