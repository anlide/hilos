// HilosUsersPage (Vue) — the framework people list over a seeded, server-windowed
// table: the shared section identity, names and ids, Blocked and You flags with
// Merged taking precedence, and the three presence words (HIL-1566) changing live
// apart from the mark of a source gone quiet.
import { type DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { h, markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  formatMoment,
  HIDDEN_VALUE,
  HilosPages,
  ScopeManager,
  createSignal,
  entityCollection,
  USER_ENTITY_TYPE,
  userFromFields,
} from '@hilos/core/base'
import type {
  ActionLifecycleSource,
  Hideable,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'
import type { HilosUserRow, HilosUsersContext } from '@hilos/core/admin/users'

import HilosUsersPage from './HilosUsersPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { readerTimeZone } from '../../../test/helpers/readerTimeZone.js'

const USERS_IDENTITY: HilosPageIdentity = {
  label: 'Users',
  lead: 'Application users and panel operators: presence, roles, and access.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.USERS, label: 'Users' },
  ],
  children: [],
  pageHeadOwner: {
    page: HilosPages.USERS,
    label: 'Users',
    lead: 'Application users and panel operators: presence, roles, and access.',
    icon: 'bi-people',
  },
  pageTabs: [{ page: HilosPages.USERS, label: 'Overview' }],
}

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.USERS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/users'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(USERS_IDENTITY),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      page === HilosPages.DASHBOARD
        ? '/hilos'
        : page === HilosPages.USERS
          ? '/hilos/users'
          : undefined,
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
        block: user.block ?? false,
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
 */
function liveUsers(users: UserSeed[]): LiveUsers {
  const scopes = new ScopeManager()
  scopes.openPage('hilos_users')
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const progressListeners = new Set<(signal: { data: unknown }) => void>()
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
    update(user: UserSeed): void {
      delta({ kind: 'row_updated', rowKey: user.id, row: wireRow(user) })
    },
    stale(id: number, staleSources: string[]): void {
      delta({ kind: 'row_stale', rowKey: id, staleSources })
    },
    progress,
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.replaceChildren()
})

/**
 * Mount the list over a seeded context and let the first window land.
 *
 * @param context The seeded project context.
 * @param options Mount options like DOM attachment.
 */
