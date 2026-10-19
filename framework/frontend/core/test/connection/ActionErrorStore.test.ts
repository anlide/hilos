import { describe, expect, it } from 'vitest'
import { ActionErrorStore } from '../../src/connection/ActionErrorStore.js'

/** What the store hears from its source on one action failure. */
interface ActionErrorEvent {
  action: string
  reason: string
  errorCode?: string | undefined
}

/** A minimal actionError source: emit() drives the store like a connection would. */
function createSource() {
  const listeners: ((signal: ActionErrorEvent) => void)[] = []

  return {
    on(
      _event: 'actionError',
      listener: (signal: ActionErrorEvent) => void,
    ): () => void {
      listeners.push(listener)

      return () => {}
    },
    emit(action: string, reason: string, errorCode?: string): void {
      for (const listener of listeners) {
        listener({ action, reason, errorCode })
      }
    },
  }
}

describe('ActionErrorStore', () => {
  it('is null for an action that has never failed', () => {
    const store = new ActionErrorStore(createSource())
    expect(store.signal('message').get()).toBeNull()
  })

  it('records the latest reason per action', () => {
    const source = createSource()
    const store = new ActionErrorStore(source)

    source.emit('message', 'Message rate limit is active')
    source.emit('rename', 'Name already taken')
    expect(store.signal('message').get()).toBe('Message rate limit is active')
    expect(store.signal('rename').get()).toBe('Name already taken')

    source.emit('message', 'Another message is already being moderated')
    expect(store.signal('message').get()).toBe(
      'Another message is already being moderated',
    )
  })

  it.each([
    'view_mode',
    'impersonation_view_only',
    'impersonated',
    'rate_limited',
  ])('keeps the wire reason verbatim for %s', (errorCode) => {
    const source = createSource()
    const store = new ActionErrorStore(source)
    const reason = ` Server refusal for ${errorCode}.\nKeep this sentence. `

    source.emit('rename', reason, errorCode)
    source.emit('message', 'Not allowed.', 'forbidden')
    expect(store.signal('rename').get()).toBe(reason)
    expect(store.signal('message').get()).toBe('Not allowed.')
  })

  it('clears one action without touching another', () => {
    const source = createSource()
    const store = new ActionErrorStore(source)
    source.emit('message', 'boom')
    source.emit('rename', 'taken')

    store.clear('message')
    expect(store.signal('message').get()).toBeNull()
    expect(store.signal('rename').get()).toBe('taken')
  })
})
