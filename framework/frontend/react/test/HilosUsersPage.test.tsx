// HilosUsersPage (React) — the framework people list over a seeded, server-windowed
// table: a row per person, the gray "Merged" badge beside the name of an account
// folded into another one (HIL-1292), and the presence badge in its three words
// (HIL-1566), live and apart from the mark of a source gone quiet.
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  type TableViewportController,
  ActionLifecycle,
  formatMoment,
  HIDDEN_VALUE,
  HilosPages,
  hilosToasts,
  resolveHilosPath,
  ScopeManager,
  createSignal,
  entityCollection,
  USER_ENTITY_TYPE,
  userFromFields,
} from '@hilos/core/base'
import type {
  ActionLifecycleSource,
  Hideable,
  HilosRouter,
  PageRouteMatch,
  TableViewportDescriptor,
} from '@hilos/core/base'
import {
  HILOS_USERS_MERGE_PROGRESS_KEY,
  HILOS_USERS_ERASURE_PROGRESS_KEY,
  type HilosUsersContext,
} from '@hilos/core/admin/users'

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
  openSessionCount?: number | null
  block?: boolean
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
        openSessionCount:
          user.openSessionCount === undefined
            ? user.onlineSessionCount
            : user.openSessionCount,
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
  /**
   * A row bar moved, came up or came down (`table_progress`).
   *
   * @param bar The bar's body, without the table's address.
   */
  progress(bar: Record<string, unknown>): void
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
 * @param initialProgress The bars delivered beside the first window.
 */
