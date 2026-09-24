import { describe, expect, it, vi } from 'vitest'
import {
  bindSessionScope,
  handshakeResponseAck,
  sessionUserName,
  sessionUserIsAdmin,
  sessionUserId,
  sessionImpersonating,
  sessionImpersonatedByName,
  sessionPendingAck,
  sessionPendingAuthStep,
  sessionCodeDelivery,
  sessionAuthMethods,
  sessionEnabledAuthMethods,
  SESSION_ACK_REGISTERED,
  SIGNAL_AUTH_METHODS,
  SESSION_SIGNAL_SCHEMAS,
} from '../../src/session/sessionScope.js'
import { applyServerTime, offsetMs } from '../../src/session/serverClock.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../src/state/signal.js'
import { type HilosConnection, type ProjectSignal } from '../../src/index.js'

/** A browser clock parked at a known moment, so the measured drift is a chosen number. */
const LOCAL_NOW = 1_700_000_000_000

/** How far ahead of this browser the handshake claims the server is, in ms. */
const SERVER_DRIFT_MS = 45_000

/** A connection double replaying handshake-response project signals. */
function fakeConnection() {
  const projectListeners: Array<(signal: ProjectSignal) => void> = []

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
    emitHandshakeResponse(payload: Record<string, unknown>): void {
      this.emit('handshake_response', payload)
    },
    emit(type: string, payload: Record<string, unknown>): void {
      const signal = {
        kind: 'project',
        type,
        data: payload,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
  }
}

describe('sessionScope', () => {
  it('exposes the handshake_response schema keyed for projectSchemas', () => {
    expect(SESSION_SIGNAL_SCHEMAS['handshake_response']).toBeDefined()
  })

  it('ingests the handshake response and resolves the current user name', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const name = sessionUserName(scopes)

    expect(name.get()).toBe('')

    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada' } },
    })

    expect(name.get()).toBe('Ada')
  })

  it('resolves the admin flag the handshake response carries', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const admin = sessionUserIsAdmin(scopes)

    expect(admin.get()).toBe(false)

    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada', admin: true } },
    })

    expect(admin.get()).toBe(true)

    // A revoke arrives the same way and takes the entry away again.
    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada', admin: false } },
    })

    expect(admin.get()).toBe(false)
  })

  it('ingests the handshake response and resolves the current user id', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const id = sessionUserId(scopes)

    expect(id.get()).toBeNull()

    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada' } },
    })

    expect(id.get()).toBe(1)
  })

  it('resolves the current user under a custom slot, type, and name field', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const options = {
      currentUserSlot: 'me',
      currentUserEntityType: 'member',
      currentUserNameField: 'handle',
    }
    bindSessionScope(connection as unknown as HilosConnection, scopes, options)
    const name = sessionUserName(scopes, options)

    connection.emitHandshakeResponse({
      entities: { me: { id: 9, handle: 'ada' } },
    })

    expect(name.get()).toBe('ada')
  })

  it('derives impersonating and the admin name from the impersonatedBy slot', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const impersonating = sessionImpersonating(scopes)
    const byName = sessionImpersonatedByName(scopes)

    expect(impersonating.get()).toBe(false)
    expect(byName.get()).toBe('')

    connection.emitHandshakeResponse({
      entities: {
        currentUser: { id: 2, name: 'Bob' },
        impersonatedBy: { id: 1, name: 'Ada' },
      },
    })

    expect(impersonating.get()).toBe(true)
    expect(byName.get()).toBe('Ada')
  })

  it('clears impersonating when the impersonatedBy slot goes null', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const impersonating = sessionImpersonating(scopes)
    const byName = sessionImpersonatedByName(scopes)

    connection.emitHandshakeResponse({
      entities: {
        currentUser: { id: 2, name: 'Bob' },
        impersonatedBy: { id: 1, name: 'Ada' },
      },
    })
    expect(impersonating.get()).toBe(true)

    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada' }, impersonatedBy: null },
    })

    expect(impersonating.get()).toBe(false)
    expect(byName.get()).toBe('')
  })

  it('resolves the pending ack and drops it back to null when the slot clears', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const ack = sessionPendingAck(scopes)

    expect(ack.get()).toBeNull()

    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada' } },
      data: { pendingAck: SESSION_ACK_REGISTERED },
    })
    expect(ack.get()).toBe(SESSION_ACK_REGISTERED)

    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada' } },
      data: { pendingAck: null },
    })

    expect(ack.get()).toBeNull()
  })

  it('has the ack of the same response ready when the current user lands', () => {
    // The gate decides whether the rising session may close the surface by
    // reading the ack inside its currentUserId subscriber, so the two arriving in
    // one response is not enough — the ack has to be applied FIRST.
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const userId = sessionUserId(scopes)
    const ack = sessionPendingAck(scopes)
    const seen: Array<string | null> = []
    subscribeSignal(userId, () => seen.push(ack.get()))

    connection.emitHandshakeResponse({ data: { pendingAck: null } })
    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada' } },
      data: { pendingAck: SESSION_ACK_REGISTERED },
    })

    expect(seen).toStrictEqual([SESSION_ACK_REGISTERED])
  })

  it('measures the server clock from the handshake, before the scope is published', () => {
    // The offset has to be in place by the time a subscriber wakes: the values
    // that wake it are the ones a countdown is drawn from.
    vi.useFakeTimers()
    vi.setSystemTime(LOCAL_NOW)
    try {
      const connection = fakeConnection()
      const scopes = new ScopeManager()
      bindSessionScope(connection as unknown as HilosConnection, scopes)
      const userId = sessionUserId(scopes)
      const seen: number[] = []
      subscribeSignal(userId, () => seen.push(offsetMs()))

      connection.emitHandshakeResponse({
        entities: { currentUser: { id: 1, name: 'Ada' } },
        data: { pendingAck: null, serverTimeMs: LOCAL_NOW + SERVER_DRIFT_MS },
      })

      expect(offsetMs()).toBe(SERVER_DRIFT_MS)
      expect(seen).toStrictEqual([SERVER_DRIFT_MS])
    } finally {
      applyServerTime(Date.now())
      vi.useRealTimers()
    }
  })

  it('reads what the installation can deliver a code to, per kind', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const delivery = sessionCodeDelivery(scopes)

    // Before any handshake there is nothing to read, and nothing to read must
    // never be read as a withdrawn registration (HIL-830).
    expect(delivery.get()).toStrictEqual({ email: true, phone: true })

    connection.emitHandshakeResponse({
      data: { codeDelivery: { email: true, phone: false } },
    })

    expect(delivery.get()).toStrictEqual({ email: true, phone: false })

    connection.emitHandshakeResponse({
      data: { codeDelivery: { email: false, phone: false } },
    })

    expect(delivery.get()).toStrictEqual({ email: false, phone: false })
  })

  it('falls back to everything deliverable when the answer is unreadable', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const delivery = sessionCodeDelivery(scopes)

    // A half-written node is the case the two rules part on: the auth step drops
    // to null and the surface falls back to the identifier field, while a
    // delivery answer falls the other way rather than lock a working deployment.
    connection.emitHandshakeResponse({
      data: { codeDelivery: { email: false } },
    })

    expect(delivery.get()).toStrictEqual({ email: true, phone: true })

    connection.emitHandshakeResponse({ data: { codeDelivery: null } })

    expect(delivery.get()).toStrictEqual({ email: true, phone: true })
  })

  it('reads the enabled sign-in methods the handshake and the settings frame write (HIL-427)', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const methods = sessionAuthMethods(scopes)

    // Before the handshake the surface is not interactive, and no set is read as
    // no method rather than as an invented one.
    expect(methods.get()).toStrictEqual([])

    connection.emitHandshakeResponse({
      data: {
        authMethods: [
          { key: 'password', name: null },
          { key: 'oauth:github', name: 'GitHub' },
        ],
      },
    })
    expect(methods.get()).toStrictEqual([
      { key: 'password', name: null },
      { key: 'oauth:github', name: 'GitHub' },
    ])

    // An administrator switched the password off: the frame rewrites the same
    // slot, and whoever reads it cannot tell which of the two brought the set.
    connection.emit(SIGNAL_AUTH_METHODS, {
      authMethods: [{ key: 'oauth:github', name: 'GitHub' }],
    })
    expect(methods.get()).toStrictEqual([
      { key: 'oauth:github', name: 'GitHub' },
    ])
    expect(SESSION_SIGNAL_SCHEMAS[SIGNAL_AUTH_METHODS]).toBeDefined()
  })

  it('offers only the ready methods and keeps the unready ones enabled (HIL-1080)', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const offered = sessionAuthMethods(scopes)
    const enabled = sessionEnabledAuthMethods(scopes)

    connection.emitHandshakeResponse({
      data: {
        authMethods: [
          { key: 'password', name: null, ready: true },
          { key: 'oauth:google', name: 'Google', ready: false },
        ],
      },
    })
    expect(offered.get()).toStrictEqual([{ key: 'password', name: null }])
    expect(enabled.get()).toStrictEqual([
      { key: 'password', name: null },
      { key: 'oauth:google', name: 'Google' },
    ])

    // The administrator entered Google's pair: the provider page's frame says so.
    connection.emit(SIGNAL_AUTH_METHODS, {
      authMethods: [
        { key: 'password', name: null, ready: true },
        { key: 'oauth:google', name: 'Google', ready: true },
      ],
    })
    expect(offered.get()).toStrictEqual(enabled.get())
    expect(offered.get()).toContainEqual({
      key: 'oauth:google',
      name: 'Google',
    })

    // An entry that does not say whether it is ready is offered.
    connection.emit(SIGNAL_AUTH_METHODS, {
      authMethods: [{ key: 'oauth:google', name: 'Google' }],
    })
    expect(offered.get()).toStrictEqual([
      { key: 'oauth:google', name: 'Google' },
    ])
    expect(enabled.get()).toStrictEqual([
      { key: 'oauth:google', name: 'Google' },
    ])
  })

  it('drops a method entry it cannot read rather than guessing at it', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)

    connection.emitHandshakeResponse({
      data: { authMethods: [{ key: 'sms', name: null }, { name: 'no key' }] },
    })

    expect(sessionAuthMethods(scopes).get()).toStrictEqual([
      { key: 'sms', name: null },
    ])
  })

  it('reads the step a session was moved to, with the reason it was moved for', () => {
    // HIL-833: the address this session was registering became somebody else's
    // while it was offline, so the handshake reports the address field under the
    // sign-in intent — and the reason, which is the whole of what it has to say.
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const step = sessionPendingAuthStep(scopes)

    connection.emitHandshakeResponse({
      data: {
        pendingAuthStep: {
          identifier: 'ada@b.com',
          kind: 'email',
          intent: 'login',
          step: 'identifier',
          channel: null,
          expiresAt: null,
          code: 'identifier_taken',
        },
      },
    })

    expect(step.get()).toStrictEqual({
      identifier: 'ada@b.com',
      kind: 'email',
      intent: 'login',
      step: 'identifier',
      channel: null,
      expiresAt: null,
      code: 'identifier_taken',
    })
  })

  it('drops a code step that promises no moment, and an identifier step that promises one', () => {
    // The two halves of the same rule: the screens that count down are unreadable
    // without a deadline, and the one that does not count down would be drawn
    // against a deadline belonging to nothing.
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const step = sessionPendingAuthStep(scopes)

    connection.emitHandshakeResponse({
      data: {
        pendingAuthStep: {
          identifier: 'ada@b.com',
          kind: 'email',
          intent: 'register',
          step: 'code',
          channel: null,
          expiresAt: null,
          code: null,
        },
      },
    })
    expect(step.get()).toBeNull()

    connection.emitHandshakeResponse({
      data: {
        pendingAuthStep: {
          identifier: 'ada@b.com',
          kind: 'email',
          intent: 'login',
          step: 'identifier',
          channel: null,
          expiresAt: 1_700_000_000_000,
          code: 'identifier_taken',
        },
      },
    })

    expect(step.get()).toBeNull()
  })

  it('drops an identifier step that names no reason for being there', () => {
    // Nobody stands on the address field by not having finished something: it is
    // the screen a session is SENT back to, so a node naming it and saying nothing
    // else would take a person off the code screen they were using in silence.
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const step = sessionPendingAuthStep(scopes)

    connection.emitHandshakeResponse({
      data: {
        pendingAuthStep: {
          identifier: 'ada@b.com',
          kind: 'email',
          intent: 'login',
          step: 'identifier',
          channel: null,
          expiresAt: null,
          code: null,
        },
      },
    })

    expect(step.get()).toBeNull()
  })
})

describe('handshakeResponseAck', () => {
  it('returns a kind the frame carries', () => {
    expect(
      handshakeResponseAck({
        data: { pendingAck: SESSION_ACK_REGISTERED },
      }),
    ).toBe(SESSION_ACK_REGISTERED)
  })

  it('returns null when the frame carries null', () => {
    expect(handshakeResponseAck({ data: { pendingAck: null } })).toBeNull()
  })

  it('returns null when the frame carries the empty string', () => {
    expect(handshakeResponseAck({ data: { pendingAck: '' } })).toBeNull()
  })

  it('returns null when the plain section is absent', () => {
    expect(
      handshakeResponseAck({
        entities: { currentUser: { id: 1, name: 'Ada' } },
      }),
    ).toBeNull()
  })
})
