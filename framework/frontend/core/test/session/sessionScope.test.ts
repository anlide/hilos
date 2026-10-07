import { describe, expect, it, vi } from 'vitest'
import {
  bindSessionScope,
  handshakeResponseAck,
  sessionUserName,
  sessionUserPhoto,
  sessionUserIsAdmin,
  sessionUserId,
  sessionAdminViewMode,
  sessionImpersonating,
  sessionImpersonatedByName,
  sessionPendingAck,
  sessionPendingAuthStep,
  sessionCodeDelivery,
  sessionAuthMethods,
  sessionEnabledAuthMethods,
  sessionPasskeyAllowsUnproven,
  sessionAccountBlocked,
  sessionAccountStanding,
  sessionSecondFactorPolicy,
  sessionImpersonationPolicy,
  DEFAULT_IMPERSONATION_POLICY,
  sessionThemeSettings,
  SESSION_ACK_REGISTERED,
  SIGNAL_AUTH_METHODS,
  SIGNAL_SECOND_FACTOR_POLICY,
  SIGNAL_IMPERSONATION_POLICY,
  SIGNAL_THEME_SETTINGS,
  SIGNAL_CODE_DELIVERY,
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

  it('takes a published photo and its removal from successive session identities', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const photo = sessionUserPhoto(scopes)

    expect(photo.get()).toBeNull()
    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, photo: null } },
    })
    expect(photo.get()).toBeNull()
    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, photo: '/_hilos/file?id=8' } },
    })
    expect(photo.get()).toBe('/_hilos/file?id=8')
    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, photo: null } },
    })
    expect(photo.get()).toBeNull()
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

  it('registers the delivery frame and shares its slot with every handshake (HIL-1102)', () => {
    const schema = SESSION_SIGNAL_SCHEMAS[SIGNAL_CODE_DELIVERY]
    expect(
      schema.safeParse({ codeDelivery: { email: true, phone: false } }).success,
    ).toBe(true)
    expect(schema.safeParse({ codeDelivery: { email: true } }).success).toBe(
      false,
    )
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const delivery = sessionCodeDelivery(scopes)
    connection.emitHandshakeResponse({
      data: { codeDelivery: { email: true, phone: true } },
    })

    connection.emit(SIGNAL_CODE_DELIVERY, {
      codeDelivery: { email: true, phone: false },
    })
    expect(delivery.get()).toStrictEqual({ email: true, phone: false })

    connection.emitHandshakeResponse({
      data: { codeDelivery: { email: false, phone: true } },
    })
    expect(delivery.get()).toStrictEqual({ email: false, phone: true })
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

  it('reads the passkey policy the handshake and the method-set frame write (HIL-1105)', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const allowed = sessionPasskeyAllowsUnproven(scopes)
    const methods = sessionEnabledAuthMethods(scopes)

    // Nothing said yet: the policy reads as no, the backend's own default.
    expect(allowed.get()).toBe(false)

    connection.emitHandshakeResponse({
      data: {
        authMethods: [{ key: 'passkey', name: null, ready: true }],
        passkeyAllowsUnproven: true,
      },
    })
    expect(allowed.get()).toBe(true)

    // The frame carries the set and the policy together and rewrites both.
    connection.emit(SIGNAL_AUTH_METHODS, {
      authMethods: [
        { key: 'password', name: null, ready: true },
        { key: 'passkey', name: null, ready: true },
      ],
      passkeyAllowsUnproven: false,
    })
    expect(allowed.get()).toBe(false)
    expect(methods.get()).toHaveLength(2)

    connection.emit(SIGNAL_AUTH_METHODS, {
      authMethods: [{ key: 'passkey', name: null, ready: true }],
      passkeyAllowsUnproven: true,
    })
    expect(allowed.get()).toBe(true)

    // A frame without the flag reads as no rather than keeping the old yes.
    connection.emit(SIGNAL_AUTH_METHODS, {
      authMethods: [{ key: 'passkey', name: null, ready: true }],
    })
    expect(allowed.get()).toBe(false)

    // A handshake whose flag is not a boolean is no as well.
    connection.emitHandshakeResponse({
      data: { passkeyAllowsUnproven: 'yes' },
    })
    expect(allowed.get()).toBe(false)
  })

  it("reads the node's admin view mode every handshake writes (HIL-1253)", () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const viewMode = sessionAdminViewMode(scopes)

    // Nothing said yet: off, the fail-closed default of the admin flag.
    expect(viewMode.get()).toBe(false)

    // A handshake that carries no key says nothing about the mode: off.
    connection.emitHandshakeResponse({ entities: { currentUser: null } })
    expect(viewMode.get()).toBe(false)

    connection.emitHandshakeResponse({
      entities: { currentUser: null },
      data: { adminViewMode: true },
    })
    expect(viewMode.get()).toBe(true)

    // The next handshake rewrites it, and only a true is on.
    connection.emitHandshakeResponse({ data: { adminViewMode: false } })
    expect(viewMode.get()).toBe(false)

    connection.emitHandshakeResponse({ data: { adminViewMode: true } })
    connection.emitHandshakeResponse({ data: { adminViewMode: null } })
    expect(viewMode.get()).toBe(false)

    connection.emitHandshakeResponse({ data: { adminViewMode: 'true' } })
    expect(viewMode.get()).toBe(false)
  })

  it('hands a listener of the admin flag the admin view mode of the same response (HIL-1253)', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const admin = sessionUserIsAdmin(scopes)
    const viewMode = sessionAdminViewMode(scopes)
    const seen: boolean[] = []
    subscribeSignal(admin, () => seen.push(viewMode.get()))

    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada', admin: true } },
      data: { adminViewMode: true },
    })
    connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada', admin: false } },
      data: { adminViewMode: false },
    })

    // The data section lands ahead of the entities, so neither read is a frame late.
    expect(seen).toEqual([true, false])
  })

  it('reads the "Access closed" card every handshake writes (HIL-289)', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const card = sessionAccountBlocked(scopes)

    // Nothing said yet: no card.
    expect(card.get()).toBeNull()

    connection.emitHandshakeResponse({
      data: { accountBlocked: { identifier: 'maria@example.com' } },
    })
    expect(card.get()).toEqual({
      identifier: 'maria@example.com',
      dataExport: null,
    })

    // A card is a card without an address, and an empty address names nobody.
    connection.emitHandshakeResponse({
      data: { accountBlocked: { identifier: '' } },
    })
    expect(card.get()).toEqual({ identifier: null, dataExport: null })

    // The next handshake without a card takes it down by overwriting the key.
    connection.emitHandshakeResponse({ data: { accountBlocked: null } })
    expect(card.get()).toBeNull()

    // Anything that is not a node reads as no card.
    connection.emitHandshakeResponse({ data: { accountBlocked: 'blocked' } })
    expect(card.get()).toBeNull()
  })

  it('reads the standing every handshake writes, on the local clock (HIL-945)', () => {
    vi.useFakeTimers()
    vi.setSystemTime(LOCAL_NOW)
    try {
      const connection = fakeConnection()
      const scopes = new ScopeManager()
      bindSessionScope(connection as unknown as HilosConnection, scopes)
      const standing = sessionAccountStanding(scopes)

      // Nothing said yet, and an anonymous session: no standing.
      expect(standing.get()).toBeNull()
      connection.emitHandshakeResponse({ data: { accountStanding: null } })
      expect(standing.get()).toBeNull()

      connection.emitHandshakeResponse({
        data: {
          serverTimeMs: LOCAL_NOW + SERVER_DRIFT_MS,
          accountStanding: {
            shown: 'frozen',
            blocked: false,
            frozen: true,
            deletionEffectiveAt: LOCAL_NOW + SERVER_DRIFT_MS + 86_400_000,
            lapsed: [
              { document: 'terms', deadline: '2026-09-01' },
              // A document this client does not know drops out alone.
              { document: 'cookies', deadline: '2026-09-01' },
              { document: 'privacy', deadline: null },
            ],
            // The documents still inside their window are read the same way (HIL-500).
            window: [
              { document: 'privacy', deadline: '2026-11-10' },
              { document: 'cookies', deadline: '2026-11-10' },
              'garbage',
            ],
          },
        },
      })
      expect(standing.get()).toEqual({
        shown: 'frozen',
        blocked: false,
        frozen: true,
        deletionEffectiveAt: LOCAL_NOW + 86_400_000,
        lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
        window: [{ document: 'privacy', deadline: '2026-11-10' }],
      })

      // A hidden mark, or any shape that is not a standing, reads as none.
      connection.emitHandshakeResponse({
        data: { accountStanding: { _hidden: true } },
      })
      expect(standing.get()).toBeNull()
      connection.emitHandshakeResponse({
        data: {
          accountStanding: {
            shown: 'suspended',
            blocked: false,
            frozen: false,
            deletionEffectiveAt: null,
            lapsed: [],
            window: [],
          },
        },
      })
      expect(standing.get()).toBeNull()
    } finally {
      applyServerTime(Date.now())
      vi.useRealTimers()
    }
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
      secondFactor: null,
      acceptedRevisions: null,
    })
  })

  it.each(['code', 'set_password'])(
    'reads accepted revisions on a pending registration %s step',
    (step) => {
      const connection = fakeConnection()
      const scopes = new ScopeManager()
      bindSessionScope(connection as unknown as HilosConnection, scopes)
      const acceptedRevisions = { terms: 'terms-1', privacy: 'privacy-1' }
      const pending = {
        identifier: 'ada@b.com',
        kind: 'email',
        intent: 'register',
        step,
        expiresAt: Date.now() + 60_000,
      }
      connection.emitHandshakeResponse({
        data: { pendingAuthStep: { ...pending, acceptedRevisions } },
      })
      expect(sessionPendingAuthStep(scopes).get()?.acceptedRevisions).toEqual(
        acceptedRevisions,
      )
      connection.emitHandshakeResponse({ data: { pendingAuthStep: pending } })
      expect(sessionPendingAuthStep(scopes).get()?.acceptedRevisions).toBeNull()
    },
  )

  it.each([false, 'terms-1', [], ['terms-1'], { terms: '' }, { terms: 3 }])(
    'drops a pending registration with malformed acceptance %j',
    (acceptedRevisions) => {
      const connection = fakeConnection()
      const scopes = new ScopeManager()
      bindSessionScope(connection as unknown as HilosConnection, scopes)
      connection.emitHandshakeResponse({
        data: {
          pendingAuthStep: {
            identifier: 'ada@b.com',
            kind: 'email',
            intent: 'register',
            step: 'code',
            expiresAt: Date.now() + 60_000,
            acceptedRevisions,
          },
        },
      })
      expect(sessionPendingAuthStep(scopes).get()).toBeNull()
    },
  )

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

