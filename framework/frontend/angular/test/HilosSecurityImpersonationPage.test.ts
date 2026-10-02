// The Angular peer of vue/src/admin/security/HilosSecurityImpersonationPage.test.ts
// (HIL-1170): six switches and the scope in words, in the order the server
// lists them. A switch sends the one-setting action, spins on its own row,
// holds the others and moves only with the row; the scope's pencil opens a
// modal on the shared row-edit helper whose Save closes on the answer and
// toasts its sentence, and whose refusal stays inside. In the page's area of a
// takeover that only looks the switches and Save stand disabled by the SDK's
// own controls; in the admin view mode a viewer sees the hidden mark in place
// of the switches, the scope and the modal's choice (HIL-1261).
import { signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
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
import { afterEach, describe, expect, it } from 'vitest'

import { HilosSecurityImpersonationPage } from '../src/admin/security/HilosSecurityImpersonationPage.js'
import { HILOS_TAKEOVER_VIEW_ONLY } from '../src/hilosLookOnly.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
function seededContext(hiddenValues = false): {
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

/** Let a reply reach the tracked driver and the page. */
async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
  fixture.detectChanges()
}

afterEach(async () => {
  for (const drop of drops.splice(0)) {
    drop()
  }
  // The drop's refusal reaches the toasts a tick later; clear them after it.
  await new Promise((resolve) => setTimeout(resolve, 0))
  hilosToasts.clear()
  document.body.classList.remove('modal-open')
})

function el(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

/**
 * One control of a row, looked up through the table: each row's controls stand
 * in the document twice — the table and the narrow-screen card.
 */
function inTable<T extends HTMLElement>(
  fixture: ComponentFixture<unknown>,
  id: string,
): T {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `table [data-id="${id}"]`,
  ) as T
}

function switchOf(
  fixture: ComponentFixture<unknown>,
  rowKey: string,
): HTMLInputElement {
  return inTable<HTMLInputElement>(
    fixture,
    `hilos-impersonation-switch-${rowKey}`,
  )
}

function saveButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return el(fixture, 'hilos-impersonation-scope-save') as HTMLButtonElement
}

function scopeRadio(
  fixture: ComponentFixture<unknown>,
  value: 'view' | 'act',
): HTMLInputElement {
  return el(fixture, `hilos-impersonation-scope-${value}`) as HTMLInputElement
}

/**
 * Mount the page.
 *
 * @param context The seeded context.
 * @param viewOnly Whether the page stands inside a takeover that only looks.
 */
