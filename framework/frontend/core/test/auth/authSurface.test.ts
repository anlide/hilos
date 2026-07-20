// Covers the auth surface state machine (HIL-364): the mode/form/pending/error
// state, the descriptor-driven switcher entries, the submit flow (delegated
// dispatch, pending guard, error surfacing, recovery advance), and the client
// password policy that mirrors HIL-164.
import { describe, expect, it, vi } from 'vitest'
import {
  authEntries,
  createAuthSurface,
  isAuthSubmittable,
  PASSWORD_AUTH_METHOD,
  PASSWORD_MIN_LENGTH,
  type AuthFormState,
  type AuthMode,
  type AuthSubmitOutcome,
} from '../../src/auth/authSurface.js'

/** Build a surface over the password method with a stubbed submit. */
function setup(
  onSubmit: (mode: AuthMode, form: AuthFormState) => Promise<AuthSubmitOutcome>,
  initialMode?: AuthMode,
) {
  return createAuthSurface({
    methods: [PASSWORD_AUTH_METHOD],
    onSubmit,
    initialMode,
  })
}

describe('authEntries', () => {
  it('derives the enabled switcher entries in first-seen order', () => {
    expect(authEntries([PASSWORD_AUTH_METHOD])).toEqual([
      'login',
      'register',
      'recovery',
    ])
  })

  it('de-duplicates entries enabled by more than one method', () => {
    const oauth = { key: 'oauth', label: 'Google', modes: ['login'] as const }

    expect(authEntries([PASSWORD_AUTH_METHOD, oauth])).toEqual([
      'login',
      'register',
      'recovery',
    ])
  })
})

describe('isAuthSubmittable', () => {
  const base: AuthFormState = {
    email: '',
    password: '',
    confirmPassword: '',
    code: '',
    newPassword: '',
    phone: '',
  }

  it('requires email and password for login', () => {
    expect(isAuthSubmittable('login', { ...base, email: 'a@b.c' })).toBe(false)
    expect(
      isAuthSubmittable('login', { ...base, email: 'a@b.c', password: 'x' }),
    ).toBe(true)
  })

  it('requires an 8+ char password and a matching confirm for register', () => {
    const short = 'a'.repeat(PASSWORD_MIN_LENGTH - 1)
    const ok = 'a'.repeat(PASSWORD_MIN_LENGTH)
    expect(
      isAuthSubmittable('register', {
        ...base,
        email: 'a@b.c',
        password: short,
        confirmPassword: short,
      }),
    ).toBe(false)
    expect(
      isAuthSubmittable('register', {
        ...base,
        email: 'a@b.c',
        password: ok,
        confirmPassword: 'mismatch!!',
      }),
    ).toBe(false)
    expect(
      isAuthSubmittable('register', {
        ...base,
        email: 'a@b.c',
        password: ok,
        confirmPassword: ok,
      }),
    ).toBe(true)
  })
})

describe('createAuthSurface', () => {
  it('starts in login with an empty form and the method entries', () => {
    const surface = setup(async () => ({ ok: true }))
    expect(surface.mode.get()).toBe('login')
    expect(surface.form.get().email).toBe('')
    expect(surface.entries).toEqual(['login', 'register', 'recovery'])
    expect(surface.pending.get()).toBe(false)
    expect(surface.error.get()).toBeNull()
  })

  it('setField updates one field and recomputes submittable', () => {
    const surface = setup(async () => ({ ok: true }))
    surface.setField('email', 'a@b.c')
    surface.setField('password', 'secret')
    expect(surface.form.get().email).toBe('a@b.c')
    expect(surface.submittable.get()).toBe(true)
  })

  it('switchTo keeps the email but clears the secrets and the error', () => {
    const surface = setup(async () => ({ ok: false, message: 'nope' }))
    surface.setField('email', 'a@b.c')
    surface.setField('password', 'secret')
    surface.error.get() // no-op read
    surface.switchTo('register')
    expect(surface.mode.get()).toBe('register')
    expect(surface.form.get().email).toBe('a@b.c')
    expect(surface.form.get().password).toBe('')
    expect(surface.error.get()).toBeNull()
  })

  it('submit surfaces the reported failure message and stays in mode', async () => {
    const surface = setup(async () => ({
      ok: false,
      message: 'Invalid email or password',
    }))
    surface.setField('email', 'a@b.c')
    surface.setField('password', 'secret')
    await surface.submit()
    expect(surface.error.get()).toBe('Invalid email or password')
    expect(surface.mode.get()).toBe('login')
    expect(surface.pending.get()).toBe(false)
  })

  it('submit advances the mode on a success that reports next', async () => {
    const surface = setup(
      async () => ({ ok: true, next: 'recovery_confirm' }),
      'recovery_request',
    )
    surface.setField('email', 'a@b.c')
    await surface.submit()
    expect(surface.mode.get()).toBe('recovery_confirm')
    expect(surface.error.get()).toBeNull()
  })

  it('submit leaves the mode alone on a success without next (session upgrade)', async () => {
    const surface = setup(async () => ({ ok: true }))
    surface.setField('email', 'a@b.c')
    surface.setField('password', 'secret')
    await surface.submit()
    expect(surface.mode.get()).toBe('login')
  })

  it('submit is a no-op while already pending', async () => {
    const onSubmit = vi.fn(
      () =>
        new Promise<AuthSubmitOutcome>((resolve) =>
          setTimeout(() => resolve({ ok: true }), 5),
        ),
    )
    const surface = setup(onSubmit)
    const first = surface.submit()
    expect(surface.pending.get()).toBe(true)
    await surface.submit()
    await first
    expect(onSubmit).toHaveBeenCalledTimes(1)
  })

  it('reset returns to the initial mode with an empty form', () => {
    const surface = setup(async () => ({ ok: true }), 'register')
    surface.setField('email', 'a@b.c')
    surface.switchTo('login')
    surface.reset()
    expect(surface.mode.get()).toBe('register')
    expect(surface.form.get().email).toBe('')
  })
})
