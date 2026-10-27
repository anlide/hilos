// These are client lifecycle tests with real parsing/session bindings. Reloads
// replace the binding and flow over the same tab storage; browser e2e lives
// separately and supplies the actual reload/DOM evidence.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announceBrowserValueErased } from '../../src/browser/browserValueChanges.js'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { type WebSocketLike } from '../../src/connection/HilosConnection.js'
import {
  bindProfileNameState,
  hilosProfileNameAccess,
  hilosProfileNameIdentity,
  type HilosProfileNameChange,
} from '../../src/profile/profileNameState.js'
import {
  createHilosProfileRename,
  createHilosProfileRenameFlow,
  HILOS_PROFILE_RENAME_COPY,
  PROFILE_NAME_SET_ACTION,
  type HilosProfileRenameFlow,
} from '../../src/profile/profileRename.js'
import {
  PROFILE_RENAME_MEMORY_STORAGE_KEY,
  readProfileRenameMemory,
  writeProfileRenameMemory,
} from '../../src/profile/profileRenameMemory.js'
import {
  bindSessionScope,
  sessionUserName,
} from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { createSignal } from '../../src/state/signal.js'

const cleanup: Array<() => void> = []
const SKIP = { required: false, purpose: 'change your name' }
const ASK = { required: true, purpose: 'change your name', method: 'password' }

function storage(): Storage {
  const values = new Map<string, string>()
  return {
    get length() {
      return values.size
    },
    clear: () => values.clear(),
    getItem: (key) => values.get(key) ?? null,
    key: (index) => [...values.keys()][index] ?? null,
    removeItem: (key) => {
      values.delete(key)
    },
    setItem: (key, value) => {
      values.set(key, value)
    },
  }
}

function tab(known = true) {
  const listeners = new Map<
    string,
    Array<(event: { data?: unknown }) => void>
  >()
  const frames: Array<{ type: string; action?: string; data: unknown }> = []
  const socket: WebSocketLike = {
    send: (frame) => frames.push(JSON.parse(String(frame))),
    close() {},
    addEventListener(type, listener) {
      const handlers = listeners.get(type) ?? []
      handlers.push(listener)
      listeners.set(type, handlers)
    },
  }
  const bundle = createHilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
  })
  const scopes = new ScopeManager()
  bindSessionScope(bundle.connection, scopes)
  const release = bindProfileNameState(bundle.connection, scopes)
  cleanup.push(() => {
    release()
    bundle.connection.close()
    bundle.actionErrors.dispose()
  })
  const tell = (type: string, data: unknown) => {
    for (const listener of listeners.get('message') ?? []) {
      listener({ data: JSON.stringify({ type, data }) })
    }
  }
  const identity = (
    id: number | null,
    name = 'Ann',
    data: Record<string, unknown> = {},
  ) => {
    tell('handshake_response', {
      data: {
        accountStanding: null,
        accountBlocked: null,
        pendingAck: null,
        pendingAuthStep: null,
        ...data,
      },
      entities: {
        currentUser: id === null ? null : { id, name },
        impersonatedBy: null,
      },
    })
  }
  const state = (
    phase: HilosProfileNameChange['phase'] | null,
    name = 'Bob',
    requestId = 'r1',
    userId: number | null = 1,
    refusal = ' Server refused this name.\nTry another. ',
  ) => {
    tell('hilos_profile_name_state', {
      userId,
      change:
        phase === null
          ? null
          : {
              requestId,
              name,
              phase,
              refusal: phase === 'refused' ? refusal : null,
            },
    })
  }
  bundle.connection.connect()
  for (const listener of listeners.get('open') ?? []) listener({})
  tell('handshake', { build: 'test' })
  if (known) {
    identity(1)
    state(null)
  }
  const dispatch = vi.fn(() => ({ done: Promise.resolve({ reply: SKIP }) }))
  const rename = createHilosProfileRename(
    bundle.connection,
    bundle.actionErrors,
  )
  const flow = (remember = true, access = createSignal(true)) => {
    const value = createHilosProfileRenameFlow(
      { actions: { dispatch } as unknown as ActionLifecycle },
      sessionUserName(scopes),
      rename,
      { remember, access },
    )
    cleanup.push(value.dispose)
    return value
  }
  return {
    ...bundle,
    scopes,
    rename,
    flow,
    dispatch,
    tell,
    identity,
    state,
    frames,
    names: () =>
      frames.filter((frame) => frame.action === PROFILE_NAME_SET_ACTION),
  }
}

