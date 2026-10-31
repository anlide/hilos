import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
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
  ScopeManager,
  SIGNAL_SECOND_FACTOR_POLICY,
  SIGNAL_SECOND_FACTOR_STATE,
  type ActionLifecycle,
  type HilosConnection,
  type HilosPageCrumb,
  type HilosProfileHeadStore,
  type HilosProfilePageContext,
  type HilosProfileSignInIdentitySource,
  type HilosSecondFactorState,
  type ProjectSignal,
  type SecondFactorPolicy,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { HilosProfileHead } from '../src/profile/HilosProfileHead.js'

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
const POLICY: SecondFactorPolicy = {
  enabled: true,
  required: 'none',
  trustDays: 30,
  backupCodes: 10,
  resetWaitDefaultDays: 8,
  resetWaitMinDays: 1,
  resetWaitMaxDays: 30,
}

let mounted: ComponentFixture<HilosProfileHead> | undefined
const cleanups: Array<() => void> = []

/** Render changes delivered by the core or by a component input. */
function update(): void {
  mounted?.detectChanges()
}

/** Destroy the view before releasing its connection and caller-owned stores. */
function unmount(): void {
  mounted?.destroy()
  mounted?.nativeElement.remove()
  mounted = undefined
}

afterEach(() => {
  unmount()
  for (const stop of cleanups.splice(0).reverse()) stop()
  document.body.innerHTML = ''
  vi.restoreAllMocks()
  TestBed.resetTestingModule()
})

/** A wire emitter beside the real session, head store, navigator and photo flow. */
function fixture(
  key: string = HilosPages.PROFILE,
  unbuilt: ReadonlySet<string> = new Set(),
) {
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      const group = listeners.get(event) ?? new Set()
      group.add(listener)
      listeners.set(event, group)
      return () => group.delete(listener)
    },
    emit(type: string, data: unknown): void {
      const signal = { kind: 'project', type, data } as ProjectSignal
      for (const listener of [...(listeners.get('projectSignal') ?? [])]) {
        listener(signal as never)
      }
      update()
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
  const dispatch = vi.fn()
  const context: HilosProfilePageContext = {
    connection: wire,
    scopes,
    actions: { dispatch } as unknown as ActionLifecycle,
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
    update()
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
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router }],
    })
    mounted = TestBed.createComponent(HilosProfileHead)
    mounted.componentRef.setInput('context', context)
    if (store !== undefined) mounted.componentRef.setInput('store', store)
    update()

    return mounted
  }

  return {
    context,
    scopes,
    connection,
    router,
    pushState,
    dispatch,
    session,
    answer,
    waysIn,
    mountHead,
  }
}

