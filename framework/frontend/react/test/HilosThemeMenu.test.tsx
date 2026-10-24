// Covers the React header theme menu (HIL-1440) on the real core: the theme
// binding bootHilos makes, a real action lifecycle with request correlation, and
// server frames that are the only authority over an account's choice.
import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  HILOS_CONNECTION_STATUS_TEXT_ID,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  hilosThemePick,
  hilosToasts,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  ScopeManager,
  setHilosThemePick,
  type ActionLifecycleSource,
  type ConnectionState,
  type HilosConnection,
  type ThemePick,
  type ThemeSettings,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
} from '../../core/src/theme/themeBrowser.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosThemeMenu } from '../src/HilosThemeMenu.js'

function dummyConnection(): HilosConnection {
  return { on: () => () => {} } as unknown as HilosConnection
}

const themeActions = new ActionLifecycle({
  sendAction: () => true,
  on: () => () => {},
})

let releaseTheme: (() => void) | undefined
let releaseSession: (() => void) | undefined

// A store the test fills by construction. Calling the browser's write from this
// file would be a value left undeclared (BROWSER-VALUE-DECLARED); the real
// declarations live beside the keys in themeBrowser.ts.
function memoryStorage(entries: Record<string, string>): Storage {
  const store = new Map(Object.entries(entries))

  return {
    get length() {
      return store.size
    },
    clear() {
      store.clear()
    },
    getItem(key: string) {
      return store.get(key) ?? null
    },
    key(index: number) {
      return [...store.keys()][index] ?? null
    },
    removeItem(key: string) {
      store.delete(key)
    },
    setItem(key: string, value: string) {
      store.set(key, value)
    },
  }
}

/** One boot binding with real request correlation, driven by server frames. */
function bindAccount(
  pick: ThemePick = 'dark',
  settings: ThemeSettings = { switchingEnabled: true, defaultTheme: 'light' },
) {
  vi.useFakeTimers()
  hilosToasts.clear()
  releaseSession?.()
  releaseTheme?.()
  vi.stubGlobal('localStorage', memoryStorage({}))
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const emitRaw = (event: string, data: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(data as never)
  }
  const emit = (event: string, data: unknown): void => {
    act(() => {
      emitRaw(event, data)
    })
  }
  const sendAction = vi.fn<
    (action: string, data: unknown, requestId?: string) => boolean
  >(() => true)
  let connectionState: ConnectionState = 'connected'
  const connection = {
    get state(): ConnectionState {
      return connectionState
    },
    get protectedMode() {
      return PROTECTED_MODE_INACTIVE
    },
    get rtStaleness() {
      return RT_STALENESS_FRESH
    },
    get reconnectDragging(): boolean {
      return false
    },
    get firstFrameHeld(): boolean {
      return false
    },
    sendAction,
    on(event: string, listener: (payload: never) => void): () => void {
      const callbacks = listeners.get(event) ?? new Set()
      callbacks.add(listener)
      listeners.set(event, callbacks)

      return () => callbacks.delete(listener)
    },
  }
  const scopes = new ScopeManager()
  const actions = new ActionLifecycle(
    connection as unknown as ActionLifecycleSource,
  )
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  const unbind = bindSignOut(scopes, actions)
  const unbindImpersonation = bindImpersonation(scopes, actions)
  releaseSession = () => {
    unbind()
    unbindImpersonation()
    listeners.clear()
  }
  releaseTheme = bindThemeState(
    connection as unknown as HilosConnection,
    scopes,
    actions,
  )

  function handshakeWith(
    dispatch: (event: string, data: unknown) => void,
    themePick: ThemePick,
    userId: number | null = 1,
    themePickInitialized = themePick !== null,
  ): void {
    dispatch('projectSignal', {
      type: 'handshake_response',
      data: {
        entities: {
          currentUser:
            userId === null
              ? null
              : {
                  id: userId,
                  name: 'Ada',
                  themePick,
                  themePickInitialized,
                },
        },
        data: { themeSettings: settings },
      },
    })
  }
  const handshake = (
    themePick: ThemePick,
    userId: number | null = 1,
    themePickInitialized = themePick !== null,
  ): void => handshakeWith(emit, themePick, userId, themePickInitialized)
  const handshakeRaw = (
    themePick: ThemePick,
    userId: number | null = 1,
    themePickInitialized = themePick !== null,
  ): void => handshakeWith(emitRaw, themePick, userId, themePickInitialized)
  handshake(pick)

  return {
    sendAction,
    connection: connection as unknown as HilosConnection,
    handshake,
    handshakeRaw,
    impersonate: () =>
      act(() =>
        scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }),
      ),
    settings: (next: ThemeSettings) =>
      emit('projectSignal', { type: 'hilos_theme_settings', data: next }),
    state: (state: ConnectionState) => {
      connectionState = state
      emit('state', state)
    },
    stateRaw: (state: ConnectionState) => {
      connectionState = state
      emitRaw('state', state)
    },
    async reply(
      outcome: 'success' | 'fail',
      requestId = sendAction.mock.lastCall?.[2],
    ): Promise<void> {
      await act(async () => {
        for (const listener of listeners.get(
          outcome === 'success' ? 'actionSuccess' : 'actionError',
        ) ?? [])
          listener({
            action: 'hilos_theme_pick',
            requestId,
            reason: 'Theme switching is disabled.',
          } as never)
      })
    },
  }
}

