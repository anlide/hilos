// Covers the profile root's view (HIL-1169): Name with Change only when the
// project handed its rename, Email only with a verified address, a row per
// catalog section in the catalog's order with its summary, the placeholder
// while the name is not known yet, and the Theme row (HIL-1434).
import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  createSignal,
  HILOS_PROFILE_IDENTITIES_LIST,
  HilosPages,
  resolveHilosProfileSignInMethods,
  ScopeManager,
  setHilosThemePick,
  type HilosConnection,
  type HilosPageIdentity,
  type HilosProfileBinding,
  type HilosProfilePageContext,
  type HilosProfileRename,
  type HilosProfileSignInMethod,
  type HilosRouter,
  type PageRouteMatch,
  type ProjectSignal,
} from '@hilos/core'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import { hilosRouterKey } from '../hilosRouterKey.js'
import HilosProfilePage from './HilosProfilePage.vue'

const themeActions = new ActionLifecycle({
  sendAction: () => true,
  on: () => () => {},
})

let releaseTheme: (() => void) | undefined
let releaseSession: (() => void) | undefined

afterEach(() => {
  document.body.innerHTML = ''
  releaseTheme?.()
  releaseTheme = undefined
  releaseSession?.()
  releaseSession = undefined
  setHilosThemePick(null)
  vi.unstubAllGlobals()
  const restore = bindThemeState(
    { on: () => () => {} } as unknown as HilosConnection,
    new ScopeManager(),
    themeActions,
  )
  restore()
})
enableAutoUnmount(afterEach)

function fakeConnection() {
  const listeners: ((signal: ProjectSignal) => void)[] = []

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {
        const idx = listeners.indexOf(
          listener as (signal: ProjectSignal) => void,
        )
        if (idx !== -1) {
          listeners.splice(idx, 1)
        }
      }
    },
    emit(type: string, data: unknown): void {
      const signal = { type, data } as ProjectSignal
      for (const listener of [...listeners]) {
        listener(signal)
      }
    },
  }
}

function bindTheme(
  settings: {
    switchingEnabled: boolean
    defaultTheme: 'light' | 'dark' | 'system'
  } = { switchingEnabled: true, defaultTheme: 'system' },
  pick: 'light' | 'dark' | 'system' | null = null,
) {
  releaseTheme?.()
  const conn = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(conn as unknown as HilosConnection, scopes)
  releaseTheme = bindThemeState(
    conn as unknown as HilosConnection,
    scopes,
    themeActions,
  )
  setHilosThemePick(pick)
  conn.emit('hilos_theme_settings', settings)

  return conn
}

/** The root's identity: the profile's sections in catalog order. */
const IDENTITY: HilosPageIdentity = {
  label: 'Profile',
  lead: '',
  breadcrumb: [{ page: HilosPages.PROFILE, label: 'Profile' }],
  children: [
    {
      page: HilosPages.PROFILE_SIGN_IN,
      label: 'Ways to sign in',
      lead: '',
      icon: null,
    },
    {
      page: HilosPages.PROFILE_SESSIONS,
      label: 'Sessions',
      lead: '',
      icon: null,
    },
    {
      page: HilosPages.PROFILE_SECURITY,
      label: 'Security',
      lead: '',
      icon: null,
    },
  ],
}

