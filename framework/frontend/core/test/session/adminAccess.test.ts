import { afterEach, describe, expect, it } from 'vitest'
import {
  bindAdminAccess,
  hilosAdminAccess,
} from '../../src/session/adminAccess.js'
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

/**
 * One handshake response: who is behind the session, if anybody, and the node's
 * admin view mode, both as the backend stamps them.
 *
 * @param user The person behind the session, or null for a guest.
 * @param viewMode The node's admin view mode.
 */
function response(
  user: { id: number; admin: boolean } | null,
  viewMode: boolean,
): Record<string, unknown> {
  return {
    entities: {
      currentUser: user === null ? null : { ...user, name: 'Olena' },
    },
    data: { adminViewMode: viewMode },
  }
}

const releases: Array<() => void> = []

/** Bind the same session scope and access the shell reads. */
function bind(options: SessionScopeOptions = {}) {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes, options)
  const unbind = bindAdminAccess(scopes, options)
  releases.push(unbind)

  return { connection, unbind }
}

afterEach(() => {
  for (const release of releases.splice(0)) release()
})

describe('hilosAdminAccess', () => {
  it('is none before any bind', () => {
    expect(hilosAdminAccess.get()).toBe('none')
  })

  it('is none before the first handshake', () => {
    bind()

    expect(hilosAdminAccess.get()).toBe('none')
  })

  it('is none for a guest on a node without the view mode', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(response(null, false))

    expect(hilosAdminAccess.get()).toBe('none')
  })

  it('is view for a guest on a node in the view mode', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(response(null, true))

    expect(hilosAdminAccess.get()).toBe('view')
  })

  it('is none for a signed-in non-admin without the view mode', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(
      response({ id: 7, admin: false }, false),
    )

    expect(hilosAdminAccess.get()).toBe('none')
  })

  it('is view for a signed-in non-admin in the view mode', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(
      response({ id: 7, admin: false }, true),
    )

    expect(hilosAdminAccess.get()).toBe('view')
  })

  it.each([true, false])(
    'is full for an admin whatever the view mode (%s)',
    (viewMode) => {
      const booted = bind()
      booted.connection.emitHandshakeResponse(
        response({ id: 7, admin: true }, viewMode),
      )

      expect(hilosAdminAccess.get()).toBe('full')
    },
  )

  it('follows a grant and a revoke on the next handshake', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(
      response({ id: 7, admin: false }, true),
    )
    expect(hilosAdminAccess.get()).toBe('view')

    booted.connection.emitHandshakeResponse(
      response({ id: 7, admin: true }, true),
    )
    expect(hilosAdminAccess.get()).toBe('full')

    booted.connection.emitHandshakeResponse(
      response({ id: 7, admin: false }, true),
    )
    expect(hilosAdminAccess.get()).toBe('view')
  })

  it('is full inside a takeover that carries the administrator rights in (HIL-1170)', () => {
    const booted = bind()
    const takeover = (carryAdmin: boolean): Record<string, unknown> => ({
      entities: {
        currentUser: { id: 7, name: 'Olena', admin: false },
        impersonatedBy: { id: 1, name: 'Ada' },
      },
      data: {
        adminViewMode: false,
        impersonationPolicy: { viewOnly: false, carryAdmin },
      },
    })

    booted.connection.emitHandshakeResponse(takeover(false))
    expect(hilosAdminAccess.get()).toBe('none')

    booted.connection.emitHandshakeResponse(takeover(true))
    expect(hilosAdminAccess.get()).toBe('full')
  })

  it('does not carry rights outside a takeover', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse({
      entities: { currentUser: { id: 7, name: 'Olena', admin: false } },
      data: {
        adminViewMode: false,
        impersonationPolicy: { viewOnly: false, carryAdmin: true },
      },
    })

    expect(hilosAdminAccess.get()).toBe('none')
  })

  it('follows the configured current-user slot', () => {
    const booted = bind({ currentUserSlot: 'person' })
    booted.connection.emitHandshakeResponse({
      entities: { person: { id: 7, name: 'Olena', admin: true } },
      data: { adminViewMode: false },
    })

    expect(hilosAdminAccess.get()).toBe('full')
  })

  it('falls back to none once unbound and stops following the session', () => {
    const booted = bind()
    booted.connection.emitHandshakeResponse(response(null, true))
    booted.unbind()
    expect(hilosAdminAccess.get()).toBe('none')

    booted.connection.emitHandshakeResponse(
      response({ id: 7, admin: true }, true),
    )
    expect(hilosAdminAccess.get()).toBe('none')
  })

  it('does not let a stale release undo a newer binding', () => {
    const previous = bind()
    const current = bind()
    current.connection.emitHandshakeResponse(response(null, true))
    previous.unbind()

    expect(hilosAdminAccess.get()).toBe('view')

    previous.connection.emitHandshakeResponse(
      response({ id: 7, admin: true }, true),
    )
    expect(hilosAdminAccess.get()).toBe('view')
    current.connection.emitHandshakeResponse(
      response({ id: 7, admin: true }, true),
    )
    expect(hilosAdminAccess.get()).toBe('full')
  })
})
