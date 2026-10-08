import { describe, expect, it, vi } from 'vitest'

import {
  createHilosStepUpStep,
  type HilosStepUpActions,
} from '../../src/auth/stepUp.js'
import {
  bindStepUpConfirmed,
  SIGNAL_STEP_UP_CONFIRMED,
  stepUpConfirmedSchema,
} from '../../src/auth/stepUpConfirmed.js'
import { ActionError } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'

function handle(reply: unknown = {}): ReturnType<HilosStepUpActions['start']> {
  return { done: Promise.resolve({ reply }) } as ReturnType<
    HilosStepUpActions['start']
  >
}

/** A request whose answer the case gives when it chooses to. */
function pending(): {
  handle: ReturnType<HilosStepUpActions['start']>
  answer: (reply: unknown) => void
} {
  let answer: (reply: unknown) => void = () => undefined
  const done = new Promise<{ reply: unknown }>((resolve) => {
    answer = (reply) => resolve({ reply })
  })

  return {
    handle: { done } as ReturnType<HilosStepUpActions['start']>,
    answer: (reply) => answer(reply),
  }
}

/** Bind the session's confirmation frame to a fake connection and return what tells one. */
function confirmedElsewhere(): (operations: string[]) => void {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  const connection = {
    on: (_event: string, listener: (signal: never) => void) => {
      listeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )

      return () => undefined
    },
  } as unknown as HilosConnection
  bindStepUpConfirmed(connection)

  return (operations) => {
    for (const listener of listeners) {
      listener({
        type: SIGNAL_STEP_UP_CONFIRMED,
        data: stepUpConfirmedSchema.parse({ operations }),
      })
    }
  }
}

const ASK_PASSWORD = {
  required: true,
  purpose: 'change your email',
  method: 'password',
} as const

const ASK_CODE = {
  required: true,
  purpose: 'change your email',
  method: 'email_code',
  destination: 'person@example.test',
} as const

