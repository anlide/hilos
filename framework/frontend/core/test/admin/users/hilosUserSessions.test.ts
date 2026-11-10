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
import { HilosPages } from '../../../src/routing/hilosPages.js'
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

/** One list item of either sessions list, with the related person when present. */
function item(
  session: Record<string, unknown>,
  connections: Record<string, unknown>[] = [],
  user?: Record<string, unknown>,
): ListItemFragment {
  return {
    itemKey: session.id as number,
    slots: {
      sessions: session,
      connections,
      ...(user === undefined ? {} : { users: user }),
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
 * @param actingItems The outgoing sessions the answer carries.
 */
function answerPage(
  scopes: ScopeManager,
  items: ListItemFragment[],
  options: NormalizerOptions = CHAT_TYPES,
  enabled: boolean | null = true,
  actingItems: ListItemFragment[] = [],
): void {
  const page = scopes.openPage(HilosPages.USER_SESSIONS)
  ingest(
    page,
    {
      data: enabled === null ? {} : { userSessionsEnabled: enabled },
      lists: {
        userSessions: { items },
        userActingSessions: { items: actingItems },
      },
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
        actingAsName: null,
      })
      expect(controller.count.get()).toBe(3)
    },
  )

  it.each(DEMO_ENTITY_TYPES)(
    'merges both lists by session id and keeps the ordinary row during Stop, with the slot typed %s',
    (_type, options) => {
      const { context, scopes } = usersContext()
      const controller = createHilosUserSessions(context)
      answerPage(
        scopes,
        [
          item(sessionRow(4, 1790000000000, { impersonatorUserId: 12 }), [], {
            id: 12,
            name: 'Olena',
          }),
        ],
        options,
        true,
        [
          {
            ...item(
              sessionRow(3, 1790000000000, {
                userId: 9,
                impersonatorUserId: 5,
              }),
              [tab('ak-stale', 3, 1790000000500)],
              { id: 9, name: 'Maria' },
            ),
            itemKey: 'outgoing-3',
          },
          item(
            sessionRow(8, 1790500000000, {
              userId: 9,
              impersonatorUserId: 5,
            }),
            [
              tab('ak-b', 8, 1790000000500),
              tab('ak-c', 8, 1790000000900),
              tab('ak-a', 8, 1790000000500),
            ],
            { id: 9, name: 'Maria' },
          ),
        ],
      )
      expect(controller.count.get()).toBe(3)
      expect(shown(controller.sessions.get())[2]).toMatchObject({
        id: 3,
        impersonatorName: null,
        actingAsName: 'Maria',
      })

      ingest(
        scopes.page()!,
        {
          lists: {
            userSessions: {
              items: [
                item(sessionRow(3, 1790000000000), [
                  tab('ak-ordinary', 3, 1790000000600),
                ]),
              ],
            },
          },
        },
        options,
      )

      const sessions = shown(controller.sessions.get())
      expect(
        sessions.map(({ id, impersonatorName, actingAsName }) => ({
          id,
          impersonatorName,
          actingAsName,
        })),
      ).toEqual([
        { id: 8, impersonatorName: null, actingAsName: 'Maria' },
        { id: 4, impersonatorName: 'Olena', actingAsName: null },
        { id: 3, impersonatorName: null, actingAsName: null },
      ])
      expect(sessions[2]?.tabs).toEqual([
        {
          acceptKey: 'ak-ordinary',
          connectedAt: 1790000000600,
          current: false,
        },
      ])
      expect(sessions[0]?.tabs.map((entry) => entry.acceptKey)).toEqual([
        'ak-c',
        'ak-a',
        'ak-b',
      ])
      expect(controller.count.get()).toBe(3)

      ingest(
        scopes.page()!,
        { lists: { userActingSessions: { deleted: ['outgoing-3'] } } },
        options,
      )
      expect(shown(controller.sessions.get())).toEqual(sessions)
    },
  )

  it.each([HilosPages.USER, HilosPages.USER_SESSIONS])(
    'counts both lists once per session on %s',
    (pageKey) => {
      const { context, scopes } = usersContext()
      const controller = createHilosUserSessions(context)
      const page = scopes.openPage(pageKey)
      ingest(
        page,
        {
          data: { userSessionsEnabled: true },
          lists: {
            userSessions: { items: [item(sessionRow(3, 1790000000000))] },
            userActingSessions: {
              items: [
                item(
                  sessionRow(8, 1790500000000, {
                    userId: 9,
                    impersonatorUserId: 5,
                  }),
                  [
                    tab('ak-a', 8, 1790000000100),
                    tab('ak-b', 8, 1790000000200),
                  ],
                  { id: 9, name: 'Maria' },
                ),
              ],
            },
          },
        },
        CHAT_TYPES,
      )
      expect(controller.count.get()).toBe(2)

      ingest(page, { lists: { userSessions: { cleared: true } } }, CHAT_TYPES)
      expect(controller.count.get()).toBe(1)
      expect(shown(controller.sessions.get())[0]?.id).toBe(8)

      ingest(page, { data: { userSessionsEnabled: false } }, CHAT_TYPES)
      expect(controller.count.get()).toBeUndefined()
      ingest(page, { data: { userSessionsEnabled: true } }, CHAT_TYPES)
      expect(controller.count.get()).toBe(1)

      scopes.dropPage()
      expect(controller.sessions.get()).toEqual([])
      expect(controller.count.get()).toBeUndefined()
    },
  )

  it('names the person acted as, falling back only for a missing person and preserving hidden names', () => {
    const { context, scopes } = usersContext()
    scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 9 },
      { id: 9, name: 'Ambient' },
    )
    const controller = createHilosUserSessions(context)
    const session = sessionRow(8, 1790500000000, {
      userId: 9,
      impersonatorUserId: 5,
    })
    answerPage(scopes, [], CHAT_TYPES, true, [item(session)])
    expect(shown(controller.sessions.get())[0]).toMatchObject({
      impersonatorName: null,
      actingAsName: '#9',
    })
    const names: unknown[] = []
    releases.push(
      subscribeSignal(controller.sessions, (sessions) =>
        names.push(shown(sessions)[0]?.actingAsName),
      ),
    )

    for (const [name, expected] of [
      ['Maria', 'Maria'],
      ['Maria Kowalska', 'Maria Kowalska'],
      [{ _hidden: true }, HIDDEN_VALUE],
    ] as const) {
      ingest(
        scopes.page()!,
        {
          lists: {
            userActingSessions: {
              items: [item(session, [], { id: 9, name })],
            },
          },
        },
        CHAT_TYPES,
      )
      expect(names.at(-1)).toBe(expected)
    }

    expect(shown(controller.sessions.get())[0]).toMatchObject({
      impersonatorName: null,
      actingAsName: HIDDEN_VALUE,
    })
  })

  it('hides both lists and their count when an outgoing session or tab field is hidden', () => {
    const outgoing = sessionRow(8, 1790500000000, {
      userId: 9,
      impersonatorUserId: 5,
    })
    const cases = [
      item({ ...outgoing, userId: { _hidden: true } }),
      item({ ...outgoing, lastSeenAt: { _hidden: true } }),
      item(outgoing, [
        { acceptKey: 'ak-a', sessionId: 8, connectedAt: { _hidden: true } },
      ]),
      {
        itemKey: 8,
        slots: { sessions: outgoing, connections: { _hidden: true } },
      },
    ]
    for (const hidden of cases) {
      const { context, scopes } = usersContext()
      const controller = createHilosUserSessions(context)
      answerPage(
        scopes,
        [item(sessionRow(3, 1790000000000))],
        CHAT_TYPES,
        true,
        [hidden],
      )

      expect(controller.sessions.get()).toBe(HIDDEN_VALUE)
      expect(controller.count.get()).toBe(HIDDEN_VALUE)
    }
  })

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
    scopes.session.entities.upsert(
      { type: 'session', id: 8 },
      sessionRow(8, 1790500000000, { userId: 9, impersonatorUserId: 5 }),
    )
    const controller = createHilosUserSessions(context)
    answerPage(
      scopes,
      [item(sessionRow(3, 1790000000000), [tab('ak-a', 3, 1790000000100)])],
      CHAT_TYPES,
      true,
      [
        item(
          sessionRow(8, 1790500000000, {
            userId: 9,
            impersonatorUserId: 5,
          }),
          [tab('ak-b', 8, 1790000000200)],
          { id: 9, name: 'Maria' },
        ),
      ],
    )
    expect(controller.count.get()).toBe(2)

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
