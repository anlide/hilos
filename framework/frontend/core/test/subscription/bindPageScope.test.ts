import { describe, expect, it } from 'vitest'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../src/subscription/bindPageScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '../../src/protocol/constants.js'
import { type ConnectionState } from '../../src/connection/HilosConnection.js'
import { type HilosConnection, type ProjectSignal } from '../../src/index.js'

/**
 * A connection double recording sent frames and replaying project signals. It
 * is the slice bindPageScope touches — the manager's `state`/`send`/`on('state')`
 * plus `on('projectSignal')` — cast to the full connection type.
 */
function fakeConnection() {
  const stateListeners: Array<(state: ConnectionState) => void> = []
  const projectListeners: Array<(signal: ProjectSignal) => void> = []
  const double = {
    state: 'connected' as ConnectionState,
    sent: [] as Array<Record<string, unknown>>,
    send(text: string): boolean {
      this.sent.push(JSON.parse(text) as Record<string, unknown>)

      return true
    },
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'state') {
        stateListeners.push(listener as (state: ConnectionState) => void)
      }
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
    emitPageResponse(page: string, payload: Record<string, unknown>): void {
      const signal = {
        kind: 'project',
        type: SIGNAL_TYPE_PAGE_RESPONSE,
        data: { page, payload },
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
  }

  return double
}

describe('bindPageScope', () => {
  it('exposes the page_response schema keyed for projectSchemas', () => {
    expect(PAGE_SIGNAL_SCHEMAS[SIGNAL_TYPE_PAGE_RESPONSE]).toBeDefined()
  })

  it('returns a manager that subscribes the page over the connection', () => {
    const connection = fakeConnection()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      new ScopeManager(),
    )

    pages.subscribe('main')

    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
    ])
    expect(pages.pageKey()).toBe('main')
  })

  it('ingests a page_response for the current page into the page scope', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      scopes,
    )

    pages.subscribe('main')
    connection.emitPageResponse('main', { data: { greeting: 'hi' } })

    expect(scopes.page()?.data.signal('greeting').get()).toBe('hi')
  })

  it('drops a page_response for a page other than the current one', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      scopes,
    )

    pages.subscribe('main')
    connection.emitPageResponse('profile', { data: { greeting: 'hi' } })

    expect(scopes.page()?.data.signal('greeting').get()).toBeUndefined()
  })

  it('applies the binding entity types to every page payload', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      scopes,
      {
        entityTypes: { author: 'user' },
      },
    )

    pages.subscribe('main')
    connection.emitPageResponse('main', {
      entities: { author: { id: 7, name: 'Ada' } },
    })

    // The slot aliased `author` resolves under the canonical `user` type.
    const ref = scopes.page()?.data.signal('author').get() as
      | { type: string; id: number }
      | undefined
    expect(ref?.type).toBe('user')
    expect(ref?.id).toBe(7)
  })
})
