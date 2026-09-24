// Covers a sign-in held on its second factor (HIL-494) as the sign-in surface
// lives it: the answer that parks the flow on the code step and the data it
// carries, the code field a previous challenge must not pre-fill, the removal
// detour and its two screens, the enrolment an administrator requires on the way
// in, the step every tab of a browser follows as it happens, the policy frame
// that outranks an older answer, the way back that lets the held sign-in go, and
// the wire each of those screens sends.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createAuthFlow,
  DEFAULT_DETECT_DEBOUNCE_MS,
  type AuthFlowOptions,
  type AuthFlowSubmitOutcome,
  type IdentifierDetection,
} from '../../src/auth/authFlow.js'
import { createAuthActions } from '../../src/auth/authActions.js'
import { type HilosAuthContext } from '../../src/auth/authContext.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '../../src/protocol/constants.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import {
  type AuthMethodEntry,
  type PendingAuthStep,
  type SecondFactorPolicy,
} from '../../src/session/sessionScope.js'
import { createSignal } from '../../src/state/signal.js'
import { bindPageReady } from '../../src/subscription/pageReadyGate.js'

/** A browser clock parked at a known moment. */
const LOCAL_NOW = 1_700_000_000_000

/** One day in ms. */
const DAY_MS = 86_400_000

const ENTRIES: readonly AuthMethodEntry[] = [
  { key: 'password', name: null },
  { key: 'passkey', name: null },
  { key: 'sms', name: null },
]

/** An account that signs in with a password, echoing the asked identifier. */
function detected(identifier: string): IdentifierDetection {
  return {
    identifier,
    normalized: identifier,
    kind: 'email',
    status: 'active',
    methods: ['password', 'passkey'],
    registerable: ['password'],
    registrationBlock: null,
    signInBlock: null,
  }
}

/** The answer of a credential the second factor holds. */
const HELD: AuthFlowSubmitOutcome = {
  ok: true,
  next: { step: 'second_factor', intent: 'login' },
  expiresAt: LOCAL_NOW + 15 * 60_000,
  secondFactor: { trustDeviceDays: 30, resetEffectiveAt: null },
}

/** The handshake node of a held sign-in, its moments already local. */
function heldStep(overrides: Partial<PendingAuthStep> = {}): PendingAuthStep {
  return {
    identifier: null,
    kind: null,
    intent: 'login',
    step: 'second_factor',
    channel: null,
    expiresAt: LOCAL_NOW + 15 * 60_000,
    code: null,
    secondFactor: { trustDeviceDays: 30, resetEffectiveAt: null },
    ...overrides,
  }
}

/** The handshake node of a wait let go: the field, naming nobody. */
function releasedStep(code: string | null = null): PendingAuthStep {
  return {
    identifier: null,
    kind: null,
    intent: 'login',
    step: 'identifier',
    channel: null,
    expiresAt: null,
    code,
    secondFactor: null,
  }
}

/** Build a flow with passing stubs; override any seam per test. */
function setup(options: Partial<AuthFlowOptions> = {}) {
  return createAuthFlow({
    authMethods: createSignal<readonly AuthMethodEntry[]>(ENTRIES),
    channels: [],
    onDetect: async (identifier) => detected(identifier),
    onSubmit: async () => ({ ok: true }),
    onMethodAction: async () => ({ ok: true }),
    ...options,
  })
}

/** Type an address, let the lookup settle, and sign in with a password. */
async function signIn(flow: ReturnType<typeof createAuthFlow>): Promise<void> {
  flow.setField('identifier', 'ada@b.com')
  await vi.advanceTimersByTimeAsync(DEFAULT_DETECT_DEBOUNCE_MS)
  flow.setField('password', 'secret-1')
  await flow.submit()
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(LOCAL_NOW)
  applyServerTime(LOCAL_NOW)
})

afterEach(() => {
  applyServerTime(Date.now())
  vi.useRealTimers()
})

