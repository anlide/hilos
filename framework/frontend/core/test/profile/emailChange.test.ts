import { describe, expect, it } from 'vitest'

import {
  createHilosProfileEmailChangeActions,
  type ActionHandle,
  type ActionLifecycle,
} from '../../src/index.js'

describe('profile email change', () => {
  it('dispatches the four steps under their wire names with their payloads', () => {
    const sent: Array<{ action: string; payload: unknown }> = []
    const handle = {} as ActionHandle
    const actions = {
      dispatch(action: string, payload: unknown): ActionHandle {
        sent.push({ action, payload })

        return handle
      },
    } as unknown as ActionLifecycle
    const emailChange = createHilosProfileEmailChangeActions({ actions })

    expect(emailChange.requestCurrentCode()).toBe(handle)
    expect(emailChange.confirmCurrentCode('111111')).toBe(handle)
    expect(emailChange.requestNewCode('111111', 'new@example.com')).toBe(handle)
    expect(
      emailChange.confirmNewCode('111111', 'new@example.com', '222222'),
    ).toBe(handle)
    expect(sent).toEqual([
      { action: 'profile_change_email_current_request', payload: {} },
      {
        action: 'profile_change_email_current_confirm',
        payload: { code: '111111' },
      },
      {
        action: 'profile_change_email_new_request',
        payload: { currentCode: '111111', email: 'new@example.com' },
      },
      {
        action: 'profile_change_email_new_confirm',
        payload: {
          currentCode: '111111',
          email: 'new@example.com',
          code: '222222',
        },
      },
    ])
  })
})
