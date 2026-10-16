// Covers the Angular header theme menu (HIL-1441) on the real core: the theme
// binding bootHilos makes, a real action lifecycle with request correlation, and
// server frames that are the only authority over an account's choice. The cases
// are the React menu's (react/test/HilosThemeMenu.test.tsx), read through
// change detection rather than through act().
import { ApplicationRef } from '@angular/core'
import type { ComponentFixture } from '@angular/core/testing'
import { TestBed } from '@angular/core/testing'
import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  hilosThemePick,
  hilosToasts,
  ScopeManager,
  setHilosThemePick,
  type ActionLifecycleSource,
  type ConnectionState,
  type HilosConnection,
  type ThemePick,
  type ThemeSettings,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
} from '../../core/src/theme/themeBrowser.js'
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
let fixture: ComponentFixture<HilosThemeMenu> | undefined

/** Run change detection over the mounted menu, if there is one. */
function update(): void {
  fixture?.detectChanges()
}

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
  const emit = (event: string, data: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(data as never)
    update()
  }
  const sendAction = vi.fn<
    (action: string, data: unknown, requestId?: string) => boolean
  >(() => true)
  const connection = {
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

  function handshake(
    themePick: ThemePick,
    userId: number | null = 1,
    themePickInitialized = themePick !== null,
  ): void {
    emit('projectSignal', {
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
  handshake(pick)

  return {
    sendAction,
    handshake,
    impersonate: () => {
      scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 })
      update()
    },
    settings: (next: ThemeSettings) =>
      emit('projectSignal', { type: 'hilos_theme_settings', data: next }),
    state: (state: ConnectionState) => emit('state', state),
    async reply(
      outcome: 'success' | 'fail',
      requestId = sendAction.mock.lastCall?.[2],
    ): Promise<void> {
      for (const listener of listeners.get(
        outcome === 'success' ? 'actionSuccess' : 'actionError',
      ) ?? [])
        listener({
          action: 'hilos_theme_pick',
          requestId,
          reason: 'Theme switching is disabled.',
        } as never)
      await settle()
    },
  }
}

/** A guest binding over a remembered settings pair and pick. */
function bindSnapshot(settings: ThemeSettings, pick: ThemePick): void {
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
  update()
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
  fixture?.destroy()
  fixture = undefined
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
  byId(id).click()
  update()
}

function key(element: Element, name: string): void {
  element.dispatchEvent(
    new KeyboardEvent('keydown', { key: name, bubbles: true }),
  )
  update()
}

/** Let the promise chain behind a reply run out, then draw what it left. */
async function settle(): Promise<void> {
  if (vi.isFakeTimers()) {
    await vi.advanceTimersByTimeAsync(0)
  } else {
    await Promise.resolve()
  }
  update()
}

function mountMenu(): ComponentFixture<HilosThemeMenu> {
  fixture = TestBed.createComponent(HilosThemeMenu)
  fixture.detectChanges()

  return fixture
}

describe('HilosThemeMenu', () => {
  it('shows the position icon and its Theme label', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, 'light')
    mountMenu()

    expect(toggle().querySelector('i')?.classList).toContain('bi-sun')
    expect(toggle().querySelector('i')?.classList).toContain('bi')
    expect(toggle().title).toBe('Theme: Light')
    expect(toggle().getAttribute('aria-label')).toBe('Theme: Light')
    expect(toggle().dataset.position).toBe('light')
    expect(toggle().getAttribute('aria-haspopup')).toBe('menu')

    setHilosThemePick('dark')
    update()
    expect(toggle().querySelector('i')?.classList).toContain('bi-moon-stars')
    expect(toggle().querySelector('i')?.classList).not.toContain('bi-sun')
    expect(toggle().title).toBe('Theme: Dark')

    setHilosThemePick('system')
    update()
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

    setHilosThemePick('dark')
    update()
    expect(marked.textContent).not.toContain('default')
    expect(marked.querySelector('.bi-check2')).not.toBeNull()
    expect(marked.getAttribute('aria-checked')).toBe('true')
  })

  it('draws nothing when switching is off and hides its host from the header row', () => {
    bindSnapshot({ switchingEnabled: false, defaultTheme: 'light' }, 'dark')
    const mounted = mountMenu()
    const host = mounted.nativeElement as HTMLElement

    expect(maybe('nav-theme')).toBeNull()
    expect(maybe('nav-theme-menu')).toBeNull()
    expect(host.classList).toContain('d-none')
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')

    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    expect(host.classList).not.toContain('d-none')
    expect(toggle().dataset.position).toBe('dark')
  })

  it('redraws an open menu and the icon when the choice changes', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    mountMenu()
    click('nav-theme')
    expect(toggle().getAttribute('aria-expanded')).toBe('true')
    expect(byId('nav-theme-option-dark').getAttribute('aria-checked')).toBe(
      'true',
    )

    setHilosThemePick('system')
    update()
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

  it('roves the four controls and returns focus on Escape', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, null)
    mountMenu()

    key(toggle(), 'ArrowDown')
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
    expect(document.activeElement).toBe(byId('nav-theme-default'))
    key(toggle(), 'Escape')
    expect(maybe('nav-theme-menu')).toBeNull()
    expect(document.activeElement).toBe(toggle())
  })

  it('closes on a click outside the menu but not inside it, without taking focus', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, 'light')
    mountMenu()

    click('nav-theme')
    byId('nav-theme-menu').click()
    update()
    expect(toggle().getAttribute('aria-expanded')).toBe('true')

    const outside = document.createElement('button')
    document.body.append(outside)
    outside.focus()
    document.body.click()
    update()
    expect(toggle().getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(outside)
  })

  it('stops listening for outside clicks once it is destroyed', () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, 'light')
    const mounted = mountMenu()
    const remove = vi.spyOn(document, 'removeEventListener')
    mounted.destroy()
    fixture = undefined

    expect(remove.mock.calls.some(([type]) => type === 'click')).toBe(true)
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
      expect(hilosToasts.toasts.get()).toEqual([])
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
      expect(reset.textContent?.trim()).toBe('Use site default')
      expect(reset.getAttribute('role')).toBe('menuitem')
      expect(reset.hasAttribute('aria-checked')).toBe(false)
      expect(reset.classList).not.toContain('active')
      expect(reset.querySelector('.bi-check2')).toBeNull()
      expect(reset.disabled).toBe(false)

      reset.click()
      update()
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

    // The click reaches the row change detection has not removed yet: the
    // handler must read the live guard, not the pass it was drawn in.
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
    update()
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    expect(maybe('nav-theme-option-light')).toBeNull()

    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    expect(toggle().getAttribute('aria-expanded')).toBe('false')
    expect(maybe('nav-theme-menu')).toBeNull()
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
      expect(toggle().hasAttribute('aria-busy')).toBe(false)
      expect(focus).toHaveBeenCalledOnce()
      expect(document.activeElement).toBe(toggle())
      server.handshake(pick, 1, true)
      expect(toggle().dataset.position).toBe(pick ?? 'light')
      expect(hilosThemePick.get()).toBe(pick)
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe(pick)
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('sends once for a double click that lands before the next change detection', async () => {
    const server = bindAccount('light')
    mountMenu()
    click('nav-theme')
    const dark = byId('nav-theme-option-dark')
    const reset = byId('nav-theme-default')

    dark.click()
    reset.click()
    dark.click()
    update()

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
      await vi.advanceTimersByTimeAsync(299)
      update()
      expect(maybe('nav-theme-loading')).toBeNull()
      await vi.advanceTimersByTimeAsync(1)
      update()
      expect(maybe('nav-theme-loading')).not.toBeNull()
      expect(toggle().querySelector('i')?.classList).toContain('invisible')
      expect(toggle().getAttribute('aria-busy')).toBe('true')
      dropFocus()
      toggle().click()
      update()
      expect(maybe('nav-theme-menu')).toBeNull()
      expect(server.sendAction).toHaveBeenCalledOnce()
      if (outcome === 'fail') await server.reply('fail')
      else if (outcome === 'timeout') {
        await vi.advanceTimersByTimeAsync(29_700)
        update()
      } else server.state('reconnecting')
      await settle()
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(document.documentElement.getAttribute('data-bs-theme')).toBe(
        'light',
      )
      expect(toggle().disabled).toBe(false)
      expect(document.activeElement).toBe(toggle())
      expect(maybe('nav-theme-loading')).toBeNull()
      expect(toggle().querySelector('i')?.classList).not.toContain('invisible')
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
    'checks live %s state on an open item before change detection redraws it',
    (change) => {
      const server = bindAccount('dark')
      mountMenu()
      click('nav-theme')
      const light = byId('nav-theme-option-light')
      // The frame lands and the click follows before the menu is drawn again.
      const redraw = fixture
      fixture = undefined
      if (change === 'off')
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
      else if (change === 'signout') server.handshake('dark', null)
      else server.impersonate()
      light.click()
      fixture = redraw
      update()
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

  it.each(['signout', 'impersonation'] as const)(
    'closes an open menu on %s and returns focus to the icon',
    (change) => {
      const server = bindAccount('dark')
      mountMenu()
      click('nav-theme')
      expect(maybe('nav-theme-menu')).not.toBeNull()
      if (change === 'signout') server.handshake('dark', null)
      else server.impersonate()

      expect(maybe('nav-theme-menu')).toBeNull()
      expect(toggle().getAttribute('aria-expanded')).toBe('false')
      expect(document.activeElement).toBe(toggle())
    },
  )

  it('keeps the icon visible but closed to writes under a takeover', () => {
    const server = bindAccount('dark')
    mountMenu()
    server.impersonate()

    expect(toggle().disabled).toBe(true)
    expect(toggle().getAttribute('aria-describedby')).toBe(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )
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
      if (change !== 'off') {
        expect(toggle().getAttribute('aria-expanded')).toBe('false')
      }
    },
  )

  it.each(['success', 'fail'] as const)(
    'does not focus a removed toggle after a destroyed menu hears %s',
    async (outcome) => {
      const server = bindAccount('light')
      const mounted = mountMenu()
      click('nav-theme')
      click('nav-theme-option-dark')
      const button = toggle()
      dropFocus()
      const focus = vi.spyOn(button, 'focus')
      mounted.destroy()
      fixture = undefined

      await server.reply(outcome)
      // Whatever renders next in the application must not reach the menu.
      TestBed.inject(ApplicationRef).tick()

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
    expect(document.activeElement).toBe(byId('nav-theme-default'))
    key(byId('nav-theme-menu'), 'ArrowUp')
    expect(document.activeElement).toBe(byId('nav-theme-option-system'))
    // Native buttons translate Enter/Space to a click in the browser.
    ;(document.activeElement as HTMLButtonElement).click()
    update()
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
