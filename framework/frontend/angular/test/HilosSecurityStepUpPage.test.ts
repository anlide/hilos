// The Angular peer of vue/src/admin/security/HilosSecurityStepUpPage.test.ts:
// the page of operations that ask for confirmation (HIL-1204) opens the
// hilosSecurityStepUp window on its own page rather than on two-factor, its
// switch sends the one-operation action, and the table carries no title of its
// own — the page heading names it. In the admin view mode a viewer sees the
// hidden mark in place of a switch, and an admin on the node keeps the switch.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosRouter,
  HilosTwoFactorContext,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import { afterEach, describe, expect, it } from 'vitest'

import { HilosSecurityStepUpPage } from '../src/admin/security/HilosSecurityStepUpPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const STEP_UP_TABLE = 'hilosSecurityStepUp'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_STEP_UP,
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

/**
 * A context whose connection answers a window on the step-up page with one
 * operation, and records every action sent.
 */
function seededContext(enabledValue: unknown = false): {
  context: HilosTwoFactorContext
  sent: Array<{ action: string; payload: Record<string, unknown> }>
} {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_STEP_UP)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    const rows =
      tableKey === STEP_UP_TABLE
        ? [
            {
              rowKey: 'change_name',
              slots: {
                operation: {
                  operationKey: 'change_name',
                  label: 'Change name',
                  owner: 'framework',
                  enabled: enabledValue,
                },
              },
            },
          ]
        : []
    const data = {
      page: HilosPages.SECURITY_STEP_UP,
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
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }

      return () => {}
    },
  }
  const sent: Array<{ action: string; payload: Record<string, unknown> }> = []
  const actions = new ActionLifecycle({
    sendAction: (action: string, payload: Record<string, unknown>) => {
      sent.push({ action, payload })

      return true
    },
    on: () => () => {},
  })

  return {
    context: {
      connection: connection as unknown as HilosTwoFactorContext['connection'],
      scopes,
      actions,
    },
    sent,
  }
}

function mountPage(
  context: HilosTwoFactorContext,
): ComponentFixture<HilosSecurityStepUpPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosSecurityStepUpPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

function query(
  fixture: ComponentFixture<unknown>,
  selector: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(selector)
}

describe('HilosSecurityStepUpPage', () => {
  it('draws the operations of the window it opened on its own page', () => {
    const { context } = seededContext()
    const fixture = mountPage(context)

    expect(
      query(fixture, 'table [data-id="hilos-step-up-row-change_name"]')
        ?.textContent,
    ).toBe('Change name')
  })

  it('sends the one-operation switch with its next state', () => {
    const { context, sent } = seededContext()
    const fixture = mountPage(context)

    query(
      fixture,
      'table input[data-id="hilos-step-up-switch-change_name"]',
    )?.click()
    fixture.detectChanges()

    expect(sent).toEqual([
      {
        action: 'security_step_up_operation_set',
        payload: expect.objectContaining({
          operationKey: 'change_name',
          enabled: true,
        }),
      },
    ])
  })

  it('leaves the table untitled: the page heading names it', () => {
    const { context } = seededContext()
    const fixture = mountPage(context)

    expect(query(fixture, '[data-id="hilos-table-title"]')).toBeNull()
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain(
      'Asked right before the operation',
    )
  })
})

describe('HilosSecurityStepUpPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  function bindSession(scopes: ScopeManager) {
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

    return {
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  it('a viewer sees hidden marks instead of switches and sends nothing', () => {
    const { context, sent } = seededContext({ _hidden: true })
    bindSession(context.scopes).handshake(null, true)
    const fixture = mountPage(context)

    expect(
      query(fixture, 'table input[data-id="hilos-step-up-switch-change_name"]'),
    ).toBeNull()
    expect(query(fixture, '[data-id="hilos-hidden"]')).not.toBeNull()
    expect(sent).toEqual([])
  })

  it('an admin on a node in the mode dispatches an operation switch', () => {
    const { context, sent } = seededContext()
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    const fixture = mountPage(context)

    const switchEl = query(
      fixture,
      'table input[data-id="hilos-step-up-switch-change_name"]',
    ) as HTMLInputElement
    expect(switchEl.disabled).toBe(false)
    expect(switchEl.getAttribute('aria-describedby')).toBeNull()

    switchEl.click()
    fixture.detectChanges()
    expect(sent).toEqual([
      {
        action: 'security_step_up_operation_set',
        payload: expect.objectContaining({
          operationKey: 'change_name',
          enabled: true,
        }),
      },
    ])
  })
})
