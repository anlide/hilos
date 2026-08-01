// Covers the identifier-first auth flow machine (HIL-413): identifier
// classification, the icon visibility matrix (the CHECK examples), submittability
// per step, and the machine itself — debounced last-reply-wins detection, the
// degraded state, the identifier-change reset, the delegated submit/method-action
// dispatch, and the recovery/back/reset transitions.
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  classifyIdentifier,
  createAuthFlow,
  isFlowSubmittable,
  visibleMethodIcons,
  MAGIC_LINK_FLOW_METHOD,
  OAUTH_GITHUB_FLOW_METHOD,
  PASSKEY_FLOW_METHOD,
  PASSWORD_FLOW_METHOD,
  PASSWORD_MIN_LENGTH,
  type AuthFlowForm,
  type AuthFlowOptions,
  type AuthFlowState,
  type IdentifierDetection,
} from '../../src/auth/authFlow.js'

const EMPTY_FORM: AuthFlowForm = {
  identifier: '',
  password: '',
  confirmPassword: '',
  code: '',
  newPassword: '',
}

/** A resolved detection stub. */
function detected(
  overrides: Partial<IdentifierDetection> = {},
): IdentifierDetection {
  return {
    identifier: 'a@b.com',
    kind: 'email',
    exists: true,
    methods: ['password'],
    ...overrides,
  }
}

/** Build a flow with sensible passing stubs; override any seam per test. */
function setup(options: Partial<AuthFlowOptions> = {}) {
  return createAuthFlow({
    methods: [PASSWORD_FLOW_METHOD],
    onDetect: async () => detected(),
    onSubmit: async () => ({ ok: true }),
    onMethodAction: async () => ({ ok: true }),
    ...options,
  })
}

describe('classifyIdentifier', () => {
  it('reads an @ as email', () => {
    expect(classifyIdentifier('a@b.com')).toBe('email')
    expect(classifyIdentifier('a@')).toBe('email')
  })

  it('reads an all-digit value (with separators) as phone', () => {
    expect(classifyIdentifier('+1 (234) 567-8901')).toBe('phone')
    expect(classifyIdentifier('123')).toBe('phone')
  })

  it('reads empty or unrecognised as unknown', () => {
    expect(classifyIdentifier('')).toBe('unknown')
    expect(classifyIdentifier('   ')).toBe('unknown')
    expect(classifyIdentifier('bob')).toBe('unknown')
  })
})

describe('isFlowSubmittable', () => {
  const flow: AuthFlowState = {
    step: 'identifier',
    intent: 'login',
    methodKey: null,
    identifierKind: 'unknown',
  }

  it('requires a non-empty identifier', () => {
    expect(isFlowSubmittable(flow, EMPTY_FORM)).toBe(false)
    expect(
      isFlowSubmittable(flow, { ...EMPTY_FORM, identifier: 'a@b.com' }),
    ).toBe(true)
  })

  it('requires a password at the credential step', () => {
    const credential = { ...flow, step: 'credential' as const }
    expect(isFlowSubmittable(credential, EMPTY_FORM)).toBe(false)
    expect(
      isFlowSubmittable(credential, { ...EMPTY_FORM, password: 'x' }),
    ).toBe(true)
  })

  it('requires an 8+ char new password and a matching confirm at set_password', () => {
    const step = { ...flow, step: 'set_password' as const }
    const short = 'a'.repeat(PASSWORD_MIN_LENGTH - 1)
    const ok = 'a'.repeat(PASSWORD_MIN_LENGTH)
    expect(
      isFlowSubmittable(step, {
        ...EMPTY_FORM,
        newPassword: short,
        confirmPassword: short,
      }),
    ).toBe(false)
    expect(
      isFlowSubmittable(step, {
        ...EMPTY_FORM,
        newPassword: ok,
        confirmPassword: 'mismatch!',
      }),
    ).toBe(false)
    expect(
      isFlowSubmittable(step, {
        ...EMPTY_FORM,
        newPassword: ok,
        confirmPassword: ok,
      }),
    ).toBe(true)
  })

  it('is never submittable at external or done', () => {
    expect(isFlowSubmittable({ ...flow, step: 'external' }, EMPTY_FORM)).toBe(
      false,
    )
    expect(isFlowSubmittable({ ...flow, step: 'done' }, EMPTY_FORM)).toBe(false)
  })
})

