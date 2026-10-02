import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HIDDEN_VALUE,
  ScopeManager,
  createSignal,
  entityCollection,
  USER_ENTITY_TYPE,
  userFromFields,
} from '@hilos/core'
import type {
  ActionLifecycleSource,
  Hideable,
  HilosRouter,
  HilosUsersContext,
  PageRouteMatch,
} from '@hilos/core'

import { HilosUsersPage } from '../src/admin/users/HilosUsersPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface UserSeed {
  id: number
  name: Hideable<string>
  lastActivity: string | null
  presence: string
  onlineSessionCount: number
}

// A users context whose connection answers each viewport request with a window
// built from the seeded users — the server-windowed table's data path in one hop.
// The `users` slot carries the entity fragment the binder upserts under the user
// collection's type; the `connections` slot stays inline (no id), as on the wire.
function seededContext(users: UserSeed[]): HilosUsersContext {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_users')
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  // The window this table would be served, by whichever road it arrives on: the page's
  // own answer at bind time, or a reply to a window the reader changed.
  const serveWindow = (page = 'hilos_users', tableKey = 'hilosUsers'): void => {
    const data = {
      page,
      tableKey,
      rows: users.map((user) => ({
        rowKey: user.id,
        slots: {
          users: {
            id: user.id,
            name: user.name,
            lastActivity: user.lastActivity,
          },
          connections: {
            presence: user.presence,
            onlineSessionCount: user.onlineSessionCount,
          },
        },
      })),
      totalCount: users.length,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }

  const connection = {
    // The lifecycle the context carries sends over it; this fake only has to accept it.
    sendAction(): boolean {
      return true
    },
    // The first window arrives with the page's own answer now (HIL-642), and binding is
    // when that answer would have landed — so this double serves it there rather than in
    // reply to a request the table no longer makes on mount.
    registerTableWindow(): void {
      serveWindow()
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(page: string, tableKey: string): boolean {
      serveWindow(page, tableKey)

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)
      }

      return () => windowListeners.delete(listener)
    },
  }
  const collection = entityCollection(scopes, USER_ENTITY_TYPE, userFromFields)

  return {
    scopes,
    connection: connection as unknown as HilosUsersContext['connection'],
    actions: new ActionLifecycle(connection as ActionLifecycleSource),
    users: collection,
  }
}

function twoUsers(): HilosUsersContext {
  return seededContext([
    {
      id: 1,
      name: 'Alice',
      lastActivity: '2026-06-19 10:00',
      presence: 'online',
      onlineSessionCount: 2,
    },
    {
      id: 2,
      name: 'Bob',
      lastActivity: null,
      presence: 'offline',
      onlineSessionCount: 0,
    },
  ])
}

describe('HilosUsersPage', () => {
  afterEach(cleanup)

  it('renders a row per user with name, presence, and sessions', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage context={twoUsers()} />
      </HilosRouterContext.Provider>,
    )
    expect(
      container.querySelectorAll('[data-id^="hilos-table-row-"]').length,
    ).toBe(2)
    expect(container.textContent).toContain('Alice')
    expect(container.textContent).toContain('Bob')
    expect(container.querySelector('.text-bg-success')?.textContent).toBe(
      'online',
    )
  })

  it('fills the trailing actions cell from the rowActions render prop', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage
          context={twoUsers()}
          rowActions={(row) => <a data-id={`open-${row.id}`}>Open</a>}
        />
      </HilosRouterContext.Provider>,
    )
    expect(container.querySelector('[data-id="open-1"]')).not.toBeNull()
    expect(container.querySelector('[data-id="open-2"]')).not.toBeNull()
  })

  it('offers no takeover on a row: it lives on the person card (HIL-1170)', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage context={twoUsers()} />
      </HilosRouterContext.Provider>,
    )

    expect(
      container.querySelectorAll('[data-id="hilos-table-row-1"]').length,
    ).toBeGreaterThan(0)
    expect(
      container.querySelector('[data-id^="hilos-users-impersonate-"]'),
    ).toBeNull()
  })

  it('draws the mark for a hidden name (HIL-1260)', () => {
    const context = seededContext([
      {
        id: 1,
        name: HIDDEN_VALUE,
        lastActivity: null,
        presence: 'online',
        onlineSessionCount: 1,
      },
    ])
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage context={context} />
      </HilosRouterContext.Provider>,
    )
    const row = container.querySelector('[data-id="hilos-table-row-1"]')

    expect(row?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
  })

  it('draws a cell under every declared column, aligned the way the column says', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage context={twoUsers()} />
      </HilosRouterContext.Provider>,
    )
    const cells = Array.from(
      container.querySelectorAll<HTMLElement>(
        '[data-id="hilos-table-row-1"] td',
      ),
    )

    // The page writes the content only; the cell and its class come from the
    // declaration, so the count of sessions stays right-aligned without the page
    // saying so.
    expect(cells).toHaveLength(container.querySelectorAll('thead th').length)
    expect(cells[1]?.textContent).toBe('Alice')
    expect(cells[1]?.classList.contains('fw-medium')).toBe(true)
    expect(cells[3]?.textContent).toBe('2')
    expect(cells[3]?.classList.contains('text-end')).toBe(true)
  })
})
