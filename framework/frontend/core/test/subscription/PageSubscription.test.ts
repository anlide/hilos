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
    pages.releaseOnSession()

    pages.subscribe('main')

    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
    ])
    expect(pages.pageKey()).toBe('main')
    expect(scopes.page()?.key).toBe('main')
  })

  it('subscribing while disconnected sends when the new socket answers', () => {
    const connection = fakeConnection('disconnected')
    const pages = new PageSubscription(connection, new ScopeManager())

    pages.subscribe('main', { tab: 'open' })
    expect(connection.sent).toEqual([])

    connection.setState('connecting')
    connection.setState('connected')
    expect(connection.sent).toEqual([])

    pages.releaseOnSession()
    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: { tab: 'open' } },
    ])
  })

  it('re-sends the current subscription when the reconnected socket answers', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('main')

    connection.setState('reconnecting')
    connection.setState('connected')

    // The socket is back, but the backend does not know it yet: a subscribe sent
    // here would be judged against a connection nobody has heard of.
    expect(connection.sent).toHaveLength(1)

    pages.releaseOnSession()

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
    pages.releaseOnSession()

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
    pages.releaseOnSession()
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
    pages.releaseOnSession()
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
    pages.releaseOnSession()
    pages.subscribe('main')
    pages.subscribe('profile')

    const applied = pages.ingestPageResponse('main', {
      data: { title: 'Lobby' },
    })

    expect(applied).toBe(false)
    expect(scopes.page()?.data.signal('title').get()).toBeUndefined()
  })

  it('records a subscription error for the current page', () => {
    const pages = new PageSubscription(fakeConnection(), new ScopeManager())
    pages.subscribe('user', { id: '9' })

    const recorded = pages.handleSubscriptionError({
      page: 'user',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'Resource #9 not found',
    })

    expect(recorded).toBe(true)
    expect(pages.pageError.get()?.httpCode).toBe(404)
    expect(pages.pageError.get()?.errorCode).toBe('not_found')
  })

  it('drops a subscription error for a page the client has left', () => {
    const pages = new PageSubscription(fakeConnection(), new ScopeManager())
    pages.subscribe('user', { id: '9' })
    pages.subscribe('main')

    const recorded = pages.handleSubscriptionError({
      page: 'user',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'Resource #9 not found',
    })

    expect(recorded).toBe(false)
    expect(pages.pageError.get()).toBeNull()
  })

  it('clears the page error when subscribing another page', () => {
    const pages = new PageSubscription(fakeConnection(), new ScopeManager())
    pages.subscribe('user', { id: '9' })
    pages.handleSubscriptionError({
      page: 'user',
      httpCode: 403,
      errorCode: 'forbidden',
      message: 'Access forbidden',
    })

    pages.subscribe('main')

    expect(pages.pageError.get()).toBeNull()
  })

  it('clears the page error on unsubscribe', () => {
    const pages = new PageSubscription(fakeConnection(), new ScopeManager())
    pages.subscribe('user', { id: '9' })
    pages.handleSubscriptionError({
      page: 'user',
      httpCode: 403,
      errorCode: 'forbidden',
      message: 'Access forbidden',
    })

    pages.unsubscribe()

    expect(pages.pageError.get()).toBeNull()
  })

  it('clears the page error in place without leaving the page', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('user', { id: '9' })
    pages.handleSubscriptionError({
      page: 'user',
      httpCode: 401,
      errorCode: 'unauthorized',
      message: 'Authentication required',
    })
    const sentBefore = connection.sent.length

    pages.clearPageError()

    expect(pages.pageError.get()).toBeNull()
    // The page stays subscribed: no unsubscribe/subscribe frame goes out.
    expect(pages.pageKey()).toBe('user')
    expect(connection.sent.length).toBe(sentBefore)
  })
})

describe('holding the subscribe until the session answers', () => {
  it('sends nothing until released, then sends the held page', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const held = new PageSubscription(connection, scopes)

    held.subscribe('hilos_settings')
    expect(connection.sent).toEqual([])

    held.releaseOnSession()

    expect(connection.sent).toMatchObject([
      { type: 'page_subscribe', page: 'hilos_settings' },
    ])
  })
})

describe('pageLoading', () => {
  it('is raised by a subscribe and lowered by an empty answer', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()

    pages.subscribe('hilos_about')
    expect(pages.pageLoading.get()).toBe(true)

    const applied = pages.ingestPageResponse('hilos_about', {})

    expect(applied).toBe(true)
    expect(pages.pageLoading.get()).toBe(false)
  })
})
