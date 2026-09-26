import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../src/connection/actionLifecycle.js'
import {
  ACCOUNT_BLOCKED_ACTION_DISMISS,
  bindAccountBlocked,
  dismissAccountBlocked,
  hilosAccountBlocked,
} from '../../src/session/accountBlocked.js'
import { bindSessionScope } from '../../src/session/sessionScope.js'
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

/** The anonymous handshake a browser gets after its account was blocked. */
const BLOCKED = {
  entities: { currentUser: null },
  data: { accountBlocked: { identifier: 'maria@example.com' } },
}

/** The handshake that follows Sign out or an unblock: no card any more. */
const CLEARED = {
  entities: { currentUser: null },
  data: { accountBlocked: null },
}

/** Boot the pieces the card reads: a session scope and a bound lifecycle. */
function bind() {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  const source = recordingSource()
  const unbind = bindAccountBlocked(scopes, new ActionLifecycle(source))

  return { connection, source, unbind }
}

describe('hilosAccountBlocked', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
  })

  it('is null before any bind', () => {
    expect(hilosAccountBlocked.get()).toBeNull()
  })

  it('stays null for a session that holds no card', () => {
    const booted = bind()
    unbind = booted.unbind

    booted.connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada' } },
      data: { accountBlocked: null },
    })

    expect(hilosAccountBlocked.get()).toBeNull()
  })

  it('names the blocked account while the handshake carries a card', () => {
    const booted = bind()
    unbind = booted.unbind

    booted.connection.emitHandshakeResponse(BLOCKED)

    expect(hilosAccountBlocked.get()).toEqual({
      identifier: 'maria@example.com',
    })
  })

  it('keeps the card when the account had no address to name', () => {
    const booted = bind()
    unbind = booted.unbind

    booted.connection.emitHandshakeResponse({
      data: { accountBlocked: { identifier: null } },
    })

    expect(hilosAccountBlocked.get()).toEqual({ identifier: null })
  })

  it('goes back to null when a handshake carries no card', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.emitHandshakeResponse(BLOCKED)

    booted.connection.emitHandshakeResponse(CLEARED)

    expect(hilosAccountBlocked.get()).toBeNull()
  })

  it('is null again once unbound', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(BLOCKED)

    booted.unbind()

    expect(hilosAccountBlocked.get()).toBeNull()
  })
})

describe('dismissAccountBlocked', () => {
  it('dispatches Sign out with an empty payload and a request id on the bound lifecycle', () => {
    const booted = bind()
    try {
      const handle = dismissAccountBlocked()
      // Never answered here; the driver's timeout must not surface as unhandled.
      handle.done.catch(() => {})

      expect(booted.source.sent).toEqual([
        {
          action: ACCOUNT_BLOCKED_ACTION_DISMISS,
          data: {},
          requestId: handle.requestId,
        },
      ])
      expect(handle.requestId).not.toBe('')
      expect(ACCOUNT_BLOCKED_ACTION_DISMISS).toBe(
        'hilos_dismiss_account_blocked',
      )
    } finally {
      booted.unbind()
    }
  })

  it('throws when nothing is bound', () => {
    expect(() => dismissAccountBlocked()).toThrow(Error)
  })
})
