// Covers the identifier-first auth flow machine (HIL-413): identifier
// classification, the icon visibility matrix (the CHECK examples), the
// debounced echo-guarded detection with NO degraded state, the intent
// derivation (none/pending/active), the consent-as-local-step registration, the
// channel-is-the-send code path, the resend gate, the second factor, the two
// return points plus cancelMethod, the applyExternal converge, screenKey on all
// thirteen screens, primaryAction, input preservation, and the
// method-set-agnostic guarantee. HIL-418 adds the cancel that reaches into the
// ceremony (the abort) and the intent-judged fate of an outcome that lands after
// it.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  applicableChannels,
  classifyIdentifier,
  createAuthFlow,
  isFlowSubmittable,
  screenKeyOf,
  visibleMethodIcons,
  DEFAULT_DETECT_DEBOUNCE_MS,
  DEFAULT_EXTERNAL_CANCEL_GRACE_MS,
  MAGIC_LINK_FLOW_METHOD,
  MAGIC_LINK_METHOD_KEY,
  OAUTH_GITHUB_FLOW_METHOD,
  PASSKEY_FLOW_METHOD,
  PASSWORD_FLOW_METHOD,
  PASSWORD_MIN_LENGTH,
  type AuthFlowForm,
  type AuthFlowOptions,
  type AuthFlowScreen,
  type AuthFlowState,
  type AuthFlowSubmitOutcome,
  type CodeChannelDescriptor,
  type DetectionState,
  type IdentifierDetection,
} from '../../src/auth/authFlow.js'

/** One second in ms — the scale a backend `resendAt` moment is built in here. */
const SECOND_MS = 1000

const EMPTY_FORM: AuthFlowForm = {
  identifier: '',
  password: '',
  code: '',
  newPassword: '',
  consentAccepted: false,
  usingBackupCode: false,
  trustDevice: false,
}

const INITIAL_FLOW: AuthFlowState = {
  step: 'identifier',
  intent: 'login',
  methodKey: null,
  identifierKind: 'unknown',
  channelKey: null,
}

const ALL_METHODS = [
  PASSWORD_FLOW_METHOD,
  OAUTH_GITHUB_FLOW_METHOD,
  PASSKEY_FLOW_METHOD,
  MAGIC_LINK_FLOW_METHOD,
]

const SMS_CHANNEL: CodeChannelDescriptor = {
  key: 'sms',
  label: 'Text me',
  identifierKinds: ['phone'],
  primary: true,
}

const TELEGRAM_CHANNEL: CodeChannelDescriptor = {
  key: 'telegram',
  label: 'Telegram',
  identifierKinds: ['phone'],
}

/** A resolved active-account detection stub echoing `a@b.com`. */
function detected(
  overrides: Partial<IdentifierDetection> = {},
): IdentifierDetection {
  return {
    identifier: 'a@b.com',
    normalized: 'a@b.com',
    kind: 'email',
    status: 'active',
    methods: ['password'],
    registerable: ['password'],
    ...overrides,
  }
}

/** Build a flow with sensible passing stubs; override any seam per test. */
function setup(options: Partial<AuthFlowOptions> = {}) {
  return createAuthFlow({
    methods: ALL_METHODS,
    channels: [SMS_CHANNEL, TELEGRAM_CHANNEL],
    onDetect: async (identifier) => detected({ identifier }),
    onSubmit: async () => ({ ok: true }),
    onMethodAction: async () => ({ ok: true }),
    ...options,
  })
}

/** Type an identifier and let the debounced lookup fire and settle. */
async function typeAndDetect(
  flow: ReturnType<typeof createAuthFlow>,
  identifier: string,
): Promise<void> {
  flow.setField('identifier', identifier)
  await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS)
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('classifyIdentifier', () => {
  it('reads an @ as email, even mid-typing', () => {
    expect(classifyIdentifier('a@b.com')).toBe('email')
    expect(classifyIdentifier('a@')).toBe('email')
  })

  it('reads digits with cosmetic separators as phone', () => {
    expect(classifyIdentifier('+7 999 123-45-67')).toBe('phone')
    expect(classifyIdentifier('89991234567')).toBe('phone')
  })

  it('reads empty and unrecognized values as unknown', () => {
    expect(classifyIdentifier('')).toBe('unknown')
    expect(classifyIdentifier('   ')).toBe('unknown')
    expect(classifyIdentifier('john doe')).toBe('unknown')
  })
})

describe('visibleMethodIcons — the matrix on the four input states', () => {
  it('empty field: whenEmpty icons only (passkey, oauth); magic link hidden', () => {
    expect(visibleMethodIcons(ALL_METHODS, '', 'unknown', 'login')).toEqual([
      OAUTH_GITHUB_FLOW_METHOD,
      PASSKEY_FLOW_METHOD,
    ])
  })

  it('typing an unrecognized value hides the whole row', () => {
    expect(
      visibleMethodIcons(ALL_METHODS, 'john doe', 'unknown', 'login'),
    ).toEqual([])
  })

  it('typing an email: magic link only (oauth and passkey vanish on typing)', () => {
    expect(
      visibleMethodIcons(ALL_METHODS, 'a@b.com', 'email', 'login'),
    ).toEqual([MAGIC_LINK_FLOW_METHOD])
  })

  it('typing a phone: nothing (magic link is email-only)', () => {
    expect(
      visibleMethodIcons(ALL_METHODS, '+79991234567', 'phone', 'login'),
    ).toEqual([])
  })

  it('honors descriptor intents: a login-only icon hides under register', () => {
    expect(visibleMethodIcons(ALL_METHODS, '', 'unknown', 'register')).toEqual([
      PASSKEY_FLOW_METHOD,
    ])
  })

  it('honors descriptor-level identifierKinds while typing', () => {
    const smsIcon = {
      key: 'sms_icon',
      label: 'Code by SMS',
      kind: 'icon',
      identifierKinds: ['phone'],
      visibility: { whenTyping: true },
    } as const
    expect(visibleMethodIcons([smsIcon], 'a@b.com', 'email', 'login')).toEqual(
      [],
    )
    expect(
      visibleMethodIcons([smsIcon], '+79991234567', 'phone', 'login'),
    ).toEqual([smsIcon])
  })
})