describe('a sign-in held on its second factor (HIL-494)', () => {
  it('reads the code step, naming nobody, with its moments put on the local scale', () => {
    vi.useFakeTimers()
    vi.setSystemTime(LOCAL_NOW)
    try {
      const connection = fakeConnection()
      const scopes = new ScopeManager()
      bindSessionScope(connection as unknown as HilosConnection, scopes)
      const step = sessionPendingAuthStep(scopes)

      connection.emitHandshakeResponse({
        data: {
          serverTimeMs: LOCAL_NOW + SERVER_DRIFT_MS,
          pendingAuthStep: {
            identifier: null,
            kind: null,
            intent: 'login',
            step: 'second_factor',
            channel: null,
            expiresAt: LOCAL_NOW + SERVER_DRIFT_MS + 60_000,
            code: null,
            secondFactor: {
              trustDeviceDays: 30,
              resetEffectiveAt: LOCAL_NOW + SERVER_DRIFT_MS + 86_400_000,
            },
          },
        },
      })

      expect(step.get()).toStrictEqual({
        identifier: null,
        kind: null,
        intent: 'login',
        step: 'second_factor',
        channel: null,
        expiresAt: LOCAL_NOW + 60_000,
        code: null,
        secondFactor: {
          trustDeviceDays: 30,
          resetEffectiveAt: LOCAL_NOW + 86_400_000,
        },
        acceptedRevisions: null,
      })
    } finally {
      applyServerTime(Date.now())
      vi.useRealTimers()
    }
  })

  it('reads the enrolment on the way in, whose trust may be none', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const step = sessionPendingAuthStep(scopes)

    connection.emitHandshakeResponse({
      data: {
        pendingAuthStep: {
          identifier: null,
          kind: null,
          intent: 'login',
          step: 'second_factor_setup',
          channel: null,
          expiresAt: 1_700_000_000_000,
          code: null,
          secondFactor: { trustDeviceDays: null, resetEffectiveAt: null },
        },
      },
    })

    expect(step.get()).toMatchObject({
      step: 'second_factor_setup',
      secondFactor: { trustDeviceDays: null, resetEffectiveAt: null },
    })
  })

  it('drops a second-factor step that names somebody, counts to nothing or carries no data', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const step = sessionPendingAuthStep(scopes)
    const node = {
      identifier: null,
      kind: null,
      intent: 'login',
      step: 'second_factor',
      channel: null,
      expiresAt: 1_700_000_000_000,
      code: null,
      secondFactor: { trustDeviceDays: 30, resetEffectiveAt: null },
    }

    for (const broken of [
      { ...node, identifier: 'ada@b.com', kind: 'email' },
      { ...node, expiresAt: null },
      { ...node, secondFactor: null },
      { ...node, secondFactor: { trustDeviceDays: 30 } },
    ]) {
      connection.emitHandshakeResponse({ data: { pendingAuthStep: broken } })
      expect(step.get()).toBeNull()
    }
  })

  it('reads the field a let-go wait sends a session to, with or without a reason', () => {
    // Another tab stepped back, or the factor was switched off: news without a
    // sentence, told apart from a lost race by naming nobody.
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const step = sessionPendingAuthStep(scopes)
    const released = {
      identifier: null,
      kind: null,
      intent: 'login',
      step: 'identifier',
      channel: null,
      expiresAt: null,
      code: null,
    }

    connection.emitHandshakeResponse({ data: { pendingAuthStep: released } })
    expect(step.get()).toStrictEqual({
      ...released,
      secondFactor: null,
      acceptedRevisions: null,
    })

    connection.emitHandshakeResponse({
      data: {
        pendingAuthStep: { ...released, code: 'second_factor_attempts' },
      },
    })
    expect(step.get()).toMatchObject({
      step: 'identifier',
      identifier: null,
      code: 'second_factor_attempts',
    })
  })

  it('keeps the policy frame an administrator sends, and nothing before it', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const policy = sessionSecondFactorPolicy(scopes)
    const frame = {
      required: 'everyone',
      trustDays: 0,
      backupCodes: 12,
      resetWaitDefaultDays: 8,
      resetWaitMinDays: 2,
      resetWaitMaxDays: 30,
    }

    expect(SESSION_SIGNAL_SCHEMAS[SIGNAL_SECOND_FACTOR_POLICY]).toBeDefined()
    expect(policy.get()).toBeNull()

    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, frame)
    expect(policy.get()).toStrictEqual(frame)

    const first = policy.get()
    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, { ...frame })
    // Every frame is a new value: that is how a reader tells a frame newer
    // than its answer from the one it was answered under.
    expect(policy.get()).not.toBe(first)
  })
})

