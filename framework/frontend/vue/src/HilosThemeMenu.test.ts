import { ScopeManager, type HilosConnection } from '@hilos/core'
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
  restoreCatalog()
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

  it('closes on a row and leaves the pick unchanged', async () => {
    bindSnapshot({ switchingEnabled: true, defaultTheme: 'light' }, 'dark')
    const wrapper = mountAttached()
    const toggle = wrapper.get('[data-id="nav-theme"]')
    await toggle.trigger('click')
    const pick = hilosThemePick.get()

    await wrapper.get('[data-id="nav-theme-option-light"]').trigger('click')

    expect(hilosThemePick.get()).toBe(pick)
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle.element)
  })
})
