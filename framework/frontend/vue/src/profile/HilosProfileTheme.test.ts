import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  hilosThemePick,
  hilosToasts,
  ScopeManager,
  type ActionLifecycleSource,
  type ConnectionState,
  type HilosConnection,
  type ThemePick,
  type ThemeSettings,
} from '@hilos/core/base'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import { THEME_PICK_STORAGE_KEY } from '../../../core/src/theme/themeBrowser.js'
import HilosProfileTheme from './HilosProfileTheme.vue'

const wrappers: VueWrapper[] = []
const releases: (() => void)[] = []

afterEach(() => {
  while (wrappers.length) wrappers.pop()?.unmount()
  while (releases.length) releases.pop()?.()
  document.body.innerHTML = ''
  vi.clearAllTimers()
  vi.useRealTimers()
  vi.unstubAllGlobals()
  hilosToasts.clear()
})

function byId(id: string): HTMLButtonElement {
  const element = document.querySelector<HTMLButtonElement>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing ${id}`)

  return element
}

/** The modal's one application connection, with real tracked replies. */
function setup(
  pick: ThemePick = 'light',
  settings: ThemeSettings = { switchingEnabled: true, defaultTheme: 'light' },
) {
  vi.useFakeTimers()
  hilosToasts.clear()
  const stored = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => stored.get(key) ?? null,
    setItem: (key: string, value: string) => stored.set(key, value),
    removeItem: (key: string) => stored.delete(key),
  })
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const emit = (event: string, payload: unknown): void => {
    for (const listener of listeners.get(event) ?? [])
      listener(payload as never)
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
  const actions = new ActionLifecycle(
    connection as unknown as ActionLifecycleSource,
  )
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  releases.push(bindSignOut(scopes, actions))
  releases.push(bindImpersonation(scopes, actions))
  releases.push(
    bindThemeState(connection as unknown as HilosConnection, scopes, actions),
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
  const shown = ref(false)
  const wrapper = mount(
    {
      components: { HilosProfileTheme },
      setup: () => ({ shown }),
      template:
        '<button data-id="theme-opener" @click="shown = true">Change</button><HilosProfileTheme v-model="shown" />',
    },
    { attachTo: document.body },
  )
  wrappers.push(wrapper)

  return {
    wrapper,
    shown,
    sendAction,
    handshake,
    settings: (next: ThemeSettings) =>
      emit('projectSignal', { type: 'hilos_theme_settings', data: next }),
    impersonate: () =>
      scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }),
    state: (next: ConnectionState) => emit('state', next),
    reply: (
      outcome: 'success' | 'fail',
      requestId = sendAction.mock.lastCall?.[2],
    ) =>
      emit(outcome === 'success' ? 'actionSuccess' : 'actionError', {
        action: 'hilos_theme_pick',
        requestId,
        reason: 'Theme switching is disabled.',
      }),
    async open(): Promise<void> {
      byId('theme-opener').focus()
      byId('theme-opener').click()
      await nextTick()
      await nextTick()
    },
  }
}

function dialog(): HTMLElement {
  const element = byId('profile-theme-modal').closest<HTMLElement>(
    '[role="dialog"]',
  )
  if (!element) throw new Error('Missing theme dialog')

  return element
}

describe('HilosProfileTheme', () => {
  it('opens a named dialog with three live positions, a default badge and only Close', async () => {
    const server = setup(null, {
      switchingEnabled: true,
      defaultTheme: 'system',
    })
    await server.open()
    expect(dialog().getAttribute('aria-label')).toBe('Theme')
    expect(dialog().getAttribute('aria-modal')).toBe('true')
    expect(document.activeElement).toBe(dialog())
    const list = byId('profile-theme-modal').querySelector('.list-group')
    expect(list?.querySelectorAll('button')).toHaveLength(3)
    expect(list?.textContent).toContain('Light')
    expect(list?.textContent).toContain('Dark')
    expect(list?.textContent).toContain('System')
    const active = byId('profile-theme-option-system')
    expect(active.classList.contains('active')).toBe(true)
    expect(active.getAttribute('aria-current')).toBe('true')
    expect(active.textContent).toContain('default')
    expect(
      active.querySelector('.bi-check2')?.classList.contains('invisible'),
    ).toBe(false)
    expect(byId('profile-theme-option-dark').hasAttribute('aria-current')).toBe(
      false,
    )
    const reset = byId('profile-theme-default')
    expect(reset.textContent?.trim()).toBe('Use site default')
    expect(reset.hasAttribute('aria-current')).toBe(false)
    expect(reset.hasAttribute('aria-busy')).toBe(false)
    expect(reset.classList.contains('active')).toBe(false)
    expect(reset.querySelector('.bi-check2')).toBeNull()
    expect(
      document.querySelector('[data-id="profile-theme-loading"]'),
    ).toBeNull()
    const footer = dialog().querySelector('.modal-footer')
    expect(footer?.querySelectorAll('button')).toHaveLength(1)
    expect(footer?.textContent?.trim()).toBe('Close')
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it.each(['close', 'escape', 'backdrop', 'header'] as const)(
    'closes through %s, returns focus and sends nothing',
    async (method) => {
      const server = setup()
      await server.open()
      if (method === 'close') byId('profile-theme-close').click()
      else if (method === 'header') byId('modal-close').click()
      else if (method === 'backdrop') dialog().click()
      else
        dialog().dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      await nextTick()
      expect(
        document.querySelector('[data-id="profile-theme-modal"]'),
      ).toBeNull()
      expect(document.activeElement).toBe(byId('theme-opener'))
      expect(server.sendAction).not.toHaveBeenCalled()
    },
  )

  it.each(['light', 'dark', 'system'] as const)(
    'sends %s without applying it locally and closes only on its success',
    async (pick) => {
      const server = setup(null)
      await server.open()
      byId(`profile-theme-option-${pick}`).click()
      await nextTick()
      expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
        'hilos_theme_pick',
        { themePick: pick },
        '1',
      )
      expect(hilosThemePick.get()).toBeNull()
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBeNull()
      expect(document.documentElement.getAttribute('data-bs-theme')).toBe(
        'light',
      )
      expect(
        document.querySelector('[data-id="profile-theme-modal"]'),
      ).not.toBeNull()
      server.handshake(pick)
      await nextTick()
      expect(
        byId(`profile-theme-option-${pick}`).getAttribute('aria-current'),
      ).toBe('true')
      expect(byId(`profile-theme-option-${pick}`).textContent).not.toContain(
        'default',
      )
      expect(
        document.querySelector('[data-id="profile-theme-modal"]'),
      ).not.toBeNull()
      server.reply('success')
      await flushPromises()
      expect(hilosThemePick.get()).toBe(pick)
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe(pick)
      expect(
        document.querySelector('[data-id="profile-theme-modal"]'),
      ).toBeNull()
      expect(document.activeElement).toBe(byId('theme-opener'))
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('lets success close without setting a choice, and sends the current explicit choice again', async () => {
    const server = setup('dark')
    await server.open()
    byId('profile-theme-option-dark').click()
    expect(server.sendAction).toHaveBeenCalledWith(
      'hilos_theme_pick',
      { themePick: 'dark' },
      '1',
    )
    server.handshake('system')
    server.reply('success')
    await flushPromises()
    expect(hilosThemePick.get()).toBe('system')
    await server.open()
    byId('profile-theme-option-light').click()
    server.reply('success')
    await flushPromises()
    expect(hilosThemePick.get()).toBe('system')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('system')
    expect(document.querySelector('[data-id="profile-theme-modal"]')).toBeNull()
  })

  it('resets only on the account frame, keeps the default mark live and allows repeated reset', async () => {
    const server = setup('dark')
    await server.open()
    byId('profile-theme-default').click()
    byId('profile-theme-option-light').click()
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_theme_pick',
      { themePick: null },
      '1',
    )
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    await nextTick()
    const reset = byId('profile-theme-default')
    expect(reset.disabled).toBe(false)
    expect(reset.getAttribute('aria-disabled')).toBe('true')
    expect(reset.getAttribute('aria-busy')).toBe('true')
    expect(reset.classList.contains('disabled')).toBe(false)
    expect(reset.querySelector('[data-id="loading-button-spinner"]')).toBeNull()
    await vi.advanceTimersByTimeAsync(300)
    expect(
      reset.querySelector('[data-id="loading-button-spinner"]'),
    ).not.toBeNull()
    expect(reset.classList.contains('disabled')).toBe(true)
    server.handshake(null, 1, true)
    await nextTick()
    expect(
      byId('profile-theme-option-light').getAttribute('aria-current'),
    ).toBe('true')
    expect(byId('profile-theme-option-light').textContent).toContain('default')
    expect(server.shown.value).toBe(true)
    expect(hilosThemePick.get()).toBeNull()
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBeNull()
    server.reply('success')
    await flushPromises()
    expect(server.shown.value).toBe(false)
    expect(document.activeElement).toBe(byId('theme-opener'))
    await server.open()
    byId('profile-theme-default').click()
    expect(server.sendAction).toHaveBeenCalledTimes(2)
    server.handshake(null, 1, true)
    server.reply('success')
    await flushPromises()
    expect(server.shown.value).toBe(false)
    expect(hilosThemePick.get()).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('keeps a refused reset retryable and does not close a later opening on its success', async () => {
    const server = setup('dark')
    await server.open()
    byId('profile-theme-default').click()
    server.reply('fail')
    await flushPromises()
    expect(server.shown.value).toBe(true)
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    expect(byId('hilos-action-error').textContent).toContain(
      'Theme switching is disabled.',
    )
    expect(byId('profile-theme-default').hasAttribute('aria-disabled')).toBe(
      false,
    )
    byId('profile-theme-default').click()
    byId('profile-theme-close').click()
    await nextTick()
    await server.open()
    expect(byId('profile-theme-default').getAttribute('aria-disabled')).toBe(
      'true',
    )
    server.handshake(null, 1, true)
    server.reply('success')
    await flushPromises()
    expect(server.shown.value).toBe(true)
    expect(byId('profile-theme-default').hasAttribute('aria-disabled')).toBe(
      false,
    )
    expect(byId('profile-theme-option-light').textContent).toContain('default')
    expect(hilosThemePick.get()).toBeNull()
  })

  it('blocks repeat clicks immediately and places deferred loading only on the submitted position', async () => {
    const server = setup()
    await server.open()
    byId('profile-theme-option-dark').click()
    byId('profile-theme-option-system').click()
    expect(server.sendAction).toHaveBeenCalledOnce()
    await nextTick()
    for (const pick of ['light', 'dark', 'system'])
      expect(byId(`profile-theme-option-${pick}`).disabled).toBe(true)
    expect(byId('profile-theme-option-dark').getAttribute('aria-busy')).toBe(
      'true',
    )
    expect(byId('profile-theme-default').disabled).toBe(true)
    expect(byId('profile-theme-default').hasAttribute('aria-busy')).toBe(false)
    await vi.advanceTimersByTimeAsync(299)
    expect(
      document.querySelector('[data-id="profile-theme-loading"]'),
    ).toBeNull()
    await vi.advanceTimersByTimeAsync(1)
    expect(byId('profile-theme-loading').parentElement).toBe(
      byId('profile-theme-option-dark'),
    )
    expect(
      byId('profile-theme-default').querySelector(
        '[data-id="loading-button-spinner"]',
      ),
    ).toBeNull()
    expect(
      byId('profile-theme-option-light').getAttribute('aria-current'),
    ).toBe('true')
    server.reply('success')
    await flushPromises()
  })

  it.each(['fail', 'timeout', 'disconnected'] as const)(
    'keeps the dialog and confirmed theme on %s, with the shared error row and toast',
    async (outcome) => {
      const server = setup()
      await server.open()
      const room = byId('hilos-action-error-slot')
      expect(
        byId('hilos-action-error-idle').classList.contains('invisible'),
      ).toBe(true)
      byId('profile-theme-option-dark').click()
      if (outcome === 'fail') server.reply('fail')
      else if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(30_000)
      else server.state('reconnecting')
      await flushPromises()
      expect(server.shown.value).toBe(true)
      expect(byId('hilos-action-error-slot')).toBe(room)
      expect(room.getAttribute('role')).toBe('alert')
      expect(room.getAttribute('aria-live')).toBe('assertive')
      const messages = {
        fail: 'Theme switching is disabled.',
        timeout: 'The action timed out. Please try again.',
        disconnected:
          'The connection dropped before the action completed. Please try again.',
      }
      expect(byId('hilos-action-error').textContent).toContain(
        messages[outcome],
      )
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: messages[outcome], severity: 'error' },
      ])
      expect(byId('profile-theme-option-dark').disabled).toBe(false)
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(document.documentElement.getAttribute('data-bs-theme')).toBe(
        'light',
      )
      server.state('connected')
      server.reply('success', '1')
      await flushPromises()
      expect(server.shown.value).toBe(true)
      expect(hilosThemePick.get()).toBe('light')
      byId('profile-theme-option-system').click()
      await nextTick()
      expect(
        document.querySelector('[data-id="hilos-action-error"]'),
      ).toBeNull()
      expect(server.sendAction).toHaveBeenCalledTimes(2)
      server.reply('success')
      await flushPromises()
      expect(server.shown.value).toBe(false)
    },
  )

  it('keeps marks live while open, following the default and then a server pick', async () => {
    const server = setup(null)
    await server.open()
    server.settings({ switchingEnabled: true, defaultTheme: 'dark' })
    await nextTick()
    expect(byId('profile-theme-option-dark').getAttribute('aria-current')).toBe(
      'true',
    )
    expect(byId('profile-theme-option-dark').textContent).toContain('default')
    server.handshake('system')
    await nextTick()
    expect(
      byId('profile-theme-option-system').getAttribute('aria-current'),
    ).toBe('true')
    expect(byId('profile-theme-option-system').textContent).not.toContain(
      'default',
    )
    expect(server.shown.value).toBe(true)
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it.each(['off', 'signout', 'impersonation'] as const)(
    'closes on %s and refuses a stale DOM click',
    async (change) => {
      const server = setup()
      await server.open()
      const button = byId('profile-theme-option-dark')
      if (change === 'off')
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
      else if (change === 'signout') server.handshake('light', null)
      else server.impersonate()
      button.click()
      await nextTick()
      expect(server.sendAction).not.toHaveBeenCalled()
      expect(server.shown.value).toBe(false)
      expect(
        document.querySelector('[data-id="profile-theme-modal"]'),
      ).toBeNull()
      expect(hilosThemePick.get()).toBe('light')
    },
  )

  it.each(['off', 'signout', 'impersonation', 'close'] as const)(
    'keeps an admitted write pending after %s and ignores its late success locally',
    async (change) => {
      const server = setup()
      await server.open()
      byId('profile-theme-option-dark').click()
      if (change === 'off')
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
      else if (change === 'signout') server.handshake('light', null)
      else if (change === 'impersonation') server.impersonate()
      else byId('profile-theme-close').click()
      await nextTick()
      expect(server.shown.value).toBe(false)
      server.reply('success')
      await flushPromises()
      expect(server.sendAction).toHaveBeenCalledOnce()
      expect(hilosThemePick.get()).toBe('light')
      expect(
        document.querySelector('[data-id="profile-theme-modal"]'),
      ).toBeNull()
    },
  )

  it('does not close a new opening when the previous opening succeeds', async () => {
    const server = setup()
    await server.open()
    byId('profile-theme-option-dark').click()
    byId('profile-theme-close').click()
    await nextTick()
    await server.open()
    expect(byId('profile-theme-option-light').disabled).toBe(true)
    server.handshake('system')
    server.reply('success')
    await flushPromises()
    expect(server.shown.value).toBe(true)
    expect(
      byId('profile-theme-option-system').getAttribute('aria-current'),
    ).toBe('true')
    expect(byId('profile-theme-option-dark').disabled).toBe(false)
    expect(hilosThemePick.get()).toBe('system')
  })

  it('traps Tab in both directions and accepts a native keyboard button activation', async () => {
    const server = setup()
    await server.open()
    byId('profile-theme-close').focus()
    byId('profile-theme-close').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }),
    )
    expect(document.activeElement).toBe(byId('modal-close'))
    byId('modal-close').dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Tab',
        shiftKey: true,
        bubbles: true,
      }),
    )
    expect(document.activeElement).toBe(byId('profile-theme-close'))
    byId('profile-theme-option-dark').focus()
    byId('profile-theme-option-dark').click()
    server.reply('success')
    await flushPromises()
    expect(server.sendAction).toHaveBeenCalledOnce()
    expect(document.activeElement).toBe(byId('theme-opener'))
  })
})
