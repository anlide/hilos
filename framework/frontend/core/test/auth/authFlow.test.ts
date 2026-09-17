// Covers the identifier-first auth flow machine (HIL-413): identifier
// classification, the icon visibility matrix (the CHECK examples), the
// debounced echo-guarded detection with NO degraded state, the intent
// derivation (none/pending/active), the consent-as-local-step registration, the
// channel-is-the-send code path, the resend gate, the second factor, the two
// return points plus cancelMethod, the applyExternal converge, screenKey on all
// fourteen screens, primaryAction, input preservation, and the
// method-set-agnostic guarantee. HIL-418 adds the cancel that reaches into the
// ceremony (the abort) and the intent-judged fate of an outcome that lands after
// it. HIL-419 wires TWO providers through the descriptor factory, so the matrix
// answers for a row rather than for a single button. HIL-651 adds the lookup a
// RETURN to the identifier step fires — the held-address screen it draws and the
// step it deliberately does not move. HIL-646 adds the reveal a typed re-ask
// holds on to, the submit that is muted for as long as it does, and the two
// places that deliberately keep nothing (the return and a failed lookup).
// HIL-973 adds the answer the icon row and a found number's channel choice now
// ask, and the proven reply the lookup schema lets through.
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
  oauthFlowMethod,
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
import { createAuthActions, toFlowPatch } from '../../src/auth/authActions.js'
import { type HilosAuthContext } from '../../src/auth/authContext.js'

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
  sendProgress: null,
}

/**
 * The two providers this file's fixture wires, in the order a project's registry
 * would hand them over — which is the order the icon row draws them in.
 */
const OAUTH_GITHUB_FLOW_METHOD = oauthFlowMethod(
  'oauth:github',
  'Continue with GitHub',
)
const OAUTH_GOOGLE_FLOW_METHOD = oauthFlowMethod(
  'oauth:google',
  'Continue with Google',
)

const ALL_METHODS = [
  PASSWORD_FLOW_METHOD,
  OAUTH_GITHUB_FLOW_METHOD,
  OAUTH_GOOGLE_FLOW_METHOD,
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
    registrationBlock: null,
    signInBlock: null,
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

/**
 * Let the lookup a RETURN to the identifier step fires settle. It carries no
 * debounce (HIL-651), so there is no window to advance — only the reply's own
 * microtasks to drain.
 */
async function settleRefresh(): Promise<void> {
  await vi.advanceTimersByTimeAsync(0)
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

describe('oauthFlowMethod — the shape every provider button shares', () => {
  it('carries the key and label it is given and nothing else per provider', () => {
    expect(OAUTH_GOOGLE_FLOW_METHOD.key).toBe('oauth:google')
    expect(OAUTH_GOOGLE_FLOW_METHOD.label).toBe('Continue with Google')
  })

  it('places every provider in the icon row, on login only, on an empty field', () => {
    for (const method of [OAUTH_GITHUB_FLOW_METHOD, OAUTH_GOOGLE_FLOW_METHOD]) {
      expect(method.kind).toBe('icon')
      expect(method.placement).toBe('icon_row')
      expect(method.intents).toEqual(['login'])
      expect(method.visibility).toEqual({ whenEmpty: true, whenTyping: false })
    }
  })
})

describe('visibleMethodIcons — the matrix on the four input states', () => {
  it('empty field: whenEmpty icons only (both providers, passkey); magic link hidden', () => {
    expect(
      visibleMethodIcons(ALL_METHODS, '', 'unknown', 'login', null),
    ).toEqual([
      OAUTH_GITHUB_FLOW_METHOD,
      OAUTH_GOOGLE_FLOW_METHOD,
      PASSKEY_FLOW_METHOD,
    ])
  })

  it('typing an unrecognized value hides the whole row', () => {
    expect(
      visibleMethodIcons(ALL_METHODS, 'john doe', 'unknown', 'login', null),
    ).toEqual([])
  })

  it('typing an email: magic link only (both providers and passkey vanish on typing)', () => {
    expect(
      visibleMethodIcons(ALL_METHODS, 'a@b.com', 'email', 'login', null),
    ).toEqual([MAGIC_LINK_FLOW_METHOD])
  })

  it('typing a phone: nothing — the providers vanish here too, magic link is email-only', () => {
    expect(
      visibleMethodIcons(ALL_METHODS, '+79991234567', 'phone', 'login', null),
    ).toEqual([])
  })

  it('honors descriptor intents: a login-only icon hides under register', () => {
    expect(
      visibleMethodIcons(ALL_METHODS, '', 'unknown', 'register', null),
    ).toEqual([PASSKEY_FLOW_METHOD])
  })

  it('honors descriptor-level identifierKinds while typing', () => {
    const smsIcon = {
      key: 'sms_icon',
      label: 'Code by SMS',
      kind: 'icon',
      identifierKinds: ['phone'],
      visibility: { whenTyping: true },
    } as const
    expect(
      visibleMethodIcons([smsIcon], 'a@b.com', 'email', 'login', null),
    ).toEqual([])
    expect(
      visibleMethodIcons([smsIcon], '+79991234567', 'phone', 'login', null),
    ).toEqual([smsIcon])
  })

  it('a resolved reply that does not name the link darkens the envelope (HIL-973)', () => {
    expect(
      visibleMethodIcons(ALL_METHODS, 'a@b.com', 'email', 'login', [
        'password',
      ]),
    ).toEqual([])
    expect(
      visibleMethodIcons(ALL_METHODS, 'a@b.com', 'email', 'login', [
        'password',
        MAGIC_LINK_METHOD_KEY,
      ]),
    ).toEqual([MAGIC_LINK_FLOW_METHOD])
  })

  it('an empty field asks no reply: its icons name no identifier', () => {
    expect(visibleMethodIcons(ALL_METHODS, '', 'unknown', 'login', [])).toEqual(
      [OAUTH_GITHUB_FLOW_METHOD, OAUTH_GOOGLE_FLOW_METHOD, PASSKEY_FLOW_METHOD],
    )
  })
})

describe('a live account on an installation that cannot send (HIL-973)', () => {
  it('the machine hides the envelope from an account the reply names no link for', async () => {
    const flow = setup()
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.icons.get()).toEqual([])
  })

  it('the machine keeps the envelope for an account the reply names it for', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, methods: ['password', MAGIC_LINK_METHOD_KEY] }),
    })
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.icons.get()).toEqual([MAGIC_LINK_FLOW_METHOD])
  })

  it('a found number with nothing to sign in with gets no channel to press', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({
          identifier,
          normalized: identifier,
          kind: 'phone',
          methods: [],
          signInBlock: 'no_channel',
        }),
    })
    await typeAndDetect(flow, '+79991234567')
    expect(flow.primaryAction.get()).toBeNull()
    expect(flow.submittable.get()).toBe(false)
  })

  it('an address with nothing to sign in with gets no primary action', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, methods: [], signInBlock: 'no_channel' }),
    })
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.primaryAction.get()).toBeNull()
    expect(flow.submittable.get()).toBe(false)
  })
})

