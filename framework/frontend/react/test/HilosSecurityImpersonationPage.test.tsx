// The React peer of vue/src/admin/security/HilosSecurityImpersonationPage.test.ts
// (HIL-1170): six switches and the scope in words, in the order the server
// lists them. A switch sends the one-setting action, spins on its own row,
// holds the others and moves only with the row; the scope's pencil opens a
// modal on the shared row-edit helper whose Save closes on the answer and
// toasts its sentence, and whose refusal stays inside. In the page's area of a
// takeover that only looks the switches and Save stand disabled by the SDK's
// own controls.
import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HilosImpersonationSettingKey,
  HilosPages,
  ScopeManager,
  createSignal,
  hilosToasts,
} from '@hilos/core'
import type {
  HilosImpersonationContext,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosSecurityImpersonationPage } from '../src/admin/security/HilosSecurityImpersonationPage.js'
import { HilosTakeoverViewOnlyContext } from '../src/hilosLookOnly.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const TABLE = 'hilosSecurityImpersonation'
const ALLOWED = HilosImpersonationSettingKey.allowed
const SCOPE = HilosImpersonationSettingKey.scope
const EQUAL = HilosImpersonationSettingKey.equal

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_IMPERSONATION,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** The text each setting holds before a test moves it: the catalog defaults. */
const DEFAULTS: ReadonlyArray<[string, string]> = [
  [ALLOWED, 'true'],
  [SCOPE, 'act'],
  [HilosImpersonationSettingKey.accountAccess, 'false'],
  [HilosImpersonationSettingKey.carryAdmin, 'false'],
  [HilosImpersonationSettingKey.blocked, 'true'],
  [HilosImpersonationSettingKey.frozen, 'true'],
  [EQUAL, 'true'],
]

/** A setting row's slot, with the given value. */
function settingSlot(rowKey: string, value: string): Record<string, unknown> {
  return {
    rowKey,
    value,
    defaultValue: DEFAULTS.find(([key]) => key === rowKey)?.[1] ?? '',
  }
}

// Drops every connection a case left an action in flight on, so the lifecycle
// fails the action and stops its deferred-loading timer before the page goes.
const drops: Array<() => void> = []

/**
 * A context whose connection answers a window on the impersonation page with
 * the seven settings, pushes a row update on demand, records every action sent
 * and answers the last one on demand.
 */
function seededContext(): {
  context: HilosImpersonationContext
  pushUpdate: (rowKey: string, value: string) => void
  answer: (outcome: 'success' | 'fail', message?: string) => void
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
  focus: string[]
} {
  let settings = new Map<string, Record<string, unknown>>(
    DEFAULTS.map(([key, value]) => [key, settingSlot(key, value)]),
  )
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_IMPERSONATION)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    const rows =
      tableKey === TABLE
        ? [...settings].map(([rowKey, setting]) => ({
            rowKey,
            slots: { setting },
          }))
        : []
    const data = {
      page: HilosPages.SECURITY_IMPERSONATION,
      tableKey,
      rows,
      totalCount: rows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }
  const connection = {
    registerTableWindow(tableKey: string): void {
      serveWindow(tableKey)
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(_page: string, tableKey: string): boolean {
      serveWindow(tableKey)

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableRowFocus(
      _page: string,
      _tableKey: string,
      rowKey: string,
    ): boolean {
      focus.push(rowKey)

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }
      if (event === 'tableViewportDelta') {
        deltaListeners.add(listener)

        return () => deltaListeners.delete(listener)
      }

      return () => {}
    },
  }
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const replyListeners = new Map<
    string,
    Set<(signal: Record<string, unknown>) => void>
  >()
  const actions = new ActionLifecycle({
    sendAction: (
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ) => {
      sent.push({ action, payload, requestId })

      return true
    },
    on: (
      event: string,
      listener: (signal: Record<string, unknown>) => void,
    ) => {
      if (event === 'state') {
        drops.push(() =>
          (listener as unknown as (state: string) => void)('disconnected'),
        )
      }
      const listeners = replyListeners.get(event) ?? new Set()
      listeners.add(listener)
      replyListeners.set(event, listeners)

      return () => listeners.delete(listener)
    },
  } as unknown as ConstructorParameters<typeof ActionLifecycle>[0])

  return {
    context: {
      connection:
        connection as unknown as HilosImpersonationContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(rowKey: string, value: string): void {
      const setting = settingSlot(rowKey, value)
      settings = new Map(settings).set(rowKey, setting)
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SECURITY_IMPERSONATION,
            tableKey: TABLE,
            kind: 'row_updated',
            rowKey,
            row: { rowKey, slots: { setting } },
          },
        })
      }
    },
    answer(outcome: 'success' | 'fail', message?: string): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          ...(outcome === 'success'
            ? message === undefined
              ? {}
              : { message }
            : { reason: message ?? 'The setting refused the write.' }),
        })
      }
    },
    sent,
    focus,
  }
}