describe('createHilosStepUpStep', () => {
  it('restarts a code send without leaving the step and passes an already confirmed operation', async () => {
    const passed = vi.fn()
    const start = vi
      .fn()
      .mockReturnValueOnce(
        handle({
          required: true,
          purpose: 'change your email',
          method: 'email_code',
          destination: 'person@example.test',
          send: {
            sent: true,
            resendAt: 1_900_000_000_000,
            expiresAt: 1_900_000_600_000,
          },
        }),
      )
      .mockReturnValueOnce(
        handle({ required: false, purpose: 'change your email' }),
      )
    const step = createHilosStepUpStep(
      { start, confirm: () => handle() },
      passed,
    )

    expect(await step.open('change_email')).toBe('ask')
    step.code.set('123456')
    await step.sendAgain()

    expect(step.code.get()).toBe('')
    expect(start).toHaveBeenCalledTimes(2)
    expect(start).toHaveBeenLastCalledWith('change_email')
    expect(passed).toHaveBeenCalledOnce()
    expect(step.opening.get()?.required).toBe(false)
  })

  it('lets a repeated send answered after the step reopened write nothing over it', async () => {
    const passed = vi.fn()
    let answerRepeat: (value: { reply: unknown }) => void = () => undefined
    const start = vi
      .fn()
      .mockReturnValueOnce(
        handle({
          required: true,
          purpose: 'block this account',
          method: 'email_code',
          destination: 'first@example.test',
        }),
      )
      .mockReturnValueOnce({
        done: new Promise((resolve) => {
          answerRepeat = resolve
        }),
      })
      .mockReturnValueOnce(
        handle({
          required: true,
          purpose: 'merge these accounts',
          method: 'email_code',
          destination: 'second@example.test',
        }),
      )
    const step = createHilosStepUpStep(
      { start, confirm: () => handle() },
      passed,
    )

    await step.open('block_account')
    const repeat = step.sendAgain()
    expect(await step.open('merge_accounts')).toBe('ask')
    // The first window's repeat comes back after the step was opened again for
    // another operation, saying that operation needs no confirmation any more.
    answerRepeat({ reply: { required: false, purpose: 'block this account' } })
    await repeat

    expect(step.opening.get()?.destination).toBe('second@example.test')
    expect(step.busy.get()).toBe(false)
    expect(passed).not.toHaveBeenCalled()
  })

  it('opens on skip or ask from the server reply', async () => {
    const skip = createHilosStepUpStep({
      start: () => handle({ required: false, purpose: 'change your email' }),
      confirm: () => handle(),
    })
    expect(await skip.open('change_email')).toBe('skip')

    const ask = createHilosStepUpStep({
      start: () =>
        handle({
          required: true,
          purpose: 'change your name',
          method: 'password',
        }),
      confirm: () => handle(),
    })
    expect(await ask.open('change_name')).toBe('ask')
    expect(ask.opening.get()?.method).toBe('password')
  })

  it('keeps an opening refusal for the modal', async () => {
    const step = createHilosStepUpStep({
      start: () =>
        ({
          done: Promise.reject(
            new ActionError('hilos_step_up_start', 'fail', 'No proof'),
          ),
        }) as ReturnType<HilosStepUpActions['start']>,
      confirm: () => handle(),
    })

    expect(await step.open('change_name')).toBe('refused')
    expect(step.refusal.get()).toBe('No proof')
  })

  it('sends every proof field and leaves typed input in place on refusal', async () => {
    const confirm = vi.fn(() => handle())
    const step = createHilosStepUpStep({
      start: () =>
        handle({
          required: true,
          purpose: 'change your name',
          method: 'password',
        }),
      confirm,
    })
    await step.open('change_name')
    step.password.set('secret')

    expect(await step.confirm()).toBe(true)
    expect(confirm).toHaveBeenCalledWith('change_name', {
      method: 'password',
      code: '',
      backupCode: false,
      password: 'secret',
      passkey: null,
    })
  })

  it('does not submit when the required credential is empty and preserves refusal', async () => {
    const confirm = vi
      .fn()
      .mockImplementationOnce(() => ({
        done: Promise.reject(
          new ActionError(
            'hilos_step_up_confirm',
            'fail',
            'Incorrect password',
          ),
        ),
      }))
      .mockImplementation(() => handle())

    const step = createHilosStepUpStep({
      start: (operation) =>
        handle({
          required: true,
          purpose: 'confirm identity',
          method:
            operation === 'op_password'
              ? 'password'
              : operation === 'op_email'
                ? 'email_code'
                : 'second_factor',
        }),
      confirm: confirm as unknown as HilosStepUpActions['confirm'],
    })

    await step.open('op_password')
    step.password.set('wrong')
    expect(await step.confirm()).toBe(false)
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(step.refusal.get()).toBe('Incorrect password')

    step.password.set('')
    expect(await step.confirm()).toBe(false)
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(step.refusal.get()).toBe('Incorrect password')

    await step.open('op_email')
    expect(step.refusal.get()).toBeNull()
    step.code.set('   ')
    expect(await step.confirm()).toBe(false)
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(step.refusal.get()).toBeNull()

    await step.open('op_2fa')
    step.code.set('')
    expect(await step.confirm()).toBe(false)
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(step.refusal.get()).toBeNull()

    step.code.set('123456')
    expect(await step.confirm()).toBe(true)
    expect(confirm).toHaveBeenCalledTimes(2)
  })

  it('does not submit when the browser passkey request is refused', async () => {
    const confirm = vi.fn(() => handle())
    const step = createHilosStepUpStep({
      start: () =>
        handle({
          required: true,
          purpose: 'change your name',
          method: 'passkey',
          signedChallenge: 'signed',
          publicKeyOptions: { challenge: 'AA' },
        }),
      confirm,
    })
    await step.open('change_name')

    expect(await step.confirm()).toBe(false)
    expect(confirm).not.toHaveBeenCalled()
    expect(step.refusal.get()).toBe('The device key was not confirmed')
  })
})