describe('the code step a proven sign-in is held on', () => {
  it('parks on the step with what it draws, and leaves it with nothing', async () => {
    const flow = setup({
      onSubmit: vi.fn<AuthFlowOptions['onSubmit']>().mockResolvedValue({
        ...HELD,
        secondFactor: {
          trustDeviceDays: 30,
          resetEffectiveAt: LOCAL_NOW + 8 * DAY_MS,
        },
      }),
    })
    await signIn(flow)

    expect(flow.flow.get().step).toBe('second_factor')
    expect(flow.screenKey.get()).toBe('two_step')
    expect(flow.secondFactor.get()).toStrictEqual({
      trustDeviceDays: 30,
      resetEffectiveAt: LOCAL_NOW + 8 * DAY_MS,
    })
    expect(flow.expiresAt.get()).toBe(LOCAL_NOW + 15 * 60_000)

    flow.backToIdentifier()
    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.secondFactor.get()).toBeNull()
  })

  it('puts the removal date on the local scale', async () => {
    applyServerTime(LOCAL_NOW + 45_000)
    const flow = setup({
      onSubmit: async () => ({
        ...HELD,
        secondFactor: {
          trustDeviceDays: null,
          resetEffectiveAt: LOCAL_NOW + 45_000 + DAY_MS,
        },
      }),
    })
    await signIn(flow)

    expect(flow.secondFactor.get()?.resetEffectiveAt).toBe(LOCAL_NOW + DAY_MS)
  })

  it('does not bring the code that proved the person onto the code step', async () => {
    // The phone code a moment ago was typed into the same field; left there, it
    // would make the new screen submittable before anything was typed.
    const flow = setup({ onSubmit: async () => HELD })
    flow.resume({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'recovery',
      step: 'code',
      channel: null,
      expiresAt: LOCAL_NOW + 60_000,
      code: null,
      secondFactor: null,
    })
    flow.setField('code', '123456')
    await flow.submit()

    expect(flow.flow.get().step).toBe('second_factor')
    expect(flow.form.get().code).toBe('')
    expect(flow.submittable.get()).toBe(false)
  })

  it('lands on the step a WebAuthn ceremony was answered with, not on the waiting screen', async () => {
    const flow = setup({ onMethodAction: async () => HELD })
    await flow.chooseMethod('passkey')

    expect(flow.flow.get().step).toBe('second_factor')
    expect(flow.pending.get()).toBe(false)
  })

  it('does not flip the step on its own clock: the server says when the wait ran out', async () => {
    const flow = setup({ onSubmit: async () => HELD })
    await signIn(flow)
    await vi.advanceTimersByTimeAsync(16 * 60_000)

    expect(flow.flow.get().step).toBe('second_factor')
  })

  it('goes back to the field with the reason when the server says the wait ran out', async () => {
    const onSubmit = vi
      .fn<AuthFlowOptions['onSubmit']>()
      .mockResolvedValueOnce(HELD)
      .mockResolvedValue({
        ok: false,
        code: 'second_factor_expired',
        message: 'Your sign-in step expired, start again',
        next: { step: 'identifier', intent: 'login' },
      })
    const flow = setup({ onSubmit })
    await signIn(flow)
    flow.setField('code', '123456')
    await flow.submit()

    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.error.get()?.code).toBe('second_factor_expired')
    expect(flow.secondFactor.get()).toBeNull()
  })
})

describe('the way back from a held sign-in', () => {
  it('lets the held sign-in go, told and not waited for', async () => {
    const onSubmit = vi
      .fn<AuthFlowOptions['onSubmit']>()
      .mockResolvedValueOnce(HELD)
      .mockReturnValue(new Promise(() => {}))
    const flow = setup({ onSubmit })
    await signIn(flow)

    flow.backToIdentifier()

    expect(onSubmit).toHaveBeenLastCalledWith(
      'second_factor_cancel',
      expect.objectContaining({ step: 'second_factor' }),
      expect.anything(),
    )
    expect(flow.flow.get().step).toBe('identifier')
    // The answer never came, and nothing waits for it.
    expect(flow.pending.get()).toBe(false)
    // Everything typed is kept, as on every way back.
    expect(flow.form.get().password).toBe('secret-1')
  })

  it('tells nothing from a field that holds no sign-in', () => {
    const onSubmit = vi.fn<AuthFlowOptions['onSubmit']>()
    const flow = setup({ onSubmit })
    flow.backToIdentifier()

    expect(onSubmit).not.toHaveBeenCalled()
  })
})

