import {
  ActionLifecycle,
  bindSessionScope,
  createAppPageRouter,
  createHilosProfileHeadStore,
  createHilosRouter,
  createSignal,
  HILOS_PROFILE_PHOTO_COPY,
  HILOS_PROFILE_ROOT_COPY,
  HilosPages,
  ingest,
  pageIdentity,
  PROFILE_PHOTO_REMOVE_ACTION,
  resolvePageTitle,
  ScopeManager,
  SIGNAL_SECOND_FACTOR_STATE,
  type HilosConnection,
  type HilosPageCrumb,
  type HilosProfileHeadStore,
  type HilosProfilePageContext,
  type HilosProfileSignInIdentitySource,
  type HilosSecondFactorState,
  type ProjectSignal,
} from '@hilos/core/base'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { markRaw, nextTick } from 'vue'
import HilosPageHead from '../HilosPageHead.vue'
import { hilosRouterKey } from '../hilosRouterKey.js'
import HilosProfileHead from './HilosProfileHead.vue'
import HilosProfilePhoto from './HilosProfilePhoto.vue'

const PROJECT_PAGE = 'project_profile_example'
const TABS: readonly HilosPageCrumb[] = [
  { page: HilosPages.PROFILE, label: 'Overview' },
  { page: HilosPages.PROFILE_SIGN_IN, label: 'Ways to sign in' },
  { page: HilosPages.PROFILE_NOTIFICATIONS, label: 'Notifications' },
  { page: HilosPages.PROFILE_SESSIONS, label: 'Sessions' },
  { page: HilosPages.PROFILE_DEVICES, label: 'Devices' },
  { page: HilosPages.PROFILE_SECURITY, label: 'Security' },
  { page: HilosPages.PROFILE_AGREEMENTS, label: 'Agreements' },
  { page: HilosPages.PROFILE_AGREEMENTS_HISTORY, label: 'Revision history' },
  { page: HilosPages.PROFILE_DATA, label: 'Your data' },
  { page: PROJECT_PAGE, label: 'Project example' },
]
const SECURITY: HilosSecondFactorState = {
  enabled: true,
  authenticators: [],
  backupCodesLeft: 0,
  backupCodesTotal: 0,
  required: false,
  resetWait: {
    days: 8,
    pendingDays: null,
    pendingFrom: null,
    defaultDays: 8,
    minDays: 1,
    maxDays: 30,
  },
  reset: null,
}
const PASSWORD: HilosProfileSignInIdentitySource = {
  id: 1,
  type: 'password',
  provider: null,
  identifier: 'ann@example.test',
  verified: true,
}
const PASSKEY: HilosProfileSignInIdentitySource = {
  id: 2,
  type: 'passkey',
  provider: null,
  identifier: 'opaque',
  verified: true,
}

const mounted = new Set<VueWrapper>()
const cleanups: Array<() => void> = []
afterEach(() => {
  for (const wrapper of mounted) wrapper.unmount()
  mounted.clear()
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
  document.body.innerHTML = ''
  vi.restoreAllMocks()
})

