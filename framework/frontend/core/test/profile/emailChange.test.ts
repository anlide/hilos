import { describe, expect, it, vi } from 'vitest'

import {
  ActionError,
  bindProfileFlows,
  createHilosProfileEmailChangeActions,
  createHilosProfileEmailChangeFlow,
  profileFlowsSchema,
  SIGNAL_PROFILE_FLOWS,
  type ActionHandle,
  type ActionLifecycle,
  type HilosConnection,
} from '../../src/index.js'

const SKIP = { required: false, purpose: 'change your email' }
const ASK = { required: true, purpose: 'change your email', method: 'password' }

/** The session's record of the email change at one step. */
function record(step: string, target: string | null = null) {
  return { operation: 'change_email', step, address: 'old@example.com', target }
}

/** Bind the held list to a fake connection and return what tells it a frame. */
function bound(): (flows: unknown[]) => void {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  bindProfileFlows({
    on: (_event: string, listener: (signal: never) => void) => {
      listeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )

      return () => undefined
    },
  } as unknown as HilosConnection)

  return (flows) => {
    for (const listener of listeners) {
      listener({
        type: SIGNAL_PROFILE_FLOWS,
        data: profileFlowsSchema.parse({ flows }),
      })
    }
  }
}

const tell = bound()

/**
 * The list the server tells every tab of the session when a step lands - before
 * it answers the tab that submitted, as the session holder does.
 */
function frameAfter(name: string, payload: unknown): unknown[] | undefined {
  switch (name) {
    case 'profile_change_email_current_request':
      return [record('current_sent')]
    case 'profile_change_email_current_confirm':
      return [record('current_proven')]
    case 'profile_change_email_new_request':
      return [
        record(
          'new_sent',
          String((payload as { email: string }).email)
            .trim()
            .toLowerCase(),
        ),
      ]
    case 'profile_change_email_new_confirm':
    case 'hilos_profile_flow_cancel':
      return []
    default:
      return undefined
  }
}

