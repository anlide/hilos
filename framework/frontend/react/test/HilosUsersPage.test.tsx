// HilosUsersPage (React) — the framework people list over a seeded, server-windowed
// table: a row per person, the gray "Merged" badge beside the name of an account
// folded into another one (HIL-1292), and the presence badge in its three words
// (HIL-1566), live and apart from the mark of a source gone quiet.
import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  formatMoment,
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
import { readerTimeZone } from './readerTimeZone.js'

const readerTimeZoneReleases: Array<() => void> = []

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
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface UserSeed {
  id: number
  name: Hideable<string>
  lastActivity: number | null
  presence: string
  onlineSessionCount: number
  /** Whether the account was folded into another one (HIL-1292); the merge slot carries it. */
  merged?: boolean
}

/**
 * One person's row as the wire carries it.
 *
 * @param user The seeded person.
 */
function wireRow(user: UserSeed): Record<string, unknown> {
  return {
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
      merge: { merged: user.merged ?? false },
    },
  }
}

/** A seeded context and the live frames its table hears after the first window. */
interface LiveUsers {
  context: HilosUsersContext
  /** Number of server viewport requests made by this test connection. */
  viewportRequests(): number
  /**
   * A person's row changed in place.
   *
   * @param user The person as the row now carries them.
   */
  update(user: UserSeed): void
  /**
   * Which of a person's row sources stopped being kept up to date.
   *
   * @param id The person's id.
   * @param staleSources The row's slot keys gone quiet; empty means current again.
   */
  stale(id: number, staleSources: string[]): void
}

// A users context whose connection answers each viewport request with a window
// built from the seeded users — the server-windowed table's data path in one hop.
// The `users` slot carries the entity fragment the binder upserts under the user
// collection's type; the `connections` and `merge` slots stay inline (no id), as
// on the wire.
function seededContext(users: UserSeed[]): HilosUsersContext {
  return liveUsers(users).context
}

/**
 * {@link seededContext} with the live delta frames of the people table at hand.
 *
 * @param users The people of the first window.
 */
function liveUsers(users: UserSeed[]): LiveUsers {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_users')
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  let viewportRequestCount = 0
  const delta = (data: Record<string, unknown>): void => {
    for (const listener of deltaListeners) {
      listener({
        data: { page: 'hilos_users', tableKey: 'hilosUsers', ...data },
      })
    }
  }
  // The window this table would be served, by whichever road it arrives on: the page's
  // own answer at bind time, or a reply to a window the reader changed.
  const serveWindow = (page = 'hilos_users', tableKey = 'hilosUsers'): void => {
    const data = {
      page,
      tableKey,
      rows: users.map(wireRow),
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
      viewportRequestCount++
      serveWindow(page, tableKey)

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      const listeners =
        event === 'tableWindow'
          ? windowListeners
          : event === 'tableViewportDelta'
            ? deltaListeners
            : null
      listeners?.add(listener)

      return () => listeners?.delete(listener)
    },
  }
  const collection = entityCollection(scopes, USER_ENTITY_TYPE, userFromFields)

  return {
    context: {
      scopes,
      connection: connection as unknown as HilosUsersContext['connection'],
      actions: new ActionLifecycle(connection as ActionLifecycleSource),
      users: collection,
    },
    viewportRequests: () => viewportRequestCount,
    update(user: UserSeed): void {
      delta({ kind: 'row_updated', rowKey: user.id, row: wireRow(user) })
    },
    stale(id: number, staleSources: string[]): void {
      delta({ kind: 'row_stale', rowKey: id, staleSources })
    },
  }
}