describe('applicableChannels', () => {
  it('filters by identifier kind and keeps registry order', () => {
    expect(
      applicableChannels([SMS_CHANNEL, TELEGRAM_CHANNEL], 'phone'),
    ).toEqual([SMS_CHANNEL, TELEGRAM_CHANNEL])
    expect(
      applicableChannels([SMS_CHANNEL, TELEGRAM_CHANNEL], 'email'),
    ).toEqual([])
  })
})

describe('isFlowSubmittable', () => {
  const resolved = (result: IdentifierDetection): DetectionState => ({
    status: 'resolved',
    result,
  })
  const idle: DetectionState = { status: 'idle', result: null }

  it('identifier: nothing submits until the detection revealed a field', () => {
    const flow = { ...INITIAL_FLOW, identifierKind: 'email' as const }
    const form = { ...EMPTY_FORM, identifier: 'a@b.com', password: 'secret-1' }
    expect(isFlowSubmittable(flow, form, idle)).toBe(false)
  })

  it('identifier login: a revealed password submits when non-empty', () => {
    const flow = { ...INITIAL_FLOW, identifierKind: 'email' as const }
    const form = { ...EMPTY_FORM, identifier: 'a@b.com' }
    const state = resolved(detected())
    expect(isFlowSubmittable(flow, form, state)).toBe(false)
    expect(isFlowSubmittable(flow, { ...form, password: 'x' }, state)).toBe(
      true,
    )
  })

  it('identifier register: the password needs the minimum length', () => {
    const flow = {
      ...INITIAL_FLOW,
      intent: 'register' as const,
      identifierKind: 'email' as const,
    }
    const form = { ...EMPTY_FORM, identifier: 'new@b.com' }
    const state = resolved(
      detected({ identifier: 'new@b.com', status: 'none', methods: [] }),
    )
    expect(isFlowSubmittable(flow, { ...form, password: 'short' }, state)).toBe(
      false,
    )
    expect(
      isFlowSubmittable(
        flow,
        { ...form, password: 'x'.repeat(PASSWORD_MIN_LENGTH) },
        state,
      ),
    ).toBe(true)
  })

  it('a phone never submits from the identifier step', () => {
    const flow = { ...INITIAL_FLOW, identifierKind: 'phone' as const }
    const form = { ...EMPTY_FORM, identifier: '+79991234567', password: 'x' }
    const state = resolved(
      detected({
        identifier: '+79991234567',
        normalized: '+79991234567',
        kind: 'phone',
      }),
    )
    expect(isFlowSubmittable(flow, form, state)).toBe(false)
  })

  it('consent submits on accepted terms only', () => {
    const flow = { ...INITIAL_FLOW, step: 'consent' as const }
    expect(isFlowSubmittable(flow, EMPTY_FORM, idle)).toBe(false)
    expect(
      isFlowSubmittable(flow, { ...EMPTY_FORM, consentAccepted: true }, idle),
    ).toBe(true)
  })

  it('code and second_factor submit on a non-empty code', () => {
    for (const step of ['code', 'second_factor'] as const) {
      const flow = { ...INITIAL_FLOW, step }
      expect(isFlowSubmittable(flow, EMPTY_FORM, idle)).toBe(false)
      expect(
        isFlowSubmittable(flow, { ...EMPTY_FORM, code: '123456' }, idle),
      ).toBe(true)
    }
  })

  it('set_password needs the minimum length; external never; done always', () => {
    expect(
      isFlowSubmittable(
        { ...INITIAL_FLOW, step: 'set_password' },
        { ...EMPTY_FORM, newPassword: 'x'.repeat(PASSWORD_MIN_LENGTH) },
        idle,
      ),
    ).toBe(true)
    expect(
      isFlowSubmittable(
        { ...INITIAL_FLOW, step: 'external' },
        EMPTY_FORM,
        idle,
      ),
    ).toBe(false)
    expect(
      isFlowSubmittable({ ...INITIAL_FLOW, step: 'done' }, EMPTY_FORM, idle),
    ).toBe(true)
  })
})

