import { describe, expect, it, vi } from 'vitest'
import {
  bindSessionScope,
  sessionUserName,
  sessionUserIsAdmin,
  sessionUserId,
  sessionImpersonating,
  sessionImpersonatedByName,
  sessionPendingAck,
  SESSION_ACK_REGISTERED,
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
      const signal = {
        kind: 'project',
        type: 'handshake_response',
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
})
