import { afterEach, describe, expect, it } from 'vitest'

import { type HilosUsersContext } from '../../../src/admin/users/hilosUsers.js'
import {
  createHilosUserSessions,
  type HilosUserSession,
} from '../../../src/admin/users/index.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../../src/protocol/parseSignal.js'
import { bindAdminAccess } from '../../../src/session/adminAccess.js'
import { bindSessionScope } from '../../../src/session/sessionScope.js'
import { entityCollection } from '../../../src/state/EntityCollection.js'
import { USER_ENTITY_TYPE, userFromFields } from '../../../src/state/entity.js'
import {
  HIDDEN_VALUE,
  type Hideable,
  isHiddenValue,
} from '../../../src/state/hiddenValue.js'
import {
  ingest,
  type ListItemFragment,
  type NormalizerOptions,
} from '../../../src/state/normalizer.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../../src/state/signal.js'

/** The slot maps of the two demos: chat maps the session slot to a type, binance does not. */
const DEMO_ENTITY_TYPES: [string, NormalizerOptions][] = [
  [
    'session',
    { entityTypes: { users: USER_ENTITY_TYPE, sessions: 'session' } },
  ],
  ['sessions', { entityTypes: { users: USER_ENTITY_TYPE } }],
]
const CHAT_TYPES = DEMO_ENTITY_TYPES[0][1]

const releases: (() => void)[] = []

/**
 * The sessions a test expects to be shown, failing the test when they arrived hidden.
 *
 * @param sessions The controller's current sessions.
 */
function shown(
  sessions: Hideable<readonly HilosUserSession[]>,
): readonly HilosUserSession[] {
  if (isHiddenValue(sessions)) {
    throw new Error('The sessions arrived hidden')
  }

  return sessions
}

afterEach(() => {
  for (const release of releases.splice(0)) {
    release()
  }
})

/** One session row as the list's anchor slot carries it. */
function sessionRow(
  id: number,
  lastSeenAt: number,
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id,
    userId: 5,
    deviceName: `Browser ${id}`,
    browserTimeZone: 'Europe/Warsaw',
    createdAt: 1789812000000,
    lastSeenAt,
    expiresAt: null,
    impersonatorUserId: null,
    ...overrides,
  }
}

/** One live tab as the list's connections slot carries it. */
function tab(
  acceptKey: string,
  sessionId: number,
  connectedAt: number,
): Record<string, unknown> {
  return { acceptKey, sessionId, connectedAt }
}

/** One list item of the card's sessions list. */
function item(
  session: Record<string, unknown>,
  connections: Record<string, unknown>[] = [],
  impersonator?: Record<string, unknown>,
): ListItemFragment {
  return {
    itemKey: session.id as number,
    slots: {
      sessions: session,
      connections,
      ...(impersonator === undefined ? {} : { users: impersonator }),
    },
  }
}