async function flush(): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
  await Promise.resolve()
}

async function send(flow: HilosProfileRenameFlow): Promise<void> {
  await flow.open()
  flow.draft.set('  Bob  ')
  flow.save()
}

beforeEach(() => vi.stubGlobal('sessionStorage', storage()))
afterEach(() => {
  for (const stop of cleanup.splice(0).reverse()) stop()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('standard rename lifecycle', () => {
  it('has one authoritative binding and keeps checking distinct from full pending', async () => {
    const t = tab()
    const f = t.flow()
    expect(t.rename.state?.identity).toBe(hilosProfileNameIdentity)
    expect(t.rename.state?.access).toBe(hilosProfileNameAccess)
    expect(HILOS_PROFILE_RENAME_COPY.checking).toBe('Checking your name…')
    await send(f)
    expect(t.names()).toEqual([
      {
        type: 'action',
        action: PROFILE_NAME_SET_ACTION,
        data: { name: 'Bob' },
      },
    ])
    expect(f.busy).toBe(f.pending)
    expect(f.pending.get()).toBe(true)
    expect(f.checking.get()).toBe(false)
    expect(f.canSave.get()).toBe(false)
    f.save()
    t.state('checking')
    expect(f.checking.get()).toBe(true)
    t.state('applying')
    expect(f.checking.get()).toBe(false)
    expect(f.pending.get()).toBe(true)
    expect(t.names()).toHaveLength(1)
  })

  it('does not let a pre-ack live echo or external update release the send', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    f.draft.set('Ann')
    t.identity(1, 'Bob')
    expect(f.pending.get()).toBe(true)
    expect(f.draft.get()).toBe('Ann')
    f.save()
    expect(t.names()).toHaveLength(1)
    t.state('done')
    expect(f.pending.get()).toBe(false)
    expect(f.step.get()).toBe('form')
    expect(f.draft.get()).toBe('Ann')
    expect(f.notice.get()).toBe('')
  })

  it('stays pending through a pre-ack external conflict and releases on fresh authoritative idle', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    t.identity(1, 'Carl')
    expect(f.pending.get()).toBe(true)
    expect(f.edit.get().conflict).toBe(true)
    t.state(null)
    expect(f.pending.get()).toBe(false)
    expect(f.edit.get().conflict).toBe(true)
  })

  it.each(['echo-first', 'done-first'] as const)(
    'closes unchanged input only on its own done (%s)',
    async (order) => {
      const t = tab()
      const f = t.flow()
      await send(f)
      t.state('checking')
      if (order === 'echo-first') {
        t.identity(1, 'Bob')
        t.tell('checking', { checking: false })
        expect(f.step.get()).toBe('form')
        expect(f.pending.get()).toBe(true)
      }
      t.state('done')
      expect(f.step.get()).toBe('closed')
      expect(f.pending.get()).toBe(false)
      expect(readProfileRenameMemory()?.open).toBe(false)
      if (order === 'done-first') t.identity(1, 'Bob')
      expect(f.step.get()).toBe('closed')
    },
  )

  it.each(['Carol', 'Ann', 'Carl'])(
    'keeps newer draft %s through own echo without external marks',
    async (newer) => {
      const t = tab()
      const f = t.flow()
      await send(f)
      t.state('checking')
      if (newer === 'Carl') t.identity(1, 'Carl')
      f.draft.set(newer)
      t.identity(1, 'Bob')
      expect(f.draft.get()).toBe(newer)
      t.state('done')
      expect(f.step.get()).toBe('form')
      expect(f.draft.get()).toBe(newer)
      expect(f.edit.get().conflict).toBe(false)
      expect(f.notice.get()).toBe('')
      expect(f.canSave.get()).toBe(true)
    },
  )

  it('does not call another attempt or another owner its own success', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    t.state('checking')
    f.draft.set('Carol')
    t.state('done', 'Other', 'r2')
    expect(f.step.get()).toBe('form')
    expect(f.draft.get()).toBe('Carol')
    t.state('done', 'Bob', 'r1', 2)
    expect(f.step.get()).toBe('form')
    t.identity(2, 'Another person')
    expect(f.step.get()).toBe('closed')
    expect(readProfileRenameMemory()).toBeNull()
  })

  it('keeps a real external change after an own echo as external through done', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    t.state('checking')
    f.draft.set('Carol')
    t.identity(1, 'Bob')
    t.identity(1, 'Carl')
    t.state('done')
    expect(f.draft.get()).toBe('Carol')
    expect(f.edit.get().conflict).toBe(true)
    expect(f.notice.get()).toBe('Changed elsewhere to "Carl".')
    f.takeTheirs()
    expect(f.draft.get()).toBe('Carl')
  })

  it('shows an attempt accepted elsewhere while step-up was still asking', async () => {
    const t = tab()
    t.dispatch.mockReturnValue({ done: Promise.resolve({ reply: ASK }) })
    const f = t.flow()
    await f.open()
    expect(f.step.get()).toBe('step-up')
    t.state('checking')
    expect(f.step.get()).toBe('form')
    expect(f.draft.get()).toBe('Bob')
    expect(f.pending.get()).toBe(true)
    expect(t.dispatch).toHaveBeenCalledTimes(1)
  })

  it('keeps external conflicts and Keep mine / Take theirs', async () => {
    const t = tab()
    const f = t.flow()
    await f.open()
    t.identity(1, 'Anna')
    expect(f.draft.get()).toBe('Anna')
    expect(f.notice.get()).toBe('Updated just now')
    f.draft.set('Bob')
    t.identity(1, 'Carl')
    expect(f.notice.get()).toBe('Changed elsewhere to "Carl".')
    expect(f.canSave.get()).toBe(false)
    f.save()
    expect(t.names()).toHaveLength(0)
    f.keepMine()
    expect(f.draft.get()).toBe('Bob')
    t.identity(1, 'Dan')
    f.takeTheirs()
    expect(f.draft.get()).toBe('Dan')
    expect(f.edit.get().conflict).toBe(false)
  })

  it.each(['echo-first', 'done-first'] as const)(
    'keeps an unsent form external to a shared attempt (%s)',
    async (order) => {
      const t = tab()
      const f = t.flow()
      await f.open()
      f.draft.set('Carol')
      t.state('checking')
      expect(f.pending.get()).toBe(true)
      expect(f.canSave.get()).toBe(false)
      f.save()
      t.state('applying')
      if (order === 'echo-first') t.identity(1, 'Bob')
      t.state('done')
      if (order === 'done-first') t.identity(1, 'Bob')
      expect(f.step.get()).toBe('form')
      expect(f.draft.get()).toBe('Carol')
      expect(f.pending.get()).toBe(false)
      expect(f.edit.get().conflict).toBe(true)
      expect(f.notice.get()).toBe('Changed elsewhere to "Bob".')
      expect(f.canSave.get()).toBe(false)
      f.save()
      expect(t.names()).toHaveLength(0)
      f.keepMine()
      expect(f.draft.get()).toBe('Carol')
      expect(f.canSave.get()).toBe(true)
      t.identity(1, 'Dan')
      f.takeTheirs()
      expect(f.draft.get()).toBe('Dan')
      expect(f.edit.get().conflict).toBe(false)
    },
  )

  it('does not close an unsent form whose draft matches a shared success', async () => {
    const t = tab()
    const f = t.flow()
    await f.open()
    f.draft.set('Bob')
    t.state('checking')
    t.identity(1, 'Bob')
    t.state('done')
    expect(f.step.get()).toBe('form')
    expect(f.draft.get()).toBe('Bob')
    expect(f.edit.get().conflict).toBe(false)
    expect(f.notice.get()).toBe('')
    expect(f.canSave.get()).toBe(false)
    expect(t.names()).toHaveLength(0)
  })

  it('keeps an unsent draft external when reopened during shared pending', async () => {
    const t = tab()
    const f = t.flow()
    await f.open()
    f.draft.set('Carol')
    t.state('checking')
    f.close()
    await f.open()
    expect(f.draft.get()).toBe('Carol')
    expect(f.pending.get()).toBe(true)
    expect(t.dispatch).toHaveBeenCalledTimes(1)
    t.identity(1, 'Bob')
    t.state('done')
    expect(f.step.get()).toBe('form')
    expect(f.notice.get()).toBe('Changed elsewhere to "Bob".')
    expect(f.canSave.get()).toBe(false)
    expect(t.names()).toHaveLength(0)
  })

  it('continues shared pending on a fresh manual opening without another START', async () => {
    const t = tab()
    const f = t.flow()
    f.start()
    t.state('checking')
    expect(f.step.get()).toBe('closed')
    await f.open()
    expect(f.step.get()).toBe('form')
    expect(f.draft.get()).toBe('Bob')
    expect(f.pending.get()).toBe(true)
    f.save()
    expect(t.dispatch).not.toHaveBeenCalled()
    expect(t.names()).toHaveLength(0)
    t.state('done')
    expect(f.step.get()).toBe('closed')
  })

  it.each(['echo-first', 'done-first'] as const)(
    'keeps a newer own draft through its correlated receipt (%s)',
    async (order) => {
      const t = tab()
      const f = t.flow()
      await send(f)
      t.state('checking')
      f.draft.set('Ann')
      t.state('applying')
      if (order === 'echo-first') t.identity(1, 'Bob')
      t.state('done')
      if (order === 'done-first') t.identity(1, 'Bob')
      expect(f.step.get()).toBe('form')
      expect(f.draft.get()).toBe('Ann')
      expect(f.edit.get().conflict).toBe(false)
      expect(f.notice.get()).toBe('')
      expect(f.canSave.get()).toBe(true)
      expect(t.names()).toHaveLength(1)
    },
  )

  it.each(['refused', 'done'] as const)(
    'holds a closed %s for manual open without opening from the frame',
    async (phase) => {
      const t = tab()
      const f = t.flow()
      await send(f)
      t.state('checking')
      f.close()
      expect(f.pending.get()).toBe(true)
      expect(readProfileRenameMemory()?.open).toBe(false)
      t.state(phase)
      t.identity(1, phase === 'done' ? 'Bob' : 'Ann')
      expect(f.step.get()).toBe('closed')
      expect(readProfileRenameMemory()?.open).toBe(false)
      await f.open()
      expect(f.step.get()).toBe('form')
      expect(f.draft.get()).toBe(phase === 'done' ? 'Bob' : '  Bob  ')
      expect(f.refusal.get()).toBe(
        phase === 'done' ? null : ' Server refused this name.\nTry another. ',
      )
      expect(t.names()).toHaveLength(1)
    },
  )

  it('reopens an accepted attempt without a cancelling step-up START or resend', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    t.state('checking')
    f.draft.set('Carol')
    f.close()
    await f.open()
    expect(f.draft.get()).toBe('Carol')
    expect(f.pending.get()).toBe(true)
    expect(t.dispatch).toHaveBeenCalledTimes(1)
    expect(t.names()).toHaveLength(1)
  })

  it('releases a pre-accept action_error with verbatim words and permits a retry', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    t.tell('action_error', {
      action: PROFILE_NAME_SET_ACTION,
      reason: ' Exact server reason. ',
    })
    expect(f.pending.get()).toBe(false)
    expect(f.refusal.get()).toBe(' Exact server reason. ')
    expect(f.canSave.get()).toBe(true)
    f.save()
    expect(f.pending.get()).toBe(true)
    expect(f.refusal.get()).toBeNull()
    t.state('refused')
    expect(f.pending.get()).toBe(false)
    f.save()
    expect(t.names()).toHaveLength(3)
    expect(f.pending.get()).toBe(true)
  })

  it('keeps a pre-accept refusal and newer draft on manual reopen', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    f.draft.set('Carol')
    t.tell('action_error', {
      action: PROFILE_NAME_SET_ACTION,
      reason: 'Exact admission refusal.',
    })
    f.close()
    await f.open()
    expect(f.draft.get()).toBe('Carol')
    expect(f.refusal.get()).toBe('Exact admission refusal.')
  })

  it('does not show unscoped action errors to a new owner', async () => {
    const t = tab()
    t.tell('action_error', {
      action: PROFILE_NAME_SET_ACTION,
      reason: 'Another person’s error.',
    })
    t.identity(2, 'New person')
    t.state(null, 'Unused', 'unused', 2)
    const f = t.flow()
    await f.open()
    expect(f.refusal.get()).toBeNull()
    expect(f.draft.get()).toBe('New person')
  })

  it('opens the confirmed name when done precedes the live echo', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    t.state('done')
    await f.open()
    expect(f.draft.get()).toBe('Bob')
    t.identity(1, 'Bob')
    expect(f.notice.get()).toBe('')
    expect(f.draft.get()).toBe('Bob')
  })

  it('does not settle an accepted attempt from a legacy action error or a matching external name', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    t.state('checking')
    t.tell('action_error', {
      action: PROFILE_NAME_SET_ACTION,
      reason: 'An uncorrelated old error.',
    })
    t.identity(1, 'Bob')
    expect(f.pending.get()).toBe(true)
    expect(f.refusal.get()).toBeNull()
    expect(f.step.get()).toBe('form')
    t.state('done')
    f.dispose()
    f.start()
    await flush()
    expect(f.refusal.get()).toBeNull()
  })

  it('waits for nothing when a send cannot leave', async () => {
    const t = tab()
    const f = t.flow()
    await f.open()
    f.draft.set('Bob')
    t.connection.close()
    f.save()
    expect(f.pending.get()).toBe(false)
    expect(f.draft.get()).toBe('Bob')
    expect(f.step.get()).toBe('form')
  })
})

