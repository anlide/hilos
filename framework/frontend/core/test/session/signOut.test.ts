import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../src/connection/actionLifecycle.js'
import {
  bindSignOut,
  hilosSignedIn,
  SIGN_OUT_ACTION,
  signOut,
} from '../../src/session/signOut.js'
import {
  bindSessionScope,
  type SessionScopeOptions,
} from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { type HilosConnection, type ProjectSignal } from '../../src/index.js'

/** A connection double replaying handshake responses into the session scope. */
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

/** A lifecycle source that records what was sent and never answers. */
function recordingSource(): ActionLifecycleSource & {
  readonly sent: { action: string; data: unknown; requestId?: string }[]
} {
  const sent: { action: string; data: unknown; requestId?: string }[] = []

  return {
    sent,
    sendAction(action: string, data: unknown, requestId?: string): boolean {
      sent.push({ action, data, requestId })

      return true
    },
    on(): () => void {
      return () => {}
    },
  }
}

const SIGNED_IN = { entities: { currentUser: { id: 1, name: 'Ada' } } }
const ANONYMOUS = { entities: { currentUser: null } }
const releases: Array<() => void> = []

/** Bind the same session scope and action lifecycle the shell reads. */
function bind(options: SessionScopeOptions = {}) {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes, options)
  const source = recordingSource()
  const unbind = bindSignOut(scopes, new ActionLifecycle(source), options)
  releases.push(unbind)

  return { connection, source, unbind }
}

beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  for (const release of releases.splice(0)) release()
  vi.clearAllTimers()
  vi.useRealTimers()
})

describe('hilosSignedIn', () => {
  it('is false before any bind', () => {
    expect(hilosSignedIn.get()).toBe(false)
  })

  it('stays false for an anonymous handshake', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(ANONYMOUS)

    expect(hilosSignedIn.get()).toBe(false)
  })

  it.each(['Ada', ''])('uses the user id even when the name is %j', (name) => {
    const booted = bind()
    booted.connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name } },
    })

    expect(hilosSignedIn.get()).toBe(true)
  })

  it('follows the configured current-user slot', () => {
    const booted = bind({ currentUserSlot: 'person' })
    booted.connection.emitHandshakeResponse({
      entities: { person: { id: 1, name: 'Ada' } },
    })

    expect(hilosSignedIn.get()).toBe(true)
  })

  it('goes back to false when a later handshake clears the user', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(SIGNED_IN)
    booted.connection.emitHandshakeResponse(ANONYMOUS)

    expect(hilosSignedIn.get()).toBe(false)
  })

  it('stops following the session once unbound', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(SIGNED_IN)
    booted.unbind()
    expect(hilosSignedIn.get()).toBe(false)

    booted.connection.emitHandshakeResponse(ANONYMOUS)
    booted.connection.emitHandshakeResponse(SIGNED_IN)
    expect(hilosSignedIn.get()).toBe(false)
  })

  it('does not let a stale release undo a newer binding', () => {
    const previous = bind()
    const current = bind()
    current.connection.emitHandshakeResponse(SIGNED_IN)
    previous.unbind()

    expect(hilosSignedIn.get()).toBe(true)
    const handle = signOut()
    handle.done.catch(() => {})
    expect(previous.source.sent).toEqual([])
    expect(current.source.sent[0]?.requestId).toBe(handle.requestId)

    previous.connection.emitHandshakeResponse(SIGNED_IN)
    current.connection.emitHandshakeResponse(ANONYMOUS)
    expect(hilosSignedIn.get()).toBe(false)
  })
})

describe('signOut', () => {
  it('dispatches hilos_logout with an empty payload and a request id', () => {
    const booted = bind()
    const handle = signOut()
    // Never answered here; the driver's timeout must not surface as unhandled.
    handle.done.catch(() => {})

    expect(booted.source.sent).toEqual([
      { action: 'hilos_logout', data: {}, requestId: handle.requestId },
    ])
    expect(handle.requestId).not.toBe('')
    expect(SIGN_OUT_ACTION).toBe('hilos_logout')
  })

  it('throws when nothing is bound', () => {
    expect(() => signOut()).toThrow(
      'signOut() before bindSignOut(): bootHilos binds the control.',
    )
  })
})