/** A users context over a real scope manager, recording every dispatched action. */
function usersContext(): {
  context: HilosUsersContext
  scopes: ScopeManager
  dispatched: { action: string; payload: Record<string, unknown> }[]
} {
  const scopes = new ScopeManager()
  const dispatched: { action: string; payload: Record<string, unknown> }[] = []
  const actions = {
    dispatch(action: string, payload: Record<string, unknown>): ActionHandle {
      dispatched.push({ action, payload })

      return { done: new Promise(() => {}) } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  return {
    context: {
      scopes,
      connection: {} as HilosConnection,
      actions,
      users: entityCollection(scopes, USER_ENTITY_TYPE, userFromFields),
    },
    scopes,
    dispatched,
  }
}

/**
 * Open the card's page and deliver its answer through the real normalizer.
 *
 * @param scopes The scope manager of the context.
 * @param items The list items the answer carries.
 * @param options The demo's slot types.
 * @param enabled The page's userSessionsEnabled flag, or null for an answer without it.
 */
function answerPage(
  scopes: ScopeManager,
  items: ListItemFragment[],
  options: NormalizerOptions = CHAT_TYPES,
  enabled: boolean | null = true,
): void {
  const page = scopes.openPage('hilos_user_sessions')
  ingest(
    page,
    {
      data: enabled === null ? {} : { userSessionsEnabled: enabled },
      lists: { userSessions: { items } },
    },
    options,
  )
}

/**
 * Bind the admin access to a handshake of the given person on a node in the given mode.
 *
 * @param user The signed-in person, or null for a guest.
 * @param viewMode Whether the node is in the admin view mode.
 */
function bindAccess(
  user: { id: number; admin: boolean } | null,
  viewMode: boolean,
): (user: { id: number; admin: boolean } | null) => void {
  const listeners: ((signal: ProjectSignal) => void)[] = []
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
  } as unknown as HilosConnection
  const sessionScopes = new ScopeManager()
  bindSessionScope(connection, sessionScopes)
  releases.push(bindAdminAccess(sessionScopes))
  const handshake = (next: { id: number; admin: boolean } | null): void => {
    const signal = {
      kind: 'project',
      type: 'handshake_response',
      data: {
        entities: {
          currentUser: next === null ? null : { ...next, name: 'Olena' },
        },
        data: { adminViewMode: viewMode },
      },
      envelope: {},
    } as unknown as ProjectSignal
    for (const listener of listeners) {
      listener(signal)
    }
  }
  handshake(user)

  return handshake
}

describe('createHilosUserSessions', () => {
  it.each(DEMO_ENTITY_TYPES)(
    'orders sessions by activity then id and tabs by connection then key, with the slot typed %s',
    (_type, options) => {
      const { context, scopes } = usersContext()
      const controller = createHilosUserSessions(context)
      answerPage(
        scopes,
        [
          item(sessionRow(3, 1790000000000), [
            tab('ak-b', 3, 1790000000500),
            tab('ak-c', 3, 1790000000900),
            tab('ak-a', 3, 1790000000500),
          ]),
          item(sessionRow(8, 1790000000000)),
          item(sessionRow(4, 1790500000000), [tab('ak-d', 4, 1790400000000)]),
        ],
        options,
      )

      const sessions = shown(controller.sessions.get())
      expect(sessions.map((session) => session.id)).toEqual([4, 8, 3])
      expect(sessions[2]?.tabs).toEqual([
        { acceptKey: 'ak-c', connectedAt: 1790000000900, current: false },
        { acceptKey: 'ak-a', connectedAt: 1790000000500, current: false },
        { acceptKey: 'ak-b', connectedAt: 1790000000500, current: false },
      ])
      expect(sessions[1]).toEqual({
        id: 8,
        deviceName: 'Browser 8',
        browserTimeZone: 'Europe/Warsaw',
        createdAt: 1789812000000,
        lastSeenAt: 1790000000000,
        expiresAt: null,
        current: false,
        impersonated: false,
        tabs: [],
        impersonatorName: null,
      })
      expect(controller.count.get()).toBe(3)
    },
  )

  it('follows the list as the server moves it and removes nothing on its own', () => {
    const { context, scopes, dispatched } = usersContext()
    const controller = createHilosUserSessions(context)
    answerPage(scopes, [
      item(sessionRow(3, 1790000000000), [tab('ak-a', 3, 1790000000100)]),
      item(sessionRow(4, 1790500000000)),
    ])

    controller.endSession(5, 4)
    controller.endAllSessions(5)
    expect(controller.count.get()).toBe(2)

    const page = scopes.page()!
    ingest(
      page,
      {
        lists: {
          userSessions: {
            items: [
              item(sessionRow(3, 1790900000000), [
                tab('ak-a', 3, 1790000000100),
                tab('ak-b', 3, 1790900000000),
              ]),
            ],
            deleted: [4],
          },
        },
      },
      CHAT_TYPES,
    )

    const sessions = shown(controller.sessions.get())
    expect(sessions.map((session) => session.id)).toEqual([3])
    expect(sessions[0]?.tabs.map((entry) => entry.acceptKey)).toEqual([
      'ak-b',
      'ak-a',
    ])
    expect(controller.count.get()).toBe(1)
    expect(dispatched).toHaveLength(2)
  })

  it('names the acting administrator from the delivered person and follows a rename', () => {
    const { context, scopes } = usersContext()
    const controller = createHilosUserSessions(context)
    answerPage(scopes, [
      item(sessionRow(3, 1790000000000, { impersonatorUserId: 9 }), [], {
        id: 9,
        name: 'Olena',
      }),
    ])
    const names: unknown[] = []
    releases.push(
      subscribeSignal(controller.sessions, (sessions) =>
        names.push(shown(sessions)[0]?.impersonatorName),
      ),
    )

    const before = shown(controller.sessions.get())
    expect(before[0]).toMatchObject({
      impersonated: true,
      impersonatorName: 'Olena',
    })

    ingest(
      scopes.page()!,
      {
        lists: {
          userSessions: {
            items: [
              item(
                sessionRow(3, 1790000000000, { impersonatorUserId: 9 }),
                [],
                { id: 9, name: 'Olena Kowalska' },
              ),
            ],
          },
        },
      },
      CHAT_TYPES,
    )

    expect(names.at(-1)).toBe('Olena Kowalska')
    const after = shown(controller.sessions.get())
    expect(after[0]?.impersonatorName).toBe('Olena Kowalska')
  })

  it('falls back to the id of a gone administrator and never to the browser session', () => {
    const { context, scopes } = usersContext()
    scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 9 },
      { id: 9, name: 'Ambient' },
    )
    const controller = createHilosUserSessions(context)
    answerPage(scopes, [
      item(sessionRow(3, 1790000000000, { impersonatorUserId: 9 })),
    ])

    const sessions = shown(controller.sessions.get())
    expect(sessions[0]).toMatchObject({
      impersonated: true,
      impersonatorName: '#9',
    })
  })

  it('hands a hidden administrator name on as hidden', () => {
    const { context, scopes } = usersContext()
    const controller = createHilosUserSessions(context)
    answerPage(scopes, [
      item(sessionRow(3, 1790000000000, { impersonatorUserId: 9 }), [], {
        id: 9,
        name: { _hidden: true },
      }),
    ])

    const sessions = shown(controller.sessions.get())
    expect(sessions[0]?.impersonatorName).toBe(HIDDEN_VALUE)
  })

  it('hides the whole list when a field it is built from arrived hidden', () => {
    const cases: [Record<string, unknown>, Record<string, unknown>[]][] = [
      [sessionRow(3, 1790000000000, { lastSeenAt: { _hidden: true } }), []],
      [sessionRow(3, 1790000000000, { deviceName: { _hidden: true } }), []],
      [
        sessionRow(3, 1790000000000),
        [{ acceptKey: 'ak-a', sessionId: 3, connectedAt: { _hidden: true } }],
      ],
    ]
    for (const [session, connections] of cases) {
      const { context, scopes } = usersContext()
      const controller = createHilosUserSessions(context)
      answerPage(scopes, [
        item(sessionRow(4, 1790500000000)),
        item(session, connections),
      ])

      expect(controller.sessions.get()).toBe(HIDDEN_VALUE)
      expect(controller.count.get()).toBe(HIDDEN_VALUE)
    }
  })

  it('hides the sessions and their count from a viewer, even ones a wider scope left behind', () => {
    const handshake = bindAccess({ id: 1, admin: true }, true)
    const { context, scopes } = usersContext()
    const controller = createHilosUserSessions(context)
    answerPage(scopes, [
      item(sessionRow(3, 1790000000000), [tab('ak-a', 3, 1790000000100)]),
    ])
    expect(controller.count.get()).toBe(1)

    handshake({ id: 10, admin: false })

    expect(controller.sessions.get()).toBe(HIDDEN_VALUE)
    expect(controller.count.get()).toBe(HIDDEN_VALUE)
  })

  it('counts sign-ins only where the page says the card reads the list', () => {
    const { context, scopes } = usersContext()
    const controller = createHilosUserSessions(context)
    expect(controller.count.get()).toBeUndefined()

    answerPage(scopes, [], CHAT_TYPES, true)
    expect(controller.count.get()).toBe(0)

    answerPage(scopes, [], CHAT_TYPES, null)
    expect(controller.count.get()).toBeUndefined()

    answerPage(scopes, [item(sessionRow(3, 1790000000000))], CHAT_TYPES, false)
    expect(controller.count.get()).toBeUndefined()

    answerPage(scopes, [
      item(sessionRow(3, 1790000000000), [
        tab('ak-a', 3, 1790000000100),
        tab('ak-b', 3, 1790000000200),
        tab('ak-c', 3, 1790000000300),
      ]),
    ])
    expect(controller.count.get()).toBe(1)
  })

  it('sends exactly the page payloads, and ending all excludes neither the current nor an acted-in session', () => {
    const { context, scopes, dispatched } = usersContext()
    const controller = createHilosUserSessions(context)
    answerPage(scopes, [
      item(sessionRow(3, 1790000000000)),
      item(
        sessionRow(4, 1790500000000, { impersonatorUserId: 9 }),
        [tab('ak-self', 4, 1790500000000)],
        { id: 9, name: 'Olena' },
      ),
    ])

    controller.endSession(5, 4)
    controller.endAllSessions(5)

    expect(dispatched).toEqual([
      {
        action: 'hilos_user_session_end',
        payload: { userId: 5, sessionId: 4 },
      },
      { action: 'hilos_user_sessions_end_all', payload: { userId: 5 } },
    ])
    const sessions = shown(controller.sessions.get())
    expect(
      sessions.map(({ id, impersonated }) => ({ id, impersonated })),
    ).toEqual([
      { id: 4, impersonated: true },
      { id: 3, impersonated: false },
    ])
    expect(controller.count.get()).toBe(2)
  })

  it('lets go of a page scope it lost', () => {
    const { context, scopes } = usersContext()
    const controller = createHilosUserSessions(context)
    answerPage(scopes, [item(sessionRow(3, 1790000000000))])
    const left = scopes.page()!
    const seen: unknown[] = []
    releases.push(
      subscribeSignal(controller.sessions, (sessions) => seen.push(sessions)),
    )

    scopes.dropPage()
    expect(seen).toEqual([[]])
    expect(controller.count.get()).toBeUndefined()

    ingest(
      left,
      {
        lists: {
          userSessions: { items: [item(sessionRow(3, 1790900000000))] },
        },
      },
      CHAT_TYPES,
    )
    expect(seen).toHaveLength(1)

    answerPage(scopes, [item(sessionRow(7, 1790000000000))])
    const sessions = shown(controller.sessions.get())
    expect(sessions.map((session) => session.id)).toEqual([7])
  })
})