/** A guest binding over a remembered settings pair and pick. */
function bindSnapshot(settings: ThemeSettings, pick: ThemePick): void {
  act(() => {
    releaseTheme?.()
    const entries: Record<string, string> = {
      [THEME_SETTINGS_STORAGE_KEY]: JSON.stringify(settings),
    }
    if (pick !== null) {
      entries[THEME_PICK_STORAGE_KEY] = pick
    }
    vi.stubGlobal('localStorage', memoryStorage(entries))
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
    releaseTheme = bindThemeState(
      dummyConnection(),
      new ScopeManager(),
      themeActions,
    )
  })
}

function restoreCatalog(): void {
  releaseTheme?.()
  releaseTheme = undefined
  vi.stubGlobal('localStorage', memoryStorage({}))
  const restore = bindThemeState(
    dummyConnection(),
    new ScopeManager(),
    themeActions,
  )
  restore()
  vi.unstubAllGlobals()
}

afterEach(() => {
  cleanup()
  document.body.innerHTML = ''
  releaseSession?.()
  releaseSession = undefined
  restoreCatalog()
  vi.restoreAllMocks()
  vi.useRealTimers()
  hilosToasts.clear()
})

function byId(id: string): HTMLButtonElement {
  const element = document.querySelector<HTMLButtonElement>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing ${id}`)

  return element
}

function maybe(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function toggle(): HTMLButtonElement {
  return byId('nav-theme')
}

function rows(): HTMLButtonElement[] {
  return [
    ...document.querySelectorAll<HTMLButtonElement>(
      '[data-id="nav-theme-menu"] [role="menuitemradio"]',
    ),
  ]
}

/**
 * Move focus off the toggle the way Chromium does when a button turns disabled;
 * the DOM emulation keeps it there, and its blur() refuses a disabled button.
 */
function dropFocus(): void {
  const elsewhere = document.createElement('button')
  document.body.append(elsewhere)
  elsewhere.focus()
}

function click(id: string): void {
  fireEvent.click(byId(id))
}

function key(element: Element, name: string): void {
  fireEvent.keyDown(element, { key: name })
}

/** Let the frame the keyboard opening waits for pass. */
async function nextFrame(): Promise<void> {
  await act(async () => {
    if (vi.isFakeTimers()) {
      vi.advanceTimersToNextFrame()
    } else {
      await new Promise<void>((resolve) =>
        requestAnimationFrame(() => resolve()),
      )
    }
  })
}

async function settle(): Promise<void> {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0)
  })
}

function mountMenu() {
  return render(<HilosThemeMenu />)
}

/** Mount the theme control under the real shell's live interaction context. */
function mountInShell(
  connection: HilosConnection,
  allowDisconnectedInteraction = false,
) {
  return render(
    <HilosLayout
      connection={connection}
      allowDisconnectedInteraction={allowDisconnectedInteraction}
    >
      <p data-id="page-body">Page</p>
    </HilosLayout>,
  )
}

describe('HilosThemeMenu', () => {
  it('locks an open account menu while unavailable and sends only a new choice after reconnect', async () => {
    const server = bindAccount('dark')
    const view = mountInShell(server.connection, true)
    fireEvent.click(toggle())
    const menu = byId('nav-theme-menu')
    const light = byId('nav-theme-option-light')

    for (const state of [
      'connecting',
      'reconnecting',
      'disconnected',
    ] as const) {
      if (state === 'connecting') {
        act(() => {
          server.stateRaw(state)
          fireEvent.click(byId('nav-theme-default'))
          fireEvent.click(light)
        })
        expect(server.sendAction).not.toHaveBeenCalled()
        expect(hilosThemePick.get()).toBe('dark')
      } else {
        act(() => server.stateRaw(state))
      }
      expect(byId('nav-theme-menu')).toBe(menu)
      expect(toggle().getAttribute('aria-expanded')).toBe('true')
      expect(toggle().disabled).toBe(true)
      expect(toggle().getAttribute('aria-describedby')).toBe(
        HILOS_CONNECTION_STATUS_TEXT_ID,
      )
      expect(
        view.container.querySelector(`#${HILOS_CONNECTION_STATUS_TEXT_ID}`),
      ).not.toBeNull()
      for (const id of [
        'nav-theme-option-light',
        'nav-theme-option-dark',
        'nav-theme-option-system',
        'nav-theme-default',
      ]) {
        expect(byId(id).disabled).toBe(true)
        expect(byId(id).getAttribute('aria-describedby')).toBe(
          HILOS_CONNECTION_STATUS_TEXT_ID,
        )
      }
      fireEvent.keyDown(menu, { key: 'Home' })
      expect(
        [
          ...view.container.querySelectorAll<HTMLButtonElement>(
            '[data-id="nav-theme-menu"] button',
          ),
        ].some(
          (button) => button.disabled && button === document.activeElement,
        ),
      ).toBe(false)
      expect(maybe('nav-theme-loading')).toBeNull()
    }

    act(() => server.stateRaw('connected'))
    expect(byId('nav-theme-menu')).toBe(menu)
    expect(toggle().disabled).toBe(false)
    expect(byId('nav-theme-option-light')).toBe(light)
    fireEvent.click(light)
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_theme_pick',
      { themePick: 'light' },
      '1',
    )
    expect(hilosThemePick.get()).toBe('dark')
    expect(maybe('nav-theme-loading')).toBeNull()
    await server.reply('success')
    expect(hilosThemePick.get()).toBe('dark')
  })

  it('keeps every guest theme choice and reset local during disconnect states', () => {
    const server = bindAccount('dark')
    server.handshake('dark', null)
    const view = mountInShell(server.connection)

    for (const [state, pick] of [
      ['connecting', 'light'],
      ['reconnecting', 'dark'],
      ['disconnected', 'system'],
    ] as const) {
      act(() => server.stateRaw(state))
      expect(toggle().disabled).toBe(false)
      expect(toggle().getAttribute('aria-describedby')).toBeNull()
      fireEvent.click(toggle())
      fireEvent.click(byId(`nav-theme-option-${pick}`))
      expect(hilosThemePick.get()).toBe(pick)
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe(pick)
    }

    for (let attempt = 0; attempt < 2; attempt++) {
      fireEvent.click(toggle())
      fireEvent.click(byId('nav-theme-default'))
      expect(hilosThemePick.get()).toBeNull()
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBeNull()
    }
    expect(server.sendAction).not.toHaveBeenCalled()
    expect(
      view.container.querySelector('[data-id="nav-theme-menu"]'),
    ).toBeNull()
  })

  it('reads sign-in and availability live before React flushes the open menu', () => {
    const server = bindAccount('dark')
    server.handshake('dark', null)
    mountInShell(server.connection, true)
    fireEvent.click(toggle())
    const light = byId('nav-theme-option-light')

    act(() => {
      server.stateRaw('disconnected')
      server.handshakeRaw('dark', 1)
      fireEvent.click(light)
    })

    expect(server.sendAction).not.toHaveBeenCalled()
    expect(hilosThemePick.get()).toBe('dark')
    expect(toggle().disabled).toBe(true)
    expect(toggle().getAttribute('aria-describedby')).toBe(
      HILOS_CONNECTION_STATUS_TEXT_ID,
    )
  })

  it('keeps the impersonation and connection reasons on a disabled toggle', () => {
    const server = bindAccount('dark')
    server.impersonate()
    server.state('disconnected')
    const view = mountInShell(server.connection)
    const theme = toggle()

    expect(theme.disabled).toBe(true)
    expect(theme.getAttribute('aria-describedby')).toBe(
      `${HILOS_IMPERSONATION_STRIP_TEXT_ID} ${HILOS_CONNECTION_STATUS_TEXT_ID}`,
    )
    expect(
      view.container.querySelector(`#${HILOS_IMPERSONATION_STRIP_TEXT_ID}`),
    ).not.toBeNull()
    expect(
      view.container.querySelector(`#${HILOS_CONNECTION_STATUS_TEXT_ID}`),
    ).not.toBeNull()
  })

  it('uses the readonly false connection fallback outside the shell', () => {
    const server = bindAccount('dark')
    server.state('disconnected')
    mountMenu()

    expect(toggle().disabled).toBe(false)
    expect(toggle().getAttribute('aria-describedby')).toBeNull()
    expect(document.getElementById(HILOS_CONNECTION_STATUS_TEXT_ID)).toBeNull()
    fireEvent.click(toggle())
    expect(byId('nav-theme-option-light').disabled).toBe(false)
  })

  it('keeps a pending action busy when the connection reconnects', async () => {
    const server = bindAccount('dark')
    mountInShell(server.connection)
    fireEvent.click(toggle())
    fireEvent.click(byId('nav-theme-option-light'))
    expect(toggle().disabled).toBe(true)
    expect(toggle().getAttribute('aria-busy')).toBe('true')

    server.state('connecting')
    expect(toggle().getAttribute('aria-describedby')).toBe(
      HILOS_CONNECTION_STATUS_TEXT_ID,
    )
    server.state('connected')
    expect(toggle().disabled).toBe(true)
    expect(toggle().getAttribute('aria-busy')).toBe('true')
    expect(toggle().getAttribute('aria-describedby')).toBeNull()
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300)
    })
    expect(maybe('nav-theme-loading')).not.toBeNull()
    await server.reply('success')
    expect(toggle().disabled).toBe(false)
    expect(hilosThemePick.get()).toBe('dark')
  })

  it('shows the position icon and its Theme label', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, 'light')
    mountMenu()

    expect(toggle().querySelector('i')?.classList).toContain('bi-sun')
    expect(toggle().title).toBe('Theme: Light')
    expect(toggle().getAttribute('aria-label')).toBe('Theme: Light')
    expect(toggle().dataset.position).toBe('light')
    expect(toggle().getAttribute('aria-haspopup')).toBe('menu')

    act(() => setHilosThemePick('dark'))
    expect(toggle().querySelector('i')?.classList).toContain('bi-moon-stars')
    expect(toggle().title).toBe('Theme: Dark')

    act(() => setHilosThemePick('system'))
    expect(toggle().querySelector('i')?.classList).toContain('bi-circle-half')
    expect(toggle().title).toBe('Theme: System')
  })

  it('checks the default position and badges it only when nothing was picked', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'dark' }, null)
    mountMenu()
    click('nav-theme')

    const marked = byId('nav-theme-option-dark')
    expect(byId('nav-theme-menu').id).toBe(
      toggle().getAttribute('aria-controls'),
    )
    expect(byId('nav-theme-menu').getAttribute('role')).toBe('menu')
    expect(byId('nav-theme-menu').getAttribute('aria-label')).toBe('Theme')
    expect(marked.classList).toContain('active')
    expect(marked.getAttribute('aria-checked')).toBe('true')
    expect(marked.textContent).toContain('default')
    expect(marked.querySelector('.bi-check2')).not.toBeNull()
    expect(marked.querySelector('.badge')?.classList).toContain(
      'bg-body-tertiary',
    )

    const light = byId('nav-theme-option-light')
    expect(light.getAttribute('aria-checked')).toBe('false')
    expect(light.classList).not.toContain('active')
    expect(light.textContent).not.toContain('default')
    expect(light.querySelector('.bi-check2')).toBeNull()

    act(() => setHilosThemePick('dark'))
    expect(marked.textContent).not.toContain('default')
    expect(marked.querySelector('.bi-check2')).not.toBeNull()
    expect(marked.getAttribute('aria-checked')).toBe('true')
  })

  it('draws nothing when switching is off', () => {
    bindSnapshot({ switchingEnabled: false, defaultTheme: 'light' }, 'dark')
    mountMenu()

    expect(maybe('nav-theme')).toBeNull()
    expect(maybe('nav-theme-menu')).toBeNull()
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
  })

  it('redraws an open menu and the icon when the choice changes', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    mountMenu()
    click('nav-theme')
    expect(toggle().getAttribute('aria-expanded')).toBe('true')
    expect(byId('nav-theme-option-dark').getAttribute('aria-checked')).toBe(
      'true',
    )

    act(() => setHilosThemePick('system'))
    expect(toggle().getAttribute('aria-expanded')).toBe('true')
    expect(toggle().dataset.position).toBe('system')
    expect(toggle().querySelector('i')?.classList).toContain('bi-circle-half')
    expect(byId('nav-theme-option-system').getAttribute('aria-checked')).toBe(
      'true',
    )
    expect(byId('nav-theme-option-dark').getAttribute('aria-checked')).toBe(
      'false',
    )
  })

  it('roves the four controls and returns focus on Escape', async () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, null)
    mountMenu()

    key(toggle(), 'ArrowDown')
    await nextFrame()
    const menu = byId('nav-theme-menu')
    const buttons = [...rows(), byId('nav-theme-default')]
    expect(buttons).toHaveLength(4)
    expect(document.activeElement).toBe(buttons[0])

    key(menu, 'ArrowDown')
    expect(document.activeElement).toBe(buttons[1])
    key(menu, 'ArrowUp')
    expect(document.activeElement).toBe(buttons[0])
    key(menu, 'ArrowUp')
    expect(document.activeElement).toBe(buttons[3])
    key(menu, 'ArrowUp')
    expect(document.activeElement).toBe(buttons[2])
    key(menu, 'Home')
    expect(document.activeElement).toBe(buttons[0])
    key(menu, 'End')
    expect(document.activeElement).toBe(buttons[3])
    key(menu, 'ArrowDown')
    expect(document.activeElement).toBe(buttons[0])

    key(menu, 'Escape')
    expect(toggle().getAttribute('aria-expanded')).toBe('false')
    expect(maybe('nav-theme-menu')).toBeNull()
    expect(document.activeElement).toBe(toggle())

    key(toggle(), 'ArrowUp')
    await nextFrame()
    expect(document.activeElement).toBe(byId('nav-theme-default'))
    key(toggle(), 'Escape')
    expect(maybe('nav-theme-menu')).toBeNull()
    expect(document.activeElement).toBe(toggle())
  })

  it('cancels a pending keyboard focus when it unmounts', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, null)
    const cancel = vi.spyOn(globalThis, 'cancelAnimationFrame')
    const view = mountMenu()
    key(toggle(), 'ArrowDown')
    view.unmount()

    expect(cancel).toHaveBeenCalled()
  })

  it('closes on a click outside the menu but not inside it, without taking focus', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, 'light')
    mountMenu()

    click('nav-theme')
    fireEvent.click(byId('nav-theme-menu'))
    expect(toggle().getAttribute('aria-expanded')).toBe('true')

    const outside = document.createElement('button')
    document.body.append(outside)
    outside.focus()
    fireEvent.click(document.body)
    expect(toggle().getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(outside)
    outside.remove()
  })

  it.each([
    ['light', 'light', 'bi-sun'],
    ['dark', 'dark', 'bi-moon-stars'],
    ['system', 'light', 'bi-circle-half'],
  ] as const)(
    'applies and remembers the guest choice %s',
    (pick, mode, icon) => {
      bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, null)
      mountMenu()
      click('nav-theme')

      click(`nav-theme-option-${pick}`)

      expect(hilosThemePick.get()).toBe(pick)
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe(pick)
      expect(document.documentElement.getAttribute('data-bs-theme')).toBe(mode)
      expect(toggle().dataset.position).toBe(pick)
      expect(toggle().querySelector('i')?.classList).toContain(icon)
      expect(toggle().getAttribute('aria-expanded')).toBe('false')
      expect(document.activeElement).toBe(toggle())
      expect(maybe('nav-theme-menu')).toBeNull()
      click('nav-theme')
      for (const position of ['light', 'dark', 'system']) {
        const option = byId(`nav-theme-option-${position}`)
        expect(option.getAttribute('aria-checked')).toBe(
          String(position === pick),
        )
        expect(option.classList.contains('active')).toBe(position === pick)
        expect(option.querySelector('.bi-check2') !== null).toBe(
          position === pick,
        )
      }
    },
  )

  it('makes the current default light an explicit choice', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, null)
    mountMenu()
    click('nav-theme')
    expect(
      byId('nav-theme-option-light').querySelector('.badge'),
    ).not.toBeNull()

    click('nav-theme-option-light')

    expect(hilosThemePick.get()).toBe('light')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
    click('nav-theme')
    expect(byId('nav-theme-option-light').querySelector('.badge')).toBeNull()
  })

  it('always offers a separate guest reset, including when already following the default', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    mountMenu()
    for (let attempt = 0; attempt < 2; attempt++) {
      click('nav-theme')
      const reset = byId('nav-theme-default')
      expect(rows()).toHaveLength(3)
      expect(document.querySelectorAll('[role="separator"]')).toHaveLength(1)
      expect(reset.textContent).toBe('Use site default')
      expect(reset.getAttribute('role')).toBe('menuitem')
      expect(reset.hasAttribute('aria-checked')).toBe(false)
      expect(reset.classList).not.toContain('active')
      expect(reset.querySelector('.bi-check2')).toBeNull()
      expect(reset.disabled).toBe(false)

      fireEvent.click(reset)
      expect(hilosThemePick.get()).toBeNull()
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBeNull()
      expect(localStorage.getItem(THEME_SETTINGS_STORAGE_KEY)).not.toBeNull()
      expect(toggle().dataset.position).toBe('light')
      expect(toggle().getAttribute('aria-expanded')).toBe('false')
      expect(document.activeElement).toBe(toggle())
      click('nav-theme')
      expect(byId('nav-theme-option-light').textContent).toContain('default')
      key(byId('nav-theme-menu'), 'Escape')
    }
  })

  it('refuses a stale open row when switching turns off and closes the menu', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    mountMenu()
    click('nav-theme')
    const light = byId('nav-theme-option-light')

    // The click reaches the row React has not removed yet: the handler must
    // read the live guard, not the render it was drawn in.
    act(() => {
      releaseTheme?.()
      vi.stubGlobal(
        'localStorage',
        memoryStorage({
          [THEME_SETTINGS_STORAGE_KEY]: JSON.stringify({
            switchingEnabled: false,
            defaultTheme: 'light',
          }),
          [THEME_PICK_STORAGE_KEY]: 'dark',
        }),
      )
      releaseTheme = bindThemeState(
        dummyConnection(),
        new ScopeManager(),
        themeActions,
      )
      light.click()
    })
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    expect(maybe('nav-theme-option-light')).toBeNull()

    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    expect(toggle().getAttribute('aria-expanded')).toBe('false')
    expect(maybe('nav-theme-menu')).toBeNull()
  })

  it('applies a guest click in memory when localStorage refuses access', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, null)
    vi.stubGlobal('localStorage', {
      ...memoryStorage({}),
      getItem: () => {
        throw new Error('refused')
      },
      setItem: () => {
        throw new Error('refused')
      },
      removeItem: () => {
        throw new Error('refused')
      },
    })
    mountMenu()
    click('nav-theme')

    click('nav-theme-option-dark')

    expect(hilosThemePick.get()).toBe('dark')
    expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark')
    expect(toggle().dataset.position).toBe('dark')
    expect(toggle().getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle())
  })

  it.each(['light', 'dark', 'system', null] as const)(
    'dispatches the signed-in choice %s, closes, and waits for the authoritative handshake',
    async (pick) => {
      const server = bindAccount()
      mountMenu()
      click('nav-theme')
      click(pick === null ? 'nav-theme-default' : `nav-theme-option-${pick}`)

      expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
        'hilos_theme_pick',
        { themePick: pick },
        '1',
      )
      expect(hilosThemePick.get()).toBe('dark')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
      expect(toggle().getAttribute('aria-expanded')).toBe('false')
      expect(document.activeElement).toBe(toggle())
      expect(toggle().disabled).toBe(true)
      expect(toggle().getAttribute('aria-busy')).toBe('true')
      expect(maybe('nav-theme-loading')).toBeNull()
      key(toggle(), 'ArrowDown')
      expect(toggle().getAttribute('aria-expanded')).toBe('false')

      // DOM emulation keeps focus on a disabled button; Chromium drops it.
      dropFocus()
      expect(document.activeElement).not.toBe(toggle())
      const focus = vi.spyOn(toggle(), 'focus')
      await server.reply('success')
      expect(hilosThemePick.get()).toBe('dark')
      expect(toggle().disabled).toBe(false)
      expect(focus).toHaveBeenCalledOnce()
      expect(document.activeElement).toBe(toggle())
      server.handshake(pick, 1, true)
      expect(toggle().dataset.position).toBe(pick ?? 'light')
      expect(hilosThemePick.get()).toBe(pick)
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe(pick)
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('sends once for a double click that lands before React re-renders', async () => {
    const server = bindAccount('light')
    mountMenu()
    click('nav-theme')
    const dark = byId('nav-theme-option-dark')
    const reset = byId('nav-theme-default')

    act(() => {
      dark.click()
      reset.click()
      dark.click()
    })

    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_theme_pick',
      { themePick: 'dark' },
      '1',
    )
    await server.reply('success')
    expect(server.sendAction).toHaveBeenCalledOnce()
  })

  it('makes an account default personal, repeats the current pick, and keeps sign-out guest-only', async () => {
    const server = bindAccount(null)
    mountMenu()
    click('nav-theme')
    expect(byId('nav-theme-option-light').textContent).toContain('default')
    click('nav-theme-option-light')
    expect(server.sendAction).toHaveBeenCalledOnce()
    // The acknowledgement alone moves nothing; the account frame does.
    await server.reply('success')
    click('nav-theme')
    expect(byId('nav-theme-option-light').textContent).toContain('default')
    key(byId('nav-theme-menu'), 'Escape')
    server.handshake('light')
    click('nav-theme')
    expect(byId('nav-theme-option-light').textContent).not.toContain('default')
    click('nav-theme-option-light')
    expect(server.sendAction).toHaveBeenCalledTimes(2)
    await server.reply('success')

    server.handshake('light', null)
    click('nav-theme')
    click('nav-theme-option-dark')
    expect(hilosThemePick.get()).toBe('dark')
    expect(server.sendAction).toHaveBeenCalledTimes(2)
  })

  it.each([
    ['fail', 'dark'],
    ['timeout', 'dark'],
    ['disconnected', 'dark'],
    ['fail', null],
    ['timeout', null],
    ['disconnected', null],
  ] as const)(
    'shows delayed loading and the normal %s toast for %s, without changing the confirmed theme',
    async (outcome, pick) => {
      const server = bindAccount('light')
      mountMenu()
      click('nav-theme')
      click(pick === null ? 'nav-theme-default' : `nav-theme-option-${pick}`)
      await act(async () => {
        await vi.advanceTimersByTimeAsync(299)
      })
      expect(maybe('nav-theme-loading')).toBeNull()
      await act(async () => {
        await vi.advanceTimersByTimeAsync(1)
      })
      expect(maybe('nav-theme-loading')).not.toBeNull()
      expect(toggle().querySelector('i')?.classList).toContain('invisible')
      expect(toggle().getAttribute('aria-busy')).toBe('true')
      dropFocus()
      fireEvent.click(toggle())
      expect(maybe('nav-theme-menu')).toBeNull()
      expect(server.sendAction).toHaveBeenCalledOnce()
      if (outcome === 'fail') await server.reply('fail')
      else if (outcome === 'timeout')
        await act(async () => {
          await vi.advanceTimersByTimeAsync(29_700)
        })
      else server.state('reconnecting')
      await settle()
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(document.documentElement.getAttribute('data-bs-theme')).toBe(
        'light',
      )
      expect(toggle().disabled).toBe(false)
      expect(document.activeElement).toBe(toggle())
      expect(maybe('nav-theme-loading')).toBeNull()
      const messages = {
        fail: 'Theme switching is disabled.',
        timeout: 'The action timed out. Please try again.',
        disconnected:
          'The connection dropped before the action completed. Please try again.',
      }
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: messages[outcome], severity: 'error' },
      ])
      dropFocus()
      const focus = vi.spyOn(toggle(), 'focus')
      server.state('connected')
      await server.reply('success', '1')
      expect(hilosThemePick.get()).toBe('light')
      expect(toggle().getAttribute('aria-expanded')).toBe('false')
      expect(focus).not.toHaveBeenCalled()
      click('nav-theme')
      click('nav-theme-option-system')
      expect(server.sendAction).toHaveBeenCalledTimes(2)
      await server.reply('success')
    },
  )

  it.each(['off', 'signout', 'impersonation'] as const)(
    'checks live %s state on an open item before React re-renders it',
    (change) => {
      const server = bindAccount('dark')
      mountMenu()
      click('nav-theme')
      const light = byId('nav-theme-option-light')
      act(() => {
        if (change === 'off')
          server.settings({ switchingEnabled: false, defaultTheme: 'light' })
        else if (change === 'signout') server.handshake('dark', null)
        else server.impersonate()
        light.click()
      })
      expect(server.sendAction).not.toHaveBeenCalled()
      // Signed out, the same click is a guest's choice of this tab.
      expect(hilosThemePick.get()).toBe(change === 'signout' ? 'light' : 'dark')
      if (change === 'off') {
        expect(maybe('nav-theme')).toBeNull()
      } else {
        expect(toggle().getAttribute('aria-expanded')).toBe('false')
        expect(maybe('nav-theme-menu')).toBeNull()
      }
    },
  )

  it('keeps the icon visible but closed to writes under a takeover', () => {
    const server = bindAccount('dark')
    mountMenu()
    server.impersonate()

    expect(toggle().disabled).toBe(true)
    expect(toggle().getAttribute('aria-describedby')).toBeTruthy()
    key(toggle(), 'ArrowDown')
    expect(maybe('nav-theme-menu')).toBeNull()
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it.each(['off', 'signout', 'impersonation'] as const)(
    'does not reopen the menu or locally apply a late success after %s',
    async (change) => {
      const server = bindAccount('light')
      mountMenu()
      click('nav-theme')
      click('nav-theme-option-dark')
      const button = toggle()
      dropFocus()
      const focus = vi.spyOn(button, 'focus')
      if (change === 'off')
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
      else if (change === 'signout') server.handshake('light', null)
      else server.impersonate()
      await server.reply('success')
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(server.sendAction).toHaveBeenCalledOnce()
      expect(focus).not.toHaveBeenCalled()
      expect(document.activeElement).not.toBe(button)
      if (change !== 'off') {
        expect(toggle().getAttribute('aria-expanded')).toBe('false')
      }
    },
  )

  it.each(['success', 'fail'] as const)(
    'does not focus a removed toggle after an unmounted action returns %s',
    async (outcome) => {
      const server = bindAccount('light')
      const view = mountMenu()
      click('nav-theme')
      click('nav-theme-option-dark')
      const button = toggle()
      dropFocus()
      const focus = vi.spyOn(button, 'focus')
      view.unmount()

      await server.reply(outcome)

      expect(button.isConnected).toBe(false)
      expect(focus).not.toHaveBeenCalled()
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(server.sendAction).toHaveBeenCalledOnce()
    },
  )

  it('keeps a newer server choice when the keyboard-submitted action succeeds late', async () => {
    const server = bindAccount('light')
    mountMenu()
    key(toggle(), 'ArrowUp')
    await nextFrame()
    expect(document.activeElement).toBe(byId('nav-theme-default'))
    key(byId('nav-theme-menu'), 'ArrowUp')
    expect(document.activeElement).toBe(byId('nav-theme-option-system'))
    // Native buttons translate Enter/Space to a click in the browser.
    act(() => (document.activeElement as HTMLButtonElement).click())
    expect(server.sendAction).toHaveBeenCalledWith(
      'hilos_theme_pick',
      { themePick: 'system' },
      '1',
    )
    expect(document.activeElement).toBe(toggle())
    server.handshake('dark')
    await server.reply('success')
    expect(hilosThemePick.get()).toBe('dark')
    expect(toggle().dataset.position).toBe('dark')
  })
})
