import { describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import {
  createHilosProfilePasswordChangeActions,
  createHilosProfilePasswordChangeFlow,
} from '../../src/profile/passwordChange.js'

const OPENING = { channel: 'email', destination: 'me@example.test' }
const SKIP = { required: false, purpose: 'change your password' }
const ASK = {
  required: true,
  purpose: 'change your password',
  method: 'password',
}
function setup(extra: Record<string, unknown> = {}) {
  const answers: Record<string, unknown> = {
    hilos_step_up_start: SKIP,
    profile_change_password_open: OPENING,
    ...extra,
  }
  const dispatch = vi.fn(
    (
      name: string,
      payload: unknown,
      options: { replySchema?: { parse(value: unknown): unknown } } = {},
    ): { done: Promise<{ reply: unknown }> } => {
      void payload
      const answer = answers[name] ?? []
      return {
        done:
          typeof answer === 'string'
            ? Promise.reject(new ActionError(name, 'fail', answer))
            : Promise.resolve({
                reply: options.replySchema?.parse(answer) ?? answer,
              }),
      }
    },
  )
  const actions = { dispatch } as unknown as ActionLifecycle
  return {
    flow: createHilosProfilePasswordChangeFlow({ actions }),
    actions,
    dispatch,
    answers,
  }
}

describe('password change', () => {
  it('carries exactly the four action payloads', async () => {
    const world = setup()
    const actions = createHilosProfilePasswordChangeActions(world)
    await actions.open().done
    await actions.requestCode().done
    await actions.confirmCode('123456').done
    await actions.change('123456', ' secret ', false).done
    expect(
      world.dispatch.mock.calls.map(([name, payload]) => [name, payload]),
    ).toEqual([
      ['profile_change_password_open', {}],
      ['profile_change_password_code_request', {}],
      ['profile_change_password_code_confirm', { code: '123456' }],
      [
        'profile_change_password',
        { code: '123456', newPassword: ' secret ', signOutOthers: false },
      ],
    ])
  })
  it('asks for operation proof before opening the code destination', async () => {
    const { flow, dispatch } = setup({ hilos_step_up_start: ASK })
    await flow.open()
    expect(flow.step.get()).toBe('step-up')
    expect(dispatch).toHaveBeenCalledTimes(1)
    flow.stepUp.password.set('current')
    await flow.confirmStepUp()
    expect(flow.step.get()).toBe('start')
    expect(dispatch).toHaveBeenCalledWith(
      'hilos_step_up_confirm',
      expect.objectContaining({
        operation: 'change_password',
        password: 'current',
      }),
    )
  })
  it('shows a refused opening and dispatches no operation action', async () => {
    const { flow, dispatch } = setup({
      hilos_step_up_start: 'Not available under impersonation',
    })
    await flow.open()
    expect(flow.step.get()).toBe('refused')
    expect(flow.refusal.get()).toBe('Not available under impersonation')
    expect(dispatch).toHaveBeenCalledTimes(1)
  })
  it('walks code and password, snapshots the choice and starts again without step-up', async () => {
    const { flow, dispatch } = setup()
    await flow.open()
    expect(flow.step.get()).toBe('start')
    expect(flow.signOutOthers.get()).toBe(true)
    expect(flow.asksBeforeClosing.get()).toBe(false)
    await flow.sendCode()
    expect(flow.asksBeforeClosing.get()).toBe(true)
    flow.code.set('123456')
    await flow.confirmCode()
    expect(flow.step.get()).toBe('password')
    flow.newPassword.set('new-secret')
    flow.signOutOthers.set(false)
    await flow.save()
    flow.signOutOthers.set(true)
    expect(flow.step.get()).toBe('done')
    expect(flow.signedOutOthers.get()).toBe(false)
    expect(flow.asksBeforeClosing.get()).toBe(false)
    await flow.again()
    expect(flow.step.get()).toBe('start')
    expect(flow.code.get()).toBe('')
    expect(
      dispatch.mock.calls.filter(([name]) => name === 'hilos_step_up_start'),
    ).toHaveLength(1)
  })
  it('goes straight to password without an address and submits an empty code', async () => {
    const { flow, dispatch } = setup({
      profile_change_password_open: { channel: null, destination: null },
    })
    await flow.open()
    expect(flow.step.get()).toBe('password')
    flow.code.set('not an address proof')
    flow.newPassword.set('new-secret')
    await flow.save()
    expect(dispatch).toHaveBeenLastCalledWith('profile_change_password', {
      code: '',
      newPassword: 'new-secret',
      signOutOthers: true,
    })
  })
  it('keeps every refused step and its fields for another submit', async () => {
    const { flow, answers } = setup({
      profile_change_password_code_request: 'Send cap reached',
    })
    await flow.open()
    await flow.sendCode()
    expect(flow.step.get()).toBe('start')
    expect(flow.refusal.get()).toBe('Send cap reached')
    answers.profile_change_password_code_request = []
    await flow.sendCode()
    flow.code.set('000000')
    answers.profile_change_password_code_confirm = 'Invalid or expired code'
    await flow.confirmCode()
    expect(flow.step.get()).toBe('code')
    expect(flow.code.get()).toBe('000000')
    answers.profile_change_password_code_confirm = []
    await flow.confirmCode()
    flow.newPassword.set('short')
    answers.profile_change_password = 'Password must be at least 8 characters'
    await flow.save()
    expect(flow.step.get()).toBe('password')
    expect(flow.newPassword.get()).toBe('short')
    expect(flow.code.get()).toBe('000000')
    expect(flow.refusal.get()).toBe('Password must be at least 8 characters')
  })
  it('does not dispatch empty fields and prevents a second save in flight', async () => {
    const { flow, dispatch } = setup({
      profile_change_password_open: { channel: null, destination: null },
    })
    await flow.open()
    await flow.save()
    expect(dispatch).toHaveBeenCalledTimes(2)
    let resolve!: (value: { reply: unknown }) => void
    dispatch.mockImplementationOnce(() => ({
      done: new Promise((done) => {
        resolve = done
      }),
    }))
    flow.newPassword.set('secret')
    const first = flow.save()
    await flow.save()
    expect(dispatch).toHaveBeenCalledTimes(3)
    resolve({ reply: [] })
    await first
    expect(flow.step.get()).toBe('done')
  })
  it.each(['open', 'send', 'confirm', 'save'] as const)(
    'ignores a late %s reply after closing',
    async (at) => {
      const { flow, dispatch } = setup()
      if (at !== 'open') await flow.open()
      if (at === 'confirm' || at === 'save') await flow.sendCode()
      flow.code.set('123456')
      if (at === 'save') {
        await flow.confirmCode()
        flow.newPassword.set('secret')
      }
      let resolve!: (value: { reply: unknown }) => void
      dispatch.mockImplementationOnce(() => ({
        done: new Promise((done) => {
          resolve = done
        }),
      }))
      const pending =
        at === 'open'
          ? flow.open()
          : at === 'send'
            ? flow.sendCode()
            : at === 'confirm'
              ? flow.confirmCode()
              : flow.save()
      flow.close()
      resolve({ reply: at === 'open' ? SKIP : [] })
      await pending
      expect(flow.step.get()).toBe('closed')
      expect(flow.opening.get()).toBeNull()
      expect(flow.newPassword.get()).toBe('')
      expect(flow.refusal.get()).toBeNull()
    },
  )
})
