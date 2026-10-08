// HilosUsersPage (Vue) — the framework people list over a seeded, server-windowed
// table: a row per person, and the gray "Merged" badge beside the name of an
// account folded into another one (HIL-1292), read off the row's inline merge slot.
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
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

// A users context whose connection answers each viewport request with a window
// built from the seeded users — the server-windowed table's data path in one hop.
// The `users` slot carries the entity fragment the binder upserts under the user
// collection's type; the `connections` and `merge` slots stay inline (no id), as
// on the wire.
function seededContext(users: UserSeed[]): HilosUsersContext {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_users')
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
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
          merge: { merged: user.merged ?? false },
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