function liveUsers(
  users: UserSeed[],
  initialProgress: readonly Record<string, unknown>[] = [],
): LiveUsers {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_users')
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const progressListeners = new Set<(signal: { data: unknown }) => void>()
  let viewportRequestCount = 0
  const progress = (data: Record<string, unknown>): void => {
    for (const listener of progressListeners) {
      listener({
        data: { page: 'hilos_users', tableKey: 'hilosUsers', ...data },
      })
    }
  }
  const delta = (data: Record<string, unknown>): void => {
    for (const listener of deltaListeners) {
      listener({
        data: { page: 'hilos_users', tableKey: 'hilosUsers', ...data },
      })
    }
  }
  // The window this table would be served, by whichever road it arrives on: the page's
  // own answer at bind time, or a reply to a window the reader changed.
  const serveWindow = (
    page = 'hilos_users',
    tableKey = 'hilosUsers',
    requestId?: string,
  ): void => {
    const data = {
      page,
      tableKey,
      ...(requestId === undefined ? {} : { requestId }),
      rows: users.map(wireRow),
      totalCount: users.length,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
    if (initialProgress.length > 0) {
      queueMicrotask(() => {
        for (const bar of initialProgress) progress(bar)
      })
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
    registerTableWindow(
      _key: string,
      controller: TableViewportController<unknown>,
    ): void {
      controller.ingestSubscriptionWindow(
        [],
        0,
        true,
        null,
        null,
        10,
        [{ field: 'id', direction: 'asc' }],
        {},
        [],
      )
      serveWindow()
    },
    sendTableRendered: () => true,
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(
      page: string,
      tableKey: string,
      descriptor: TableViewportDescriptor,
    ): boolean {
      viewportRequestCount++
      // A reply completes this request; the unsolicited first window has no id.
      serveWindow(page, tableKey, descriptor.requestId)

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
            : event === 'tableProgress'
              ? progressListeners
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
    progress,
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
      openSessionCount: 4,
    },
    {
      id: 2,
      name: 'Bob',
      lastActivity: null,
      presence: 'offline',
      onlineSessionCount: 0,
      openSessionCount: 0,
    },
  ])
}

describe('HilosUsersPage', () => {
  afterEach(() => {
    cleanup()
    for (const release of readerTimeZoneReleases.splice(0)) release()
    vi.useRealTimers()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
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
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
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

    expect(viewportRequests).toBe(1)
    expect(row).not.toBeNull()

    await act(async () => {
      zone.choose('Pacific/Kiritimati')
      // Cross the delayed skeleton boundary too: the search reply must have
      // completed its request, however long the reader-zone update takes.
      await vi.runOnlyPendingTimersAsync()
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
    expect(cells[1]?.textContent).toBe('AliceShow in full')
    expect(cells[1]?.classList.contains('fw-medium')).toBe(true)
    expect(cells[3]?.textContent).toBe('4Show in full')
    expect(cells[3]?.classList.contains('text-end')).toBe(true)
  })

  function layout() {
    const frames = new Map<number, FrameRequestCallback>()
    let serial = 0
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
      frames.set(++serial, callback)
      return serial
    })
    vi.stubGlobal('cancelAnimationFrame', (id: number) => frames.delete(id))
    vi.stubGlobal(
      'ResizeObserver',
      class {
        readonly nodes = new Set<Element>()
        constructor(readonly callback: () => void) {}
        observe(node: Element) {
          this.nodes.add(node)
        }
        unobserve(node: Element) {
          this.nodes.delete(node)
        }
        disconnect() {
          this.nodes.clear()
        }
      },
    )
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
      function (this: HTMLElement) {
        return new DOMRect(0, 0, 200, 40)
      },
    )
    for (const property of [
      'clientWidth',
      'clientHeight',
      'scrollWidth',
      'scrollHeight',
    ] as const) {
      vi.spyOn(HTMLElement.prototype, property, 'get').mockImplementation(
        function (this: HTMLElement) {
          if (property === 'clientHeight') return 40
          if (property === 'scrollHeight') return 120
          return 200
        },
      )
    }
    return {
      async flush() {
        await act(async () => {
          const pending = [...frames.values()]
          frames.clear()
          for (const callback of pending) callback(0)
        })
      },
    }
  }

  it('shows reading controls on overflowing fields and keeps badges outside the text window', async () => {
    const dom = layout()
    const context = seededContext([
      {
        id: 1,
        name: 'Maria-Luisa Alexandra Konstantinopolskaya-Vishnevetskaya von Hohenzollern-Sigmaringen',
        lastActivity: 1781863200000,
        presence: 'offline',
        onlineSessionCount: 12,
        openSessionCount: 4,
        merged: true,
      },
      {
        id: 2,
        name: HIDDEN_VALUE,
        lastActivity: null,
        presence: 'offline',
        onlineSessionCount: 0,
        openSessionCount: null,
      },
    ])

    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage context={context} />
      </HilosRouterContext.Provider>,
    )
    await dom.flush()

    // 1. Name overflow on row 1 (merged):
    const row1 = container.querySelector('[data-id="hilos-table-row-1"]')!
    expect(row1).not.toBeNull()
    const nameToggle1 = row1.querySelector<HTMLButtonElement>(
      'button[aria-label="Show in full: Name"]',
    )
    expect(nameToggle1).not.toBeNull()
    expect(nameToggle1?.getAttribute('data-id')).toBe('hilos-table-text-toggle')
    const ariaControls1 = nameToggle1?.getAttribute('aria-controls')
    expect(ariaControls1).toBeTruthy()
    const textRegion1 = document.getElementById(ariaControls1!)
    expect(textRegion1).not.toBeNull()
    expect(textRegion1!.textContent).toContain('Maria-Luisa')
    expect(textRegion1!.textContent).not.toContain('Merged')
    expect(row1.querySelector('[data-id="hilos-users-merged"]')).not.toBeNull()

    // 2. Sessions and Last activity have their own toggle buttons with their column label:
    const sessionsToggle = row1.querySelector<HTMLButtonElement>(
      'button[aria-label="Show in full: Sessions"]',
    )
    expect(sessionsToggle).not.toBeNull()
    expect(sessionsToggle?.getAttribute('data-id')).toBe(
      'hilos-table-text-toggle',
    )
    expect(row1.querySelectorAll('td')[3]?.textContent).toContain('4')

    const activityToggle = row1.querySelector<HTMLButtonElement>(
      'button[aria-label="Show in full: Last activity"]',
    )
    expect(activityToggle).not.toBeNull()
    expect(activityToggle?.getAttribute('data-id')).toBe(
      'hilos-table-text-toggle',
    )

    // 3. Hidden name: renders mark without reading toggle button:
    const row2 = container.querySelector('[data-id="hilos-table-row-2"]')!
    expect(row2.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(row2.querySelectorAll('td')[3]?.textContent).toContain('—')
    expect(
      row2.querySelector('button[aria-label="Show in full: Name"]'),
    ).toBeNull()
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
    ).toBe('2Show in full')
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
    expect(sessions()).toBe('1Show in full')

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
    expect(sessions()).toBe('2Show in full')

    await change(() =>
      live.update({ ...alice, presence: 'offline', onlineSessionCount: 0 }),
    )
    expect(presence(container, 1).badge?.textContent).toBe('offline')
    expect(tone(presence(container, 1).badge)).toEqual(['text-bg-secondary'])
    expect(presence(container, 1).twin?.textContent).toBe('probably offline')
    expect(container.textContent).toContain('Oct 21, 2026, 10:00:00 AM')
  })
})

