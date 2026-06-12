import { describe, expect, it } from 'vitest'
import { PageSubscription } from '../../src/subscription/PageSubscription.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { type EntityRef } from '../../src/state/EntityStore.js'
import { type ConnectionState } from '../../src/connection/HilosConnection.js'

/** A connection double recording sent frames and replaying state changes. */
function fakeConnection(initialState: ConnectionState = 'connected') {
  const listeners: Array<(state: ConnectionState) => void> = []
  const sent: Array<Record<string, unknown>> = []

  return {
    state: initialState,
    sent,
    setState(state: ConnectionState) {
      this.state = state
      for (const listener of listeners) {
        listener(state)
      }
    },
    send(text: string): boolean {
      if (this.state !== 'connected') {
        return false
      }
      sent.push(JSON.parse(text) as Record<string, unknown>)

      return true
    },
    on(_event: 'state', listener: (state: ConnectionState) => void) {
      listeners.push(listener)

      return () => {}
    },
  }
}

describe('PageSubscription', () => {
  it('subscribing while connected sends one page_subscribe frame', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)

    pages.subscribe('main')

    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
    ])
    expect(pages.pageKey()).toBe('main')
    expect(scopes.page()?.key).toBe('main')
  })

  it('subscribing while disconnected sends on the next connected transition', () => {
    const connection = fakeConnection('disconnected')
    const pages = new PageSubscription(connection, new ScopeManager())

    pages.subscribe('main', { tab: 'open' })
    expect(connection.sent).toEqual([])

    connection.setState('connecting')
    connection.setState('connected')
    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: { tab: 'open' } },
    ])
  })

  it('re-sends the current subscription after a reconnect', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')

    connection.setState('reconnecting')
    connection.setState('connected')

    expect(connection.sent).toHaveLength(2)
    expect(connection.sent[1]).toEqual({
      type: 'page_subscribe',
      page: 'main',
      params: {},
    })
  })

  it('subscribing another page atomically replaces scope and subscription', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)

    const first = pages.subscribe('main')
    first.data.set('marker', 1)
    pages.subscribe('profile')

    expect(scopes.page()?.key).toBe('profile')
    expect(scopes.page()?.data.signal('marker').get()).toBeUndefined()
    expect(connection.sent.map((frame) => frame['page'])).toEqual([
      'main',
      'profile',
    ])
  })

  it('unsubscribe drops the page scope and tells the backend', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.subscribe('main')

    pages.unsubscribe()

    expect(pages.pageKey()).toBeUndefined()
    expect(scopes.page()).toBeUndefined()
    expect(connection.sent[1]).toEqual({
      type: 'page_unsubscribe',
      page: 'main',
    })
  })

  it('ingests a payload for the current page into the page scope', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.subscribe('main')

    const applied = pages.ingestPageResponse('main', {
      entities: { user: { id: 7, name: 'Ada' } },
      data: { title: 'Lobby' },
    })

    expect(applied).toBe(true)
    expect(scopes.page()?.data.signal('title').get()).toBe('Lobby')
    const ref = scopes.page()?.data.signal('user').get() as EntityRef
    expect(scopes.entitySignal(ref).get()?.fields['name']).toBe('Ada')
  })

  it('drops a late payload for a page the client has left', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.subscribe('main')
    pages.subscribe('profile')

    const applied = pages.ingestPageResponse('main', {
      data: { title: 'Lobby' },
    })

    expect(applied).toBe(false)
    expect(scopes.page()?.data.signal('title').get()).toBeUndefined()
  })
})