describe('detection', () => {
  it('debounces: no lookup before the quiet window, one after', async () => {
    const onDetect = vi.fn(async (identifier: string) =>
      detected({ identifier }),
    )
    const flow = setup({ onDetect })
    flow.setField('identifier', 'a@b.com')
    expect(flow.detection.get().status).toBe('pending')
    expect(onDetect).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS)
    expect(onDetect).toHaveBeenCalledTimes(1)
    expect(flow.detection.get().status).toBe('resolved')
  })

  it('spends no lookup on a partial or unrecognized value', async () => {
    const onDetect = vi.fn(async (identifier: string) =>
      detected({ identifier }),
    )
    const flow = setup({ onDetect })
    flow.setField('identifier', 'a@')
    flow.setField('identifier', 'john doe')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS)
    expect(onDetect).not.toHaveBeenCalled()
    expect(flow.detection.get().status).toBe('idle')
  })

  it('drops a reply to a value the user has since retyped', async () => {
    let release: (value: IdentifierDetection) => void = () => undefined
    const first = new Promise<IdentifierDetection>((resolve) => {
      release = resolve
    })
    const onDetect = vi
      .fn<(identifier: string) => Promise<IdentifierDetection>>()
      .mockReturnValueOnce(first)
      .mockImplementation(async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
      )
    const flow = setup({ onDetect })
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('identifier', 'new@b.com')
    release(detected({ identifier: 'a@b.com' }))
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS)
    // The stale active-account reply must not have leaked into the new value's
    // resolution — the second lookup's none-account result stands.
    expect(flow.detection.get().result?.identifier).toBe('new@b.com')
    expect(flow.detection.get().result?.status).toBe('none')
  })

  it('matches by the request ECHO: a normalized phone keeps its own reply', async () => {
    const typed = '+7 999 123-45-67'
    const flow = setup({
      onDetect: async (identifier) =>
        detected({
          identifier,
          normalized: '+79991234567',
          kind: 'phone',
          methods: [],
        }),
    })
    await typeAndDetect(flow, typed)
    expect(flow.detection.get().status).toBe('resolved')
    expect(flow.detection.get().result?.normalized).toBe('+79991234567')
  })

  it('an unanswered lookup reveals nothing (no degraded state)', async () => {
    const flow = setup({
      onDetect: () => new Promise<IdentifierDetection>(() => undefined),
    })
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.detection.get().status).toBe('pending')
    expect(flow.submittable.get()).toBe(false)
    expect(flow.primaryAction.get()).toBeNull()
  })

  it('a rejected lookup rolls back to idle, not to an error', async () => {
    const flow = setup({
      onDetect: async () => {
        throw new Error('transport down')
      },
    })
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.detection.get()).toEqual({ status: 'idle', result: null })
    expect(flow.error.get()).toBeNull()
  })

  it('an emptied field rolls detection back to idle', async () => {
    const flow = setup()
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('identifier', '')
    expect(flow.detection.get().status).toBe('idle')
  })

  it('orders two in-flight lookups of the SAME text (type, edit away, retype)', async () => {
    let releaseFirst: (value: IdentifierDetection) => void = () => undefined
    const first = new Promise<IdentifierDetection>((resolve) => {
      releaseFirst = resolve
    })
    const onDetect = vi
      .fn<(identifier: string) => Promise<IdentifierDetection>>()
      .mockReturnValueOnce(first)
      .mockImplementation(async (identifier) => detected({ identifier }))
    const flow = setup({ onDetect })
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('identifier', 'a@b.co')
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.detection.get().status).toBe('resolved')
    // The stale first reply's echo matches the field again — only the
    // sequence guard can drop it.
    releaseFirst(
      detected({ identifier: 'a@b.com', status: 'none', methods: [] }),
    )
    await vi.advanceTimersByTimeAsync(0)
    expect(flow.detection.get().result?.status).toBe('active')
    expect(flow.flow.get().intent).toBe('login')
  })

  it('re-emitting the same value retries a failed lookup', async () => {
    const onDetect = vi
      .fn<(identifier: string) => Promise<IdentifierDetection>>()
      .mockRejectedValueOnce(new Error('blip'))
      .mockImplementation(async (identifier) => detected({ identifier }))
    const flow = setup({ onDetect })
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.detection.get().status).toBe('idle')
    flow.setField('identifier', 'a@b.com')
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS)
    expect(onDetect).toHaveBeenCalledTimes(2)
    expect(flow.detection.get().status).toBe('resolved')
  })
})

describe('intent derivation from the detection reply', () => {
  it('active → login on the identifier step', async () => {
    const flow = setup()
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      intent: 'login',
    })
    expect(flow.screenKey.get()).toBe('sign_in')
  })

  it('none with open registration → register, still on the identifier step', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      intent: 'register',
    })
    expect(flow.screenKey.get()).toBe('create_account')
  })

  it('none with registration closed → no offer: login intent, nothing to press', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [], registerable: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    expect(flow.flow.get().intent).toBe('login')
    expect(flow.submittable.get()).toBe(false)
    expect(flow.primaryAction.get()).toBeNull()
  })

  it('pending reservation parks on the code screen WITHOUT a send', async () => {
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({
      onSubmit,
      onDetect: async (identifier) =>
        detected({ identifier, status: 'pending', methods: [] }),
    })
    await typeAndDetect(flow, 'reserved@b.com')
    expect(flow.flow.get()).toMatchObject({ step: 'code', intent: 'register' })
    expect(flow.screenKey.get()).toBe('confirm_identifier')
    expect(onSubmit).not.toHaveBeenCalled()
  })
})

