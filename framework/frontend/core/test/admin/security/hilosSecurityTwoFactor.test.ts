// Covers the two-step verification admin headless (HIL-494): a settings row read
// off its inline slot, the row key it falls back to, and the edit sent under the
// keys the page reads.
import { describe, expect, it } from 'vitest'

import {
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  describeHilosSecondFactorSetting,
  HilosSecondFactorSettingKey,
  resolveHilosTwoFactorSettingRow,
} from '../../../src/admin/security/hilosSecurityTwoFactor.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

describe('resolveHilosTwoFactorSettingRow', () => {
  it('maps the setting slot onto the view-model', () => {
    expect(
      resolveHilosTwoFactorSettingRow({
        rowKey: HilosSecondFactorSettingKey.trustDays,
        slots: {
          setting: {
            rowKey: HilosSecondFactorSettingKey.trustDays,
            value: '14',
            defaultValue: '30',
          },
        },
      }),
    ).toStrictEqual({
      rowKey: 'auth.second_factor.trust_days',
      value: '14',
      defaultValue: '30',
    })
  })

  it('preserves hidden value marks on value and defaultValue', () => {
    const row = resolveHilosTwoFactorSettingRow({
      rowKey: HilosSecondFactorSettingKey.trustDays,
      slots: {
        setting: {
          rowKey: HilosSecondFactorSettingKey.trustDays,
          value: { _hidden: true },
          defaultValue: { _hidden: true },
        },
      },
    })

    expect(row.value).toBe(HIDDEN_VALUE)
    expect(row.defaultValue).toBe(HIDDEN_VALUE)
  })

  it('falls back to the table row key when the slot is absent', () => {
    expect(
      resolveHilosTwoFactorSettingRow({
        rowKey: HilosSecondFactorSettingKey.required,
        slots: {},
      }),
    ).toStrictEqual({
      rowKey: 'auth.second_factor.required',
      value: '',
      defaultValue: '',
    })
  })
})

describe('createHilosSecurityTwoFactorTable', () => {
  it('hands a setting row into focus to the dialog over it, and lets it go with an empty key', () => {
    const focus: Array<{ page: string; tableKey: string; rowKey: string }> = []
    const connection = {
      sendTableViewport: () => true,
      sendTableRendered: () => true,
      sendTableRowFocus: (page: string, tableKey: string, rowKey: string) =>
        focus.push({ page, tableKey, rowKey }) > 0,
    } as unknown as HilosConnection
    const table = createHilosSecurityTwoFactorTable({
      connection,
      scopes: new ScopeManager(),
      actions: {} as unknown as ActionLifecycle,
    })
    table.controller.ingestSubscriptionWindow(
      [
        {
          rowKey: HilosSecondFactorSettingKey.trustDays,
          slots: {
            setting: {
              rowKey: HilosSecondFactorSettingKey.trustDays,
              value: '14',
              defaultValue: '30',
            },
          },
        },
      ],
      1,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )

    expect(
      table.controller.focusRow(HilosSecondFactorSettingKey.trustDays)?.value,
    ).toBe('14')
    table.controller.releaseFocus()

    expect(focus).toEqual([
      {
        page: 'hilos_security_2fa',
        tableKey: 'hilosSecurityTwoFactor',
        rowKey: 'auth.second_factor.trust_days',
      },
      {
        page: 'hilos_security_2fa',
        tableKey: 'hilosSecurityTwoFactor',
        rowKey: '',
      },
    ])
  })
})

describe('createHilosSecurityTwoFactorActions', () => {
  it('sends the setting under the keys the page reads', () => {
    const sent: Array<{ name: string; payload: unknown }> = []
    const actions = {
      dispatch: (name: string, payload: unknown) => {
        sent.push({ name, payload })

        return { done: Promise.resolve({}) }
      },
    } as unknown as ActionLifecycle

    createHilosSecurityTwoFactorActions({ actions }).sendSettingSet(
      HilosSecondFactorSettingKey.required,
      'everyone',
    )

    expect(sent).toStrictEqual([
      {
        name: 'security_2fa_setting_set',
        payload: { key: 'auth.second_factor.required', value: 'everyone' },
      },
    ])
  })
})

describe('describeHilosSecondFactorSetting', () => {
  it('says each value in words', () => {
    expect(
      describeHilosSecondFactorSetting(
        HilosSecondFactorSettingKey.required,
        'admins',
      ),
    ).toBe('Administrators')
    expect(
      describeHilosSecondFactorSetting(
        HilosSecondFactorSettingKey.trustDays,
        '0',
      ),
    ).toBe('Off')
    expect(
      describeHilosSecondFactorSetting(
        HilosSecondFactorSettingKey.trustDays,
        '30',
      ),
    ).toBe('30 days')
    expect(
      describeHilosSecondFactorSetting(
        HilosSecondFactorSettingKey.backupCodes,
        '10',
      ),
    ).toBe('10 codes')
  })
})
