import { describe, expect, it, vi } from 'vitest'
import * as oauthLogin from '../../src/auth/oauthLogin.js'
import { createAuthActions } from '../../src/auth/authActions.js'
import { createHilosAuthContext } from '../../src/auth/authContext.js'
import {
  type AuthFlowForm,
  type AuthFlowState,
} from '../../src/auth/authFlow.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
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
  const connection = {
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
  const context = createHilosAuthContext({
    connection,
    actions,
    scopes: new ScopeManager(),
    channels: [],
  })
  return { actions: createAuthActions(context), sent, emit }
}

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
