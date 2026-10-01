import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../src/connection/actionLifecycle.js'
import {
  bindImpersonation,
  hilosImpersonation,
  hilosTakeoverViewOnly,
  IMPERSONATION_ACTION_STOP,
  stopImpersonation,
} from '../../src/session/impersonation.js'
import {
  bindSessionScope,
  SIGNAL_IMPERSONATION_POLICY,
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

    // No standing named: the strip keeps its plain yellow (HIL-945).
    expect(hilosImpersonation.get()).toEqual({
      userName: 'Bob',
      tone: 'warning',
      viewOnly: false,
    })
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

describe('a takeover that only looks (HIL-1170)', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
  })

  /**
   * The takeover handshake carrying the installation's policy.
   *
   * @param viewOnly Whether the administrator may only look.
   */
  function takeoverWith(viewOnly: boolean): Record<string, unknown> {
    return {
      ...TAKEOVER,
      data: { impersonationPolicy: { viewOnly, carryAdmin: false } },
    }
  }

  it('is false before any bind and for a plain session', () => {
    expect(hilosTakeoverViewOnly.get()).toBe(false)

    const booted = bind()
    unbind = booted.unbind
    booted.connection.emitHandshakeResponse({
      entities: { currentUser: { id: 1, name: 'Ada' } },
      data: { impersonationPolicy: { viewOnly: true, carryAdmin: false } },
    })

    expect(hilosImpersonation.get()).toBeNull()
    expect(hilosTakeoverViewOnly.get()).toBe(false)
  })

  it('marks the strip and the flag from the policy the handshake carries', () => {
    const booted = bind()
    unbind = booted.unbind

    booted.connection.emitHandshakeResponse(takeoverWith(true))

    expect(hilosImpersonation.get()).toEqual({
      userName: 'Bob',
      tone: 'warning',
      viewOnly: true,
    })
    expect(hilosTakeoverViewOnly.get()).toBe(true)
  })

  it('follows the policy frame live, and drops with the takeover', () => {
    const booted = bind()
    unbind = booted.unbind
    booted.connection.emitHandshakeResponse(takeoverWith(false))
    expect(hilosTakeoverViewOnly.get()).toBe(false)

    booted.connection.emit(SIGNAL_IMPERSONATION_POLICY, {
      viewOnly: true,
      carryAdmin: false,
    })
    expect(hilosImpersonation.get()?.viewOnly).toBe(true)
    expect(hilosTakeoverViewOnly.get()).toBe(true)

    booted.connection.emitHandshakeResponse(RESTORED)
    expect(hilosTakeoverViewOnly.get()).toBe(false)
  })

  it('is false again once unbound', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(takeoverWith(true))

    booted.unbind()

    expect(hilosTakeoverViewOnly.get()).toBe(false)
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
