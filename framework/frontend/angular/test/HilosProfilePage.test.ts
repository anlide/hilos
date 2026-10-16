// Covers the Angular profile root (HIL-1169): Name with Change only for the
// project's rename, Email only with a verified address, a row per catalog
// section in its order with the summaries it knows, and the Theme row with its
// window (HIL-1441, the React page's cases).
import type { ComponentFixture } from '@angular/core/testing'
import { TestBed } from '@angular/core/testing'
import {
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  createSignal,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_PROFILE_IDENTITIES_LIST,
  HilosPages,
  resolveHilosProfileSignInMethods,
  ScopeManager,
  setHilosThemePick,
  type ActionLifecycle,
  type HilosConnection,
  type HilosProfileBinding,
  type HilosProfilePageContext,
  type HilosProfileSignInMethod,
  type HilosRouter,
  type ProjectSignal,
  type ThemePick,
  type ThemeSettings,
} from '@hilos/core'
import { afterEach, expect, it, vi } from 'vitest'
import { HilosProfilePage } from '../src/profile/HilosProfilePage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const themeActions = {
  dispatch: () => ({
    loading: createSignal(false),
    done: new Promise(() => {}),
  }),
} as unknown as ActionLifecycle

let releaseTheme: (() => void) | undefined
let releaseSession: (() => void) | undefined
let mounted: ComponentFixture<HilosProfilePage> | undefined

afterEach(() => {
  mounted?.destroy()
  mounted = undefined
  document.body.innerHTML = ''
  releaseTheme?.()
  releaseTheme = undefined
  releaseSession?.()
  releaseSession = undefined
  setHilosThemePick(null)
  const restore = bindThemeState(
    { on: () => () => {} } as unknown as HilosConnection,
    new ScopeManager(),
    themeActions,
  )
  restore()
})

/** Run change detection over the mounted page, if there is one. */
function update(): void {
  mounted?.detectChanges()
}

/** A connection that delivers the project signals a test emits. */
function fakeConnection() {
  const listeners: ((signal: ProjectSignal) => void)[] = []

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {
        const index = listeners.indexOf(
          listener as (signal: ProjectSignal) => void,
        )
        if (index !== -1) listeners.splice(index, 1)
      }
    },
    emit(type: string, data: unknown): void {
      const signal = { type, data } as ProjectSignal
      for (const listener of [...listeners]) listener(signal)
      update()
    },
  }
}

/** Bind the theme the way bootHilos does, with a guest's pick in this tab. */
function bindTheme(
  settings: ThemeSettings = { switchingEnabled: true, defaultTheme: 'system' },
  pick: ThemePick = null,
) {
  releaseTheme?.()
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  releaseTheme = bindThemeState(
    connection as unknown as HilosConnection,
    scopes,
    themeActions,
  )
  setHilosThemePick(pick)
  connection.emit('hilos_theme_settings', settings)

  return connection
}

/** A router on the profile root whose sections all have an address. */
function router(withSignIn = false): HilosRouter {
  return {
    pageIdentity: createSignal({
      label: 'Profile',
      lead: '',
      breadcrumb: [{ page: HilosPages.PROFILE, label: 'Profile' }],
      children: [
        ...(withSignIn
          ? [
              {
                page: HilosPages.PROFILE_SIGN_IN,
                label: 'Ways to sign in',
                lead: '',
                icon: null,
              },
            ]
          : []),
        {
          page: HilosPages.PROFILE_SECURITY,
          label: 'Security',
          lead: '',
          icon: null,
        },
        {
          page: HilosPages.PROFILE_DATA,
          label: 'Your data',
          lead: '',
          icon: null,
        },
      ],
    }),
    currentRoute: createSignal({
      page: HilosPages.PROFILE,
      params: {},
      admin: false,
    }),
    currentPath: createSignal('/profile'),
    resolvePath: (page: string) => `/${page}`,
    navigate: () => {},
  } as unknown as HilosRouter
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

function setup(
  binding: HilosProfileBinding,
  signedIn = true,
  withSignIn = false,
  initialMethods?: readonly HilosProfileSignInMethod[],
) {
  const scopes = new ScopeManager()
  if (signedIn) scopes.session.data.set('currentUser', { type: 'user', id: 1 })
  if (initialMethods) setMethods(scopes, initialMethods)
  const context = {
    actions: {
      dispatch: vi.fn(() => ({
        loading: createSignal(false),
        done: Promise.resolve({}),
      })),
    },
    connection: { on: () => () => {} },
    scopes,
  } as unknown as HilosProfilePageContext
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router(withSignIn) }],
  })
  const fixture = TestBed.createComponent(HilosProfilePage)
  mounted = fixture
  fixture.componentRef.setInput('context', context)
  fixture.componentRef.setInput('binding', binding)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  const node = (id: string) =>
    root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  return {
    fixture,
    scopes,
    node,
    root,
    setMethods: (next: readonly HilosProfileSignInMethod[]) => {
      setMethods(scopes, next)
      fixture.detectChanges()
    },
  }
}

