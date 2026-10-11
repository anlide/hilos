import {
  ActionLifecycle,
  bindSessionScope,
  bindSignOut,
  ScopeManager,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { mount, type VueWrapper } from '@vue/test-utils'
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

let releaseTheme: (() => void) | undefined
let releaseSession: (() => void) | undefined

/** Feed identity through the same session binding the shell uses at boot. */
function bindSession(): (userId: number | null) => void {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.add(listener as (signal: ProjectSignal) => void)
      }

      return () => listeners.delete(listener as (signal: ProjectSignal) => void)
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes)
  const actions = new ActionLifecycle({
    sendAction: () => true,
    on: () => () => {},
  })
  const unbind = bindSignOut(scopes, actions)
  releaseSession = () => {
    unbind()
    listeners.clear()
  }

  return (userId) => {
    const signal = {
      kind: 'project',
      type: 'handshake_response',
      data: {
        entities: {
          currentUser: userId === null ? null : { id: userId, name: 'Ada' },
        },
      },
      envelope: {},
    } as unknown as ProjectSignal
    for (const listener of listeners) {
      listener(signal)
    }
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
  releaseTheme = bindThemeState(dummyConnection(), new ScopeManager())
}

function restoreCatalog(): void {
  releaseTheme?.()
  releaseTheme = undefined
  vi.stubGlobal('localStorage', memoryStorage({}))
  const restore = bindThemeState(dummyConnection(), new ScopeManager())
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

  it.each([true, false])(
    'refuses a signed-in choice (signed in before opening: %s) and permits it after sign-out',
    async (beforeOpening) => {
      const handshake = bindSession()
      bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
      const wrapper = mountAttached()
      const toggle = wrapper.get('[data-id="nav-theme"]')
      if (beforeOpening) {
        handshake(1)
      }
      await toggle.trigger('click')
      if (!beforeOpening) {
        handshake(1)
      }

      await wrapper.get('[data-id="nav-theme-option-light"]').trigger('click')

      expect(hilosThemePick.get()).toBe('dark')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
      expect(toggle.attributes('aria-expanded')).toBe('false')
      expect(document.activeElement).toBe(toggle.element)

      handshake(null)
      await toggle.trigger('click')
      await wrapper.get('[data-id="nav-theme-option-light"]').trigger('click')
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
    },
  )

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
