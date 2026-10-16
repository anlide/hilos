// Covers the profile's Theme window (HIL-1441) on the real core: the theme and
// session bindings bootHilos makes, a real action lifecycle with request
// correlation, and server frames that alone move an account's mark. The cases
// are the React window's (react/test/HilosProfileTheme.test.tsx), read through
// change detection rather than through act().
import { Component, signal } from '@angular/core'
import type { ComponentFixture } from '@angular/core/testing'
import { TestBed } from '@angular/core/testing'
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
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { THEME_PICK_STORAGE_KEY } from '../../core/src/theme/themeBrowser.js'
import { HilosProfileTheme } from '../src/profile/HilosProfileTheme.js'

/** The profile row's Change and the window it opens, as the page wires them. */
@Component({
  selector: 'test-theme-row',
  imports: [HilosProfileTheme],
  template: `
    <button type="button" data-id="theme-opener" (click)="shown.set(true)">
      Change
    </button>
    <hilos-profile-theme [(open)]="shown" />
  `,
})
class ThemeRow {
  readonly shown = signal(false)
}

const releases: (() => void)[] = []
let fixture: ComponentFixture<ThemeRow> | undefined

/** Run change detection over the mounted row, if there is one. */
function update(): void {
  fixture?.detectChanges()
}

/** Whether the page still asks for the window. */
function shown(): boolean {
  return fixture?.componentInstance.shown() ?? false
}

afterEach(() => {
  fixture?.destroy()
  fixture = undefined
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
    for (const listener of listeners.get(event) ?? [])
      listener(payload as never)
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
  fixture = TestBed.createComponent(ThemeRow)
  fixture.detectChanges()

  return {
    sendAction,
    handshake,
    settings: (next: ThemeSettings) =>
      emit('projectSignal', { type: 'hilos_theme_settings', data: next }),
    impersonate: () => {
      scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 })
      update()
    },
    state: (next: ConnectionState) => emit('state', next),
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
      await vi.advanceTimersByTimeAsync(0)
      update()
    },
    open(): void {
      byId('theme-opener').focus()
      click('theme-opener')
    },
  }
}

function click(id: string): void {
  byId(id).click()
  update()
}

function key(element: Element, name: string, shiftKey = false): void {
  element.dispatchEvent(
    new KeyboardEvent('keydown', { key: name, shiftKey, bubbles: true }),
  )
  update()
}

