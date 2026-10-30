// Covers the React profile root (HIL-1169): Name with Change only for the
// project's rename, Email only with a verified address, live catalog tabs,
// and a name window that still closes on the name it
// sent after StrictMode ran its effects twice, and the Theme row with its
// window (HIL-1440).
import { StrictMode, useLayoutEffect, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  bindTimeZoneState,
  applyServerTime,
  createSignal,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_PROFILE_IDENTITIES_LIST,
  HilosPages,
  ingest,
  PROFILE_RENAME_MEMORY_STORAGE_KEY,
  readProfileRenameMemory,
  resolveHilosProfileSignInMethods,
  ScopeManager,
  setHilosThemePick,
  type ActionLifecycle,
  type HilosConnection,
  type HilosProfileBinding,
  type HilosProfilePageContext,
  type HilosProfileRename,
  type HilosProfileSignInMethod,
  type HilosRouter,
  type ProjectSignal,
  type ThemePick,
  type ThemeSettings,
} from '@hilos/core'
import { HilosProfilePage } from '../src/profile/HilosProfilePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
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
const renameDisposers: Array<() => void> = []

beforeEach(() => sessionStorage.clear())
afterEach(() => {
  cleanup()
  for (const stop of renameDisposers.splice(0).reverse()) stop()
  sessionStorage.clear()
  document.body.innerHTML = ''
  releaseTheme?.()
  releaseTheme = undefined
  releaseSession?.()
  releaseSession = undefined
  releaseTimeZone?.()
  releaseTimeZone = undefined
  vi.clearAllTimers()
  vi.useRealTimers()
  vi.restoreAllMocks()
  applyServerTime(Date.now())
  setHilosThemePick(null)
  const restore = bindThemeState(
    { on: () => () => {} } as unknown as HilosConnection,
    new ScopeManager(),
    themeActions,
  )
  restore()
})

