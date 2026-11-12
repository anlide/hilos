/// <reference types="node" />
// ThemeMenu-only transport inputs and the real boot binding used by its cases.
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  bindSignOut,
  bindThemeState,
  hilosToasts,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  ScopeManager,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_THEME_SETTINGS,
  type ActionLifecycleSource,
  type ConnectionState,
  type HilosConnection,
  type ThemePick,
  type ThemeSettings,
} from '@hilos/core/base'
import { vi } from 'vitest'
import type * as Vitest from 'vitest'

import {
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
} from '../core/src/theme/themeBrowser.js'

export type ThemeMenuRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

export interface ThemeMenuInput {
  account?: boolean
  beforeMount?: (server: ThemeMenuServer) => void
  pick?: ThemePick
  settings?: ThemeSettings
  shell?: boolean
  allowDisconnectedInteraction?: boolean
}

/** Raw frames do not flush a framework: stale events can follow in one turn. */
export interface ThemeMenuServer {
  readonly connection: HilosConnection
  readonly sendAction: ReturnType<
    typeof vi.fn<(action: string, data: unknown, requestId?: string) => boolean>
  >
  handshake(
    pick: ThemePick,
    userId?: number | null,
    initialized?: boolean,
  ): void
  impersonate(): void
  settings(value: ThemeSettings): void
  state(value: ConnectionState): void
  reply(outcome: 'success' | 'fail', requestId?: string): void
  snapshot(settings: ThemeSettings, pick: ThemePick): void
  refuseStorage(): void
}

/** Only rendering, native event batching and the SDK's lifecycle live locally. */
export interface ThemeMenuView {
  readonly root: HTMLElement
  readonly server: ThemeMenuServer
  update(change: () => void): Promise<void>
  keyboardFlush(): Promise<void>
  settle(): Promise<void>
  advance(milliseconds: number): Promise<void>
  unmount(): Promise<void>
}

export type WithThemeMenu = (
  input: ThemeMenuInput,
  steps: (view: ThemeMenuView) => Promise<void>,
) => Promise<void>

// Real Bootstrap supplies visibility semantics; cases never freeze its classes.
const bootstrapCss = readFileSync(
  createRequire(import.meta.url).resolve('bootstrap/dist/css/bootstrap.css'),
  'utf8',
)

/** Construct browser input without leaving an undeclared browser value. */
function memoryStorage(entries: Record<string, string>): Storage {
  const store = new Map(Object.entries(entries))
  return {
    get length() {
      return store.size
    },
    clear: () => store.clear(),
    getItem: (key) => store.get(key) ?? null,
    key: (index) => [...store.keys()][index] ?? null,
    removeItem: (key) => {
      store.delete(key)
    },
    setItem: (key, value) => {
      store.set(key, value)
    },
  }
}

/** Restore the original descriptor, including a property that was inherited. */
function restoreProperty(
  key: string,
  descriptor: PropertyDescriptor | undefined,
): void {
  if (descriptor === undefined) Reflect.deleteProperty(globalThis, key)
  else Object.defineProperty(globalThis, key, descriptor)
}

