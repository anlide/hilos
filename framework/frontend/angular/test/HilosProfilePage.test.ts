// Covers the Angular profile root (HIL-1169): Name with Change only for the
// project's rename, Email only with a verified address, a row per catalog
// section in its order with the summaries it knows — but for a section the
// root hides (HIL-1303) — and the Theme row with its window (HIL-1441, the
// React page's cases).
import type { ComponentFixture } from '@angular/core/testing'
import { TestBed } from '@angular/core/testing'
import {
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  bindTimeZoneState,
  ingest,
  createSignal,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_PROFILE_IDENTITIES_LIST,
  HilosPages,
  PROFILE_RENAME_MEMORY_STORAGE_KEY,
  readProfileRenameMemory,
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
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { By } from '@angular/platform-browser'
import { HilosProfilePage } from '../src/profile/HilosProfilePage.js'
import { HilosProfileTimeZone } from '../src/profile/HilosProfileTimeZone.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { profileNameStatus, profileRenameTab } from './profileRenameHarness.js'

const themeActions = {
  dispatch: () => ({
    loading: createSignal(false),
    done: new Promise(() => {}),
  }),
} as unknown as ActionLifecycle

let releaseTheme: (() => void) | undefined
let releaseSession: (() => void) | undefined
let releaseTimeZone: (() => void) | undefined
let mounted: ComponentFixture<HilosProfilePage> | undefined
const renameDisposers: Array<() => void> = []

beforeEach(() => sessionStorage.clear())

afterEach(() => {
  mounted?.destroy()
  mounted = undefined
  for (const stop of renameDisposers.splice(0).reverse()) stop()
  document.body.innerHTML = ''
  releaseTheme?.()
  releaseTheme = undefined
  releaseSession?.()
  releaseSession = undefined
  releaseTimeZone?.()
  releaseTimeZone = undefined
  vi.restoreAllMocks()
  sessionStorage.clear()
  TestBed.resetTestingModule()
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
  answerProfile(scopes)
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

/** The common profile identity shipped beside the Account body. */
function answerProfile(scopes: ScopeManager): void {
  const page = scopes.page() ?? scopes.openPage(HilosPages.PROFILE)
  page.data.set('pageLabel', 'Profile')
  page.data.set('pageHeadOwner', {
    page: HilosPages.PROFILE,
    label: 'Profile',
    lead: '',
  })
  page.data.set('pageBreadcrumb', [
    { page: HilosPages.PROFILE, label: 'Profile' },
  ])
  page.data.set('pageChildren', [])
  page.data.set('pageTabs', [
    { page: HilosPages.PROFILE, label: 'Overview' },
    { page: HilosPages.PROFILE_SIGN_IN, label: 'Ways to sign in' },
    { page: HilosPages.PROFILE_SESSIONS, label: 'Sessions' },
    { page: HilosPages.PROFILE_SECURITY, label: 'Security' },
    { page: HilosPages.PROFILE_DATA, label: 'Your data' },
  ])
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
  answerProfile(scopes)
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
    context,
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
  scopes.page()!.data.set('profilePhoto', true)
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

function standardRenamePage(
  known = true,
  options: Parameters<typeof profileRenameTab>[1] = {},
) {
  TestBed.resetTestingModule()
  const tab = profileRenameTab(known, options)
  let active = true
  const dispose = (): void => {
    if (!active) return
    active = false
    tab.dispose()
  }
  renameDisposers.push(dispose)
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosProfilePage)
  mounted = fixture
  fixture.componentRef.setInput('context', tab.context)
  fixture.componentRef.setInput('binding', tab.binding)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  const query = (id: string): HTMLElement | null =>
    root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  const node = (id: string): HTMLElement => {
    const found = query(id)
    if (!found) throw new Error(`Missing ${id}`)
    return found
  }
  return {
    ...tab,
    fixture,
    node,
    exists: (id: string) => query(id) !== null,
    unmount() {
      fixture.destroy()
      if (mounted === fixture) mounted = undefined
    },
    dispose,
  }
}

async function settleProfile(
  fixture: ComponentFixture<unknown>,
): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

function editName(
  node: (id: string) => HTMLElement,
  fixture: ComponentFixture<unknown>,
  value: string,
): void {
  const field = node('profile-name-input') as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

async function openStandardName(page: ReturnType<typeof standardRenamePage>) {
  page.node('profile-edit').click()
  await settleProfile(page.fixture)
}

async function sendStandardName(page: ReturnType<typeof standardRenamePage>) {
  await openStandardName(page)
  editName(page.node, page.fixture, 'Bob')
  page.node('profile-rename-save').click()
  await settleProfile(page.fixture)
  expect(page.names()).toHaveLength(1)
}

async function dismissStandardName(
  page: ReturnType<typeof standardRenamePage>,
) {
  page.node('profile-rename-cancel').click()
  page.fixture.detectChanges()
  page.node('modal-confirm-discard').click()
  await settleProfile(page.fixture)
}

it('shows the name without Change and the sections in catalog order when the project hands no rename', () => {
  const { node } = setup(nameOnly())

  expect(node('profile-identity-name')?.textContent?.trim()).toBe('Ann Lee')
  expect(node('profile-edit')).toBeNull()
  expect(node('profile-email')).toBeNull()
  expect(document.querySelectorAll('h1')).toHaveLength(1)
  expect(node('hilos-page-tabs')?.getAttribute('aria-label')).toBe(
    'Profile pages',
  )
  expect(
    node('hilos-page-tab-hilos_profile')?.getAttribute('aria-current'),
  ).toBe('page')
  expect(node('profile-section')).toBeNull()
  expect(
    node('hilos-page-tab-hilos_profile_security')?.getAttribute('href'),
  ).toBe(`/${HilosPages.PROFILE_SECURITY}`)
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

it('hides Security navigation while two-step verification is turned off with nothing of the person standing, live', () => {
  const { fixture, node, scopes } = setup(nameOnly())
  const section = {
    enabled: false,
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
  scopes.page()!.data.set('secondFactor', section)
  fixture.detectChanges()

  expect(node('hilos-page-tab-hilos_profile_security')).toBeNull()

  scopes.page()!.data.set('secondFactor', { ...section, enabled: true })
  fixture.detectChanges()
  expect(node('hilos-page-tab-hilos_profile_security')).not.toBeNull()
})

it('draws only the placeholder while nobody is signed in', () => {
  const { node } = setup(nameOnly(), false)

  expect(node('profile-loading')).not.toBeNull()
  expect(node('profile-view')).toBeNull()
})

it('warns under the name while device keys are the only way in, live', () => {
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
  answerProfile(scopes)
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

  return { connection, context, scopes, signIn, themeCalls, dispatch }
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

it('places Time zone before Theme, after Email or Name without an address', () => {
  bindTheme({ switchingEnabled: true, defaultTheme: 'system' })
  setup(nameOnly(), true, false, VERIFIED_EMAIL)
  let rows = accountRows()
  expect(rows).toHaveLength(4)
  expect(rows[0]?.querySelector('[data-id="profile-name"]')).not.toBeNull()
  expect(rows[1]?.querySelector('[data-id="profile-email"]')).not.toBeNull()
  expect(rows[2]?.querySelector('[data-id="profile-time-zone"]')).not.toBeNull()
  expect(rows[2]?.querySelector('i')?.classList).toContain('bi-clock')
  expect(rows[3]?.querySelector('[data-id="profile-theme"]')).not.toBeNull()
  expect(rows[3]?.querySelector('i')?.classList).toContain('bi-circle-half')
  expect(
    rows[3]?.querySelector('[data-id="profile-theme-change"]')?.textContent,
  ).toContain('Change')
  mounted?.destroy()
  mounted = undefined
  TestBed.resetTestingModule()

  setup(nameOnly())
  rows = accountRows()
  expect(rows).toHaveLength(3)
  expect(rows[0]?.querySelector('[data-id="profile-name"]')).not.toBeNull()
  expect(rows[1]?.querySelector('[data-id="profile-time-zone"]')).not.toBeNull()
  expect(rows[2]?.querySelector('[data-id="profile-theme"]')).not.toBeNull()
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

describe('the standard name window tab lifecycle', () => {
  it('restores an open draft only after this page knows its owner and STATE', async () => {
    const first = standardRenamePage()
    await openStandardName(first)
    editName(first.node, first.fixture, ' x ')
    first.unmount()
    expect(readProfileRenameMemory()?.open).toBe(true)
    expect(readProfileRenameMemory()?.draft).toBe(' x ')
    first.dispose()

    const second = standardRenamePage(false)
    await settleProfile(second.fixture)
    expect(second.exists('modal')).toBe(false)
    expect(second.exists('profile-view')).toBe(false)
    expect(readProfileRenameMemory()?.open).toBe(true)
    second.identity()
    await settleProfile(second.fixture)
    expect(second.exists('modal')).toBe(false)
    second.state(null)
    await settleProfile(second.fixture)

    expect(second.node('profile-name-input')).toBeDefined()
    expect((second.node('profile-name-input') as HTMLInputElement).value).toBe(
      ' x ',
    )
    expect(
      (second.node('profile-rename-save') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(first.names()).toHaveLength(0)
    expect(second.names()).toHaveLength(0)
    expect(
      second.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
    ).toHaveLength(1)
  })

  it.each(['checking', 'applying'] as const)(
    'restores open %s and a newer draft without another name send or step-up start',
    async (phase) => {
      const first = standardRenamePage()
      await sendStandardName(first)
      first.state(phase)
      editName(first.node, first.fixture, 'Carol')
      first.unmount()
      first.dispose()
      expect(readProfileRenameMemory()?.open).toBe(true)

      const second = standardRenamePage(false)
      second.identity()
      second.state(phase)
      await settleProfile(second.fixture)
      expect(
        (second.node('profile-name-input') as HTMLInputElement).value,
      ).toBe('Carol')
      expect(profileNameStatus().textContent?.trim()).toBe(
        phase === 'checking' ? 'Checking your name…' : '',
      )
      expect(second.node('profile-name').textContent?.trim()).toBe('Ann')
      expect(
        (second.node('profile-rename-save') as HTMLButtonElement).disabled,
      ).toBe(true)
      expect(second.names()).toHaveLength(0)
      expect(
        second.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
      ).toHaveLength(0)

      second.identity(1, 'Bob')
      second.state('done')
      await settleProfile(second.fixture)
      expect(
        (second.node('profile-name-input') as HTMLInputElement).value,
      ).toBe('Carol')
      expect(second.exists('profile-edit-notice')).toBe(false)
      expect(
        second.node('profile-edit-notice-idle').getAttribute('aria-hidden'),
      ).toBe('true')
      expect(
        (second.node('profile-rename-save') as HTMLButtonElement).disabled,
      ).toBe(false)
    },
  )

  it('restores the exact refusal without sending the name again', async () => {
    const first = standardRenamePage()
    await sendStandardName(first)
    first.state('refused')
    first.unmount()
    first.dispose()

    const second = standardRenamePage(false)
    second.identity()
    second.state('refused')
    await settleProfile(second.fixture)
    expect((second.node('profile-name-input') as HTMLInputElement).value).toBe(
      'Bob',
    )
    expect(
      second.node('profile-rename-live-assertive').textContent?.trim(),
    ).toBe('Exact server refusal.\nTry another name.')
    expect(
      (second.node('profile-rename-save') as HTMLButtonElement).disabled,
    ).toBe(false)
    expect(second.names()).toHaveLength(0)
  })

  it.each(['refused', 'done'] as const)(
    'keeps an explicit close through remount and late %s until manual Change',
    async (phase) => {
      const first = standardRenamePage()
      await sendStandardName(first)
      first.state('checking')
      await dismissStandardName(first)
      expect(readProfileRenameMemory()?.open).toBe(false)
      first.unmount()
      first.dispose()

      const second = standardRenamePage(false)
      second.identity()
      second.state('checking')
      await settleProfile(second.fixture)
      expect(second.exists('modal')).toBe(false)
      second.state(phase)
      if (phase === 'done') second.identity(1, 'Bob')
      await settleProfile(second.fixture)
      expect(second.exists('modal')).toBe(false)
      expect(readProfileRenameMemory()?.open).toBe(false)
      expect(
        second.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
      ).toHaveLength(0)

      await openStandardName(second)
      expect(
        (second.node('profile-name-input') as HTMLInputElement).value,
      ).toBe('Bob')
      expect(
        (second.node('profile-rename-save') as HTMLButtonElement).disabled,
      ).toBe(phase === 'done')
      expect(second.names()).toHaveLength(0)
      if (phase === 'refused') await dismissStandardName(second)
      else {
        second.node('profile-rename-cancel').click()
        await settleProfile(second.fixture)
      }
    },
  )

  it('keeps a new tab closed when server state arrives without its open checkpoint', async () => {
    const first = standardRenamePage()
    first.state('checking')
    await settleProfile(first.fixture)
    expect(first.exists('modal')).toBe(false)
    sessionStorage.clear()
    first.unmount()
    first.dispose()

    const second = standardRenamePage()
    second.state('checking')
    await settleProfile(second.fixture)
    expect(second.exists('modal')).toBe(false)
    expect(readProfileRenameMemory()).toEqual({
      version: 1,
      userId: 1,
      open: false,
      step: 'closed',
      draft: '',
      checkpoint: null,
      requestId: null,
    })
    expect(
      second.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
    ).toHaveLength(0)
  })

  it.each(['signout', 'person', 'access'] as const)(
    'discards the old window on %s and ignores late frames',
    async (cause) => {
      const page = standardRenamePage()
      await sendStandardName(page)
      page.state('checking')
      if (cause === 'signout') page.identity(null)
      if (cause === 'person') page.identity(2, 'Another person')
      if (cause === 'access') page.identity(1, 'Ann', { accountBlocked: {} })
      await settleProfile(page.fixture)
      expect(page.exists('modal')).toBe(false)
      expect(readProfileRenameMemory()).toBeNull()
      if (cause === 'signout') expect(page.exists('profile-view')).toBe(false)

      page.state('done')
      page.identity(1, 'Bob')
      await settleProfile(page.fixture)
      expect(page.exists('modal')).toBe(false)
      expect(readProfileRenameMemory()).toBeNull()
      expect(page.names()).toHaveLength(1)
    },
  )

  it.each([
    ['password', 'step-up-password', 'secret-password'],
    ['email_code', 'step-up-code', '123456'],
  ])(
    'rechecks %s after remount and never persists its credential or proof',
    async (method, fieldId, secret) => {
      const options = {
        stepUp: { required: true, purpose: 'change your name', method },
      }
      const first = standardRenamePage(true, options)
      await openStandardName(first)
      const field = first.node(fieldId) as HTMLInputElement
      field.value = secret
      field.dispatchEvent(new Event('input', { bubbles: true }))
      first.fixture.detectChanges()
      expect(readProfileRenameMemory()?.step).toBe('step-up')
      first.unmount()
      first.dispose()
      const raw = sessionStorage.getItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)!
      expect(raw).not.toContain(secret)
      expect(JSON.parse(raw)).toMatchObject({
        version: 1,
        userId: 1,
        open: true,
        step: 'step-up',
        draft: '',
        checkpoint: null,
        requestId: null,
      })

      const second = standardRenamePage(false, options)
      second.identity()
      second.state(null)
      await settleProfile(second.fixture)
      expect((second.node(fieldId) as HTMLInputElement).value).toBe('')
      expect(second.exists('profile-name-input')).toBe(false)
      expect(
        second.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
      ).toHaveLength(1)
      expect(second.names()).toHaveLength(0)
    },
  )

  it('labels the name field, traps focus, and returns focus to Change after discard', async () => {
    const page = standardRenamePage()
    const opener = page.node('profile-edit')
    opener.focus()
    await openStandardName(page)
    const input = page.node('profile-name-input') as HTMLInputElement
    expect(document.activeElement).toBe(input)
    expect(input.labels?.[0]?.textContent).toBe('Display name')
    const close = page.node('modal-close')
    close.focus()
    close.dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Tab',
        shiftKey: true,
        bubbles: true,
      }),
    )
    expect(document.activeElement).toBe(page.node('profile-rename-cancel'))
    document.activeElement?.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }),
    )
    expect(document.activeElement).toBe(close)

    editName(page.node, page.fixture, 'Carol')
    page
      .node('modal')
      .dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
    await settleProfile(page.fixture)
    expect(document.activeElement).toBe(page.node('modal-confirm'))
    page.node('modal-confirm-discard').click()
    await settleProfile(page.fixture)
    expect(page.exists('modal')).toBe(false)
    expect(document.activeElement).toBe(opener)
    expect(readProfileRenameMemory()?.open).toBe(false)
    expect(page.names()).toHaveLength(0)
  })
})

describe('the account Time zone row', () => {
  function zones(browser = 'Europe/Warsaw') {
    const binding = bindTestTimeZone(browser)
    releaseTimeZone = binding.release
    binding.receive(null)
    return binding
  }

  it('follows confirmed own and Automatic choices without replacing the page or open search', () => {
    const timeZones = zones()
    const { scopes } = accountThemePage()
    const row = element('profile-time-zone-row')
    const value = element('profile-time-zone')
    expect(row.querySelector('.fw-semibold')?.textContent?.trim()).toBe(
      'Time zone',
    )
    expect(row.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(value.textContent?.trim()).toBe('Europe/Warsaw · automatic')
    const page = scopes.page() ?? scopes.openPage(HilosPages.PROFILE)
    ingest(page, {
      lists: {
        profileTimeZones: {
          cleared: true,
          items: [{ itemKey: 'Asia/Tokyo', slots: { timeZone: 'Asia/Tokyo' } }],
        },
      },
    })
    element('profile-time-zone-change').focus()
    click('profile-time-zone-change')
    const input = element(
      'profile-time-zone-search',
    ) as unknown as HTMLInputElement
    expect(document.activeElement).toBe(input)
    input.value = 'asia'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    update()
    for (const [own, text] of [
      ['Asia/Tokyo', 'Asia/Tokyo'],
      ['Europe/Warsaw', 'Europe/Warsaw'],
      [null, 'Europe/Warsaw · automatic'],
    ] as const) {
      timeZones.receive(own)
      update()
      expect(element('profile-time-zone-row')).toBe(row)
      expect(element('profile-time-zone')).toBe(value)
      expect(value.textContent?.trim()).toBe(text)
      expect(element('profile-time-zone-search')).toBe(input)
      expect(input.value).toBe('asia')
      expect(
        element('profile-time-zone-option-Asia/Tokyo').getAttribute(
          'aria-current',
        ),
      ).toBe(own === 'Asia/Tokyo' ? 'true' : null)
    }
    click('profile-time-zone-close')
    expect(document.activeElement).toBe(element('profile-time-zone-change'))
  })

  it.each(['Asia/Kathmandu', undefined])(
    'uses project %s or UTC when the browser supplies none',
    (projectTimeZone) => {
      zones('')
      const connection = { ...fakeConnection(), projectTimeZone }
      releaseTimeZone?.()
      releaseTimeZone = bindTimeZoneState(
        connection as unknown as HilosConnection,
      )
      connection.emit('handshake_response', {
        entities: { currentUser: { id: 1, timeZone: null } },
      })
      setup(nameOnly())
      expect(element('profile-time-zone').textContent?.trim()).toBe(
        `${projectTimeZone ?? 'UTC'} · automatic`,
      )
    },
  )

  it('keeps Time zone available when theme switching is off', () => {
    zones()
    accountThemePage()
    setHilosThemePick(null)
    releaseTheme?.()
    bindTheme({ switchingEnabled: false, defaultTheme: 'system' })
    update()
    expect(document.querySelector('[data-id="profile-theme"]')).toBeNull()
    click('profile-time-zone-change')
    expect(element('profile-time-zone-automatic').textContent).toContain(
      'Europe/Warsaw — from this browser',
    )
  })

  it.each(['signout', 'impersonation'] as const)(
    'closes on %s, rejects stale gestures and stays closed when admission returns',
    (change) => {
      zones()
      const { connection, scopes, signIn, dispatch } = accountThemePage()
      click('profile-time-zone-change')
      const opener = element('profile-time-zone-change')
      const choice = element('profile-time-zone-automatic')
      if (change === 'signout')
        connection.emit('handshake_response', {
          entities: { currentUser: null },
        })
      else scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 })
      opener.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      choice.click()
      update()
      expect(
        document.querySelector('[data-id="profile-time-zone-modal"]'),
      ).toBeNull()
      expect(dispatch).not.toHaveBeenCalled()
      if (change === 'impersonation') {
        expect(opener.disabled).toBe(true)
        expect(opener.getAttribute('aria-describedby')).toBe(
          HILOS_IMPERSONATION_STRIP_TEXT_ID,
        )
      } else
        expect(document.querySelector('[data-id="profile-view"]')).toBeNull()
      scopes.session.data.set('impersonatedBy', null)
      signIn(null)
      expect(element('profile-time-zone-change').disabled).toBe(false)
      expect(
        document.querySelector('[data-id="profile-time-zone-modal"]'),
      ).toBeNull()
    },
  )

  it('keeps the placeholder for a guest and while awaiting the name', () => {
    const { fixture, node } = setup(nameOnly(), false)
    expect(node('profile-time-zone-row')).toBeNull()
    expect(node('profile-loading')).not.toBeNull()
    fixture.destroy()
    mounted = undefined
    TestBed.resetTestingModule()
    const waiting = setup({ ...nameOnly(), name: createSignal('') })
    expect(waiting.node('profile-time-zone-row')).toBeNull()
    expect(waiting.node('profile-loading')).not.toBeNull()
  })

  it('disposes the old picker on context replacement and stops the replacement on destroy', () => {
    zones()
    const { context } = accountThemePage()
    const child = (): HilosProfileTimeZone =>
      mounted!.debugElement.query(By.directive(HilosProfileTimeZone))
        .componentInstance as HilosProfileTimeZone
    const first = child().picker()
    const disposeFirst = vi.spyOn(first, 'dispose')
    const readFirst = vi.spyOn(first.rows, 'get')
    const readAutomatic = vi.spyOn(first.automatic, 'get')
    expect(readFirst).not.toHaveBeenCalled()
    click('profile-theme-change')
    const page = context.scopes.page()!
    ingest(page, {
      lists: {
        profileTimeZones: {
          cleared: true,
          items: [{ itemKey: 'UTC', slots: { timeZone: 'UTC' } }],
        },
      },
    })
    update()
    expect(readFirst).not.toHaveBeenCalled()
    expect(readAutomatic).not.toHaveBeenCalled()
    click('profile-theme-close')
    click('profile-time-zone-change')
    const removeFocus = vi.spyOn(globalThis, 'removeEventListener')
    const removeVisibility = vi.spyOn(document, 'removeEventListener')
    mounted!.componentRef.setInput('context', { ...context })
    update()
    expect(disposeFirst).toHaveBeenCalledOnce()
    expect(removeFocus).toHaveBeenCalledWith('focus', expect.any(Function))
    expect(removeVisibility).toHaveBeenCalledWith(
      'visibilitychange',
      expect.any(Function),
    )
    const second = child().picker()
    expect(second).not.toBe(first)
    const disposeSecond = vi.spyOn(second, 'dispose')
    readFirst.mockClear()
    readAutomatic.mockClear()
    first.setQuery('utc')
    window.dispatchEvent(new Event('focus'))
    update()
    expect(readFirst).not.toHaveBeenCalled()
    expect(readAutomatic).not.toHaveBeenCalled()
    mounted!.destroy()
    mounted = undefined
    expect(disposeSecond).toHaveBeenCalledOnce()
  })
})
