import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import {
  createHilosAccountMerge,
  createHilosAccountMergeCancel,
  createHilosAccountMergeWindow,
  HILOS_ACCOUNT_MERGE_CANCEL_COPY,
  readHilosAccountMerge,
  type HilosAccountMergeProgress,
  type HilosUsersContext,
} from '../../../src/admin/users/index.js'
import {
  ActionLifecycle,
  type ActionHandle,
  type ActionLifecycleSource,
} from '../../../src/connection/actionLifecycle.js'
import type { ConnectionState } from '../../../src/connection/HilosConnection.js'
import type {
  ActionErrorSignal,
  ActionSuccessSignal,
} from '../../../src/protocol/parseSignal.js'
import {
  bindAdminAccess,
  type HilosAdminAccess,
} from '../../../src/session/adminAccess.js'
import { USER_ENTITY_TYPE } from '../../../src/state/entity.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal, subscribeSignal } from '../../../src/state/signal.js'
import { hilosToasts } from '../../../src/state/toasts.js'

interface Events {
  actionSuccess: ActionSuccessSignal
  actionError: ActionErrorSignal
  state: ConnectionState
}

/** Drive the real tracked lifecycle with replies and a fake clock. */
class ActionSource implements ActionLifecycleSource {
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly listeners: {
    [E in keyof Events]: ((payload: Events[E]) => void)[]
  } = { actionSuccess: [], actionError: [], state: [] }

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })
    return true
  }

  on<E extends keyof Events>(
    event: E,
    listener: (payload: Events[E]) => void,
  ): () => void {
    this.listeners[event].push(listener)
    return () => {
      const index = this.listeners[event].indexOf(listener)
      if (index !== -1) this.listeners[event].splice(index, 1)
    }
  }

  success(handle: ActionHandle): void {
    for (const listener of this.listeners.actionSuccess) {
      listener({
        kind: 'actionSuccess',
        action: 'hilos_user_merge_cancel',
        requestId: handle.requestId,
        message: undefined,
        reply: undefined,
        envelope: { type: 'action_success', data: {} },
      })
    }
  }

  fail(handle: ActionHandle): void {
    for (const listener of this.listeners.actionError) {
      listener({
        kind: 'actionError',
        action: 'hilos_user_merge_cancel',
        requestId: handle.requestId,
        reason: 'The merge can no longer be canceled',
        errorCode: undefined,
        errorType: undefined,
        errorDetail: undefined,
        envelope: { type: 'action_error', data: {} },
      })
    }
  }

  disconnect(): void {
    for (const listener of this.listeners.state) listener('disconnected')
  }
}

function progress(
  overrides: Partial<HilosAccountMergeProgress> = {},
): HilosAccountMergeProgress {
  return {
    operationId: 41,
    survivorUserId: 7,
    loserUserId: 8,
    state: 'preparing',
    stage: 'preparing',
    outcome: null,
    given: false,
    received: false,
    choice: null,
    refusal: null,
    parts: [],
    waits: [],
    ...overrides,
  }
}

const releases: (() => void)[] = []
beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  for (const release of releases.splice(0).reverse()) release()
  hilosToasts.clear()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

/** Use the real safe reader, observation window, access binding and action lifecycle. */
function setup(
  raw: unknown = progress(),
  options: { accountMerge?: boolean } = { accountMerge: true },
) {
  const scopes = new ScopeManager()
  const user = { type: USER_ENTITY_TYPE, id: 1 }
  scopes.session.data.set('currentUser', user)
  function access(next: HilosAdminAccess): void {
    scopes.session.data.set('adminViewMode', next !== 'none')
    scopes.session.entities.upsert(user, { admin: next === 'full' })
  }
  access('full')
  const offAccess = bindAdminAccess(scopes)
  const source = new ActionSource()
  const actions = new ActionLifecycle(source)
  const context = { ...options, actions } as HilosUsersContext
  const current = createSignal(readHilosAccountMerge(raw))
  const shownUserId = createSignal<number | undefined>(7)
  const window = createHilosAccountMergeWindow(current, shownUserId)
  const cancel = createHilosAccountMergeCancel(
    context,
    window,
    createHilosAccountMerge(context),
  )
  releases.push(() => {
    cancel.dispose()
    window.dispose()
    source.disconnect()
    offAccess()
  })
  window.open()
  return {
    cancel,
    window,
    source,
    actions,
    access,
    shownUserId,
    update(raw: unknown) {
      current.set(readHilosAccountMerge(raw))
    },
  }
}

