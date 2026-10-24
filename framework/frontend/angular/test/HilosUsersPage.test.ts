// HilosUsersPage (Angular) — the framework people list over a seeded,
// server-windowed table: a row per person, and the gray "Merged" badge beside
// the name of an account folded into another one (HIL-1292), read off the row's
// inline merge slot, and the presence badge in its three words (HIL-1566), live
// and apart from the mark of a source gone quiet.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
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
import { afterEach, describe, expect, it } from 'vitest'

import { HilosUsersPage } from '../src/admin/users/HilosUsersPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
  const delta = (data: Record<string, unknown>): void => {
    for (const listener of deltaListeners) {
      listener({
        data: { page: 'hilos_users', tableKey: 'hilosUsers', ...data },
      })
    }
  }
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
    sendAction(): boolean {
      return true
    },
    // The first window arrives with the page's own answer (HIL-642), and binding
    // is when that answer would have landed.
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
    update(user: UserSeed): void {
      delta({ kind: 'row_updated', rowKey: user.id, row: wireRow(user) })
    },
    stale(id: number, staleSources: string[]): void {
      delta({ kind: 'row_stale', rowKey: id, staleSources })
    },
  }
}

/**
 * Mount the list over a seeded context and let the first window land.
 *
 * @param context The seeded project context.
 */
async function mountList(
  context: HilosUsersContext,
): Promise<ComponentFixture<HilosUsersPage>> {
  TestBed.resetTestingModule()
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosUsersPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  await fixture.whenStable()
  fixture.detectChanges()

  return fixture
}

function all(fixture: ComponentFixture<unknown>, id: string): HTMLElement[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
      `[data-id="${id}"]`,
    ),
  )
}

describe('HilosUsersPage', () => {
  afterEach(() => {
    TestBed.resetTestingModule()
  })

  it('renders a row per user with the name and the presence', async () => {
    const fixture = await mountList(
      seededContext([
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
      ]),
    )
    const text = (fixture.nativeElement as HTMLElement).textContent ?? ''

    expect(text).toContain('Alice')
    expect(text).toContain('Bob')
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.text-bg-success')
        ?.textContent,
    ).toBe('online')
    expect(all(fixture, 'hilos-users-merged')).toHaveLength(0)
  })

  it('marks a merged account beside its name and nobody else (HIL-1292)', async () => {
    const fixture = await mountList(
      seededContext([
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
      ]),
    )

    // The table draws every row twice - the row and the card of the narrow
    // layout - so the badge is counted by whose row it sits in, not by number.
    const badges = all(fixture, 'hilos-users-merged')
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
    const host = fixture.nativeElement as HTMLElement
    expect(
      host
        .querySelector('[data-id="hilos-table-row-1"]')
        ?.querySelector('[data-id="hilos-users-merged"]'),
    ).not.toBeNull()
    expect(
      host
        .querySelector('[data-id="hilos-table-row-2"]')
        ?.querySelector('[data-id="hilos-users-merged"]'),
    ).toBeNull()
  })
})

describe('HilosUsersPage presence (HIL-1566)', () => {
  afterEach(() => {
    TestBed.resetTestingModule()
  })

  /**
   * The presence cell of a person's row: the room the twin holds, the twin, and
   * the badge drawn over it.
   *
   * @param fixture The mounted list.
   * @param id The person's id.
   */
  function presence(
    fixture: ComponentFixture<unknown>,
    id: number,
  ): {
    room: Element | null
    twin: Element | null
    badge: Element | null
  } {
    const row = (fixture.nativeElement as HTMLElement).querySelector(
      `[data-id="hilos-table-row-${id}"]`,
    )

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

  it('says every presence in words and a doubt in the warning tone, over the widest word', async () => {
    const fixture = await mountList(
      seededContext([
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
      ]),
    )

    const expected: Record<number, [string, string]> = {
      1: ['online', 'text-bg-success'],
      2: ['probably offline', 'text-bg-warning'],
      3: ['offline', 'text-bg-secondary'],
      4: ['offline', 'text-bg-secondary'],
    }
    for (const [id, [word, color]] of Object.entries(expected)) {
      const { room, twin, badge } = presence(fixture, Number(id))
      expect(badge?.textContent?.trim()).toBe(word)
      expect(tone(badge)).toEqual([color])
      expect(twin?.textContent?.trim()).toBe('probably offline')
      expect(twin?.getAttribute('aria-hidden')).toBe('true')
      expect(tone(twin)).toEqual([])
      expect(room?.querySelectorAll('.badge')).toHaveLength(2)
    }
    const text = (fixture.nativeElement as HTMLElement).textContent ?? ''
    expect(text).not.toContain('probably_offline')
    // The doubted connections are still registered, so the count keeps them.
    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-table-row-2"] td',
      )[3]?.textContent,
    ).toBe('2')
    expect(text).toContain('Oct 21, 2026, 11:00:00 AM')
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
    const fixture = await mountList(live.context)
    const host = fixture.nativeElement as HTMLElement
    const change = async (fn: () => void): Promise<void> => {
      fn()
      await Promise.resolve()
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
    }
    const stale = (): boolean =>
      host.querySelector('[data-id="hilos-table-stale-row-1"]') !== null
    const sessions = (): string | null | undefined =>
      host.querySelectorAll('[data-id="hilos-table-row-1"] td')[3]?.textContent

    expect(presence(fixture, 1).badge?.textContent?.trim()).toBe('online')

    // Every connection stopped answering: in doubt, still counted, nothing quiet.
    await change(() => live.update({ ...alice, presence: 'probably_offline' }))
    expect(presence(fixture, 1).badge?.textContent?.trim()).toBe(
      'probably offline',
    )
    expect(tone(presence(fixture, 1).badge)).toEqual(['text-bg-warning'])
    expect(presence(fixture, 1).twin?.textContent?.trim()).toBe(
      'probably offline',
    )
    expect(stale()).toBe(false)
    expect(sessions()).toBe('1')

    // The source going quiet marks the row and leaves the word alone.
    await change(() => live.stale(1, ['connections']))
    expect(stale()).toBe(true)
    expect(presence(fixture, 1).badge?.textContent?.trim()).toBe(
      'probably offline',
    )

    await change(() => live.stale(1, []))
    expect(stale()).toBe(false)
    expect(presence(fixture, 1).badge?.textContent?.trim()).toBe(
      'probably offline',
    )

    // A connection answered again, then the last one closed.
    await change(() =>
      live.update({ ...alice, presence: 'online', onlineSessionCount: 2 }),
    )
    expect(presence(fixture, 1).badge?.textContent?.trim()).toBe('online')
    expect(tone(presence(fixture, 1).badge)).toEqual(['text-bg-success'])
    expect(sessions()).toBe('2')

    await change(() =>
      live.update({ ...alice, presence: 'offline', onlineSessionCount: 0 }),
    )
    expect(presence(fixture, 1).badge?.textContent?.trim()).toBe('offline')
    expect(tone(presence(fixture, 1).badge)).toEqual(['text-bg-secondary'])
    expect(presence(fixture, 1).twin?.textContent?.trim()).toBe(
      'probably offline',
    )
    expect(host.textContent).toContain('Oct 21, 2026, 10:00:00 AM')
  })
})
