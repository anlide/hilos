import { describe, expect, it, vi } from 'vitest'

import {
  ActionError,
  createHilosProfileEmailChangeActions,
  createHilosProfileEmailChangeFlow,
  type ActionHandle,
  type ActionLifecycle,
} from '../../src/index.js'

const SKIP = { required: false, purpose: 'change your email' }
const ASK = { required: true, purpose: 'change your email', method: 'password' }

/** A flow whose server answers each action from the table: a string refuses with it. */
function setup(extra: Record<string, unknown> = {}) {
  const answers: Record<string, unknown> = {
    hilos_step_up_start: SKIP,
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
      if (answer instanceof Error) return { done: Promise.reject(answer) }
      if (answer instanceof Promise)
        return { done: answer as Promise<{ reply: unknown }> }
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
  const flow = createHilosProfileEmailChangeFlow({
    actions: { dispatch } as unknown as ActionLifecycle,
  })
  return { flow, dispatch, answers }
}

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

describe('profile email change window', () => {
  it('walks the five steps and names the address it set', async () => {
    const { flow, dispatch } = setup()
    await flow.open('old@example.com')
    expect(flow.step.get()).toBe('send-current')
    expect(flow.was.get()).toBe('old@example.com')
    expect(flow.asksBeforeClosing.get()).toBe(false)
    await flow.submit()
    expect(flow.step.get()).toBe('confirm-current')
    expect(flow.asksBeforeClosing.get()).toBe(true)
    expect(flow.canSubmit.get()).toBe(false)
    flow.currentCode.set(' 111111 ')
    await flow.submit()
    expect(flow.step.get()).toBe('new-address')
    flow.newEmail.set(' New@Example.com ')
    await flow.submit()
    expect(flow.step.get()).toBe('confirm-new')
    flow.newCode.set('222222')
    await flow.submit()
    expect(flow.step.get()).toBe('done')
    expect(flow.now.get()).toBe('new@example.com')
    expect(flow.asksBeforeClosing.get()).toBe(false)
    expect(
      dispatch.mock.calls
        .filter(([name]) => name !== 'hilos_step_up_start')
        .map(([name, payload]) => [name, payload]),
    ).toEqual([
      ['profile_change_email_current_request', {}],
      ['profile_change_email_current_confirm', { code: '111111' }],
      [
        'profile_change_email_new_request',
        { currentCode: '111111', email: 'New@Example.com' },
      ],
      [
        'profile_change_email_new_confirm',
        { currentCode: '111111', email: 'New@Example.com', code: '222222' },
      ],
    ])
  })

  it('confirms the person before step one when the operation asks', async () => {
    const { flow, dispatch } = setup({ hilos_step_up_start: ASK })
    await flow.open('old@example.com')
    expect(flow.step.get()).toBe('step-up')
    expect(dispatch).toHaveBeenCalledWith(
      'hilos_step_up_start',
      { operation: 'change_email' },
      expect.anything(),
    )
    flow.stepUp.password.set('secret')
    await flow.submit()
    expect(flow.step.get()).toBe('send-current')
  })

  it('keeps the step and the typed values on a refusal', async () => {
    const { flow } = setup({
      profile_change_email_current_confirm: 'That code is not right',
    })
    await flow.open('old@example.com')
    await flow.submit()
    flow.currentCode.set('000000')
    await flow.submit()
    expect(flow.step.get()).toBe('confirm-current')
    expect(flow.refusal.get()).toBe('That code is not right')
    expect(flow.currentCode.get()).toBe('000000')
    expect(flow.busy.get()).toBe(false)
  })

  it('says the server was not reached when a step got no verdict', async () => {
    const { flow } = setup({
      profile_change_email_current_request: new ActionError(
        'profile_change_email_current_request',
        'timeout',
        'timed out',
      ),
    })
    await flow.open('old@example.com')
    await flow.submit()
    expect(flow.step.get()).toBe('send-current')
    expect(flow.refusal.get()).toBe(
      'Could not reach the server. Please try again.',
    )
  })

  it('walks again from the address it just set', async () => {
    const { flow } = setup()
    await flow.open('old@example.com')
    await flow.submit()
    flow.currentCode.set('111111')
    await flow.submit()
    flow.newEmail.set('new@example.com')
    await flow.submit()
    flow.newCode.set('222222')
    await flow.submit()
    await flow.again()
    expect(flow.step.get()).toBe('send-current')
    expect(flow.was.get()).toBe('new@example.com')
    expect(flow.currentCode.get()).toBe('')
  })

  it('does not reopen for an answer that arrives after closing', async () => {
    let answer: (value: { reply: unknown }) => void = () => {}
    const { flow } = setup({
      profile_change_email_current_request: new Promise((resolve) => {
        answer = resolve
      }),
    })
    await flow.open('old@example.com')
    const submitting = flow.submit()
    flow.close()
    answer({ reply: [] })
    await submitting
    expect(flow.step.get()).toBe('closed')
    expect(flow.busy.get()).toBe(false)
  })
})
