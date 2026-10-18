import { afterEach, describe, expect, it, vi } from 'vitest'
import * as oauthLogin from '../../src/auth/oauthLogin.js'
import { createAuthActions } from '../../src/auth/authActions.js'
import { createHilosAuthContext } from '../../src/auth/authContext.js'
import {
  type AuthFlowForm,
  type AuthFlowState,
} from '../../src/auth/authFlow.js'
import {
  type ConnectionState,
  type HilosConnection,
} from '../../src/connection/HilosConnection.js'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { bindPageScope } from '../../src/subscription/bindPageScope.js'
import { bindPageReady } from '../../src/subscription/pageReadyGate.js'
import { setHilosThemePick } from '../../src/theme/themeState.js'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '../../src/protocol/constants.js'
import { consentTerms } from '../legal/consentFixture.js'

const FORM: AuthFlowForm = {
  identifier: 'person@example.test',
  password: '',
  code: '',
  newPassword: '',
  consentAccepted: true,
  acceptedRevisions: { terms: 'terms-v1', privacy: 'privacy-v1' },
  usingBackupCode: false,
  trustDevice: false,
  secondFactorLabel: '',
  backupCodesSaved: false,
}
const FLOW: AuthFlowState = {
  step: 'consent',
  intent: 'register',
  methodKey: null,
  identifierKind: 'email',
  channelKey: null,
  sendProgress: null,
}

/** A real action adapter over a wire that can deliver a phone outcome before its ack. */
function world(
  phoneReason = 'code_sent',
  replies: Record<string, unknown> = {},
) {
  const listeners = new Map<string, Set<(value: unknown) => void>>()
  const sent: Array<{ action: string; payload: Record<string, unknown> }> = []
  const frames: Array<Record<string, unknown>> = []
  let state: ConnectionState = 'connected'
  const connection = {
    get state() {
      return state
    },
    reconnectDragging: false,
    send(text: string): boolean {
      frames.push(JSON.parse(text))
      return true
    },
    forgetPageFrames: () => {},
    tableWindowDescriptors: () => ({}),
    on(event: string, handler: (value: unknown) => void) {
      const set = listeners.get(event) ?? new Set<(value: unknown) => void>()
      listeners.set(event, set)
      set.add(handler)
      return () => {
        set.delete(handler)
      }
    },
  } as unknown as HilosConnection
  const emit = (event: string, value: unknown) => {
    for (const handler of listeners.get(event) ?? []) handler(value)
  }
  const actions = {
    dispatch(action: string, payload: Record<string, unknown>) {
      sent.push({ action, payload })
      frames.push({ type: 'action', action, data: payload })
      if (action === 'hilos_request_phone_code') {
        emit('projectSignal', {
          type: 'hilos_code_send_progress',
          data: {
            ticket: 'phone-send',
            reason: phoneReason,
            state: phoneReason === 'code_sent' ? 'sent' : 'failed',
            channel: 'sms',
            detail: null,
            resendAt: null,
            expiresAt: null,
          },
        })
        return { done: Promise.resolve({ reply: { ticket: 'phone-send' } }) }
      }
      return {
        done: Promise.resolve({
          reply:
            action === 'hilos_legal_consent' ? consentTerms() : replies[action],
        }),
      }
    },
  } as unknown as ActionLifecycle
  const scopes = new ScopeManager()
  const pages = bindPageScope(connection, scopes)
  pages.subscribe('main')
  connection.on('projectSignal', (signal) => {
    if (signal.type === 'handshake_response') pages.releaseOnSession()
  })
  const context = createHilosAuthContext({
    connection,
    actions,
    scopes,
    channels: [],
  })
  return {
    actions: createAuthActions(context),
    lifecycle: actions,
    sent,
    frames,
    emit,
    connection,
    setState(next: ConnectionState): void {
      state = next
      emit('state', next)
    },
  }
}

afterEach(() => setHilosThemePick(null))