describe('primaryAction — the five shapes', () => {
  it('submit: an active email account with a password', async () => {
    const flow = setup()
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.primaryAction.get()).toEqual({ kind: 'submit' })
  })

  it('method: a passwordless account promotes its passwordless method', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, methods: [MAGIC_LINK_METHOD_KEY] }),
    })
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.primaryAction.get()).toEqual({
      kind: 'method',
      key: MAGIC_LINK_METHOD_KEY,
    })
  })

  it('channel: a phone signs in by its primary code channel', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({
          identifier,
          normalized: identifier,
          kind: 'phone',
          methods: [],
        }),
    })
    await typeAndDetect(flow, '+79991234567')
    expect(flow.primaryAction.get()).toEqual({ kind: 'channel', key: 'sms' })
  })

  it('null: before the detection resolved', () => {
    const flow = setup()
    expect(flow.primaryAction.get()).toBeNull()
  })

  it('null: a parked external ceremony has no primary control', async () => {
    const flow = setup()
    flow.applyExternal({ step: 'external', methodKey: 'passkey' })
    expect(flow.primaryAction.get()).toBeNull()
  })

  it('passwordless-only registration promotes the registerable method, never a dead submit', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({
          identifier,
          status: 'none',
          methods: [],
          registerable: ['passkey'],
        }),
    })
    await typeAndDetect(flow, 'new@b.com')
    expect(flow.primaryAction.get()).toEqual({ kind: 'method', key: 'passkey' })
    flow.setField('password', 'x'.repeat(PASSWORD_MIN_LENGTH))
    expect(flow.submittable.get()).toBe(false)
  })
})

describe('screenKey — all thirteen screens', () => {
  it('derives every screen from the axes', () => {
    const cases: ReadonlyArray<[Partial<AuthFlowState>, AuthFlowScreen]> = [
      [{}, 'sign_in'],
      [{ intent: 'register' }, 'create_account'],
      [{ step: 'consent', intent: 'register' }, 'terms'],
      [{ step: 'code', intent: 'register' }, 'confirm_identifier'],
      [{ step: 'code', intent: 'login' }, 'enter_code'],
      [{ step: 'code', intent: 'recovery' }, 'reset_code'],
      [{ step: 'second_factor', intent: 'login' }, 'two_step'],
      [{ step: 'set_password', intent: 'recovery' }, 'choose_password'],
      [{ step: 'external', methodKey: 'oauth:github' }, 'waiting_external'],
      [{ step: 'external', methodKey: MAGIC_LINK_METHOD_KEY }, 'check_inbox'],
      [{ step: 'done', intent: 'register' }, 'done_registered'],
      [{ step: 'done', intent: 'recovery' }, 'done_password_changed'],
      [{ step: 'done', intent: 'login' }, 'done_signed_in'],
    ]
    for (const [partial, expected] of cases) {
      expect(screenKeyOf({ ...INITIAL_FLOW, ...partial })).toBe(expected)
    }
  })
})

