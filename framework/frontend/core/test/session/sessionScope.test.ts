import { describe, expect, it } from 'vitest'
import {
  bindSessionScope,
  sessionUserName,
  SESSION_SIGNAL_SCHEMAS,
} from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { type HilosConnection, type ProjectSignal } from '../../src/index.js'

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
})