it('offers the photo button only after the profile page enables photos', () => {
  const { fixture, node, scopes } = setup(nameOnly())
  expect(node('profile-photo-open')).toBeNull()
  scopes.openPage(HilosPages.PROFILE).data.set('profilePhoto', true)
  fixture.detectChanges()
  expect(node('profile-photo-open')?.getAttribute('aria-label')).toBe(
    'Change your photo',
  )
  fixture.destroy()
})

function nameOnly(): HilosProfileBinding {
  return {
    name: createSignal('Ann Lee'),
    rename: null,
    sessionCount: null,
    deviceCount: null,
  }
}

it('shows the name without Change and the sections in catalog order when the project hands no rename', () => {
  const { node, root } = setup(nameOnly())

  expect(node('profile-identity-name')?.textContent?.trim()).toBe('Ann Lee')
  expect(node('profile-edit')).toBeNull()
  expect(node('profile-email')).toBeNull()
  expect(
    [...root.querySelectorAll('[data-id="profile-section"] .fw-semibold')].map(
      (label) => label.textContent,
    ),
  ).toEqual(['Security', 'Your data'])
  expect(node('profile-security-summary')?.textContent?.trim()).toBe(
    'Two-step verification is off',
  )
  expect(node('profile-data-open')?.getAttribute('href')).toBe(
    `/${HilosPages.PROFILE_DATA}`,
  )
})

