import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  hilosToasts,
  ScopeManager,
  type ActionLifecycleSource,
  type ConnectionState,
  type HilosConnection,
  type ThemePick,
  type ThemeSettings,
} from '@hilos/core'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import {
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
} from '../../core/src/theme/themeBrowser.js'
import {
  bindThemeState,
  hilosThemePick,
  setHilosThemePick,
} from '../../core/src/theme/themeState.js'
import HilosThemeMenu from './HilosThemeMenu.vue'

// bindThemeState is the boot door, not a barrel export. The menu reads only
// hilosThemeChoice, so a test moves that signal the way bootHilos does.
function dummyConnection(): HilosConnection {
  return { on: () => () => {} } as unknown as HilosConnection
}

const themeActions = new ActionLifecycle({
  sendAction: () => true,
  on: () => () => {},
})

let releaseTheme: (() => void) | undefined
let releaseSession: (() => void) | undefined

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

  function handshake(themePick: ThemePick, userId: number | null = 1): void {
    emit('projectSignal', {
      type: 'handshake_response',
      data: {
        entities: {
          currentUser:
            userId === null ? null : { id: userId, name: 'Ada', themePick },
        },
        data: { themeSettings: settings },
      },
    })
  }
  handshake(pick)

  return {
    sendAction,
    handshake,
    impersonate: () =>
      scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }),
    settings: (next: ThemeSettings) =>
      emit('projectSignal', { type: 'hilos_theme_settings', data: next }),
    state: (state: ConnectionState) => emit('state', state),
    reply: (
      outcome: 'success' | 'fail',
      requestId = sendAction.mock.lastCall?.[2],
    ) =>
      emit(outcome === 'success' ? 'actionSuccess' : 'actionError', {
        action: 'hilos_theme_pick',
        requestId,
        reason: 'Theme switching is disabled.',
      }),
  }
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

