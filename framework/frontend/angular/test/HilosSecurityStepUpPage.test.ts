// The Angular peer of vue/src/admin/security/HilosSecurityStepUpPage.test.ts:
// the page of operations that ask for confirmation (HIL-1204) opens the
// hilosSecurityStepUp window on its own page rather than on two-factor, its
// switch sends the one-operation action, and the table carries no title of its
// own — the page heading names it.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import type {
  HilosRouter,
  HilosTwoFactorContext,
  PageRouteMatch,
} from '@hilos/core'

import { describe, expect, it } from 'vitest'

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
function seededContext(): {
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
                  enabled: false,
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
