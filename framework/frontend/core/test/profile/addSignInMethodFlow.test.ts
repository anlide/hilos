import { afterEach, describe, expect, it, vi } from 'vitest'
import { type HilosAuthContext } from '../../src/auth/authContext.js'
import { type OAuthTripOutcome } from '../../src/auth/oauthLogin.js'
import { ActionError } from '../../src/connection/actionLifecycle.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { createSignal } from '../../src/state/signal.js'
import { createHilosProfileAddSignInFlow } from '../../src/profile/addSignInMethodFlow.js'
import { resolveHilosProfileSignInMethods } from '../../src/profile/profileSignInMethods.js'

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
  vi.resetAllMocks()
  doubles.trip = null
})

function setup(verifiedEmail = false) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatch = vi.fn(() => ({ done: Promise.resolve({}) }))
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
    termsPath: '/terms',
    privacyPath: '/privacy',
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

describe('add sign-in method flow', () => {
  it('proves an email, retains its address for the code, and closes only on the password frame', async () => {
    const world = setup()
    world.flow.open()
    world.flow.choosePassword()
    expect(world.flow.step.get()).toBe('password-email')
    await world.flow.submitPasswordEmail('a@example.test')
    expect(world.flow.step.get()).toBe('password-code')
    await world.flow.submitPasswordCode('123456', 'new-secret')
    expect(world.dispatch).toHaveBeenLastCalledWith(
      'profile_add_password_confirm',
      { email: 'a@example.test', code: '123456', newPassword: 'new-secret' },
    )
    expect(world.flow.busy.get()).toBe(true)
    expect(world.flow.step.get()).toBe('password-code')
    world.passwordUpdated()
    expect(world.flow.step.get()).toBe('closed')
    expect(world.flow.busy.get()).toBe(false)
    world.flow.dispose()
    expect(world.listeners.size).toBe(0)
  })
  it('adds directly to a confirmed address and suppresses a duplicate while waiting for the frame', async () => {
    const world = setup(true)
    world.flow.open()
    world.flow.choosePassword()
    expect(world.flow.step.get()).toBe('password-new')
    await world.flow.submitPasswordNew('new-secret')
    await world.flow.submitPasswordNew('other-secret')
    expect(world.dispatch).toHaveBeenCalledTimes(1)
    expect(world.dispatch).toHaveBeenCalledWith('profile_set_password', {
      newPassword: 'new-secret',
    })
    world.passwordUpdated()
    expect(world.flow.step.get()).toBe('closed')
  })
  it('keeps a refused code on the same step and allows a retry', async () => {
    const world = setup()
    world.flow.open()
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
  it('does not apply a late rejection to a reopened dialog', async () => {
    const world = setup()
    let reject!: (error: Error) => void
    world.dispatch.mockReturnValueOnce({
      done: new Promise((_, fail) => {
        reject = fail
      }),
    })
    world.flow.open()
    world.flow.choosePhone()
    const pending = world.flow.submitPhone('+15551234567')
    world.flow.close()
    world.flow.open()
    reject(new ActionError('phone', 'fail', 'Too late'))
    await pending
    expect(world.flow.step.get()).toBe('choose')
    expect(world.flow.refusal.get()).toBeNull()
    expect(world.flow.busy.get()).toBe(false)
  })
  it('leaves a canceled device ceremony in the chooser and ignores success after closing', async () => {
    const world = setup()
    world.flow.open()
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
    world.flow.open()
    finish({ ok: true })
    await pending
    expect(world.flow.step.get()).toBe('choose')
  })
  it('keeps provider errors in the chooser and closes on a completed link', async () => {
    const world = setup()
    world.flow.open()
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
})