afterEach(async () => {
  // The drop's refusal reaches the toasts a tick later; clear them after it.
  await act(async () => {
    for (const drop of drops.splice(0)) {
      drop()
    }
    await new Promise((resolve) => setTimeout(resolve, 0))
  })
  cleanup()
  document.body.classList.remove('modal-open')
  hilosToasts.clear()
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/**
 * One control of a row, looked up through the table: each row's controls stand
 * in the document twice — the table and the narrow-screen card.
 */
function inTable<T extends HTMLElement>(id: string): T {
  return document.querySelector(`table [data-id="${id}"]`) as T
}

function switchOf(rowKey: string): HTMLInputElement {
  return inTable<HTMLInputElement>(`hilos-impersonation-switch-${rowKey}`)
}

function saveButton(): HTMLButtonElement {
  return byId('hilos-impersonation-scope-save') as HTMLButtonElement
}

function scopeRadio(value: 'view' | 'act'): HTMLInputElement {
  return byId(`hilos-impersonation-scope-${value}`) as HTMLInputElement
}

/**
 * Mount the page.
 *
 * @param context The seeded context.
 * @param viewOnly Whether the page stands inside a takeover that only looks.
 */
function mountPage(context: HilosImpersonationContext, viewOnly = false): void {
  render(
    <HilosRouterContext.Provider value={router()}>
      <HilosTakeoverViewOnlyContext.Provider value={viewOnly}>
        <HilosSecurityImpersonationPage context={context} />
      </HilosTakeoverViewOnlyContext.Provider>
    </HilosRouterContext.Provider>,
  )
}

/** Mount the page and open the scope's modal. */
function openScope(context: HilosImpersonationContext, viewOnly = false): void {
  mountPage(context, viewOnly)
  fireEvent.click(inTable('hilos-impersonation-scope-edit'))
}

/** Let a reply reach the tracked driver and the page. */
async function settle(): Promise<void> {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0))
  })
}