describe('tab rename checkpoint lifecycle', () => {
  it.each(['before', 'checking', 'applying', 'echo', 'done'] as const)(
    'preserves an unsent external conflict across reload at %s',
    async (point) => {
      const first = tab()
      const f = first.flow()
      await f.open()
      f.draft.set('Carol')
      if (point !== 'before') first.state('checking')
      if (point !== 'before' && point !== 'checking') first.state('applying')
      if (point === 'echo' || point === 'done') first.identity(1, 'Bob')
      if (point === 'done') first.state('done')
      f.dispose()
      expect(readProfileRenameMemory()?.requestId).toBeNull()

      const second = tab(false)
      const restored = second.flow()
      restored.start()
      second.identity(1, point === 'echo' || point === 'done' ? 'Bob' : 'Ann')
      second.state(point === 'done' ? 'done' : 'applying')
      await flush()
      expect(restored.step.get()).toBe('form')
      expect(restored.draft.get()).toBe('Carol')
      if (point !== 'done') {
        expect(restored.pending.get()).toBe(true)
        restored.save()
        second.identity(1, 'Bob')
        second.state('done')
      }
      expect(restored.pending.get()).toBe(false)
      expect(restored.edit.get().conflict).toBe(true)
      expect(restored.notice.get()).toBe('Changed elsewhere to "Bob".')
      expect(restored.canSave.get()).toBe(false)
      restored.save()
      expect(first.names()).toHaveLength(0)
      expect(second.names()).toHaveLength(0)
      restored.takeTheirs()
      expect(restored.draft.get()).toBe('Bob')
      expect(restored.edit.get().conflict).toBe(false)
    },
  )

  it.each([false, true])(
    'restores open invalid input on a fresh binding (accepted: %s) without a second send',
    async (accepted) => {
      const first = tab()
      const f = first.flow()
      await send(f)
      if (accepted) first.state('checking')
      f.draft.set(' x ')
      f.dispose()
      expect(readProfileRenameMemory()?.open).toBe(true)
      const second = tab(false)
      const restored = second.flow()
      restored.start()
      expect(readProfileRenameMemory()?.draft).toBe(' x ')
      expect(restored.step.get()).toBe('closed')
      second.identity(1)
      expect(restored.step.get()).toBe('closed')
      second.state(accepted ? 'checking' : null)
      await flush()
      expect(restored.step.get()).toBe('form')
      expect(restored.draft.get()).toBe(' x ')
      expect(restored.valid.get()).toBe(false)
      expect(second.names()).toHaveLength(0)
      expect(first.names()).toHaveLength(1)
    },
  )

  it.each(['echo-first', 'done-first'] as const)(
    'rebases a restored newer draft against correlated done (%s)',
    async (order) => {
      const first = tab()
      const f = first.flow()
      await send(f)
      first.state('checking')
      f.draft.set('Ann')
      f.dispose()
      const second = tab(false)
      const restored = second.flow()
      restored.start()
      second.identity(1, order === 'echo-first' ? 'Bob' : 'Ann')
      second.state('done')
      await flush()
      if (order === 'done-first') second.identity(1, 'Bob')
      expect(restored.draft.get()).toBe('Ann')
      expect(restored.step.get()).toBe('form')
      expect(restored.edit.get().conflict).toBe(false)
      expect(restored.notice.get()).toBe('')
      expect(restored.canSave.get()).toBe(true)
      expect(second.names()).toHaveLength(0)
    },
  )

  it('holds an early matching echo during pending restore until its receipt', async () => {
    const first = tab()
    const f = first.flow()
    await send(f)
    first.state('applying')
    f.draft.set('Ann')
    f.dispose()
    const second = tab(false)
    const restored = second.flow()
    restored.start()
    second.identity(1, 'Bob')
    second.state('applying')
    await flush()
    expect(restored.draft.get()).toBe('Ann')
    expect(restored.pending.get()).toBe(true)
    second.state('done')
    expect(restored.draft.get()).toBe('Ann')
    expect(restored.notice.get()).toBe('')
    expect(restored.edit.get().conflict).toBe(false)
    expect(second.names()).toHaveLength(0)
  })

  it('does not reapply an already consumed receipt over a later external baseline after reload', async () => {
    const first = tab()
    const f = first.flow()
    await send(f)
    first.state('checking')
    f.draft.set('Carol')
    first.identity(1, 'Bob')
    first.state('done')
    expect(readProfileRenameMemory()?.requestId).toBeNull()
    first.identity(1, 'Carl')
    f.keepMine()
    f.dispose()
    const second = tab(false)
    const restored = second.flow()
    restored.start()
    second.identity(1, 'Carl')
    second.state('done')
    await flush()
    expect(restored.draft.get()).toBe('Carol')
    expect(restored.edit.get().conflict).toBe(false)
    expect(restored.notice.get()).toBe('')
    expect(restored.canSave.get()).toBe(true)
  })

  it('keeps a current external name distinct from an unconsumed own receipt after reload', async () => {
    const first = tab()
    const f = first.flow()
    await send(f)
    first.state('checking')
    f.draft.set('Carol')
    f.dispose()
    const second = tab(false)
    const restored = second.flow()
    restored.start()
    second.identity(1, 'Carl')
    second.state('done')
    await flush()
    expect(restored.draft.get()).toBe('Carol')
    expect(restored.edit.get().conflict).toBe(true)
    expect(restored.notice.get()).toBe('Changed elsewhere to "Carl".')
    expect(second.names()).toHaveLength(0)
  })

  it('records the newly accepted request after a refused retry, including the pre-ack interval', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    t.state('refused')
    expect(readProfileRenameMemory()?.requestId).toBe('r1')
    f.draft.set('Carol')
    f.save()
    expect(readProfileRenameMemory()?.requestId).toBeNull()
    t.state('checking', 'Carol', 'r2')
    expect(readProfileRenameMemory()?.requestId).toBe('r2')
    f.dispose()
    const restored = t.flow()
    restored.start()
    await flush()
    expect(restored.draft.get()).toBe('Carol')
    expect(restored.pending.get()).toBe(true)
    expect(t.names()).toHaveLength(2)
  })

  it('keeps an in-memory draft on close/reopen even without persisted memory', async () => {
    const t = tab()
    const f = t.flow(false)
    await send(f)
    t.state('checking')
    f.draft.set('Carol')
    f.close()
    await f.open()
    expect(f.draft.get()).toBe('Carol')
    expect(readProfileRenameMemory()).toBeNull()
  })

  it('keeps explicit close false across disposal, fresh bindings and background frames', async () => {
    const first = tab()
    const f = first.flow()
    await send(f)
    first.state('checking')
    f.close()
    f.dispose()
    const second = tab()
    second.state('checking')
    const restored = second.flow()
    restored.start()
    second.state('done')
    second.identity(1, 'Bob')
    await flush()
    expect(restored.step.get()).toBe('closed')
    expect(readProfileRenameMemory()?.open).toBe(false)
    expect(second.names()).toHaveLength(0)
    expect(second.dispatch).not.toHaveBeenCalled()
  })

  it('does not open a new tab from shared pending or refusal', async () => {
    const t = tab()
    const f = t.flow()
    f.start()
    t.state('checking')
    t.state('refused')
    expect(f.step.get()).toBe('closed')
    expect(t.dispatch).not.toHaveBeenCalled()
    await f.open()
    expect(f.draft.get()).toBe('Bob')
    expect(f.refusal.get()).toBe(' Server refused this name.\nTry another. ')
  })

  it('revalidates step-up after reload and never stores proof or secrets', async () => {
    const first = tab()
    const f = first.flow()
    await f.open()
    f.draft.set('Carol')
    f.dispose()
    const second = tab()
    second.dispatch.mockReturnValue({ done: Promise.resolve({ reply: ASK }) })
    const restored = second.flow()
    restored.start()
    await flush()
    expect(restored.step.get()).toBe('step-up')
    restored.stepUp.password.set('secret')
    restored.stepUp.code.set('123456')
    expect(JSON.stringify(readProfileRenameMemory())).not.toContain('secret')
    expect(JSON.stringify(readProfileRenameMemory())).not.toContain('123456')
    restored.dispose()
    expect(restored.stepUp.password.get()).toBe('')
    expect(readProfileRenameMemory()?.open).toBe(true)
  })

  it.each(['Ann', 'Carol'])(
    'restores a same-instance cleanup after a late done without losing %s',
    async (newer) => {
      const t = tab()
      const f = t.flow()
      await send(f)
      t.state('checking')
      f.draft.set(newer)
      f.dispose()
      t.identity(1, 'Bob')
      t.state('done')
      f.start()
      await flush()
      expect(f.step.get()).toBe('form')
      expect(f.draft.get()).toBe(newer)
      expect(f.notice.get()).toBe('')
      expect(f.edit.get().conflict).toBe(false)
    },
  )

  it('consumes a held done for an unchanged restored send without reopening later', async () => {
    const first = tab()
    const f = first.flow()
    await send(f)
    first.state('checking')
    f.dispose()
    const second = tab(false)
    const restored = second.flow()
    restored.start()
    second.identity(1, 'Bob')
    second.state('done')
    await flush()
    expect(restored.step.get()).toBe('closed')
    expect(readProfileRenameMemory()?.open).toBe(false)
    expect(second.names()).toHaveLength(0)
  })

  it('restarts listeners after strict-effect cleanup without losing visibility intent', async () => {
    const t = tab()
    const f = t.flow()
    await f.open()
    f.draft.set('Carol')
    f.dispose()
    f.start()
    await flush()
    expect(f.step.get()).toBe('form')
    expect(f.draft.get()).toBe('Carol')
    t.identity(1, 'Carl')
    expect(f.edit.get().conflict).toBe(true)
  })

  it.each(['identity', 'signout', 'access', 'erase'] as const)(
    'discards tab intent on %s and cannot resurrect it from late frames',
    async (cause) => {
      const t = tab()
      const access = createSignal(true)
      const f = t.flow(true, access)
      await send(f)
      t.state('checking')
      if (cause === 'identity') t.identity(2, 'Other')
      if (cause === 'signout') t.identity(null)
      if (cause === 'access') access.set(false)
      if (cause === 'erase') {
        sessionStorage.removeItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)
        announceBrowserValueErased(PROFILE_RENAME_MEMORY_STORAGE_KEY)
      }
      expect(f.step.get()).toBe('closed')
      expect(readProfileRenameMemory()).toBeNull()
      t.state('done')
      t.identity(1, 'Bob')
      access.set(true)
      await flush()
      expect(f.step.get()).toBe('closed')
      expect(readProfileRenameMemory()).toBeNull()
    },
  )

  it('clears foreign memory after authoritative handshake even without a mounted flow', () => {
    writeProfileRenameMemory({
      version: 1,
      userId: 9,
      open: true,
      step: 'form',
      draft: 'Private draft',
      checkpoint: null,
      requestId: null,
    })
    const t = tab(false)
    expect(readProfileRenameMemory()?.userId).toBe(9)
    t.identity(1)
    expect(readProfileRenameMemory()).toBeNull()
  })

  it('closes on the real session blocked-card hook and ignores late step-up answers', async () => {
    const t = tab()
    let answer: (value: { reply: typeof SKIP }) => void = () => {}
    t.dispatch.mockReturnValue({
      done: new Promise((resolve) => {
        answer = resolve
      }),
    })
    const f = t.flow()
    const opening = f.open()
    t.identity(1, 'Ann', { accountBlocked: {} })
    answer({ reply: SKIP })
    await opening
    expect(f.step.get()).toBe('closed')
    expect(readProfileRenameMemory()).toBeNull()
  })

  it.each(['blocked', 'frozen', 'merged', 'ack'] as const)(
    'closes from the actual session %s hook',
    async (kind) => {
      const t = tab()
      const f = t.flow()
      await send(f)
      const accountStanding = {
        shown: kind === 'ack' ? 'none' : kind,
        blocked: kind === 'blocked',
        frozen: kind === 'frozen',
        deletionEffectiveAt: null,
        lapsed: [],
        window: [],
        mergedInto: kind === 'merged' ? 2 : null,
        mergedIntoName: kind === 'merged' ? 'Other' : null,
      }
      t.identity(1, 'Ann', {
        accountStanding,
        pendingAck: kind === 'ack' ? 'auth_signed_in' : null,
      })
      expect(hilosProfileNameAccess.get()).toBe(false)
      expect(f.step.get()).toBe('closed')
      expect(readProfileRenameMemory()).toBeNull()
      t.state('done')
      expect(f.step.get()).toBe('closed')
    },
  )

  it('leaves disposed flow fields untouched by late names and STATE', async () => {
    const t = tab()
    const f = t.flow()
    await send(f)
    t.state('checking')
    f.draft.set('Carol')
    f.dispose()
    const stored = sessionStorage.getItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)
    t.identity(1, 'Bob')
    t.state('done')
    expect(f.draft.get()).toBe('Carol')
    expect(f.step.get()).toBe('closed')
    expect(sessionStorage.getItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)).toBe(
      stored,
    )
  })

  it.each(['corrupt', 'denied', 'absent'] as const)(
    'works with %s storage',
    async (kind) => {
      if (kind === 'corrupt')
        sessionStorage.setItem(PROFILE_RENAME_MEMORY_STORAGE_KEY, '{bad json')
      if (kind === 'denied')
        Object.defineProperty(globalThis, 'sessionStorage', {
          configurable: true,
          get() {
            throw new Error('denied')
          },
        })
      if (kind === 'absent') vi.stubGlobal('sessionStorage', undefined)
      const t = tab()
      const f = t.flow()
      await send(f)
      t.state('checking')
      t.state('done')
      expect(f.step.get()).toBe('closed')
      expect(t.names()).toHaveLength(1)
    },
  )

  it('does not persist standard flows without opt-in', async () => {
    const t = tab()
    const f = t.flow(false)
    await send(f)
    expect(readProfileRenameMemory()).toBeNull()
  })
})