it("offers Change for the project's rename and the Email row for a verified address", () => {
  const { node } = setup(
    {
      ...nameOnly(),
      rename: {
        send: () => false,
        refusal: createSignal(null),
        clearRefusal: () => {},
        stepUpOperation: 'change_name',
        minLength: 2,
        maxLength: 64,
      },
    },
    true,
    false,
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

  expect(node('profile-edit')).not.toBeNull()
  expect(node('profile-email')?.textContent?.trim()).toBe(
    'ann@example.test · verified',
  )
  expect(node('profile-email-change')).not.toBeNull()
})

it('draws only the placeholder while nobody is signed in', () => {
  const { node } = setup(nameOnly(), false)

  expect(node('profile-loading')).not.toBeNull()
  expect(node('profile-view')).toBeNull()
})

it('warns under the sign-in row while device keys are the only way in, live', () => {
  const methods = resolveHilosProfileSignInMethods(
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
  )
  const { node, setMethods } = setup(nameOnly(), true, true, methods)

  expect(node('profile-sign-in-passkey-only-line')?.textContent?.trim()).toBe(
    'Only passkeys can sign you in: access cannot be restored automatically',
  )
  setMethods(
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
  expect(node('profile-sign-in-passkey-only-line')).toBeNull()
})

/** An email-and-password way in, so the Email row is drawn. */
const VERIFIED_EMAIL = resolveHilosProfileSignInMethods(
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
)

/** The Account rows of the profile, in the order they are drawn. */
function accountRows(): HTMLElement[] {
  return [
    ...document.querySelectorAll<HTMLElement>(
      '[data-id="profile-detail"] > .border-bottom',
    ),
  ]
}

/** A profile on the same live session/theme binding as the application shell. */
function accountThemePage() {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  const dispatch = vi.fn((action: string) => ({
    loading: createSignal(false),
    done: Promise.resolve(
      action === 'hilos_step_up_start'
        ? { reply: { required: false, purpose: 'change your name' } }
        : {},
    ),
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
  const signIn = (
    themePick: ThemePick,
    settings: ThemeSettings = {
      switchingEnabled: true,
      defaultTheme: 'system',
    },
  ) =>
    connection.emit('handshake_response', {
      entities: {
        currentUser: {
          id: 1,
          name: 'Ann Lee',
          themePick,
          themePickInitialized: themePick !== null,
        },
      },
      data: { themeSettings: settings },
    })
  signIn(null)
  const context = {
    actions,
    connection,
    scopes,
  } as unknown as HilosProfilePageContext
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  mounted = TestBed.createComponent(HilosProfilePage)
  mounted.componentRef.setInput('context', context)
  mounted.componentRef.setInput('binding', nameOnly())
  mounted.detectChanges()
  const themeCalls = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_theme_pick')

  return { connection, scopes, signIn, themeCalls }
}

function element(id: string): HTMLButtonElement {
  const found = document.querySelector<HTMLButtonElement>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing ${id}`)

  return found
}

function click(id: string): void {
  element(id).click()
  update()
}

it('places the Theme row after Email, or after Name without an address', () => {
  bindTheme({ switchingEnabled: true, defaultTheme: 'system' })
  setup(nameOnly(), true, false, VERIFIED_EMAIL)
  let rows = accountRows()
  expect(rows).toHaveLength(3)
  expect(rows[0]?.querySelector('[data-id="profile-name"]')).not.toBeNull()
  expect(rows[1]?.querySelector('[data-id="profile-email"]')).not.toBeNull()
  expect(rows[2]?.querySelector('[data-id="profile-theme"]')).not.toBeNull()
  expect(rows[2]?.querySelector('i')?.classList).toContain('bi-circle-half')
  expect(
    rows[2]?.querySelector('[data-id="profile-theme-change"]')?.textContent,
  ).toContain('Change')
  mounted?.destroy()
  mounted = undefined
  TestBed.resetTestingModule()

  setup(nameOnly())
  rows = accountRows()
  expect(rows).toHaveLength(2)
  expect(rows[0]?.querySelector('[data-id="profile-name"]')).not.toBeNull()
  expect(rows[1]?.querySelector('[data-id="profile-theme"]')).not.toBeNull()
  expect(document.querySelector('[data-id="profile-email"]')).toBeNull()
})

it.each([
  ['system', null, 'System · default'],
  ['system', 'dark', 'Dark'],
  ['system', 'system', 'System'],
  ['dark', null, 'Dark · default'],
] as const)(
  'names the position over a %s default with the pick %s as %s',
  (defaultTheme, pick, text) => {
    bindTheme({ switchingEnabled: true, defaultTheme }, pick)
    setup(nameOnly())
    expect(element('profile-theme').textContent?.trim()).toBe(text)
  },
)

it('draws no Theme row when switching is off', () => {
  bindTheme({ switchingEnabled: false, defaultTheme: 'light' }, 'dark')
  setup(nameOnly())
  expect(document.querySelector('[data-id="profile-theme"]')).toBeNull()
  expect(document.querySelector('[data-id="profile-theme-change"]')).toBeNull()
})

it('updates pick, default, and switching live without remounting', () => {
  const connection = bindTheme(
    { switchingEnabled: true, defaultTheme: 'system' },
    null,
  )
  setup(nameOnly())
  expect(element('profile-theme').textContent?.trim()).toBe('System · default')

  setHilosThemePick('dark')
  update()
  expect(element('profile-theme').textContent?.trim()).toBe('Dark')

  connection.emit('hilos_theme_settings', {
    switchingEnabled: true,
    defaultTheme: 'light',
  })
  setHilosThemePick(null)
  update()
  expect(element('profile-theme').textContent?.trim()).toBe('Light · default')

  connection.emit('hilos_theme_settings', {
    switchingEnabled: false,
    defaultTheme: 'light',
  })
  expect(document.querySelector('[data-id="profile-theme"]')).toBeNull()

  connection.emit('hilos_theme_settings', {
    switchingEnabled: true,
    defaultTheme: 'light',
  })
  expect(element('profile-theme').textContent?.trim()).toBe('Light · default')
})

it('opens the framework theme window without a project rename and returns focus on Close', () => {
  const { themeCalls } = accountThemePage()
  const change = element('profile-theme-change')
  expect(document.querySelector('[data-id="profile-edit"]')).toBeNull()
  change.focus()
  click('profile-theme-change')
  const modal = element('profile-theme-modal')
  expect(modal.querySelectorAll('.list-group button')).toHaveLength(3)
  expect(element('profile-theme-option-system').textContent).toContain(
    'default',
  )
  click('profile-theme-close')
  expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
  expect(document.activeElement).toBe(change)
  expect(themeCalls()).toEqual([])
})

it('updates both the profile row and the open window through the server echo', () => {
  const { signIn, themeCalls } = accountThemePage()
  click('profile-theme-change')
  signIn('dark')
  expect(element('profile-theme').textContent?.trim()).toBe('Dark')
  expect(
    element('profile-theme-option-dark').getAttribute('aria-current'),
  ).toBe('true')
  expect(themeCalls()).toEqual([])
})

it('sends a repeated default from the window and moves the row only on the account frame', async () => {
  const { signIn, themeCalls } = accountThemePage()
  click('profile-theme-change')
  click('profile-theme-option-system')
  await Promise.resolve()
  update()
  expect(themeCalls()).toEqual([['hilos_theme_pick', { themePick: 'system' }]])
  expect(element('profile-theme').textContent?.trim()).toBe('System · default')
  signIn('system')
  expect(element('profile-theme').textContent?.trim()).toBe('System')
})

it('keeps Change visible but closed under a takeover, with the strip as its reason', () => {
  const { scopes } = accountThemePage()
  scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 })
  update()
  const change = element('profile-theme-change')
  expect(change.disabled).toBe(true)
  expect(change.getAttribute('aria-describedby')).toBe(
    HILOS_IMPERSONATION_STRIP_TEXT_ID,
  )
  click('profile-theme-change')
  expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
})

it.each(['off', 'signout', 'impersonation'] as const)(
  'closes an open theme window on %s and keeps it closed when availability returns',
  (change) => {
    const { connection, scopes, signIn } = accountThemePage()
    click('profile-theme-change')
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
    else {
      scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 })
      update()
    }
    expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
    if (change === 'off')
      expect(
        document.querySelector('[data-id="profile-theme-change"]'),
      ).toBeNull()
    if (change === 'signout')
      expect(document.querySelector('[data-id="profile-view"]')).toBeNull()
    scopes.session.data.set('impersonatedBy', null)
    signIn('light', { switchingEnabled: true, defaultTheme: 'light' })
    expect(
      document.querySelector('[data-id="profile-theme-change"]'),
    ).not.toBeNull()
    expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
  },
)
