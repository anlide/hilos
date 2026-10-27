import { afterEach, describe, expect, it, vi } from 'vitest'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import { type WebSocketLike } from '../../src/connection/HilosConnection.js'
import {
  bindProfileNameState,
  hilosProfileNameIdentity,
  hilosProfileNameState,
  hilosProfileNameStateKnown,
  profileNameStateSchema,
  SIGNAL_PROFILE_NAME_STATE,
  type HilosProfileNameChange,
} from '../../src/profile/profileNameState.js'
import {
  bindSessionScope,
  SESSION_SIGNAL_SCHEMAS,
  sessionUserId,
  type SessionScopeOptions,
} from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'

const releases: Array<() => void> = []

/** Drive real connection parsing and the public session binding without a browser. */
function fixture(options: SessionScopeOptions = {}) {
  const sockets: Array<ReturnType<typeof socketDouble>> = []
  const { connection } = createHilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => {
      const socket = socketDouble()
      sockets.push(socket)
      return socket.socket
    },
  })
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes, options)
  const release = bindProfileNameState(connection, scopes, options)
  releases.push(() => {
    release()
    connection.close()
  })
  const errors = vi.fn()
  connection.on('parseFailure', errors)
  const open = () => {
    connection.connect()
    sockets.at(-1)!.open()
    sockets.at(-1)!.message('handshake', { build: 'test' })
  }
  const tell = (type: string, data: unknown) =>
    sockets.at(-1)!.message(type, data)
  open()

  return {
    connection,
    scopes,
    errors,
    release,
    sent: () => sockets.flatMap((socket) => socket.sent),
    open,
    tell,
    state: (userId: number | null, change: HilosProfileNameChange | null) =>
      tell('hilos_profile_name_state', { userId, change }),
    identity: (id: number | null) =>
      tell('handshake_response', {
        data: { accountStanding: null },
        entities: {
          [options.currentUserSlot ?? 'currentUser']:
            id === null ? null : { id, name: 'Current person' },
        },
      }),
  }
}

/** A new physical socket with synchronous, ordered incoming messages. */
function socketDouble() {
  const listeners = new Map<
    string,
    Array<(event: { data?: unknown }) => void>
  >()
  const sent: string[] = []
  const socket: WebSocketLike = {
    send: (data) => sent.push(String(data)),
    close: () => {},
    addEventListener: (type, listener) => {
      const handlers = listeners.get(type) ?? []
      handlers.push(listener)
      listeners.set(type, handlers)
    },
  }

  return {
    socket,
    sent,
    open: () => {
      for (const listener of listeners.get('open') ?? []) listener({})
    },
    message: (type: string, data: unknown) => {
      for (const listener of listeners.get('message') ?? []) {
        listener({ data: JSON.stringify({ type, data }) })
      }
    },
  }
}

const checking: HilosProfileNameChange = {
  requestId: 'rename-1',
  name: 'New name',
  phase: 'checking',
  refusal: null,
}

afterEach(() => {
  for (const release of releases.splice(0)) release()
})

describe('profile name STATE schema', () => {
  it('loads the held module first without depending on a root import order', async () => {
    vi.resetModules()
    const direct = await import('../../src/profile/profileNameState.js')
    expect(
      direct.profileNameStateSchema.parse({ userId: null, change: null }),
    ).toEqual({
      userId: null,
      change: null,
    })
  })

  it('declares exactly the public server payload and joins ordinary connections', () => {
    expect(SIGNAL_PROFILE_NAME_STATE).toBe('hilos_profile_name_state')
    expect(SESSION_SIGNAL_SCHEMAS[SIGNAL_PROFILE_NAME_STATE]).toBe(
      profileNameStateSchema,
    )
    expect(
      profileNameStateSchema.parse({ userId: 1, change: checking }),
    ).toEqual({
      userId: 1,
      change: {
        requestId: 'rename-1',
        name: 'New name',
        phase: 'checking',
        refusal: null,
      },
    })
    expect(
      profileNameStateSchema.parse({ userId: null, change: null }),
    ).toEqual({
      userId: null,
      change: null,
    })
    // The PHP DTO requires strings, with no additional name/request-id policy.
    expect(
      profileNameStateSchema.safeParse({
        userId: 1,
        change: { ...checking, requestId: '', name: '' },
      }).success,
    ).toBe(true)
  })

  it.each([
    undefined,
    null,
    {},
    { userId: 1 },
    { change: null },
    { userId: '1', change: null },
    { userId: 1.5, change: null },
    { userId: null, change: checking },
    { userId: 1, change: false },
    { userId: 1, change: {} },
    { userId: 1, change: { ...checking, requestId: undefined } },
    { userId: 1, change: { ...checking, name: null } },
    { userId: 1, change: { ...checking, phase: undefined } },
    { userId: 1, change: { ...checking, phase: 'failed' } },
    { userId: 1, change: { ...checking, refusal: undefined } },
    { userId: 1, change: { ...checking, refusal: 1 } },
    { userId: 1, change: { ...checking, refusal: 'Not accepted.' } },
    { userId: 1, change: { ...checking, phase: 'applying', refusal: 'No.' } },
    { userId: 1, change: { ...checking, phase: 'done', refusal: 'No.' } },
    { userId: 1, change: { ...checking, phase: 'refused' } },
    { userId: 1, change: { ...checking, phase: 'refused', refusal: '' } },
    { userId: 1, change: checking, sessionTokenHash: 'internal' },
    { userId: 1, change: { ...checking, updatedAt: 123 } },
  ])('rejects a missing, malformed or contradictory payload: %j', (data) => {
    expect(profileNameStateSchema.safeParse(data).success).toBe(false)
  })
})