describe('the lookup reply schema', () => {
  /**
   * A context whose dispatch validates the given wire reply with the schema the
   * lookup hands it, and answers with the parsed value.
   *
   * @param wire The reply as the backend sends it.
   */
  function contextReplying(wire: unknown): HilosAuthContext {
    return {
      actions: {
        dispatch: (
          _name: string,
          _payload: unknown,
          options: { replySchema: { parse(value: unknown): unknown } },
        ) => ({
          done: Promise.resolve({ reply: options.replySchema.parse(wire) }),
        }),
      },
    } as unknown as HilosAuthContext
  }

  const wireReply = {
    identifier: 'a@b.com',
    normalized: 'a@b.com',
    kind: 'email',
    methods: [],
    registerable: [],
    registrationBlock: null,
    signInBlock: null,
  }

  it('lets a proven reply through (HIL-825), which the backend does send', async () => {
    const actions = createAuthActions(
      contextReplying({ ...wireReply, status: 'proven' }),
    )
    await expect(actions.onDetect('a@b.com')).resolves.toMatchObject({
      status: 'proven',
    })
  })

  it('carries the reason an account is offered no way in', async () => {
    const actions = createAuthActions(
      contextReplying({
        ...wireReply,
        status: 'active',
        signInBlock: 'no_channel',
      }),
    )
    await expect(actions.onDetect('a@b.com')).resolves.toMatchObject({
      signInBlock: 'no_channel',
    })
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

  it('identifier register: the answer about the address is the whole gate', () => {
    // No password is measured here since HIL-825 — the step asks for the
    // address alone, and a password is chosen after the code proves it.
    const flow = {
      ...INITIAL_FLOW,
      intent: 'register' as const,
      identifierKind: 'email' as const,
    }
    const form = { ...EMPTY_FORM, identifier: 'new@b.com' }
    const free = resolved(
      detected({ identifier: 'new@b.com', status: 'none', methods: [] }),
    )
    expect(isFlowSubmittable(flow, form, free)).toBe(true)
    const closed = resolved(
      detected({
        identifier: 'new@b.com',
        status: 'none',
        methods: [],
        registerable: [],
        registrationBlock: 'closed',
      }),
    )
    expect(isFlowSubmittable(flow, form, closed)).toBe(false)
  })

  it('identifier: a held reply does not submit while the re-ask is in flight', () => {
    // The one price of holding the reveal across a lookup (HIL-646): the form
    // must not go out on a verdict the machine is already re-asking about.
    const flow = { ...INITIAL_FLOW, identifierKind: 'email' as const }
    const form = { ...EMPTY_FORM, identifier: 'a@b.com', password: 'secret-1' }
    const held: DetectionState = { status: 'pending', result: detected() }
    expect(isFlowSubmittable(flow, form, held)).toBe(false)
    expect(isFlowSubmittable(flow, form, resolved(detected()))).toBe(true)
  })

  it('identifier proven: the way on is the primary action, not the submit', () => {
    const flow = {
      ...INITIAL_FLOW,
      intent: 'register' as const,
      identifierKind: 'email' as const,
    }
    const form = { ...EMPTY_FORM, identifier: 'proved@b.com' }
    const state = resolved(
      detected({
        identifier: 'proved@b.com',
        status: 'proven',
        methods: [],
        registerable: [],
      }),
    )
    expect(isFlowSubmittable(flow, form, state)).toBe(false)
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
          methods: ['sms'],
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

describe('the reveal is held across a new lookup (HIL-646)', () => {
  /** A lookup answering "account" for one address and "free" for any other. */
  function borderLookup() {
    return vi.fn(async (identifier: string) =>
      identifier === 'a@b.com'
        ? detected({ identifier })
        : detected({ identifier, status: 'none', methods: [] }),
    )
  }

  it('keeps the previous reply on screen until the new one lands', async () => {
    // The defect itself: editing a character across the "account exists ↔ free"
    // border used to redraw the form TWICE — once to nothing while the lookup
    // ran, once to the new composition.
    const flow = setup({ onDetect: borderLookup() })
    await typeAndDetect(flow, 'a@b.com')
    expect(flow.detection.get()).toEqual({
      status: 'resolved',
      result: detected({ identifier: 'a@b.com' }),
    })
    flow.setField('identifier', 'a@b.co')
    expect(flow.detection.get()).toEqual({
      status: 'pending',
      result: detected({ identifier: 'a@b.com' }),
    })
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS)
    expect(flow.detection.get()).toEqual({
      status: 'resolved',
      result: detected({ identifier: 'a@b.co', status: 'none', methods: [] }),
    })
  })

  it('mutes the submit while the held reveal is being re-asked about', async () => {
    // A free address submits on the answer alone (HIL-825), so only the status
    // gate can stop it going out on the held verdict.
    const flow = setup({ onDetect: borderLookup() })
    await typeAndDetect(flow, 'free@b.com')
    expect(flow.submittable.get()).toBe(true)
    flow.setField('identifier', 'free2@b.com')
    expect(flow.detection.get().result?.identifier).toBe('free@b.com')
    expect(flow.submittable.get()).toBe(false)
    await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS)
    expect(flow.submittable.get()).toBe(true)
  })

  it('drops the held reply when the new lookup fails', async () => {
    // No degraded state (rules-and-violations §A): a reply nobody confirmed
    // must not keep a reveal alive that the machine can no longer stand behind.
    let fail = false
    const flow = setup({
      onDetect: async (identifier) => {
        if (fail) {
          throw new Error('transport down')
        }

        return detected({ identifier })
      },
    })
    await typeAndDetect(flow, 'a@b.com')
    fail = true
    await typeAndDetect(flow, 'a@b.co')
    expect(flow.detection.get()).toEqual({ status: 'idle', result: null })
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

  it('a free address is submittable the moment the lookup answers — no password there any more', async () => {
    // HIL-825 took the password off the identifier step: registration asks for
    // one after the code, so there is nothing here to measure and the button
    // lives on the ANSWER about the address alone.
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    expect(flow.form.get().password).toBe('')
    expect(flow.submittable.get()).toBe(true)
    expect(flow.primaryAction.get()).toEqual({ kind: 'submit' })
  })

  it('a proved reservation goes to the password screen WITHOUT a send', async () => {
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({
      onSubmit,
      onDetect: async (identifier) =>
        detected({
          identifier,
          status: 'proven',
          methods: [],
          registerable: [],
        }),
    })
    await typeAndDetect(flow, 'proved@b.com')
    expect(flow.flow.get()).toMatchObject({
      step: 'set_password',
      intent: 'register',
    })
    expect(flow.screenKey.get()).toBe('set_first_password')
    expect(onSubmit).not.toHaveBeenCalled()
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

describe('primaryAction — the six shapes', () => {
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
          methods: ['sms'],
        }),
    })
    await typeAndDetect(flow, '+79991234567')
    expect(flow.primaryAction.get()).toEqual({ kind: 'channel', key: 'sms' })
  })

  it('resume_password: an address this browser has already proved', async () => {
    const flow = setup({
      onDetect: async (identifier) =>
        detected({
          identifier,
          status: 'proven',
          methods: [],
          registerable: [],
        }),
    })
    await typeAndDetect(flow, 'proved@b.com')
    flow.backToIdentifier()
    await settleRefresh()
    expect(flow.screenKey.get()).toBe('proven_identifier')
    expect(flow.primaryAction.get()).toEqual({ kind: 'resume_password' })
    expect(flow.submittable.get()).toBe(false)
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

describe('screenKey — all sixteen screens', () => {
  it('derives every screen from the axes', () => {
    // The third element is the resolved lookup: only the identifier step
    // consults one, and only a `pending` (HIL-651) or `proven` (HIL-825) reply
    // changes what it draws.
    const cases: ReadonlyArray<
      [Partial<AuthFlowState>, AuthFlowScreen, IdentifierDetection?]
    > = [
      [{}, 'sign_in'],
      [{ intent: 'register' }, 'create_account'],
      [
        { intent: 'register' },
        'held_identifier',
        detected({ status: 'pending', methods: [] }),
      ],
      [
        { intent: 'register' },
        'proven_identifier',
        detected({ status: 'proven', methods: [], registerable: [] }),
      ],
      [{ step: 'consent', intent: 'register' }, 'terms'],
      [{ step: 'code', intent: 'register' }, 'confirm_identifier'],
      [{ step: 'code', intent: 'login' }, 'enter_code'],
      [{ step: 'code', intent: 'recovery' }, 'reset_code'],
      [
        { step: 'code', intent: 'register', methodKey: MAGIC_LINK_METHOD_KEY },
        'check_inbox',
      ],
      [
        { step: 'code', intent: 'login', methodKey: MAGIC_LINK_METHOD_KEY },
        'check_inbox',
      ],
      [{ step: 'second_factor', intent: 'login' }, 'two_step'],
      [{ step: 'set_password', intent: 'recovery' }, 'choose_password'],
      [{ step: 'set_password', intent: 'register' }, 'set_first_password'],
      [{ step: 'external', methodKey: 'oauth:github' }, 'waiting_external'],
      [{ step: 'external', methodKey: MAGIC_LINK_METHOD_KEY }, 'check_inbox'],
      [{ step: 'done', intent: 'register' }, 'done_registered'],
      [{ step: 'done', intent: 'recovery' }, 'done_password_changed'],
      [{ step: 'done', intent: 'login' }, 'done_signed_in'],
    ]
    for (const [partial, expected, result] of cases) {
      expect(screenKeyOf({ ...INITIAL_FLOW, ...partial }, result)).toBe(
        expected,
      )
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
    expect(flow.flow.get().step).toBe('code')
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
    expect(flow.flow.get().step).toBe('code')
    expect(flow.screenKey.get()).toBe('check_inbox')
  })

  it('a sent letter leaves the code field empty, whatever was in it before', async () => {
    const flow = setup({ onMethodAction: vi.fn(async () => ({ ok: true })) })
    await typeAndDetect(flow, 'a@b.com')
    flow.setField('code', '999999')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    expect(flow.form.get().code).toBe('')
    expect(flow.submittable.get()).toBe(false)
  })

  it('a send in flight still parks in external, where a cancel can end it', async () => {
    let release: () => void = () => undefined
    const sending = new Promise<AuthFlowSubmitOutcome>((resolve) => {
      release = () => {
        resolve({ ok: true })
      }
    })
    const flow = setup({ onMethodAction: vi.fn(async () => sending) })
    await typeAndDetect(flow, 'a@b.com')
    const choosing = flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    expect(flow.flow.get().step).toBe('external')
    expect(flow.screenKey.get()).toBe('check_inbox')
    release()
    await choosing
    expect(flow.flow.get().step).toBe('code')
  })

  it('a code the backend refuses leaves the waiting screen exactly where it is', async () => {
    const onSubmit = vi.fn(async () => ({
      ok: false,
      code: 'magic_link_invalid',
      message: 'That code did not work',
    }))
    const flow = setup({
      onSubmit,
      onMethodAction: vi.fn(async () => ({ ok: true })),
    })
    await typeAndDetect(flow, 'a@b.com')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    flow.setField('code', '000001')
    await flow.submit()
    expect(onSubmit).toHaveBeenCalledWith(
      'submit',
      expect.objectContaining({
        step: 'code',
        methodKey: MAGIC_LINK_METHOD_KEY,
      }),
      expect.objectContaining({ code: '000001' }),
    )
    expect(flow.flow.get().step).toBe('code')
    expect(flow.screenKey.get()).toBe('check_inbox')
    expect(flow.error.get()).toEqual({
      message: 'That code did not work',
      code: 'magic_link_invalid',
    })
  })

  it('a refused send falls back to the identifier field, not the code step', async () => {
    const flow = setup({
      onMethodAction: async () => ({ ok: false, code: 'send_cap_reached' }),
    })
    await typeAndDetect(flow, 'a@b.com')
    await flow.chooseMethod(MAGIC_LINK_METHOD_KEY)
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      methodKey: null,
    })
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
          methods: ['sms'],
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
          methods: ['sms'],
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

  it('backToIdentifier from the registration code screen', async () => {
    const onDetect = vi.fn(async (identifier: string) =>
      detected({ identifier, status: 'pending', methods: [] }),
    )
    const flow = setup({ onDetect })
    await typeAndDetect(flow, 'reserved@b.com')
    expect(flow.flow.get().step).toBe('code')
    flow.backToIdentifier()
    await settleRefresh()
    // The return asks the lookup again, undebounced, and the answer draws the
    // screen WITHOUT taking the step back away (HIL-651).
    expect(onDetect).toHaveBeenCalledTimes(2)
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.form.get().identifier).toBe('reserved@b.com')
    expect(flow.screenKey.get()).toBe('held_identifier')
    expect(flow.primaryAction.get()).toEqual({ kind: 'resume_code' })
  })

  it('leaves the code screen whatever intent opened it (HIL-829)', async () => {
    // The machine half of "every code screen has a way out": what the surface
    // adds is the wording and the cancel it sends beside this call, and neither
    // of those reaches here. Register arrives by a held address, recovery by the
    // key icon, sign-in by a backend that named the step.
    const register = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'pending', methods: [] }),
    })
    await typeAndDetect(register, 'reserved@b.com')
    const recovery = setup()
    await typeAndDetect(recovery, 'a@b.com')
    recovery.startRecovery()
    const login = setup({
      onSubmit: async () => ({
        ok: true,
        next: { step: 'code' as const, intent: 'login' as const },
      }),
    })
    await typeAndDetect(login, 'a@b.com')
    login.setField('password', 'x'.repeat(PASSWORD_MIN_LENGTH))
    await login.submit()

    for (const flow of [register, recovery, login]) {
      expect(flow.flow.get().step).toBe('code')
      flow.backToIdentifier()
      await settleRefresh()
      expect(flow.flow.get().step).toBe('identifier')
      // What was typed survives the way out on every one of them: the field is
      // cleared by an EDIT, never by leaving a screen.
      expect(flow.form.get().identifier).not.toBe('')
    }
  })

  it('cancelMethod re-asks the lookup too — the ceremony left an old answer behind', async () => {
    let status: IdentifierDetection['status'] = 'none'
    const onDetect = vi.fn(async (identifier: string) =>
      detected({
        identifier,
        status,
        methods: [],
        registerable: ['passkey'],
      }),
    )
    const flow = setup({
      onDetect,
      // The send the terms screen makes (HIL-417) is the very thing that
      // reserves the address, so the answer behind the field changes while the
      // person is parked on the ceremony.
      onMethodAction: async () => {
        status = 'pending'

        return { ok: true }
      },
    })
    await typeAndDetect(flow, 'new@b.com')
    await flow.chooseMethod('passkey')
    flow.setField('consentAccepted', true)
    await flow.submit()
    expect(flow.flow.get().step).toBe('external')
    flow.cancelMethod()
    await settleRefresh()
    expect(onDetect).toHaveBeenCalledTimes(2)
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.screenKey.get()).toBe('held_identifier')
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

describe('an address held by this browser (HIL-651)', () => {
  it('stops offering "Create account" when the address got reserved in between', async () => {
    // The defect itself: `none` was answered BEFORE the reservation, the flow
    // left the field, and the return drew its offer from that dead answer —
    // inviting the person to register an address they had just reserved.
    let status: IdentifierDetection['status'] = 'none'
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status, methods: [] }),
      onSubmit: async () => ({ ok: true, next: { step: 'code' as const } }),
    })
    await typeAndDetect(flow, 'new@b.com')
    expect(flow.screenKey.get()).toBe('create_account')
    await flow.submit()
    flow.setField('consentAccepted', true)
    await flow.submit()
    expect(flow.flow.get().step).toBe('code')
    status = 'pending'
    flow.backToIdentifier()
    await settleRefresh()
    expect(flow.screenKey.get()).not.toBe('create_account')
    expect(flow.screenKey.get()).toBe('held_identifier')
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.submittable.get()).toBe(false)
  })

  it('the return takes the reveal away instead of holding it (HIL-646)', async () => {
    // The holding of HIL-646 is for a TYPED re-ask only. Here the screen just
    // changed and nobody is typing: keeping the old verdict on it would draw
    // exactly the stale answer this describe exists to forbid.
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'pending', methods: [] }),
    })
    await typeAndDetect(flow, 'reserved@b.com')
    expect(flow.flow.get().step).toBe('code')
    flow.backToIdentifier()
    expect(flow.detection.get()).toEqual({ status: 'pending', result: null })
    await settleRefresh()
    expect(flow.detection.get().status).toBe('resolved')
  })

  it('still parks on the code step when the held address is TYPED again', async () => {
    // The existing path the lookup alone owns: an edit keeps its right to move
    // the step, and only the return gives it up.
    const flow = setup({
      onDetect: async (identifier) =>
        detected({ identifier, status: 'pending', methods: [] }),
    })
    await typeAndDetect(flow, 'reserved@b.com')
    expect(flow.flow.get().step).toBe('code')
    flow.backToIdentifier()
    await settleRefresh()
    expect(flow.flow.get().step).toBe('identifier')
    await typeAndDetect(flow, '')
    await typeAndDetect(flow, 'reserved@b.com')
    expect(flow.flow.get()).toMatchObject({ step: 'code', intent: 'register' })
  })

  it('resumeProvenRegistration goes on to the password, sending nothing and asking nothing', async () => {
    const onDetect = vi.fn(async (identifier: string) =>
      detected({
        identifier,
        status: 'proven',
        methods: [],
        registerable: [],
      }),
    )
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({ onDetect, onSubmit })
    await typeAndDetect(flow, 'proved@b.com')
    flow.backToIdentifier()
    await settleRefresh()
    // Walking back to the field does NOT take the step away again, which is why
    // the screen offers the way on rather than moving on its own (HIL-825).
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.screenKey.get()).toBe('proven_identifier')
    const asked = onDetect.mock.calls.length
    flow.resumeProvenRegistration()
    expect(flow.flow.get()).toMatchObject({
      step: 'set_password',
      intent: 'register',
    })
    expect(flow.screenKey.get()).toBe('set_first_password')
    expect(onSubmit).not.toHaveBeenCalled()
    expect(onDetect).toHaveBeenCalledTimes(asked)
  })

  it('resumeHeldRegistration returns to the code, sending nothing and asking nothing', async () => {
    const onDetect = vi.fn(async (identifier: string) =>
      detected({ identifier, status: 'pending', methods: [] }),
    )
    const onSubmit = vi.fn(async () => ({ ok: true }))
    const flow = setup({ onDetect, onSubmit })
    await typeAndDetect(flow, 'reserved@b.com')
    flow.backToIdentifier()
    await settleRefresh()
    expect(flow.screenKey.get()).toBe('held_identifier')
    const asked = onDetect.mock.calls.length
    flow.resumeHeldRegistration()
    expect(flow.flow.get()).toMatchObject({ step: 'code', intent: 'register' })
    expect(flow.screenKey.get()).toBe('confirm_identifier')
    expect(onSubmit).not.toHaveBeenCalled()
    expect(onDetect).toHaveBeenCalledTimes(asked)
    // No deadline is invented for a code this browser never re-sent.
    expect(flow.expiresAt.get()).toBeNull()
    expect(flow.resendAvailableAt.get()).toBeNull()
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

  it('a refusal that names a step moves the surface and keeps the reason on screen', async () => {
    const flow = setup({
      onSubmit: async () => ({
        ok: false,
        code: 'identifier_taken',
        message: 'That address is already registered.',
        next: { step: 'identifier' as const, intent: 'login' as const },
      }),
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    await flow.submit()
    expect(flow.flow.get().step).toBe('consent')
    flow.setField('consentAccepted', true)
    await flow.submit()
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      intent: 'login',
    })
    expect(flow.screenKey.get()).toBe('sign_in')
    expect(flow.error.get()).toEqual({
      message: 'That address is already registered.',
      code: 'identifier_taken',
    })
  })

  it('a refusal that names a step arms no countdown', async () => {
    const flow = setup({
      onSubmit: async () => ({
        ok: false,
        code: 'identifier_taken',
        message: 'That address is already registered.',
        next: { step: 'identifier' as const, intent: 'login' as const },
        resendAt: Date.now() + 30 * SECOND_MS,
        expiresAt: Date.now() + 300 * SECOND_MS,
      }),
      onDetect: async (identifier) =>
        detected({ identifier, status: 'none', methods: [] }),
    })
    await typeAndDetect(flow, 'new@b.com')
    await flow.submit()
    flow.setField('consentAccepted', true)
    await flow.submit()
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.resendAvailableAt.get()).toBeNull()
    expect(flow.expiresAt.get()).toBeNull()
  })

  it('a refusal that names no step still leaves the surface exactly where it is', async () => {
    const flow = setup({
      onSubmit: async () => ({
        ok: false,
        message: 'That password was already changed elsewhere.',
      }),
    })
    await typeAndDetect(flow, 'a@b.com')
    flow.applyExternal({ step: 'set_password', intent: 'recovery' })
    flow.setField('newPassword', 'x'.repeat(PASSWORD_MIN_LENGTH))
    await flow.submit()
    expect(flow.flow.get()).toMatchObject({
      step: 'set_password',
      intent: 'recovery',
    })
    expect(flow.error.get()).toEqual({
      message: 'That password was already changed elsewhere.',
      code: null,
    })
  })

  it('a ceremony refusal that names a step goes where the backend said, not to the identifier field', async () => {
    const flow = setup({
      onMethodAction: async () => ({
        ok: false,
        code: 'magic_link_invalid',
        message: 'That link is no longer good.',
        next: { step: 'code' as const, intent: 'login' as const },
      }),
    })
    await typeAndDetect(flow, 'a@b.com')
    await flow.chooseMethod('passkey')
    expect(flow.flow.get()).toMatchObject({
      step: 'code',
      intent: 'login',
      methodKey: 'passkey',
    })
    expect(flow.error.get()).toEqual({
      message: 'That link is no longer good.',
      code: 'magic_link_invalid',
    })
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
          methods: ['sms'],
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

describe('resuming an unfinished auth step', () => {
  it('parks on the code screen with the identifier and the expiry back', () => {
    const flow = setup()
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'register',
      step: 'code',
      channel: null,
      expiresAt: Date.now() + 10 * SECOND_MS,
      code: null,
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
      intent: 'register',
      step: 'code',
      channel: 'telegram',
      expiresAt: Date.now() + 10 * SECOND_MS,
      code: null,
    })
    expect(flow.flow.get()).toMatchObject({
      identifierKind: 'phone',
      channelKey: 'telegram',
    })
  })

  it('does nothing when the session stands on no unfinished step', async () => {
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
      intent: 'register',
      step: 'code',
      channel: null,
      expiresAt: Date.now() + 10 * SECOND_MS,
      code: null,
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
      intent: 'register',
      step: 'code',
      channel: null,
      expiresAt: Date.now() + 10 * SECOND_MS,
      code: null,
    })
    flow.setField('code', '000000')
    await flow.submit()
    expect(flow.expiresAt.get()).toBe(Date.now() + 10 * SECOND_MS)
  })

  it('parks on the new-password screen when the node names that step', () => {
    // HIL-648: a tab opened after the code was accepted is told where its SESSION
    // stands, so it lands on the password screen instead of retyping the code.
    const flow = setup()
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'recovery',
      step: 'set_password',
      channel: null,
      expiresAt: Date.now() + 10 * SECOND_MS,
      code: null,
    })
    expect(flow.flow.get()).toMatchObject({
      step: 'set_password',
      intent: 'recovery',
      identifierKind: 'email',
    })
    expect(flow.form.get().identifier).toBe('ada@b.com')
  })

  it('parks on the code screen of a recovery that has not been proven yet', () => {
    const flow = setup()
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'recovery',
      step: 'code',
      channel: null,
      expiresAt: Date.now() + 10 * SECOND_MS,
      code: null,
    })
    expect(flow.flow.get()).toMatchObject({
      step: 'code',
      intent: 'recovery',
    })
  })

  it('sends a session back to the address field when it lost the race while away', () => {
    // HIL-833: the browser that was offline in the second somebody else
    // registered the address never saw the live converge, so the handshake is the
    // only thing left that can tell it — and it tells it by naming the step the
    // session was MOVED to, with the same reason the converge carries.
    const flow = setup()
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'login',
      step: 'identifier',
      channel: null,
      expiresAt: null,
      code: 'identifier_taken',
    })
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      intent: 'login',
      identifierKind: 'email',
      channelKey: null,
    })
    // The address comes back in the field, because signing in is what is left to
    // do with it and retyping it is not part of being told.
    expect(flow.form.get().identifier).toBe('ada@b.com')
    expect(flow.expiresAt.get()).toBeNull()
  })

  it('takes the old countdown down when the step it lands on has no code in play', async () => {
    // The timer belongs to the code screen, and a step carrying no moment has to
    // TAKE IT DOWN rather than merely not add one: left armed, it would outlive
    // the screen it was made for and expire the next code the person asks for,
    // seconds before that code's own deadline.
    const flow = setup()
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'register',
      step: 'code',
      channel: null,
      expiresAt: Date.now() + 5 * SECOND_MS,
      code: null,
    })
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'login',
      step: 'identifier',
      channel: null,
      expiresAt: null,
      code: 'identifier_taken',
    })
    expect(flow.flow.get().step).toBe('identifier')

    // Back on a code screen, on a deadline of its own that has not come yet.
    flow.applyExternal({ step: 'code', intent: 'register' })
    await vi.advanceTimersByTimeAsync(6 * SECOND_MS)

    expect(flow.flow.get().step).toBe('code')
  })
})

