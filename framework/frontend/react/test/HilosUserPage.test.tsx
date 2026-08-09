import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
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

import { HilosUserPage } from '../src/admin/users/HilosUserPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: { userId: '1' },
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    clearPageError: () => {},
    navigate: () => {},
    start: () => {},
    stop: () => {},
  }
}

// The single-user detail arrives as the one row of the `userDetail` table; when
// `seed` is false the table is empty and the page shows its loading state.
function userContext(seed: boolean): HilosUsersContext {
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_user')
  if (seed) {
    page.entities.upsert(
      { type: 'user', id: 1 },
      { id: 1, name: 'Alice', lastActivity: '2026-06-19 10:00' },
    )
    page.tables.upsert('userDetail', 1, {
      users: { type: 'user', id: 1 },
      connections: { presence: 'online', onlineSessionCount: 2 },
    })
  }
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

function renderPage(context: HilosUsersContext) {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosUserPage context={context} />
    </HilosRouterContext.Provider>,
  )
}

describe('HilosUserPage', () => {
  afterEach(cleanup)

  it('renders the loading state until the detail row lands', () => {
    const { container } = renderPage(userContext(false))
    expect(
      container.querySelector('[data-id="hilos-user-empty"]'),
    ).not.toBeNull()
    expect(container.querySelector('[data-id="hilos-user-detail"]')).toBeNull()
  })

  it('renders the user profile card from the detail row', () => {
    const { container } = renderPage(userContext(true))
    expect(
      container.querySelector('[data-id="hilos-user-name"]')?.textContent,
    ).toBe('Alice')
    expect(
      container.querySelector('[data-id="hilos-user-id"]')?.textContent,
    ).toBe('1')
    expect(
      container.querySelector('[data-id="hilos-user-sessions"]')?.textContent,
    ).toBe('2')
  })

  it('opens the rename modal prefilled with the current name on Edit', () => {
    const { container } = renderPage(userContext(true))
    // Editing is modal, not inline: nothing is mounted until Edit, and the modal
    // portals to <body>, so the input is queried on the document, not container.
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-user-name-input"]'),
    ).toBeNull()
    fireEvent.click(
      container.querySelector('[data-id="hilos-user-edit"]') as Element,
    )
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    const input = document.querySelector(
      '[data-id="hilos-user-name-input"]',
    ) as HTMLInputElement
    expect(input).not.toBeNull()
    expect(input.value).toBe('Alice')
  })
})