describe('Continue waits for its page subscription (HIL-1557)', () => {
  it('sends one empty dismiss after subscribe, without a page response', async () => {
    const { actions, sent, frames, emit } = world()
    const submitted = actions.onSubmit(
      'submit',
      { ...FLOW, step: 'done' },
      FORM,
    )
    expect(sent).toEqual([])
    expect(frames).toEqual([])

    emit('projectSignal', { type: 'handshake_response' })
    expect(frames).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
      { type: 'action', action: 'hilos_dismiss_session_ack', data: {} },
    ])
    await expect(submitted).resolves.toEqual({ ok: true })
    emit('projectSignal', { type: 'handshake_response' })
    expect(sent).toEqual([{ action: 'hilos_dismiss_session_ack', payload: {} }])
  })

  it('reports unavailable connection through the ordinary failed outcome and permits retry', async () => {
    const { actions, sent, emit, setState } = world()
    const submitted = actions.onSubmit(
      'submit',
      { ...FLOW, step: 'done' },
      FORM,
    )
    setState('disconnected')
    await expect(submitted).resolves.toEqual({
      ok: false,
      message: 'Could not reach the server. Please try again.',
    })
    setState('connected')
    emit('projectSignal', { type: 'handshake_response' })
    expect(sent).toEqual([])
    await expect(
      actions.onSubmit('submit', { ...FLOW, step: 'done' }, FORM),
    ).resolves.toEqual({ ok: true })
    expect(sent).toEqual([{ action: 'hilos_dismiss_session_ack', payload: {} }])
  })

  it.each([false, true])(
    'aborts a wait, already aborted = %s',
    async (alreadyAborted) => {
      const { actions, sent, emit } = world()
      const controller = new AbortController()
      if (alreadyAborted) controller.abort()
      const submitted = actions.onSubmit(
        'submit',
        { ...FLOW, step: 'done' },
        FORM,
        controller.signal,
      )
      controller.abort()
      await expect(submitted).resolves.toEqual({
        ok: false,
        message: 'Could not reach the server. Please try again.',
      })
      emit('projectSignal', { type: 'handshake_response' })
      expect(sent).toEqual([])
    },
  )

  it('converts a missing subscription owner to the same failed outcome', async () => {
    const { lifecycle } = world()
    const dispatch = vi.spyOn(lifecycle, 'dispatch')
    const actions = createAuthActions({
      actions: lifecycle,
      connection: {} as HilosConnection,
      scopes: new ScopeManager(),
      channels: [],
    })
    await expect(
      actions.onSubmit('submit', { ...FLOW, step: 'done' }, FORM),
    ).resolves.toEqual({
      ok: false,
      message: 'Could not reach the server. Please try again.',
    })
    expect(dispatch).not.toHaveBeenCalled()
  })

  it('keeps a dispatch failure on the ordinary failed outcome path', async () => {
    const { actions, lifecycle, emit } = world()
    vi.spyOn(lifecycle, 'dispatch').mockImplementationOnce(() => {
      throw new Error('Could not send')
    })
    const submitted = actions.onSubmit(
      'submit',
      { ...FLOW, step: 'done' },
      FORM,
    )
    emit('projectSignal', { type: 'handshake_response' })
    await expect(submitted).resolves.toEqual({
      ok: false,
      message: 'Could not reach the server. Please try again.',
    })
  })

  it('does not delay other auth actions behind the subscription', async () => {
    const { actions, sent, frames } = world()
    await actions.onSubmit('submit', { ...FLOW, step: 'identifier' }, FORM)
    expect(sent).toEqual([
      {
        action: 'hilos_login',
        payload: { email: FORM.identifier, password: '' },
      },
    ])
    expect(frames.map((frame) => frame.type)).toEqual(['action'])
  })
})

describe('guest theme choice on account-creating submits (HIL-1427)', () => {
  it.each(['dark', null] as const)(
    'carries the current choice %s on every creating road',
    async (pick) => {
      setHilosThemePick(pick)
      const { actions, sent, emit, connection } = world()
      const stopReady = bindPageReady(connection)
      emit('projectSignal', {
        type: SIGNAL_TYPE_PAGE_RESPONSE,
        data: { page: 'main', payload: {} },
      })
      const pending = vi
        .spyOn(oauthLogin, 'pendingOAuthAccount')
        .mockReturnValue({
          provider: 'oauth:github',
          providerName: 'GitHub',
          email: null,
          accountToken: 'signed-account-token',
        })
      try {
        await actions.onSubmit(
          'submit',
          { ...FLOW, step: 'set_password' },
          { ...FORM, newPassword: 'new-password' },
        )
        await actions.onSubmit(
          'finish_without_password',
          { ...FLOW, step: 'set_password' },
          FORM,
        )
        await actions.onSubmit(
          'submit',
          { ...FLOW, step: 'code', identifierKind: 'phone' },
          { ...FORM, identifier: '+14155552671', code: '123456' },
        )
        await actions.onSubmit(
          'submit',
          { ...FLOW, step: 'code', methodKey: 'magic_link' },
          { ...FORM, code: '123456' },
        )
        await actions.onSubmit(
          'submit',
          { ...FLOW, methodKey: 'oauth:github', identifierKind: 'unknown' },
          FORM,
        )
        await actions.confirmMagicLink(FORM.identifier, 'mailed-token')
      } finally {
        pending.mockRestore()
        stopReady()
      }

      expect(sent.map(({ action }) => action)).toEqual([
        'hilos_complete_registration',
        'hilos_complete_registration_passwordless',
        'hilos_confirm_phone_code',
        'hilos_confirm_magic_link_code',
        'hilos_oauth_create_account',
        'hilos_confirm_magic_link',
      ])
      for (const { payload } of sent) {
        expect(payload).toHaveProperty('themePick', pick)
      }
    },
  )

  it('keeps password recovery free of a guest theme choice', async () => {
    setHilosThemePick('dark')
    const { actions, sent } = world()

    await actions.onSubmit(
      'submit',
      { ...FLOW, step: 'set_password', intent: 'recovery' },
      { ...FORM, newPassword: 'new-password' },
    )

    expect(sent).toEqual([
      {
        action: 'hilos_complete_password_reset',
        payload: { password: 'new-password' },
      },
    ])
  })
})

