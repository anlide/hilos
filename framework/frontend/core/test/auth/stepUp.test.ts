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
