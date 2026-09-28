import { describe, expect, it } from 'vitest'
import { createAuthActions } from '../../src/auth/authActions.js'
import { createHilosAuthContext } from '../../src/auth/authContext.js'
import {
  type AuthFlowForm,
  type AuthFlowState,
} from '../../src/auth/authFlow.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
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
function world(phoneReason = 'code_sent') {
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
          reply: action === 'hilos_legal_consent' ? consentTerms() : undefined,
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