function twoUsers(): HilosUsersContext {
  return seededContext([
    {
      id: 1,
      name: 'Alice',
      lastActivity: 1781863200000,
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
  afterEach(() => {
    cleanup()
    for (const release of readerTimeZoneReleases.splice(0)) release()
  })

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

  it('reformats activity in place without resetting the search or requesting a new viewport', async () => {
    const zone = readerTimeZone()
    readerTimeZoneReleases.push(zone.release)
    await act(async () => {
      zone.choose('Asia/Tokyo')
    })
    const alice = {
      id: 1,
      name: 'Person 1',
      lastActivity: 0,
      presence: 'offline',
      onlineSessionCount: 0,
    }
    const live = liveUsers([
      alice,
      {
        id: 2,
        name: 'Person 2',
        lastActivity: null,
        presence: 'offline',
        onlineSessionCount: 0,
      },
      {
        id: 3,
        name: 'Person 3',
        lastActivity: Number.NaN,
        presence: 'offline',
        onlineSessionCount: 0,
      },
    ])
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage context={live.context} />
      </HilosRouterContext.Provider>,
    )
    const before = formatMoment(0)
    const search = container.querySelector(
      '[data-id="hilos-table-search"]',
    ) as HTMLInputElement

    expect(
      container.querySelector('[data-id="hilos-table-row-1"]')?.textContent,
    ).toContain(before)
    expect(
      container.querySelector('[data-id="hilos-table-row-2"]')?.textContent,
    ).toContain('—')
    expect(
      container.querySelector('[data-id="hilos-table-row-3"]')?.textContent,
    ).toContain('—')

    await act(async () => {
      fireEvent.change(search, { target: { value: 'Person 1' } })
    })
    const viewportRequests = live.viewportRequests()
    const row = container.querySelector('[data-id="hilos-table-row-1"]')

    await act(async () => {
      zone.choose('Pacific/Kiritimati')
    })
    const after = formatMoment(0)

    expect(after).not.toBe(before)
    expect(
      container.querySelector('[data-id="hilos-table-row-1"]')?.textContent,
    ).toContain(after)
    expect(container.querySelector('[data-id="hilos-table-row-1"]')).toBe(row)
    expect(search.value).toBe('Person 1')
    expect(live.viewportRequests()).toBe(viewportRequests)
    expect(
      [1, 2, 3].map((id) =>
        container
          .querySelector(`[data-id="hilos-table-row-${id}"]`)
          ?.getAttribute('data-id'),
      ),
    ).toEqual(['hilos-table-row-1', 'hilos-table-row-2', 'hilos-table-row-3'])
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

  it('marks a merged account beside its name and nobody else (HIL-1292)', () => {
    const context = seededContext([
      {
        id: 1,
        name: 'Alice',
        lastActivity: null,
        presence: 'offline',
        onlineSessionCount: 0,
        merged: true,
      },
      {
        id: 2,
        name: 'Bob',
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

    // The table draws every row twice - the row and the card of the narrow
    // layout - so the badge is counted by whose row it sits in, not by number.
    const badges = Array.from(
      container.querySelectorAll('[data-id="hilos-users-merged"]'),
    )
    expect(badges.length).toBeGreaterThan(0)
    for (const badge of badges) {
      expect(badge.textContent?.trim()).toBe('Merged')
      expect(badge.classList.contains('text-bg-secondary')).toBe(true)
      expect(badge.querySelector('.bi-sign-merge-left')).not.toBeNull()
      expect(
        badge
          .closest(
            '[data-id^="hilos-table-row-"], [data-id^="hilos-table-card-"]',
          )
          ?.getAttribute('data-id'),
      ).toMatch(/-1$/)
    }
    expect(
      container
        .querySelector('[data-id="hilos-table-row-1"]')
        ?.querySelector('[data-id="hilos-users-merged"]'),
    ).not.toBeNull()
    expect(
      container
        .querySelector('[data-id="hilos-table-row-2"]')
        ?.querySelector('[data-id="hilos-users-merged"]'),
    ).toBeNull()
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

describe('HilosUsersPage presence (HIL-1566)', () => {
  afterEach(cleanup)

  /**
   * The presence cell of a person's row: the room the twin holds, the twin, and
   * the badge drawn over it.
   *
   * @param container The rendered list.
   * @param id The person's id.
   */
  function presence(
    container: HTMLElement,
    id: number,
  ): {
    room: Element | null
    twin: Element | null
    badge: Element | null
  } {
    const row = container.querySelector(`[data-id="hilos-table-row-${id}"]`)

    return {
      room: row?.querySelector('.hilos-stack.d-inline-grid') ?? null,
      twin: row?.querySelector('.badge.invisible') ?? null,
      badge: row?.querySelector('.badge:not(.invisible)') ?? null,
    }
  }

  const TONES = ['text-bg-success', 'text-bg-warning', 'text-bg-secondary']

  /**
   * The one tone a badge wears.
   *
   * @param badge The badge.
   */
  function tone(badge: Element | null): string[] {
    return Array.from(badge?.classList ?? []).filter((name) =>
      TONES.includes(name),
    )
  }

  it('says every presence in words and a doubt in the warning tone, over the widest word', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage
          context={seededContext([
            {
              id: 1,
              name: 'Alice',
              lastActivity: 1792576800000,
              presence: 'online',
              onlineSessionCount: 1,
            },
            {
              id: 2,
              name: 'Bob',
              lastActivity: 1792580400000,
              presence: 'probably_offline',
              onlineSessionCount: 2,
            },
            {
              id: 3,
              name: 'Carol',
              lastActivity: null,
              presence: 'offline',
              onlineSessionCount: 0,
            },
            {
              id: 4,
              name: 'Dan',
              lastActivity: null,
              presence: 'away',
              onlineSessionCount: 0,
            },
          ])}
        />
      </HilosRouterContext.Provider>,
    )

    const expected: Record<number, [string, string]> = {
      1: ['online', 'text-bg-success'],
      2: ['probably offline', 'text-bg-warning'],
      3: ['offline', 'text-bg-secondary'],
      4: ['offline', 'text-bg-secondary'],
    }
    for (const [id, [word, color]] of Object.entries(expected)) {
      const { room, twin, badge } = presence(container, Number(id))
      expect(badge?.textContent).toBe(word)
      expect(tone(badge)).toEqual([color])
      expect(twin?.textContent).toBe('probably offline')
      expect(twin?.getAttribute('aria-hidden')).toBe('true')
      expect(tone(twin)).toEqual([])
      expect(room?.querySelectorAll('.badge')).toHaveLength(2)
    }
    expect(container.textContent).not.toContain('probably_offline')
    // The doubted connections are still registered, so the count keeps them.
    expect(
      container.querySelectorAll('[data-id="hilos-table-row-2"] td')[3]
        ?.textContent,
    ).toBe('2')
    expect(container.textContent).toContain('Oct 21, 2026, 11:00:00 AM')
  })

  it('changes the word in place as the presence changes live, apart from a source gone quiet', async () => {
    const alice = {
      id: 1,
      name: 'Alice',
      lastActivity: 1792576800000,
      presence: 'online',
      onlineSessionCount: 1,
    }
    const live = liveUsers([alice])
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage context={live.context} />
      </HilosRouterContext.Provider>,
    )
    const change = async (fn: () => void): Promise<void> => {
      await act(async () => {
        fn()
      })
    }
    const stale = (): boolean =>
      container.querySelector('[data-id="hilos-table-stale-row-1"]') !== null
    const sessions = (): string | null | undefined =>
      container.querySelectorAll('[data-id="hilos-table-row-1"] td')[3]
        ?.textContent

    expect(presence(container, 1).badge?.textContent).toBe('online')

    // Every connection stopped answering: in doubt, still counted, nothing quiet.
    await change(() => live.update({ ...alice, presence: 'probably_offline' }))
    expect(presence(container, 1).badge?.textContent).toBe('probably offline')
    expect(tone(presence(container, 1).badge)).toEqual(['text-bg-warning'])
    expect(presence(container, 1).twin?.textContent).toBe('probably offline')
    expect(stale()).toBe(false)
    expect(sessions()).toBe('1')

    // The source going quiet marks the row and leaves the word alone.
    await change(() => live.stale(1, ['connections']))
    expect(stale()).toBe(true)
    expect(presence(container, 1).badge?.textContent).toBe('probably offline')

    await change(() => live.stale(1, []))
    expect(stale()).toBe(false)
    expect(presence(container, 1).badge?.textContent).toBe('probably offline')

    // A connection answered again, then the last one closed.
    await change(() =>
      live.update({ ...alice, presence: 'online', onlineSessionCount: 2 }),
    )
    expect(presence(container, 1).badge?.textContent).toBe('online')
    expect(tone(presence(container, 1).badge)).toEqual(['text-bg-success'])
    expect(sessions()).toBe('2')

    await change(() =>
      live.update({ ...alice, presence: 'offline', onlineSessionCount: 0 }),
    )
    expect(presence(container, 1).badge?.textContent).toBe('offline')
    expect(tone(presence(container, 1).badge)).toEqual(['text-bg-secondary'])
    expect(presence(container, 1).twin?.textContent).toBe('probably offline')
    expect(container.textContent).toContain('Oct 21, 2026, 10:00:00 AM')
  })
})