describe('the send-progress line (HIL-826)', () => {
  it('holds the step the server reported', () => {
    const flow = setup()

    flow.reportSendProgress({
      state: 'sending',
      channel: 'email',
      detail: null,
    })

    expect(flow.flow.get().sendProgress).toEqual({
      state: 'sending',
      channel: 'email',
      detail: null,
    })
  })

  it('takes the line away on an empty frame', () => {
    const flow = setup()
    flow.reportSendProgress({ state: 'sent', channel: 'email', detail: null })

    flow.reportSendProgress(null)

    // A code screen with no line is a legal state, read as "nothing to say" -
    // which is exactly what the server means by the empty frame.
    expect(flow.flow.get().sendProgress).toBeNull()
  })

  it('forgets the old line the moment a new ask goes out', async () => {
    const flow = setup()
    flow.reportSendProgress({ state: 'failed', channel: 'email', detail: 'no' })

    await flow.submit()

    // The line on screen was about the previous send. The server's own `queued`
    // lands a tick later and says the same thing with authority; what must not
    // happen is a refusal from the last attempt sitting under a fresh one.
    expect(flow.flow.get().sendProgress).toBeNull()
  })

  it('forgets the line when the person walks back to the field', () => {
    const flow = setup()
    flow.reportSendProgress({ state: 'sent', channel: 'email', detail: null })

    flow.backToIdentifier()

    expect(flow.flow.get().sendProgress).toBeNull()
  })
})