describe('the delayed removal asked from the code step', () => {
  it('detours locally and comes back to the code as typed', async () => {
    const onSubmit = vi
      .fn<AuthFlowOptions['onSubmit']>()
      .mockResolvedValue(HELD)
    const flow = setup({ onSubmit })
    await signIn(flow)
    flow.setField('code', '12')

    flow.startSecondFactorReset()
    expect(flow.flow.get().step).toBe('second_factor_reset')
    expect(flow.screenKey.get()).toBe('two_step_reset')
    flow.backToSecondFactor()

    expect(flow.flow.get().step).toBe('second_factor')
    expect(flow.form.get().code).toBe('12')
    expect(onSubmit).toHaveBeenCalledTimes(1)
  })

  it('asks the removal, names its date, and its Continue sends nothing', async () => {
    const onSubmit = vi
      .fn<AuthFlowOptions['onSubmit']>()
      .mockResolvedValueOnce(HELD)
      .mockResolvedValueOnce({
        ok: true,
        next: { step: 'second_factor_reset_requested', intent: 'login' },
        secondFactor: {
          trustDeviceDays: 30,
          resetEffectiveAt: LOCAL_NOW + 8 * DAY_MS,
        },
      })
    const flow = setup({ onSubmit })
    await signIn(flow)
    flow.startSecondFactorReset()
    await flow.submit()

    expect(onSubmit).toHaveBeenLastCalledWith(
      'submit',
      expect.objectContaining({ step: 'second_factor_reset' }),
      expect.anything(),
    )
    expect(flow.flow.get().step).toBe('second_factor_reset_requested')
    expect(flow.secondFactor.get()?.resetEffectiveAt).toBe(
      LOCAL_NOW + 8 * DAY_MS,
    )

    await flow.submit()
    expect(flow.flow.get().step).toBe('identifier')
    expect(onSubmit).toHaveBeenCalledTimes(2)
  })

  it('opens nowhere but on the code step', () => {
    const flow = setup()
    flow.startSecondFactorReset()
    flow.backToSecondFactor()

    expect(flow.flow.get().step).toBe('identifier')
  })
})

describe('the enrolment an administrator requires on the way in', () => {
  const SETUP_HELD: AuthFlowSubmitOutcome = {
    ok: true,
    next: { step: 'second_factor_setup', intent: 'login' },
    expiresAt: LOCAL_NOW + 15 * 60_000,
    secondFactor: { trustDeviceDays: 30, resetEffectiveAt: null },
  }

  it('asks for the secret, confirms the first code, and lets in after the codes', async () => {
    const onSubmit = vi
      .fn<AuthFlowOptions['onSubmit']>()
      .mockResolvedValueOnce(SETUP_HELD)
      .mockResolvedValueOnce({
        ok: true,
        secondFactor: {
          trustDeviceDays: 30,
          resetEffectiveAt: null,
          setup: { secret: 'JBSWY3DP', otpauthUri: 'otpauth://totp/x' },
        },
      })
      .mockResolvedValueOnce({
        ok: true,
        next: { step: 'second_factor_codes', intent: 'login' },
        secondFactor: {
          trustDeviceDays: 30,
          resetEffectiveAt: null,
          backupCodes: ['abcde-fghjk'],
        },
      })
      .mockResolvedValue({ ok: true })
    const flow = setup({ onSubmit })
    await signIn(flow)
    expect(flow.screenKey.get()).toBe('two_step_setup')

    await flow.loadSecondFactorSetup()
    expect(onSubmit).toHaveBeenLastCalledWith(
      'second_factor_setup_start',
      expect.objectContaining({ step: 'second_factor_setup' }),
      expect.anything(),
    )
    expect(flow.flow.get().step).toBe('second_factor_setup')
    expect(flow.secondFactor.get()?.setup?.secret).toBe('JBSWY3DP')

    flow.setField('secondFactorLabel', 'Work phone')
    flow.setField('code', '123456')
    await flow.submit()
    expect(onSubmit).toHaveBeenLastCalledWith(
      'submit',
      expect.objectContaining({ step: 'second_factor_setup' }),
      expect.objectContaining({
        code: '123456',
        secondFactorLabel: 'Work phone',
      }),
    )
    expect(flow.flow.get().step).toBe('second_factor_codes')
    expect(flow.secondFactor.get()?.backupCodes).toStrictEqual(['abcde-fghjk'])
    // The secret was for the screen before; it does not ride along.
    expect(flow.secondFactor.get()?.setup).toBeUndefined()
    expect(flow.submittable.get()).toBe(false)

    flow.setField('backupCodesSaved', true)
    await flow.submit()
    expect(onSubmit).toHaveBeenLastCalledWith(
      'submit',
      expect.objectContaining({ step: 'second_factor_codes' }),
      expect.anything(),
    )
  })

  it('asks for no secret anywhere but on the enrolment step', async () => {
    const onSubmit = vi.fn<AuthFlowOptions['onSubmit']>()
    const flow = setup({ onSubmit })
    await flow.loadSecondFactorSetup()

    expect(onSubmit).not.toHaveBeenCalled()
  })
})

