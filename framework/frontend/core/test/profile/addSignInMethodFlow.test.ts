import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  PROFILE_FLOW_STEP_EMAIL_SENT,
  PROFILE_FLOW_STEP_PHONE_SENT,
  bindProfileFlows,
  profileFlowsSchema,
  SIGNAL_PROFILE_FLOWS,
} from '../../src/profile/profileFlows.js'
import {
  CODE_SEND_STATE_SENT,
  CODE_SEND_STATE_QUEUED,
  bindCodeSendProgress,
  SIGNAL_CODE_SEND_PROGRESS,
} from '../../src/auth/authSendProgress.js'
import {
  ADD_SIGN_IN_METHOD_OPERATION,
  createHilosProfileAddSignInFlow,
} from '../../src/profile/addSignInMethodFlow.js'
import { type HilosAuthContext } from '../../src/auth/authContext.js'
import { type OAuthTripOutcome } from '../../src/auth/oauthLogin.js'
import { type HilosStepUpOpening } from '../../src/auth/stepUp.js'
import { ActionError } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { createSignal } from '../../src/state/signal.js'
import { resolveHilosProfileSignInMethods } from '../../src/profile/profileSignInMethods.js'

/** Bind the held session list and return a sender for its frames. */
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
function record(step: string, address: string) {
  return { operation: 'add_sign_in_method', step, address, target: address }
}

const doubles = vi.hoisted(() => ({
  passkey: vi.fn(),
  start: vi.fn(),
  trip: null as ((outcome: OAuthTripOutcome) => void) | null,
}))
vi.mock('../../src/auth/passkeyCeremony.js', () => ({
  createPasskeyCeremony: () => ({ runPasskeyRegister: doubles.passkey }),
}))
vi.mock('../../src/auth/oauthLogin.js', () => ({
  createOAuthLogin: () => ({
    startOAuthLink: doubles.start,
    subscribeOAuthOutcome: (listener: (outcome: OAuthTripOutcome) => void) => {
      doubles.trip = listener
      return () => {
        doubles.trip = null
      }
    },
  }),
  describeOAuthError: (error: Error) => error.message,
}))
afterEach(() => {
  tell([])
  vi.resetAllMocks()
  doubles.trip = null
})

const STEP_UP_START = 'hilos_step_up_start'
const SEND_REPLY = {
  sent: true,
  resendAt: 1_900_000_000_000,
  expiresAt: 1_900_000_600_000,
}
const NO_STEP: HilosStepUpOpening = {
  required: false,
  purpose: 'add a way to sign in',
}
const PASSWORD_STEP: HilosStepUpOpening = {
  required: true,
  purpose: 'add a way to sign in',
  method: 'password',
}

function setup(verifiedEmail = false, opening: HilosStepUpOpening = NO_STEP) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatch = vi.fn(
    (
      action: string,
      payload?: { phone?: string; email?: string },
    ): { done: Promise<{ reply?: unknown }> } => {
      if (action === 'profile_add_sms_request' && payload?.phone)
        tell([record('phone_sent', payload.phone)])
      if (action === 'profile_add_password_request' && payload?.email)
        tell([record('email_sent', payload.email)])
      return {
        done: Promise.resolve(
          action === STEP_UP_START
            ? { reply: opening }
            : action === 'profile_add_sms_request' ||
                action === 'profile_add_password_request'
              ? { reply: SEND_REPLY }
              : {},
        ),
      }
    },
  )
  const context = {
    connection: {
      on: (_: string, listener: (signal: ProjectSignal) => void) => {
        listeners.add(listener)
        return () => listeners.delete(listener)
      },
    },
    actions: { dispatch },
    scopes: new ScopeManager(),
    channels: [],
  } as unknown as HilosAuthContext
  const methods = createSignal(
    resolveHilosProfileSignInMethods(
      verifiedEmail
        ? [
            {
              id: 1,
              type: 'magic_link',
              identifier: 'a@example.test',
              verified: true,
              provider: null,
            },
          ]
        : [],
      [],
    ),
  )
  const flow = createHilosProfileAddSignInFlow(context, methods)
  return {
    flow,
    dispatch,
    listeners,
    passwordUpdated: () => {
      for (const listener of listeners)
        listener({
          kind: 'project',
          type: 'profile_password_updated',
          data: { mode: 'added' },
        } as ProjectSignal)
    },
  }
}