describe('held profile name STATE', () => {
  it('distinguishes unknown identity and snapshot from an authoritative idle answer', () => {
    const tab = fixture()
    expect(sessionUserId(tab.scopes).get()).toBeNull()
    expect(hilosProfileNameIdentity.get()).toBeUndefined()
    expect(hilosProfileNameState.get()).toBeNull()
    expect(hilosProfileNameStateKnown.get()).toBe(false)
    tab.state(1, null)
    expect(hilosProfileNameStateKnown.get()).toBe(false)
    expect(hilosProfileNameIdentity.get()).toBeUndefined()
    tab.identity(1)
    expect(hilosProfileNameIdentity.get()).toBe(1)
    expect(hilosProfileNameStateKnown.get()).toBe(true)
    expect(hilosProfileNameState.get()).toEqual({ userId: 1, change: null })
  })

  it('holds whole phase snapshots before a view mounts, replaces attempts and clears', () => {
    const tab = fixture()
    tab.identity(1)
    expect(hilosProfileNameStateKnown.get()).toBe(false)
    for (const change of [
      checking,
      { ...checking, phase: 'applying' } as const,
      { ...checking, phase: 'done' } as const,
      { ...checking, requestId: 'rename-2' },
      {
        ...checking,
        requestId: 'rename-2',
        phase: 'refused',
        refusal: 'This name was not accepted.',
      } as const,
    ]) {
      tab.state(1, change)
      expect(hilosProfileNameState.get()).toEqual({ userId: 1, change })
      expect(hilosProfileNameStateKnown.get()).toBe(true)
    }
    tab.state(1, null)
    expect(hilosProfileNameState.get()).toEqual({ userId: 1, change: null })
    expect(tab.errors).not.toHaveBeenCalled()
    expect(tab.sent()).toEqual([])
  })

  it('never infers done or idle from acknowledgements, legacy flags or malformed STATE', () => {
    const tab = fixture()
    tab.identity(1)
    tab.state(1, checking)
    const held = hilosProfileNameState.get()
    tab.tell('checking', { checking: false })
    tab.tell('action_success', { action: 'rename' })
    tab.tell('action_error', { action: 'rename', reason: 'No.' })
    tab.tell('hilos_profile_name_state', { userId: 1 })
    tab.tell('hilos_profile_name_state', {
      userId: 1,
      change: { ...checking, phase: 'done', refusal: 'No.' },
    })
    expect(hilosProfileNameState.get()).toBe(held)
    expect(tab.errors).toHaveBeenCalledTimes(2)
  })

  it.each([true, false])(
    'checks STATE against its first identity (same owner: %s)',
    (same) => {
      const tab = fixture()
      tab.state(1, checking)
      expect(hilosProfileNameState.get()).toBeNull()
      tab.identity(same ? 1 : 2)
      expect(hilosProfileNameIdentity.get()).toBe(same ? 1 : 2)
      expect(hilosProfileNameState.get()).toEqual(
        same ? { userId: 1, change: checking } : null,
      )
      expect(hilosProfileNameStateKnown.get()).toBe(same)
    },
  )

  it('clears a previous outcome on identity loss and never publishes a foreign STATE', () => {
    const tab = fixture()
    tab.identity(1)
    tab.state(1, { ...checking, phase: 'done' })
    tab.identity(2)
    expect(hilosProfileNameIdentity.get()).toBe(2)
    expect(hilosProfileNameStateKnown.get()).toBe(false)
    tab.state(1, checking)
    expect(hilosProfileNameState.get()).toBeNull()
    tab.state(2, checking)
    tab.identity(null)
    expect(hilosProfileNameIdentity.get()).toBeNull()
    expect(hilosProfileNameState.get()).toBeNull()
    expect(hilosProfileNameStateKnown.get()).toBe(false)
    tab.state(null, null)
    expect(hilosProfileNameState.get()).toEqual({ userId: null, change: null })
    expect(hilosProfileNameStateKnown.get()).toBe(true)
  })

  it('holds a new owner frame until its following identity, including sign-out clearing', () => {
    const tab = fixture()
    tab.identity(1)
    tab.state(1, checking)
    tab.state(2, { ...checking, requestId: 'other-session' })
    expect(hilosProfileNameState.get()?.userId).toBe(1)
    tab.identity(2)
    expect(hilosProfileNameState.get()?.change?.requestId).toBe('other-session')
    tab.state(null, null)
    expect(hilosProfileNameState.get()?.userId).toBe(2)
    tab.identity(null)
    expect(hilosProfileNameState.get()).toEqual({ userId: null, change: null })
  })

  it.each([true, false])(
    'requires a fresh STATE across reconnect (STATE first: %s)',
    (stateFirst) => {
      const tab = fixture()
      tab.identity(1)
      tab.state(1, { ...checking, phase: 'done' })
      tab.connection.close()
      tab.open()
      expect(hilosProfileNameIdentity.get()).toBe(1)
      expect(hilosProfileNameStateKnown.get()).toBe(false)
      if (stateFirst) tab.state(2, checking)
      tab.identity(2)
      if (!stateFirst) tab.state(2, checking)
      expect(hilosProfileNameIdentity.get()).toBe(2)
      expect(hilosProfileNameState.get()).toEqual({
        userId: 2,
        change: checking,
      })
    },
  )

  it('preserves same-person reconnect knowledge only after a fresh matching frame', () => {
    const tab = fixture()
    tab.identity(1)
    tab.state(1, {
      ...checking,
      phase: 'refused',
      refusal: 'Try a different name.',
    })
    tab.connection.close()
    tab.open()
    tab.identity(1)
    expect(hilosProfileNameStateKnown.get()).toBe(false)
    tab.state(1, null)
    expect(hilosProfileNameState.get()).toEqual({ userId: 1, change: null })
  })

  it('uses configured session identity and an already authoritative scope on bind', () => {
    const options = { currentUserSlot: 'me', currentUserEntityType: 'person' }
    const tab = fixture(options)
    tab.identity(7)
    tab.release()
    const release = bindProfileNameState(tab.connection, tab.scopes, options)
    releases.push(release)
    expect(hilosProfileNameIdentity.get()).toBe(7)
    expect(tab.scopes.session.data.signal('me').get()).toEqual({
      type: 'person',
      id: 7,
    })
    tab.state(7, checking)
    expect(hilosProfileNameState.get()?.userId).toBe(7)
  })

  it('does not erase the same connection on an initially empty replacement scope', () => {
    const tab = fixture()
    tab.identity(1)
    tab.state(1, checking)
    const scopes = new ScopeManager()
    bindSessionScope(tab.connection, scopes)
    const release = bindProfileNameState(tab.connection, scopes)
    releases.push(release)
    expect(hilosProfileNameIdentity.get()).toBe(1)
    expect(hilosProfileNameState.get()).toEqual({ userId: 1, change: checking })
    tab.identity(1)
    expect(hilosProfileNameState.get()).toEqual({ userId: 1, change: checking })
    tab.identity(2)
    expect(hilosProfileNameState.get()).toBeNull()
  })

  it('releases old readers and isolates connections even when the person id matches', () => {
    const first = fixture()
    first.identity(1)
    first.state(1, checking)
    const second = fixture()
    expect(hilosProfileNameIdentity.get()).toBeUndefined()
    expect(hilosProfileNameStateKnown.get()).toBe(false)
    first.release()
    first.identity(2)
    first.state(2, checking)
    expect(hilosProfileNameState.get()).toBeNull()
    second.identity(1)
    second.state(1, null)
    expect(hilosProfileNameState.get()).toEqual({ userId: 1, change: null })
    second.release()
    second.release()
    second.state(1, checking)
    expect(hilosProfileNameState.get()).toEqual({ userId: 1, change: null })
  })
})
