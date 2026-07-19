import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../src/connection/actionLifecycle.js'
import { type ConnectionState } from '../../src/connection/HilosConnection.js'
import {
  type ActionErrorSignal,
  type ActionSuccessSignal,
} from '../../src/protocol/parseSignal.js'

interface FakeEvents {
  actionSuccess: ActionSuccessSignal
  actionError: ActionErrorSignal
  state: ConnectionState
}

/** A minimal lifecycle source: emit helpers drive it like a connection would. */
class FakeSource implements ActionLifecycleSource {
  connected = true
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly listeners: {
    [E in keyof FakeEvents]: ((payload: FakeEvents[E]) => void)[]
  } = { actionSuccess: [], actionError: [], state: [] }

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })

    return this.connected
  }

  on<E extends keyof FakeEvents>(
    event: E,
    listener: (payload: FakeEvents[E]) => void,
  ): () => void {
    this.listeners[event].push(listener)

    return () => {}
  }

  success(action: string, requestId: string | undefined): void {
    for (const listener of this.listeners.actionSuccess) {
      listener({
        kind: 'actionSuccess',
        action,
        requestId,
        envelope: { type: 'action_success', data: {} },
      })
    }
  }

  fail(
    action: string,
    requestId: string | undefined,
    reason: string,
    errorCode?: string,
  ): void {
    for (const listener of this.listeners.actionError) {
      listener({
        kind: 'actionError',
        action,
        reason,
        errorCode,
        requestId,
        envelope: { type: 'action_error', data: {} },
      })
    }
  }

  state(next: ConnectionState): void {
    for (const listener of this.listeners.state) {
      listener(next)
    }
  }
}

describe('ActionLifecycle', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('dispatches with a minted requestId and resolves on the success reply', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch('moderator_piece_update', { id: 1 })
    expect(source.sent).toHaveLength(1)
    expect(source.sent[0]?.requestId).toBe(handle.requestId)

    source.success('moderator_piece_update', handle.requestId)
    await expect(handle.done).resolves.toBeUndefined()
    expect(handle.loading.get()).toBe(false)
  })

  it('shows loading only after the deferral while pending', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source, { deferredLoadingMs: 300 })

    const handle = lifecycle.dispatch('a', {})
    expect(handle.loading.get()).toBe(false)
    vi.advanceTimersByTime(300)
    expect(handle.loading.get()).toBe(true)

    source.success('a', handle.requestId)
    await handle.done
    expect(handle.loading.get()).toBe(false)
  })

  it('rejects with the backend reason on a fail reply', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch('a', {})
    source.fail('a', handle.requestId, 'Name already taken')

    await expect(handle.done).rejects.toBeInstanceOf(ActionError)
    await expect(handle.done).rejects.toMatchObject({
      outcome: 'fail',
      message: 'Name already taken',
    })
  })

  it('times out a pending action and reconciles a late reply', async () => {
    const onLateReply = vi.fn()
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source, {
      timeoutMs: 30000,
      onLateReply,
    })

    const handle = lifecycle.dispatch('a', {})
    vi.advanceTimersByTime(30000)
    await expect(handle.done).rejects.toMatchObject({ outcome: 'timeout' })

    source.success('a', handle.requestId)
    expect(onLateReply).toHaveBeenCalledWith('a', 'success')
  })

  it('fails the in-flight action when the transport drops', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch('a', {})
    source.state('reconnecting')
    await expect(handle.done).rejects.toMatchObject({ outcome: 'disconnected' })
  })

  it('rejects immediately when the connection is not connected', async () => {
    const source = new FakeSource()
    source.connected = false
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch('a', {})
    await expect(handle.done).rejects.toMatchObject({ outcome: 'disconnected' })
    expect(handle.loading.get()).toBe(false)
  })

  it('ignores a reply that carries no requestId', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch('a', {})
    source.success('a', undefined)
    source.success('a', handle.requestId)
    await expect(handle.done).resolves.toBeUndefined()
  })
})