describe('HilosUsersPage erasure (HIL-1961)', () => {
  const startedAt = 1_800_000_000_000
  const alice: UserSeed = {
    id: 1,
    name: 'Alice',
    lastActivity: null,
    presence: 'offline',
    onlineSessionCount: 0,
    block: true,
  }
  const bob: UserSeed = { ...alice, id: 2, name: 'Bob', block: false }
  const detail = {
    startedAt,
    parts: [
      { participant: 'profile', owner: 'user', userId: 1, done: true },
      { participant: 'chat', owner: 'room', userId: 1, done: false },
      { participant: 'chat', owner: 'room', userId: 2, done: false },
    ],
    waits: [{ participant: 'delivery:email', userId: 2 }],
    refusals: [{ userId: 2, table: null }],
  }

  /**
   * The erasure bar of one row as the server sends it.
   *
   * @param rowKey The account the row shows.
   * @param fields What differs from a running bar three of five parts along.
   */
  function erasureBar(
    rowKey: string,
    fields: Record<string, unknown> = {},
  ): Record<string, unknown> {
    return {
      scope: 'row',
      progressKey: 'hilos-account-erasure',
      rowKey,
      current: 3,
      total: 5,
      detail,
      ...fields,
    }
  }

  /**
   * Mount the list over Alice and Bob.
   *
   * @param seeds The people of the first window.
   */
  async function mountCircle(seeds: UserSeed[] = [alice, bob]) {
    const live = liveUsers(seeds)
    live.context.scopes.session.data.set('currentUser', {
      type: USER_ENTITY_TYPE,
      id: 1,
    })
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosUsersPage context={live.context} />
      </HilosRouterContext.Provider>,
    )
    const progressRow = (id: number): Element | null =>
      container.querySelector(`[data-id="hilos-table-progress-row-${id}"]`)
    const caption = (id: number, line: string): Element | null =>
      progressRow(id)?.querySelector(
        `[data-id="hilos-users-erasure-${line}"]`,
      ) ?? null

    return { live, container, progressRow, caption }
  }

  it('wears Being deleted and captions the bar when it arrives live', async () => {
    const { live, container, progressRow, caption } = await mountCircle()
    const name1 = (): Element | null =>
      container.querySelector('[data-id="hilos-table-row-1"] td:nth-child(2)')

    expect(name1()?.querySelector('[data-id="hilos-users-erasing"]')).toBeNull()
    expect(progressRow(1)).toBeNull()

    await act(async () => {
      live.progress(erasureBar('1'))
    })

    const erasing = name1()?.querySelector('[data-id="hilos-users-erasing"]')
    expect(erasing).not.toBeNull()
    expect(erasing?.textContent).toBe('Being deleted')
    expect(erasing?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(caption(1, 'summary')?.textContent).toBe(
      `Erasure in progress · 3 of 5 parts done · started ${String(formatMoment(startedAt, { kind: 'dateTime' }))}`,
    )
    expect(caption(1, 'expected')?.textContent).toBe(
      'Expected: chat; chat · #2',
    )
    expect(caption(1, 'expected')?.querySelectorAll('code')).toHaveLength(2)
    expect(caption(1, 'waits')?.textContent).toBe(
      'Waiting for an owner: delivery:email · #2',
    )
    expect(caption(1, 'refusals')?.textContent).toBe(
      'Refused: #2 · Another table still references the account',
    )
    expect(
      progressRow(1)
        ?.querySelector('[role="progressbar"]')
        ?.getAttribute('aria-valuenow'),
    ).toBe('60')
    expect(
      container
        .querySelector('[data-id="hilos-table-row-2"] td:nth-child(2)')
        ?.querySelector('[data-id="hilos-users-erasing"]'),
    ).toBeNull()

    const row1 = container.querySelector('[data-id="hilos-table-row-1"]')
    await act(async () => {
      live.progress(erasureBar('1', { current: 4 }))
    })

    expect(caption(1, 'summary')?.textContent).toContain('4 of 5 parts done')
    expect(caption(1, 'expected')?.textContent).toBe(
      'Expected: chat; chat · #2',
    )
    expect(caption(1, 'waits')?.textContent).toBe(
      'Waiting for an owner: delivery:email · #2',
    )
    expect(caption(1, 'refusals')?.textContent).toBe(
      'Refused: #2 · Another table still references the account',
    )
    expect(container.querySelector('[data-id="hilos-table-row-1"]')).toBe(row1)
  })

  it('keeps Merged on a merged account of the circle and still captions its bar', async () => {
    const { live, container, caption } = await mountCircle([
      alice,
      { ...bob, merged: true },
    ])

    await act(async () => {
      live.progress(erasureBar('2'))
    })

    const name2 = container.querySelector(
      '[data-id="hilos-table-row-2"] td:nth-child(2)',
    )
    expect(
      name2?.querySelector('[data-id="hilos-users-merged"]')?.textContent,
    ).toBe('Merged')
    expect(name2?.querySelector('[data-id="hilos-users-erasing"]')).toBeNull()
    expect(caption(2, 'summary')?.textContent).toContain('3 of 5 parts done')
    expect(caption(2, 'expected')?.textContent).toBe(
      'Expected: chat · #1; chat',
    )
  })

  it('takes the badge and the caption down when the erasure ends, and gives a bar about other work neither', async () => {
    const { live, container, progressRow, caption } = await mountCircle()

    await act(async () => {
      live.progress(erasureBar('1'))
    })

    const name1 = (): Element | null =>
      container.querySelector('[data-id="hilos-table-row-1"] td:nth-child(2)')

    expect(
      name1()?.querySelector('[data-id="hilos-users-erasing"]'),
    ).not.toBeNull()
    expect(progressRow(1)).not.toBeNull()

    await act(async () => {
      live.progress(erasureBar('1', { current: 5, ended: true }))
    })

    expect(name1()?.querySelector('[data-id="hilos-users-erasing"]')).toBeNull()
    expect(progressRow(1)).toBeNull()

    await act(async () => {
      live.progress(erasureBar('1', { progressKey: 'other-work' }))
    })

    expect(progressRow(1)?.querySelector('[role="progressbar"]')).not.toBeNull()
    expect(caption(1, 'summary')).toBeNull()
    expect(name1()?.querySelector('[data-id="hilos-users-erasing"]')).toBeNull()
  })

  it('says only that the erasure is in progress when its block cannot be read', async () => {
    const { live, container, caption } = await mountCircle()

    await act(async () => {
      live.progress(erasureBar('1', { detail: {} }))
    })

    expect(caption(1, 'summary')?.textContent).toBe('Erasure in progress')
    expect(caption(1, 'expected')).toBeNull()
    expect(caption(1, 'waits')).toBeNull()
    expect(caption(1, 'refusals')).toBeNull()
    expect(
      container
        .querySelector('[data-id="hilos-table-row-1"] td:nth-child(2)')
        ?.querySelector('[data-id="hilos-users-erasing"]')?.textContent,
    ).toBe('Being deleted')
  })
})