/** A router on the profile root whose every section has an address. */
function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.PROFILE,
      params: {},
      admin: false,
    }),
    currentPath: createSignal('/profile'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(IDENTITY),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => `/${page}`,
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

/** A rename that sends nothing; the page only needs to know it is there. */
const RENAME: HilosProfileRename = {
  send: () => false,
  refusal: createSignal(null),
  clearRefusal: () => {},
  stepUpOperation: 'change_name',
  minLength: 2,
  maxLength: 64,
}

/** A binding with the name only, as polls and tasks hand it. */
function nameOnly(name = 'Ann Lee'): HilosProfileBinding {
  return {
    name: createSignal(name),
    rename: null,
    sessionCount: null,
    deviceCount: null,
  }
}

/** Scopes whose session holds a signed-in person. */
function signedInScopes(): ScopeManager {
  const scopes = new ScopeManager()
  scopes.session.data.set('currentUser', { type: 'user', id: 1 })
  return scopes
}

/** Set the framework identity list in the profile's page scope. */
function setMethods(
  scopes: ScopeManager,
  methods: readonly HilosProfileSignInMethod[],
): void {
  const page = scopes.page() ?? scopes.openPage(HilosPages.PROFILE)
  const identities = methods.map((method) => {
    const id = Number(method.key)
    const ref = { type: 'identities', id }
    page.entities.upsert(ref, {
      id,
      type: method.type,
      provider: method.provider,
      identifier: method.identifier,
      verified: method.verified,
    })
    return ref
  })
  page.lists.upsert(HILOS_PROFILE_IDENTITIES_LIST, 1, {
    identities,
    passkeyCredentials: [],
  })
}

function mountPage(
  binding: HilosProfileBinding,
  scopes: ScopeManager = signedInScopes(),
  context: HilosProfilePageContext = {
    connection: { on: () => () => {} } as unknown as HilosConnection,
    scopes,
    actions: {} as ActionLifecycle,
  },
) {
  return mount(HilosProfilePage, {
    attachTo: document.body,
    global: {
      provide: { [hilosRouterKey as symbol]: router() },
      stubs: { HilosPageHeading: true, HilosAccountDeletion: true },
    },
    props: {
      context,
      binding,
    },
  })
}

/** A profile on the same live session/theme binding as the application shell. */
function accountThemePage() {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  const dispatch = vi.fn(() => ({
    requestId: 'theme',
    loading: createSignal(false),
    done: Promise.resolve({}),
  }))
  const actions = { dispatch } as unknown as ActionLifecycle
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  const stopSignOut = bindSignOut(scopes, actions)
  const stopImpersonation = bindImpersonation(scopes, actions)
  releaseSession = () => {
    stopSignOut()
    stopImpersonation()
  }
  releaseTheme = bindThemeState(
    connection as unknown as HilosConnection,
    scopes,
    actions,
  )
  setHilosThemePick(null)
  connection.emit('handshake_response', {
    entities: { currentUser: { id: 1, name: 'Ann Lee', themePick: null } },
    data: { themeSettings: { switchingEnabled: true, defaultTheme: 'system' } },
  })
  const wrapper = mountPage(nameOnly(), scopes, {
    connection: connection as unknown as HilosConnection,
    scopes,
    actions,
  })

  return { connection, scopes, dispatch, wrapper }
}

describe('HilosProfilePage', () => {
  it('opens photo editing only when the page enables it', () => {
    expect(
      mountPage(nameOnly()).find('[data-id="profile-photo-open"]').exists(),
    ).toBe(false)
    const scopes = signedInScopes()
    scopes.openPage(HilosPages.PROFILE).data.set('profilePhoto', true)
    const wrapper = mountPage(nameOnly(), scopes)
    expect(
      wrapper.find('[data-id="profile-photo-open"]').attributes('aria-label'),
    ).toBe('Change your photo')
  })

  it('shows the name without Change and no Email row when the project hands neither', () => {
    const wrapper = mountPage(nameOnly())

    expect(wrapper.find('[data-id="profile-identity-name"]').text()).toBe(
      'Ann Lee',
    )
    expect(wrapper.find('[data-id="profile-name"]').text()).toBe('Ann Lee')
    expect(wrapper.find('[data-id="profile-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="profile-email"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="profile-identity-email"]').exists()).toBe(
      false,
    )
  })

  it("offers Change for the project's rename and the Email row for a verified address", () => {
    const scopes = signedInScopes()
    setMethods(
      scopes,
      resolveHilosProfileSignInMethods(
        [
          {
            id: 1,
            type: 'password',
            provider: null,
            identifier: 'ann@example.test',
            verified: true,
          },
        ],
        [],
      ),
    )
    const wrapper = mountPage({ ...nameOnly(), rename: RENAME }, scopes)

    expect(wrapper.find('[data-id="profile-edit"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="profile-email"]').text()).toBe(
      'ann@example.test · verified',
    )
    expect(wrapper.find('[data-id="profile-identity-email"]').text()).toBe(
      'ann@example.test',
    )
    expect(wrapper.find('[data-id="profile-email-change"]').exists()).toBe(true)
  })

  it('draws a row per catalog section in its order with the summaries it knows', () => {
    const wrapper = mountPage({
      ...nameOnly(),
      sessionCount: createSignal(2),
    })

    const rows = wrapper.findAll('[data-id="profile-section"]')
    expect(rows.map((row) => row.text())).toEqual([
      expect.stringContaining('Ways to sign in'),
      expect.stringContaining('Sessions'),
      expect.stringContaining('Security'),
    ])
    expect(wrapper.find('[data-id="profile-sign-in-summary"]').text()).toBe(
      'No ways to sign in',
    )
    expect(wrapper.find('[data-id="profile-sessions-summary"]').text()).toBe(
      '2 active sign-ins',
    )
    expect(wrapper.find('[data-id="profile-security-summary"]').text()).toBe(
      'Two-step verification is off',
    )
    expect(
      wrapper.find('[data-id="profile-security-open"]').attributes('href'),
    ).toBe(`/${HilosPages.PROFILE_SECURITY}`)
  })

  it('draws only the placeholder while nobody is signed in', () => {
    const wrapper = mountPage(nameOnly(), new ScopeManager())

    expect(wrapper.find('[data-id="profile-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="profile-view"]').exists()).toBe(false)
  })

  it('holds a placeholder until the name is known', () => {
    const wrapper = mountPage(nameOnly(''))

    expect(wrapper.find('[data-id="profile-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="profile-identity"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="profile-detail"]').exists()).toBe(false)
  })

  it('warns under the sign-in row while device keys are the only way in, live', async () => {
    const methods = createSignal(
      resolveHilosProfileSignInMethods(
        [
          {
            id: 2,
            type: 'passkey',
            provider: null,
            identifier: 'opaque',
            verified: true,
          },
        ],
        [],
      ),
    )
    const scopes = signedInScopes()
    setMethods(scopes, methods.get())
    const wrapper = mountPage(nameOnly(), scopes)

    expect(
      wrapper.find('[data-id="profile-sign-in-passkey-only-line"]').text(),
    ).toBe(
      'Only passkeys can sign you in: access cannot be restored automatically',
    )
    setMethods(
      scopes,
      resolveHilosProfileSignInMethods(
        [
          {
            id: 1,
            type: 'password',
            provider: null,
            identifier: 'ann@example.test',
            verified: true,
          },
        ],
        [],
      ),
    )
    await nextTick()
    expect(
      wrapper.find('[data-id="profile-sign-in-passkey-only-line"]').exists(),
    ).toBe(false)
  })

  it('places the Theme row after Email, or after Name without an address', () => {
    bindTheme({ switchingEnabled: true, defaultTheme: 'system' })

    const scopesWithEmail = signedInScopes()
    setMethods(
      scopesWithEmail,
      resolveHilosProfileSignInMethods(
        [
          {
            id: 1,
            type: 'password',
            provider: null,
            identifier: 'ann@example.test',
            verified: true,
          },
        ],
        [],
      ),
    )
    const wrapperWithEmail = mountPage(nameOnly(), scopesWithEmail)
    const rowsWithEmail = wrapperWithEmail
      .find('[data-id="profile-detail"]')
      .findAll('.border-bottom')
    expect(rowsWithEmail).toHaveLength(3)
    expect(rowsWithEmail[0].find('[data-id="profile-name"]').exists()).toBe(
      true,
    )
    expect(rowsWithEmail[1].find('[data-id="profile-email"]').exists()).toBe(
      true,
    )
    expect(rowsWithEmail[2].find('[data-id="profile-theme"]').exists()).toBe(
      true,
    )
    expect(rowsWithEmail[2].find('i').classes()).toContain('bi-circle-half')

    const wrapperNoEmail = mountPage(nameOnly())
    const rowsNoEmail = wrapperNoEmail
      .find('[data-id="profile-detail"]')
      .findAll('.border-bottom')
    expect(rowsNoEmail).toHaveLength(2)
    expect(rowsNoEmail[0].find('[data-id="profile-name"]').exists()).toBe(true)
    expect(rowsNoEmail[1].find('[data-id="profile-theme"]').exists()).toBe(true)
    expect(wrapperNoEmail.find('[data-id="profile-email"]').exists()).toBe(
      false,
    )
  })

  it('renders four theme texts according to picks and defaults', () => {
    bindTheme({ switchingEnabled: true, defaultTheme: 'system' }, null)
    let wrapper = mountPage(nameOnly())
    expect(wrapper.find('[data-id="profile-theme"]').text()).toBe(
      'System · default',
    )

    bindTheme({ switchingEnabled: true, defaultTheme: 'system' }, 'dark')
    wrapper = mountPage(nameOnly())
    expect(wrapper.find('[data-id="profile-theme"]').text()).toBe('Dark')

    bindTheme({ switchingEnabled: true, defaultTheme: 'system' }, 'system')
    wrapper = mountPage(nameOnly())
    expect(wrapper.find('[data-id="profile-theme"]').text()).toBe('System')

    bindTheme({ switchingEnabled: true, defaultTheme: 'dark' }, null)
    wrapper = mountPage(nameOnly())
    expect(wrapper.find('[data-id="profile-theme"]').text()).toBe(
      'Dark · default',
    )
  })

  it('draws no Theme row when switching is off', () => {
    bindTheme({ switchingEnabled: false, defaultTheme: 'light' }, 'dark')
    const wrapper = mountPage(nameOnly())
    expect(wrapper.find('[data-id="profile-theme"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="profile-theme-change"]').exists()).toBe(
      false,
    )
  })

  it('opens the framework theme modal without a project rename and returns focus on Close', async () => {
    const { wrapper, dispatch } = accountThemePage()
    const change = wrapper.get('[data-id="profile-theme-change"]')
    expect(wrapper.find('[data-id="profile-edit"]').exists()).toBe(false)
    ;(change.element as HTMLButtonElement).focus()
    await change.trigger('click')
    await nextTick()
    const modal = document.querySelector('[data-id="profile-theme-modal"]')
    expect(modal).not.toBeNull()
    expect(modal?.querySelectorAll('.list-group button')).toHaveLength(3)
    expect(
      modal?.querySelector('[data-id="profile-theme-option-system"]')
        ?.textContent,
    ).toContain('default')
    const close = document.querySelector<HTMLButtonElement>(
      '[data-id="profile-theme-close"]',
    )
    close?.click()
    await nextTick()
    expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
    expect(document.activeElement).toBe(change.element)
    expect(dispatch).not.toHaveBeenCalled()
  })

  it('updates both the profile row and open modal through the server echo', async () => {
    const { wrapper, connection, dispatch } = accountThemePage()
    await wrapper.get('[data-id="profile-theme-change"]').trigger('click')
    connection.emit('handshake_response', {
      entities: { currentUser: { id: 1, name: 'Ann Lee', themePick: 'dark' } },
    })
    await nextTick()
    expect(wrapper.get('[data-id="profile-theme"]').text()).toBe('Dark')
    expect(
      document
        .querySelector('[data-id="profile-theme-option-dark"]')
        ?.getAttribute('aria-current'),
    ).toBe('true')
    expect(dispatch).not.toHaveBeenCalled()
  })

  it.each(['off', 'signout', 'impersonation'] as const)(
    'closes an open profile theme modal on %s and keeps it closed when availability returns',
    async (change) => {
      const { wrapper, connection, scopes } = accountThemePage()
      await wrapper.get('[data-id="profile-theme-change"]').trigger('click')
      expect(
        document.querySelector('[data-id="profile-theme-modal"]'),
      ).not.toBeNull()
      if (change === 'off')
        connection.emit('hilos_theme_settings', {
          switchingEnabled: false,
          defaultTheme: 'light',
        })
      else if (change === 'signout')
        connection.emit('handshake_response', {
          entities: { currentUser: null },
        })
      else scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 })
      await nextTick()
      expect(
        document.querySelector('[data-id="profile-theme-modal"]'),
      ).toBeNull()
      if (change === 'off')
        expect(wrapper.find('[data-id="profile-theme-change"]').exists()).toBe(
          false,
        )
      if (change === 'signout')
        expect(wrapper.find('[data-id="profile-view"]').exists()).toBe(false)
      scopes.session.data.set('impersonatedBy', null)
      connection.emit('handshake_response', {
        entities: {
          currentUser: { id: 1, name: 'Ann Lee', themePick: 'light' },
        },
        data: {
          themeSettings: { switchingEnabled: true, defaultTheme: 'light' },
        },
      })
      await nextTick()
      expect(wrapper.find('[data-id="profile-theme-change"]').exists()).toBe(
        true,
      )
      expect(
        document.querySelector('[data-id="profile-theme-modal"]'),
      ).toBeNull()
    },
  )

  it('dispatches a repeated default from the modal without changing the profile row on success', async () => {
    const { wrapper, connection, dispatch } = accountThemePage()
    await wrapper.get('[data-id="profile-theme-change"]').trigger('click')
    document
      .querySelector<HTMLButtonElement>(
        '[data-id="profile-theme-option-system"]',
      )
      ?.click()
    await Promise.resolve()
    await nextTick()
    expect(dispatch).toHaveBeenCalledExactlyOnceWith('hilos_theme_pick', {
      themePick: 'system',
    })
    expect(wrapper.get('[data-id="profile-theme"]').text()).toBe(
      'System · default',
    )
    connection.emit('handshake_response', {
      entities: {
        currentUser: { id: 1, name: 'Ann Lee', themePick: 'system' },
      },
    })
    await nextTick()
    expect(wrapper.get('[data-id="profile-theme"]').text()).toBe('System')
  })

  it('updates pick, default, and switching live without remounting', async () => {
    const conn = bindTheme(
      { switchingEnabled: true, defaultTheme: 'system' },
      null,
    )
    const wrapper = mountPage(nameOnly())
    expect(wrapper.find('[data-id="profile-theme"]').text()).toBe(
      'System · default',
    )

    setHilosThemePick('dark')
    await nextTick()
    expect(wrapper.find('[data-id="profile-theme"]').text()).toBe('Dark')

    conn.emit('hilos_theme_settings', {
      switchingEnabled: true,
      defaultTheme: 'light',
    })
    setHilosThemePick(null)
    await nextTick()
    expect(wrapper.find('[data-id="profile-theme"]').text()).toBe(
      'Light · default',
    )

    conn.emit('hilos_theme_settings', {
      switchingEnabled: false,
      defaultTheme: 'light',
    })
    await nextTick()
    expect(wrapper.find('[data-id="profile-theme"]').exists()).toBe(false)

    conn.emit('hilos_theme_settings', {
      switchingEnabled: true,
      defaultTheme: 'light',
    })
    await nextTick()
    expect(wrapper.find('[data-id="profile-theme"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="profile-theme"]').text()).toBe(
      'Light · default',
    )
  })
})