/** Own clocks, globals and boot bindings outside the real component's lifetime. */
export async function withThemeMenuState(
  input: ThemeMenuInput,
  steps: (server: ThemeMenuServer) => Promise<void>,
): Promise<void> {
  const storage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
  const media = Object.getOwnPropertyDescriptor(globalThis, 'matchMedia')
  const theme = document.documentElement.getAttribute('data-bs-theme')
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const stylesheet = document.head.appendChild(document.createElement('style'))
  stylesheet.textContent = bootstrapCss
  const settings = input.settings ?? {
    switchingEnabled: true,
    defaultTheme: 'light',
  }
  const pick = input.pick === undefined ? 'dark' : input.pick
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const emit = (event: string, data: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(data as never)
  }
  let state: ConnectionState = 'connected'
  const sendAction = vi.fn<
    (action: string, data: unknown, requestId?: string) => boolean
  >(() => true)
  const connection = {
    get state() {
      return state
    },
    get protectedMode() {
      return PROTECTED_MODE_INACTIVE
    },
    get rtStaleness() {
      return RT_STALENESS_FRESH
    },
    get reconnectDragging() {
      return false
    },
    get firstFrameHeld() {
      return false
    },
    sendAction,
    on(event: string, listener: (payload: never) => void): () => void {
      const callbacks = listeners.get(event) ?? new Set()
      callbacks.add(listener)
      listeners.set(event, callbacks)
      return () => {
        callbacks.delete(listener)
      }
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  let releaseTheme: (() => void) | undefined
  let releaseSession: (() => void) | undefined
  let releaseImpersonation: (() => void) | undefined
  vi.useFakeTimers()
  hilosToasts.clear()
  try {
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
    vi.stubGlobal('localStorage', memoryStorage({}))
    const actions = new ActionLifecycle(
      connection as unknown as ActionLifecycleSource,
    )
    bindSessionScope(connection, scopes)
    releaseSession = bindSignOut(scopes, actions)
    releaseImpersonation = bindImpersonation(scopes, actions)
    const snapshot = (next: ThemeSettings, value: ThemePick): void => {
      releaseTheme?.()
      const entries: Record<string, string> = {
        [THEME_SETTINGS_STORAGE_KEY]: JSON.stringify(next),
      }
      if (value !== null) entries[THEME_PICK_STORAGE_KEY] = value
      vi.stubGlobal('localStorage', memoryStorage(entries))
      releaseTheme = bindThemeState(
        { on: () => () => {} } as unknown as HilosConnection,
        new ScopeManager(),
        actions,
      )
    }
    const server: ThemeMenuServer = {
      connection,
      sendAction,
      handshake(value, userId = 1, initialized = value !== null) {
        emit('projectSignal', {
          type: SIGNAL_HANDSHAKE_RESPONSE,
          data: {
            entities: {
              currentUser:
                userId === null
                  ? null
                  : {
                      id: userId,
                      name: 'Ada',
                      themePick: value,
                      themePickInitialized: initialized,
                    },
            },
            data: { themeSettings: settings },
          },
        })
      },
      impersonate: () =>
        scopes.session.data.set('impersonatedBy', { type: 'user', id: 9 }),
      settings: (next) =>
        emit('projectSignal', { type: SIGNAL_THEME_SETTINGS, data: next }),
      state(value) {
        state = value
        emit('state', value)
      },
      reply(outcome, requestId = sendAction.mock.lastCall?.[2]) {
        emit(outcome === 'success' ? 'actionSuccess' : 'actionError', {
          action: 'hilos_theme_pick',
          requestId,
          reason: 'Theme switching is disabled.',
        })
      },
      snapshot,
      refuseStorage() {
        const refused = (): never => {
          throw new Error('refused')
        }
        vi.stubGlobal('localStorage', {
          ...memoryStorage({}),
          getItem: refused,
          setItem: refused,
          removeItem: refused,
        })
      },
    }
    if (input.account) {
      releaseTheme = bindThemeState(connection, scopes, actions)
      server.handshake(pick)
    } else snapshot(settings, pick)
    input.beforeMount?.(server)
    await steps(server)
  } finally {
    try {
      // Settle any abandoned action after the adapter has destroyed its surface.
      emit('state', 'disconnected')
      await Promise.resolve()
      await Promise.resolve()
      releaseTheme?.()
      releaseSession?.()
      releaseImpersonation?.()
      listeners.clear()
      vi.stubGlobal('localStorage', memoryStorage({}))
      const restore = bindThemeState(
        { on: () => () => {} } as unknown as HilosConnection,
        new ScopeManager(),
        new ActionLifecycle({ sendAction: () => true, on: () => () => {} }),
      )
      restore()
      hilosToasts.clear()
    } finally {
      stylesheet.remove()
      document.body.classList.toggle('modal-open', modalOpen)
      if (theme === null)
        document.documentElement.removeAttribute('data-bs-theme')
      else document.documentElement.setAttribute('data-bs-theme', theme)
      vi.restoreAllMocks()
      vi.unstubAllGlobals()
      restoreProperty('localStorage', storage)
      restoreProperty('matchMedia', media)
      vi.clearAllTimers()
      vi.useRealTimers()
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}

/** Query only this mounted surface, including a retained but hidden Vue menu. */
export function byId(root: ParentNode, id: string): HTMLButtonElement {
  const target = root.querySelector<HTMLButtonElement>(`[data-id="${id}"]`)
  if (target === null) throw new Error(`Missing ThemeMenu element: ${id}`)
  return target
}

/** A hidden ancestor also takes a retained menu out of use. */
export function shown(element: HTMLElement | null): boolean {
  if (element === null || !element.isConnected) return false
  for (
    let node: HTMLElement | null = element;
    node;
    node = node.parentElement
  ) {
    const style = getComputedStyle(node)
    if (
      node.hidden ||
      style.display === 'none' ||
      style.visibility === 'hidden'
    )
      return false
  }
  return true
}

/** Native keyboard delivery; the adapter decides when its render is complete. */
export function key(element: Element, name: string): void {
  element.dispatchEvent(
    new KeyboardEvent('keydown', { key: name, bubbles: true }),
  )
}
