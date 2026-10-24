import { enableAutoUnmount, mount } from '@vue/test-utils'
import { defineComponent, h, markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  bindAdminAccess,
  formatMoment,
  createAppPageRouter,
  createHilosRouter,
  DEFAULT_SKELETON_DELAY_MS,
  entityCollection,
  HIDDEN_VALUE,
  HilosPages,
  PageSubscription,
  resolvePageTitle,
  ScopeManager,
  USER_ENTITY_TYPE,
  userFromFields,
  type ActionLifecycle,
  type Hideable,
  type HilosAccountStanding,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core/base'
import {
  HILOS_USER_ANALYTICS_ENABLED_KEY,
  HILOS_USER_STANDING_SECTION,
  SIGNAL_ACCOUNT_STANDING_STATE,
  type HilosPresence,
  type HilosUsersContext,
} from '@hilos/core/admin/users'

import { HilosUserPageShell } from './index.js'
import HilosView from '../../HilosView.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { readerTimeZone } from '../../../test/helpers/readerTimeZone.js'

enableAutoUnmount(afterEach)
const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
  vi.useRealTimers()
})

const USER_OWNER = {
  page: HilosPages.USER,
  label: 'User',
  lead: 'A single user: profile, presence, and account actions.',
  icon: null,
}
const PAGE_TABS = [
  { page: HilosPages.USER, label: 'Overview' },
  { page: HilosPages.USER_ROLES, label: 'Roles' },
  { page: HilosPages.USER_ACCESS, label: 'Access' },
  { page: HilosPages.USER_SESSIONS, label: 'Sessions' },
]
const CLEAR_STANDING: HilosAccountStanding = {
  shown: 'none',
  blocked: false,
  frozen: false,
  deletionEffectiveAt: null,
  lapsed: [],
  window: [],
  mergedInto: null,
  mergedIntoName: null,
}

