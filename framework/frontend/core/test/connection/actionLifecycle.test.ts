import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
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

  success(
    action: string,
    requestId: string | undefined,
    message?: string,
    reply?: unknown,
  ): void {
    for (const listener of this.listeners.actionSuccess) {
      listener({
        kind: 'actionSuccess',
        action,
        message,
        reply,
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
    errorType?: string,
    errorDetail?: string,
  ): void {
    for (const listener of this.listeners.actionError) {
      listener({
        kind: 'actionError',
        action,
        reason,
        errorCode,
        errorType,
        errorDetail,
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
    await expect(handle.done).resolves.toEqual({
      message: undefined,
      reply: undefined,
    })
    expect(handle.loading.get()).toBe(false)
  })

  it('resolves with the backend success message when the reply carries one', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch('moderator_piece_update', { id: 1 })
    source.success(
      'moderator_piece_update',
      handle.requestId,
      'Piece approved.',
    )
    await expect(handle.done).resolves.toMatchObject({
      message: 'Piece approved.',
    })
  })

  it('resolves with the raw reply when no schema is given', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch('a', {})
    source.success('a', handle.requestId, undefined, { token: 'abc' })
    await expect(handle.done).resolves.toEqual({
      message: undefined,
      reply: { token: 'abc' },
    })
  })

  it('resolves with the parsed reply when a schema is given', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch(
      'a',
      {},
      {
        replySchema: z.object({ token: z.string() }),
      },
    )
    source.success('a', handle.requestId, undefined, { token: 'abc' })
    await expect(handle.done).resolves.toEqual({
      message: undefined,
      reply: { token: 'abc' },
    })
  })

  it('rejects with invalid-reply when the reply fails the schema', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch(
      'a',
      {},
      {
        replySchema: z.object({ token: z.string() }),
      },
    )
    source.success('a', handle.requestId, undefined, { token: 42 })
    await expect(handle.done).rejects.toMatchObject({
      outcome: 'invalid-reply',
    })
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
      errorType: undefined,
      errorDetail: undefined,
    })
  })

  it('carries the admin-only type and detail onto the failure', async () => {
    const source = new FakeSource()
    const lifecycle = new ActionLifecycle(source)

    const handle = lifecycle.dispatch('a', {})
    source.fail(
      'a',
      handle.requestId,
      'The action could not be completed.',
      undefined,
      'DatabaseException',
      'SQLSTATE[42S02]: Base table or view not found',
    )

    // The generic sentence is what is shown; the two fields beside it are what an
    // admin surface opens to read (HIL-779), and they arrive only when the backend
    // held something back.
    await expect(handle.done).rejects.toMatchObject({
      outcome: 'fail',
      message: 'The action could not be completed.',
      errorType: 'DatabaseException',
      errorDetail: 'SQLSTATE[42S02]: Base table or view not found',
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
    await expect(handle.done).resolves.toEqual({
      message: undefined,
      reply: undefined,
    })
  })
})
