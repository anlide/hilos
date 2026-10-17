// Covers the two-step verification admin headless (HIL-494): the eight settings
// and their words, a settings row read off its inline slot with a switch's
// position, the row key it falls back to, the rows locked while the factor is
// off (HIL-1303), and the two writes sent under the keys the page reads.
import { describe, expect, it } from 'vitest'

import {
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  describeHilosSecondFactorSetting,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HilosSecondFactorSettingKey,
  isHilosSecondFactorSwitch,
  resolveHilosTwoFactorSettingRow,
} from '../../../src/admin/security/hilosSecurityTwoFactor.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/**
 * A raw settings row as the table window carries it.
 *
 * @param rowKey The setting key.
 * @param value The value in force, as text.
 */
function settingRow(rowKey: string, value: string): TableRow {
  return {
    rowKey,
    slots: { setting: { rowKey, value, defaultValue: value } },
  }
}

/** A table over a connection that takes every send, with no actions. */
function tableSetup() {
  const connection = {
    sendTableViewport: () => true,
    sendTableRendered: () => true,
    sendTableRowFocus: () => true,
  } as unknown as HilosConnection

  return createHilosSecurityTwoFactorTable({
    connection,
    scopes: new ScopeManager(),
    actions: {} as unknown as ActionLifecycle,
  })
}

describe('the eight settings', () => {
  it('spells the keys in the order the screen lists them, each with its words', () => {
    expect(Object.values(HilosSecondFactorSettingKey)).toStrictEqual([
      'auth.second_factor.enabled',
      'auth.second_factor.forget_trusts_on_disable',
      'auth.second_factor.required',
      'auth.second_factor.trust_days',
      'auth.second_factor.backup_codes',
      'auth.second_factor.reset_wait_default_days',
      'auth.second_factor.reset_wait_min_days',
      'auth.second_factor.reset_wait_max_days',
    ])
    for (const settingKey of Object.values(HilosSecondFactorSettingKey)) {
      expect(HILOS_SECOND_FACTOR_SETTING_COPY[settingKey]?.label).toBeTruthy()
    }
    expect(
      HILOS_SECOND_FACTOR_SETTING_COPY[HilosSecondFactorSettingKey.enabled],
    ).toStrictEqual({
      label: 'Two-factor enabled',
      hint: "Turn it off and no sign-in asks for a code; people's apps and backup codes are kept.",
    })
    expect(
      HILOS_SECOND_FACTOR_SETTING_COPY[
        HilosSecondFactorSettingKey.forgetTrustsOnDisable
      ],
    ).toStrictEqual({
      label: 'Forget trusted browsers when turned off',
      hint: 'Trusted browsers ask for the code again once two-factor is back on.',
    })
  })

  it('tells the two switches from the six values', () => {
    expect(
      Object.values(HilosSecondFactorSettingKey).filter(
        isHilosSecondFactorSwitch,
      ),
    ).toStrictEqual([
      HilosSecondFactorSettingKey.enabled,
      HilosSecondFactorSettingKey.forgetTrustsOnDisable,
    ])
  })
})

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
      enabled: false,
    })
  })

  it('reads the position of a switch, and only of a switch', () => {
    expect(
      resolveHilosTwoFactorSettingRow(
        settingRow(HilosSecondFactorSettingKey.enabled, 'true'),
      ).enabled,
    ).toBe(true)
    expect(
      resolveHilosTwoFactorSettingRow(
        settingRow(HilosSecondFactorSettingKey.forgetTrustsOnDisable, 'false'),
      ).enabled,
    ).toBe(false)
    expect(
      resolveHilosTwoFactorSettingRow(
        settingRow(HilosSecondFactorSettingKey.required, 'true'),
      ).enabled,
    ).toBe(false)
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
    expect(row.enabled).toBe(HIDDEN_VALUE)
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
      enabled: false,
    })
  })
})

describe('createHilosSecurityTwoFactorTable', () => {
  it('locks every row below the first while the factor is off, and none while it is on', () => {
    const table = tableSetup()
    expect(table.locked.get()).toStrictEqual([])
    const window = (enabled: string) =>
      table.controller.ingestSubscriptionWindow(
        [
          settingRow(HilosSecondFactorSettingKey.enabled, enabled),
          settingRow(HilosSecondFactorSettingKey.forgetTrustsOnDisable, 'true'),
          settingRow(HilosSecondFactorSettingKey.trustDays, '30'),
        ],
        3,
        true,
        null,
        null,
        10,
        undefined,
        [],
      )

    window('true')
    expect(table.locked.get()).toStrictEqual([])

    window('false')
    expect(table.locked.get()).toStrictEqual([
      'auth.second_factor.forget_trusts_on_disable',
      'auth.second_factor.required',
      'auth.second_factor.trust_days',
      'auth.second_factor.backup_codes',
      'auth.second_factor.reset_wait_default_days',
      'auth.second_factor.reset_wait_min_days',
      'auth.second_factor.reset_wait_max_days',
    ])

    window('true')
    expect(table.locked.get()).toStrictEqual([])
  })

  it('locks nothing on a viewer the switch is hidden from', () => {
    const table = tableSetup()
    table.controller.ingestSubscriptionWindow(
      [
        {
          rowKey: HilosSecondFactorSettingKey.enabled,
          slots: {
            setting: {
              rowKey: HilosSecondFactorSettingKey.enabled,
              value: { _hidden: true },
              defaultValue: { _hidden: true },
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

    expect(table.locked.get()).toStrictEqual([])
  })

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

  it('sends a switch under the keys the page reads', () => {
    const sent: Array<{ name: string; payload: unknown }> = []
    const actions = {
      dispatch: (name: string, payload: unknown) => {
        sent.push({ name, payload })

        return { done: Promise.resolve({}) }
      },
    } as unknown as ActionLifecycle

    createHilosSecurityTwoFactorActions({ actions }).switchSet(
      HilosSecondFactorSettingKey.enabled,
      false,
    )

    expect(sent).toStrictEqual([
      {
        name: 'security_2fa_switch_set',
        payload: { key: 'auth.second_factor.enabled', enabled: false },
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
    expect(
      describeHilosSecondFactorSetting(
        HilosSecondFactorSettingKey.enabled,
        'true',
      ),
    ).toBe('On')
    expect(
      describeHilosSecondFactorSetting(
        HilosSecondFactorSettingKey.forgetTrustsOnDisable,
        'false',
      ),
    ).toBe('Off')
  })
})