/** Find a stable hook in the real head or its photo modal. */
function byId(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/** The page's one h1, which carries the profile name. */
function pageHeading(): HTMLElement | null {
  return document.querySelector<HTMLElement>('h1')
}

/** The named navigation's links, in catalog order. */
function links(): HTMLAnchorElement[] {
  return [
    ...document.querySelectorAll<HTMLAnchorElement>(
      'nav[aria-label="Profile pages"] ul > li > a',
    ),
  ]
}

/** Open the existing photo window with the head's real trigger. */
async function openPhoto(): Promise<HTMLElement> {
  const opener = byId('profile-photo-open')!
  opener.focus()
  opener.click()
  update()
  await mounted!.whenStable()

  return opener
}

describe('HilosProfileHead', () => {
  it.each(TABS.map((tab) => tab.page))(
    'draws one named shared h1 on direct %s entry',
    (key) => {
      const f = fixture(key)
      f.answer()
      const head = f.mountHead()
      const root = head.nativeElement as HTMLElement

      expect(byId('profile-identity')).not.toBeNull()
      expect(byId('hilos-page-head')).not.toBeNull()
      expect(root.querySelectorAll('h1')).toHaveLength(1)
      expect(pageHeading()?.textContent?.trim()).toBe('Ann Lee')
      expect(pageHeading()?.id).toBeTruthy()
      expect(byId('hilos-breadcrumb')).toBeNull()
      expect(root.textContent).not.toContain('Profile')
      expect(byId('profile-identity-email')?.textContent?.trim()).toBe(
        'ann@example.test',
      )
      expect(f.dispatch).not.toHaveBeenCalled()
    },
  )

  it('uses one named link navigation in catalog order, with History itself current', () => {
    const f = fixture(HilosPages.PROFILE_AGREEMENTS_HISTORY)
    f.answer()
    f.mountHead()
    const nav = byId('hilos-page-tabs')!

    expect(document.querySelectorAll('nav')).toHaveLength(1)
    expect(nav.getAttribute('aria-label')).toBe('Profile pages')
    expect(links().map((link) => link.textContent?.trim())).toEqual([
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
    expect(links().map((link) => link.getAttribute('href'))).toEqual([
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
    expect(nav.querySelector('[role="tablist"], [role="tab"]')).toBeNull()
    expect(nav.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(
      nav.querySelector('[aria-current="page"]')?.textContent?.trim(),
    ).toBe('Revision history')
    expect(
      byId(`hilos-page-tab-${HilosPages.PROFILE_AGREEMENTS}`)?.getAttribute(
        'aria-current',
      ),
    ).toBeNull()
  })

  it('marks the project page itself and follows the normal navigator to Overview', () => {
    const f = fixture(PROJECT_PAGE)
    f.answer()
    f.mountHead()
    expect(
      byId(`hilos-page-tab-${PROJECT_PAGE}`)?.getAttribute('aria-current'),
    ).toBe('page')

    byId(`hilos-page-tab-${HilosPages.PROFILE}`)!.click()
    update()
    expect(f.pushState).toHaveBeenCalledWith('/profile')
    expect(f.router.currentRoute.get().page).toBe(HilosPages.PROFILE)
    expect(pageHeading()).toBeNull()
    f.answer()
    expect(
      byId(`hilos-page-tab-${HilosPages.PROFILE}`)?.getAttribute(
        'aria-current',
      ),
    ).toBe('page')
  })

  it('keeps catalog captions live and leaves no empty project place when none is declared', () => {
    const f = fixture()
    f.answer(TABS.slice(0, -1))
    f.mountHead()
    expect(links()).toHaveLength(9)
    expect(byId(`hilos-page-tab-${PROJECT_PAGE}`)).toBeNull()
    expect(byId('hilos-page-tabs')?.querySelectorAll('li')).toHaveLength(9)

    f.answer([
      { page: HilosPages.PROFILE, label: 'Catalog overview' },
      { page: PROJECT_PAGE, label: 'Catalog project caption' },
    ])
    expect(links().map((link) => link.textContent?.trim())).toEqual([
      'Catalog overview',
      'Catalog project caption',
    ])
  })

  it('drops tabs without a client route and hides a row with only one remaining link', () => {
    const f = fixture(
      HilosPages.PROFILE,
      new Set([HilosPages.PROFILE_SECURITY]),
    )
    f.answer([
      TABS[0]!,
      TABS[5]!,
      { page: 'project_profile_unrouted', label: 'Unrouted' },
    ])
    f.mountHead()
    expect(byId('hilos-page-tabs')).toBeNull()
    expect(pageHeading()?.textContent?.trim()).toBe('Ann Lee')
    expect(document.querySelector('a')).toBeNull()

    f.answer([TABS[0]!, TABS[1]!, TABS[5]!])
    expect(links().map((link) => link.textContent?.trim())).toEqual([
      'Overview',
      'Ways to sign in',
    ])
  })

  it('updates Security eligibility without navigating away from Security', () => {
    const f = fixture(HilosPages.PROFILE_SECURITY)
    f.answer()
    f.mountHead()
    const securityTab = () =>
      byId(`hilos-page-tab-${HilosPages.PROFILE_SECURITY}`)
    expect(securityTab()?.getAttribute('aria-current')).toBe('page')

    f.connection.emit(SIGNAL_SECOND_FACTOR_POLICY, {
      ...POLICY,
      enabled: false,
    })
    expect(securityTab()).toBeNull()
    expect(f.router.currentRoute.get().page).toBe(HilosPages.PROFILE_SECURITY)
    expect(f.pushState).not.toHaveBeenCalled()

    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...SECURITY,
      enabled: false,
      authenticators: [
        { id: 1, label: 'Phone', createdAt: 1, lastUsedAt: null },
      ],
    })
    expect(securityTab()?.getAttribute('aria-current')).toBe('page')
    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...SECURITY,
      enabled: false,
      reset: { requestedAt: 1, effectiveAt: 2 },
    })
    expect(securityTab()).not.toBeNull()
    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...SECURITY,
      enabled: false,
    })
    expect(securityTab()).toBeNull()
    f.connection.emit(SIGNAL_SECOND_FACTOR_POLICY, POLICY)
    expect(securityTab()).not.toBeNull()
  })

  it('replaces confirmed email with the live passkey-only warning and invents no address', () => {
    const f = fixture()
    f.answer()
    f.mountHead()
    const warning = () => byId('profile-sign-in-passkey-only-line')
    const email = () => byId('profile-identity-email')
    expect(warning()).toBeNull()
    expect(email()?.textContent?.trim()).toBe('ann@example.test')

    f.waysIn([PASSKEY])
    expect(warning()?.textContent?.trim()).toBe(
      HILOS_PROFILE_ROOT_COPY.passkeyOnly,
    )
    expect(warning()?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(email()).toBeNull()
    expect(
      warning()?.closest('[data-id="hilos-page-head-block"]'),
    ).not.toBeNull()
    expect(
      pageHeading()!.compareDocumentPosition(warning()!) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()

    f.waysIn([PASSKEY, { ...PASSWORD, verified: false }])
    expect(warning()).toBeNull()
    expect(email()).toBeNull()
    f.waysIn([PASSKEY, PASSWORD])
    expect(email()?.textContent?.trim()).toBe('ann@example.test')
    f.waysIn([PASSKEY])
    expect(warning()).not.toBeNull()
  })

  it('keeps live session name and photo on the same heading and decorative avatar', () => {
    const f = fixture()
    f.answer()
    f.mountHead()
    const heading = pageHeading()!
    const headingId = heading.id
    expect(byId('hilos-avatar')?.getAttribute('aria-hidden')).toBe('true')
    expect(byId('hilos-avatar-photo')?.getAttribute('alt')).toBe('')
    expect(byId('hilos-avatar-photo')?.getAttribute('src')).toBe('/ann.jpg')

    f.session('Bea Smith', '/bea.jpg')
    expect(pageHeading()).toBe(heading)
    expect(heading.id).toBe(headingId)
    expect(heading.textContent?.trim()).toBe('Bea Smith')
    expect(byId('hilos-avatar-photo')?.getAttribute('src')).toBe('/bea.jpg')
    f.session('Bea Smith', null)
    expect(byId('hilos-avatar-photo')).toBeNull()
    expect(byId('hilos-avatar')?.textContent?.trim()).toBe('BS')
  })

  it('opens the real named photo modal and returns focus to its accessible trigger', async () => {
    const f = fixture(HilosPages.PROFILE_AGREEMENTS_HISTORY)
    f.answer()
    f.mountHead()
    const opener = await openPhoto()
    expect(opener.tagName).toBe('BUTTON')
    expect(opener.getAttribute('aria-label')).toBe(
      HILOS_PROFILE_PHOTO_COPY.open,
    )
    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog.getAttribute('aria-label')).toBe(
      HILOS_PROFILE_PHOTO_COPY.title,
    )
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(document.activeElement).toBe(dialog)
    expect(byId('profile-photo-remove')).not.toBeNull()

    f.session('Ann Lee', null)
    expect(byId('profile-photo-remove')).toBeNull()
    expect(byId('profile-photo-choose')).not.toBeNull()
    byId('profile-photo-cancel')!.click()
    update()
    await mounted!.whenStable()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(opener)
    expect(f.dispatch).not.toHaveBeenCalled()
  })

  it('leaves the avatar noninteractive while photo is disabled and enables the old window live', async () => {
    const f = fixture()
    f.answer()
    ingest(f.scopes.page()!, { data: { profilePhoto: false } })
    f.mountHead()
    expect(byId('profile-photo-open')).toBeNull()
    expect(byId('profile-photo-live-assertive')).toBeNull()
    expect(byId('hilos-avatar')?.closest('button, a')).toBeNull()

    ingest(f.scopes.page()!, { data: { profilePhoto: true } })
    update()
    await openPhoto()
    expect(
      document.querySelector('[role="dialog"]')?.getAttribute('aria-label'),
    ).toBe(HILOS_PROFILE_PHOTO_COPY.title)
  })

  it('shows only the shared skeleton before an answer and drops the old head between scopes', () => {
    const f = fixture()
    const head = f.mountHead()
    const root = head.nativeElement as HTMLElement
    expect(byId('hilos-page-head-skeleton')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(root.querySelector('h1, nav, button, img')).toBeNull()
    expect(root.textContent?.trim()).toBe('')
    f.answer()
    expect(pageHeading()?.textContent?.trim()).toBe('Ann Lee')

    f.router.navigate('/profile/example')
    update()
    expect(root.querySelector('h1, nav, button, img')).toBeNull()
    expect(root.textContent?.trim()).toBe('')
    f.session('Next person', null)
    f.answer()
    expect(pageHeading()?.textContent?.trim()).toBe('Next person')
    expect(
      byId(`hilos-page-tab-${PROJECT_PAGE}`)?.getAttribute('aria-current'),
    ).toBe('page')
  })

  it('releases its owned store, name binding and open photo flow on destroy', async () => {
    const f = fixture()
    f.answer()
    f.mountHead()
    await openPhoto()
    expect(f.connection.listenerCount('projectSignal')).toBe(3)
    expect(f.connection.listenerCount('actionError')).toBe(1)
    expect(document.body.classList.contains('modal-open')).toBe(true)

    unmount()
    expect(f.connection.listenerCount('projectSignal')).toBe(1)
    expect(f.connection.listenerCount('actionError')).toBe(0)
    expect(pageIdentity(f.scopes).get()?.pageHeadOwner?.label).toBe('Profile')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('borrows the root store without taking its lifecycle and releases its view subscriptions', async () => {
    const f = fixture()
    f.answer()
    const name = createSignal('Project name')
    const store = createHilosProfileHeadStore(f.context, name)
    store.start()
    cleanups.push(() => store.dispose())
    const start = vi.spyOn(store, 'start')
    const dispose = vi.spyOn(store, 'dispose')
    f.mountHead(store)
    expect(pageHeading()?.textContent?.trim()).toBe('Project name')
    expect(start).not.toHaveBeenCalled()
    name.set('Project renamed')
    update()
    expect(pageHeading()?.textContent?.trim()).toBe('Project renamed')
    await openPhoto()
    unmount()
    name.set('After destroy')
    expect(dispose).not.toHaveBeenCalled()
    expect(f.connection.listenerCount('projectSignal')).toBe(2)
    expect(f.connection.listenerCount('actionError')).toBe(0)
    expect(pageIdentity(f.scopes).get()?.pageHeadOwner?.label).toBe(
      'After destroy',
    )
  })

  it('replaces a context and releases the old open flow before using the new account', async () => {
    const first = fixture()
    first.answer()
    const head = first.mountHead()
    await openPhoto()
    const next = fixture()
    next.session('Next person', null)
    next.answer()
    head.componentRef.setInput('context', next.context)
    update()
    await head.whenStable()

    expect(first.connection.listenerCount('projectSignal')).toBe(1)
    expect(first.connection.listenerCount('actionError')).toBe(0)
    expect(pageIdentity(first.scopes).get()?.pageHeadOwner?.label).toBe(
      'Profile',
    )
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(pageHeading()?.textContent?.trim()).toBe('Next person')
    first.session('Old account', '/old.jpg')
    expect(pageHeading()?.textContent?.trim()).toBe('Next person')
    expect(byId('hilos-avatar-photo')).toBeNull()
    await openPhoto()
    expect(byId('profile-photo-choose')).not.toBeNull()
    expect(byId('profile-photo-remove')).toBeNull()
    expect(next.connection.listenerCount('actionError')).toBe(1)
    unmount()
    expect(next.connection.listenerCount('projectSignal')).toBe(1)
    expect(next.connection.listenerCount('actionError')).toBe(0)
  })

  it('replaces a borrowed store without stopping either caller-owned store', async () => {
    const f = fixture()
    f.answer()
    const name = createSignal('First binding')
    const first = createHilosProfileHeadStore(f.context, name)
    first.start()
    cleanups.push(() => first.dispose())
    const head = f.mountHead(first)
    await openPhoto()
    const nextName = createSignal('Next binding')
    const next = createHilosProfileHeadStore(f.context, nextName)
    next.start()
    cleanups.push(() => next.dispose())
    const start = vi.spyOn(next, 'start')
    const disposeFirst = vi.spyOn(first, 'dispose')
    const disposeNext = vi.spyOn(next, 'dispose')
    head.componentRef.setInput('store', next)
    update()
    await head.whenStable()

    expect(disposeFirst).not.toHaveBeenCalled()
    expect(start).not.toHaveBeenCalled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(pageHeading()?.textContent?.trim()).toBe('Next binding')
    name.set('Old binding')
    nextName.set('Next renamed')
    update()
    expect(pageHeading()?.textContent?.trim()).toBe('Next renamed')
    await openPhoto()
    expect(byId('profile-photo-remove')).not.toBeNull()
    unmount()
    expect(disposeFirst).not.toHaveBeenCalled()
    expect(disposeNext).not.toHaveBeenCalled()
    expect(f.connection.listenerCount('actionError')).toBe(0)
  })
})