describe('a held sign-in restored by the handshake', () => {
  it('stands on the step with its data, and names nobody in the field', () => {
    const flow = setup()
    flow.resume(heldStep())

    expect(flow.flow.get()).toMatchObject({
      step: 'second_factor',
      intent: 'login',
      identifierKind: 'unknown',
    })
    expect(flow.form.get().identifier).toBe('')
    expect(flow.secondFactor.get()).toStrictEqual({
      trustDeviceDays: 30,
      resetEffectiveAt: null,
    })
  })

  it('comes back to the field when the wait ran out while the tab was closed', async () => {
    const onDetect = vi.fn(async (identifier: string) => detected(identifier))
    const flow = setup({ onDetect })
    flow.resume(releasedStep('second_factor_expired'))

    expect(flow.flow.get().step).toBe('identifier')
    // Nobody is named, so nothing is looked up.
    await vi.advanceTimersByTimeAsync(0)
    expect(onDetect).not.toHaveBeenCalled()
  })
})

describe('the step every tab of a browser follows', () => {
  it('takes a tab that submitted nothing onto the code step', () => {
    const flow = setup()
    flow.setField('identifier', 'ada@b.com')
    flow.followReportedStep(heldStep())

    expect(flow.flow.get().step).toBe('second_factor')
    expect(flow.secondFactor.get()?.trustDeviceDays).toBe(30)
    // The field is left as it was: the step names nobody.
    expect(flow.form.get().identifier).toBe('ada@b.com')
  })

  it('moves nothing while this tab waits for its own answer', async () => {
    let answer: (outcome: AuthFlowSubmitOutcome) => void = () => {}
    const onSubmit = vi
      .fn<AuthFlowOptions['onSubmit']>()
      .mockResolvedValueOnce(HELD)
      .mockReturnValueOnce(
        new Promise((resolve) => {
          answer = resolve
        }),
      )
    const flow = setup({ onSubmit })
    await signIn(flow)
    flow.startSecondFactorReset()
    const asking = flow.submit()

    // The removal let the wait go, and the report of it runs ahead of the answer.
    flow.followReportedStep(releasedStep())
    expect(flow.flow.get().step).toBe('second_factor_reset')

    answer({
      ok: true,
      next: { step: 'second_factor_reset_requested', intent: 'login' },
      secondFactor: { trustDeviceDays: 30, resetEffectiveAt: LOCAL_NOW },
    })
    await asking
    expect(flow.flow.get().step).toBe('second_factor_reset_requested')
  })

  it('refreshes a step already on show without taking what was typed', async () => {
    const flow = setup({ onSubmit: async () => HELD })
    await signIn(flow)
    flow.setField('code', '12')
    flow.startSecondFactorReset()

    flow.followReportedStep(
      heldStep({
        secondFactor: { trustDeviceDays: 7, resetEffectiveAt: null },
      }),
    )

    expect(flow.flow.get().step).toBe('second_factor_reset')
    expect(flow.form.get().code).toBe('12')
    expect(flow.secondFactor.get()?.trustDeviceDays).toBe(7)
  })

  it('keeps the secret on show when the enrolment step is reported again', async () => {
    const flow = setup({
      onSubmit: async () => ({
        ok: true,
        secondFactor: {
          trustDeviceDays: 30,
          resetEffectiveAt: null,
          setup: { secret: 'JBSWY3DP', otpauthUri: 'otpauth://totp/x' },
        },
      }),
    })
    flow.resume(heldStep({ step: 'second_factor_setup' }))
    await flow.loadSecondFactorSetup()

    flow.followReportedStep(heldStep({ step: 'second_factor_setup' }))

    expect(flow.secondFactor.get()?.setup?.secret).toBe('JBSWY3DP')
  })

  it('takes every tab standing on the wait back to the field when it is let go', () => {
    const flow = setup()
    flow.resume(heldStep())

    flow.followReportedStep(releasedStep())

    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.secondFactor.get()).toBeNull()
  })

  it('leaves alone a tab that stands on no wait, and a report that is not the wait', () => {
    const flow = setup()
    flow.setField('identifier', 'ada@b.com')
    flow.setField('password', 'secret-1')
    flow.followReportedStep(releasedStep('second_factor_attempts'))
    flow.followReportedStep({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'register',
      step: 'code',
      channel: null,
      expiresAt: LOCAL_NOW + 60_000,
      code: null,
      secondFactor: null,
    })
    flow.followReportedStep(null)

    expect(flow.flow.get().step).toBe('identifier')
    expect(flow.form.get().password).toBe('secret-1')
  })
})