function bindSnapshot(
  settings: {
    switchingEnabled: boolean
    defaultTheme: 'light' | 'dark' | 'system'
  },
  pick: 'light' | 'dark' | 'system' | null,
): void {
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

const attached: VueWrapper[] = []

afterEach(() => {
  while (attached.length > 0) {
    attached.pop()?.unmount()
  }
  releaseSession?.()
  releaseSession = undefined
  restoreCatalog()
  vi.restoreAllMocks()
  vi.useRealTimers()
  hilosToasts.clear()
})

function mountAttached(): VueWrapper {
  const wrapper = mount(HilosThemeMenu, {
    attachTo: document.body,
  }) as unknown as VueWrapper
  attached.push(wrapper)

  return wrapper
}

function optionButtons(wrapper: VueWrapper): HTMLButtonElement[] {
  return wrapper
    .find('[data-id="nav-theme-menu"]')
    .findAll('[role="menuitemradio"]')
    .map((item) => item.element as HTMLButtonElement)
}

describe('HilosThemeMenu', () => {
  it('shows the position icon and its Theme label', async () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, 'light')
    const wrapper = mountAttached()
    const button = () => wrapper.get('[data-id="nav-theme"]')

    expect(button().find('i').classes()).toContain('bi-sun')
    expect(button().attributes('title')).toBe('Theme: Light')
    expect(button().attributes('aria-label')).toBe('Theme: Light')
    expect(button().attributes('data-position')).toBe('light')

    setHilosThemePick('dark')
    await nextTick()
    expect(button().find('i').classes()).toContain('bi-moon-stars')
    expect(button().attributes('title')).toBe('Theme: Dark')

    setHilosThemePick('system')
    await nextTick()
    expect(button().find('i').classes()).toContain('bi-circle-half')
    expect(button().attributes('title')).toBe('Theme: System')
  })

  it('checks the default position and badges it only when nothing was picked', async () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'dark' }, null)
    const wrapper = mountAttached()
    await wrapper.get('[data-id="nav-theme"]').trigger('click')

    const marked = wrapper.get('[data-id="nav-theme-option-dark"]')
    expect(marked.classes()).toContain('active')
    expect(marked.attributes('aria-checked')).toBe('true')
    expect(marked.text()).toContain('default')
    expect(marked.find('.bi-check2').exists()).toBe(true)
    expect(marked.find('.badge').classes()).toContain('bg-body-tertiary')

    const light = wrapper.get('[data-id="nav-theme-option-light"]')
    expect(light.attributes('aria-checked')).toBe('false')
    expect(light.classes()).not.toContain('active')
    expect(light.text()).not.toContain('default')
    expect(light.find('.bi-check2').exists()).toBe(false)

    setHilosThemePick('dark')
    await nextTick()
    expect(marked.text()).not.toContain('default')
    expect(marked.find('.bi-check2').exists()).toBe(true)
    expect(marked.attributes('aria-checked')).toBe('true')
  })

  it('draws nothing when switching is off', () => {
    bindSnapshot({ switchingEnabled: false, defaultTheme: 'light' }, 'dark')
    const wrapper = mountAttached()

    expect(wrapper.find('[data-id="nav-theme"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="nav-theme-menu"]').exists()).toBe(false)
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
  })

  it('redraws an open menu and the icon when the choice changes', async () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    const wrapper = mountAttached()
    const toggle = wrapper.get('[data-id="nav-theme"]')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(
      wrapper
        .get('[data-id="nav-theme-option-dark"]')
        .attributes('aria-checked'),
    ).toBe('true')

    setHilosThemePick('system')
    await nextTick()
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(toggle.attributes('data-position')).toBe('system')
    expect(toggle.find('i').classes()).toContain('bi-circle-half')
    expect(
      wrapper
        .get('[data-id="nav-theme-option-system"]')
        .attributes('aria-checked'),
    ).toBe('true')
    expect(
      wrapper
        .get('[data-id="nav-theme-option-dark"]')
        .attributes('aria-checked'),
    ).toBe('false')
  })

  it('roves the rows and returns focus on Escape', async () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, null)
    const wrapper = mountAttached()
    const toggle = wrapper.get('[data-id="nav-theme"]')
    const menu = wrapper.get('[data-id="nav-theme-menu"]')
    const buttons = optionButtons(wrapper)

    await toggle.trigger('keydown', { key: 'ArrowDown' })
    await nextTick()
    expect(document.activeElement).toBe(buttons[0])

    await menu.trigger('keydown', { key: 'ArrowDown' })
    expect(document.activeElement).toBe(buttons[1])
    await menu.trigger('keydown', { key: 'ArrowUp' })
    expect(document.activeElement).toBe(buttons[0])
    await menu.trigger('keydown', { key: 'ArrowUp' })
    expect(document.activeElement).toBe(buttons[2])
    await menu.trigger('keydown', { key: 'Home' })
    expect(document.activeElement).toBe(buttons[0])
    await menu.trigger('keydown', { key: 'End' })
    expect(document.activeElement).toBe(buttons[2])

    await menu.trigger('keydown', { key: 'Escape' })
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle.element)
  })

  it('closes on a click outside the menu', async () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'system' }, 'light')
    const wrapper = mountAttached()
    const toggle = wrapper.get('[data-id="nav-theme"]')
    const menu = wrapper.get('[data-id="nav-theme-menu"]')

    await toggle.trigger('click')
    menu.element.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()
    expect(toggle.attributes('aria-expanded')).toBe('true')

    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()
    expect(toggle.attributes('aria-expanded')).toBe('false')
  })

  it.each([
    ['light', 'light', 'bi-sun'],
    ['dark', 'dark', 'bi-moon-stars'],
    ['system', 'light', 'bi-circle-half'],
  ] as const)(
    'applies and remembers the guest choice %s',
    async (pick, mode, icon) => {
      bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, null)
      const wrapper = mountAttached()
      const toggle = wrapper.get('[data-id="nav-theme"]')
      await toggle.trigger('click')

      await wrapper.get(`[data-id="nav-theme-option-${pick}"]`).trigger('click')
      await nextTick()

      expect(hilosThemePick.get()).toBe(pick)
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe(pick)
      expect(document.documentElement.getAttribute('data-bs-theme')).toBe(mode)
      expect(toggle.attributes('data-position')).toBe(pick)
      expect(toggle.find('i').classes()).toContain(icon)
      expect(toggle.attributes('aria-expanded')).toBe('false')
      expect(document.activeElement).toBe(toggle.element)
      expect(wrapper.get('[data-id="nav-theme-menu"]').isVisible()).toBe(false)
      for (const position of ['light', 'dark', 'system']) {
        const option = wrapper.get(`[data-id="nav-theme-option-${position}"]`)
        expect(option.attributes('aria-checked')).toBe(
          String(position === pick),
        )
        expect(option.classes().includes('active')).toBe(position === pick)
        expect(option.find('.bi-check2').exists()).toBe(position === pick)
      }
    },
  )

  it('makes the current default light an explicit choice', async () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, null)
    const wrapper = mountAttached()
    await wrapper.get('[data-id="nav-theme"]').trigger('click')
    const light = wrapper.get('[data-id="nav-theme-option-light"]')
    expect(light.find('.badge').exists()).toBe(true)

    await light.trigger('click')

    expect(hilosThemePick.get()).toBe('light')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
    expect(light.find('.badge').exists()).toBe(false)
  })

  it.each(['light', 'dark', 'system'] as const)(
    'dispatches the signed-in choice %s, closes, and waits for the authoritative handshake',
    async (pick) => {
      const server = bindAccount()
      const wrapper = mountAttached()
      const toggle = wrapper.get('[data-id="nav-theme"]')
      await toggle.trigger('click')
      await wrapper.get(`[data-id="nav-theme-option-${pick}"]`).trigger('click')

      expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
        'hilos_theme_pick',
        { themePick: pick },
        '1',
      )
      expect(hilosThemePick.get()).toBe('dark')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
      expect(toggle.attributes('aria-expanded')).toBe('false')
      expect(document.activeElement).toBe(toggle.element)
      expect((toggle.element as HTMLButtonElement).disabled).toBe(true)
      expect(wrapper.find('[data-id="nav-theme-loading"]').exists()).toBe(false)
      await toggle.trigger('keydown', { key: 'ArrowDown' })
      expect(toggle.attributes('aria-expanded')).toBe('false')

      // DOM emulation may keep focus on a disabled button; Chromium drops it.
      ;(toggle.element as HTMLButtonElement).blur()
      expect(document.activeElement).not.toBe(toggle.element)
      const focus = vi.spyOn(toggle.element as HTMLButtonElement, 'focus')
      server.reply('success')
      await flushPromises()
      expect(hilosThemePick.get()).toBe('dark')
      expect((toggle.element as HTMLButtonElement).disabled).toBe(false)
      expect(focus).toHaveBeenCalledOnce()
      expect(document.activeElement).toBe(toggle.element)
      server.handshake(pick)
      await nextTick()
      expect(toggle.attributes('data-position')).toBe(pick)
      expect(hilosThemePick.get()).toBe(pick)
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe(pick)
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('makes an account default personal, repeats the current pick, and keeps sign-out guest-only', async () => {
    const server = bindAccount(null)
    const wrapper = mountAttached()
    const toggle = wrapper.get('[data-id="nav-theme"]')
    const light = wrapper.get('[data-id="nav-theme-option-light"]')
    expect(light.text()).toContain('default')
    await toggle.trigger('click')
    await light.trigger('click')
    expect(server.sendAction).toHaveBeenCalledOnce()
    expect(light.text()).toContain('default')
    server.handshake('light')
    server.reply('success')
    await flushPromises()
    expect(light.text()).not.toContain('default')
    await toggle.trigger('click')
    await light.trigger('click')
    expect(server.sendAction).toHaveBeenCalledTimes(2)
    server.reply('success')
    await flushPromises()

    server.handshake('light', null)
    await toggle.trigger('click')
    await wrapper.get('[data-id="nav-theme-option-dark"]').trigger('click')
    expect(hilosThemePick.get()).toBe('dark')
    expect(server.sendAction).toHaveBeenCalledTimes(2)
  })

  it.each(['fail', 'timeout', 'disconnected'] as const)(
    'shows delayed loading and the normal %s toast, without changing the confirmed theme',
    async (outcome) => {
      const server = bindAccount('light')
      const wrapper = mountAttached()
      const toggle = wrapper.get('[data-id="nav-theme"]')
      await toggle.trigger('click')
      await wrapper.get('[data-id="nav-theme-option-dark"]').trigger('click')
      await vi.advanceTimersByTimeAsync(299)
      expect(wrapper.find('[data-id="nav-theme-loading"]').exists()).toBe(false)
      await vi.advanceTimersByTimeAsync(1)
      expect(wrapper.find('[data-id="nav-theme-loading"]').exists()).toBe(true)
      expect(toggle.attributes('aria-busy')).toBe('true')
      ;(toggle.element as HTMLButtonElement).blur()
      expect(document.activeElement).not.toBe(toggle.element)
      await wrapper.get('[data-id="nav-theme-option-system"]').trigger('click')
      expect(server.sendAction).toHaveBeenCalledOnce()
      if (outcome === 'fail') server.reply('fail')
      else if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(29_700)
      else server.state('reconnecting')
      await flushPromises()
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(document.documentElement.getAttribute('data-bs-theme')).toBe(
        'light',
      )
      expect((toggle.element as HTMLButtonElement).disabled).toBe(false)
      expect(document.activeElement).toBe(toggle.element)
      expect(wrapper.find('[data-id="nav-theme-loading"]').exists()).toBe(false)
      const messages = {
        fail: 'Theme switching is disabled.',
        timeout: 'The action timed out. Please try again.',
        disconnected:
          'The connection dropped before the action completed. Please try again.',
      }
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: messages[outcome], severity: 'error' },
      ])
      ;(toggle.element as HTMLButtonElement).blur()
      const focus = vi.spyOn(toggle.element as HTMLButtonElement, 'focus')
      server.state('connected')
      server.reply('success', '1')
      await flushPromises()
      expect(hilosThemePick.get()).toBe('light')
      expect(toggle.attributes('aria-expanded')).toBe('false')
      expect(focus).not.toHaveBeenCalled()
      await toggle.trigger('click')
      await wrapper.get('[data-id="nav-theme-option-system"]').trigger('click')
      expect(server.sendAction).toHaveBeenCalledTimes(2)
      server.reply('success')
      await flushPromises()
    },
  )

  it.each(['off', 'signout', 'impersonation'] as const)(
    'checks live %s state on an open item before Vue patches it',
    async (change) => {
      const server = bindAccount('dark')
      const wrapper = mountAttached()
      await wrapper.get('[data-id="nav-theme"]').trigger('click')
      const light = wrapper.get('[data-id="nav-theme-option-light"]')
      if (change === 'off')
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
      else if (change === 'signout') server.handshake('dark', null)
      else server.impersonate()
      await light.trigger('click')
      expect(server.sendAction).not.toHaveBeenCalled()
      expect(hilosThemePick.get()).toBe(change === 'signout' ? 'light' : 'dark')
      if (change !== 'off')
        expect(
          wrapper.get('[data-id="nav-theme"]').attributes('aria-expanded'),
        ).toBe('false')
    },
  )

  it.each(['off', 'signout', 'impersonation'] as const)(
    'does not reopen the menu or locally apply a late success after %s',
    async (change) => {
      const server = bindAccount('light')
      const wrapper = mountAttached()
      const toggle = wrapper.get('[data-id="nav-theme"]')
      await wrapper.get('[data-id="nav-theme"]').trigger('click')
      await wrapper.get('[data-id="nav-theme-option-dark"]').trigger('click')
      ;(toggle.element as HTMLButtonElement).blur()
      const focus = vi.spyOn(toggle.element as HTMLButtonElement, 'focus')
      if (change === 'off')
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
      else if (change === 'signout') server.handshake('light', null)
      else server.impersonate()
      await nextTick()
      server.reply('success')
      await flushPromises()
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(server.sendAction).toHaveBeenCalledOnce()
      expect(focus).not.toHaveBeenCalled()
      expect(document.activeElement).not.toBe(toggle.element)
      if (change !== 'off')
        expect(
          wrapper.get('[data-id="nav-theme"]').attributes('aria-expanded'),
        ).toBe('false')
    },
  )

  it.each(['success', 'fail'] as const)(
    'does not focus a removed toggle after an unmounted action returns %s',
    async (outcome) => {
      const server = bindAccount('light')
      const wrapper = mountAttached()
      const toggle = wrapper.get('[data-id="nav-theme"]')
      await toggle.trigger('click')
      await wrapper.get('[data-id="nav-theme-option-dark"]').trigger('click')
      ;(toggle.element as HTMLButtonElement).blur()
      const focus = vi.spyOn(toggle.element as HTMLButtonElement, 'focus')
      attached.pop()?.unmount()

      server.reply(outcome)
      await flushPromises()

      expect(toggle.element.isConnected).toBe(false)
      expect(focus).not.toHaveBeenCalled()
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(server.sendAction).toHaveBeenCalledOnce()
    },
  )

  it('keeps a newer server choice when the keyboard-submitted action succeeds late', async () => {
    const server = bindAccount('light')
    const wrapper = mountAttached()
    const toggle = wrapper.get('[data-id="nav-theme"]')
    await toggle.trigger('keydown', { key: 'ArrowUp' })
    await nextTick()
    expect(document.activeElement).toBe(
      wrapper.get('[data-id="nav-theme-option-system"]').element,
    )
    // Native buttons translate Enter/Space to a click in the browser.
    ;(document.activeElement as HTMLButtonElement).click()
    await nextTick()
    expect(server.sendAction).toHaveBeenCalledWith(
      'hilos_theme_pick',
      { themePick: 'system' },
      '1',
    )
    expect(document.activeElement).toBe(toggle.element)
    server.handshake('dark')
    server.reply('success')
    await flushPromises()
    expect(hilosThemePick.get()).toBe('dark')
    expect(toggle.attributes('data-position')).toBe('dark')
  })

  it('refuses a stale open row when switching turns off and closes the menu', async () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    const wrapper = mountAttached()
    await wrapper.get('[data-id="nav-theme"]').trigger('click')
    const light = wrapper.get('[data-id="nav-theme-option-light"]')

    bindSnapshot({ switchingEnabled: false, defaultTheme: 'light' }, 'dark')
    // Click before Vue removes the old DOM: the action must read the live guard.
    await light.trigger('click')
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    expect(wrapper.find('[data-id="nav-theme-option-light"]').exists()).toBe(
      false,
    )

    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    await nextTick()
    expect(wrapper.get('[data-id="nav-theme-menu"]').isVisible()).toBe(false)
  })

  it('applies a guest click in memory when localStorage refuses access', async () => {
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
    const wrapper = mountAttached()
    const toggle = wrapper.get('[data-id="nav-theme"]')
    await toggle.trigger('click')

    await wrapper.get('[data-id="nav-theme-option-dark"]').trigger('click')

    expect(hilosThemePick.get()).toBe('dark')
    expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark')
    expect(toggle.attributes('data-position')).toBe('dark')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle.element)
  })
})