describe('the impersonation policy (HIL-1170, HIL-1307)', () => {
  it('reads the defaults until a handshake says otherwise', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const policy = sessionImpersonationPolicy(scopes)

    // Nothing said yet: defaults from ImpersonationSettings.
    expect(policy.get()).toStrictEqual(DEFAULT_IMPERSONATION_POLICY)

    const fullPolicy = {
      viewOnly: true,
      carryAdmin: true,
      allowed: true,
      accountAccess: false,
      blocked: false,
      frozen: true,
      equal: false,
    }

    connection.emitHandshakeResponse({
      data: { impersonationPolicy: fullPolicy },
    })
    expect(policy.get()).toStrictEqual(fullPolicy)

    // An unreadable node on initial empty slot falls back to the defaults rather than guessing.
    const emptyScopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, emptyScopes)
    const emptyPolicy = sessionImpersonationPolicy(emptyScopes)

    connection.emitHandshakeResponse({
      data: { impersonationPolicy: { viewOnly: 'yes', carryAdmin: true } },
    })
    expect(emptyPolicy.get()).toStrictEqual(DEFAULT_IMPERSONATION_POLICY)

    connection.emitHandshakeResponse({ data: { impersonationPolicy: null } })
    expect(emptyPolicy.get()).toStrictEqual(DEFAULT_IMPERSONATION_POLICY)
  })

  it('registers the policy frame and shares its slot with every handshake', () => {
    const schema = SESSION_SIGNAL_SCHEMAS[SIGNAL_IMPERSONATION_POLICY]
    expect(SIGNAL_IMPERSONATION_POLICY).toBe('hilos_impersonation_policy')
    const valid = {
      viewOnly: true,
      carryAdmin: false,
      allowed: true,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: true,
    }
    expect(schema.safeParse(valid).success).toBe(true)
    expect(schema.safeParse({ viewOnly: true }).success).toBe(false)
    expect(schema.safeParse({ ...valid, viewOnly: 'yes' }).success).toBe(false)

    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const policy = sessionImpersonationPolicy(scopes)

    const initial = {
      viewOnly: false,
      carryAdmin: false,
      allowed: true,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: true,
    }
    connection.emitHandshakeResponse({
      data: { impersonationPolicy: initial },
    })
    expect(policy.get()).toStrictEqual(initial)

    const liveUpdated = {
      viewOnly: true,
      carryAdmin: false,
      allowed: false,
      accountAccess: true,
      blocked: false,
      frozen: false,
      equal: false,
    }
    connection.emit(SIGNAL_IMPERSONATION_POLICY, liveUpdated)
    expect(policy.get()).toStrictEqual(liveUpdated)

    // A malformed live frame does not overwrite the active value.
    connection.emit(SIGNAL_IMPERSONATION_POLICY, {
      viewOnly: false,
      carryAdmin: true,
    })
    expect(policy.get()).toStrictEqual(liveUpdated)

    // A malformed reconnect handshake does not overwrite the active value.
    connection.emitHandshakeResponse({
      data: { impersonationPolicy: { invalid: true } },
    })
    expect(policy.get()).toStrictEqual(liveUpdated)

    // A valid reconnect handshake updates the slot.
    const reconnected = {
      viewOnly: false,
      carryAdmin: true,
      allowed: true,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: true,
    }
    connection.emitHandshakeResponse({
      data: { impersonationPolicy: reconnected },
    })
    expect(policy.get()).toStrictEqual(reconnected)
  })
})

