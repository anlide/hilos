// Covers the profile's Theme window (HIL-1440) on the real core: the theme and
// session bindings bootHilos makes, a real action lifecycle with request
// correlation, and server frames that alone move an account's mark.
import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  createConnectionInteractionState,
  hilosThemePick,
  hilosToasts,
  ScopeManager,
  type ActionLifecycleSource,
  type ConnectionState,
  type HilosConnection,
  type ThemePick,
  type ThemeSettings,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { THEME_PICK_STORAGE_KEY } from '../../core/src/theme/themeBrowser.js'
import { HilosConnectionInteractionContext } from '../src/hilosConnectionInteraction.js'
import { HilosProfileTheme } from '../src/profile/HilosProfileTheme.js'

const releases: (() => void)[] = []

afterEach(() => {
  cleanup()
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

function maybe(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/** What the page that owns the window holds: whether it asked for it. */
const page = { shown: false, close: () => {} }

/** The profile row's Change and the window it opens, as the page wires them. */
function ThemeRow() {
  const [shown, setShown] = useState(false)
  page.shown = shown
  page.close = () => setShown(false)

  return (
    <>
      <button
        type="button"
        data-id="theme-opener"
        onClick={() => setShown(true)}
      >
        Change
      </button>
      <HilosProfileTheme open={shown} onClose={() => setShown(false)} />
    </>
  )
}

/** The window's one application connection, with real tracked replies. */
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
    act(() => {
      for (const listener of listeners.get(event) ?? [])
        listener(payload as never)
    })
  }
  const sendAction = vi.fn<
    (action: string, data: unknown, requestId?: string) => boolean
  >(() => true)
  const connection = {
    sendAction,
    state: 'connected' as ConnectionState,
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
  const interaction = createConnectionInteractionState(
    connection as unknown as HilosConnection,
  )
  releases.push(() => interaction.dispose())

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
  render(
    <HilosConnectionInteractionContext.Provider value={interaction}>
      <ThemeRow />
    </HilosConnectionInteractionContext.Provider>,
  )

  return {
    sendAction,
    handshake,
    settings: (next: ThemeSettings) =>
      emit('projectSignal', { type: 'hilos_theme_settings', data: next }),
    impersonate: () =>
      act(() =>
        scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }),
      ),
    state(next: ConnectionState): void {
      connection.state = next
      emit('state', next)
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
    open(): void {
      byId('theme-opener').focus()
      fireEvent.click(byId('theme-opener'))
    },
  }
}

function click(id: string): void {
  fireEvent.click(byId(id))
}

function dialog(): HTMLElement {
  const element = byId('profile-theme-modal').closest<HTMLElement>(
    '[role="dialog"]',
  )
  if (!element) throw new Error('Missing theme dialog')

  return element
}

/** Every user close path stays locked while requests or connection block it. */
function expectCloseBlocked(): void {
  const root = dialog()
  expect(byId('profile-theme-close').matches(':disabled')).toBe(true)
  expect(byId('modal-close').matches(':disabled')).toBe(true)
  for (const dismiss of [
    () => click('profile-theme-close'),
    () => click('modal-close'),
    () => fireEvent.keyDown(root, { key: 'Escape' }),
    () => fireEvent.click(root),
  ]) {
    dismiss()
    expect(page.shown).toBe(true)
    expect(dialog()).toBe(root)
  }
}

describe('HilosProfileTheme', () => {
  it('opens a named dialog with three live positions, a default badge and only Close', () => {
    const server = setup(null, {
      switchingEnabled: true,
      defaultTheme: 'system',
    })
    server.open()
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
    expect(
      byId('profile-theme-option-dark')
        .querySelector('.bi-check2')
        ?.classList.contains('invisible'),
    ).toBe(true)
    const reset = byId('profile-theme-default')
    expect(reset.textContent?.trim()).toBe('Use site default')
    expect(reset.hasAttribute('aria-current')).toBe(false)
    expect(reset.hasAttribute('aria-busy')).toBe(false)
    expect(reset.classList.contains('active')).toBe(false)
    expect(reset.querySelector('.bi-check2')).toBeNull()
    expect(maybe('profile-theme-loading')).toBeNull()
    const footer = dialog().querySelector('.modal-footer')
    expect(footer?.querySelectorAll('button')).toHaveLength(1)
    expect(footer?.textContent?.trim()).toBe('Close')
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it.each(['close', 'escape', 'backdrop', 'header'] as const)(
    'closes through %s, returns focus and sends nothing',
    (method) => {
      const server = setup()
      server.open()
      if (method === 'close') click('profile-theme-close')
      else if (method === 'header') click('modal-close')
      else if (method === 'backdrop') fireEvent.click(dialog())
      else fireEvent.keyDown(dialog(), { key: 'Escape' })
      expect(maybe('profile-theme-modal')).toBeNull()
      expect(page.shown).toBe(false)
      expect(document.activeElement).toBe(byId('theme-opener'))
      expect(server.sendAction).not.toHaveBeenCalled()
    },
  )

  it.each(['light', 'dark', 'system'] as const)(
    'sends %s without applying it locally and closes only on its success',
    async (pick) => {
      const server = setup(null)
      server.open()
      click(`profile-theme-option-${pick}`)
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
      expect(maybe('profile-theme-modal')).not.toBeNull()
      server.handshake(pick)
      expect(
        byId(`profile-theme-option-${pick}`).getAttribute('aria-current'),
      ).toBe('true')
      expect(byId(`profile-theme-option-${pick}`).textContent).not.toContain(
        'default',
      )
      expect(maybe('profile-theme-modal')).not.toBeNull()
      await server.reply('success')
      expect(hilosThemePick.get()).toBe(pick)
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe(pick)
      expect(maybe('profile-theme-modal')).toBeNull()
      expect(document.activeElement).toBe(byId('theme-opener'))
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('lets success close without setting a choice, and sends the current explicit choice again', async () => {
    const server = setup('dark')
    server.open()
    click('profile-theme-option-dark')
    expect(server.sendAction).toHaveBeenCalledWith(
      'hilos_theme_pick',
      { themePick: 'dark' },
      '1',
    )
    server.handshake('system')
    await server.reply('success')
    expect(hilosThemePick.get()).toBe('system')
    server.open()
    click('profile-theme-option-light')
    await server.reply('success')
    expect(hilosThemePick.get()).toBe('system')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('system')
    expect(maybe('profile-theme-modal')).toBeNull()
  })

  it('resets only on the account frame, keeps the default mark live and allows repeated reset', async () => {
    const server = setup('dark')
    server.open()
    const body = byId('profile-theme-modal')
    const fieldset = body.querySelector('fieldset')!
    const room = byId('hilos-action-error-slot')
    const reset = byId('profile-theme-default')
    const idleClass = reset.className
    const label = reset.firstElementChild!
    expect(fieldset.disabled).toBe(false)
    expect(fieldset.className).toBe('m-0 p-0 border-0')
    expect(fieldset.contains(room)).toBe(true)
    expect(fieldset.contains(reset)).toBe(false)
    expect(reset.previousElementSibling?.tagName).toBe('HR')
    reset.focus()
    act(() => {
      reset.click()
      byId('profile-theme-option-light').click()
    })
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_theme_pick',
      { themePick: null },
      '1',
    )
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    // The reset is locked by its own flight at once and keeps its look; the
    // rows are off natively by a flight that is not theirs.
    const spinner = (): Element | null =>
      reset.querySelector('[data-id="loading-button-spinner"]')
    for (const elapsed of [0, 299, 1]) {
      await act(async () => {
        await vi.advanceTimersByTimeAsync(elapsed)
      })
      expect(byId('profile-theme-modal')).toBe(body)
      expect(body.querySelector('fieldset')).toBe(fieldset)
      expect(fieldset.disabled).toBe(true)
      expect(byId('hilos-action-error-slot')).toBe(room)
      expect(byId('profile-theme-default')).toBe(reset)
      expect(reset.firstElementChild).toBe(label)
      expect(document.activeElement).toBe(reset)
      expect(reset.disabled).toBe(false)
      expect(reset.matches(':disabled')).toBe(false)
      expect(reset.getAttribute('aria-disabled')).toBe('true')
      expect(reset.getAttribute('aria-busy')).toBe('true')
      expect(reset.className).toBe(
        elapsed === 1 ? `${idleClass} disabled` : idleClass,
      )
      expect(label.classList.contains('invisible')).toBe(elapsed === 1)
      expect(spinner() !== null).toBe(elapsed === 1)
      for (const pick of ['light', 'dark', 'system']) {
        const option = byId(`profile-theme-option-${pick}`)
        expect(fieldset.contains(option)).toBe(true)
        expect(option.disabled).toBe(true)
        expect(option.matches(':disabled')).toBe(true)
        click(`profile-theme-option-${pick}`)
      }
      expectCloseBlocked()
      expect(fireEvent.click(reset)).toBe(false)
      expect(server.sendAction).toHaveBeenCalledOnce()
      expect(hilosThemePick.get()).toBe('dark')
      expect(
        byId('profile-theme-option-dark').getAttribute('aria-current'),
      ).toBe('true')
    }
    // The rows keep their own deferred indicator, and none of them shows it.
    expect(maybe('profile-theme-loading')).toBeNull()
    for (const pick of ['light', 'dark', 'system'])
      expect(
        byId(`profile-theme-option-${pick}`).hasAttribute('aria-busy'),
      ).toBe(false)
    server.handshake(null, 1, true)
    expect(
      byId('profile-theme-option-light').getAttribute('aria-current'),
    ).toBe('true')
    expect(byId('profile-theme-option-light').textContent).toContain('default')
    expect(page.shown).toBe(true)
    expect(hilosThemePick.get()).toBeNull()
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBeNull()
    await server.reply('success')
    expect(page.shown).toBe(false)
    expect(document.activeElement).toBe(byId('theme-opener'))
    server.open()
    click('profile-theme-default')
    expect(server.sendAction).toHaveBeenCalledTimes(2)
    server.handshake(null, 1, true)
    await server.reply('success')
    expect(page.shown).toBe(false)
    expect(hilosThemePick.get()).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('keeps a refused reset retryable and does not close a later opening on its success', async () => {
    const server = setup('dark')
    server.open()
    const body = byId('profile-theme-modal')
    const fieldset = body.querySelector('fieldset')!
    const room = byId('hilos-action-error-slot')
    click('profile-theme-default')
    expect(fieldset.disabled).toBe(true)
    await server.reply('fail')
    expect(page.shown).toBe(true)
    expect(byId('profile-theme-modal')).toBe(body)
    expect(body.querySelector('fieldset')).toBe(fieldset)
    expect(fieldset.disabled).toBe(false)
    expect(byId('hilos-action-error-slot')).toBe(room)
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    expect(byId('hilos-action-error').textContent).toContain(
      'Theme switching is disabled.',
    )
    expect(byId('profile-theme-default').hasAttribute('aria-disabled')).toBe(
      false,
    )
    expect(byId('profile-theme-default').matches(':disabled')).toBe(false)
    expect(byId('profile-theme-close').matches(':disabled')).toBe(false)
    expect(byId('modal-close').matches(':disabled')).toBe(false)
    for (const pick of ['light', 'dark', 'system'])
      expect(byId(`profile-theme-option-${pick}`).matches(':disabled')).toBe(
        false,
      )
    click('profile-theme-default')
    expect(server.sendAction).toHaveBeenCalledTimes(2)
    expect(maybe('hilos-action-error')).toBeNull()
    expect(body.querySelector('fieldset')).toBe(fieldset)
    expect(fieldset.disabled).toBe(true)
    act(() => page.close())
    expect(page.shown).toBe(false)
    server.open()
    expect(byId('profile-theme-default').getAttribute('aria-disabled')).toBe(
      'true',
    )
    server.handshake(null, 1, true)
    await server.reply('success')
    expect(page.shown).toBe(true)
    expect(byId('profile-theme-default').hasAttribute('aria-disabled')).toBe(
      false,
    )
    expect(byId('profile-theme-option-light').textContent).toContain('default')
    expect(hilosThemePick.get()).toBeNull()
  })

  it('leaves a reset answered before its spinner with no disabled look', async () => {
    const server = setup('dark')
    server.open()
    const reset = byId('profile-theme-default')
    click('profile-theme-default')
    expect(server.sendAction).toHaveBeenCalledTimes(1)
    expect(reset.getAttribute('aria-busy')).toBe('true')
    await act(async () => {
      await vi.advanceTimersByTimeAsync(299)
    })
    await server.reply('fail')
    expect(reset.hasAttribute('aria-busy')).toBe(false)
    expect(reset.hasAttribute('aria-disabled')).toBe(false)
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300)
    })
    expect(reset.disabled).toBe(false)
    expect(reset.classList.contains('disabled')).toBe(false)
    expect(reset.querySelector('[data-id="loading-button-spinner"]')).toBeNull()
    expect(server.sendAction).toHaveBeenCalledTimes(1)
  })

  it('blocks a double click before React re-renders and places deferred loading only on the submitted position', async () => {
    const server = setup()
    server.open()
    const body = byId('profile-theme-modal')
    const fieldset = body.querySelector('fieldset')!
    act(() => {
      byId('profile-theme-option-dark').click()
      byId('profile-theme-option-system').click()
      byId('profile-theme-default').click()
    })
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_theme_pick',
      { themePick: 'dark' },
      '1',
    )
    for (const pick of ['light', 'dark', 'system'])
      expect(byId(`profile-theme-option-${pick}`).disabled).toBe(true)
    expect(byId('profile-theme-default').disabled).toBe(true)
    expect(byId('profile-theme-option-dark').getAttribute('aria-busy')).toBe(
      'true',
    )
    expect(byId('profile-theme-option-system').hasAttribute('aria-busy')).toBe(
      false,
    )
    expect(byId('profile-theme-default').hasAttribute('aria-busy')).toBe(false)
    for (const elapsed of [0, 299, 1]) {
      await act(async () => {
        await vi.advanceTimersByTimeAsync(elapsed)
      })
      expect(byId('profile-theme-modal')).toBe(body)
      expect(body.querySelector('fieldset')).toBe(fieldset)
      expect(fieldset.disabled).toBe(true)
      for (const pick of ['light', 'dark', 'system']) {
        expect(byId(`profile-theme-option-${pick}`).matches(':disabled')).toBe(
          true,
        )
        click(`profile-theme-option-${pick}`)
      }
      expect(byId('profile-theme-default').disabled).toBe(true)
      expect(byId('profile-theme-default').matches(':disabled')).toBe(true)
      click('profile-theme-default')
      expectCloseBlocked()
      expect(server.sendAction).toHaveBeenCalledOnce()
      expect(maybe('profile-theme-loading') !== null).toBe(elapsed === 1)
      expect(
        byId('profile-theme-option-light').getAttribute('aria-current'),
      ).toBe('true')
      expect(hilosThemePick.get()).toBe('light')
    }
    expect(byId('profile-theme-loading').parentElement).toBe(
      byId('profile-theme-option-dark'),
    )
    expect(
      byId('profile-theme-default').querySelector(
        '[data-id="loading-button-spinner"]',
      ),
    ).toBeNull()
    expect(
      document.querySelectorAll('[data-id="profile-theme-loading"]'),
    ).toHaveLength(1)
    expect(
      byId('profile-theme-option-light').getAttribute('aria-current'),
    ).toBe('true')
    await server.reply('success')
  })

  it.each(['fail', 'timeout', 'disconnected'] as const)(
    'keeps the dialog and confirmed theme on %s, with the shared error row and toast',
    async (outcome) => {
      const server = setup()
      server.open()
      const body = byId('profile-theme-modal')
      const fieldset = body.querySelector('fieldset')!
      const room = byId('hilos-action-error-slot')
      expect(
        byId('hilos-action-error-idle').classList.contains('invisible'),
      ).toBe(true)
      click('profile-theme-option-dark')
      if (outcome === 'fail') await server.reply('fail')
      else if (outcome === 'timeout')
        await act(async () => {
          await vi.advanceTimersByTimeAsync(30_000)
        })
      else server.state('reconnecting')
      await act(async () => {
        await vi.advanceTimersByTimeAsync(0)
      })
      expect(page.shown).toBe(true)
      expect(byId('profile-theme-modal')).toBe(body)
      expect(body.querySelector('fieldset')).toBe(fieldset)
      expect(fieldset.disabled).toBe(false)
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
      for (const id of [
        'profile-theme-option-light',
        'profile-theme-option-dark',
        'profile-theme-option-system',
        'profile-theme-default',
        'profile-theme-close',
        'modal-close',
      ])
        expect(byId(id).matches(':disabled')).toBe(outcome === 'disconnected')
      expect(hilosThemePick.get()).toBe('light')
      expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(document.documentElement.getAttribute('data-bs-theme')).toBe(
        'light',
      )
      server.state('connected')
      await server.reply('success', '1')
      expect(page.shown).toBe(true)
      expect(body.querySelector('fieldset')).toBe(fieldset)
      expect(byId('profile-theme-default').matches(':disabled')).toBe(false)
      expect(byId('profile-theme-close').matches(':disabled')).toBe(false)
      expect(hilosThemePick.get()).toBe('light')
      click('profile-theme-option-system')
      expect(maybe('hilos-action-error')).toBeNull()
      expect(server.sendAction).toHaveBeenCalledTimes(2)
      await server.reply('success')
      expect(page.shown).toBe(false)
    },
  )

  it('keeps the connection lock independent of the custom body and request busy', async () => {
    const server = setup('dark')
    server.open()
    const body = byId('profile-theme-modal')
    const fieldset = body.querySelector('fieldset')!
    const reset = byId('profile-theme-default')
    click('profile-theme-default')
    expect(fieldset.disabled).toBe(true)
    expect(reset.matches(':disabled')).toBe(false)
    await server.reply('fail')
    expect(fieldset.disabled).toBe(false)
    server.state('reconnecting')
    expect(byId('profile-theme-modal')).toBe(body)
    expect(body.querySelector('fieldset')).toBe(fieldset)
    expect(fieldset.disabled).toBe(false)
    expect(reset.disabled).toBe(false)
    expect(reset.matches(':disabled')).toBe(true)
    expect(reset.hasAttribute('aria-busy')).toBe(false)
    for (const pick of ['light', 'dark', 'system']) {
      expect(byId(`profile-theme-option-${pick}`).disabled).toBe(false)
      expect(byId(`profile-theme-option-${pick}`).matches(':disabled')).toBe(
        true,
      )
      act(() => byId(`profile-theme-option-${pick}`).click())
    }
    act(() => reset.click())
    expectCloseBlocked()
    expect(server.sendAction).toHaveBeenCalledOnce()
    server.state('connected')
    expect(body.querySelector('fieldset')).toBe(fieldset)
    expect(fieldset.disabled).toBe(false)
    expect(reset.matches(':disabled')).toBe(false)
    expect(page.shown).toBe(true)
    expect(server.sendAction).toHaveBeenCalledOnce()
    click('profile-theme-default')
    expect(fieldset.disabled).toBe(true)
    expect(reset.matches(':disabled')).toBe(false)
    expect(server.sendAction).toHaveBeenCalledTimes(2)
    await server.reply('success')
    expect(page.shown).toBe(false)
  })

  it('keeps marks live while open, following the default and then a server pick', () => {
    const server = setup(null)
    server.open()
    server.settings({ switchingEnabled: true, defaultTheme: 'dark' })
    expect(byId('profile-theme-option-dark').getAttribute('aria-current')).toBe(
      'true',
    )
    expect(byId('profile-theme-option-dark').textContent).toContain('default')
    server.handshake('system')
    expect(
      byId('profile-theme-option-system').getAttribute('aria-current'),
    ).toBe('true')
    expect(byId('profile-theme-option-system').textContent).not.toContain(
      'default',
    )
    expect(page.shown).toBe(true)
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it.each(['off', 'signout', 'impersonation'] as const)(
    'closes on %s and refuses a stale DOM click',
    (change) => {
      const server = setup()
      server.open()
      const button = byId('profile-theme-option-dark')
      // The click reaches the row React has not removed yet: the handler must
      // read the live guard, not the render it was drawn in.
      act(() => {
        if (change === 'off')
          server.settings({ switchingEnabled: false, defaultTheme: 'light' })
        else if (change === 'signout') server.handshake('light', null)
        else server.impersonate()
        button.click()
      })
      expect(server.sendAction).not.toHaveBeenCalled()
      expect(page.shown).toBe(false)
      expect(maybe('profile-theme-modal')).toBeNull()
      expect(hilosThemePick.get()).toBe('light')
    },
  )

  it.each(['off', 'signout', 'impersonation', 'parent close'] as const)(
    'keeps an admitted write pending after %s and ignores its late success locally',
    async (change) => {
      const server = setup()
      server.open()
      click('profile-theme-option-dark')
      if (change === 'off')
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
      else if (change === 'signout') server.handshake('light', null)
      else if (change === 'impersonation') server.impersonate()
      else act(() => page.close())
      expect(page.shown).toBe(false)
      await server.reply('success')
      expect(server.sendAction).toHaveBeenCalledOnce()
      expect(hilosThemePick.get()).toBe('light')
      expect(maybe('profile-theme-modal')).toBeNull()
    },
  )

  it('does not close a new opening when the previous opening succeeds', async () => {
    const server = setup()
    server.open()
    click('profile-theme-option-dark')
    act(() => page.close())
    server.open()
    expect(byId('profile-theme-option-light').disabled).toBe(true)
    server.handshake('system')
    await server.reply('success')
    expect(page.shown).toBe(true)
    expect(
      byId('profile-theme-option-system').getAttribute('aria-current'),
    ).toBe('true')
    expect(byId('profile-theme-option-dark').disabled).toBe(false)
    expect(hilosThemePick.get()).toBe('system')
  })

  it('traps Tab in both directions and accepts a native keyboard button activation', async () => {
    const server = setup()
    server.open()
    byId('profile-theme-close').focus()
    fireEvent.keyDown(byId('profile-theme-close'), { key: 'Tab' })
    expect(document.activeElement).toBe(byId('modal-close'))
    fireEvent.keyDown(byId('modal-close'), { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(byId('profile-theme-close'))
    byId('profile-theme-option-dark').focus()
    act(() => byId('profile-theme-option-dark').click())
    await server.reply('success')
    expect(server.sendAction).toHaveBeenCalledOnce()
    expect(document.activeElement).toBe(byId('theme-opener'))
  })
})