/** A connection emitter beside the real session, profile store and navigator. */
function fixture(
  key: string = HilosPages.PROFILE,
  unbuilt: ReadonlySet<string> = new Set(),
) {
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const emitEvent = (event: string, payload: unknown): void => {
    for (const listener of [...(listeners.get(event) ?? [])]) {
      listener(payload as never)
    }
  }
  const connection = {
    sendAction: vi.fn(() => true),
    on(event: string, listener: (payload: never) => void): () => void {
      const group = listeners.get(event) ?? new Set()
      group.add(listener)
      listeners.set(event, group)
      return () => {
        group.delete(listener)
      }
    },
    emit(type: string, data: unknown): void {
      const signal = { kind: 'project', type, data } as ProjectSignal
      emitEvent('projectSignal', signal)
    },
    listenerCount: (event: string) => listeners.get(event)?.size ?? 0,
  }
  const wire = connection as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(wire, scopes)
  const session = (name = 'Ann Lee', photo: string | null = '/ann.jpg') => {
    connection.emit('handshake_response', {
      entities: { currentUser: { id: 7, name, photo } },
    })
  }
  session()
  const routes = createAppPageRouter({
    [PROJECT_PAGE]: { path: '/profile/example', admin: false },
  })
  const pushState = vi.fn()
  const router = createHilosRouter(
    routes,
    {
      subscribe: vi.fn((page) => scopes.openPage(page)),
      unsubscribe: () => scopes.dropPage(),
      refuseUnbuilt: vi.fn(),
      pageError: createSignal(null),
      pageLoading: createSignal(false),
      pageIdentity: pageIdentity(scopes),
      dashboardSections: createSignal(undefined),
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
    },
    {
      pathname: () => routes.path(key)!,
      pushState,
      replaceState: () => {},
      onPopState: () => () => {},
    },
    undefined,
    unbuilt,
  )
  router.start()
  cleanups.push(() => router.stop())
  const actions = new ActionLifecycle(wire)
  const dispatch = vi.spyOn(actions, 'dispatch')
  cleanups.push(() => emitEvent('state', 'disconnected'))
  const context: HilosProfilePageContext = {
    connection: wire,
    scopes,
    actions,
  }
  const waysIn = (identities: readonly HilosProfileSignInIdentitySource[]) => {
    ingest(scopes.page()!, {
      lists: {
        profileIdentities: {
          items: [
            { itemKey: 7, slots: { identities, passkeyCredentials: [] } },
          ],
        },
      },
    })
  }
  const answer = (tabs = TABS) => {
    ingest(scopes.page()!, {
      data: {
        pageLabel: 'Current profile section',
        pageLead: 'Its catalog lead',
        pageHeadOwner: {
          page: HilosPages.PROFILE,
          label: 'Profile',
          lead: 'Your account',
          icon: 'bi-person',
        },
        pageBreadcrumb: [{ page: HilosPages.PROFILE, label: 'Profile' }],
        pageTabs: tabs,
        pageChildren: [],
        profilePhoto: true,
        secondFactor: SECURITY,
      },
    })
    waysIn([PASSWORD, PASSKEY])
  }
  const mountHead = (store?: HilosProfileHeadStore) => {
    const wrapper = mount(HilosProfileHead, {
      props: { context, store },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router } },
    })
    mounted.add(wrapper)
    return wrapper
  }

  return {
    context,
    scopes,
    connection,
    router,
    pushState,
    dispatch,
    emitEvent,
    session,
    answer,
    waysIn,
    mountHead,
  }
}

function unmountHead(wrapper: VueWrapper): void {
  mounted.delete(wrapper)
  wrapper.unmount()
}