describe('HilosSecurityImpersonationPage', () => {
  it('draws six switches and the scope in words, in the order of the rows', () => {
    const { context } = seededContext()
    mountPage(context)

    expect(
      document.querySelector('[data-id="hilos-impersonation-table"] table'),
    ).not.toBeNull()
    const switches = Array.from(
      document.querySelectorAll<HTMLInputElement>(
        'table [data-id^="hilos-impersonation-switch-"]',
      ),
    )
    expect(switches.map((input) => input.dataset.id)).toEqual(
      DEFAULTS.filter(([key]) => key !== SCOPE).map(
        ([key]) => `hilos-impersonation-switch-${key}`,
      ),
    )
    expect(switches.map((input) => input.checked)).toEqual([
      true,
      false,
      false,
      true,
      true,
      true,
    ])
    expect(inTable('hilos-impersonation-scope-value').textContent).toBe(
      'View and act',
    )
    expect(
      document.querySelectorAll(
        'table [data-id="hilos-impersonation-scope-edit"]',
      ),
    ).toHaveLength(1)
    expect(document.body.textContent).toContain('Impersonation is allowed')
  })

  it('sends one switch, holds the others while it flies, and moves only with the row', async () => {
    const { context, sent, answer, pushUpdate } = seededContext()
    mountPage(context)

    fireEvent.click(switchOf(EQUAL))

    expect(sent).toEqual([
      {
        action: 'security_impersonation_switch_set',
        payload: { key: EQUAL, enabled: false },
        requestId: expect.any(String),
      },
    ])
    expect(switchOf(EQUAL).getAttribute('aria-busy')).toBe('true')
    expect(switchOf(ALLOWED).disabled).toBe(true)

    act(() => answer('success'))
    await settle()
    expect(switchOf(EQUAL).checked).toBe(true)
    expect(switchOf(ALLOWED).disabled).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])

    act(() => pushUpdate(EQUAL, 'false'))
    expect(switchOf(EQUAL).checked).toBe(false)
  })

  it('toasts a refused switch and leaves it where the row says', async () => {
    const { context, answer } = seededContext()
    mountPage(context)

    fireEvent.click(switchOf(ALLOWED))
    act(() => answer('fail', 'Unknown impersonation setting'))
    await settle()

    expect(switchOf(ALLOWED).checked).toBe(true)
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Unknown impersonation setting']])
  })
})

describe('HilosSecurityImpersonationPage scope modal', () => {
  it('opens on the value in force with Save locked and the row in focus', () => {
    const { context, focus } = seededContext()
    openScope(context)

    expect(scopeRadio('act').checked).toBe(true)
    expect(scopeRadio('view').checked).toBe(false)
    expect(saveButton().disabled).toBe(true)
    expect(byId('modal')?.textContent).toContain(
      'Only look, or act as well. Default: view and act.',
    )
    expect(focus).toEqual([SCOPE])
  })

  it('saves the choice, closes on the answer and toasts its sentence', async () => {
    const { context, sent, answer, focus } = seededContext()
    openScope(context)

    fireEvent.click(scopeRadio('view'))
    expect(saveButton().disabled).toBe(false)
    fireEvent.click(saveButton())

    expect(sent).toEqual([
      {
        action: 'security_impersonation_scope_set',
        payload: { scope: 'view' },
        requestId: expect.any(String),
      },
    ])
    expect(byId('modal')).not.toBeNull()

    act(() => answer('success', 'Impersonation setting saved.'))
    await settle()

    expect(byId('modal')).toBeNull()
    expect(focus).toEqual([SCOPE, ''])
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['success', 'Impersonation setting saved.']])
  })

  it('keeps a refusal inside the open modal', async () => {
    const { context, answer } = seededContext()
    openScope(context)
    fireEvent.click(scopeRadio('view'))
    fireEvent.click(saveButton())

    act(() => answer('fail', 'The scope must be view or act'))
    await settle()

    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-action-error')?.textContent).toContain(
      'The scope must be view or act',
    )
    expect(scopeRadio('view').checked).toBe(true)
  })

  it('takes a change made elsewhere into a pristine modal and says so', () => {
    const { context, pushUpdate } = seededContext()
    openScope(context)

    act(() => pushUpdate(SCOPE, 'view'))

    expect(scopeRadio('view').checked).toBe(true)
    expect(byId('hilos-impersonation-scope-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)
  })
})

describe('HilosSecurityImpersonationPage in a takeover that only looks', () => {
  it('stands the switches and Save disabled, pointing at the impersonation strip, and opens the modal', () => {
    const { context, sent } = seededContext()
    openScope(context, true)

    expect(switchOf(ALLOWED).disabled).toBe(true)
    expect(switchOf(ALLOWED).getAttribute('aria-describedby')).toBe(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )
    fireEvent.click(scopeRadio('view'))
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().getAttribute('aria-describedby')).toContain(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )
    fireEvent.click(saveButton())
    expect(sent).toEqual([])
  })
})
