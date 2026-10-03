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
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  Hideable,
  HilosConnection,
  HilosRouter,
  HilosTwoFactorContext,
  PageRouteMatch,
  ProjectSignal,
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
    onLeave: () => () => {},
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
function seededContext(enabled: Hideable<boolean> = false): {
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
                  enabled,
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
    const { context, sent } = seededContext({
      _hidden: true,
    } as Hideable<boolean>)
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    expect(
      document.querySelector(
        'table input[data-id="hilos-step-up-switch-change_name"]',
      ),
    ).toBeNull()
    expect(document.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(sent).toEqual([])
  })

  it('an admin on a node in the mode dispatches an operation switch', () => {
    const { context, sent } = seededContext()
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    mountPage(context)

    const switchEl = document.querySelector<HTMLInputElement>(
      'table input[data-id="hilos-step-up-switch-change_name"]',
    )
    expect(switchEl?.disabled).toBe(false)
    expect(switchEl?.getAttribute('aria-describedby')).toBeNull()

    fireEvent.click(switchEl as Element)
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