describe('a confirmation step passed in another tab of the session', () => {
  it('passes the step asking for that operation, once', async () => {
    const tell = confirmedElsewhere()
    const passed = vi.fn()
    const step = createHilosStepUpStep(
      { start: () => handle(ASK_PASSWORD), confirm: () => handle() },
      passed,
    )
    expect(await step.open('change_email')).toBe('ask')
    step.password.set('typed')

    tell(['change_email', 'export_data'])
    tell(['change_email'])

    expect(passed).toHaveBeenCalledOnce()
    // Silently: what the person typed is left alone.
    expect(step.password.get()).toBe('typed')
  })

  it('leaves a step asking for another operation alone', async () => {
    const tell = confirmedElsewhere()
    const passed = vi.fn()
    const step = createHilosStepUpStep(
      { start: () => handle(ASK_PASSWORD), confirm: () => handle() },
      passed,
    )
    await step.open('delete_account')

    tell(['change_email'])

    expect(passed).not.toHaveBeenCalled()
  })

  it('does not pass on what was told before the step asked', async () => {
    const tell = confirmedElsewhere()
    const passed = vi.fn()
    const opening = pending()
    const step = createHilosStepUpStep(
      { start: () => opening.handle, confirm: () => handle() },
      passed,
    )

    tell(['change_email'])
    const outcome = step.open('change_email')
    // The opening is in flight: its answer decides, and a frame held from
    // before is no word on now.
    tell(['change_email'])
    opening.answer(ASK_PASSWORD)

    expect(await outcome).toBe('ask')
    expect(passed).not.toHaveBeenCalled()
  })

  it('lets the answer of its own Send again decide while it is in flight', async () => {
    const tell = confirmedElsewhere()
    const passed = vi.fn()
    const repeat = pending()
    const start = vi
      .fn()
      .mockReturnValueOnce(handle(ASK_CODE))
      .mockReturnValueOnce(repeat.handle)
    const step = createHilosStepUpStep(
      { start, confirm: () => handle() },
      passed,
    )
    await step.open('change_email')

    const again = step.sendAgain()
    tell(['change_email'])
    repeat.answer({ required: false, purpose: 'change your email' })
    await again

    // Passed once, by the answer, and not a second time by the frame.
    expect(passed).toHaveBeenCalledOnce()
    tell(['change_email'])
    expect(passed).toHaveBeenCalledOnce()
  })

  it('lets the answer of its own Confirm decide, and stops listening once it passed', async () => {
    const tell = confirmedElsewhere()
    const passed = vi.fn()
    const confirming = pending()
    const step = createHilosStepUpStep(
      {
        start: () => handle(ASK_PASSWORD),
        confirm: () =>
          confirming.handle as unknown as ReturnType<
            HilosStepUpActions['confirm']
          >,
      },
      passed,
    )
    await step.open('change_email')
    step.password.set('secret')

    const confirmed = step.confirm()
    tell(['change_email'])
    confirming.answer({})

    expect(await confirmed).toBe(true)
    tell(['change_email'])
    expect(passed).not.toHaveBeenCalled()
  })

  it('stops listening for the earlier operation once opened again', async () => {
    const tell = confirmedElsewhere()
    const passed = vi.fn()
    const step = createHilosStepUpStep(
      { start: () => handle(ASK_PASSWORD), confirm: () => handle() },
      passed,
    )
    await step.open('change_email')
    await step.open('delete_account')

    tell(['change_email'])
    expect(passed).not.toHaveBeenCalled()

    tell(['delete_account'])
    expect(passed).toHaveBeenCalledOnce()
  })

  it('passes again on the same list once the step asks again', async () => {
    const tell = confirmedElsewhere()
    const passed = vi.fn()
    const step = createHilosStepUpStep(
      { start: () => handle(ASK_PASSWORD), confirm: () => handle() },
      passed,
    )
    await step.open('change_email')
    tell(['change_email'])
    await step.open('change_email')
    tell(['change_email'])

    expect(passed).toHaveBeenCalledTimes(2)
  })
})