/** A connection that delivers the project signals a test emits. */
function fakeConnection() {
  const listeners: ((signal: ProjectSignal) => void)[] = []

  return {
    projectTimeZone: 'UTC',
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
      act(() => {
        for (const listener of [...listeners]) listener(signal)
      })
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
  act(() => setHilosThemePick(pick))
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
const router = {
  pageIdentity: createSignal({
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
        page: HilosPages.PROFILE_SECURITY,
        label: 'Security',
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
} as unknown as HilosRouter

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
  initialMethods?: readonly HilosProfileSignInMethod[],
) {
  const dispatch = vi.fn((action: string) => ({
    loading: createSignal(false),
    done: Promise.resolve(
      action === 'hilos_step_up_start'
        ? { reply: { required: false, purpose: 'change your name' } }
        : {},
    ),
  }))
  const scopes = new ScopeManager()
  answerProfile(scopes)
  if (signedIn) scopes.session.data.set('currentUser', { type: 'user', id: 1 })
  if (initialMethods) setMethods(scopes, initialMethods)
  const context = {
    actions: { dispatch },
    connection: { on: () => () => {} },
    scopes,
  } as unknown as HilosProfilePageContext
  render(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfilePage context={context} binding={binding} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  return {
    scopes,
    node: (id: string) =>
      document.querySelector<HTMLElement>(`[data-id="${id}"]`),
    setMethods: (next: readonly HilosProfileSignInMethod[]) =>
      act(() => setMethods(scopes, next)),
  }
}

it('opens the enabled photo window after StrictMode replay and returns focus on close', () => {
  const { node, scopes } = setup(nameOnly())
  expect(node('profile-photo-open')).toBeNull()
  act(() => scopes.page()!.data.set('profilePhoto', true))
  expect(node('profile-photo-open')?.getAttribute('aria-label')).toBe(
    'Change your photo',
  )
  const opener = node('profile-photo-open')!
  opener.focus()
  fireEvent.click(opener)
  const dialog = document.querySelector('[role="dialog"]')
  expect(dialog?.getAttribute('aria-modal')).toBe('true')
  expect(document.activeElement).toBe(dialog)
  fireEvent.click(node('profile-photo-cancel')!)
  expect(document.querySelector('[role="dialog"]')).toBeNull()
  expect(document.activeElement).toBe(opener)
})

it('shows the borrowed root name before the first paint without passive subscriptions', async () => {
  const tab = profileRenameTab()
  renameDisposers.push(tab.dispose)
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
    root.render(
      <StrictMode>
        <Frame>
          <HilosRouterContext.Provider value={router}>
            <HilosProfilePage context={tab.context} binding={tab.binding} />
          </HilosRouterContext.Provider>
        </Frame>
      </StrictMode>,
    )
    await firstPaint
  } finally {
    environment.IS_REACT_ACT_ENVIRONMENT = previous
    act(() => root.unmount())
    container.remove()
  }
  expect(name).toBe('Ann')
})

function nameOnly(): HilosProfileBinding {
  return {
    name: createSignal('Ann Lee'),
    rename: null,
    sessionCount: null,
    deviceCount: null,
  }
}

it('shows the name without Change and no Email row when the project hands neither', () => {
  const { node } = setup(nameOnly())

  expect(node('profile-identity-name')?.textContent).toBe('Ann Lee')
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

it('shows the Email row for a verified address and the shared head address', () => {
  const { node } = setup(
    nameOnly(),
    true,
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

  expect(node('profile-email')?.textContent).toBe('ann@example.test · verified')
  expect(node('profile-identity-email')?.textContent).toBe('ann@example.test')
})

it('opens the name window under StrictMode and closes it on the name it sent', async () => {
  const name = createSignal('Ann Lee')
  const sent: string[] = []
  const rename: HilosProfileRename = {
    send(next) {
      sent.push(next)
      return true
    },
    refusal: createSignal(null),
    clearRefusal: () => {},
    stepUpOperation: 'change_name',
    minLength: 2,
    maxLength: 64,
  }
  const { node } = setup({ ...nameOnly(), name, rename })

  await act(async () => {
    fireEvent.click(node('profile-edit') as HTMLElement)
  })
  const input = node('profile-name-input') as HTMLInputElement
  expect(input.value).toBe('Ann Lee')
  await act(async () => {
    fireEvent.change(input, { target: { value: 'Bea' } })
  })
  await act(async () => {
    fireEvent.click(node('profile-rename-save') as HTMLElement)
  })
  expect(sent).toEqual(['Bea'])
  await act(async () => {
    name.set('Bea')
  })
  expect(node('profile-name-input')).toBeNull()
  expect(node('profile-name')?.textContent).toBe('Bea')
})

it('draws only the placeholder while nobody is signed in', () => {
  const { node } = setup(nameOnly(), false)

  expect(node('profile-loading')).not.toBeNull()
  expect(node('profile-view')).toBeNull()
})

it('hides Security navigation while two-step verification is turned off with nothing of the person standing, live', () => {
  const { node, scopes } = setup(nameOnly())
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
  act(() => scopes.page()!.data.set('secondFactor', section))

  expect(node('hilos-page-tab-hilos_profile_security')).toBeNull()

  act(() =>
    scopes.page()!.data.set('secondFactor', { ...section, enabled: true }),
  )
  expect(node('hilos-page-tab-hilos_profile_security')).not.toBeNull()
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
  const { node, setMethods } = setup(nameOnly(), true, methods)

  expect(node('profile-sign-in-passkey-only-line')?.textContent).toBe(
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
  releaseTimeZone = bindTimeZoneState(connection as unknown as HilosConnection)
  act(() => setHilosThemePick(null))
  const signIn = (
    themePick: ThemePick,
    settings: ThemeSettings = {
      switchingEnabled: true,
      defaultTheme: 'system',
    },
    timeZone: string | null = null,
  ) =>
    connection.emit('handshake_response', {
      entities: {
        currentUser: {
          id: 1,
          name: 'Ann Lee',
          themePick,
          themePickInitialized: themePick !== null,
          timeZone,
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
  const binding = nameOnly()
  const view = render(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfilePage context={context} binding={binding} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  const themeCalls = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_theme_pick')
  const timeZoneCalls = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_time_zone_pick')

  return {
    connection,
    scopes,
    signIn,
    themeCalls,
    timeZoneCalls,
    view,
    context,
    binding,
  }
}

function element(id: string): HTMLButtonElement {
  const found = document.querySelector<HTMLButtonElement>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing ${id}`)

  return found
}

it('places Time zone before Theme, after Email or after Name without an address', () => {
  bindTheme({ switchingEnabled: true, defaultTheme: 'system' })
  setup(nameOnly(), true, VERIFIED_EMAIL)
  let rows = accountRows()
  expect(rows).toHaveLength(4)
  expect(rows[0]?.querySelector('[data-id="profile-name"]')).not.toBeNull()
  expect(rows[1]?.querySelector('[data-id="profile-email"]')).not.toBeNull()
  expect(rows[2]?.querySelector('[data-id="profile-time-zone"]')).not.toBeNull()
  expect(rows[2]?.querySelector('i')?.classList).toContain('bi-clock')
  expect(rows[2]?.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
  expect(rows[3]?.querySelector('[data-id="profile-theme"]')).not.toBeNull()
  expect(rows[3]?.querySelector('i')?.classList).toContain('bi-circle-half')
  expect(
    rows[3]?.querySelector('[data-id="profile-theme-change"]')?.textContent,
  ).toBe('Change')
  cleanup()

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
    expect(element('profile-theme').textContent).toBe(text)
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
  expect(element('profile-theme').textContent).toBe('System · default')

  act(() => setHilosThemePick('dark'))
  expect(element('profile-theme').textContent).toBe('Dark')

  connection.emit('hilos_theme_settings', {
    switchingEnabled: true,
    defaultTheme: 'light',
  })
  act(() => setHilosThemePick(null))
  expect(element('profile-theme').textContent).toBe('Light · default')

  connection.emit('hilos_theme_settings', {
    switchingEnabled: false,
    defaultTheme: 'light',
  })
  expect(document.querySelector('[data-id="profile-theme"]')).toBeNull()

  connection.emit('hilos_theme_settings', {
    switchingEnabled: true,
    defaultTheme: 'light',
  })
  expect(element('profile-theme').textContent).toBe('Light · default')
})

it('opens the framework theme window without a project rename and returns focus on Close', () => {
  const { themeCalls } = accountThemePage()
  const change = element('profile-theme-change')
  expect(document.querySelector('[data-id="profile-edit"]')).toBeNull()
  change.focus()
  fireEvent.click(change)
  const modal = element('profile-theme-modal')
  expect(modal.querySelectorAll('.list-group button')).toHaveLength(3)
  expect(element('profile-theme-option-system').textContent).toContain(
    'default',
  )
  fireEvent.click(element('profile-theme-close'))
  expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
  expect(document.activeElement).toBe(change)
  expect(themeCalls()).toEqual([])
})

it('updates both the profile row and the open window through the server echo', () => {
  const { signIn, themeCalls } = accountThemePage()
  fireEvent.click(element('profile-theme-change'))
  signIn('dark')
  expect(element('profile-theme').textContent).toBe('Dark')
  expect(
    element('profile-theme-option-dark').getAttribute('aria-current'),
  ).toBe('true')
  expect(themeCalls()).toEqual([])
})

it('sends a repeated default from the window and moves the row only on the account frame', async () => {
  const { signIn, themeCalls } = accountThemePage()
  fireEvent.click(element('profile-theme-change'))
  await act(async () => {
    fireEvent.click(element('profile-theme-option-system'))
  })
  expect(themeCalls()).toEqual([['hilos_theme_pick', { themePick: 'system' }]])
  expect(element('profile-theme').textContent).toBe('System · default')
  signIn('system')
  expect(element('profile-theme').textContent).toBe('System')
})

it('keeps Change visible but closed under a takeover, with the strip as its reason', () => {
  const { scopes } = accountThemePage()
  act(() => scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }))
  const change = element('profile-theme-change')
  expect(change.disabled).toBe(true)
  expect(change.getAttribute('aria-describedby')).toBe(
    HILOS_IMPERSONATION_STRIP_TEXT_ID,
  )
  fireEvent.click(change)
  expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
})

it.each(['off', 'signout', 'impersonation'] as const)(
  'closes an open theme window on %s and keeps it closed when availability returns',
  (change) => {
    const { connection, scopes, signIn } = accountThemePage()
    fireEvent.click(element('profile-theme-change'))
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
    else
      act(() =>
        scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }),
      )
    expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
    if (change === 'off')
      expect(
        document.querySelector('[data-id="profile-theme-change"]'),
      ).toBeNull()
    if (change === 'signout')
      expect(document.querySelector('[data-id="profile-view"]')).toBeNull()
    act(() => scopes.session.data.set('impersonatedBy', null))
    signIn('light', { switchingEnabled: true, defaultTheme: 'light' })
    expect(
      document.querySelector('[data-id="profile-theme-change"]'),
    ).not.toBeNull()
    expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
  },
)

it('keeps the effect-owned time zone picker live after StrictMode replay', async () => {
  vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout'] })
  vi.setSystemTime(new Date('2026-01-01T00:00:10Z'))
  applyServerTime(Date.now())
  const resolved = new Intl.DateTimeFormat().resolvedOptions()
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    ...resolved,
    timeZone: 'Europe/Warsaw',
  })
  const { scopes, signIn, timeZoneCalls } = accountThemePage()
  act(() =>
    ingest(scopes.page() ?? scopes.openPage(HilosPages.PROFILE), {
      lists: {
        profileTimeZones: {
          cleared: true,
          items: ['UTC', 'Asia/Tokyo', 'Europe/Warsaw'].map((timeZone) => ({
            itemKey: timeZone,
            slots: { timeZone },
          })),
        },
      },
    }),
  )
  expect(element('profile-time-zone').textContent).toBe(
    'Europe/Warsaw · automatic',
  )
  expect(
    element('profile-time-zone-row').querySelector('.fw-semibold')?.textContent,
  ).toBe('Time zone')
  const change = element('profile-time-zone-change')
  change.focus()
  fireEvent.click(change)
  expect(document.activeElement).toBe(element('profile-time-zone-search'))
  expect(element('profile-time-zone-option-UTC').textContent).toContain('00:00')
  await act(async () => {
    await vi.advanceTimersByTimeAsync(50000)
  })
  expect(element('profile-time-zone-option-UTC').textContent).toContain('00:01')
  fireEvent.change(element('profile-time-zone-search'), {
    target: { value: 'tokyo' },
  })
  expect(
    element('profile-time-zone-modal').querySelectorAll('.list-group button'),
  ).toHaveLength(1)
  signIn(null, undefined, 'Asia/Tokyo')
  expect(element('profile-time-zone').textContent).toBe('Asia/Tokyo')
  expect(
    element('profile-time-zone-option-Asia/Tokyo').getAttribute('aria-current'),
  ).toBe('true')
  expect(
    (element('profile-time-zone-search') as unknown as HTMLInputElement).value,
  ).toBe('tokyo')
  await act(async () => {
    fireEvent.click(element('profile-time-zone-option-Asia/Tokyo'))
  })
  expect(timeZoneCalls()).toEqual([
    ['hilos_time_zone_pick', { timeZone: 'Asia/Tokyo' }],
  ])
  expect(
    document.querySelector('[data-id="profile-time-zone-modal"]'),
  ).toBeNull()
  expect(document.activeElement).toBe(change)
  fireEvent.click(change)
  await act(async () => {
    fireEvent.click(element('profile-time-zone-automatic'))
  })
  expect(timeZoneCalls()).toEqual([
    ['hilos_time_zone_pick', { timeZone: 'Asia/Tokyo' }],
    ['hilos_time_zone_pick', { timeZone: null }],
  ])
  expect(element('profile-time-zone').textContent).toBe('Asia/Tokyo')
  signIn(null, undefined, null)
  expect(element('profile-time-zone').textContent).toBe(
    'Europe/Warsaw · automatic',
  )
})

it('replaces the time zone owner on context change and sends only through the current picker', async () => {
  const tab = accountThemePage()
  tab.signIn(null, undefined, 'Asia/Tokyo')
  fireEvent.click(element('profile-time-zone-change'))
  fireEvent.change(element('profile-time-zone-search'), {
    target: { value: 'old search' },
  })
  const dispatch = vi.fn(() => ({
    loading: createSignal(false),
    done: Promise.resolve({}),
  }))
  const next = {
    ...tab.context,
    actions: { dispatch } as unknown as ActionLifecycle,
  }
  tab.view.rerender(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfilePage context={next} binding={tab.binding} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  fireEvent.change(element('profile-time-zone-search'), {
    target: { value: 'tokyo' },
  })
  expect(
    element('profile-time-zone-option-Asia/Tokyo').getAttribute('aria-current'),
  ).toBe('true')
  await act(async () => {
    fireEvent.click(element('profile-time-zone-option-Asia/Tokyo'))
  })
  expect(tab.timeZoneCalls()).toEqual([])
  expect(dispatch).toHaveBeenCalledWith('hilos_time_zone_pick', {
    timeZone: 'Asia/Tokyo',
  })
  expect(
    document.querySelector('[data-id="profile-time-zone-modal"]'),
  ).toBeNull()
})

it('describes the time zone Change lock with the impersonation strip', () => {
  const { scopes } = accountThemePage()
  act(() => scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }))
  const change = element('profile-time-zone-change')
  expect(change.disabled).toBe(true)
  expect(change.getAttribute('aria-describedby')).toBe(
    HILOS_IMPERSONATION_STRIP_TEXT_ID,
  )
  fireEvent.click(change)
  expect(
    document.querySelector('[data-id="profile-time-zone-modal"]'),
  ).toBeNull()
})

it.each(['signout', 'impersonation'] as const)(
  'takes the time zone window away on %s and keeps it closed after return',
  (cause) => {
    const { scopes, connection, signIn } = accountThemePage()
    fireEvent.click(element('profile-time-zone-change'))
    expect(
      document.querySelector('[data-id="profile-time-zone-modal"]'),
    ).not.toBeNull()
    if (cause === 'signout')
      connection.emit('handshake_response', { entities: { currentUser: null } })
    else
      act(() =>
        scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }),
      )
    expect(
      document.querySelector('[data-id="profile-time-zone-modal"]'),
    ).toBeNull()
    act(() => scopes.session.data.set('impersonatedBy', null))
    signIn(null)
    expect(
      document.querySelector('[data-id="profile-time-zone-modal"]'),
    ).toBeNull()
  },
)

/** The standard binding mounted under StrictMode, including effect replay. */
function renamePage(
  known = true,
  options: Parameters<typeof profileRenameTab>[1] = {},
) {
  const tab = profileRenameTab(known, options)
  renameDisposers.push(tab.dispose)
  const view = render(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfilePage context={tab.context} binding={tab.binding} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  return { ...tab, view }
}

function renameNode(id: string): HTMLElement {
  const node = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!node) throw new Error(`Missing ${id}`)
  return node
}

function nameInput(): HTMLInputElement {
  return renameNode('profile-name-input') as HTMLInputElement
}

async function openName(): Promise<void> {
  await act(async () => {
    fireEvent.click(renameNode('profile-edit'))
  })
}

async function sendName(tab: ReturnType<typeof renamePage>): Promise<void> {
  await openName()
  fireEvent.change(nameInput(), { target: { value: 'Bob' } })
  fireEvent.click(renameNode('profile-rename-save'))
  expect(tab.names()).toHaveLength(1)
}

// Component remount exercises the tab checkpoint; a real browser reload is
// covered by the separate end-to-end scenario.
describe('standard name window tab lifecycle', () => {
  it('starts restore after StrictMode replay, waits for known owner and STATE, and keeps an invalid draft', async () => {
    const first = renamePage()
    await openName()
    fireEvent.change(nameInput(), { target: { value: ' x ' } })
    first.view.unmount()
    expect(readProfileRenameMemory()?.open).toBe(true)
    expect(readProfileRenameMemory()?.draft).toBe(' x ')
    first.dispose()

    const second = renamePage(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.querySelector('[data-id="profile-view"]')).toBeNull()
    expect(readProfileRenameMemory()?.open).toBe(true)
    await act(async () => {
      second.identity()
    })
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    await act(async () => {
      second.state(null)
    })
    expect(nameInput().value).toBe(' x ')
    expect(renameNode('profile-rename-save').hasAttribute('disabled')).toBe(
      true,
    )
    expect(first.names()).toHaveLength(0)
    expect(second.names()).toHaveLength(0)
    expect(
      second.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
    ).toHaveLength(1)
  })

  it.each(['checking', 'applying'] as const)(
    'restores open %s and newer input without another name send or step-up start',
    async (phase) => {
      const first = renamePage()
      await sendName(first)
      act(() => first.state(phase))
      fireEvent.change(nameInput(), { target: { value: 'Carol' } })
      first.view.unmount()
      first.dispose()
      expect(readProfileRenameMemory()?.open).toBe(true)
      const second = renamePage(false)
      await act(async () => {
        second.identity()
        second.state(phase)
      })
      expect(nameInput().value).toBe('Carol')
      expect(profileNameStatus().textContent).toBe(
        phase === 'checking' ? 'Checking your name…' : '',
      )
      expect(renameNode('profile-name').textContent).toBe('Ann')
      expect(renameNode('profile-rename-save').hasAttribute('disabled')).toBe(
        true,
      )
      expect(second.names()).toHaveLength(0)
      expect(
        second.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
      ).toHaveLength(0)
      act(() => {
        second.identity(1, 'Bob')
        second.state('done')
      })
      expect(nameInput().value).toBe('Carol')
      expect(
        document.querySelector('[data-id="profile-edit-notice"]'),
      ).toBeNull()
      expect(
        renameNode('profile-edit-notice-idle').getAttribute('aria-hidden'),
      ).toBe('true')
      expect(renameNode('profile-rename-save').hasAttribute('disabled')).toBe(
        false,
      )
    },
  )

  it('restores exact refused text without sending again', async () => {
    const first = renamePage()
    await sendName(first)
    act(() => first.state('refused'))
    first.view.unmount()
    first.dispose()
    const second = renamePage(false)
    await act(async () => {
      second.identity()
      second.state('refused')
    })
    expect(nameInput().value).toBe('Bob')
    expect(renameNode('profile-rename-live-assertive').textContent).toBe(
      ' Exact server refusal.\nTry another name. ',
    )
    expect(renameNode('profile-rename-save').hasAttribute('disabled')).toBe(
      false,
    )
    expect(second.names()).toHaveLength(0)
  })

  it.each(['refused', 'done'] as const)(
    'preserves explicit close across remount and late %s until manual Change',
    async (phase) => {
      const first = renamePage()
      await sendName(first)
      act(() => first.state('checking'))
      fireEvent.click(renameNode('profile-rename-cancel'))
      fireEvent.click(renameNode('modal-confirm-discard'))
      expect(readProfileRenameMemory()?.open).toBe(false)
      first.view.unmount()
      first.dispose()
      const second = renamePage(false)
      await act(async () => {
        second.identity()
        second.state('checking')
      })
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      await act(async () => {
        second.state(phase)
        if (phase === 'done') second.identity(1, 'Bob')
      })
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(readProfileRenameMemory()?.open).toBe(false)
      expect(
        second.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
      ).toHaveLength(0)
      await openName()
      expect(nameInput().value).toBe('Bob')
      expect(renameNode('profile-rename-save').hasAttribute('disabled')).toBe(
        phase === 'done',
      )
      expect(second.names()).toHaveLength(0)
    },
  )

  it('keeps a new tab closed when server state arrives without its open checkpoint', async () => {
    const tab = renamePage()
    act(() => {
      tab.state('checking')
      tab.state('refused')
    })
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
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
      tab.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
    ).toHaveLength(0)
  })

  it.each(['signout', 'person', 'access'] as const)(
    'discards the old window on %s and ignores late state over a new surface',
    async (cause) => {
      const tab = renamePage()
      await sendName(tab)
      await act(async () => {
        tab.state('checking')
        if (cause === 'signout') tab.identity(null)
        if (cause === 'person') tab.identity(2, 'Another person')
        if (cause === 'access') tab.identity(1, 'Ann', { accountBlocked: {} })
      })
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(readProfileRenameMemory()).toBeNull()
      if (cause === 'signout')
        expect(document.querySelector('[data-id="profile-view"]')).toBeNull()
      await act(async () => {
        tab.state('done')
        tab.identity(1, 'Bob')
      })
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(readProfileRenameMemory()).toBeNull()
      expect(tab.names()).toHaveLength(1)
    },
  )

  it.each([
    ['password', 'step-up-password', 'secret-password'],
    ['email_code', 'step-up-code', '123456'],
  ])(
    'rechecks %s on remount and never persists its credential or proof',
    async (method, fieldId, secret) => {
      const options = {
        stepUp: { required: true, purpose: 'change your name', method },
      }
      const first = renamePage(true, options)
      await openName()
      fireEvent.change(renameNode(fieldId), { target: { value: secret } })
      expect(readProfileRenameMemory()?.step).toBe('step-up')
      first.view.unmount()
      first.dispose()
      const raw = sessionStorage.getItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)!
      expect(raw).not.toContain(secret)
      expect(JSON.parse(raw)).toEqual({
        version: 1,
        userId: 1,
        open: true,
        step: 'step-up',
        draft: '',
        checkpoint: null,
        requestId: null,
      })
      const second = renamePage(false, options)
      await act(async () => {
        second.identity()
        second.state(null)
      })
      expect((renameNode(fieldId) as HTMLInputElement).value).toBe('')
      expect(
        document.querySelector('[data-id="profile-name-input"]'),
      ).toBeNull()
      expect(
        second.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
      ).toHaveLength(1)
      expect(second.names()).toHaveLength(0)
    },
  )

  it('focuses the name, traps Tab and ShiftTab, and returns to Change after Escape and discard', async () => {
    const tab = renamePage()
    const opener = renameNode('profile-edit')
    opener.focus()
    await openName()
    expect(document.activeElement).toBe(nameInput())
    expect(nameInput().labels?.[0]?.textContent).toBe('Display name')
    const close = renameNode('modal-close')
    close.focus()
    fireEvent.keyDown(close, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(renameNode('profile-rename-cancel'))
    fireEvent.keyDown(document.activeElement!, { key: 'Tab' })
    expect(document.activeElement).toBe(close)
    fireEvent.change(nameInput(), { target: { value: 'Carol' } })
    fireEvent.keyDown(renameNode('modal'), { key: 'Escape' })
    expect(document.activeElement).toBe(renameNode('modal-confirm'))
    fireEvent.click(renameNode('modal-confirm-discard'))
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.activeElement).toBe(opener)
    expect(readProfileRenameMemory()?.open).toBe(false)
    expect(tab.names()).toHaveLength(0)
  })
})