describe('registration consent on the wire', () => {
  it('sends the accepted map with email registration and renewal, but not a live resend', async () => {
    const { actions, sent } = world()
    await actions.onSubmit('submit', FLOW, FORM)
    await actions.onSubmit('resend', { ...FLOW, step: 'code' }, FORM)
    await actions.onSubmit('resend', { ...FLOW, step: 'code_expired' }, FORM)
    expect(sent).toEqual([
      {
        action: 'hilos_register',
        payload: {
          email: FORM.identifier,
          acceptedRevisions: FORM.acceptedRevisions,
        },
      },
      {
        action: 'hilos_request_register_confirm',
        payload: { email: FORM.identifier },
      },
      {
        action: 'hilos_register',
        payload: {
          email: FORM.identifier,
          acceptedRevisions: FORM.acceptedRevisions,
        },
      },
    ])
  })

  it('sends the accepted map on the first phone request and renewal only', async () => {
    const { actions, sent } = world()
    const flow: AuthFlowState = {
      ...FLOW,
      identifierKind: 'phone',
      channelKey: 'sms',
    }
    const form = { ...FORM, identifier: '+14155552671' }
    await actions.onSubmit('submit', flow, form)
    await actions.onSubmit('resend', { ...flow, step: 'code' }, form)
    await actions.onSubmit('resend', { ...flow, step: 'code_expired' }, form)
    expect(sent[0]?.payload).toEqual({
      phone: form.identifier,
      channel: 'sms',
      acceptedRevisions: FORM.acceptedRevisions,
    })
    expect(sent.slice(1).map(({ payload }) => payload)).toEqual([
      { phone: form.identifier, channel: 'sms' },
      {
        phone: form.identifier,
        channel: 'sms',
        acceptedRevisions: FORM.acceptedRevisions,
      },
    ])
  })

  it('carries consent with a first magic link and renewal, but not a live repeat', async () => {
    const { actions, sent } = world()
    await actions.onMethodAction(
      'magic_link',
      FORM,
      new AbortController().signal,
    )
    await actions.onSubmit(
      'resend',
      { ...FLOW, step: 'code', methodKey: 'magic_link' },
      FORM,
    )
    await actions.onSubmit(
      'resend',
      { ...FLOW, step: 'code_expired', methodKey: 'magic_link' },
      FORM,
    )
    expect(sent[0]?.payload).toEqual({
      email: FORM.identifier,
      acceptedRevisions: FORM.acceptedRevisions,
    })
    expect(sent.slice(1).map(({ payload }) => payload)).toEqual([
      { email: FORM.identifier },
      { email: FORM.identifier, acceptedRevisions: FORM.acceptedRevisions },
    ])
  })

  it.each([
    { identifierKind: 'email' as const, methodKey: null },
    { identifierKind: 'phone' as const, methodKey: null },
    { identifierKind: 'email' as const, methodKey: 'magic_link' },
  ])(
    'omits unknown consent on a $identifierKind / $methodKey renewal',
    async (road) => {
      const { actions, sent } = world()
      await actions.onSubmit(
        'resend',
        { ...FLOW, ...road, step: 'code_expired' },
        { ...FORM, acceptedRevisions: null },
      )
      expect(sent).toHaveLength(1)
      expect(sent[0]?.payload).not.toHaveProperty('acceptedRevisions')
    },
  )

  it('keeps password recovery free of a registration map', async () => {
    const { actions, sent } = world()
    await actions.onSubmit(
      'resend',
      { ...FLOW, intent: 'recovery', step: 'code_expired' },
      FORM,
    )
    expect(sent).toEqual([
      {
        action: 'hilos_request_password_reset',
        payload: { email: FORM.identifier },
      },
    ])
  })

  it.each(['consent_required', 'consent_revised', 'terms_unpublished'])(
    'returns the phone owner refusal %s to consent',
    async (reason) => {
      const { actions } = world(reason)
      const outcome = await actions.onSubmit(
        'submit',
        { ...FLOW, identifierKind: 'phone' },
        FORM,
      )
      expect(outcome).toMatchObject({
        ok: false,
        code: reason,
        next: { step: 'consent', intent: 'register' },
      })
      expect(outcome.message === undefined).toBe(reason === 'consent_required')
    },
  )

  it('exposes the content read and a disposable handshake listener', async () => {
    const { actions, emit } = world()
    expect(await actions.onConsentTerms()).toEqual(consentTerms())
    let restored = 0
    const stop = actions.subscribeConnectionRestored(() => {
      restored += 1
    })
    emit('handshake', {})
    stop()
    emit('handshake', {})
    expect(restored).toBe(1)
  })
})