/** A flow whose server answers each action from the table: a string refuses with it. */
function setup(extra: Record<string, unknown> = {}) {
  tell([])
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
      const answer = answers[name] ?? []
      if (answer instanceof Error) return { done: Promise.reject(answer) }
      if (answer instanceof Promise)
        return { done: answer as Promise<{ reply: unknown }> }
      if (typeof answer === 'string')
        return { done: Promise.reject(new ActionError(name, 'fail', answer)) }
      const frame = frameAfter(name, payload)
      if (frame !== undefined) tell(frame)
      return {
        done: Promise.resolve({
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

/** The step actions a flow sent, past the confirmation, as name and payload. */
function sentSteps(dispatch: ReturnType<typeof setup>['dispatch']) {
  return dispatch.mock.calls
    .filter(([name]) => name !== 'hilos_step_up_start')
    .map(([name, payload]) => [name, payload])
}

/** Let every answer already on its way land. */
function settled(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
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
    expect(emailChange.requestNewCode('new@example.com')).toBe(handle)
    expect(emailChange.confirmNewCode('222222')).toBe(handle)
    // The current address's code is never sent again: the session keeps the proof.
    expect(sent).toEqual([
      { action: 'profile_change_email_current_request', payload: {} },
      {
        action: 'profile_change_email_current_confirm',
        payload: { code: '111111' },
      },
      {
        action: 'profile_change_email_new_request',
        payload: { email: 'new@example.com' },
      },
      {
        action: 'profile_change_email_new_confirm',
        payload: { code: '222222' },
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
    // Step 4 names the address the session's record holds, as the server stored it.
    expect(flow.newEmail.get()).toBe('new@example.com')
    flow.newCode.set('222222')
    await flow.submit()
    expect(flow.step.get()).toBe('done')
    expect(flow.now.get()).toBe('new@example.com')
    expect(flow.asksBeforeClosing.get()).toBe(false)
    expect(sentSteps(dispatch)).toEqual([
      ['profile_change_email_current_request', {}],
      ['profile_change_email_current_confirm', { code: '111111' }],
      ['profile_change_email_new_request', { email: 'New@Example.com' }],
      ['profile_change_email_new_confirm', { code: '222222' }],
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

  it('opens on the step the session already reached, after the confirmation', async () => {
    const { flow } = setup({ hilos_step_up_start: ASK })
    tell([record('current_proven')])
    await flow.open('old@example.com')
    // The confirmation still comes first; the step reached waits behind it.
    expect(flow.step.get()).toBe('step-up')
    flow.stepUp.password.set('secret')
    await flow.submit()
    expect(flow.step.get()).toBe('new-address')
    expect(flow.was.get()).toBe('old@example.com')
  })

  it('opens on step 4 with the new address the session holds', async () => {
    const { flow, dispatch } = setup()
    tell([record('new_sent', 'new@example.com')])
    await flow.open('old@example.com')
    expect(flow.step.get()).toBe('confirm-new')
    expect(flow.newEmail.get()).toBe('new@example.com')
    flow.newCode.set('222222')
    await flow.submit()
    expect(flow.step.get()).toBe('done')
    expect(flow.now.get()).toBe('new@example.com')
    expect(sentSteps(dispatch)).toEqual([
      ['profile_change_email_new_confirm', { code: '222222' }],
    ])
  })

  it('moves the open window when another tab moves the flow', async () => {
    const { flow } = setup({
      profile_change_email_current_confirm: 'That code is not right',
    })
    await flow.open('old@example.com')
    tell([record('current_sent')])
    expect(flow.step.get()).toBe('confirm-current')
    flow.currentCode.set('000000')
    await flow.submit()
    expect(flow.refusal.get()).toBe('That code is not right')

    tell([record('current_proven')])

    // The step moved without this tab: its refusal goes, the new step's field is empty.
    expect(flow.step.get()).toBe('new-address')
    expect(flow.refusal.get()).toBeNull()
    expect(flow.newEmail.get()).toBe('')
  })

  it('closes when the flow ends in another tab', async () => {
    const { flow } = setup()
    tell([record('current_proven')])
    await flow.open('old@example.com')
    expect(flow.step.get()).toBe('new-address')

    tell([])

    expect(flow.step.get()).toBe('closed')
  })

  it('keeps the window open while its own last step is on the way', async () => {
    let answer: (value: { reply: unknown }) => void = () => {}
    const { flow } = setup({
      profile_change_email_new_confirm: new Promise((resolve) => {
        answer = resolve
      }),
    })
    tell([record('new_sent', 'new@example.com')])
    await flow.open('old@example.com')
    flow.newCode.set('222222')
    const submitting = flow.submit()

    // The record goes before the answer comes: it is this tab's own ending.
    tell([])
    expect(flow.step.get()).toBe('confirm-new')
    answer({ reply: [] })
    await submitting

    expect(flow.step.get()).toBe('done')
  })

  it('discards the flow for the whole session and closes on the answer', async () => {
    let answer: (value: { reply: unknown }) => void = () => {}
    const { flow, dispatch } = setup({
      hilos_profile_flow_cancel: new Promise((resolve) => {
        answer = resolve
      }),
    })
    tell([record('current_sent')])
    await flow.open('old@example.com')

    flow.close()

    expect(dispatch).toHaveBeenCalledWith('hilos_profile_flow_cancel', {
      operation: 'change_email',
    })
    expect(flow.step.get()).toBe('confirm-current')
    expect(flow.busy.get()).toBe(true)
    // The empty list of its own discard does not close the window before the answer.
    tell([])
    expect(flow.step.get()).toBe('confirm-current')
    answer({ reply: [] })
    await settled()
    expect(flow.step.get()).toBe('closed')
    expect(flow.busy.get()).toBe(false)
  })

  it('stays open with the reason when the discard is not answered', async () => {
    const { flow } = setup({
      hilos_profile_flow_cancel: new ActionError(
        'hilos_profile_flow_cancel',
        'timeout',
        'timed out',
      ),
    })
    tell([record('current_sent')])
    await flow.open('old@example.com')

    flow.close()
    await settled()

    expect(flow.step.get()).toBe('confirm-current')
    expect(flow.busy.get()).toBe(false)
    expect(flow.refusal.get()).toBe(
      'Could not reach the server. Please try again.',
    )
  })

  it('leaves the flow alone when it closes on step one or leaves the page', async () => {
    const { flow, dispatch } = setup()
    await flow.open('old@example.com')
    flow.close()
    expect(flow.step.get()).toBe('closed')

    tell([record('current_sent')])
    await flow.open('old@example.com')
    flow.dispose()

    expect(flow.step.get()).toBe('closed')
    expect(dispatch).not.toHaveBeenCalledWith(
      'hilos_profile_flow_cancel',
      expect.anything(),
    )
    // A window let go follows nothing any more.
    tell([record('current_proven')])
    expect(flow.step.get()).toBe('closed')
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