async function mountList(
  context: HilosUsersContext,
  options?: { attachTo?: HTMLElement },
): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(HilosUsersPage, {
    props: { context: markRaw(context) },
    global: { provide: { [hilosRouterKey as symbol]: router() } },
    slots: {
      'row-actions': ({ row }: { row: HilosUserRow }) =>
        h('a', { href: `/hilos/user/${row.id}` }, 'Open'),
    },
    ...(options?.attachTo ? { attachTo: options.attachTo } : {}),
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

/**
 * Read a row's value by the column's semantic heading.
 *
 * @param wrapper The mounted list.
 * @param id The person's id.
 * @param heading The column's heading.
 */
function cell(
  wrapper: ReturnType<typeof mount>,
  id: number,
  heading: string,
): DOMWrapper<Element> {
  const index = wrapper
    .findAll('thead th')
    .findIndex((column) => column.text().startsWith(heading))
  expect(index).toBeGreaterThanOrEqual(0)

  return wrapper.get(`[data-id="hilos-table-row-${id}"]`).findAll('td')[index]!
}

describe('HilosUsersPage', () => {
  it('reformats a shown activity without moving rows, with epoch zero present and invalid moments empty', async () => {
    const zone = readerTimeZone()
    try {
      const wrapper = await mountList(
        seededContext(
          [0, null, NaN].map((lastActivity, index) => ({
            id: index + 1,
            name: `Person ${index + 1}`,
            lastActivity,
            presence: 'offline',
            onlineSessionCount: 0,
          })),
        ),
      )
      const automatic = formatMoment(0)
      expect(wrapper.text()).toContain(automatic)
      expect(wrapper.text()).toContain('—')
      zone.choose('Pacific/Kiritimati')
      await nextTick()
      expect(formatMoment(0)).not.toBe(automatic)
      expect(wrapper.text()).toContain(formatMoment(0))
      expect(wrapper.text()).not.toContain(automatic)
      zone.choose(null)
      await nextTick()
      expect(wrapper.text()).toContain(automatic)
      expect(wrapper.text().indexOf('Person 1')).toBeLessThan(
        wrapper.text().indexOf('Person 2'),
      )
      expect(wrapper.text().indexOf('Person 2')).toBeLessThan(
        wrapper.text().indexOf('Person 3'),
      )
    } finally {
      zone.release()
    }
  })

  it('renders a row per user with the name and the presence', async () => {
    const wrapper = await mountList(
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

    expect(wrapper.text()).toContain('Alice')
    expect(wrapper.text()).toContain('Bob')
    expect(cell(wrapper, 1, 'Presence').text()).toContain('online')
    expect(wrapper.findAll('[data-id="hilos-users-merged"]')).toHaveLength(0)
  })

  it('names the table through its single Users heading and keeps row ids available to Open', async () => {
    const wrapper = await mountList(
      seededContext([
        {
          id: 7,
          name: 'Alice',
          lastActivity: null,
          presence: 'offline',
          onlineSessionCount: 0,
        },
      ]),
    )
    const headings = wrapper.findAll('h1')
    expect(headings).toHaveLength(1)
    const heading = headings[0]!
    expect(heading.text()).toBe('Users')
    expect(heading.attributes('id')).toBeTruthy()
    expect(wrapper.get('table').attributes('aria-labelledby')).toBe(
      heading.attributes('id'),
    )
    const icon = wrapper.get('[data-id="hilos-page-head-block"] i')
    expect(icon.element.closest('[aria-hidden="true"]')).not.toBeNull()
    expect(
      wrapper.get('nav[aria-label="Breadcrumb"] a').attributes('href'),
    ).toBe('/hilos')
    expect(
      wrapper.findAll('nav[aria-label="Breadcrumb"] [aria-current="page"]'),
    ).toHaveLength(1)
    expect(cell(wrapper, 7, 'Name').text()).toContain('Alice')
    expect(cell(wrapper, 7, 'Name').text()).toContain('#7')
    expect(
      wrapper.get('[data-id="hilos-table-row-7"] a').attributes('href'),
    ).toBe('/hilos/user/7')
  })

  it('reads Blocked initially and changes it through row and entity updates', async () => {
    const alice: UserSeed = {
      id: 1,
      name: 'Alice',
      lastActivity: null,
      presence: 'offline',
      onlineSessionCount: 0,
      block: true,
    }
    const live = liveUsers([alice])
    const wrapper = await mountList(live.context)
    const name = (): DOMWrapper<Element> => cell(wrapper, 1, 'Name')

    expect(name().text()).toContain('Blocked')
    expect(name().get('i').attributes('aria-hidden')).toBe('true')
    live.update({ ...alice, block: false })
    await flushPromises()
    expect(name().text()).not.toContain('Blocked')
    live.context.scopes
      .page()!
      .entities.upsert({ type: USER_ENTITY_TYPE, id: 1 }, { block: true })
    await nextTick()
    expect(name().text()).toContain('Blocked')
    live.update({ ...alice, block: false })
    await flushPromises()
    expect(name().text()).not.toContain('Blocked')
    live.update(alice)
    await flushPromises()
    expect(name().text()).toContain('Blocked')
    expect(name().text()).toContain('#1')
  })

  it.each([null, 99, 1])(
    'follows the session person from %s through changes and sign-out',
    async (initialId) => {
      const context = seededContext(
        [1, 2].map((id) => ({
          id,
          name: `Person ${id}`,
          lastActivity: null,
          presence: 'offline',
          onlineSessionCount: 0,
          block: id === 1,
        })),
      )
      context.scopes.session.data.set(
        'currentUser',
        initialId === null ? null : { type: USER_ENTITY_TYPE, id: initialId },
      )
      const wrapper = await mountList(context)
      const you = (id: number): boolean =>
        cell(wrapper, id, 'Name').text().includes('You')

      expect(you(1)).toBe(initialId === 1)
      expect(you(2)).toBe(false)
      context.scopes.session.data.set('currentUser', {
        type: USER_ENTITY_TYPE,
        id: 1,
      })
      await nextTick()
      expect(you(1)).toBe(true)
      expect(cell(wrapper, 1, 'Name').text()).toContain('Blocked')
      context.scopes.session.data.set('currentUser', {
        type: USER_ENTITY_TYPE,
        id: 2,
      })
      await nextTick()
      expect(you(1)).toBe(false)
      expect(you(2)).toBe(true)
      context.scopes.session.data.set('currentUser', null)
      await nextTick()
      expect(you(1)).toBe(false)
      expect(you(2)).toBe(false)
    },
  )

  it('lets Merged replace Blocked and You, including when the merge arrives live', async () => {
    const alice: UserSeed = {
      id: 1,
      name: 'Alice',
      lastActivity: null,
      presence: 'offline',
      onlineSessionCount: 0,
      block: true,
      merged: true,
    }
    const bob: UserSeed = { ...alice, id: 2, name: 'Bob', merged: false }
    const live = liveUsers([alice, bob])
    live.context.scopes.session.data.set('currentUser', {
      type: USER_ENTITY_TYPE,
      id: 1,
    })
    const wrapper = await mountList(live.context)
    const name = (): DOMWrapper<Element> => cell(wrapper, 1, 'Name')
    const merged = name().get('[data-id="hilos-users-merged"]')
    expect(merged.text()).toBe('Merged')
    expect(merged.get('i').attributes('aria-hidden')).toBe('true')
    expect(name().text()).not.toContain('Blocked')
    expect(name().text()).not.toContain('You')
    expect(
      cell(wrapper, 2, 'Name').find('[data-id="hilos-users-merged"]').exists(),
    ).toBe(false)

    live.update({ ...alice, merged: false })
    await flushPromises()
    expect(name().find('[data-id="hilos-users-merged"]').exists()).toBe(false)
    expect(name().text()).toContain('Blocked')
    expect(name().text()).toContain('You')
    live.update(alice)
    await flushPromises()
    expect(name().get('[data-id="hilos-users-merged"]').text()).toBe('Merged')
    expect(name().text()).not.toContain('Blocked')
    expect(name().text()).not.toContain('You')
    expect(name().text()).toContain('#1')
  })

  it('keeps a hidden name hidden while the public id and flags remain readable', async () => {
    const context = seededContext([
      {
        id: 1,
        name: HIDDEN_VALUE,
        lastActivity: null,
        presence: 'offline',
        onlineSessionCount: 0,
        block: true,
      },
    ])
    context.scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 1 },
      { name: 'Private name' },
    )
    context.scopes.session.data.set('currentUser', {
      type: USER_ENTITY_TYPE,
      id: 1,
    })
    const wrapper = await mountList(context)
    const name = cell(wrapper, 1, 'Name')

    expect(name.text()).toContain('Hidden')
    expect(wrapper.text()).not.toContain('Private name')
    expect(name.text()).toContain('#1')
    expect(name.text()).toContain('Blocked')
    expect(name.text()).toContain('You')
    expect(
      wrapper.get('[data-id="hilos-table-row-1"] a').attributes('href'),
    ).toBe('/hilos/user/1')
  })

  function layout() {
    const frames = new Map<number, FrameRequestCallback>()
    let serial = 0
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
      frames.set(++serial, callback)
      return serial
    })
    vi.stubGlobal('cancelAnimationFrame', (id: number) => frames.delete(id))
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
      function () {
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
        await nextTick()
        const pending = [...frames.values()]
        frames.clear()
        for (const callback of pending) callback(0)
        await nextTick()
      },
    }
  }

  it('shows reading controls on overflowing fields and keeps badges and id outside the text window', async () => {
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
        name: 'Constance Current User Name Overflowing Height Window',
        lastActivity: 1781863200000,
        presence: 'online',
        onlineSessionCount: 3,
        openSessionCount: null,
        block: true,
      },
      {
        id: 3,
        name: HIDDEN_VALUE,
        lastActivity: null,
        presence: 'offline',
        onlineSessionCount: 0,
      },
    ])
    context.scopes.session.data.set('currentUser', {
      type: USER_ENTITY_TYPE,
      id: 2,
    })

    const wrapper = await mountList(context, { attachTo: document.body })
    await dom.flush()

    // 1. Name overflow on row 1 (merged):
    const nameCell1 = cell(wrapper, 1, 'Name')
    const nameToggle1 = nameCell1.get('[data-id="hilos-table-text-toggle"]')
    expect(nameToggle1.attributes('aria-label')).toBe('Show in full: Name')
    const ariaControls1 = nameToggle1.attributes('aria-controls')
    expect(ariaControls1).toBeTruthy()
    const textRegion1 = document.getElementById(ariaControls1!)
    expect(textRegion1).not.toBeNull()
    expect(textRegion1!.textContent).toContain('Maria-Luisa')
    expect(textRegion1!.textContent).not.toContain('Merged')
    expect(textRegion1!.textContent).not.toContain('#1')
    expect(nameCell1.find('[data-id="hilos-users-merged"]').exists()).toBe(true)
    expect(nameCell1.text()).toContain('#1')

    // 2. Name overflow on row 2 (blocked and you):
    const nameCell2 = cell(wrapper, 2, 'Name')
    const nameToggle2 = nameCell2.get('[data-id="hilos-table-text-toggle"]')
    expect(nameToggle2.attributes('aria-label')).toBe('Show in full: Name')
    const ariaControls2 = nameToggle2.attributes('aria-controls')
    expect(ariaControls2).toBeTruthy()
    const textRegion2 = document.getElementById(ariaControls2!)
    expect(textRegion2).not.toBeNull()
    expect(textRegion2!.textContent).toContain('Constance Current User')
    expect(textRegion2!.textContent).not.toContain('Blocked')
    expect(textRegion2!.textContent).not.toContain('You')
    expect(textRegion2!.textContent).not.toContain('#2')
    expect(nameCell2.text()).toContain('Blocked')
    expect(nameCell2.text()).toContain('You')
    expect(nameCell2.text()).toContain('#2')

    // 3. Sessions and Last activity have their own toggle buttons with their column label:
    const sessionsCell = cell(wrapper, 1, 'Sessions')
    expect(sessionsCell.text()).toContain('4')
    const sessionsToggle = sessionsCell.get(
      '[data-id="hilos-table-text-toggle"]',
    )
    expect(sessionsToggle.attributes('aria-label')).toBe(
      'Show in full: Sessions',
    )

    const activityCell = cell(wrapper, 1, 'Last activity')
    const activityToggle = activityCell.get(
      '[data-id="hilos-table-text-toggle"]',
    )
    expect(activityToggle.attributes('aria-label')).toBe(
      'Show in full: Last activity',
    )
    expect(cell(wrapper, 2, 'Sessions').text()).toContain('—')

    // 4. Hidden name: renders mark without reading toggle button:
    const nameCell3 = cell(wrapper, 3, 'Name')
    expect(nameCell3.find('[data-id="hilos-hidden"]').exists()).toBe(true)
    expect(nameCell3.find('[data-id="hilos-table-text-toggle"]').exists()).toBe(
      false,
    )
  })
})