describe('provider first sign-in consent on the wire (HIL-1235)', () => {
  const providerFlow: AuthFlowState = {
    ...FLOW,
    identifierKind: 'unknown',
    methodKey: 'oauth:github',
  }

  it('submits the signed proof and accepted revisions', async () => {
    setHilosThemePick('dark')
    const pending = vi
      .spyOn(oauthLogin, 'pendingOAuthAccount')
      .mockReturnValue({
        provider: 'oauth:github',
        providerName: 'GitHub',
        email: null,
        accountToken: 'signed-account-token',
      })
    try {
      const { actions, sent } = world()
      await actions.onSubmit('submit', providerFlow, FORM)

      expect(sent).toEqual([
        {
          action: 'hilos_oauth_create_account',
          payload: {
            accountToken: 'signed-account-token',
            acceptedRevisions: FORM.acceptedRevisions,
            themePick: 'dark',
          },
        },
      ])
    } finally {
      pending.mockRestore()
    }
  })

  it('returns to sign-in if this tab no longer holds the proof', async () => {
    oauthLogin.dropOAuthAccount()
    const { actions, sent } = world()

    const outcome = await actions.onSubmit('submit', providerFlow, FORM)

    expect(outcome).toMatchObject({
      ok: false,
      code: 'oauth_sign_in_expired',
      next: { step: 'identifier', intent: 'login' },
    })
    expect(sent).toEqual([])
  })

  it('names the provider in an expired proof refusal', async () => {
    const pending = vi
      .spyOn(oauthLogin, 'pendingOAuthAccount')
      .mockReturnValue({
        provider: 'oauth:github',
        providerName: 'GitHub',
        email: 'new@example.test',
        accountToken: 'expired-token',
      })
    try {
      const { actions } = world('code_sent', {
        hilos_oauth_create_account: {
          ok: false,
          code: 'oauth_sign_in_expired',
          next: { step: 'identifier', intent: 'login' },
        },
      })
      const outcome = await actions.onSubmit('submit', providerFlow, FORM)

      expect(outcome.message).toBe(
        'Your sign-in with GitHub has expired. Continue with GitHub again.',
      )
      expect(outcome.next).toEqual({ step: 'identifier', intent: 'login' })
    } finally {
      pending.mockRestore()
    }
  })
})

describe('a refusal of the anti-abuse guard (HIL-1280)', () => {
  /** An adapter whose every dispatch the server refuses with the given failure. */
  function refusing(failure: ActionError) {
    const actions = {
      dispatch() {
        return { done: Promise.reject(failure) }
      },
    } as unknown as ActionLifecycle
    const context = createHilosAuthContext({
      connection: { on: () => () => {} } as unknown as HilosConnection,
      actions,
      scopes: new ScopeManager(),
      channels: [],
    })
    return createAuthActions(context)
  }

  const SIGN_IN: AuthFlowState = {
    ...FLOW,
    step: 'identifier',
    intent: 'login',
  }

  it('reaches the surface as its code, not as the placeholder sent in place of its words', async () => {
    const actions = refusing(
      new ActionError(
        'hilos_login',
        'fail',
        'The action could not be completed.',
        undefined,
        undefined,
        'rate_limited',
      ),
    )

    const outcome = await actions.onSubmit('submit', SIGN_IN, FORM)

    expect(outcome).toEqual({ ok: false, code: 'rate_limited' })
  })

  it('keeps the sentence of any other refusal', async () => {
    const actions = refusing(
      new ActionError('hilos_login', 'fail', 'Wrong email or password.'),
    )

    const outcome = await actions.onSubmit('submit', SIGN_IN, FORM)

    expect(outcome).toEqual({ ok: false, message: 'Wrong email or password.' })
  })
})
