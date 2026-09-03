import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import { ScopeManager, createSignal, entityCollection } from '@hilos/core'
import type {
  HilosRouter,
  HilosUserProfile,
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
  name: string
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
  const connection = {
    sendTableViewport(page: string, tableKey: string): boolean {
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
  const collection = entityCollection<HilosUserProfile>(
    scopes,
    'user',
    (fields) => ({
      id: Number(fields.id),
      name: String(fields.name ?? ''),
      lastActivity: (fields.lastActivity as string | null) ?? null,
    }),
  )

  return {
    scopes,
    connection: connection as unknown as HilosUsersContext['connection'],
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
})
