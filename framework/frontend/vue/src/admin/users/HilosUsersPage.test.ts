// HilosUsersPage (Vue) — the framework people list over a seeded, server-windowed
// table: a row per person, the gray "Merged" badge beside the name of an
// account folded into another one (HIL-1292), read off the row's inline merge slot,
// and the presence badge in its three words (HIL-1566), live and apart from the
// mark of a source gone quiet.
import { type DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
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
} from '@hilos/core/base'
import type { HilosUsersContext } from '@hilos/core/admin/users'

import HilosUsersPage from './HilosUsersPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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
  lastActivity: string | null
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

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
})

/**
 * Mount the list over a seeded context and let the first window land.
 *
 * @param context The seeded project context.
 */
async function mountList(
  context: HilosUsersContext,
): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(HilosUsersPage, {
    props: { context: markRaw(context) },
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

describe('HilosUsersPage', () => {
  it('renders a row per user with the name and the presence', async () => {
    const wrapper = await mountList(
      seededContext([
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
      ]),
    )

    expect(wrapper.text()).toContain('Alice')
    expect(wrapper.text()).toContain('Bob')
    expect(wrapper.find('.text-bg-success').text()).toBe('online')
    expect(wrapper.findAll('[data-id="hilos-users-merged"]')).toHaveLength(0)
  })

  it('marks a merged account beside its name and nobody else (HIL-1292)', async () => {
    const wrapper = await mountList(
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
    const badges = wrapper.findAll('[data-id="hilos-users-merged"]')
    expect(badges.length).toBeGreaterThan(0)
    for (const badge of badges) {
      expect(badge.text()).toBe('Merged')
      expect(badge.classes()).toContain('text-bg-secondary')
      expect(badge.find('.bi-sign-merge-left').exists()).toBe(true)
      expect(
        badge.element
          .closest(
            '[data-id^="hilos-table-row-"], [data-id^="hilos-table-card-"]',
          )
          ?.getAttribute('data-id'),
      ).toMatch(/-1$/)
    }
    expect(
      wrapper
        .find('[data-id="hilos-table-row-1"]')
        .find('[data-id="hilos-users-merged"]')
        .exists(),
    ).toBe(true)
    expect(
      wrapper
        .find('[data-id="hilos-table-row-2"]')
        .find('[data-id="hilos-users-merged"]')
        .exists(),
    ).toBe(false)
  })
})

describe('HilosUsersPage presence (HIL-1566)', () => {
  /**
   * The presence cell of a person's row: the room the twin holds, the twin, and
   * the badge drawn over it.
   *
   * @param wrapper The mounted list.
   * @param id The person's id.
   */
  function presence(
    wrapper: ReturnType<typeof mount>,
    id: number,
  ): {
    room: DOMWrapper<Element>
    twin: DOMWrapper<Element>
    badge: DOMWrapper<Element>
  } {
    const row = wrapper.find(`[data-id="hilos-table-row-${id}"]`)

    return {
      room: row.find('.hilos-stack.d-inline-grid'),
      twin: row.find('.badge.invisible'),
      badge: row.find('.badge:not(.invisible)'),
    }
  }

  const TONES = ['text-bg-success', 'text-bg-warning', 'text-bg-secondary']

  /**
   * The one tone a badge wears.
   *
   * @param badge The badge.
   */
  function tone(badge: DOMWrapper<Element>): string[] {
    return badge.classes().filter((name) => TONES.includes(name))
  }

  it('says every presence in words and a doubt in the warning tone, over the widest word', async () => {
    const wrapper = await mountList(
      seededContext([
        {
          id: 1,
          name: 'Alice',
          lastActivity: '2026-10-21 10:00',
          presence: 'online',
          onlineSessionCount: 1,
        },
        {
          id: 2,
          name: 'Bob',
          lastActivity: '2026-10-21 11:00',
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
      const { room, twin, badge } = presence(wrapper, Number(id))
      expect(badge.text()).toBe(word)
      expect(tone(badge)).toEqual([color])
      expect(twin.text()).toBe('probably offline')
      expect(twin.attributes('aria-hidden')).toBe('true')
      expect(tone(twin)).toEqual([])
      expect(room.findAll('.badge')).toHaveLength(2)
    }
    expect(wrapper.text()).not.toContain('probably_offline')
    // The doubted connections are still registered, so the count keeps them.
    expect(
      wrapper.find('[data-id="hilos-table-row-2"]').findAll('td')[3]?.text(),
    ).toBe('2')
    expect(wrapper.text()).toContain('2026-10-21 11:00')
  })

  it('changes the word in place as the presence changes live, apart from a source gone quiet', async () => {
    const alice = {
      id: 1,
      name: 'Alice',
      lastActivity: '2026-10-21 10:00',
      presence: 'online',
      onlineSessionCount: 1,
    }
    const live = liveUsers([alice])
    const wrapper = await mountList(live.context)
    const settle = async (): Promise<void> => {
      await flushPromises()
      await nextTick()
    }
    const stale = (): boolean =>
      wrapper.find('[data-id="hilos-table-stale-row-1"]').exists()

    expect(presence(wrapper, 1).badge.text()).toBe('online')

    // Every connection stopped answering: in doubt, still counted, nothing quiet.
    live.update({ ...alice, presence: 'probably_offline' })
    await settle()
    expect(presence(wrapper, 1).badge.text()).toBe('probably offline')
    expect(tone(presence(wrapper, 1).badge)).toEqual(['text-bg-warning'])
    expect(presence(wrapper, 1).twin.text()).toBe('probably offline')
    expect(stale()).toBe(false)
    expect(
      wrapper.find('[data-id="hilos-table-row-1"]').findAll('td')[3]?.text(),
    ).toBe('1')

    // The source going quiet marks the row and leaves the word alone.
    live.stale(1, ['connections'])
    await settle()
    expect(stale()).toBe(true)
    expect(presence(wrapper, 1).badge.text()).toBe('probably offline')

    live.stale(1, [])
    await settle()
    expect(stale()).toBe(false)
    expect(presence(wrapper, 1).badge.text()).toBe('probably offline')

    // A connection answered again, then the last one closed.
    live.update({ ...alice, presence: 'online', onlineSessionCount: 2 })
    await settle()
    expect(presence(wrapper, 1).badge.text()).toBe('online')
    expect(tone(presence(wrapper, 1).badge)).toEqual(['text-bg-success'])
    expect(
      wrapper.find('[data-id="hilos-table-row-1"]').findAll('td')[3]?.text(),
    ).toBe('2')

    live.update({ ...alice, presence: 'offline', onlineSessionCount: 0 })
    await settle()
    expect(presence(wrapper, 1).badge.text()).toBe('offline')
    expect(tone(presence(wrapper, 1).badge)).toEqual(['text-bg-secondary'])
    expect(presence(wrapper, 1).twin.text()).toBe('probably offline')
    expect(wrapper.text()).toContain('2026-10-21 10:00')
  })
})
