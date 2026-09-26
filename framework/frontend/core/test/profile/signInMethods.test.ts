import { describe, expect, it } from 'vitest'

import {
  createHilosProfileSignInActions,
  PROFILE_PASSWORD_MODE_ADDED,
  PROFILE_PASSWORD_MODE_CHANGED,
  PROFILE_PASSWORD_SIGNAL_SCHEMAS,
  profilePasswordUpdatedSchema,
  SIGNAL_PROFILE_PASSWORD_UPDATED,
  type ActionHandle,
  type ActionLifecycle,
} from '../../src/index.js'

describe('profile sign-in methods', () => {
  it('dispatches each tracked action under its wire name with its payload', () => {
    const sent: Array<{ action: string; payload: unknown }> = []
    const handle = {} as ActionHandle
    const actions = {
      dispatch(action: string, payload: unknown): ActionHandle {
        sent.push({ action, payload })

        return handle
      },
    } as unknown as ActionLifecycle
    const signIn = createHilosProfileSignInActions({ actions })

    expect(signIn.setPassword('old', 'new-secret')).toBe(handle)
    expect(signIn.setPassword(null, 'new-secret')).toBe(handle)
    expect(signIn.unlinkIdentity(7)).toBe(handle)
    expect(signIn.requestSmsAdd('+15551234')).toBe(handle)
    expect(signIn.confirmSmsAdd('+15551234', '123456')).toBe(handle)
    expect(signIn.requestPasswordAdd('a@example.com')).toBe(handle)
    expect(signIn.confirmPasswordAdd('a@example.com', '123456', 'secret')).toBe(
      handle,
    )
    expect(sent).toEqual([
      {
        action: 'profile_set_password',
        payload: { currentPassword: 'old', newPassword: 'new-secret' },
      },
      {
        action: 'profile_set_password',
        payload: { currentPassword: '', newPassword: 'new-secret' },
      },
      { action: 'profile_unlink_identity', payload: { identityId: 7 } },
      { action: 'profile_add_sms_request', payload: { phone: '+15551234' } },
      {
        action: 'profile_add_sms_confirm',
        payload: { phone: '+15551234', code: '123456' },
      },
      {
        action: 'profile_add_password_request',
        payload: { email: 'a@example.com' },
      },
      {
        action: 'profile_add_password_confirm',
        payload: {
          email: 'a@example.com',
          code: '123456',
          newPassword: 'secret',
        },
      },
    ])
  })

  it('parses the password-updated mode and reads an unknown one as changed', () => {
    expect(
      PROFILE_PASSWORD_SIGNAL_SCHEMAS[SIGNAL_PROFILE_PASSWORD_UPDATED],
    ).toBe(profilePasswordUpdatedSchema)
    expect(profilePasswordUpdatedSchema.parse({ mode: 'added' }).mode).toBe(
      PROFILE_PASSWORD_MODE_ADDED,
    )
    expect(profilePasswordUpdatedSchema.parse({ mode: 'changed' }).mode).toBe(
      PROFILE_PASSWORD_MODE_CHANGED,
    )
    expect(profilePasswordUpdatedSchema.parse({ mode: 'renamed' }).mode).toBe(
      PROFILE_PASSWORD_MODE_CHANGED,
    )
  })
})
