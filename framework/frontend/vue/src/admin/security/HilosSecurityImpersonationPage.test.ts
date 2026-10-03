// The impersonation settings page (HIL-1170): six switches and the scope in
// words, in the order the server lists them. A switch sends the one-setting
// action, spins on its own row, holds the others and moves only with the row;
// the scope's pencil opens a modal on the shared row-edit helper whose Save
// closes on the answer and toasts its sentence, and whose refusal stays inside.
// In the admin view mode the switches and Save stand disabled by the SDK's own
// controls (HIL-1261).
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HilosImpersonationSettingKey,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosToasts,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosImpersonationContext,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import HilosSecurityImpersonationPage from './HilosSecurityImpersonationPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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

/**
 * A context whose connection answers a window on the impersonation page with
 * the seven settings, pushes a row update on demand, records every action sent
 * and answers the last one on demand.
 */
function seededContext(hiddenValues = false): {
  context: HilosImpersonationContext
  pushUpdate: (rowKey: string, value: string) => void
  answer: (outcome: 'success' | 'fail', message?: string) => void
  sent: Array<{ action: string; payload: Record<string, unknown> }>
  focus: string[]
} {
  let settings = new Map<string, Record<string, unknown>>(
    DEFAULTS.map(([key, value]) => [
      key,
      hiddenValues
        ? {
            rowKey: key,
            value: { _hidden: true },
            defaultValue: { _hidden: true },
          }
        : settingSlot(key, value),
    ]),
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

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  document.body.classList.remove('modal-open')
  hilosToasts.clear()
})

function el(id: string): HTMLElement | null {
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
  return el('hilos-impersonation-scope-save') as HTMLButtonElement
}

function scopeRadio(value: 'view' | 'act'): HTMLInputElement {
  return el(`hilos-impersonation-scope-${value}`) as HTMLInputElement
}

/** Let a reply or a live frame reach the page. */
async function settle(): Promise<void> {
  for (let tick = 0; tick < 4; tick += 1) {
    await nextTick()
  }
}

async function mountPage(context: HilosImpersonationContext): Promise<void> {
  mounted.push(
    mount(HilosSecurityImpersonationPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    }),
  )
  await nextTick()
}

/** Mount the page and open the scope's modal. */
async function openScope(context: HilosImpersonationContext): Promise<void> {
  await mountPage(context)
  inTable('hilos-impersonation-scope-edit').click()
  await nextTick()
}

async function choose(value: 'view' | 'act'): Promise<void> {
  const radio = scopeRadio(value)
  radio.checked = true
  radio.dispatchEvent(new Event('change', { bubbles: true }))
  await nextTick()
}

describe('HilosSecurityImpersonationPage', () => {
  it('draws six switches and the scope in words, in the order of the rows', async () => {
    const { context } = seededContext()
    await mountPage(context)

    const table = document.querySelector(
      'table[data-id="hilos-impersonation-table"], [data-id="hilos-impersonation-table"] table',
    )
    expect(table).not.toBeNull()
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
    await mountPage(context)

    switchOf(EQUAL).click()
    await nextTick()

    expect(sent).toEqual([
      {
        action: 'security_impersonation_switch_set',
        payload: { key: EQUAL, enabled: false },
        requestId: expect.any(String),
      },
    ])
    expect(switchOf(EQUAL).checked).toBe(true)
    expect(switchOf(EQUAL).getAttribute('aria-busy')).toBe('true')
    expect(switchOf(ALLOWED).disabled).toBe(true)

    answer('success')
    await settle()
    expect(switchOf(EQUAL).checked).toBe(true)
    expect(switchOf(ALLOWED).disabled).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])

    pushUpdate(EQUAL, 'false')
    await settle()
    expect(switchOf(EQUAL).checked).toBe(false)
  })

  it('toasts a refused switch and leaves it where the row says', async () => {
    const { context, answer } = seededContext()
    await mountPage(context)

    switchOf(ALLOWED).click()
    await nextTick()
    answer('fail', 'Unknown impersonation setting')
    await settle()

    expect(switchOf(ALLOWED).checked).toBe(true)
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Unknown impersonation setting']])
  })
})

describe('HilosSecurityImpersonationPage scope modal', () => {
  it('opens on the value in force with Save locked and the row in focus', async () => {
    const { context, focus } = seededContext()
    await openScope(context)

    expect(scopeRadio('act').checked).toBe(true)
    expect(scopeRadio('view').checked).toBe(false)
    expect(saveButton().disabled).toBe(true)
    expect(el('modal')?.textContent).toContain(
      'Only look, or act as well. Default: view and act.',
    )
    expect(focus).toEqual([SCOPE])
  })

  it('saves the choice, closes on the answer and toasts its sentence', async () => {
    const { context, sent, answer, focus } = seededContext()
    await openScope(context)

    await choose('view')
    expect(saveButton().disabled).toBe(false)
    saveButton().click()
    await nextTick()

    expect(sent).toEqual([
      {
        action: 'security_impersonation_scope_set',
        payload: { scope: 'view' },
        requestId: expect.any(String),
      },
    ])
    expect(el('modal')).not.toBeNull()

    answer('success', 'Impersonation setting saved.')
    await settle()

    expect(el('modal')).toBeNull()
    expect(focus).toEqual([SCOPE, ''])
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['success', 'Impersonation setting saved.']])
  })

  it('keeps a refusal inside the open modal', async () => {
    const { context, answer } = seededContext()
    await openScope(context)
    await choose('view')
    saveButton().click()
    await nextTick()

    answer('fail', 'The scope must be view or act')
    await settle()

    expect(el('modal')).not.toBeNull()
    expect(el('hilos-action-error')?.textContent).toContain(
      'The scope must be view or act',
    )
    expect(scopeRadio('view').checked).toBe(true)
  })

  it('takes a change made elsewhere into a pristine modal and says so', async () => {
    const { context, pushUpdate } = seededContext()
    await openScope(context)

    pushUpdate(SCOPE, 'view')
    await settle()

    expect(scopeRadio('view').checked).toBe(true)
    expect(el('hilos-impersonation-scope-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)
  })
})

describe('HilosSecurityImpersonationPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /** Put a guest on a node in the view mode behind the page's scopes. */
  function viewAsGuest(scopes: ScopeManager): void {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))
    const signal = {
      kind: 'project',
      type: 'handshake_response',
      data: {
        entities: { currentUser: null },
        data: { adminViewMode: true },
      },
      envelope: {},
    } as unknown as ProjectSignal
    for (const listener of listeners) {
      listener(signal)
    }
  }

  it('shows hidden marks for switches and the scope, and opens the modal with a hidden mark', async () => {
    const { context, sent } = seededContext(true)
    viewAsGuest(context.scopes)
    await mountPage(context)

    expect(
      document.querySelector(
        `input[data-id="hilos-impersonation-switch-${ALLOWED}"]`,
      ),
    ).toBeNull()
    expect(
      document.querySelectorAll('[data-id="hilos-hidden"]').length,
    ).toBeGreaterThan(0)

    await openScope(context)

    expect(scopeRadio('view')).toBeNull()
    expect(scopeRadio('act')).toBeNull()
    expect(
      el('modal')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(saveButton().disabled).toBe(true)

    saveButton().click()
    await nextTick()
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    await nextTick()

    expect(el('modal')).toBeNull()
  })
})
