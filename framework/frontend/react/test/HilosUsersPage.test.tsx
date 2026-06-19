import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import {
  HilosConnection,
  ScopeManager,
  createSignal,
  entityCollection,
} from '@hilos/core'
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
    currentRoute: createSignal<PageRouteMatch>({ page: '', params: {} }),
    navigate: () => {},
    start: () => {},
    stop: () => {},
  }
}

// Two users referenced by two rows of the users table, plus the inline presence
// summary the project's backend fills — the shape resolveHilosUserRow folds.
function usersContext(): HilosUsersContext {
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_users')
  page.entities.upsert(
    { type: 'user', id: 1 },
    { id: 1, name: 'Alice', lastActivity: '2026-06-19 10:00' },
  )
  page.entities.upsert(
    { type: 'user', id: 2 },
    { id: 2, name: 'Bob', lastActivity: null },
  )
  page.tables.upsert('hilosUsers', 1, {
    users: { type: 'user', id: 1 },
    connections: { presence: 'online', onlineSessionCount: 2 },
  })
  page.tables.upsert('hilosUsers', 2, {
    users: { type: 'user', id: 2 },
    connections: { presence: 'offline', onlineSessionCount: 0 },
  })
  const users = entityCollection<HilosUserProfile>(
    scopes,
    'user',
    (fields) => ({
      id: Number(fields.id),
      name: String(fields.name ?? ''),
      lastActivity: (fields.lastActivity as string | null) ?? null,
    }),
  )
  const connection = new HilosConnection({ url: 'ws://test/ws' })
  return { scopes, connection, users }
}

describe('HilosUsersPage', () => {
  afterEach(cleanup)

  it('renders a row per user with name, presence, and sessions', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage context={usersContext()} />
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
          context={usersContext()}
          rowActions={(row) => <a data-id={`open-${row.id}`}>Open</a>}
        />
      </HilosRouterContext.Provider>,
    )
    expect(container.querySelector('[data-id="open-1"]')).not.toBeNull()
    expect(container.querySelector('[data-id="open-2"]')).not.toBeNull()
  })
})