/** Real scopes, identity and navigation; the fixture supplies server answers only. */
function harness(
  options: {
    path?: string
    accessBuilt?: boolean
    analyticsBuilt?: boolean
    viewer?: boolean
  } = {},
) {
  const scopes = new ScopeManager()
  scopes.session.data.set('adminViewMode', options.viewer ?? false)
  releases.push(bindAdminAccess(scopes))
  const subscription = new PageSubscription(
    {
      state: 'connected',
      reconnectDragging: false,
      send: () => true,
      on: () => () => {},
      forgetPageFrames: () => {},
      tableWindowDescriptors: () => ({}),
    },
    scopes,
  )
  let path = options.path ?? '/hilos/user/1'
  const unbuilt = new Set<string>([
    HilosPages.USER_ROLES,
    HilosPages.USER_SESSIONS,
  ])
  if (!options.accessBuilt) unbuilt.add(HilosPages.USER_ACCESS)
  if (options.analyticsBuilt === false) {
    unbuilt.add(HilosPages.ANALYTICS_PERSON)
  }
  const navigation = createHilosRouter(
    createAppPageRouter({}),
    subscription,
    {
      pathname: () => path,
      pushState: (next) => {
        path = next
      },
      replaceState: (next) => {
        path = next
      },
      onPopState: () => () => {},
    },
    (page) => {
      const identity = subscription.pageIdentity.get()
      return resolvePageTitle(
        page,
        {},
        'Test app',
        identity?.label,
        !subscription.pageLoading.get(),
        identity?.pageHeadOwner?.label,
      )
    },
    unbuilt,
  )
  navigation.start()
  releases.push(navigation.stop)

  const listeners = new Set<(signal: ProjectSignal) => void>()
  const connection = {
    on: (event: string, listener: (signal: ProjectSignal) => void) => {
      if (event === 'projectSignal') listeners.add(listener)
      return () => listeners.delete(listener)
    },
  } as unknown as HilosConnection
  const context: HilosUsersContext = markRaw({
    scopes,
    connection,
    actions: {} as ActionLifecycle,
    users: entityCollection(scopes, USER_ENTITY_TYPE, userFromFields),
  })
  const global = { provide: { [hilosRouterKey as symbol]: navigation } }

  /**
   * Deliver the existing page payload, including its catalog identity and slots.
   *
   * @param values The permitted values in this answer.
   */
  function answer(
    values: {
      id?: number
      name?: Hideable<string>
      lastActivity?: number | null
      photo?: Hideable<string | null>
      presence?: HilosPresence
      standing?: HilosAccountStanding | typeof HIDDEN_VALUE
      analytics?: unknown
      detail?: boolean
    } = {},
  ): void {
    const page = subscription.pageKey()!
    const id = values.id ?? 1
    subscription.ingestPageResponse(
      page,
      {
        data: {
          pageLabel: page === HilosPages.USER ? 'User' : 'User access',
          pageLead: 'The subscribing page lead.',
          pageHeadOwner: USER_OWNER,
          pageBreadcrumb: [
            { page: HilosPages.DASHBOARD, label: 'Hilos' },
            { page: HilosPages.USERS, label: 'Users' },
            { page: HilosPages.USER, label: 'User' },
          ],
          pageTabs: PAGE_TABS,
          pageChildren: [],
          [HILOS_USER_STANDING_SECTION]: values.standing ?? CLEAR_STANDING,
          [HILOS_USER_ANALYTICS_ENABLED_KEY]: values.analytics ?? false,
        },
        tables: {
          userDetail: {
            cleared: true,
            rows:
              values.detail === false
                ? []
                : [
                    {
                      rowKey: id,
                      slots: {
                        users: {
                          id,
                          name: values.name ?? 'Alice',
                          lastActivity: values.lastActivity ?? null,
                        },
                        connections: {
                          presence: values.presence ?? 'online',
                          onlineSessionCount: 3,
                        },
                      },
                    },
                  ],
          },
          userPhoto: {
            cleared: true,
            rows: [
              {
                rowKey: id,
                slots: {
                  userPhotos: { photo: values.photo ?? null },
                },
              },
            ],
          },
        },
      },
      { entityTypes: { users: USER_ENTITY_TYPE } },
    )
  }

  return {
    scopes,
    subscription,
    navigation,
    context,
    global,
    answer,
    listeners,
    frame: (standing: HilosAccountStanding, userId = 1) => {
      for (const listener of listeners) {
        listener({
          type: SIGNAL_ACCOUNT_STANDING_STATE,
          data: { userId, accountStanding: standing },
        } as ProjectSignal)
      }
    },
    mount: () =>
      mount(HilosUserPageShell, {
        props: { context, page: HilosPages.USER },
        global,
      }),
  }
}

