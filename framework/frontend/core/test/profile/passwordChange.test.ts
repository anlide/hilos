import { describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import {
  createHilosProfilePasswordChangeActions,
  createHilosProfilePasswordChangeFlow,
} from '../../src/profile/passwordChange.js'
import {
  bindProfileFlows,
  profileFlowsSchema,
  SIGNAL_PROFILE_FLOWS,
} from '../../src/profile/profileFlows.js'
import {
  bindCodeSendProgress,
  SIGNAL_CODE_SEND_PROGRESS,
} from '../../src/auth/authSendProgress.js'

const OPENING = { channel: 'email', destination: 'me@example.test' }
const SKIP = { required: false, purpose: 'change your password' }
const ASK = {
  required: true,
  purpose: 'change your password',
  method: 'password',
}
const SEND_REPLY = {
  sent: true,
  resendAt: 1_900_000_000_000,
  expiresAt: 1_900_000_600_000,
}
const HELD_REPLY = { sent: false, resendAt: 1_900_000_000_000, expiresAt: null }
/** The session's record of the password change at one step. */
function record(step: string) {
  return {
    operation: 'change_password',
    step,
    address: 'me@example.test',
    target: null,
  }
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

/** Bind the session's send line to a fake connection and return what tells it a frame. */
function boundLine(): (line: Record<string, unknown>) => void {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  bindCodeSendProgress({
    on: (_event: string, listener: (signal: never) => void) => {
      listeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )

      return () => undefined
    },
  } as unknown as HilosConnection)

  return (line) => {
    for (const listener of listeners) {
      listener({ type: SIGNAL_CODE_SEND_PROGRESS, data: line })
    }
  }
}

const showLine = boundLine()

/** The password window's line of one send, as the session holder publishes it. */
function passwordLine(ticket: string, state: string) {
  return {
    state,
    channel: 'email',
    purpose: 'change_password',
    ticket,
  }
}

/** The list the server tells the session when a step lands, before it answers. */
const FRAME_AFTER: Record<string, unknown[]> = {
  profile_change_password_code_request: [record('code_sent')],
  profile_change_password_code_confirm: [record('code_proven')],
  profile_change_password: [],
  hilos_profile_flow_cancel: [],
}

function setup(extra: Record<string, unknown> = {}) {
  tell([])
  const answers: Record<string, unknown> = {
    hilos_step_up_start: SKIP,
    profile_change_password_open: OPENING,
    profile_change_password_code_request: SEND_REPLY,
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
      if (typeof answer === 'string')
        return { done: Promise.reject(new ActionError(name, 'fail', answer)) }
      const frame =
        name === 'profile_change_password_code_request' &&
        (answer as { expiresAt?: number | null }).expiresAt === null
          ? undefined
          : FRAME_AFTER[name]
      if (frame !== undefined) tell(frame)
      return {
        done: Promise.resolve({
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

/** Let every answer already on its way land. */
function settled(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

describe('password change', () => {
  it('keeps a locally held code step and clears the field before requesting again', async () => {
    const { flow, dispatch } = setup({
      profile_change_password_code_request: HELD_REPLY,
    })
    await flow.open()
    await flow.sendCode()
    expect(flow.step.get()).toBe('code')
    tell([])
    expect(flow.step.get()).toBe('code')

    flow.code.set('123456')
    await flow.sendAgain()
    expect(flow.code.get()).toBe('')
    expect(
      dispatch.mock.calls.filter(
        ([name]) => name === 'profile_change_password_code_request',
      ),
    ).toHaveLength(2)
  })

  it('hides the line of an earlier window until its own send is told', async () => {
    showLine(passwordLine('earlier-send', 'sent'))
    const { flow } = setup()
    await flow.open()

    await flow.sendCode()

    // The answer can land before the line of the new send does; until it does,
    // the window must not draw the green line of the earlier window's code.
    expect(flow.step.get()).toBe('code')
    expect(flow.sendProgress.get()).toBeNull()
    showLine(passwordLine('this-send', 'queued'))
    expect(flow.sendProgress.get()?.ticket).toBe('this-send')
    showLine({ state: null })
  })

  it('lets a record that exists close the window even after a held repeat', async () => {
    const { flow } = setup({
      profile_change_password_code_request: HELD_REPLY,
    })
    await flow.open()
    await flow.sendCode()
    tell([record('code_sent')])

    await flow.sendAgain()
    expect(flow.step.get()).toBe('code')
    // Discarded in another tab: the record the window stood on is gone.
    tell([])

    expect(flow.step.get()).toBe('closed')
  })

  it('carries exactly the four action payloads', async () => {
    const world = setup()
    const actions = createHilosProfilePasswordChangeActions(world)
    await actions.open().done
    await actions.requestCode().done
    await actions.confirmCode('123456').done
    await actions.change(' secret ', false).done
    expect(
      world.dispatch.mock.calls.map(([name, payload]) => [name, payload]),
    ).toEqual([
      ['profile_change_password_open', {}],
      ['profile_change_password_code_request', {}],
      ['profile_change_password_code_confirm', { code: '123456' }],
      // The save carries no code: the session keeps the proof of it.
      [
        'profile_change_password',
        { newPassword: ' secret ', signOutOthers: false },
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
  it('goes straight to password without an address and sends no code', async () => {
    const { flow, dispatch } = setup({
      profile_change_password_open: { channel: null, destination: null },
    })
    await flow.open()
    expect(flow.step.get()).toBe('password')
    flow.code.set('not an address proof')
    flow.newPassword.set('new-secret')
    await flow.save()
    expect(dispatch).toHaveBeenLastCalledWith('profile_change_password', {
      newPassword: 'new-secret',
      signOutOthers: true,
    })
  })
  it('opens on the step the session already reached', async () => {
    const { flow } = setup()
    tell([record('code_proven')])
    await flow.open()
    expect(flow.step.get()).toBe('password')
    expect(flow.asksBeforeClosing.get()).toBe(true)
  })
  it('moves the open window when another tab moves the flow', async () => {
    const { flow } = setup()
    await flow.open()
    expect(flow.step.get()).toBe('start')
    tell([record('code_sent')])
    expect(flow.step.get()).toBe('code')
    flow.code.set('12')
    tell([record('code_proven')])
    expect(flow.step.get()).toBe('password')
    expect(flow.newPassword.get()).toBe('')
    tell([])
    // Saved or discarded in the other tab: nothing is left for this window.
    expect(flow.step.get()).toBe('closed')
  })
  it('follows no flow when a code has nowhere to go', async () => {
    const { flow } = setup({
      profile_change_password_open: { channel: null, destination: null },
    })
    await flow.open()
    tell([record('code_sent')])
    tell([])
    expect(flow.step.get()).toBe('password')
  })
  it('discards the flow for the whole session and leaves it alone on leaving', async () => {
    const { flow, dispatch } = setup()
    await flow.open()
    await flow.sendCode()
    flow.close()
    await settled()
    expect(dispatch).toHaveBeenLastCalledWith('hilos_profile_flow_cancel', {
      operation: 'change_password',
    })
    expect(flow.step.get()).toBe('closed')

    tell([record('code_sent')])
    await flow.open()
    expect(flow.step.get()).toBe('code')
    const sent = dispatch.mock.calls.length
    flow.dispose()
    expect(flow.step.get()).toBe('closed')
    expect(dispatch.mock.calls).toHaveLength(sent)
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
    'ignores a late %s reply after the window is let go',
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
      flow.dispose()
      resolve({ reply: at === 'open' ? SKIP : at === 'send' ? SEND_REPLY : [] })
      await pending
      expect(flow.step.get()).toBe('closed')
      expect(flow.opening.get()).toBeNull()
      expect(flow.newPassword.get()).toBe('')
      expect(flow.refusal.get()).toBeNull()
    },
  )
})