function mountPage(
  context: HilosImpersonationContext,
  viewOnly = false,
): ComponentFixture<HilosSecurityImpersonationPage> {
  TestBed.configureTestingModule({
    providers: [
      { provide: HILOS_ROUTER, useValue: router() },
      {
        provide: HILOS_TAKEOVER_VIEW_ONLY,
        useValue: signal(viewOnly).asReadonly(),
      },
    ],
  })
  const fixture = TestBed.createComponent(HilosSecurityImpersonationPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

/** Mount the page and open the scope's modal. */
function openScope(
  context: HilosImpersonationContext,
  viewOnly = false,
): ComponentFixture<HilosSecurityImpersonationPage> {
  const fixture = mountPage(context, viewOnly)
  inTable(fixture, 'hilos-impersonation-scope-edit').click()
  fixture.detectChanges()

  return fixture
}

function choose(
  fixture: ComponentFixture<unknown>,
  value: 'view' | 'act',
): void {
  scopeRadio(fixture, value).click()
  fixture.detectChanges()
}

describe('HilosSecurityImpersonationPage', () => {
  it('draws six switches and the scope in words, in the order of the rows', () => {
    const { context } = seededContext()
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    expect(
      root.querySelector('[data-id="hilos-impersonation-table"] table'),
    ).not.toBeNull()
    const switches = Array.from(
      root.querySelectorAll<HTMLInputElement>(
        'table [data-id^="hilos-impersonation-switch-"]',
      ),
    )
    expect(switches.map((input) => input.dataset['id'])).toEqual(
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
    expect(
      inTable(fixture, 'hilos-impersonation-scope-value').textContent,
    ).toBe('View and act')
    expect(
      root.querySelectorAll('table [data-id="hilos-impersonation-scope-edit"]'),
    ).toHaveLength(1)
    expect(root.textContent).toContain('Impersonation is allowed')
  })

  it('sends one switch, holds the others while it flies, and moves only with the row', async () => {
    const { context, sent, answer, pushUpdate } = seededContext()
    const fixture = mountPage(context)

    switchOf(fixture, EQUAL).click()
    fixture.detectChanges()

    expect(sent).toEqual([
      {
        action: 'security_impersonation_switch_set',
        payload: { key: EQUAL, enabled: false },
        requestId: expect.any(String),
      },
    ])
    expect(switchOf(fixture, EQUAL).checked).toBe(true)
    expect(switchOf(fixture, EQUAL).getAttribute('aria-busy')).toBe('true')
    expect(switchOf(fixture, ALLOWED).disabled).toBe(true)

    answer('success')
    await settle(fixture)
    expect(switchOf(fixture, EQUAL).checked).toBe(true)
    expect(switchOf(fixture, ALLOWED).disabled).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])

    pushUpdate(EQUAL, 'false')
    fixture.detectChanges()
    expect(switchOf(fixture, EQUAL).checked).toBe(false)
  })

  it('toasts a refused switch and leaves it where the row says', async () => {
    const { context, answer } = seededContext()
    const fixture = mountPage(context)

    switchOf(fixture, ALLOWED).click()
    fixture.detectChanges()
    answer('fail', 'Unknown impersonation setting')
    await settle(fixture)

    expect(switchOf(fixture, ALLOWED).checked).toBe(true)
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Unknown impersonation setting']])
  })
})

describe('HilosSecurityImpersonationPage scope modal', () => {
  it('opens on the value in force with Save locked and the row in focus', () => {
    const { context, focus } = seededContext()
    const fixture = openScope(context)

    expect(scopeRadio(fixture, 'act').checked).toBe(true)
    expect(scopeRadio(fixture, 'view').checked).toBe(false)
    expect(saveButton(fixture).disabled).toBe(true)
    expect(el(fixture, 'modal')?.textContent).toContain(
      'Only look, or act as well. Default: view and act.',
    )
    expect(focus).toEqual([SCOPE])
  })

  it('saves the choice, closes on the answer and toasts its sentence', async () => {
    const { context, sent, answer, focus } = seededContext()
    const fixture = openScope(context)

    choose(fixture, 'view')
    expect(saveButton(fixture).disabled).toBe(false)
    saveButton(fixture).click()
    fixture.detectChanges()

    expect(sent).toEqual([
      {
        action: 'security_impersonation_scope_set',
        payload: { scope: 'view' },
        requestId: expect.any(String),
      },
    ])
    expect(el(fixture, 'modal')).not.toBeNull()

    answer('success', 'Impersonation setting saved.')
    await settle(fixture)

    expect(el(fixture, 'modal')).toBeNull()
    expect(focus).toEqual([SCOPE, ''])
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['success', 'Impersonation setting saved.']])
  })

  it('keeps a refusal inside the open modal', async () => {
    const { context, answer } = seededContext()
    const fixture = openScope(context)
    choose(fixture, 'view')
    saveButton(fixture).click()
    fixture.detectChanges()

    answer('fail', 'The scope must be view or act')
    await settle(fixture)

    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'hilos-action-error')?.textContent).toContain(
      'The scope must be view or act',
    )
    expect(scopeRadio(fixture, 'view').checked).toBe(true)
  })

  it('takes a change made elsewhere into a pristine modal and says so', () => {
    const { context, pushUpdate } = seededContext()
    const fixture = openScope(context)

    pushUpdate(SCOPE, 'view')
    fixture.detectChanges()

    expect(scopeRadio(fixture, 'view').checked).toBe(true)
    expect(
      el(fixture, 'hilos-impersonation-scope-notice')?.textContent,
    ).toContain('Updated just now')
    expect(saveButton(fixture).disabled).toBe(true)
  })
})

describe('HilosSecurityImpersonationPage in a takeover that only looks', () => {
  it('stands the switches and Save disabled, pointing at the impersonation strip, and opens the modal', () => {
    const { context, sent } = seededContext()
    const fixture = openScope(context, true)

    expect(switchOf(fixture, ALLOWED).disabled).toBe(true)
    expect(switchOf(fixture, ALLOWED).getAttribute('aria-describedby')).toBe(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )
    choose(fixture, 'view')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(saveButton(fixture).getAttribute('aria-describedby')).toContain(
      HILOS_IMPERSONATION_STRIP_TEXT_ID,
    )
    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toEqual([])
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

  it('shows hidden marks for switches and the scope, and opens the modal with a hidden mark', () => {
    const { context, sent } = seededContext(true)
    viewAsGuest(context.scopes)
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    expect(
      root.querySelector(
        `input[data-id="hilos-impersonation-switch-${ALLOWED}"]`,
      ),
    ).toBeNull()
    expect(
      root.querySelectorAll('[data-id="hilos-hidden"]').length,
    ).toBeGreaterThan(0)

    inTable(fixture, 'hilos-impersonation-scope-edit').click()
    fixture.detectChanges()

    expect(scopeRadio(fixture, 'view')).toBeNull()
    expect(scopeRadio(fixture, 'act')).toBeNull()
    expect(
      el(fixture, 'modal')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)

    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toEqual([])

    const cancel = Array.from(
      root.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    fixture.detectChanges()

    expect(el(fixture, 'modal')).toBeNull()
  })
})