describe('HilosUsersPage presence (HIL-1566)', () => {
  /**
   * The person's presence word, separate from the hidden reserve text.
   *
   * @param wrapper The mounted list.
   * @param id The person's id.
   */
  function presence(
    wrapper: ReturnType<typeof mount>,
    id: number,
  ): DOMWrapper<Element> {
    return cell(wrapper, id, 'Presence')
      .findAll('span')
      .find(
        (word) =>
          word.element.childElementCount === 0 &&
          word.element.closest('[aria-hidden="true"]') === null,
      )!
  }

  it('says every presence in words and hides the reserve text from assistive technology', async () => {
    const wrapper = await mountList(
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

    const expected: Record<number, string> = {
      1: 'online',
      2: 'probably offline',
      3: 'offline',
      4: 'offline',
    }
    for (const [id, word] of Object.entries(expected)) {
      expect(presence(wrapper, Number(id)).text()).toBe(word)
      const twin = cell(wrapper, Number(id), 'Presence').get(
        '[aria-hidden="true"]',
      )
      expect(twin.text()).toBe('probably offline')
      expect(twin.attributes('aria-hidden')).toBe('true')
    }
    expect(wrapper.text()).not.toContain('probably_offline')
    // The doubted connections are still registered, so the count keeps them.
    expect(cell(wrapper, 2, 'Sessions').text()).toBe('2Show in full')
    expect(wrapper.text()).toContain('Oct 21, 2026, 11:00:00 AM')
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
    const wrapper = await mountList(live.context)
    const settle = async (): Promise<void> => {
      await flushPromises()
      await nextTick()
    }
    const stale = (): boolean =>
      wrapper.find('[data-id="hilos-table-stale-row-1"]').exists()

    expect(presence(wrapper, 1).text()).toBe('online')

    // Every connection stopped answering: in doubt, still counted, nothing quiet.
    live.update({ ...alice, presence: 'probably_offline' })
    await settle()
    expect(presence(wrapper, 1).text()).toBe('probably offline')
    expect(stale()).toBe(false)
    expect(cell(wrapper, 1, 'Sessions').text()).toBe('1Show in full')

    // The source going quiet marks the row and leaves the word alone.
    live.stale(1, ['connections'])
    await settle()
    expect(stale()).toBe(true)
    expect(presence(wrapper, 1).text()).toBe('probably offline')

    live.stale(1, [])
    await settle()
    expect(stale()).toBe(false)
    expect(presence(wrapper, 1).text()).toBe('probably offline')

    // A connection answered again, then the last one closed.
    live.update({ ...alice, presence: 'online', onlineSessionCount: 2 })
    await settle()
    expect(presence(wrapper, 1).text()).toBe('online')
    expect(cell(wrapper, 1, 'Sessions').text()).toBe('2Show in full')

    live.update({ ...alice, presence: 'offline', onlineSessionCount: 0 })
    await settle()
    expect(presence(wrapper, 1).text()).toBe('offline')
    expect(wrapper.text()).toContain('Oct 21, 2026, 10:00:00 AM')
  })
})

describe('HilosUsersPage erasure (HIL-1956)', () => {
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
   * Mount the list over Alice (blocked, the session's own) and Bob.
   *
   * @param seeds The people of the first window.
   */
  async function mountCircle(seeds: UserSeed[] = [alice, bob]) {
    const live = liveUsers(seeds)
    live.context.scopes.session.data.set('currentUser', {
      type: USER_ENTITY_TYPE,
      id: 1,
    })
    const wrapper = await mountList(live.context)
    const progressRow = (id: number) =>
      wrapper.find(`[data-id="hilos-table-progress-row-${id}"]`)
    const caption = (id: number, line: string) =>
      progressRow(id).find(`[data-id="hilos-users-erasure-${line}"]`)

    return { live, wrapper, progressRow, caption }
  }

  it('wears Being deleted instead of Blocked and You and captions the bar when it arrives live', async () => {
    const { live, wrapper, progressRow, caption } = await mountCircle()
    const name = (): DOMWrapper<Element> => cell(wrapper, 1, 'Name')
    expect(name().text()).toContain('Blocked')
    expect(name().text()).toContain('You')

    live.progress(erasureBar('1'))
    await flushPromises()

    const erasing = name().get('[data-id="hilos-users-erasing"]')
    expect(erasing.text()).toBe('Being deleted')
    expect(erasing.get('i').attributes('aria-hidden')).toBe('true')
    expect(name().text()).not.toContain('Blocked')
    expect(name().text()).not.toContain('You')
    expect(caption(1, 'summary').text()).toBe(
      `Erasure in progress · 3 of 5 parts done · started ${String(formatMoment(startedAt, { kind: 'dateTime' }))}`,
    )
    expect(caption(1, 'expected').text()).toBe('Expected: chat; chat · #2')
    expect(caption(1, 'expected').findAll('code')).toHaveLength(2)
    expect(caption(1, 'waits').text()).toBe(
      'Waiting for an owner: delivery:email · #2',
    )
    expect(caption(1, 'refusals').text()).toBe(
      'Refused: #2 · Another table still references the account',
    )
    expect(
      progressRow(1).get('[role="progressbar"]').attributes('aria-valuenow'),
    ).toBe('60')
    expect(
      cell(wrapper, 2, 'Name').find('[data-id="hilos-users-erasing"]').exists(),
    ).toBe(false)
  })

  it('keeps Merged on a merged account of the circle and still captions its bar', async () => {
    const { live, wrapper, caption } = await mountCircle([
      alice,
      { ...bob, merged: true },
    ])

    live.progress(erasureBar('2'))
    await flushPromises()

    const name = cell(wrapper, 2, 'Name')
    expect(name.get('[data-id="hilos-users-merged"]').text()).toBe('Merged')
    expect(name.find('[data-id="hilos-users-erasing"]').exists()).toBe(false)
    expect(caption(2, 'summary').text()).toContain('3 of 5 parts done')
    expect(caption(2, 'expected').text()).toBe('Expected: chat · #1; chat')
  })

  it('brings Blocked and You back when the erasure ends', async () => {
    const { live, wrapper, progressRow } = await mountCircle()
    live.progress(erasureBar('1'))
    await flushPromises()

    live.progress(erasureBar('1', { current: 5, ended: true }))
    await flushPromises()

    const name = cell(wrapper, 1, 'Name')
    expect(name.find('[data-id="hilos-users-erasing"]').exists()).toBe(false)
    expect(name.text()).toContain('Blocked')
    expect(name.text()).toContain('You')
    expect(progressRow(1).exists()).toBe(false)
  })

  it('leaves a bar about other work without the erasure badge or caption', async () => {
    const { live, wrapper, progressRow, caption } = await mountCircle()

    live.progress(erasureBar('1', { progressKey: 'other-work' }))
    await flushPromises()

    expect(progressRow(1).find('[role="progressbar"]').exists()).toBe(true)
    expect(caption(1, 'summary').exists()).toBe(false)
    const name = cell(wrapper, 1, 'Name')
    expect(name.find('[data-id="hilos-users-erasing"]').exists()).toBe(false)
    expect(name.text()).toContain('Blocked')
  })

  it('says only that the erasure is in progress when its block cannot be read', async () => {
    const { live, wrapper, caption } = await mountCircle()

    live.progress(erasureBar('1', { detail: {} }))
    await flushPromises()

    expect(caption(1, 'summary').text()).toBe('Erasure in progress')
    expect(caption(1, 'expected').exists()).toBe(false)
    expect(caption(1, 'waits').exists()).toBe(false)
    expect(caption(1, 'refusals').exists()).toBe(false)
    expect(
      cell(wrapper, 1, 'Name').get('[data-id="hilos-users-erasing"]').text(),
    ).toBe('Being deleted')
  })

  it('moves the count without moving the row', async () => {
    const { live, wrapper, caption } = await mountCircle()
    live.progress(erasureBar('1'))
    await flushPromises()
    const row = wrapper.get('[data-id="hilos-table-row-1"]').element

    live.progress(erasureBar('1', { current: 4 }))
    await flushPromises()

    expect(caption(1, 'summary').text()).toContain('4 of 5 parts done')
    expect(wrapper.get('[data-id="hilos-table-row-1"]').element).toBe(row)
  })
})