function requested(world: ReturnType<typeof setup>): ActionHandle {
  const handle = world.cancel.cancel()
  expect(handle).not.toBeNull()
  if (handle === null) throw new Error('Expected a cancellation request')
  return handle
}

describe('createHilosAccountMergeCancel', () => {
  it('exports its copy only through the lazy users API', () => {
    expect(HILOS_ACCOUNT_MERGE_CANCEL_COPY).toEqual({
      cancel: 'Cancel merge',
      canceled: 'Merge canceled',
    })
  })

  it.each<HilosAccountMergeProgress['state']>([
    'preparing',
    'wait',
    'refusal',
    'end',
  ])('offers cancellation only in an active state: %s', (state) => {
    const { cancel } = setup(progress({ state }))
    expect(cancel.available.get()).toBe(
      state === 'preparing' || state === 'wait',
    )
  })

  it.each<HilosAccountMergeProgress['stage']>([
    'preparing',
    'go',
    'cancelling',
    null,
  ])('offers cancellation only before the release stage: %s', (stage) => {
    const { cancel } = setup(progress({ stage }))
    expect(cancel.available.get()).toBe(stage === 'preparing' || stage === 'go')
  })

  it.each<Partial<HilosAccountMergeProgress>>([
    { operationId: null },
    { given: true },
    { outcome: 'completed' },
    { outcome: 'not_started' },
  ])('does not send when the live facts exclude cancellation: %j', (fields) => {
    const { cancel, source } = setup(progress(fields))
    expect(cancel.available.get()).toBe(false)
    expect(cancel.cancel()).toBeNull()
    expect(source.sent).toEqual([])
  })

  it.each([false, undefined])(
    'requires an enabled project flag: %s',
    (flag) => {
      const { cancel, source } = setup(progress(), { accountMerge: flag })
      expect(cancel.available.get()).toBe(false)
      expect(cancel.cancel()).toBeNull()
      expect(source.sent).toEqual([])
    },
  )

  it.each<HilosAdminAccess>(['view', 'none'])(
    'requires full access instead of %s',
    (next) => {
      const { cancel, source, access } = setup()
      access(next)
      expect(cancel.available.get()).toBe(false)
      expect(cancel.cancel()).toBeNull()
      expect(source.sent).toEqual([])
    },
  )

  it('waits for the live outcome after acceptance without another dispatch', async () => {
    const world = setup()
    const handle = requested(world)
    expect(world.cancel.pending.get()).toBe(true)
    expect(handle.loading.get()).toBe(false)
    expect(world.cancel.available.get()).toBe(false)
    expect(world.cancel.cancel()).toBeNull()
    world.source.success(handle)
    await handle.done
    expect(world.cancel.pending.get()).toBe(false)
    expect(world.cancel.available.get()).toBe(false)
    expect(world.window.opened.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    world.update(progress({ state: 'wait', stage: 'go' }))
    expect(world.cancel.available.get()).toBe(false)
    world.update(progress({ state: 'wait', stage: 'cancelling' }))
    expect(world.cancel.available.get()).toBe(false)
    expect(world.window.opened.get()).toBe(true)
    world.update(
      progress({ state: 'end', stage: 'cancelling', outcome: 'not_started' }),
    )
    expect(world.window.opened.get()).toBe(false)
    expect(hilosToasts.toasts.get()).toMatchObject([
      { message: 'Merge canceled', severity: 'success', repeats: 1 },
    ])
    world.update(progress({ state: 'end', outcome: 'not_started' }))
    expect(hilosToasts.toasts.get()).toHaveLength(1)
    expect(world.source.sent).toEqual([
      {
        action: 'hilos_user_merge_cancel',
        data: { operationId: 41 },
        requestId: handle.requestId,
      },
    ])
  })

  it('does not flash availability while acceptance is being applied', async () => {
    const world = setup()
    const seen: boolean[] = []
    releases.push(
      subscribeSignal(world.cancel.available, (value) => seen.push(value)),
    )
    const handle = requested(world)
    world.source.success(handle)
    await handle.done
    expect(seen).toEqual([false])
  })

  it('accepts a live cancellation outcome before its ack and ignores the late reply', async () => {
    const world = setup()
    const handle = requested(world)
    world.update(progress({ state: 'end', outcome: 'not_started' }))
    expect(world.window.opened.get()).toBe(false)
    world.window.open()
    world.update(progress())
    world.source.success(handle)
    await handle.done
    expect(world.cancel.available.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toMatchObject([
      { message: 'Merge canceled', repeats: 1 },
    ])
  })

  it('keeps a not-started business refusal in the observation window', async () => {
    const world = setup()
    const handle = requested(world)
    world.source.success(handle)
    await handle.done
    world.update(
      progress({
        state: 'end',
        outcome: 'not_started',
        refusal: { code: 'occupied', reason: 'The other account is busy.' },
      }),
    )
    expect(world.window.opened.get()).toBe(true)
    expect(world.window.view.get()).toMatchObject({
      kind: 'progress',
      reason: 'The other account is busy.',
    })
    world.update(progress({ state: 'end', outcome: 'not_started' }))
    expect(world.window.opened.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('clears the intent on completion without a cancellation toast', async () => {
    const world = setup()
    const handle = requested(world)
    world.update(progress({ state: 'end', outcome: 'completed' }))
    world.source.success(handle)
    await handle.done
    world.update(progress({ state: 'end', outcome: 'not_started' }))
    expect(world.window.opened.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('preserves the server failure on the returned handle and refuses retries for this id', async () => {
    const world = setup()
    const handle = requested(world)
    world.source.fail(handle)
    await expect(handle.done).rejects.toMatchObject({
      outcome: 'fail',
      message: 'The merge can no longer be canceled',
    })
    expect(world.cancel.pending.get()).toBe(false)
    expect(world.cancel.available.get()).toBe(false)
    expect(world.cancel.cancel()).toBeNull()
    world.update(progress({ state: 'end', outcome: 'not_started' }))
    expect(world.window.opened.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    world.window.close()
    world.update(progress())
    world.window.open()
    expect(world.cancel.available.get()).toBe(true)
    expect(world.source.sent).toHaveLength(1)
  })

  it.each(['timeout', 'disconnected', 'invalid-reply'] as const)(
    'allows retry after %s and still follows a late live outcome',
    async (outcome) => {
      const world = setup()
      if (outcome === 'invalid-reply') {
        const dispatch = world.actions.dispatch.bind(world.actions)
        vi.spyOn(world.actions, 'dispatch').mockImplementationOnce(
          (action, data) => dispatch(action, data, { replySchema: z.never() }),
        )
      }
      const handle = requested(world)
      const rejected = expect(handle.done).rejects.toMatchObject({ outcome })
      if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(30000)
      else if (outcome === 'disconnected') world.source.disconnect()
      else world.source.success(handle)
      await rejected
      expect(world.cancel.pending.get()).toBe(false)
      expect(world.cancel.available.get()).toBe(true)
      expect(world.window.opened.get()).toBe(true)
      expect(hilosToasts.toasts.get()).toEqual([])
      world.update(progress({ state: 'wait', stage: 'cancelling' }))
      world.update(progress({ state: 'end', outcome: 'not_started' }))
      expect(world.window.opened.get()).toBe(false)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: 'Merge canceled' },
      ])
      expect(world.source.sent).toHaveLength(1)
    },
  )

  it('can dispatch another request after an uncertain reply', async () => {
    const world = setup()
    const first = requested(world)
    world.source.disconnect()
    await expect(first.done).rejects.toMatchObject({ outcome: 'disconnected' })
    const second = requested(world)
    world.source.success(second)
    await second.done
    world.update(progress({ state: 'end', outcome: 'not_started' }))
    expect(world.source.sent).toHaveLength(2)
    expect(hilosToasts.toasts.get()).toMatchObject([
      { message: 'Merge canceled', repeats: 1 },
    ])
  })

  it.each([
    'close',
    'changed',
    'unavailable',
    'hidden id',
    'view',
    'none',
    'navigation',
    'dispose',
  ])(
    'forgets intent on %s and ignores late replies and outcomes',
    async (cause) => {
      const world = setup()
      const handle = requested(world)
      if (cause === 'close') world.window.close()
      else if (cause === 'changed') world.update(progress({ operationId: 42 }))
      else if (cause === 'unavailable') world.update(null)
      else if (cause === 'hidden id')
        world.update(progress({ operationId: null }))
      else if (cause === 'view' || cause === 'none') world.access(cause)
      else if (cause === 'navigation') world.shownUserId.set(undefined)
      else world.cancel.dispose()
      expect(world.cancel.pending.get()).toBe(false)
      expect(world.cancel.available.get()).toBe(false)
      expect(world.cancel.cancel()).toBeNull()
      world.window.close()
      world.access('full')
      world.shownUserId.set(7)
      world.update(progress())
      world.window.open()
      world.source.success(handle)
      await handle.done
      expect(world.cancel.available.get()).toBe(cause !== 'dispose')
      world.update(progress({ state: 'end', outcome: 'not_started' }))
      expect(world.window.opened.get()).toBe(true)
      expect(hilosToasts.toasts.get()).toEqual([])
      expect(world.source.sent).toHaveLength(1)
    },
  )

  it('does not let a late ack clear a newer request after reopening', async () => {
    const world = setup()
    const first = requested(world)
    world.window.close()
    world.window.open()
    const second = requested(world)
    world.source.success(first)
    await first.done
    expect(world.cancel.pending.get()).toBe(true)
    world.source.success(second)
    await second.done
    expect(world.cancel.pending.get()).toBe(false)
    world.update(progress({ state: 'end', outcome: 'not_started' }))
    expect(world.window.opened.get()).toBe(false)
    expect(hilosToasts.toasts.get()).toHaveLength(1)
  })

  it.each(['accepted', 'refused'])(
    'clears the %s marker when the source becomes unavailable',
    async (reply) => {
      const world = setup()
      const handle = requested(world)
      if (reply === 'accepted') {
        world.source.success(handle)
        await handle.done
      } else {
        world.source.fail(handle)
        await expect(handle.done).rejects.toMatchObject({ outcome: 'fail' })
      }
      expect(world.cancel.available.get()).toBe(false)
      world.update(null)
      world.update(progress())
      expect(world.cancel.available.get()).toBe(true)
      world.update(progress({ state: 'end', outcome: 'not_started' }))
      expect(world.window.opened.get()).toBe(true)
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('keeps request intent and acceptance local to each controller', async () => {
    const first = setup()
    const second = setup()
    const handle = requested(first)
    first.source.success(handle)
    await handle.done
    expect(first.cancel.available.get()).toBe(false)
    expect(second.cancel.available.get()).toBe(true)
    second.update(progress({ state: 'end', outcome: 'not_started' }))
    expect(second.window.opened.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    first.update(progress({ state: 'end', outcome: 'not_started' }))
    expect(first.window.opened.get()).toBe(false)
    expect(second.source.sent).toEqual([])
    expect(hilosToasts.toasts.get()).toHaveLength(1)
  })
})