function dialog(): HTMLElement {
  const element = byId('profile-theme-modal').closest<HTMLElement>(
    '[role="dialog"]',
  )
  if (!element) throw new Error('Missing theme dialog')

  return element
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
    expect(
      byId('profile-theme-option-dark').querySelector('i')?.classList,
    ).toContain('bi-moon-stars')
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
      else if (method === 'backdrop') {
        dialog().click()
        update()
      } else key(dialog(), 'Escape')
      expect(maybe('profile-theme-modal')).toBeNull()
      expect(shown()).toBe(false)
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
      expect(shown()).toBe(false)
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
    byId('profile-theme-default').click()
    byId('profile-theme-option-light').click()
    update()
    expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
      'hilos_theme_pick',
      { themePick: null },
      '1',
    )
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    expect(byId('profile-theme-default').disabled).toBe(true)
    expect(byId('profile-theme-default').getAttribute('aria-busy')).toBe('true')
    await vi.advanceTimersByTimeAsync(300)
    update()
    expect(byId('profile-theme-loading').parentElement).toBe(
      byId('profile-theme-default'),
    )
    server.handshake(null, 1, true)
    expect(
      byId('profile-theme-option-light').getAttribute('aria-current'),
    ).toBe('true')
    expect(byId('profile-theme-option-light').textContent).toContain('default')
    expect(shown()).toBe(true)
    expect(hilosThemePick.get()).toBeNull()
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBeNull()
    await server.reply('success')
    expect(shown()).toBe(false)
    expect(document.activeElement).toBe(byId('theme-opener'))
    server.open()
    click('profile-theme-default')
    expect(server.sendAction).toHaveBeenCalledTimes(2)
    server.handshake(null, 1, true)
    await server.reply('success')
    expect(shown()).toBe(false)
    expect(hilosThemePick.get()).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('keeps a refused reset retryable and does not close a later opening on its success', async () => {
    const server = setup('dark')
    server.open()
    click('profile-theme-default')
    await server.reply('fail')
    expect(shown()).toBe(true)
    expect(hilosThemePick.get()).toBe('dark')
    expect(localStorage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    expect(byId('hilos-action-error').textContent).toContain(
      'Theme switching is disabled.',
    )
    expect(byId('profile-theme-default').disabled).toBe(false)
    click('profile-theme-default')
    click('profile-theme-close')
    expect(shown()).toBe(false)
    server.open()
    expect(byId('profile-theme-default').disabled).toBe(true)
    server.handshake(null, 1, true)
    await server.reply('success')
    expect(shown()).toBe(true)
    expect(byId('profile-theme-default').disabled).toBe(false)
    expect(byId('profile-theme-option-light').textContent).toContain('default')
    expect(hilosThemePick.get()).toBeNull()
  })

  it('blocks a double click before change detection and places deferred loading only on the submitted position', async () => {
    const server = setup()
    server.open()
    byId('profile-theme-option-dark').click()
    byId('profile-theme-option-system').click()
    byId('profile-theme-default').click()
    update()
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
    await vi.advanceTimersByTimeAsync(299)
    update()
    expect(maybe('profile-theme-loading')).toBeNull()
    await vi.advanceTimersByTimeAsync(1)
    update()
    expect(byId('profile-theme-loading').parentElement).toBe(
      byId('profile-theme-option-dark'),
    )
    expect(
      document.querySelectorAll('[data-id="profile-theme-loading"]'),
    ).toHaveLength(1)
    expect(
      byId('profile-theme-option-dark')
        .querySelector('.bi-check2')
        ?.classList.contains('invisible'),
    ).toBe(true)
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
      const room = byId('hilos-action-error-slot')
      expect(
        byId('hilos-action-error-idle').classList.contains('invisible'),
      ).toBe(true)
      click('profile-theme-option-dark')
      if (outcome === 'fail') await server.reply('fail')
      else if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(30_000)
      else server.state('reconnecting')
      await vi.advanceTimersByTimeAsync(0)
      update()
      expect(shown()).toBe(true)
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
      await server.reply('success', '1')
      expect(shown()).toBe(true)
      expect(hilosThemePick.get()).toBe('light')
      click('profile-theme-option-system')
      expect(maybe('hilos-action-error')).toBeNull()
      expect(server.sendAction).toHaveBeenCalledTimes(2)
      await server.reply('success')
      expect(shown()).toBe(false)
    },
  )

  it('clears an old refusal when the window opens again', async () => {
    const server = setup()
    server.open()
    click('profile-theme-option-dark')
    await server.reply('fail')
    expect(maybe('hilos-action-error')).not.toBeNull()
    click('profile-theme-close')
    server.open()

    expect(maybe('hilos-action-error')).toBeNull()
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
    expect(shown()).toBe(true)
    expect(server.sendAction).not.toHaveBeenCalled()
  })

  it.each(['off', 'signout', 'impersonation'] as const)(
    'closes on %s and refuses a stale DOM click',
    (change) => {
      const server = setup()
      server.open()
      const button = byId('profile-theme-option-dark')
      // The click reaches the row change detection has not removed yet: the
      // handler must read the live guard, not the pass it was drawn in.
      const redraw = fixture
      fixture = undefined
      if (change === 'off')
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
      else if (change === 'signout') server.handshake('light', null)
      else server.impersonate()
      button.click()
      fixture = redraw
      update()
      expect(server.sendAction).not.toHaveBeenCalled()
      expect(shown()).toBe(false)
      expect(maybe('profile-theme-modal')).toBeNull()
      expect(hilosThemePick.get()).toBe('light')
    },
  )

  it.each(['off', 'signout', 'impersonation'] as const)(
    'keeps the window closed when availability returns after %s',
    (change) => {
      const server = setup()
      server.open()
      if (change === 'off') {
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
        server.settings({ switchingEnabled: true, defaultTheme: 'light' })
      } else if (change === 'signout') {
        server.handshake('light', null)
        server.handshake('light')
      } else {
        server.impersonate()
        server.handshake('light')
      }
      expect(shown()).toBe(false)
      expect(maybe('profile-theme-modal')).toBeNull()
    },
  )

  it.each(['off', 'signout', 'impersonation', 'close'] as const)(
    'keeps an admitted write pending after %s and ignores its late success locally',
    async (change) => {
      const server = setup()
      server.open()
      click('profile-theme-option-dark')
      if (change === 'off')
        server.settings({ switchingEnabled: false, defaultTheme: 'light' })
      else if (change === 'signout') server.handshake('light', null)
      else if (change === 'impersonation') server.impersonate()
      else click('profile-theme-close')
      expect(shown()).toBe(false)
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
    click('profile-theme-close')
    server.open()
    expect(byId('profile-theme-option-light').disabled).toBe(true)
    server.handshake('system')
    await server.reply('success')
    expect(shown()).toBe(true)
    expect(
      byId('profile-theme-option-system').getAttribute('aria-current'),
    ).toBe('true')
    expect(byId('profile-theme-option-dark').disabled).toBe(false)
    expect(hilosThemePick.get()).toBe('system')
  })

  it('does not answer for a window destroyed before its success', async () => {
    const server = setup()
    server.open()
    click('profile-theme-option-dark')
    const page = fixture
    fixture = undefined
    page?.destroy()
    // Angular warns about an emit from a destroyed component on the console.
    const warnings = vi.spyOn(console, 'warn')

    await server.reply('success')

    expect(warnings).not.toHaveBeenCalled()
    expect(page?.componentInstance.shown()).toBe(true)
  })

  it('traps Tab in both directions and accepts a native keyboard button activation', async () => {
    const server = setup()
    server.open()
    byId('profile-theme-close').focus()
    key(byId('profile-theme-close'), 'Tab')
    expect(document.activeElement).toBe(byId('modal-close'))
    key(byId('modal-close'), 'Tab', true)
    expect(document.activeElement).toBe(byId('profile-theme-close'))
    byId('profile-theme-option-dark').focus()
    byId('profile-theme-option-dark').click()
    update()
    await server.reply('success')
    expect(server.sendAction).toHaveBeenCalledOnce()
    expect(document.activeElement).toBe(byId('theme-opener'))
  })
})
