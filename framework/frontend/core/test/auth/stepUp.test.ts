import { describe, expect, it, vi } from 'vitest'

import {
  createHilosStepUpStep,
  type HilosStepUpActions,
} from '../../src/auth/stepUp.js'
import { ActionError } from '../../src/connection/actionLifecycle.js'

function handle(reply: unknown = {}): ReturnType<HilosStepUpActions['start']> {
  return { done: Promise.resolve({ reply }) } as ReturnType<
    HilosStepUpActions['start']
  >
}

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
