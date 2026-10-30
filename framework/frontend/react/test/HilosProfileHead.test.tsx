import { StrictMode, useLayoutEffect, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
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
  SIGNAL_SECOND_FACTOR_STATE,
  type ActionLifecycle,
  type HilosConnection,
  type HilosPageCrumb,
  type HilosProfileHeadStore,
  type HilosProfilePageContext,
  type HilosProfileSignInIdentitySource,
  type HilosSecondFactorState,
  type ProjectSignal,
} from '@hilos/core/base'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
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

const cleanups: Array<() => void> = []
afterEach(() => {
  cleanup()
  for (const stop of cleanups.splice(0).reverse()) stop()
  vi.restoreAllMocks()
})

/** A wire emitter beside the real session, head store, navigator and photo flow. */
function fixture(
  key: string = HilosPages.PROFILE,
  unbuilt: ReadonlySet<string> = new Set(),
) {
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const subscriptions: string[] = []
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      const group = listeners.get(event) ?? new Set()
      group.add(listener)
      listeners.set(event, group)
      subscriptions.push(`on:${event}`)
      return () => {
        group.delete(listener)
        subscriptions.push(`off:${event}`)
      }
    },
    emit(type: string, data: unknown): void {
      const signal = { kind: 'project', type, data } as ProjectSignal
      act(() => {
        for (const listener of [...(listeners.get('projectSignal') ?? [])]) {
          listener(signal as never)
        }
      })
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
    act(() => {
      ingest(scopes.page()!, {
        lists: {
          profileIdentities: {
            items: [
              { itemKey: 7, slots: { identities, passkeyCredentials: [] } },
            ],
          },
        },
      })
    })
  }
  const answer = (tabs = TABS) => {
    act(() => {
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
    })
  }
  const view = (store?: HilosProfileHeadStore) => (
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfileHead context={context} store={store} />
      </HilosRouterContext.Provider>
    </StrictMode>
  )
  const mountHead = (store?: HilosProfileHeadStore) => render(view(store))

  return {
    context,
    scopes,
    connection,
    subscriptions,
    router,
    pushState,
    dispatch,
    session,
    answer,
    waysIn,
    view,
    mountHead,
  }
}

