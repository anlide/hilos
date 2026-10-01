// The React peer of vue/src/admin/security/HilosSecurityStepUpPage.test.ts:
// the page of operations that ask for confirmation (HIL-1204) opens the
// hilosSecurityStepUp window on its own page rather than on two-factor, its
// switch sends the one-operation action, and the table carries no title of its
// own — the page heading names it.
import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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

import { HilosSecurityStepUpPage } from '../src/admin/security/HilosSecurityStepUpPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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

// Drops every connection a case left an action in flight on, so the lifecycle
// fails the action and stops its deferred-loading timer before the page goes.
const drops: Array<() => void> = []

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
  const source = {
    sendAction: (action: string, payload: Record<string, unknown>) => {
      sent.push({ action, payload })

      return true
    },
    on: (event: string, listener: (state: string) => void) => {
      if (event === 'state') {
        drops.push(() => listener('disconnected'))
      }

      return () => {}
    },
  }
  const actions = new ActionLifecycle(
    source as unknown as ConstructorParameters<typeof ActionLifecycle>[0],
  )

  return {
    context: {
      connection: connection as unknown as HilosTwoFactorContext['connection'],
      scopes,
      actions,
    },
    sent,
  }
}

afterEach(() => {
  act(() => {
    for (const drop of drops.splice(0)) {
      drop()
    }
  })
  cleanup()
})

function mountPage(context: HilosTwoFactorContext): void {
  render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSecurityStepUpPage context={context} />
    </HilosRouterContext.Provider>,
  )
}

describe('HilosSecurityStepUpPage', () => {
  it('draws the operations of the window it opened on its own page', () => {
    const { context } = seededContext()
    mountPage(context)

    expect(
      document.querySelector('table [data-id="hilos-step-up-row-change_name"]')
        ?.textContent,
    ).toBe('Change name')
  })

  it('sends the one-operation switch with its next state', () => {
    const { context, sent } = seededContext()
    mountPage(context)

    fireEvent.click(
      document.querySelector(
        'table input[data-id="hilos-step-up-switch-change_name"]',
      ) as HTMLElement,
    )

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
    mountPage(context)

    expect(document.querySelector('[data-id="hilos-table-title"]')).toBeNull()
    expect(document.body.textContent).not.toContain(
      'Asked right before the operation',
    )
  })
})