describe('registration: consent is a local step before anything is created', () => {
  it('submit from the identifier step moves to consent without a dispatch', async () => {
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({
      onSubmit,
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    flow.setField('password', 'x'.repeat(PASSWORD_MIN_LENGTH))
    await flow.submit()
    expect(flow.flow.get().step).toBe('consent')
    expect(flow.screenKey.get()).toBe('terms')
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('the real dispatch happens from consent, with the terms accepted', async () => {
    const onSubmit = vi.fn(async () => ({
      ok: true,
      next: { step: 'code' as const },
      resendAt: Date.now() + 30 * SECOND_MS,
    }))
    const flow = setup({
      onSubmit,
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    flow.setField('password', 'x'.repeat(PASSWORD_MIN_LENGTH))
    await flow.submit()
    flow.setField('consentAccepted', true)
    expect(flow.submittable.get()).toBe(true)
    await flow.submit()
    expect(onSubmit).toHaveBeenCalledWith(
      'submit',
      expect.objectContaining({ step: 'consent', intent: 'register' }),
      expect.objectContaining({ consentAccepted: true }),
    )
    expect(flow.flow.get().step).toBe('code')
    expect(flow.screenKey.get()).toBe('confirm_identifier')
  })

  it('a registering phone hops to consent from its channel choice — nothing dispatches', async () => {
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({
      onSubmit,
      onDetect: async (identifier) =>
        detected({
          identifier,
          normalized: identifier,
          kind: 'phone',
          status: 'none',
          methods: [],
        }),
    })
    await typeAndDetect(flow, '+79991234567')
    expect(flow.flow.get().intent).toBe('register')
    await flow.chooseChannel('sms')
    expect(flow.flow.get()).toMatchObject({
      step: 'consent',
      channelKey: 'sms',
    })
    expect(onSubmit).not.toHaveBeenCalled()
    flow.setField('consentAccepted', true)
    await flow.submit()
    expect(onSubmit).toHaveBeenCalledWith(
      'submit',
      expect.objectContaining({ step: 'consent', channelKey: 'sms' }),
      expect.anything(),
    )
  })

  it('a registering envelope hops to consent — nothing is sent', async () => {
    const onMethodAction = vi.fn(async () => ({ ok: true }))
    const flow = setup({
      onMethodAction,
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    expect(flow.flow.get().intent).toBe('register')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    expect(flow.flow.get()).toMatchObject({
      step: 'consent',
      methodKey: MAGIC_LINK_METHOD_KEY,
    })
    expect(flow.screenKey.get()).toBe('terms')
    expect(onMethodAction).not.toHaveBeenCalled()
  })

  it("consent's submit is the send for the chosen method", async () => {
    const onMethodAction = vi.fn(async () => ({
      ok: true,
      resendAt: Date.now() + 60 * SECOND_MS,
    }))
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({
      onMethodAction,
      onSubmit,
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    flow.setField('consentAccepted', true)
    await flow.submit()
    expect(onMethodAction).toHaveBeenCalledWith(
      MAGIC_LINK_METHOD_KEY,
      expect.objectContaining({ consentAccepted: true }),
      expect.any(AbortSignal),
    )
    expect(onSubmit).not.toHaveBeenCalled()
    expect(flow.flow.get().step).toBe('external')
    expect(flow.screenKey.get()).toBe('check_inbox')
    expect(flow.resendAvailableAt.get()).toBe(Date.now() + 60 * SECOND_MS)
  })

  it('a refused send keeps the terms screen and says why', async () => {
    const flow = setup({
      onMethodAction: async () => ({
        ok: false,
        code: 'send_cap_reached',
        message: 'Too many codes',
      }),
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    flow.setField('consentAccepted', true)
    await flow.submit()
    expect(flow.flow.get().step).toBe('consent')
    expect(flow.error.get()).toEqual({
      message: 'Too many codes',
      code: 'send_cap_reached',
    })
  })

  it('a signing-in envelope still sends at once', async () => {
    const onMethodAction = vi.fn(async () => ({ ok: true }))
    const flow = setup({ onMethodAction })
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.flow.get().intent).toBe('login')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    expect(onMethodAction).toHaveBeenCalledWith(
      MAGIC_LINK_METHOD_KEY,
      expect.anything(),
      expect.any(AbortSignal),
    )
    expect(flow.flow.get().step).toBe('external')
    expect(flow.screenKey.get()).toBe('check_inbox')
  })
})

describe('code channels: choosing the channel IS the send', () => {
  async function phoneFlow(onSubmit: AuthFlowOptions['onSubmit']) {
    const flow = setup({
      onSubmit,
      onDetect: async (identifier) =>
        detected({
          identifier,
          normalized: identifier,
          kind: 'phone',
          methods: [],
        }),
    })
    await typeAndDetect(flow, '+79991234567')

    return flow
  }

  it('puts the key into the state and dispatches; the backend moves to code', async () => {
    const onSubmit = vi.fn(async () => ({
      ok: true,
      next: { step: 'code' as const },
      resendAt: Date.now() + 60 * SECOND_MS,
    }))
    const flow = await phoneFlow(onSubmit)
    await flow.chooseChannel('sms')
    expect(onSubmit).toHaveBeenCalledWith(
      'submit',
      expect.objectContaining({ channelKey: 'sms' }),
      expect.anything(),
    )
    expect(flow.flow.get()).toMatchObject({ step: 'code', channelKey: 'sms' })
    expect(flow.screenKey.get()).toBe('enter_code')
  })

  it('ignores a channel outside the applicable registry', async () => {
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = await phoneFlow(onSubmit)
    await flow.chooseChannel('carrier_pigeon')
    expect(onSubmit).not.toHaveBeenCalled()
    expect(flow.flow.get().channelKey).toBeNull()
  })

  it('honors the resend cooldown: switching channels does not re-send', async () => {
    const onSubmit = vi.fn(async () => ({
      ok: true,
      next: { step: 'code' as const },
      resendAt: Date.now() + 30 * SECOND_MS,
    }))
    const flow = await phoneFlow(onSubmit)
    await flow.chooseChannel('sms')
    await flow.chooseChannel('telegram')
    expect(onSubmit).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(31 * SECOND_MS)
    await flow.chooseChannel('telegram')
    expect(onSubmit).toHaveBeenCalledTimes(2)
  })
})

describe('resend gate', () => {
  it('blocks resend() until the backend-allowed moment, then re-arms', async () => {
    const onSubmit = vi.fn(async () => ({
      ok: true,
      next: { step: 'code' as const },
      resendAt: Date.now() + 30 * SECOND_MS,
    }))
    const flow = setup({
      onSubmit,
      onDetect: async (identifier) =>
        detected({
          identifier,
          normalized: identifier,
          kind: 'phone',
          methods: [],
        }),
    })
    await typeAndDetect(flow, '+79991234567')
    await flow.chooseChannel('sms')
    expect(flow.resendAvailableAt.get()).toBe(Date.now() + 30 * SECOND_MS)
    await flow.resend()
    expect(onSubmit).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(31 * SECOND_MS)
    await flow.resend()
    expect(onSubmit).toHaveBeenCalledTimes(2)
    expect(onSubmit).toHaveBeenLastCalledWith(
      'resend',
      expect.anything(),
      expect.anything(),
    )
  })

  it('a ceremony outcome arms the gate too (a magic-link send)', async () => {
    const flow = setup({
      onMethodAction: async () => ({
        ok: true,
        next: { step: 'external' as const },
        resendAt: Date.now() + 60 * SECOND_MS,
      }),
    })
    await typeAndDetect(flow, 'a@b.com')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    expect(flow.resendAvailableAt.get()).toBe(Date.now() + 60 * SECOND_MS)
  })
})

describe('second factor', () => {
  it('carries the backup-code and trust-device flags into the dispatch', async () => {
    const onSubmit = vi
      .fn<AuthFlowOptions['onSubmit']>()
      .mockResolvedValueOnce({ ok: true, next: { step: 'second_factor' } })
      .mockResolvedValue({ ok: true })
    const flow = setup({ onSubmit })
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('password', 'secret-1')
    await flow.submit()
    expect(flow.flow.get().step).toBe('second_factor')
    expect(flow.screenKey.get()).toBe('two_step')
    flow.setField('code', 'ABCD-1234')
    flow.setField('usingBackupCode', true)
    flow.setField('trustDevice', true)
    await flow.submit()
    expect(onSubmit).toHaveBeenLastCalledWith(
      'submit',
      expect.objectContaining({ step: 'second_factor' }),
      expect.objectContaining({
        code: 'ABCD-1234',
        usingBackupCode: true,
        trustDevice: true,
      }),
    )
  })
})

describe('the two return points and cancelMethod', () => {
  it('backToIdentifier from consent keeps everything typed', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    flow.setField('password', 'x'.repeat(PASSWORD_MIN_LENGTH))
    await flow.submit()
    flow.backToIdentifier()
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      intent: 'register',
    })
    expect(flow.form.get()).toMatchObject({
      identifier: 'new@b.com',
      password: 'x'.repeat(PASSWORD_MIN_LENGTH),
    })
  })

  it('backToIdentifier from the registration code screen ("Not that address?")', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'pending', methods: [] }),
    })
    await typeAndDetect(flow, 'reserved@b.com')
    expect(flow.flow.get().step).toBe('code')
    flow.backToIdentifier()
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.form.get().identifier).toBe('reserved@b.com')
  })

  it('cancelMethod abandons the parked ceremony and returns to the field', async () => {
    const flow = setup({
      onMethodAction: () => new Promise<AuthFlowSubmitOutcome>(() => undefined),
    })
    void flow.chooseMethod('passkey')
    expect(flow.flow.get()).toMatchObject({
      step: 'external',
      methodKey: 'passkey',
    })
    flow.cancelMethod()
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      methodKey: null,
    })
  })

  it('cancelMethod aborts the ceremony ITSELF — the driver is told, not just forgotten', () => {
    const aborted: string[] = []
    const flow = setup({
      onMethodAction: (key, _form, signal) => {
        signal.addEventListener('abort', () => aborted.push(key))

        return new Promise<AuthFlowSubmitOutcome>(() => undefined)
      },
    })
    void flow.chooseMethod('passkey')
    expect(aborted).toEqual([])
    flow.cancelMethod()
    expect(aborted).toEqual(['passkey'])
  })

  it('cancelMethod reaches the send a registration starts from the terms screen', async () => {
    const aborted: string[] = []
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
      onMethodAction: (key, _form, signal) => {
        signal.addEventListener('abort', () => aborted.push(key))

        return new Promise<AuthFlowSubmitOutcome>(() => undefined)
      },
    })
    await typeAndDetect(flow, 'new@b.com')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    flow.setField('consentAccepted', true)
    void flow.submit()
    expect(aborted).toEqual([])
    flow.cancelMethod()
    expect(aborted).toEqual([MAGIC_LINK_METHOD_KEY])
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      methodKey: null,
    })
    expect(flow.pending.get()).toBe(false)
  })

  it('cancelMethod on the terms screen with nothing sent yet does nothing — that is Back', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    flow.cancelMethod()
    expect(flow.flow.get()).toMatchObject({
      step: 'consent',
      methodKey: MAGIC_LINK_METHOD_KEY,
    })
  })

  it('cancelMethod releases pending — the controls come back to life', async () => {
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({
      onSubmit,
      // An abandoned popup: the ceremony promise never settles at all.
      onMethodAction: () => new Promise<{ ok: boolean }>(() => undefined),
    })
    await typeAndDetect(flow, 'a@b.com')
    void flow.chooseMethod('passkey')
    expect(flow.pending.get()).toBe(true)
    flow.cancelMethod()
    expect(flow.pending.get()).toBe(false)
    flow.setField('password', 'secret-1')
    await flow.submit()
    expect(onSubmit).toHaveBeenCalledTimes(1)
  })
})