function byId(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function links(): HTMLAnchorElement[] {
  return [
    ...screen
      .getByRole('navigation')
      .querySelectorAll<HTMLAnchorElement>('ul > li > a'),
  ]
}

describe('HilosProfileHead under StrictMode', () => {
  it.each(TABS.map((tab) => tab.page))(
    'draws the one named shared h1 on direct %s entry',
    (key) => {
      const f = fixture(key)
      f.answer()
      const head = f.mountHead()

      expect(byId('profile-identity')).not.toBeNull()
      expect(byId('hilos-page-head')).not.toBeNull()
      expect(head.container.querySelectorAll('h1')).toHaveLength(1)
      const heading = screen.getByRole('heading', { level: 1, name: 'Ann Lee' })
      expect(heading.dataset.id).toBe('profile-identity-name')
      expect(heading.id).toBeTruthy()
      expect(byId('hilos-page-head-title')).toBeNull()
      expect(byId('hilos-breadcrumb')).toBeNull()
      expect(head.container.textContent).not.toContain('Profile')
      expect(byId('profile-identity-email')?.textContent).toBe(
        'ann@example.test',
      )
      expect(f.dispatch).not.toHaveBeenCalled()
    },
  )

  it('shows the bound name before the first paint, without waiting for passive subscriptions', async () => {
    const f = fixture()
    f.answer()
    const container = document.createElement('div')
    document.body.append(container)
    const root = createRoot(container)
    const environment = globalThis as typeof globalThis & {
      IS_REACT_ACT_ENVIRONMENT?: boolean
    }
    const previous = environment.IS_REACT_ACT_ENVIRONMENT
    let painted = () => {}
    const firstPaint = new Promise<void>((resolve) => {
      painted = resolve
    })
    let name: string | null | undefined
    function Frame({ children }: { children: ReactNode }) {
      useLayoutEffect(() => {
        queueMicrotask(() => {
          name = container.querySelector('h1')?.textContent
          painted()
        })
      }, [])
      return children
    }

    environment.IS_REACT_ACT_ENVIRONMENT = false
    try {
      root.render(<Frame>{f.view()}</Frame>)
      await firstPaint
    } finally {
      environment.IS_REACT_ACT_ENVIRONMENT = previous
      act(() => root.unmount())
      container.remove()
    }
    expect(name).toBe('Ann Lee')
  })

  it('uses one named navigation row in catalog order, with History itself current', () => {
    const f = fixture(HilosPages.PROFILE_AGREEMENTS_HISTORY)
    f.answer()
    f.mountHead()
    const nav = screen.getByRole('navigation', { name: 'Profile pages' })

    expect(screen.getAllByRole('navigation')).toHaveLength(1)
    expect(nav.dataset.id).toBe('hilos-page-tabs')
    expect(links().map((link) => link.textContent)).toEqual([
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
    expect(nav.querySelector('[aria-current="page"]')?.textContent).toBe(
      'Revision history',
    )
    expect(
      byId(`hilos-page-tab-${HilosPages.PROFILE_AGREEMENTS}`)?.getAttribute(
        'aria-current',
      ),
    ).toBeNull()
  })

  it('marks the project page itself and follows a link through the normal navigator', () => {
    const f = fixture(PROJECT_PAGE)
    f.answer()
    f.mountHead()
    expect(
      byId(`hilos-page-tab-${PROJECT_PAGE}`)?.getAttribute('aria-current'),
    ).toBe('page')
    fireEvent.click(screen.getByRole('link', { name: 'Overview' }))
    expect(f.pushState).toHaveBeenCalledWith('/profile')
    expect(f.router.currentRoute.get().page).toBe(HilosPages.PROFILE)
    expect(screen.queryByRole('heading', { level: 1 })).toBeNull()
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
    expect(screen.getByRole('navigation').querySelectorAll('li')).toHaveLength(
      9,
    )

    f.answer([
      { page: HilosPages.PROFILE, label: 'Catalog overview' },
      { page: PROJECT_PAGE, label: 'Catalog project caption' },
    ])
    expect(links().map((link) => link.textContent)).toEqual([
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
      TABS[0],
      TABS[5],
      { page: 'project_profile_unrouted', label: 'Unrouted' },
    ])
    f.mountHead()
    expect(screen.queryByRole('navigation')).toBeNull()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      'Ann Lee',
    )
    expect(screen.queryByRole('link')).toBeNull()

    f.answer([TABS[0], TABS[1], TABS[5]])
    expect(links().map((link) => link.textContent)).toEqual([
      'Overview',
      'Ways to sign in',
    ])
  })

  it('updates Security eligibility without navigating away from the current Security page', () => {
    const f = fixture(HilosPages.PROFILE_SECURITY)
    f.answer()
    f.mountHead()
    const securityTab = () =>
      byId(`hilos-page-tab-${HilosPages.PROFILE_SECURITY}`)
    expect(securityTab()?.getAttribute('aria-current')).toBe('page')

    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...SECURITY,
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
    f.connection.emit(SIGNAL_SECOND_FACTOR_STATE, SECURITY)
    expect(securityTab()).not.toBeNull()
  })

  it('replaces confirmed email with the live passkey-only warning and invents no address', () => {
    const f = fixture()
    f.answer()
    f.mountHead()
    const warning = () => byId('profile-sign-in-passkey-only-line')
    const email = () => byId('profile-identity-email')
    expect(warning()).toBeNull()
    expect(email()?.textContent).toBe('ann@example.test')

    f.waysIn([PASSKEY])
    expect(warning()?.textContent).toBe(HILOS_PROFILE_ROOT_COPY.passkeyOnly)
    expect(warning()?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(email()).toBeNull()
    expect(
      warning()?.closest('[data-id="hilos-page-head-block"]'),
    ).not.toBeNull()
    expect(
      byId('profile-identity-name')!.compareDocumentPosition(warning()!) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()

    f.waysIn([PASSKEY, { ...PASSWORD, verified: false }])
    expect(warning()).toBeNull()
    expect(email()).toBeNull()
    f.waysIn([PASSKEY, PASSWORD])
    expect(email()?.textContent).toBe('ann@example.test')
    f.waysIn([PASSKEY])
    expect(warning()).not.toBeNull()
  })

  it('keeps live session name and photo on the same heading and decorative avatar', () => {
    const f = fixture()
    f.answer()
    const head = f.mountHead()
    const heading = screen.getByRole('heading', { level: 1 })
    const headingId = heading.id
    expect(byId('hilos-avatar')?.getAttribute('aria-hidden')).toBe('true')
    expect(head.container.querySelector('img')?.getAttribute('alt')).toBe('')
    expect(head.container.querySelector('img')?.getAttribute('src')).toBe(
      '/ann.jpg',
    )

    f.session('Bea Smith', '/bea.jpg')
    expect(byId('profile-identity-name')).toBe(heading)
    expect(heading.id).toBe(headingId)
    expect(heading.textContent).toBe('Bea Smith')
    expect(head.container.querySelector('img')?.getAttribute('src')).toBe(
      '/bea.jpg',
    )
    f.session('Bea Smith', null)
    expect(head.container.querySelector('img')).toBeNull()
    expect(byId('hilos-avatar')?.textContent).toBe('BS')
  })

  it('replays setup and cleanup, then opens the real named photo modal and returns focus', async () => {
    const f = fixture(HilosPages.PROFILE_AGREEMENTS_HISTORY)
    f.answer()
    f.mountHead()
    // These are real connection acquisitions: StrictMode disposed one flow and
    // set up another. Reusing the disposed flow makes the following click fail.
    expect(
      f.subscriptions.filter((event) => event.endsWith(':actionError')),
    ).toEqual(['on:actionError', 'off:actionError', 'on:actionError'])
    const opener = screen.getByRole('button', {
      name: HILOS_PROFILE_PHOTO_COPY.open,
    })
    expect(opener.tagName).toBe('BUTTON')
    expect(opener.dataset.id).toBe('profile-photo-open')
    opener.focus()
    fireEvent.click(opener)
    const dialog = await screen.findByRole('dialog', {
      name: HILOS_PROFILE_PHOTO_COPY.title,
    })
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(document.activeElement).toBe(dialog)
    expect(byId('profile-photo-remove')).not.toBeNull()

    // The existing flow follows a server photo removal even with its window open.
    f.session('Ann Lee', null)
    expect(byId('profile-photo-remove')).toBeNull()
    expect(byId('profile-photo-choose')).not.toBeNull()
    fireEvent.click(
      screen.getByRole('button', { name: HILOS_PROFILE_PHOTO_COPY.cancel }),
    )
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(opener)
    expect(f.dispatch).not.toHaveBeenCalled()
  })

  it('leaves the avatar noninteractive while photo is disabled and enables the old window live', () => {
    const f = fixture()
    f.answer()
    ingest(f.scopes.page()!, { data: { profilePhoto: false } })
    f.mountHead()
    expect(byId('profile-photo-open')).toBeNull()
    expect(byId('profile-photo-live-assertive')).toBeNull()
    expect(byId('hilos-avatar')?.closest('button, a')).toBeNull()

    act(() => ingest(f.scopes.page()!, { data: { profilePhoto: true } }))
    fireEvent.click(
      screen.getByRole('button', { name: HILOS_PROFILE_PHOTO_COPY.open }),
    )
    expect(
      screen.getByRole('dialog', { name: HILOS_PROFILE_PHOTO_COPY.title }),
    ).not.toBeNull()
  })

  it('shows only the shared skeleton before an answer and drops the old head between pages', () => {
    const f = fixture()
    const head = f.mountHead()
    expect(byId('hilos-page-head-skeleton')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(head.container.querySelector('h1, nav, button, img')).toBeNull()
    expect(head.container.textContent).toBe('')
    f.answer()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      'Ann Lee',
    )
    act(() => f.router.navigate('/profile/example'))
    expect(head.container.querySelector('h1, nav, button, img')).toBeNull()
    expect(head.container.textContent).toBe('')
    f.session('Next person', null)
    f.answer()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      'Next person',
    )
    expect(
      byId(`hilos-page-tab-${PROJECT_PAGE}`)?.getAttribute('aria-current'),
    ).toBe('page')
  })

  it('releases its owned store, name binding and open photo flow on unmount', () => {
    const f = fixture()
    f.answer()
    const head = f.mountHead()
    fireEvent.click(
      screen.getByRole('button', { name: HILOS_PROFILE_PHOTO_COPY.open }),
    )
    expect(f.connection.listenerCount('projectSignal')).toBe(3)
    expect(f.connection.listenerCount('actionError')).toBe(1)
    expect(document.body.classList.contains('modal-open')).toBe(true)

    head.unmount()
    expect(f.connection.listenerCount('projectSignal')).toBe(1)
    expect(f.connection.listenerCount('actionError')).toBe(0)
    expect(pageIdentity(f.scopes).get()?.pageHeadOwner?.label).toBe('Profile')
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(
      f.subscriptions.filter((event) => event.endsWith(':actionError')),
    ).toEqual([
      'on:actionError',
      'off:actionError',
      'on:actionError',
      'off:actionError',
    ])
  })

  it('borrows the root store without taking its lifecycle and owns a fresh photo flow per mount', () => {
    const f = fixture()
    f.answer()
    const name = createSignal('Project name')
    const store = createHilosProfileHeadStore(f.context, name)
    store.start()
    cleanups.push(() => store.dispose())
    const start = vi.spyOn(store, 'start')
    const dispose = vi.spyOn(store, 'dispose')
    const first = f.mountHead(store)
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      'Project name',
    )
    expect(start).not.toHaveBeenCalled()
    fireEvent.click(
      screen.getByRole('button', { name: HILOS_PROFILE_PHOTO_COPY.open }),
    )
    first.unmount()
    expect(dispose).not.toHaveBeenCalled()
    expect(f.connection.listenerCount('projectSignal')).toBe(2)
    expect(f.connection.listenerCount('actionError')).toBe(0)

    name.set('Project renamed')
    const second = f.mountHead(store)
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      'Project renamed',
    )
    act(() => name.set('Project renamed again'))
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      'Project renamed again',
    )
    fireEvent.click(
      screen.getByRole('button', { name: HILOS_PROFILE_PHOTO_COPY.open }),
    )
    expect(
      screen.getByRole('dialog', { name: HILOS_PROFILE_PHOTO_COPY.title }),
    ).not.toBeNull()
    expect(byId('profile-photo-remove')).not.toBeNull()
    expect(start).not.toHaveBeenCalled()
    second.unmount()
    expect(dispose).not.toHaveBeenCalled()
    expect(f.connection.listenerCount('actionError')).toBe(0)
    expect(pageIdentity(f.scopes).get()?.pageHeadOwner?.label).toBe(
      'Project renamed again',
    )
  })

  it('replaces a context and releases the old open flow before using the new account', () => {
    const first = fixture()
    first.answer()
    const head = first.mountHead()
    fireEvent.click(
      screen.getByRole('button', { name: HILOS_PROFILE_PHOTO_COPY.open }),
    )
    const next = fixture(PROJECT_PAGE)
    next.session('Next person', null)
    next.answer()
    head.rerender(next.view())

    expect(first.connection.listenerCount('projectSignal')).toBe(1)
    expect(first.connection.listenerCount('actionError')).toBe(0)
    expect(pageIdentity(first.scopes).get()?.pageHeadOwner?.label).toBe(
      'Profile',
    )
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      'Next person',
    )
    fireEvent.click(
      screen.getByRole('button', { name: HILOS_PROFILE_PHOTO_COPY.open }),
    )
    expect(
      screen.getByRole('dialog', { name: HILOS_PROFILE_PHOTO_COPY.title }),
    ).not.toBeNull()
    expect(byId('profile-photo-choose')).not.toBeNull()
    expect(byId('profile-photo-remove')).toBeNull()
    expect(next.connection.listenerCount('actionError')).toBe(1)
    head.unmount()
    expect(next.connection.listenerCount('projectSignal')).toBe(1)
    expect(next.connection.listenerCount('actionError')).toBe(0)
  })
})
