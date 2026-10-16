// Covers the React profile root (HIL-1169): Name with Change only for the
// project's rename, Email only with a verified address, a row per catalog
// section with its summary, a name window that still closes on the name it
// sent after StrictMode ran its effects twice, and the Theme row with its
// window (HIL-1440).
import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
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
  type HilosProfileRename,
  type HilosProfileSignInMethod,
  type HilosRouter,
  type ProjectSignal,
  type ThemePick,
  type ThemeSettings,
} from '@hilos/core'
import { HilosProfilePage } from '../src/profile/HilosProfilePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const themeActions = {
  dispatch: () => ({
    loading: createSignal(false),
    done: new Promise(() => {}),
  }),
} as unknown as ActionLifecycle

let releaseTheme: (() => void) | undefined
let releaseSession: (() => void) | undefined

afterEach(() => {
  cleanup()
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

it('offers the photo button only after the profile page enables photos', () => {
  const { node, scopes } = setup(nameOnly())
  expect(node('profile-photo-open')).toBeNull()
  act(() => scopes.openPage(HilosPages.PROFILE).data.set('profilePhoto', true))
  expect(node('profile-photo-open')?.getAttribute('aria-label')).toBe(
    'Change your photo',
  )
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
  expect(
    [...document.querySelectorAll('[data-id="profile-section"]')].map(
      (row) => row.querySelector('.fw-semibold')?.textContent,
    ),
  ).toEqual(['Ways to sign in', 'Security'])
  expect(node('profile-sign-in-summary')?.textContent).toBe(
    'No ways to sign in',
  )
  expect(node('profile-security-summary')?.textContent).toBe(
    'Two-step verification is off',
  )
  expect(node('profile-security-open')?.getAttribute('href')).toBe(
    `/${HilosPages.PROFILE_SECURITY}`,
  )
})

it('shows the Email row for a verified address and the ways-in summary', () => {
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
  expect(node('profile-sign-in-summary')?.textContent).toBe('Password')
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
  act(() => setHilosThemePick(null))
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
  render(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfilePage context={context} binding={nameOnly()} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  const themeCalls = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_theme_pick')

  return { connection, scopes, signIn, themeCalls }
}

function element(id: string): HTMLButtonElement {
  const found = document.querySelector<HTMLButtonElement>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing ${id}`)

  return found
}

it('places the Theme row after Email, or after Name without an address', () => {
  bindTheme({ switchingEnabled: true, defaultTheme: 'system' })
  setup(nameOnly(), true, VERIFIED_EMAIL)
  let rows = accountRows()
  expect(rows).toHaveLength(3)
  expect(rows[0]?.querySelector('[data-id="profile-name"]')).not.toBeNull()
  expect(rows[1]?.querySelector('[data-id="profile-email"]')).not.toBeNull()
  expect(rows[2]?.querySelector('[data-id="profile-theme"]')).not.toBeNull()
  expect(rows[2]?.querySelector('i')?.classList).toContain('bi-circle-half')
  expect(
    rows[2]?.querySelector('[data-id="profile-theme-change"]')?.textContent,
  ).toBe('Change')
  cleanup()

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