describe('the policy frame an administrator sends', () => {
  const POLICY: SecondFactorPolicy = {
    required: 'none',
    trustDays: 30,
    backupCodes: 10,
    resetWaitDefaultDays: 8,
    resetWaitMinDays: 1,
    resetWaitMaxDays: 30,
  }

  it('outranks the answer it arrived after, and only that one', async () => {
    const policy = createSignal<SecondFactorPolicy | null>(POLICY)
    const flow = setup({
      secondFactorPolicy: policy,
      onSubmit: async () => HELD,
    })
    await signIn(flow)
    // The frame standing when the answer came is what the answer was computed under.
    expect(flow.secondFactor.get()?.trustDeviceDays).toBe(30)

    policy.set({ ...POLICY, trustDays: 0 })
    expect(flow.secondFactor.get()?.trustDeviceDays).toBeNull()

    policy.set({ ...POLICY, trustDays: 14 })
    expect(flow.secondFactor.get()?.trustDeviceDays).toBe(14)
  })
})

describe('the wire of the second-factor screens', () => {
  /**
   * A context recording every dispatch and answering each with the given reply,
   * validated by the schema the wire hands it.
   *
   * @param wire The reply as the backend sends it.
   */
  function recordingContext(wire: unknown) {
    const sent: Array<{ name: string; payload: unknown }> = []
    const context = {
      actions: {
        dispatch: (
          name: string,
          payload: unknown,
          options: { replySchema?: { parse(value: unknown): unknown } } = {},
        ) => {
          sent.push({ name, payload })

          return {
            done: Promise.resolve({
              reply:
                options.replySchema === undefined
                  ? wire
                  : options.replySchema.parse(wire),
            }),
          }
        },
      },
    } as unknown as HilosAuthContext

    return { context, sent }
  }

  const FORM = {
    identifier: 'ada@b.com',
    password: '',
    code: '123456',
    newPassword: '',
    consentAccepted: false,
    usingBackupCode: true,
    trustDevice: true,
    secondFactorLabel: 'Work phone',
    backupCodesSaved: true,
  }

  const FLOW = {
    step: 'second_factor' as const,
    intent: 'login' as const,
    methodKey: null,
    identifierKind: 'email' as const,
    channelKey: null,
    sendProgress: null,
  }

  it('sends each screen to its own action', async () => {
    // The cancel link is relayed from a route loaded cold, so it waits for the
    // page to be answered first; here it already was.
    bindPageReady({
      on: (_event: string, listener: (signal: unknown) => void) => {
        listener({ type: SIGNAL_TYPE_PAGE_RESPONSE })

        return () => {}
      },
    } as unknown as HilosConnection)
    const { context, sent } = recordingContext(undefined)
    const actions = createAuthActions(context)
    await actions.onSubmit('submit', FLOW, FORM)
    await actions.onSubmit(
      'submit',
      { ...FLOW, step: 'second_factor_setup' },
      FORM,
    )
    await actions.onSubmit(
      'submit',
      { ...FLOW, step: 'second_factor_codes' },
      FORM,
    )
    await actions.onSubmit(
      'submit',
      { ...FLOW, step: 'second_factor_reset' },
      FORM,
    )
    await actions.onSubmit(
      'second_factor_setup_start',
      { ...FLOW, step: 'second_factor_setup' },
      FORM,
    )
    await actions.onSubmit('second_factor_cancel', FLOW, FORM)
    await actions.cancelSecondFactorReset('token-1')

    expect(sent).toStrictEqual([
      {
        name: 'hilos_confirm_second_factor',
        payload: { code: '123456', backupCode: true, trustDevice: true },
      },
      {
        name: 'hilos_second_factor_setup_confirm',
        payload: { code: '123456', label: 'Work phone' },
      },
      { name: 'hilos_second_factor_setup_finish', payload: {} },
      { name: 'hilos_second_factor_reset_request', payload: {} },
      { name: 'hilos_second_factor_setup_start', payload: {} },
      { name: 'hilos_cancel_second_factor', payload: {} },
      {
        name: 'hilos_second_factor_reset_cancel_link',
        payload: { token: 'token-1' },
      },
    ])
  })

  it('reads the step data an answer carries', async () => {
    const { context } = recordingContext({
      ok: true,
      next: { step: 'second_factor_codes', intent: 'login' },
      secondFactor: {
        trustDeviceDays: null,
        resetEffectiveAt: null,
        backupCodes: ['abcde-fghjk'],
      },
    })
    const actions = createAuthActions(context)

    await expect(
      actions.onSubmit(
        'submit',
        { ...FLOW, step: 'second_factor_setup' },
        FORM,
      ),
    ).resolves.toMatchObject({
      ok: true,
      next: { step: 'second_factor_codes', intent: 'login' },
      secondFactor: { backupCodes: ['abcde-fghjk'] },
    })
  })
})
