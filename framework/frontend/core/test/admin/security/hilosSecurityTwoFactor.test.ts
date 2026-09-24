// Covers the two-step verification admin headless (HIL-494): a settings row read
// off its inline slot, the row key it falls back to, and the edit sent under the
// keys the page reads.
import { describe, expect, it } from 'vitest'

import {
  createHilosSecurityTwoFactorActions,
  describeHilosSecondFactorSetting,
  HilosSecondFactorSettingKey,
  resolveHilosTwoFactorSettingRow,
} from '../../../src/admin/security/hilosSecurityTwoFactor.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'

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