describe('the theme settings (HIL-1428)', () => {
  it('parses the whole pair and rejects missing or invalid values', () => {
    const schema = SESSION_SIGNAL_SCHEMAS[SIGNAL_THEME_SETTINGS]
    expect(SIGNAL_THEME_SETTINGS).toBe('hilos_theme_settings')
    expect(
      schema.safeParse({ switchingEnabled: false, defaultTheme: 'dark' })
        .success,
    ).toBe(true)
    expect(schema.safeParse({ switchingEnabled: false }).success).toBe(false)
    expect(
      schema.safeParse({ switchingEnabled: 'false', defaultTheme: 'dark' })
        .success,
    ).toBe(false)
    expect(
      schema.safeParse({ switchingEnabled: true, defaultTheme: 'sepia' })
        .success,
    ).toBe(false)
  })

  it('takes the same slot from a guest handshake, live frame and reconnect', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const settings = sessionThemeSettings(scopes)

    expect(settings.get()).toStrictEqual({
      switchingEnabled: true,
      defaultTheme: 'system',
    })
    connection.emitHandshakeResponse({
      data: {
        themeSettings: { switchingEnabled: false, defaultTheme: 'dark' },
      },
    })
    expect(settings.get()).toStrictEqual({
      switchingEnabled: false,
      defaultTheme: 'dark',
    })

    connection.emit(SIGNAL_THEME_SETTINGS, {
      switchingEnabled: true,
      defaultTheme: 'light',
    })
    expect(settings.get()).toStrictEqual({
      switchingEnabled: true,
      defaultTheme: 'light',
    })

    connection.emitHandshakeResponse({
      data: {
        themeSettings: { switchingEnabled: false, defaultTheme: 'system' },
      },
    })
    expect(settings.get()).toStrictEqual({
      switchingEnabled: false,
      defaultTheme: 'system',
    })

    connection.emitHandshakeResponse({
      data: {
        themeSettings: { switchingEnabled: true, defaultTheme: 'sepia' },
      },
    })
    expect(settings.get()).toStrictEqual({
      switchingEnabled: true,
      defaultTheme: 'system',
    })
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