/** Feed the session's send line through its normal signal binding. */
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
  return (data) => {
    for (const listener of listeners)
      listener({ type: SIGNAL_CODE_SEND_PROGRESS, data })
  }
}

const showLine = boundLine()

/** Complete or interrupt one action independently of the session frames. */
function pendingSend() {
  let answer!: (value: { reply: unknown }) => void
  let reject!: (error: ActionError) => void
  const done = new Promise<{ reply: unknown }>((resolve, fail) => {
    answer = resolve
    reject = fail
  })
  return { done, answer, reject }
}

describe('add sign-in method flow', () => {
  it('asks the server about the confirmation first and opens at the chooser when none is needed', async () => {
    const world = setup()
    const opening = world.flow.open()
    expect(world.flow.step.get()).toBe('opening')
    expect(world.flow.busy.get()).toBe(true)
    await opening
    expect(world.dispatch).toHaveBeenCalledWith(
      STEP_UP_START,
      { operation: 'add_sign_in_method' },
      expect.anything(),
    )
    expect(world.flow.step.get()).toBe('choose')
    expect(world.flow.busy.get()).toBe(false)
  })
  it('opens on a phone or email code step already held by this session', async () => {
    tell([record('phone_sent', '+15551234567')])
    const phone = setup()
    await phone.flow.open()
    expect(phone.flow.step.get()).toBe('phone-code')
    expect(phone.flow.phone.get()).toBe('+15551234567')
    expect(phone.flow.asksBeforeClosing.get()).toBe(true)
    phone.flow.dispose()

    tell([record('email_sent', 'a@example.test')])
    const password = setup()
    await password.flow.open()
    expect(password.flow.step.get()).toBe('password-code')
    expect(password.flow.email.get()).toBe('a@example.test')
    password.flow.dispose()
  })
  it('uses the session step for the same named way and keeps another named way at its first step', async () => {
    tell([record('phone_sent', '+15551234567')])
    const phone = setup()
    await phone.flow.open({ kind: 'phone' })
    expect(phone.flow.step.get()).toBe('phone-code')
    phone.flow.dispose()

    const password = setup()
    await password.flow.open({ kind: 'password' })
    expect(password.flow.step.get()).toBe('password-email')
    password.flow.dispose()
  })
  it('opens at the confirmation step, holds the chooser back until it is confirmed, and keeps a refused proof there', async () => {
    const world = setup(false, PASSWORD_STEP)
    await world.flow.open()
    expect(world.flow.step.get()).toBe('step-up')
    world.flow.choosePassword()
    world.flow.choosePhone()
    world.flow.back()
    expect(world.flow.step.get()).toBe('step-up')
    world.dispatch.mockReturnValueOnce({
      done: Promise.reject(
        new ActionError('step-up', 'fail', 'Incorrect password'),
      ),
    })
    world.flow.stepUp.password.set('wrong')
    await world.flow.confirmStepUp()
    expect(world.flow.step.get()).toBe('step-up')
    expect(world.flow.stepUp.refusal.get()).toBe('Incorrect password')
    world.flow.stepUp.password.set('right')
    await world.flow.confirmStepUp()
    expect(world.dispatch).toHaveBeenLastCalledWith('hilos_step_up_confirm', {
      operation: 'add_sign_in_method',
      method: 'password',
      code: '',
      backupCode: false,
      password: 'right',
      passkey: null,
    })
    expect(world.flow.step.get()).toBe('choose')
  })
  it('shows a refused opening as a line and stays closed to a late answer after closing', async () => {
    const world = setup()
    world.dispatch.mockReturnValueOnce({
      done: Promise.reject(
        new ActionError('step-up', 'fail', 'Too many codes'),
      ),
    })
    await world.flow.open()
    expect(world.flow.step.get()).toBe('refused')
    expect(world.flow.refusal.get()).toBe('Too many codes')
    world.flow.close()
    let answer!: (reply: { reply: HilosStepUpOpening }) => void
    world.dispatch.mockReturnValueOnce({
      done: new Promise((resolve) => {
        answer = resolve
      }),
    })
    const pending = world.flow.open()
    world.flow.close()
    answer({ reply: NO_STEP })
    await pending
    expect(world.flow.step.get()).toBe('closed')
    expect(world.flow.busy.get()).toBe(false)
  })
  it('proves an email, retains its address for the code, and closes only on the password frame', async () => {
    const world = setup()
    await world.flow.open()
    world.flow.choosePassword()
    expect(world.flow.step.get()).toBe('password-email')
    await world.flow.submitPasswordEmail('a@example.test')
    expect(world.flow.step.get()).toBe('password-code')
    await world.flow.submitPasswordCode('123456', 'new-secret')
    expect(world.dispatch).toHaveBeenLastCalledWith(
      'profile_add_password_confirm',
      { code: '123456', newPassword: 'new-secret' },
    )
    expect(world.flow.busy.get()).toBe(true)
    expect(world.flow.step.get()).toBe('password-code')
    world.passwordUpdated()
    expect(world.flow.step.get()).toBe('closed')
    expect(world.flow.busy.get()).toBe(false)
    expect(
      world.dispatch.mock.calls.some(
        ([action]) => action === 'hilos_profile_flow_cancel',
      ),
    ).toBe(false)
    world.flow.dispose()
    expect(world.listeners.size).toBe(0)
  })
  it('adds directly to a confirmed address and suppresses a duplicate while waiting for the frame', async () => {
    const world = setup(true)
    await world.flow.open()
    world.flow.choosePassword()
    expect(world.flow.step.get()).toBe('password-new')
    await world.flow.submitPasswordNew('new-secret')
    await world.flow.submitPasswordNew('other-secret')
    expect(world.dispatch).toHaveBeenCalledTimes(2)
    expect(world.dispatch).toHaveBeenCalledWith('profile_set_password', {
      newPassword: 'new-secret',
    })
    world.passwordUpdated()
    expect(world.flow.step.get()).toBe('closed')
  })
  it('keeps a refused code on the same step and allows a retry', async () => {
    const world = setup()
    await world.flow.open()
    world.flow.choosePhone()
    await world.flow.submitPhone('+15551234567')
    world.dispatch.mockReturnValueOnce({
      done: Promise.reject(new ActionError('phone', 'fail', 'Wrong code')),
    })
    await world.flow.submitPhoneCode('bad')
    expect(world.flow.step.get()).toBe('phone-code')
    expect(world.flow.refusal.get()).toBe('Wrong code')
    expect(world.flow.phone.get()).toBe('+15551234567')
    await world.flow.submitPhoneCode('123456')
    expect(world.flow.step.get()).toBe('closed')
  })
  it('stays on entry when a send has no session code step', async () => {
    const phone = setup()
    await phone.flow.open()
    phone.flow.choosePhone()
    phone.dispatch.mockReturnValueOnce({
      done: Promise.resolve({ reply: SEND_REPLY }),
    })
    await phone.flow.submitPhone('+15551234567')
    expect(phone.flow.step.get()).toBe('phone-number')
    phone.flow.dispose()

    const password = setup()
    await password.flow.open()
    password.flow.choosePassword()
    password.dispatch.mockReturnValueOnce({
      done: Promise.resolve({ reply: SEND_REPLY }),
    })
    await password.flow.submitPasswordEmail('a@example.test')
    expect(password.flow.step.get()).toBe('password-email')
    password.flow.dispose()
  })
  it('follows changed destinations only on a code step and closes when another tab finishes', async () => {
    const world = setup()
    await world.flow.open()
    tell([record('phone_sent', '+15551234567')])
    expect(world.flow.step.get()).toBe('choose')
    world.flow.choosePhone()
    tell([record('email_sent', 'a@example.test')])
    expect(world.flow.step.get()).toBe('phone-number')
    await world.flow.submitPhone('+15551234567')
    expect(world.flow.step.get()).toBe('phone-code')
    world.dispatch.mockReturnValueOnce({
      done: Promise.reject(new ActionError('phone', 'fail', 'Wrong code')),
    })
    await world.flow.submitPhoneCode('bad')
    tell([record('phone_sent', '+15559876543')])
    expect(world.flow.phone.get()).toBe('+15559876543')
    expect(world.flow.refusal.get()).toBeNull()
    tell([record('email_sent', 'b@example.test')])
    expect(world.flow.step.get()).toBe('password-code')
    expect(world.flow.email.get()).toBe('b@example.test')
    tell([])
    expect(world.flow.step.get()).toBe('closed')
  })
  it('keeps an own pending confirmation open while its record disappears', async () => {
    const world = setup()
    await world.flow.open()
    world.flow.choosePhone()
    await world.flow.submitPhone('+15551234567')
    let answer!: (value: object) => void
    world.dispatch.mockReturnValueOnce({
      done: new Promise((resolve) => {
        answer = resolve
      }),
    })
    const pending = world.flow.submitPhoneCode('123456')
    tell([])
    expect(world.flow.step.get()).toBe('phone-code')
    answer({})
    await pending
    expect(world.flow.step.get()).toBe('closed')
  })
  it('discards a held code step on close, reports a refusal, and never cancels on dispose or Back', async () => {
    const world = setup()
    await world.flow.open()
    world.flow.choosePhone()
    await world.flow.submitPhone('+15551234567')
    expect(world.flow.asksBeforeClosing.get()).toBe(true)
    world.flow.back()
    expect(world.flow.asksBeforeClosing.get()).toBe(false)
    expect(world.flow.step.get()).toBe('phone-number')
    world.flow.dispose()
    expect(
      world.dispatch.mock.calls.some(
        ([action]) => action === 'hilos_profile_flow_cancel',
      ),
    ).toBe(false)

    await world.flow.open()
    expect(world.flow.step.get()).toBe('phone-code')
    world.dispatch.mockReturnValueOnce({
      done: Promise.reject(new ActionError('cancel', 'fail', 'Cannot discard')),
    })
    world.flow.close()
    expect(world.flow.busy.get()).toBe(true)
    await vi.waitFor(() =>
      expect(world.flow.refusal.get()).toBe('Cannot discard'),
    )
    expect(world.flow.step.get()).toBe('phone-code')
    world.flow.close()
    await vi.waitFor(() => expect(world.flow.step.get()).toBe('closed'))
    expect(world.dispatch).toHaveBeenLastCalledWith(
      'hilos_profile_flow_cancel',
      {
        operation: 'add_sign_in_method',
      },
    )
  })

  it('orders another phone code to the same number on the code step', async () => {
    const world = setup()
    await world.flow.open()
    world.flow.choosePhone()
    await world.flow.submitPhone('+15551234567')
    await world.flow.sendAgain()

    expect(world.flow.step.get()).toBe('phone-code')
    expect(
      world.dispatch.mock.calls.filter(
        ([name]) => name === 'profile_add_sms_request',
      ),
    ).toHaveLength(2)
    expect(world.dispatch).toHaveBeenLastCalledWith(
      'profile_add_sms_request',
      { phone: '+15551234567' },
      expect.anything(),
    )
  })
  it('does not apply a late rejection to a reopened dialog', async () => {
    const world = setup()
    await world.flow.open()
    let reject!: (error: Error) => void
    world.dispatch.mockReturnValueOnce({
      done: new Promise((_, fail) => {
        reject = fail
      }),
    })
    world.flow.choosePhone()
    const pending = world.flow.submitPhone('+15551234567')
    world.flow.close()
    const reopened = world.flow.open()
    reject(new ActionError('phone', 'fail', 'Too late'))
    await pending
    await reopened
    expect(world.flow.step.get()).toBe('choose')
    expect(world.flow.refusal.get()).toBeNull()
    expect(world.flow.busy.get()).toBe(false)
  })
  it('leaves a canceled device ceremony in the chooser and ignores success after closing', async () => {
    const world = setup()
    await world.flow.open()
    doubles.passkey.mockResolvedValueOnce({
      ok: false,
      message: 'Canceled on device',
    })
    await world.flow.choosePasskey()
    expect(world.flow.step.get()).toBe('choose')
    expect(world.flow.refusal.get()).toBe('Canceled on device')
    let finish!: (outcome: { ok: boolean }) => void
    doubles.passkey.mockReturnValueOnce(
      new Promise((resolve) => {
        finish = resolve
      }),
    )
    const pending = world.flow.choosePasskey()
    world.flow.close()
    const reopened = world.flow.open()
    finish({ ok: true })
    await pending
    await reopened
    expect(world.flow.step.get()).toBe('choose')
  })
  it('keeps provider errors in the chooser and closes on a completed link', async () => {
    const world = setup()
    await world.flow.open()
    doubles.start.mockRejectedValueOnce(new Error('Window blocked'))
    await world.flow.chooseProvider('oauth:github')
    expect(world.flow.refusal.get()).toBe('Window blocked')
    await world.flow.chooseProvider('oauth:github')
    expect(world.flow.busy.get()).toBe(true)
    doubles.trip?.({ kind: 'error', message: 'Provider refused' })
    expect(world.flow.refusal.get()).toBe('Provider refused')
    await world.flow.chooseProvider('oauth:github')
    doubles.trip?.({ kind: 'linked', message: '' })
    expect(world.flow.step.get()).toBe('closed')
  })
  it('opens for one way straight at its first step when no confirmation is needed, and Back returns to the chooser', async () => {
    const world = setup()
    await world.flow.open({ kind: 'password' })
    expect(world.flow.step.get()).toBe('password-email')
    world.flow.back()
    expect(world.flow.step.get()).toBe('choose')
    world.flow.close()
    await world.flow.open({ kind: 'phone' })
    expect(world.flow.step.get()).toBe('phone-number')
  })
  it('opens for one way at the confirmation step and enters the way once it is confirmed', async () => {
    const world = setup(false, PASSWORD_STEP)
    await world.flow.open({
      kind: 'provider',
      key: 'oauth:github',
      label: 'Continue with GitHub',
      name: 'GitHub',
    })
    expect(world.flow.step.get()).toBe('step-up')
    expect(doubles.start).not.toHaveBeenCalled()
    world.flow.stepUp.password.set('right')
    await world.flow.confirmStepUp()
    expect(world.flow.step.get()).toBe('choose')
    expect(world.flow.provider.get()).toBe('oauth:github')
    expect(doubles.start).toHaveBeenCalledWith(
      'oauth:github',
      expect.anything(),
    )
  })
  it('keeps a refused opening for one way at the refusal and forgets the way on close', async () => {
    const world = setup(false, PASSWORD_STEP)
    world.dispatch.mockReturnValueOnce({
      done: Promise.reject(
        new ActionError('step-up', 'fail', 'Too many codes'),
      ),
    })
    await world.flow.open({ kind: 'phone' })
    expect(world.flow.step.get()).toBe('refused')
    world.flow.close()
    await world.flow.open({ kind: 'phone' })
    world.flow.close()
    await world.flow.open()
    world.flow.stepUp.password.set('right')
    await world.flow.confirmStepUp()
    expect(world.flow.step.get()).toBe('choose')
  })
})