describe('visibleMethodIcons', () => {
  const methods = [
    PASSWORD_FLOW_METHOD,
    OAUTH_GITHUB_FLOW_METHOD,
    PASSKEY_FLOW_METHOD,
    MAGIC_LINK_FLOW_METHOD,
  ]

  it('shows the empty-state icons and never the identifier method', () => {
    const keys = visibleMethodIcons(methods, '', 'unknown').map((m) => m.key)
    // passkey (whenEmpty) and oauth (whenEmpty) show; magic_link (whenEmpty:false)
    // does not; the identifier-kind password method is never an icon.
    expect(keys).toEqual(['oauth:github', 'passkey'])
  })

  it('drops the empty-only passkey and adds the email magic link while typing an email', () => {
    const keys = visibleMethodIcons(methods, 'a@b.com', 'email').map(
      (m) => m.key,
    )
    expect(keys).toEqual(['oauth:github', 'magic_link'])
  })

  it('excludes the email-only magic link while typing a phone', () => {
    const keys = visibleMethodIcons(methods, '+1234567890', 'phone').map(
      (m) => m.key,
    )
    expect(keys).toEqual(['oauth:github'])
  })
})

describe('createAuthFlow', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('starts on the identifier step with an empty form and idle detection', () => {
    const flow = setup()
    expect(flow.flow.get()).toEqual({
      step: 'identifier',
      intent: 'login',
      methodKey: null,
      identifierKind: 'unknown',
    })
    expect(flow.form.get()).toEqual(EMPTY_FORM)
    expect(flow.detection.get()).toEqual({ status: 'idle', result: null })
    expect(flow.pending.get()).toBe(false)
    expect(flow.error.get()).toBeNull()
  })

  it('classifies the identifier and exposes the icon matrix reactively', () => {
    const flow = setup({
      methods: [
        PASSWORD_FLOW_METHOD,
        PASSKEY_FLOW_METHOD,
        MAGIC_LINK_FLOW_METHOD,
      ],
    })
    expect(flow.icons.get().map((m) => m.key)).toEqual(['passkey'])
    flow.setField('identifier', 'a@b.com')
    expect(flow.flow.get().identifierKind).toBe('email')
    expect(flow.icons.get().map((m) => m.key)).toEqual(['magic_link'])
  })

  it('debounces the lookup and resolves detection for a complete email', async () => {
    vi.useFakeTimers()
    const onDetect = vi.fn(async () => detected({ exists: false, methods: [] }))
    const flow = setup({ onDetect, detectDebounceMs: 300 })

    flow.setField('identifier', 'a@b.com')
    expect(flow.detection.get().status).toBe('pending')
    expect(onDetect).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(300)
    expect(onDetect).toHaveBeenCalledWith('a@b.com', 'email')
    expect(flow.detection.get()).toEqual({
      status: 'resolved',
      result: {
        identifier: 'a@b.com',
        kind: 'email',
        exists: false,
        methods: [],
      },
    })
  })

  it('does not fire a lookup for a partial or unrecognised identifier', async () => {
    vi.useFakeTimers()
    const onDetect = vi.fn(async () => detected())
    const flow = setup({ onDetect, detectDebounceMs: 300 })

    flow.setField('identifier', 'a@')
    flow.setField('identifier', 'bob')
    await vi.advanceTimersByTimeAsync(300)
    expect(onDetect).not.toHaveBeenCalled()
    expect(flow.detection.get().status).toBe('idle')
  })

  it('rolls detection back to idle when the identifier is cleared', async () => {
    vi.useFakeTimers()
    const flow = setup({ detectDebounceMs: 300 })
    flow.setField('identifier', 'a@b.com')
    await vi.advanceTimersByTimeAsync(300)
    expect(flow.detection.get().status).toBe('resolved')

    flow.setField('identifier', '')
    expect(flow.detection.get()).toEqual({ status: 'idle', result: null })
    expect(flow.flow.get().identifierKind).toBe('unknown')
  })

  it('applies only the latest lookup reply (last wins)', async () => {
    vi.useFakeTimers()
    const deferred: Array<(value: IdentifierDetection) => void> = []
    const onDetect = vi.fn(
      () =>
        new Promise<IdentifierDetection>((resolve) => deferred.push(resolve)),
    )
    const flow = setup({ onDetect, detectDebounceMs: 100 })

    flow.setField('identifier', 'a@b.com')
    await vi.advanceTimersByTimeAsync(100)
    flow.setField('identifier', 'x@y.com')
    await vi.advanceTimersByTimeAsync(100)
    expect(deferred).toHaveLength(2)

    // Resolve the stale request after the current one: it must be ignored.
    deferred[1](detected({ identifier: 'x@y.com', exists: false, methods: [] }))
    deferred[0](detected({ identifier: 'a@b.com', exists: true }))
    await vi.advanceTimersByTimeAsync(0)

    expect(flow.detection.get().result?.identifier).toBe('x@y.com')
    expect(flow.detection.get().result?.exists).toBe(false)
  })

  it('degrades to unavailable when the lookup rejects, without blocking', async () => {
    vi.useFakeTimers()
    const onDetect = vi.fn(async () => {
      throw new Error('offline')
    })
    const flow = setup({ onDetect, detectDebounceMs: 300 })

    flow.setField('identifier', 'a@b.com')
    await vi.advanceTimersByTimeAsync(300)
    expect(flow.detection.get()).toEqual({
      status: 'unavailable',
      result: null,
    })
    // The flow still lets the user proceed.
    expect(flow.submittable.get()).toBe(true)
  })

  it('clears the secrets when the identifier changes but keeps them stepping forward', async () => {
    const flow = setup({
      onSubmit: async () => ({ ok: true, next: { step: 'credential' } }),
    })
    flow.setField('identifier', 'a@b.com')
    await flow.submit()
    expect(flow.flow.get().step).toBe('credential')
    flow.setField('password', 'secret-pw')
    expect(flow.form.get().password).toBe('secret-pw')

    // Editing the identifier restarts the flow and wipes the password.
    flow.setField('identifier', 'c@d.com')
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.form.get().password).toBe('')
  })

  it('merges the outcome next state on a successful submit', async () => {
    const onSubmit = vi.fn(async () => ({
      ok: true,
      next: { step: 'set_password' as const, intent: 'register' as const },
    }))
    const flow = setup({ onSubmit })
    flow.setField('identifier', 'new@b.com')
    await flow.submit()
    expect(flow.flow.get().step).toBe('set_password')
    expect(flow.flow.get().intent).toBe('register')
    expect(flow.error.get()).toBeNull()
  })

  it('surfaces the reported failure message and stays put on submit', async () => {
    const flow = setup({
      onSubmit: async () => ({ ok: false, message: 'No such account' }),
    })
    flow.setField('identifier', 'a@b.com')
    await flow.submit()
    expect(flow.error.get()).toBe('No such account')
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.pending.get()).toBe(false)
  })

  it('submit is a no-op while already pending', async () => {
    const onSubmit = vi.fn(
      () =>
        new Promise<{ ok: boolean }>((resolve) =>
          setTimeout(() => resolve({ ok: true }), 5),
        ),
    )
    const flow = setup({ onSubmit })
    const first = flow.submit()
    expect(flow.pending.get()).toBe(true)
    await flow.submit()
    await first
    expect(onSubmit).toHaveBeenCalledTimes(1)
  })

  it('hands off to an icon method and parks in external', async () => {
    const onMethodAction = vi.fn(async () => ({ ok: true }))
    const flow = setup({
      methods: [PASSWORD_FLOW_METHOD, PASSKEY_FLOW_METHOD],
      onMethodAction,
    })
    await flow.chooseMethod('passkey')
    expect(onMethodAction).toHaveBeenCalledWith('passkey', EMPTY_FORM)
    expect(flow.flow.get().step).toBe('external')
    expect(flow.flow.get().methodKey).toBe('passkey')
  })

  it('falls back to the identifier step when a ceremony fails', async () => {
    const flow = setup({
      methods: [PASSWORD_FLOW_METHOD, PASSKEY_FLOW_METHOD],
      onMethodAction: async () => ({ ok: false, message: 'Cancelled' }),
    })
    await flow.chooseMethod('passkey')
    expect(flow.error.get()).toBe('Cancelled')
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.flow.get().methodKey).toBeNull()
  })

  it('ignores chooseMethod for an unknown or non-icon key', async () => {
    const onMethodAction = vi.fn(async () => ({ ok: true }))
    const flow = setup({ onMethodAction })
    await flow.chooseMethod('nope')
    await flow.chooseMethod('password')
    expect(onMethodAction).not.toHaveBeenCalled()
    expect(flow.flow.get().step).toBe('identifier')
  })

  it('startRecovery enters the recovery code step keeping the identifier', () => {
    const flow = setup()
    flow.setField('identifier', 'a@b.com')
    flow.setField('password', 'guess')
    flow.startRecovery()
    expect(flow.flow.get()).toMatchObject({ intent: 'recovery', step: 'code' })
    expect(flow.form.get().identifier).toBe('a@b.com')
    expect(flow.form.get().password).toBe('')
  })

  it('back returns to the identifier step without touching the identifier', async () => {
    const flow = setup({
      onSubmit: async () => ({ ok: true, next: { step: 'credential' } }),
    })
    flow.setField('identifier', 'a@b.com')
    await flow.submit()
    flow.back()
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.form.get().identifier).toBe('a@b.com')
    expect(flow.flow.get().identifierKind).toBe('email')
  })

  it('reset returns to the initial state and clears detection', async () => {
    vi.useFakeTimers()
    const flow = setup({ detectDebounceMs: 300 })
    flow.setField('identifier', 'a@b.com')
    await vi.advanceTimersByTimeAsync(300)
    flow.reset()
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      intent: 'login',
    })
    expect(flow.form.get()).toEqual(EMPTY_FORM)
    expect(flow.detection.get()).toEqual({ status: 'idle', result: null })
  })
})