describe('HilosUsersPage merge (HIL-2010)', () => {
  const alice: UserSeed = {
    id: 1,
    name: 'Alice',
    lastActivity: null,
    presence: 'offline',
    onlineSessionCount: 0,
  }
  const bob: UserSeed = { ...alice, id: 2, name: 'Bob' }
  const detail = {
    operationId: 10,
    survivorUserId: 1,
    loserUserId: 2,
    state: 'preparing',
    stage: 'preparing',
    outcome: null,
    given: true,
    received: false,
    choice: null,
    refusal: null,
    parts: [
      { participant: 'profile', owner: 'user', userId: 1, done: true },
      { participant: 'chat', owner: 'room', userId: 1, done: false },
      { participant: 'files', owner: 'user', userId: 2, done: false },
      { participant: 'auth', owner: 'credentials', userId: 2, done: false },
    ],
    waits: [
      { participant: 'sessions', userId: 2 },
      { participant: 'delivery', userId: null },
    ],
  }

  /**
   * The row's merge bar, with only the varying fields supplied by each case.
   *
   * @param rowKey The account the row shows.
   * @param fields Fields that differ from a preparing merge.
   */
  function mergeBar(rowKey: string, fields: Record<string, unknown> = {}) {
    return {
      scope: 'row',
      progressKey: HILOS_USERS_MERGE_PROGRESS_KEY,
      rowKey,
      current: 1,
      total: 3,
      detail,
      ...fields,
    }
  }

  /**
   * Mount the real list and core table, including its project action cell.
   *
   * @param initialProgress The bars sent beside the first window.
   * @param seeds The people shown in the window.
   */
  async function mountMerge(
    initialProgress: Record<string, unknown>[] = [],
    seeds: UserSeed[] = [alice, bob],
  ) {
    const live = liveUsers(seeds, initialProgress)
    let view!: ReturnType<typeof render>
    await act(async () => {
      view = render(
        <HilosRouterContext.Provider value={router()}>
          <HilosUsersPage
            context={live.context}
            rowActions={(row) => (
              <a
                href={resolveHilosPath(HilosPages.USER, {
                  userId: String(row.id),
                })}
              >
                Open
              </a>
            )}
          />
        </HilosRouterContext.Provider>,
      )
    })
    const progressRow = (id: number) =>
      view.container.querySelector(`[data-id="hilos-table-progress-row-${id}"]`)
    const caption = (id: number, line: string) =>
      progressRow(id)?.querySelector(`[data-id="hilos-users-merge-${line}"]`) ??
      null
    const change = async (
      fields: Record<string, unknown> = {},
      rowKey = '1',
    ) => {
      await act(async () => live.progress(mergeBar(rowKey, fields)))
    }

    return { ...view, live, progressRow, caption, change }
  }

  afterEach(() => hilosToasts.clear())

  it('reads the merge bar delivered with the first window', async () => {
    const { caption } = await mountMerge([mergeBar('1')])
    expect(caption(1, 'summary')?.textContent).toBe(
      'Preparing merge · 1 of 3 parts done',
    )
    expect(caption(1, 'expected')?.textContent).toBe(
      'Expected: chat; files · #2; auth · #2',
    )
    expect(caption(1, 'waits')?.textContent).toBe(
      'Waiting for an owner: Close source sessions · #2; delivery',
    )
    expect(caption(1, 'refusal')).toBeNull()
  })

  it('follows preparing, waiting, refusal and cancellation live', async () => {
    const { caption, change, progressRow } = await mountMerge()
    expect(progressRow(1)).toBeNull()
    await change()
    expect(caption(1, 'summary')?.textContent).toBe(
      'Preparing merge · 1 of 3 parts done',
    )
    await change({
      current: 2,
      detail: { ...detail, state: 'wait', stage: 'go' },
    })
    expect(caption(1, 'summary')?.textContent).toBe(
      'Merge in progress · 2 of 3 parts done',
    )
    await change({
      detail: {
        ...detail,
        state: 'refusal',
        refusal: {
          code: 'occupied',
          reason: 'Account has pending transactions',
          permanent: false,
        },
      },
    })
    expect(caption(1, 'summary')?.textContent).toBe(
      'Merge refused · 1 of 3 parts done',
    )
    expect(caption(1, 'refusal')?.textContent).toBe(
      'Account has pending transactions',
    )
    await change({ detail: { ...detail, state: 'wait', stage: 'cancelling' } })
    expect(caption(1, 'summary')?.textContent).toBe(
      'Cancelling merge preparation · 1 of 3 parts done',
    )
    expect(caption(1, 'refusal')).toBeNull()
  })

  it('keeps Merged beside a full bar whose operation still waits', async () => {
    const { container, caption, progressRow, change } = await mountMerge(
      [],
      [alice, { ...bob, merged: true }],
    )
    await change(
      { current: 3, detail: { ...detail, state: 'wait', stage: 'go' } },
      '2',
    )
    expect(
      container.querySelector('[data-id="hilos-users-merged"]')?.textContent,
    ).toBe('Merged')
    expect(caption(2, 'summary')?.textContent).toBe(
      'Merge in progress · 3 of 3 parts done',
    )
    expect(
      progressRow(2)
        ?.querySelector('[role="progressbar"]')
        ?.getAttribute('aria-valuenow'),
    ).toBe('100')
    expect(caption(2, 'waits')?.textContent).toBe(
      'Waiting for an owner: Close source sessions; delivery',
    )
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('caps both lists, preserves the exact refusal as text and keeps Open', async () => {
    const { caption, change, container } = await mountMerge()
    const reason =
      '<img src=x onerror=alert(1)> Another merge already active on candidate'
    await change({
      detail: {
        ...detail,
        parts: [
          ...detail.parts,
          { participant: 'extra1', owner: 'sys', userId: 2, done: false },
          { participant: 'extra2', owner: 'sys', userId: null, done: false },
        ],
        waits: [
          ...detail.waits,
          { participant: 'backup', userId: 2 },
          { participant: 'audit', userId: null },
        ],
        refusal: { code: 'occupied', reason, permanent: true },
      },
    })
    expect(caption(1, 'expected')?.textContent).toBe(
      'Expected: chat; files · #2; auth · #2; +2 more',
    )
    expect(caption(1, 'waits')?.textContent).toBe(
      'Waiting for an owner: Close source sessions · #2; delivery; backup · #2; +1 more',
    )
    expect(caption(1, 'refusal')?.textContent).toBe(reason)
    expect(caption(1, 'refusal')?.querySelector('img')).toBeNull()
    const open = container.querySelector('[data-id="hilos-table-row-1"] a')
    expect(open?.textContent).toBe('Open')
    expect(open?.getAttribute('href')).toBe(
      resolveHilosPath(HilosPages.USER, { userId: '1' }),
    )
  })

  it('handles hidden or broken detail, erasure and other work, then clears without sending or toasting', async () => {
    const { caption, change, progressRow, live, unmount } = await mountMerge()
    const send = vi.spyOn(live.context.connection, 'sendAction')
    for (const unreadable of [{}, HIDDEN_VALUE, null]) {
      await change({ detail: unreadable })
      expect(caption(1, 'summary')?.textContent).toBe('Merge in progress')
      expect(caption(1, 'expected')).toBeNull()
      expect(caption(1, 'waits')).toBeNull()
      expect(caption(1, 'refusal')).toBeNull()
    }
    await change({
      detail: {
        ...detail,
        stage: HIDDEN_VALUE,
        refusal: HIDDEN_VALUE,
        parts: [
          {
            participant: 'private_module',
            owner: 'user',
            userId: HIDDEN_VALUE,
            done: false,
          },
        ],
        waits: [{ participant: 'private_worker', userId: HIDDEN_VALUE }],
      },
    })
    expect(caption(1, 'expected')?.textContent).toBe('Expected: private_module')
    expect(caption(1, 'waits')?.textContent).toBe(
      'Waiting for an owner: private_worker',
    )
    expect(caption(1, 'refusal')).toBeNull()
    await change({
      progressKey: HILOS_USERS_ERASURE_PROGRESS_KEY,
      detail: {
        startedAt: 1_800_000_000_000,
        parts: [],
        waits: [],
        refusals: [],
      },
    })
    expect(
      progressRow(1)?.querySelector('[data-id="hilos-users-erasure-summary"]'),
    ).not.toBeNull()
    expect(caption(1, 'summary')).toBeNull()
    await change({ progressKey: 'other-work' })
    expect(progressRow(1)?.querySelector('[role="progressbar"]')).not.toBeNull()
    expect(caption(1, 'summary')).toBeNull()
    expect(
      progressRow(1)?.querySelector('[data-id="hilos-users-erasure-summary"]'),
    ).toBeNull()
    await change()
    await change({ ended: true })
    expect(progressRow(1)).toBeNull()
    expect(caption(1, 'summary')).toBeNull()
    await change()
    unmount()
    expect(send).not.toHaveBeenCalled()
    expect(hilosToasts.toasts.get()).toEqual([])
  })
})