describe('the phone code screen opens at once (HIL-826, Design D7)', () => {
  it('shows the code screen while the send is still being asked about', async () => {
    let release = (): void => {}
    const onSubmit = vi.fn(
      async () =>
        await new Promise<AuthFlowSubmitOutcome>((resolve) => {
          release = () => resolve({ ok: true, next: { step: 'code' } })
        }),
    )
    const flow = setup({
      onSubmit,
      onDetect: async (identifier) =>
        detected({
          identifier,
          normalized: identifier,
          kind: 'phone',
          status: 'active',
          methods: ['sms'],
        }),
    })
    await typeAndDetect(flow, '+79991234567')

    const sending = flow.chooseChannel('sms')

    // The screen no longer waits for the transport's word: it says "queued",
    // then "sending", and neither is a promise that a code exists.
    expect(flow.flow.get()).toMatchObject({ step: 'code', channelKey: 'sms' })
    // The wire is still given the step the send was ORDERED from - which action
    // a submit becomes is keyed by it.
    expect(onSubmit).toHaveBeenCalledWith(
      'submit',
      expect.objectContaining({ step: 'identifier' }),
      expect.anything(),
    )
    release()
    await sending
  })

  it('takes the person back when the channel cannot be reached', async () => {
    const flow = setup({
      onSubmit: async () => ({ ok: false, message: 'not this way' }),
      onDetect: async (identifier) =>
        detected({
          identifier,
          normalized: identifier,
          kind: 'phone',
          status: 'active',
          methods: ['sms'],
        }),
    })
    await typeAndDetect(flow, '+79991234567')

    await flow.chooseChannel('sms')

    // What is paid for opening early: the field comes back a second later, with
    // the refusal on it, exactly as it did when the screen had not opened yet.
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.error.get()?.message).toBe('not this way')
  })

  it('takes a registering phone back to the terms, not to the field', async () => {
    const flow = setup({
      onSubmit: async () => ({ ok: false, message: 'not this way' }),
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
    await flow.chooseChannel('sms')
    flow.setField('consentAccepted', true)

    await flow.submit()

    // The send was ordered from the terms screen, so that is where a refusal
    // returns - going to the field would drop an accepted consent.
    expect(flow.flow.get().step).toBe('consent')
  })
})

describe('a code that ran out says so itself (HIL-828)', () => {
  /** A backend answer that opens a code screen with a deadline on it. */
  function sentCode(lifetimeMs: number): AuthFlowSubmitOutcome {
    return {
      ok: true,
      next: { step: 'code' as const, intent: 'register' as const },
      expiresAt: Date.now() + lifetimeMs,
    }
  }

  it('moves the code step to the expired screen when the countdown reaches zero', async () => {
    const flow = setup({ onSubmit: async () => sentCode(10 * SECOND_MS) })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    flow.setField('code', '123456')
    expect(flow.flow.get().step).toBe('code')

    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)

    expect(flow.flow.get().step).toBe('code_expired')
    // A code typed for a dead challenge must not carry into the next one, and
    // "that code is wrong" was about a challenge that no longer exists.
    expect(flow.form.get().code).toBe('')
    expect(flow.error.get()).toBeNull()
  })

  it('keeps the heading and the countdown the code screen had', async () => {
    const flow = setup({ onSubmit: async () => sentCode(10 * SECOND_MS) })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    const deadline = flow.expiresAt.get()

    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)

    // The person is still confirming the same address, so the screen key does
    // not move; the deadline is what the screen stands on and is not cleared.
    expect(flow.screenKey.get()).toBe('confirm_identifier')
    expect(flow.expiresAt.get()).toBe(deadline)
    expect(flow.submittable.get()).toBe(false)
    expect(flow.primaryAction.get()).toBeNull()
  })

  it('flips at once on a tab restored onto a code that died while it was closed', async () => {
    const flow = setup()
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'register',
      step: 'code',
      channel: null,
      expiresAt: Date.now() - SECOND_MS,
      code: null,
    })

    await vi.advanceTimersByTimeAsync(0)

    expect(flow.flow.get().step).toBe('code_expired')
  })

  it('does not flip a flow that left the code step before its old timer fired', async () => {
    // The accepted code opens the password screen on the SAME deadline (HIL-825),
    // so the timer outlives the step it was armed for and must own nothing there.
    const flow = setup({ onSubmit: async () => sentCode(10 * SECOND_MS) })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    flow.applyExternal({ step: 'set_password', intent: 'register' })

    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)

    expect(flow.flow.get().step).toBe('set_password')
  })

  it('disarms the timer when the identifier is edited', async () => {
    const flow = setup({ onSubmit: async () => sentCode(10 * SECOND_MS) })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    flow.setField('identifier', 'other@b.com')

    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)

    expect(flow.flow.get().step).toBe('identifier')
  })

  it('dispatches the first send of the flow when the new-code button is pressed', async () => {
    const onSubmit = vi
      .fn<AuthFlowOptions['onSubmit']>()
      .mockImplementation(async () => sentCode(10 * SECOND_MS))
    const flow = setup({ onSubmit })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)
    expect(flow.flow.get().step).toBe('code_expired')

    await flow.renewCode()

    // The step is what selects the branch below, so the action name it travels
    // under is unchanged and no new wire string appears.
    expect(onSubmit).toHaveBeenLastCalledWith(
      'resend',
      expect.objectContaining({ step: 'code_expired' }),
      expect.anything(),
    )
    // The answer is an ordinary send: the code screen is back, with a field and
    // a countdown armed again.
    expect(flow.flow.get().step).toBe('code')
    expect(flow.expiresAt.get()).toBe(Date.now() + 10 * SECOND_MS)
  })

  it('re-arms the countdown of the code the button just ordered', async () => {
    const flow = setup({ onSubmit: async () => sentCode(10 * SECOND_MS) })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)
    await flow.renewCode()
    expect(flow.flow.get().step).toBe('code')

    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)

    expect(flow.flow.get().step).toBe('code_expired')
  })

  it('is a silent no-op inside the send cooldown', async () => {
    const onSubmit = vi
      .fn<AuthFlowOptions['onSubmit']>()
      .mockImplementation(async () => ({
        ok: true,
        next: { step: 'code' as const, intent: 'register' as const },
        resendAt: Date.now() + 30 * SECOND_MS,
        expiresAt: Date.now() + 10 * SECOND_MS,
      }))
    const flow = setup({ onSubmit })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)
    expect(flow.flow.get().step).toBe('code_expired')

    await flow.renewCode()

    // The gate belongs to the address and outlives the code: a person could
    // otherwise spend a code, watch it die and re-take the address inside the
    // very cooldown the gate exists to hold.
    expect(onSubmit).toHaveBeenCalledTimes(1)
    expect(flow.flow.get().step).toBe('code_expired')
  })

  it('leaves the expired screen where a refusal that names a step sends it', async () => {
    let sends = 0
    const flow = setup({
      onSubmit: async () => {
        sends += 1

        return sends === 1
          ? sentCode(10 * SECOND_MS)
          : {
              ok: false,
              next: { step: 'identifier' as const, intent: 'login' as const },
              message: 'That address already has an account',
            }
      },
    })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)

    await flow.renewCode()

    // Somebody claimed the address in those seconds: the ordinary
    // identifier_taken refusal of a first submit, from a different screen.
    expect(flow.flow.get()).toMatchObject({
      step: 'identifier',
      intent: 'login',
    })
    expect(flow.error.get()?.message).toBe(
      'That address already has an account',
    )
  })

  it('stays put on a refusal that names nowhere else', async () => {
    let sends = 0
    const flow = setup({
      onSubmit: async () => {
        sends += 1

        return sends === 1
          ? sentCode(10 * SECOND_MS)
          : { ok: false, message: 'Too many codes for now' }
      },
    })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)

    await flow.renewCode()

    // The send cap refuses out loud without moving anyone, so the screen keeps
    // the sentence and keeps offering the button under the gate.
    expect(flow.flow.get().step).toBe('code_expired')
    expect(flow.error.get()?.message).toBe('Too many codes for now')
  })

  it('returns to the code screen when the send it ordered names no step', async () => {
    // A magic link and a recovery both answer `sent()` with a life and a gate and
    // nothing else: where their letter lands has always been the core's to say.
    let sends = 0
    const flow = setup({
      onSubmit: async () => {
        sends += 1

        return sends === 1
          ? sentCode(10 * SECOND_MS)
          : { ok: true, expiresAt: Date.now() + 10 * SECOND_MS }
      },
    })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()
    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)
    expect(flow.flow.get().step).toBe('code_expired')

    await flow.renewCode()

    expect(flow.flow.get().step).toBe('code')
  })

  it('lets a converge name the expired step over the wire', () => {
    // Without the step in the list the sweep frame would be dropped as unknown,
    // and the browser swept off the screen it had just flipped to.
    expect(toFlowPatch('code_expired', 'register')).toEqual({
      step: 'code_expired',
      intent: 'register',
    })
  })

  it('accepts a converge that names the expired step, and a local flip agrees with it', async () => {
    const flow = setup({ onSubmit: async () => sentCode(10 * SECOND_MS) })
    await typeAndDetect(flow, 'a@b.com')
    await flow.submit()

    // The sweep's converge lands BEFORE this browser's own clock reaches zero.
    flow.applyExternal({ step: 'code_expired', intent: 'register' })
    expect(flow.flow.get().step).toBe('code_expired')

    await vi.advanceTimersByTimeAsync(10 * SECOND_MS)

    // The timer then finds a step it does not own and does nothing: which of the
    // two arrives first stops mattering.
    expect(flow.flow.get().step).toBe('code_expired')
  })
})