async function settle(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosUserPageShell', () => {
  it('updates the activity text in the open head when the reader changes zone or returns to Automatic', async () => {
    const zone = readerTimeZone()
    releases.push(zone.release)
    const t = harness()
    t.answer({ lastActivity: 0 })
    const view = t.mount()
    const automatic = formatMoment(0)
    expect(view.get('[data-id="hilos-page-head-block"] p').text()).toBe(
      `#1 · Last seen ${automatic}`,
    )
    zone.choose('Pacific/Kiritimati')
    await settle()
    expect(formatMoment(0)).not.toBe(automatic)
    expect(view.get('[data-id="hilos-page-head-block"] p').text()).toBe(
      `#1 · Last seen ${formatMoment(0)}`,
    )
    zone.choose(null)
    await settle()
    expect(view.get('[data-id="hilos-page-head-block"] p').text()).toBe(
      `#1 · Last seen ${automatic}`,
    )
  })

  it.each([null, NaN, Infinity])(
    'omits absent or invalid activity %j from the head',
    (lastActivity) => {
      const t = harness()
      t.answer({ lastActivity })
      expect(t.mount().get('[data-id="hilos-page-head-block"] p').text()).toBe(
        '#1',
      )
    },
  )

  it('renders one shared head, three crumbs, the avatar and a live permitted name and title', async () => {
    const t = harness()
    t.answer({ lastActivity: 1792758896000, analytics: true })
    const view = t.mount()
    expect(view.findAll('h1')).toHaveLength(1)
    expect(view.get('h1').text()).toBe('Alice')
    const crumbs = view.findAll('[data-id="hilos-breadcrumb"] li')
    expect(crumbs.map((crumb) => crumb.text())).toEqual([
      'Hilos',
      'Users',
      'Alice',
    ])
    expect(crumbs[0].get('a').attributes('href')).toBe('/hilos')
    expect(crumbs[1].get('a').attributes('href')).toBe('/hilos/users')
    expect(crumbs[2].find('a').exists()).toBe(false)
    expect(crumbs[2].attributes('aria-current')).toBe('page')
    expect(t.navigation.currentTitle.get()).toBe('Alice · Test app')
    expect(view.get('[data-id="hilos-avatar"]').text()).toBe('A')
    expect(view.get('[data-id="hilos-page-head-block"] p').text()).toBe(
      '#1 · Last seen Oct 23, 2026, 12:34:56 PM',
    )
    expect(view.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
    const analytics = view.get('[data-id="hilos-page-head-action"]')
    expect(analytics.text()).toBe('Actions in Analytics')
    expect(analytics.attributes('href')).toBe('/hilos/analytics/people/1')
    expect(view.findAll('button')).toHaveLength(0)

    t.scopes
      .page()!
      .entities.upsert(
        { type: USER_ENTITY_TYPE, id: 1 },
        { name: 'Alice renamed' },
      )
    await nextTick()
    expect(view.get('h1').text()).toBe('Alice renamed')
    expect(view.get('[data-id="hilos-breadcrumb"] li:last-child').text()).toBe(
      'Alice renamed',
    )
    expect(t.navigation.currentTitle.get()).toBe('Alice renamed · Test app')
    expect(analytics.attributes('href')).toBe('/hilos/analytics/people/1')
  })

  it('keeps hidden names hidden in the head, breadcrumb, avatar and browser title', async () => {
    const t = harness({ viewer: true })
    for (const scope of [t.scopes.session, t.scopes.user]) {
      scope.entities.upsert(
        { type: USER_ENTITY_TYPE, id: 1 },
        { id: 1, name: 'Private session name' },
      )
      scope.data.set('currentUser', { type: USER_ENTITY_TYPE, id: 1 })
    }
    t.answer({
      name: HIDDEN_VALUE,
      photo: HIDDEN_VALUE,
      standing: HIDDEN_VALUE,
    })
    const view = t.mount()
    expect(view.get('h1').text()).toBe('Hidden')
    expect(view.get('[data-id="hilos-breadcrumb"] li:last-child').text()).toBe(
      'Hidden',
    )
    expect(t.navigation.currentTitle.get()).toBe('Hidden · Test app')
    expect(view.text()).not.toContain('Private session name')
    expect(view.find('[data-id="hilos-avatar-photo"]').exists()).toBe(false)
    expect(view.get('[data-id="hilos-avatar"]').text()).toBe('')
    expect(view.find('[data-id="user-standing-badge"]').exists()).toBe(false)

    t.answer({ name: 'Permitted now' })
    await nextTick()
    expect(view.get('h1').text()).toBe('Permitted now')
    expect(t.navigation.currentTitle.get()).toBe('Permitted now · Test app')
  })

  it('forgets an absent detail and ignores a foreign scope even if it contains a user detail row', async () => {
    const t = harness()
    t.answer()
    const view = t.mount()
    const oldScope = t.scopes.page()!
    oldScope.tables.delete('userDetail', 1)
    await nextTick()
    expect(view.get('h1').text()).toBe('User')
    expect(t.navigation.currentTitle.get()).toBe('User · Test app')
    expect(view.find('[data-id="hilos-avatar"]').exists()).toBe(false)
    expect(view.find('[data-id="hilos-page-head-block"] p').exists()).toBe(
      false,
    )

    t.scopes.dropPage()
    expect(t.navigation.pageIdentity.get()).toBeUndefined()
    t.scopes.openPage(HilosPages.USERS)
    t.answer({ name: 'Foreign page name' })
    oldScope.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 1 },
      { name: 'Departed page name' },
    )
    await nextTick()
    expect(view.get('h1').text()).toBe('User')
    expect(t.navigation.currentTitle.get()).toBe('User · Test app')
    expect(view.text()).not.toContain('Foreign page name')
    expect(view.text()).not.toContain('Departed page name')
  })

  it.each([
    HilosPages.USER,
    HilosPages.USER_ROLES,
    HilosPages.USER_ACCESS,
    HilosPages.USER_SESSIONS,
  ])(
    'binds only the current detail on %s and releases the binding and live frames on unmount',
    async (page) => {
      const t = harness()
      t.scopes.openPage(page)
      t.answer({ name: 'Current tab name' })
      const view = t.mount()
      expect(t.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
        'Current tab name',
      )
      expect(t.listeners.size).toBe(1)
      view.unmount()
      expect(t.listeners.size).toBe(0)
      expect(t.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('User')
      t.scopes
        .page()!
        .entities.upsert(
          { type: USER_ENTITY_TYPE, id: 1 },
          { name: 'After unmount' },
        )
      await nextTick()
      expect(t.navigation.currentTitle.get()).toBe('User · Test app')
    },
  )

  it('uses the existing photo slot and falls back to initials on a failed or removed photo', async () => {
    const t = harness()
    t.answer({ photo: '/files/portrait' })
    const view = t.mount()
    const photo = view.get('[data-id="hilos-avatar-photo"]')
    expect(photo.attributes('src')).toBe('/files/portrait')
    expect(photo.attributes('alt')).toBe('')
    expect(view.get('[data-id="hilos-avatar"]').attributes('aria-hidden')).toBe(
      'true',
    )
    await photo.trigger('error')
    expect(view.get('[data-id="hilos-avatar"]').text()).toBe('A')
    t.answer({ photo: '/files/new-portrait' })
    await nextTick()
    expect(view.get('img').attributes('src')).toBe('/files/new-portrait')
    t.answer()
    await nextTick()
    expect(view.find('img').exists()).toBe(false)
    expect(view.get('[data-id="hilos-page-head-block"] p').text()).toBe('#1')
  })

  it('draws all three presence words in reserved room and just the shown standing badge', async () => {
    const t = harness()
    t.answer()
    const view = t.mount()
    for (const [presence, label, tone] of [
      ['online', 'online', 'success'],
      ['probably_offline', 'probably offline', 'warning'],
      ['offline', 'offline', 'secondary'],
    ] as const) {
      t.answer({ presence })
      await nextTick()
      const badge = view.get(
        '[data-id="hilos-page-head-block"] .hilos-stack .badge:not(.invisible)',
      )
      expect(badge.text()).toBe(label)
      expect(badge.classes()).toContain(`text-bg-${tone}`)
      const twin = badge.element.previousElementSibling!
      expect(twin.textContent).toBe('probably offline')
      expect(twin.getAttribute('aria-hidden')).toBe('true')
      expect(twin.classList.contains('invisible')).toBe(true)
    }
    for (const [shown, label, tone, icon] of [
      ['deletion_scheduled', 'Deletion scheduled', 'warning', 'bi-trash'],
      ['frozen', 'Frozen', 'info', 'bi-snow'],
      ['blocked', 'Blocked', 'danger', 'bi-slash-circle'],
      ['merged', 'Merged', 'secondary', 'bi-sign-merge-left'],
    ] as const) {
      t.frame({ ...CLEAR_STANDING, shown, blocked: true, frozen: true })
      await nextTick()
      const badges = view.findAll('[data-id="user-standing-badge"]')
      expect(badges).toHaveLength(1)
      expect(badges[0].text()).toBe(label)
      expect(badges[0].classes()).toContain(`text-bg-${tone}`)
      expect(badges[0].get('i').classes()).toContain(icon)
    }
    t.frame(CLEAR_STANDING, 99)
    await nextTick()
    expect(view.get('[data-id="user-standing-badge"]').text()).toBe('Merged')
    t.frame(CLEAR_STANDING)
    await nextTick()
    expect(view.find('[data-id="user-standing-badge"]').exists()).toBe(false)
  })

  it.each([false, HIDDEN_VALUE, 'true', undefined])(
    'hides Analytics when its enabled value is %j',
    (analytics) => {
      const t = harness()
      t.answer({ analytics })
      const view = t.mount()
      expect(view.find('[data-id="hilos-page-head-action"]').exists()).toBe(
        false,
      )
    },
  )

  it('omits Analytics without a built route or detail and follows the live enabled value and person id', async () => {
    const unbuilt = harness({ analyticsBuilt: false })
    unbuilt.answer({ analytics: true })
    expect(
      unbuilt.mount().find('[data-id="hilos-page-head-action"]').exists(),
    ).toBe(false)
    const t = harness()
    t.answer({ analytics: true, detail: false })
    const view = t.mount()
    expect(view.find('[data-id="hilos-page-head-action"]').exists()).toBe(false)
    t.answer({ analytics: true, id: 42 })
    await nextTick()
    expect(
      view.get('[data-id="hilos-page-head-action"]').attributes('href'),
    ).toBe('/hilos/analytics/people/42')
    t.scopes.page()!.data.set(HILOS_USER_ANALYTICS_ENABLED_KEY, false)
    await nextTick()
    expect(view.find('[data-id="hilos-page-head-action"]').exists()).toBe(false)
  })

  it.each([
    [2, 'Bob', 'This account was merged into Bob (#2). Open card'],
    [2, HIDDEN_VALUE, 'This account was merged into Hidden (#2). Open card'],
    [null, null, 'This account was merged into another one.'],
  ] as const)(
    'shows a merged survivor %j/%j with navigation and no mutations',
    async (mergedInto, mergedIntoName, expected) => {
      const t = harness()
      t.answer({
        analytics: true,
        standing: {
          ...CLEAR_STANDING,
          shown: 'merged',
          mergedInto,
          mergedIntoName,
        },
      })
      const view = t.mount()
      await nextTick()
      expect(
        view.get('[data-id="hilos-user-merged"]').text().replace(/\s+/g, ' '),
      ).toBe(expected)
      const link = view.find('[data-id="hilos-user-merged-link"]')
      expect(link.exists()).toBe(mergedInto !== null)
      if (mergedInto !== null)
        expect(link.attributes('href')).toBe('/hilos/user/2')
      expect(
        view.get('[data-id="hilos-page-head-action"]').attributes('href'),
      ).toBe('/hilos/analytics/people/1')
      expect(view.findAll('button')).toHaveLength(0)
    },
  )

  it('provides admin view mode to both content and modal slots without requiring either', async () => {
    const t = harness({ viewer: true })
    t.answer({ name: HIDDEN_VALUE })
    const view = mount(HilosUserPageShell, {
      props: { context: t.context, page: HilosPages.USER },
      global: t.global,
      slots: {
        default: () =>
          h(LoadingButton, { 'data-id': 'body-write' }, () => 'Save'),
        modals: () =>
          h(LoadingButton, { 'data-id': 'modal-write' }, () => 'Confirm'),
      },
    })
    expect(
      view.get('[data-id="body-write"]').attributes('disabled'),
    ).toBeDefined()
    expect(
      view.get('[data-id="modal-write"]').attributes('disabled'),
    ).toBeDefined()
    expect(view.get('h1').text()).toBe('Hidden')
    view.unmount()
    const empty = t.mount()
    await nextTick()
    expect(empty.find('[data-id="body-write"]').exists()).toBe(false)
    expect(empty.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)
  })

  it('shows the shared entity skeleton without identity, names, badges or navigation while unanswered', () => {
    const t = harness()
    const view = t.mount()
    expect(
      view
        .get('[data-id="hilos-page-head-skeleton"]')
        .attributes('aria-hidden'),
    ).toBe('true')
    expect(view.find('h1').exists()).toBe(false)
    expect(view.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(view.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(view.find('[data-id="hilos-avatar"]').exists()).toBe(false)
    expect(
      view
        .find(
          '[data-id="hilos-page-head-block"] .hilos-stack .badge:not(.invisible)',
        )
        .exists(),
    ).toBe(false)
    expect(view.find('[data-id="hilos-page-head-action"]').exists()).toBe(false)
  })
})

describe('user shell with the router and served test pages', () => {
  it('shares one owner and live name across two addressed pages, holding the old screen inert until the next answer', async () => {
    vi.useFakeTimers()
    const t = harness({ accessBuilt: true })
    // Test-only served pages exercise the public shell without building any
    // production Roles, Access or Sessions view or handler.
    const Overview = markRaw(
      defineComponent(
        () => () =>
          h(
            HilosUserPageShell,
            { context: t.context, page: HilosPages.USER },
            {
              default: () =>
                h('p', { 'data-id': 'overview-body' }, 'Overview content'),
            },
          ),
      ),
    )
    const Access = markRaw(
      defineComponent(
        () => () =>
          h(
            HilosUserPageShell,
            { context: t.context, page: HilosPages.USER_ACCESS },
            {
              default: () =>
                h('p', { 'data-id': 'access-body' }, 'Access content'),
            },
          ),
      ),
    )
    const view = mount(HilosView, {
      props: {
        pages: {
          [HilosPages.USER]: Overview,
          [HilosPages.USER_ACCESS]: Access,
        },
      },
      global: t.global,
      attachTo: document.body,
    })
    t.answer()
    await settle()
    expect(view.findAll('h1')).toHaveLength(1)
    expect(view.get('h1').text()).toBe('Alice')
    const row = view.get('nav[aria-label="User pages"]')
    expect(
      row.findAll('a').map((link) => [link.text(), link.attributes('href')]),
    ).toEqual([
      ['Overview', '/hilos/user/1'],
      ['Access', '/hilos/user/1/access'],
    ])
    expect(row.get('[aria-current="page"]').text()).toBe('Overview')
    await row
      .get('[data-id="hilos-page-tab-hilos_user_access"]')
      .trigger('click')
    await settle()
    expect(t.navigation.currentPath.get()).toBe('/hilos/user/1/access')
    expect(
      view.get('[data-id="hilos-page-state"]').attributes('data-state'),
    ).toBe('loading')
    const departure = view.get('[data-id="hilos-page-departure"]')
    expect(departure.attributes('aria-hidden')).toBe('true')
    expect(departure.attributes('inert')).toBeDefined()
    expect(departure.find('[data-id]').exists()).toBe(false)
    expect(departure.get('[aria-current="page"].nav-link').text()).toBe(
      'Overview',
    )
    expect(t.navigation.pageIdentity.get()).toBeUndefined()
    await vi.advanceTimersByTimeAsync(DEFAULT_SKELETON_DELAY_MS)
    await settle()
    expect(view.find('[data-id="hilos-page-skeleton"]').exists()).toBe(true)
    expect(view.find('nav[aria-label="User pages"]').exists()).toBe(false)
    expect(view.text()).not.toContain('Alice')

    t.answer({ name: 'Alice on Access' })
    await settle()
    expect(view.findAll('h1')).toHaveLength(1)
    expect(view.get('h1').text()).toBe('Alice on Access')
    expect(
      view
        .findAll('[data-id="hilos-breadcrumb"] li')
        .map((crumb) => crumb.text()),
    ).toEqual(['Hilos', 'Users', 'Alice on Access'])
    expect(
      view.get('nav[aria-label="User pages"] [aria-current="page"]').text(),
    ).toBe('Access')
    expect(view.get('[data-id="access-body"]').text()).toBe('Access content')
    expect(t.navigation.pageIdentity.get()?.pageHeadOwner?.page).toBe(
      HilosPages.USER,
    )
    expect(t.navigation.currentTitle.get()).toBe('Alice on Access · Test app')
    expect(t.listeners.size).toBe(1)

    await view.get('[data-id="hilos-page-tab-hilos_user"]').trigger('click')
    t.answer({ name: 'Back on Overview' })
    await settle()
    expect(view.get('[data-id="overview-body"]').text()).toBe(
      'Overview content',
    )
    expect(view.get('h1').text()).toBe('Back on Overview')
    expect(
      view.get('nav[aria-label="User pages"] [aria-current="page"]').text(),
    ).toBe('Overview')
    expect(t.listeners.size).toBe(1)
    view.unmount()
    expect(t.listeners.size).toBe(0)
    expect(t.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('User')
  })
})