describe('a canceled ceremony that settles anyway', () => {
  /**
   * Run the whole late-outcome sequence: park an icon ceremony, cancel it, wait
   * out `afterMs`, then let the abandoned ceremony resolve.
   *
   * @param outcome What the abandoned ceremony finally resolves to.
   * @param afterMs How long after the cancel it lands.
   * @param registering Whether the flow is registering rather than signing in.
   * @returns The settled machine.
   */
  async function lateOutcome(
    outcome: AuthFlowSubmitOutcome,
    afterMs: number,
    registering = false,
  ): Promise<ReturnType<typeof createAuthFlow>> {
    let release: (value: AuthFlowSubmitOutcome) => void = () => undefined
    const flow = setup({
      onDetect: async (identifier) =>
        detected(registering ? { identifier, status: 'none' } : { identifier }),
      onMethodAction: () =>
        new Promise<AuthFlowSubmitOutcome>((resolve) => {
          release = resolve
        }),
    })
    if (registering) {
      await typeAndDetect(flow, 'new@b.com')
      expect(flow.flow.get().intent).toBe('register')
    }
    // A registration's ceremony starts where the terms are ACCEPTED, not where
    // the method is picked (HIL-417): the choice only hops to consent, and it
    // has to be awaited first — it settles without a dispatch of its own.
    let ceremony = flow.chooseMethod('passkey')
    if (registering) {
      await ceremony
      ceremony = flow.submit()
    }
    flow.cancelMethod()
    await vi.advanceTimersByTimeAsync(afterMs)
    release(outcome)
    await ceremony

    return flow
  }

  it('a LOGIN success inside the window is applied — Cancel, then the finger lands', async () => {
    const flow = await lateOutcome(
      { ok: true, next: { step: 'done' } },
      DEFAULT_EXTERNAL_CANCEL_GRACE_MS,
    )
    expect(flow.flow.get().step).toBe('done')
  })

  it('a LOGIN success past the window is not — that gesture belonged to another moment', async () => {
    const flow = await lateOutcome(
      { ok: true, next: { step: 'done' } },
      DEFAULT_EXTERNAL_CANCEL_GRACE_MS + 1,
    )
    expect(flow.flow.get().step).toBe('identifier')
  })

  it('a REGISTER success is dropped however fresh — the refused account is not undoable', async () => {
    const flow = await lateOutcome(
      { ok: true, next: { step: 'done' } },
      0,
      true,
    )
    expect(flow.flow.get().step).toBe('identifier')
  })

  it('a reset ends the ceremony and outlasts its grace window', async () => {
    const aborted: string[] = []
    let release: (value: AuthFlowSubmitOutcome) => void = () => undefined
    const flow = setup({
      onMethodAction: (key, _form, signal) => {
        signal.addEventListener('abort', () => aborted.push(key))

        return new Promise<AuthFlowSubmitOutcome>((resolve) => {
          release = resolve
        })
      },
    })
    const choice = flow.chooseMethod('passkey')
    flow.cancelMethod()
    // A (re)mount is a stronger forget than a cancel: the device dialog closes
    // and the freshly emptied flow takes nothing from the old ceremony.
    flow.reset()
    expect(aborted).toEqual(['passkey'])
    release({ ok: true, next: { step: 'done' } })
    await choice
    expect(flow.flow.get().step).toBe('identifier')
  })

  it('a late FAILURE is noise under either intent — no error surfaces', async () => {
    const failure: AuthFlowSubmitOutcome = { ok: false, message: 'Timed out' }
    const signingIn = await lateOutcome(failure, 0)
    expect(signingIn.flow.get().step).toBe('identifier')
    expect(signingIn.error.get()).toBeNull()
    const registering = await lateOutcome(failure, 0, true)
    expect(registering.flow.get().step).toBe('identifier')
    expect(registering.error.get()).toBeNull()
  })
})