afterEach(() => showLine({ state: null, message: null }))

describe.each(['email', 'phone'])('send-progress visibility: %s', (mode) => {
  function line(
    ticket: string,
    state = CODE_SEND_STATE_SENT,
    purpose = ADD_SIGN_IN_METHOD_OPERATION,
  ) {
    return { ticket, state, purpose, channel: 'email', message: null }
  }

  async function openWindow() {
    const world = setup()
    await world.flow.open({ kind: mode === 'email' ? 'password' : 'phone' })
    const action =
      mode === 'email'
        ? 'profile_add_password_request'
        : 'profile_add_sms_request'
    const send = () =>
      mode === 'email'
        ? world.flow.submitPasswordEmail('new@example.test')
        : world.flow.submitPhone('+15551234567')
    const tellStep = () =>
      tell([
        record(
          mode === 'email'
            ? PROFILE_FLOW_STEP_EMAIL_SENT
            : PROFILE_FLOW_STEP_PHONE_SENT,
          mode === 'email' ? 'new@example.test' : '+15551234567',
        ),
      ])
    const repeat = () => world.flow.sendAgain()
    const queue = (request: ReturnType<typeof pendingSend>) => {
      world.dispatch.mockImplementationOnce(() => request)
    }
    const dispose = () => world.flow.dispose()
    const rejectOther = () => {
      world.dispatch.mockImplementationOnce(() => ({
        done: Promise.reject(
          new ActionError('confirmation', 'fail', 'Wrong code'),
        ),
      }))

      return mode === 'email'
        ? world.flow.submitPasswordCode('123456', 'secret')
        : world.flow.submitPhoneCode('123456')
    }
    const reopen = async () => {
      world.flow.dispose()
      await world.flow.open({ kind: mode === 'email' ? 'password' : 'phone' })
    }
    return {
      flow: world.flow,
      action,
      send,
      tellStep,
      repeat,
      queue,
      dispose,
      rejectOther,
      reopen,
    }
  }

  it.each([true, false])(
    'shows each new ticket before the step frame and successful reply with sent=%s',
    async (sent) => {
      const { flow, send, tellStep, repeat, queue, dispose } =
        await openWindow()
      showLine(line('old'))
      for (const [ticket, submit] of [
        ['first', send],
        ['second', repeat],
      ] as const) {
        const request = pendingSend()
        queue(request)
        const sending = submit()
        expect(flow.sendProgress.get()).toBeNull()
        showLine(line(ticket, CODE_SEND_STATE_QUEUED))
        expect(flow.sendProgress.get()).toMatchObject(
          line(ticket, CODE_SEND_STATE_QUEUED),
        )
        tellStep()
        expect(flow.sendProgress.get()).toMatchObject(
          line(ticket, CODE_SEND_STATE_QUEUED),
        )
        request.answer({ reply: { ...SEND_REPLY, sent } })
        await sending
        expect(flow.sendProgress.get()).toMatchObject(
          line(ticket, CODE_SEND_STATE_QUEUED),
        )
      }
      dispose()
    },
  )

  it.each([true, false])(
    'waits for a new ticket across lines, steps and a successful reply with sent=%s',
    async (sent) => {
      const { flow, send, tellStep, repeat, queue, dispose } =
        await openWindow()
      showLine(line('old'))
      const first = pendingSend()
      queue(first)
      const sending = send()
      expect(flow.sendProgress.get()).toBeNull()
      showLine(line('old'))
      tellStep()
      expect(flow.sendProgress.get()).toBeNull()
      showLine(line('first', CODE_SEND_STATE_QUEUED))
      expect(flow.sendProgress.get()).toMatchObject(
        line('first', CODE_SEND_STATE_QUEUED),
      )
      tellStep()
      first.answer({ reply: { ...SEND_REPLY, sent } })
      await sending
      expect(flow.sendProgress.get()?.ticket).toBe('first')

      const second = pendingSend()
      queue(second)
      const repeating = repeat()
      second.answer({ reply: { ...SEND_REPLY, sent } })
      await repeating
      showLine(line('first'))
      tellStep()
      expect(flow.sendProgress.get()).toBeNull()
      showLine(line('second', CODE_SEND_STATE_QUEUED))
      expect(flow.sendProgress.get()).toMatchObject(
        line('second', CODE_SEND_STATE_QUEUED),
      )
      dispose()
    },
  )

  it.each(['fail', 'disconnected'] as const)(
    'restores the current session line after %s on first send and repeat',
    async (outcome) => {
      for (const attempt of ['first', 'repeat']) {
        for (const current of ['old', 'new', 'removed', 'other']) {
          const { flow, action, send, tellStep, repeat, queue, dispose } =
            await openWindow()
          if (attempt === 'repeat') {
            const initial = pendingSend()
            queue(initial)
            const sending = send()
            showLine(line('initial', CODE_SEND_STATE_QUEUED))
            tellStep()
            initial.answer({ reply: SEND_REPLY })
            await sending
          }
          showLine(line('old'))
          const request = pendingSend()
          queue(request)
          const sending = attempt === 'first' ? send() : repeat()
          expect(flow.sendProgress.get()).toBeNull()
          showLine(line('old'))
          expect(flow.sendProgress.get()).toBeNull()
          if (current === 'new') showLine(line('new', CODE_SEND_STATE_QUEUED))
          if (current === 'removed') showLine({ state: null, message: null })
          if (current === 'other')
            showLine(line('foreign', CODE_SEND_STATE_SENT, 'another_operation'))
          request.reject(new ActionError(action, outcome, 'Send refused'))
          await sending
          expect(flow.busy.get()).toBe(false)
          expect(flow.refusal.get()).not.toBeNull()
          if (current === 'old')
            expect(flow.sendProgress.get()).toMatchObject(line('old'))
          else if (current === 'new')
            expect(flow.sendProgress.get()).toMatchObject(
              line('new', CODE_SEND_STATE_QUEUED),
            )
          else expect(flow.sendProgress.get()).toBeNull()
          if (outcome === 'disconnected') {
            showLine(line('old'))
            expect(flow.sendProgress.get()).toMatchObject(line('old'))
            showLine(line('reconnected', CODE_SEND_STATE_QUEUED))
            expect(flow.sendProgress.get()).toMatchObject(
              line('reconnected', CODE_SEND_STATE_QUEUED),
            )
          }
          dispose()
          showLine({ state: null, message: null })
          tell([])
        }
      }
    },
  )
  it('keeps the old ticket hidden when confirmation fails after a successful send', async () => {
    const { flow, send, tellStep, queue, dispose, rejectOther } =
      await openWindow()
    showLine(line('old'))
    const request = pendingSend()
    queue(request)
    const sending = send()
    tellStep()
    request.answer({ reply: SEND_REPLY })
    await sending
    expect(flow.sendProgress.get()).toBeNull()
    await rejectOther()
    expect(flow.refusal.get()).not.toBeNull()
    expect(flow.sendProgress.get()).toBeNull()
    dispose()
  })

  it('ignores an interrupted send from an earlier opening', async () => {
    const { flow, action, send, queue, dispose, reopen } = await openWindow()
    showLine(line('old'))
    const earlier = pendingSend()
    queue(earlier)
    const sending = send()
    await reopen()
    const current = pendingSend()
    queue(current)
    const sendingNow = send()
    earlier.reject(new ActionError(action, 'disconnected', 'Disconnected'))
    await sending
    expect(flow.busy.get()).toBe(true)
    expect(flow.refusal.get()).toBeNull()
    expect(flow.sendProgress.get()).toBeNull()
    current.reject(new ActionError(action, 'fail', 'Send refused'))
    await sendingNow
    expect(flow.sendProgress.get()).toMatchObject(line('old'))
    dispose()
  })
})
