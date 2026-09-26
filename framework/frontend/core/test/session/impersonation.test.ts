import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../src/connection/actionLifecycle.js'
import {
  bindImpersonation,
  hilosImpersonation,
  IMPERSONATION_ACTION_STOP,
  stopImpersonation,
} from '../../src/session/impersonation.js'
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

/** A handshake naming Bob as the user and Ada as the administrator behind him. */
const TAKEOVER = {
  entities: {
    currentUser: { id: 2, name: 'Bob' },
    impersonatedBy: { id: 1, name: 'Ada' },
  },
}

/** The handshake that follows Stop: Ada is herself again. */
const RESTORED = {
  entities: { currentUser: { id: 1, name: 'Ada' }, impersonatedBy: null },
}

/** Boot the pieces the strip reads: a session scope and a bound lifecycle. */
function bind() {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  const source = recordingSource()
  const unbind = bindImpersonation(scopes, new ActionLifecycle(source))

  return { connection, source, unbind }
}

describe('hilosImpersonation', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
  })

  it('is null before any bind', () => {
    expect(hilosImpersonation.get()).toBeNull()
  })

  it('stays null for a plain session', () => {
    const booted = bind()
    unbind = booted.unbind

    booted.connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada' } },
    })

    expect(hilosImpersonation.get()).toBeNull()
  })

  it('names the user the session acts as while impersonatedBy is set', () => {
    const booted = bind()
    unbind = booted.unbind

    booted.connection.emitHandshakeResponse(TAKEOVER)

    expect(hilosImpersonation.get()).toEqual({ userName: 'Bob' })
  })

  it('goes back to null when the slot clears', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.emitHandshakeResponse(TAKEOVER)

    booted.connection.emitHandshakeResponse(RESTORED)

    expect(hilosImpersonation.get()).toBeNull()
  })

  it('is null again once unbound', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(TAKEOVER)

    booted.unbind()

    expect(hilosImpersonation.get()).toBeNull()
  })
})

describe('stopImpersonation', () => {
  it('dispatches Stop with an empty payload and a request id on the bound lifecycle', () => {
    const booted = bind()
    try {
      const handle = stopImpersonation()
      // Never answered here; the driver's timeout must not surface as unhandled.
      handle.done.catch(() => {})

      expect(booted.source.sent).toEqual([
        {
          action: IMPERSONATION_ACTION_STOP,
          data: {},
          requestId: handle.requestId,
        },
      ])
      expect(handle.requestId).not.toBe('')
      expect(IMPERSONATION_ACTION_STOP).toBe('hilos_impersonate_stop')
    } finally {
      booted.unbind()
    }
  })

  it('throws when nothing is bound', () => {
    expect(() => stopImpersonation()).toThrow(Error)
  })
})