describe('dispatch generations', () => {
  it('an identifier edit orphans an in-flight submit: no merge, no pending leak', async () => {
    let release: (outcome: {
      ok: boolean
      next?: Partial<AuthFlowState>
    }) => void = () => undefined
    const flow = setup({
      onSubmit: () =>
        new Promise((resolve) => {
          release = resolve
        }),
    })
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('password', 'secret-1')
    const submit = flow.submit()
    expect(flow.pending.get()).toBe(true)
    flow.setField('identifier', 'other@b.com')
    expect(flow.pending.get()).toBe(false)
    release({ ok: true, next: { step: 'done' } })
    await submit
    // The stale outcome belongs to the abandoned identifier: nothing merged.
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.pending.get()).toBe(false)
  })
})

describe('startRecovery', () => {
  it('moves locally to the recovery code screen', async () => {
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({ onSubmit })
    await typeAndDetect(flow, 'a@b.com')
    flow.startRecovery()
    expect(flow.flow.get()).toMatchObject({ step: 'code', intent: 'recovery' })
    expect(flow.screenKey.get()).toBe('reset_code')
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('starts the challenge clean: a foreign code never pre-fills it', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'pending', methods: [] }),
    })
    await typeAndDetect(flow, 'reserved@b.com')
    flow.setField('code', '111111')
    flow.backToIdentifier()
    flow.startRecovery()
    expect(flow.form.get().code).toBe('')
    expect(flow.submittable.get()).toBe(false)
    expect(flow.form.get().identifier).toBe('reserved@b.com')
  })
})

describe('applyExternal — the converge entry', () => {
  it('merges a partial state atomically, like a backend next', () => {
    const flow = setup()
    flow.applyExternal({ step: 'second_factor', intent: 'login' })
    expect(flow.flow.get()).toMatchObject({
      step: 'second_factor',
      intent: 'login',
      methodKey: null,
    })
  })

  it('clears the shown error', async () => {
    const flow = setup({
      onSubmit: async () => ({ ok: false, message: 'Wrong password' }),
    })
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('password', 'wrong')
    await flow.submit()
    expect(flow.error.get()).toEqual({ message: 'Wrong password', code: null })
    flow.applyExternal({ step: 'done' })
    expect(flow.error.get()).toBeNull()
  })

  it('never touches the form', async () => {
    const flow = setup()
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('password', 'secret-1')
    flow.applyExternal({ step: 'second_factor' })
    expect(flow.form.get()).toMatchObject({
      identifier: 'a@b.com',
      password: 'secret-1',
    })
  })

  it("rebuilds any step under the user's hands", async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'pending', methods: [] }),
    })
    await typeAndDetect(flow, 'reserved@b.com')
    expect(flow.flow.get().step).toBe('code')
    // The reservation completed in another tab — this surface converges.
    flow.applyExternal({ step: 'done' })
    expect(flow.screenKey.get()).toBe('done_registered')
  })
})

describe('input preservation', () => {
  it('only an identifier CHANGE clears the form; re-setting the same value is a no-op', async () => {
    const flow = setup()
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('password', 'secret-1')
    flow.setField('identifier', 'a@b.com')
    expect(flow.form.get().password).toBe('secret-1')
    expect(flow.detection.get().status).toBe('resolved')
    flow.setField('identifier', 'other@b.com')
    expect(flow.form.get().password).toBe('')
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      intent: 'login',
    })
  })

  it('stepping forward never clears what was typed', async () => {
    const flow = setup({
      onSubmit: async () => ({
        ok: true,
        next: { step: 'second_factor' as const },
      }),
    })
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('password', 'secret-1')
    await flow.submit()
    expect(flow.form.get().password).toBe('secret-1')
  })
})