function byId(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

describe('HilosProfileHead', () => {
  it.each(TABS.map((tab) => tab.page))(
    'draws the one named shared h1 on direct %s entry',
    (key) => {
      const f = fixture(key)
      f.answer()
      const head = f.mountHead()

      expect(head.findComponent(HilosPageHead).exists()).toBe(true)
      expect(head.attributes('data-id')).toBe('profile-identity')
      expect(head.findAll('h1')).toHaveLength(1)
      const heading = head.get('[data-id="profile-identity-name"]')
      expect(heading.element.tagName).toBe('H1')
      expect(heading.text()).toBe('Ann Lee')
      expect(heading.attributes('id')).toBeTruthy()
      expect(head.find('[data-id="hilos-page-head-title"]').exists()).toBe(
        false,
      )
      expect(head.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
      expect(head.text()).not.toContain('Profile')
      expect(head.get('[data-id="profile-identity-email"]').text()).toBe(
        'ann@example.test',
      )
      expect(f.dispatch).not.toHaveBeenCalled()
    },
  )

  it('uses one named navigation row in catalog order, including History and the project page', () => {
    const f = fixture(HilosPages.PROFILE_AGREEMENTS_HISTORY)
    f.answer()
    const head = f.mountHead()
    const nav = head.get('nav')

    expect(head.findAll('nav')).toHaveLength(1)
    expect(nav.attributes('data-id')).toBe('hilos-page-tabs')
    expect(nav.attributes('aria-label')).toBe('Profile pages')
    expect(nav.findAll('ul > li > a').map((link) => link.text())).toEqual([
      'Overview',
      'Ways to sign in',
      'Notifications',
      'Sessions',
      'Devices',
      'Security',
      'Agreements',
      'Revision history',
      'Your data',
      'Project example',
    ])
    expect(nav.findAll('a').map((link) => link.attributes('href'))).toEqual([
      '/profile',
      '/profile/sign-in',
      '/profile/notifications',
      '/profile/sessions',
      '/profile/devices',
      '/profile/security',
      '/profile/agreements',
      '/profile/agreements/history',
      '/profile/data',
      '/profile/example',
    ])
    expect(head.find('[role="tablist"], [role="tab"]').exists()).toBe(false)
    expect(nav.findAll('[aria-current="page"]')).toHaveLength(1)
    expect(nav.get('[aria-current="page"]').text()).toBe('Revision history')
    expect(
      head
        .get(`[data-id="hilos-page-tab-${HilosPages.PROFILE_AGREEMENTS}"]`)
        .attributes('aria-current'),
    ).toBeUndefined()
  })

  it('marks the project page itself and follows a tab through the normal navigator', async () => {
    const f = fixture(PROJECT_PAGE)
    f.answer()
    const head = f.mountHead()
    expect(head.get('[aria-current="page"]').text()).toBe('Project example')
    await head
      .get(`[data-id="hilos-page-tab-${HilosPages.PROFILE}"]`)
      .trigger('click')
    expect(f.pushState).toHaveBeenCalledWith('/profile')
    expect(f.router.currentRoute.get().page).toBe(HilosPages.PROFILE)
    expect(head.find('h1').exists()).toBe(false)
    f.answer()
    await nextTick()
    expect(head.get('[aria-current="page"]').text()).toBe('Overview')
  })

  it('keeps catalog captions live and leaves no empty project place when none is declared', async () => {
    const f = fixture()
    f.answer(TABS.slice(0, -1))
    const head = f.mountHead()
    expect(head.findAll('nav li')).toHaveLength(9)
    expect(
      head.find(`[data-id="hilos-page-tab-${PROJECT_PAGE}"]`).exists(),
    ).toBe(false)
    expect(
      head.findAll('nav li').every((item) => item.find('a').exists()),
    ).toBe(true)

    f.answer([
      { page: HilosPages.PROFILE, label: 'Catalog overview' },
      { page: PROJECT_PAGE, label: 'Catalog project caption' },
    ])
    await nextTick()
    expect(head.findAll('nav a').map((link) => link.text())).toEqual([
      'Catalog overview',
      'Catalog project caption',
    ])
  })

  it('drops tabs without a client route and counts the one remaining link after filtering', async () => {
    const f = fixture(
      HilosPages.PROFILE,
      new Set([HilosPages.PROFILE_SECURITY]),
    )
    f.answer([
      TABS[0],
      TABS[5],
      { page: 'project_profile_unrouted', label: 'Unrouted' },
    ])
    const head = f.mountHead()
    expect(head.find('nav').exists()).toBe(false)
    expect(head.get('h1').text()).toBe('Ann Lee')
    expect(head.find('a').exists()).toBe(false)

    f.answer([TABS[0], TABS[1], TABS[5]])
    await nextTick()
    expect(head.findAll('nav a').map((link) => link.text())).toEqual([
      'Overview',
      'Ways to sign in',
    ])
  })

  it('updates Security eligibility without navigating away from its current page', async () => {
    const f = fixture(HilosPages.PROFILE_SECURITY)
    f.answer()
    const head = f.mountHead()
    const securityTab = () =>
      head.find(`[data-id="hilos-page-tab-${HilosPages.PROFILE_SECURITY}"]`)
    expect(securityTab().attributes('aria-current')).toBe('page')

    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...SECURITY,
      enabled: false,
    })
    await nextTick()
    expect(securityTab().exists()).toBe(false)
    expect(f.router.currentRoute.get().page).toBe(HilosPages.PROFILE_SECURITY)
    expect(f.pushState).not.toHaveBeenCalled()

    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...SECURITY,
      enabled: false,
      authenticators: [
        { id: 1, label: 'Phone', createdAt: 1, lastUsedAt: null },
      ],
    })
    await nextTick()
    expect(securityTab().attributes('aria-current')).toBe('page')
    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...SECURITY,
      enabled: false,
      reset: { requestedAt: 1, effectiveAt: 2 },
    })
    await nextTick()
    expect(securityTab().exists()).toBe(true)
    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, SECURITY)
    await nextTick()
    expect(securityTab().exists()).toBe(true)
  })

  it('replaces confirmed email with the live passkey-only warning and invents no address', async () => {
    const f = fixture()
    f.answer()
    const head = f.mountHead()
    const warning = () =>
      head.find('[data-id="profile-sign-in-passkey-only-line"]')
    const email = () => head.find('[data-id="profile-identity-email"]')
    expect(warning().exists()).toBe(false)
    expect(email().text()).toBe('ann@example.test')

    f.waysIn([PASSKEY])
    await nextTick()
    expect(warning().text()).toBe(HILOS_PROFILE_ROOT_COPY.passkeyOnly)
    expect(warning().get('i').attributes('aria-hidden')).toBe('true')
    expect(email().exists()).toBe(false)
    expect(
      warning().element.closest('[data-id="hilos-page-head-block"]'),
    ).not.toBeNull()
    expect(
      head
        .get('[data-id="profile-identity-name"]')
        .element.compareDocumentPosition(warning().element) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()

    f.waysIn([PASSKEY, { ...PASSWORD, verified: false }])
    await nextTick()
    expect(warning().exists()).toBe(false)
    expect(email().exists()).toBe(false)
    f.waysIn([PASSKEY, PASSWORD])
    await nextTick()
    expect(email().text()).toBe('ann@example.test')
    f.waysIn([PASSKEY])
    await nextTick()
    expect(warning().exists()).toBe(true)
  })

  it('keeps live session name and photo on the same heading and decorative avatar', async () => {
    const f = fixture()
    f.answer()
    const head = f.mountHead()
    const heading = head.get('h1').element
    const headingId = heading.id
    expect(head.get('[data-id="hilos-avatar"]').attributes('aria-hidden')).toBe(
      'true',
    )
    expect(head.get('img').attributes('alt')).toBe('')
    expect(head.get('img').attributes('src')).toBe('/ann.jpg')

    f.session('Bea Smith', '/bea.jpg')
    await nextTick()
    expect(head.get('[data-id="profile-identity-name"]').element).toBe(heading)
    expect(heading.id).toBe(headingId)
    expect(heading.textContent?.trim()).toBe('Bea Smith')
    expect(head.get('img').attributes('src')).toBe('/bea.jpg')
    f.session('Bea Smith', null)
    await nextTick()
    expect(head.find('img').exists()).toBe(false)
    expect(head.get('[data-id="hilos-avatar"]').text()).toBe('BS')
  })

  it('opens the existing named photo modal and returns focus to its native opener', async () => {
    const f = fixture(HilosPages.PROFILE_AGREEMENTS_HISTORY)
    f.answer()
    const head = f.mountHead()
    const opener = head.get<HTMLButtonElement>('[data-id="profile-photo-open"]')
    expect(opener.element.tagName).toBe('BUTTON')
    expect(opener.attributes('aria-label')).toBe(HILOS_PROFILE_PHOTO_COPY.open)
    opener.element.focus()
    await opener.trigger('click')
    await flushPromises()

    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog).not.toBeNull()
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(dialog.getAttribute('aria-label')).toBe(
      HILOS_PROFILE_PHOTO_COPY.title,
    )
    expect(document.activeElement).toBe(dialog)
    expect(byId('profile-photo-remove')).not.toBeNull()
    byId('profile-photo-cancel')!.click()
    await flushPromises()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(opener.element)
    expect(f.dispatch).not.toHaveBeenCalled()
  })

  it('leaves the avatar noninteractive while the photo feature is off', async () => {
    const f = fixture()
    f.answer()
    ingest(f.scopes.page()!, { data: { profilePhoto: false } })
    const head = f.mountHead()
    expect(head.find('[data-id="profile-photo-open"]').exists()).toBe(false)
    expect(head.findComponent(HilosProfilePhoto).exists()).toBe(false)
    expect(
      head.get('[data-id="hilos-avatar"]').element.closest('button, a'),
    ).toBeNull()

    ingest(f.scopes.page()!, { data: { profilePhoto: true } })
    await nextTick()
    expect(head.find('[data-id="profile-photo-open"]').exists()).toBe(true)
    expect(head.findComponent(HilosProfilePhoto).exists()).toBe(true)
  })

  it('shows only the shared skeleton before an answer and drops the old head between pages', async () => {
    const f = fixture()
    const head = f.mountHead()
    expect(
      head
        .get('[data-id="hilos-page-head-skeleton"]')
        .attributes('aria-hidden'),
    ).toBe('true')
    expect(head.find('h1, nav, button').exists()).toBe(false)
    expect(head.text()).toBe('')
    f.answer()
    await nextTick()
    expect(head.get('h1').text()).toBe('Ann Lee')
    f.router.navigate('/profile/example')
    await nextTick()
    expect(head.find('h1, nav, button, img').exists()).toBe(false)
    expect(head.text()).toBe('')
    f.session('Next person', null)
    f.answer()
    await nextTick()
    expect(head.get('h1').text()).toBe('Next person')
    expect(head.get('[aria-current="page"]').text()).toBe('Project example')
  })

  it('keeps an open photo flow through rerenders and live facts with the same inputs', async () => {
    const f = fixture()
    f.answer()
    const head = f.mountHead()
    const flow = head.getComponent(HilosProfilePhoto).props('flow')
    const dispose = vi.spyOn(flow, 'dispose')
    await head.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()
    const dialog = document.querySelector('[role="dialog"]')

    await head.setProps({ context: f.context, store: undefined })
    head.vm.$forceUpdate()
    f.session('Bea Smith', '/bea.jpg')
    f.waysIn([{ ...PASSWORD, identifier: 'bea@example.test' }, PASSKEY])
    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...SECURITY,
      enabled: false,
    })
    await flushPromises()

    expect(head.getComponent(HilosProfilePhoto).props('flow')).toBe(flow)
    expect(document.querySelector('[role="dialog"]')).toBe(dialog)
    expect(flow.step.get()).toBe('current')
    expect(dispose).not.toHaveBeenCalled()
    expect(head.get('h1').text()).toBe('Bea Smith')
    expect(head.get('img').attributes('src')).toBe('/bea.jpg')
    expect(dialog?.querySelector('img')?.getAttribute('src')).toBe('/bea.jpg')
    expect(head.get('[data-id="profile-identity-email"]').text()).toBe(
      'bea@example.test',
    )
    expect(
      head
        .find(`[data-id="hilos-page-tab-${HilosPages.PROFILE_SECURITY}"]`)
        .exists(),
    ).toBe(false)
    expect(f.connection.listenerCount('projectSignal')).toBe(3)
    unmountHead(head)
    expect(dispose).toHaveBeenCalledOnce()
  })

  it('replaces an owned context at the same address and sends photo removal through the new actions', async () => {
    const first = fixture()
    first.answer()
    const head = first.mountHead()
    const firstFlow = head.getComponent(HilosProfilePhoto).props('flow')
    const disposeFirst = vi.spyOn(firstFlow, 'dispose')
    await head.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()

    const next = fixture()
    next.session('Bea Smith', '/bea.jpg')
    next.answer([{ ...TABS[0], label: 'Next overview' }, TABS[1]])
    next.waysIn([{ ...PASSWORD, identifier: 'bea@example.test' }])
    await head.setProps({ context: next.context })
    await flushPromises()

    expect(disposeFirst).toHaveBeenCalledOnce()
    expect(firstFlow.step.get()).toBe('closed')
    expect(first.connection.listenerCount('projectSignal')).toBe(1)
    expect(first.connection.listenerCount('actionError')).toBe(1)
    expect(pageIdentity(first.scopes).get()?.pageHeadOwner?.label).toBe(
      'Profile',
    )
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(head.get('h1').text()).toBe('Bea Smith')
    expect(head.get('img').attributes('src')).toBe('/bea.jpg')
    expect(head.get('[data-id="profile-identity-email"]').text()).toBe(
      'bea@example.test',
    )
    expect(head.get('[aria-current="page"]').text()).toBe('Next overview')

    first.session('Old account', '/old.jpg')
    next.session('Cora Smith', '/cora.jpg')
    next.waysIn([{ ...PASSWORD, identifier: 'cora@example.test' }])
    await nextTick()
    expect(head.get('h1').text()).toBe('Cora Smith')
    expect(head.get('img').attributes('src')).toBe('/cora.jpg')
    expect(head.get('[data-id="profile-identity-email"]').text()).toBe(
      'cora@example.test',
    )

    const nextFlow = head.getComponent(HilosProfilePhoto).props('flow')
    const disposeNext = vi.spyOn(nextFlow, 'dispose')
    expect(nextFlow).not.toBe(firstFlow)
    firstFlow.open()
    expect(firstFlow.step.get()).toBe('closed')
    await head.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()
    expect(
      document.querySelector('[role="dialog"] img')?.getAttribute('src'),
    ).toBe('/cora.jpg')
    byId('profile-photo-remove')!.click()
    await nextTick()
    expect(next.dispatch).toHaveBeenCalledExactlyOnceWith(
      PROFILE_PHOTO_REMOVE_ACTION,
      {},
    )
    expect(first.dispatch).not.toHaveBeenCalled()
    expect(nextFlow.busy.get()).toBe(true)
    const requestId = next.dispatch.mock.results[0].value.requestId
    expect(next.connection.sendAction).toHaveBeenCalledExactlyOnceWith(
      PROFILE_PHOTO_REMOVE_ACTION,
      {},
      requestId,
    )
    next.emitEvent('actionSuccess', {
      action: PROFILE_PHOTO_REMOVE_ACTION,
      requestId,
    })
    await flushPromises()
    expect(nextFlow.step.get()).toBe('current')
    next.session('Cora Smith', null)
    await flushPromises()
    expect(nextFlow.step.get()).toBe('closed')
    expect(nextFlow.busy.get()).toBe(false)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(head.find('img').exists()).toBe(false)

    unmountHead(head)
    expect(disposeNext).toHaveBeenCalledOnce()
    expect(next.connection.listenerCount('projectSignal')).toBe(1)
    expect(next.connection.listenerCount('actionError')).toBe(1)
    expect(pageIdentity(next.scopes).get()?.pageHeadOwner?.label).toBe(
      'Profile',
    )
  })

  it('replaces a borrowed store alone and releases the old view mirrors and photo flow', async () => {
    const f = fixture()
    f.answer()
    f.session('Session name', null)
    const firstName = createSignal('Borrowed Ann')
    const readFirstName = vi.fn(() => firstName.get())
    const first = createHilosProfileHeadStore(f.context, { get: readFirstName })
    first.start()
    cleanups.push(() => first.dispose())
    const startFirst = vi.spyOn(first, 'start')
    const disposeFirst = vi.spyOn(first, 'dispose')
    const head = f.mountHead(first)
    const firstFlow = head.getComponent(HilosProfilePhoto).props('flow')
    const disposeFirstFlow = vi.spyOn(firstFlow, 'dispose')
    expect(head.get('[data-id="hilos-avatar"]').text()).toBe('BA')
    await head.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()

    const nextContext = fixture()
    nextContext.session('Next session', '/bea.jpg')
    nextContext.answer([{ ...TABS[0], label: 'Borrowed overview' }, TABS[1]])
    nextContext.waysIn([{ ...PASSWORD, identifier: 'bea@example.test' }])
    const nextName = createSignal('Borrowed Bea')
    const next = createHilosProfileHeadStore(nextContext.context, nextName)
    next.start()
    cleanups.push(() => next.dispose())
    const startNext = vi.spyOn(next, 'start')
    const disposeNext = vi.spyOn(next, 'dispose')
    await head.setProps({ store: next })
    await flushPromises()
    expect(disposeFirstFlow).toHaveBeenCalledOnce()
    expect(firstFlow.step.get()).toBe('closed')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(head.get('h1').text()).toBe('Borrowed Bea')
    expect(head.get('img').attributes('src')).toBe('/bea.jpg')
    expect(head.get('[data-id="profile-identity-email"]').text()).toBe(
      'bea@example.test',
    )
    expect(head.get('[aria-current="page"]').text()).toBe('Borrowed overview')
    readFirstName.mockClear()
    firstName.set('Old binding')
    nextName.set('Borrowed Cora')
    f.session('Old session', '/old.jpg')
    nextContext.session('Next renamed', '/cora.jpg')
    await nextTick()
    expect(readFirstName).not.toHaveBeenCalled()
    expect(head.get('h1').text()).toBe('Borrowed Cora')
    expect(head.get('img').attributes('src')).toBe('/cora.jpg')
    const nextFlow = head.getComponent(HilosProfilePhoto).props('flow')
    const disposeNextFlow = vi.spyOn(nextFlow, 'dispose')
    expect(nextFlow).not.toBe(firstFlow)
    await head.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()
    expect(nextFlow.step.get()).toBe('current')
    expect(byId('profile-photo-remove')).not.toBeNull()

    unmountHead(head)
    expect(disposeNextFlow).toHaveBeenCalledOnce()
    expect(startFirst).not.toHaveBeenCalled()
    expect(startNext).not.toHaveBeenCalled()
    expect(disposeFirst).not.toHaveBeenCalled()
    expect(disposeNext).not.toHaveBeenCalled()
    expect(f.connection.listenerCount('projectSignal')).toBe(2)
    expect(f.connection.listenerCount('actionError')).toBe(1)
    expect(nextContext.connection.listenerCount('projectSignal')).toBe(2)
    expect(nextContext.connection.listenerCount('actionError')).toBe(1)
  })

  it('switches between owned and borrowed stores without taking the caller lifecycle', async () => {
    const own = fixture()
    own.answer()
    const head = own.mountHead()
    const ownFlow = head.getComponent(HilosProfilePhoto).props('flow')
    await head.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()
    const borrowed = fixture()
    borrowed.session('Borrowed person', '/borrowed.jpg')
    borrowed.answer()
    const store = createHilosProfileHeadStore(borrowed.context)
    store.start()
    cleanups.push(() => store.dispose())
    const start = vi.spyOn(store, 'start')
    const dispose = vi.spyOn(store, 'dispose')

    await head.setProps({ context: borrowed.context, store })
    await flushPromises()
    expect(ownFlow.step.get()).toBe('closed')
    expect(own.connection.listenerCount('projectSignal')).toBe(1)
    expect(own.connection.listenerCount('actionError')).toBe(1)
    expect(pageIdentity(own.scopes).get()?.pageHeadOwner?.label).toBe('Profile')
    expect(head.get('h1').text()).toBe('Borrowed person')
    expect(head.get('img').attributes('src')).toBe('/borrowed.jpg')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    const borrowedFlow = head.getComponent(HilosProfilePhoto).props('flow')
    const disposeBorrowedFlow = vi.spyOn(borrowedFlow, 'dispose')
    await head.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()

    await head.setProps({ context: own.context, store: undefined })
    await flushPromises()
    expect(disposeBorrowedFlow).toHaveBeenCalledOnce()
    expect(borrowedFlow.step.get()).toBe('closed')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(borrowed.connection.listenerCount('projectSignal')).toBe(2)
    expect(borrowed.connection.listenerCount('actionError')).toBe(1)
    expect(pageIdentity(borrowed.scopes).get()?.pageHeadOwner?.label).toBe(
      'Borrowed person',
    )
    expect(own.connection.listenerCount('projectSignal')).toBe(2)
    expect(head.get('h1').text()).toBe('Ann Lee')
    expect(head.get('img').attributes('src')).toBe('/ann.jpg')
    const nextOwnFlow = head.getComponent(HilosProfilePhoto).props('flow')
    expect(nextOwnFlow).not.toBe(ownFlow)
    await head.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()
    expect(nextOwnFlow.step.get()).toBe('current')
    unmountHead(head)
    expect(nextOwnFlow.step.get()).toBe('closed')
    expect(own.connection.listenerCount('projectSignal')).toBe(1)
    expect(own.connection.listenerCount('actionError')).toBe(1)
    expect(pageIdentity(own.scopes).get()?.pageHeadOwner?.label).toBe('Profile')
    expect(start).not.toHaveBeenCalled()
    expect(dispose).not.toHaveBeenCalled()
  })

  it.each(['session', 'project'] as const)(
    'returns to the same caller-owned %s name on the same scopes',
    async (source) => {
      const f = fixture()
      f.answer()
      // Test Utils makes props deeply reactive; keep the caller's scopes and
      // ActionLifecycle identities intact across all three selections.
      markRaw(f.context)
      const projectName = createSignal('Borrowed Ann')
      const readName = vi.fn(() => projectName.get())
      const store = markRaw(
        createHilosProfileHeadStore(
          f.context,
          source === 'project' ? { get: readName } : undefined,
        ),
      )
      store.start()
      cleanups.push(() => store.dispose())
      const start = vi.spyOn(store, 'start')
      const dispose = vi.spyOn(store, 'dispose')
      const head = f.mountHead(store)
      const assertName = (expected: string) => {
        expect(head.props('context')).toBe(f.context)
        expect(head.props('context').scopes).toBe(f.scopes)
        expect(head.findAll('h1')).toHaveLength(1)
        expect(head.get('[data-id="profile-identity-name"]').text()).toBe(
          expected,
        )
        const identity = pageIdentity(f.scopes).get()
        expect(identity?.pageHeadOwner?.label).toBe(expected)
        expect(
          resolvePageTitle(
            HilosPages.PROFILE,
            {},
            'Example',
            identity?.label,
            true,
            identity?.pageHeadOwner?.label,
          ),
        ).toBe(`${expected} · Example`)
      }
      assertName(source === 'project' ? 'Borrowed Ann' : 'Ann Lee')
      const borrowedFlow = head.getComponent(HilosProfilePhoto).props('flow')
      const disposeBorrowedFlow = vi.spyOn(borrowedFlow, 'dispose')
      await head.get('[data-id="profile-photo-open"]').trigger('click')
      await flushPromises()

      await head.setProps({ store: undefined })
      await flushPromises()
      assertName('Ann Lee')
      expect(disposeBorrowedFlow).toHaveBeenCalledOnce()
      expect(borrowedFlow.step.get()).toBe('closed')
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(f.connection.listenerCount('projectSignal')).toBe(3)
      expect(f.connection.listenerCount('actionError')).toBe(2)
      readName.mockClear()
      projectName.set('Borrowed Bea')
      f.session('Bea Smith', '/bea.jpg')
      await nextTick()
      expect(readName).not.toHaveBeenCalled()
      assertName('Bea Smith')
      const ownFlow = head.getComponent(HilosProfilePhoto).props('flow')
      const disposeOwnFlow = vi.spyOn(ownFlow, 'dispose')
      await head.get('[data-id="profile-photo-open"]').trigger('click')
      await flushPromises()

      await head.setProps({ store })
      await flushPromises()
      assertName(source === 'project' ? 'Borrowed Bea' : 'Bea Smith')
      expect(disposeOwnFlow).toHaveBeenCalledOnce()
      expect(ownFlow.step.get()).toBe('closed')
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(f.connection.listenerCount('projectSignal')).toBe(2)
      expect(f.connection.listenerCount('actionError')).toBe(2)
      projectName.set('Borrowed Cora')
      f.session('Cora Jones', '/cora.jpg')
      await nextTick()
      assertName(source === 'project' ? 'Borrowed Cora' : 'Cora Jones')
      expect(head.get('img').attributes('src')).toBe('/cora.jpg')
      const returnedFlow = head.getComponent(HilosProfilePhoto).props('flow')
      const disposeReturnedFlow = vi.spyOn(returnedFlow, 'dispose')
      expect(returnedFlow).not.toBe(borrowedFlow)
      expect(returnedFlow).not.toBe(ownFlow)
      await head.get('[data-id="profile-photo-open"]').trigger('click')
      await flushPromises()
      expect(returnedFlow.step.get()).toBe('current')

      unmountHead(head)
      expect(disposeReturnedFlow).toHaveBeenCalledOnce()
      expect(returnedFlow.step.get()).toBe('closed')
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(f.connection.listenerCount('projectSignal')).toBe(2)
      expect(f.connection.listenerCount('actionError')).toBe(1)
      expect(start).not.toHaveBeenCalled()
      expect(dispose).not.toHaveBeenCalled()
      expect(pageIdentity(f.scopes).get()?.pageHeadOwner?.label).toBe(
        source === 'project' ? 'Borrowed Cora' : 'Cora Jones',
      )
      store.dispose()
      expect(dispose).toHaveBeenCalledOnce()
      expect(f.connection.listenerCount('projectSignal')).toBe(1)
      expect(f.connection.listenerCount('actionError')).toBe(1)
      expect(pageIdentity(f.scopes).get()?.pageHeadOwner?.label).toBe('Profile')
      projectName.set('After disposal')
      f.session('After disposal', null)
      expect(pageIdentity(f.scopes).get()?.pageHeadOwner?.label).toBe('Profile')
    },
  )

  it('releases its owned store, name binding and open photo flow on unmount', async () => {
    const f = fixture()
    f.answer()
    const head = f.mountHead()
    const photoFlow = head.getComponent(HilosProfilePhoto).props('flow')
    await head.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()
    expect(f.connection.listenerCount('projectSignal')).toBe(3)
    expect(f.connection.listenerCount('actionError')).toBe(2)
    expect(document.body.classList.contains('modal-open')).toBe(true)

    unmountHead(head)
    expect(photoFlow.step.get()).toBe('closed')
    expect(f.connection.listenerCount('projectSignal')).toBe(1)
    expect(f.connection.listenerCount('actionError')).toBe(1)
    expect(pageIdentity(f.scopes).get()?.pageHeadOwner?.label).toBe('Profile')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('borrows the root store without taking its lifecycle and creates a fresh photo flow per mount', async () => {
    const f = fixture()
    f.answer()
    const name = createSignal('Project name')
    const store = createHilosProfileHeadStore(f.context, name)
    store.start()
    cleanups.push(() => store.dispose())
    const start = vi.spyOn(store, 'start')
    const dispose = vi.spyOn(store, 'dispose')
    const first = f.mountHead(store)
    const firstFlow = first.getComponent(HilosProfilePhoto).props('flow')
    expect(first.get('h1').text()).toBe('Project name')
    expect(start).not.toHaveBeenCalled()
    await first.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()
    unmountHead(first)
    expect(dispose).not.toHaveBeenCalled()
    expect(firstFlow.step.get()).toBe('closed')
    expect(f.connection.listenerCount('projectSignal')).toBe(2)
    expect(f.connection.listenerCount('actionError')).toBe(1)

    name.set('Project renamed')
    const second = f.mountHead(store)
    expect(second.get('h1').text()).toBe('Project renamed')
    const secondFlow = second.getComponent(HilosProfilePhoto).props('flow')
    expect(secondFlow).not.toBe(firstFlow)
    await second.get('[data-id="profile-photo-open"]').trigger('click')
    await flushPromises()
    expect(secondFlow.step.get()).toBe('current')
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()
    expect(start).not.toHaveBeenCalled()
    unmountHead(second)
    expect(dispose).not.toHaveBeenCalled()
    expect(f.connection.listenerCount('actionError')).toBe(1)
    expect(pageIdentity(f.scopes).get()?.pageHeadOwner?.label).toBe(
      'Project renamed',
    )
  })
})