describe('failure surface', () => {
  it('surfaces the backend message and semantic code, inventing no text', async () => {
    const flow = setup({
      onSubmit: async () => ({ ok: false, code: 'rate_limited' }),
    })
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('password', 'secret-1')
    await flow.submit()
    expect(flow.error.get()).toEqual({ message: null, code: 'rate_limited' })
  })

  it('a failed ceremony falls back to the identifier field', async () => {
    const flow = setup({
      onMethodAction: async () => ({ ok: false, message: 'Ceremony failed' }),
    })
    await flow.chooseMethod('passkey')
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      methodKey: null,
    })
    expect(flow.error.get()).toEqual({ message: 'Ceremony failed', code: null })
  })
})

describe('method-set-agnostic', () => {
  it('password only: no icons anywhere, the machine still flows', async () => {
    const flow = setup({ methods: [PASSWORD_FLOW_METHOD] })
    expect(flow.icons.get()).toEqual([])
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.primaryAction.get()).toEqual({ kind: 'submit' })
  })

  it('icons only: a passwordless account still gets a primary method', async () => {
    const flow = setup({
      methods: [OAUTH_GITHUB_FLOW_METHOD, MAGIC_LINK_FLOW_METHOD],
      onDetect: async (identifier) =>
        detected({ identifier, methods: [MAGIC_LINK_METHOD_KEY] }),
    })
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.primaryAction.get()).toEqual({
      kind: 'method',
      key: MAGIC_LINK_METHOD_KEY,
    })
  })

  it('an empty channel registry leaves a phone with nothing to press, not a crash', async () => {
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({
      channels: [],
      onSubmit,
      onDetect: async (identifier) =>
        detected({
          identifier,
          normalized: identifier,
          kind: 'phone',
          methods: [],
        }),
    })
    await typeAndDetect(flow, '+79991234567')
    expect(flow.channels.get()).toEqual([])
    expect(flow.primaryAction.get()).toBeNull()
    await flow.chooseChannel('sms')
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('an empty method registry never throws', async () => {
    const flow = setup({
      methods: [],
      onDetect: async (identifier) =>
        detected({ identifier, methods: [MAGIC_LINK_METHOD_KEY] }),
    })
    expect(flow.icons.get()).toEqual([])
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.primaryAction.get()).toBeNull()
    await flow.chooseMethod('passkey')
    expect(flow.flow.get().step).toBe('identifier')
  })
})

describe('reset', () => {
  it('returns to the initial state with an empty form and idle detection', async () => {
    const flow = setup()
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('password', 'secret-1')
    flow.reset()
    expect(flow.flow.get()).toEqual(INITIAL_FLOW)
    expect(flow.form.get()).toEqual(EMPTY_FORM)
    expect(flow.detection.get()).toEqual({ status: 'idle', result: null })
    expect(flow.error.get()).toBeNull()
    expect(flow.resendAvailableAt.get()).toBeNull()
  })
})

describe('resuming an unfinished registration', () => {
  it('parks on the code screen with the identifier and the expiry back', () => {
    const flow = setup()
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      channel: null,
      expiresAt: Date.now() + 10 * SECOND_MS,
    })
    expect(flow.flow.get()).toMatchObject({
      step: 'code',
      intent: 'register',
      identifierKind: 'email',
      channelKey: null,
    })
    expect(flow.form.get().identifier).toBe('ada@b.com')
    expect(flow.expiresAt.get()).toBe(Date.now() + 10 * SECOND_MS)
    expect(flow.screenKey.get()).toBe('confirm_identifier')
  })

  it('names the channel a phone code went over', () => {
    const flow = setup()
    flow.resume({
      identifier: '+79991234567',
      kind: 'phone',
      channel: 'telegram',
      expiresAt: Date.now() + 10 * SECOND_MS,
    })
    expect(flow.flow.get()).toMatchObject({
      identifierKind: 'phone',
      channelKey: 'telegram',
    })
  })

  it('does nothing when the session has no unfinished registration', async () => {
    // A reconnect lands while somebody is typing: a handshake with nothing to
    // say must not take away what they are doing.
    const flow = setup()
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('password', 'secret-1')
    flow.resume(null)
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.form.get().password).toBe('secret-1')
    expect(flow.expiresAt.get()).toBeNull()
  })

  it('drops the countdown when the identifier is edited', () => {
    const flow = setup()
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      channel: null,
      expiresAt: Date.now() + 10 * SECOND_MS,
    })
    flow.setField('identifier', 'other@b.com')
    expect(flow.expiresAt.get()).toBeNull()
  })

  it('starts the countdown off a submit that just sent something', async () => {
    // The other half of the same fact: a resume is how a RETURNING tab learns the
    // moment, and this is how the tab that asked for the code learns it.
    const flow = setup({
      onSubmit: async () => ({
        ok: true,
        next: { step: 'code' as const },
        expiresAt: Date.now() + 10 * SECOND_MS,
      }),
    })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    expect(flow.flow.get().step).toBe('code')
    expect(flow.expiresAt.get()).toBe(Date.now() + 10 * SECOND_MS)
  })

  it('leaves a running countdown alone when an outcome names no moment', async () => {
    // A mistyped code is answered without one, and the screen it lands on is still
    // counting down the very code being retyped.
    const flow = setup({
      onSubmit: async () => ({ ok: false, message: 'Wrong code' }),
    })
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      channel: null,
      expiresAt: Date.now() + 10 * SECOND_MS,
    })
    flow.setField('code', '000000')
    await flow.submit()
    expect(flow.expiresAt.get()).toBe(Date.now() + 10 * SECOND_MS)
  })
})
