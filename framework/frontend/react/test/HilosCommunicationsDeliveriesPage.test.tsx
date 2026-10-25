// The React peer of vue/src/admin/communications/HilosCommunicationsDeliveriesPage.test.ts:
// the retry the pressed row carries while the others wait, and the cases of the
// admin view mode (HIL-1260, HIL-1261): the mark where personal texts are hidden,
// and the retry a viewer finds standing while an admin keeps it.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosDeliveriesContext,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import { HilosCommunicationsDeliveriesPage } from '../src/admin/communications/HilosCommunicationsDeliveriesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const TABLE = 'hilosNotificationDeliveries'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.COMMUNICATIONS_DELIVERIES,
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

/** One failed email delivery, the only kind a retry is offered on. */
const FAILED_DELIVERY = {
  createdAt: 1790157600000,
  channel: 'email',
  status: 'failed',
  attempts: 3,
  deliveredAt: null,
  lastError: 'mailbox unavailable',
  userId: 3,
  userLabel: null,
  notificationType: 'backup.completed',
  notificationTitle: 'Backup is ready',
}

type Listener = (signal: Record<string, unknown>) => void

interface SentAction {
  action: string
  payload: Record<string, unknown>
  requestId?: string
}

/**
 * A deliveries context that serves one delivery as its window, the neighbors
 * after it, records what is sent and answers it when told.
 */
function seededContext(
  delivery: Record<string, unknown> = FAILED_DELIVERY,
  neighbors: Record<string, Record<string, unknown>> = {},
): {
  context: HilosDeliveriesContext
  sent: SentAction[]
  answer: (requestId: string) => void
} {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.COMMUNICATIONS_DELIVERIES)
  const windowListeners = new Set<Listener>()
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.COMMUNICATIONS_DELIVERIES,
      tableKey: TABLE,
      rows: [
        { rowKey: '57', slots: { delivery } },
        ...Object.entries(neighbors).map(([rowKey, neighbor]) => ({
          rowKey,
          slots: { delivery: neighbor },
        })),
      ],
      totalCount: 1 + Object.keys(neighbors).length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 25,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }

  const connection = {
    registerTableWindow(): void {
      serveWindow()
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): boolean {
      serveWindow()

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    on(event: string, listener: Listener): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }

      return () => {}
    },
  }
  const successListeners = new Set<Listener>()
  const sent: SentAction[] = []
  const actions = new ActionLifecycle({
    sendAction: (
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ) => {
      sent.push({ action, payload, requestId })

      return true
    },
    on: (event: string, listener: Listener) => {
      if (event === 'actionSuccess') {
        successListeners.add(listener)

        return () => successListeners.delete(listener)
      }

      return () => {}
    },
  } as unknown as ConstructorParameters<typeof ActionLifecycle>[0])

  return {
    context: {
      connection: connection as unknown as HilosDeliveriesContext['connection'],
      scopes,
      actions,
    },
    sent,
    answer(requestId: string): void {
      for (const listener of successListeners) {
        listener({
          kind: 'actionSuccess',
          action: 'communications_delivery_retry',
          message: undefined,
          reply: undefined,
          requestId,
        })
      }
    },
  }
}

function renderPage(context: HilosDeliveriesContext): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosCommunicationsDeliveriesPage context={context} />
    </HilosRouterContext.Provider>,
  ).container
}

// The row's controls stand in the document twice — once in the table and once
// in the card the same row becomes on a narrow screen — so the button is looked
// up through the table, which says which of the two is clicked.
function retryButton(rowKey = '57'): HTMLButtonElement {
  return document.querySelector(
    `table [data-id="hilos-delivery-retry-${rowKey}"]`,
  ) as HTMLButtonElement
}

// What a Retry tells a sighted person and a screen reader now.
function retryState(rowKey: string) {
  const button = retryButton(rowKey)

  return {
    nativeDisabled: button.disabled,
    spinner:
      button.querySelector('[data-id="loading-button-spinner"]') !== null,
    ariaBusy: button.getAttribute('aria-busy'),
    ariaDisabled: button.getAttribute('aria-disabled'),
  }
}

const RESTING = {
  nativeDisabled: false,
  spinner: false,
  ariaBusy: null,
  ariaDisabled: null,
}
const OFF = { ...RESTING, nativeDisabled: true, ariaDisabled: 'true' }
const LOCKED = { ...RESTING, ariaBusy: 'true', ariaDisabled: 'true' }

// One retry flies at a time: the row it was pressed on carries it — locked at
// once, its spinner at 300 ms — and every other row's Retry is off natively
// from the first moment, without a spinner of its own.
describe('HilosCommunicationsDeliveriesPage retry', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  it('spins only the row it retries and turns the other rows off at once', async () => {
    const { context, sent, answer } = seededContext(FAILED_DELIVERY, {
      '58': FAILED_DELIVERY,
    })
    renderPage(context)
    expect(retryState('57')).toEqual(RESTING)
    expect(retryState('58')).toEqual(RESTING)

    fireEvent.click(retryButton('57'))
    expect(sent).toHaveLength(1)
    expect(sent[0]?.payload).toEqual({ deliveryId: 57 })
    expect(retryState('57')).toEqual(LOCKED)
    expect(retryState('58')).toEqual(OFF)

    act(() => {
      vi.advanceTimersByTime(300)
    })
    expect(retryState('57')).toEqual({ ...LOCKED, spinner: true })
    expect(retryState('58')).toEqual(OFF)
    fireEvent.click(retryButton('57'))
    fireEvent.click(retryButton('58'))
    expect(sent).toHaveLength(1)

    await act(async () => {
      answer(sent[0]?.requestId ?? '')
      for (let tick = 0; tick < 4; tick += 1) await Promise.resolve()
    })
    expect(retryState('57')).toEqual(RESTING)
    expect(retryState('58')).toEqual(RESTING)
  })
})

describe('HilosCommunicationsDeliveriesPage with personal texts hidden (HIL-1260)', () => {
  const HIDDEN = { _hidden: true }

  afterEach(cleanup)

  it('draws the mark beside the recipient id, and in place of the title and the error', () => {
    const { context } = seededContext({
      ...FAILED_DELIVERY,
      userLabel: HIDDEN,
      notificationTitle: HIDDEN,
      lastError: HIDDEN,
    })
    renderPage(context)

    // The only cell of the row the mark stands in is the recipient's: the title and
    // the error live in the row's details, closed until it is expanded.
    const cells = Array.from(
      document.querySelectorAll('table [data-id="hilos-table-row-57"] td'),
    ).filter((cell) => cell.querySelector('[data-id="hilos-hidden"]') !== null)
    expect(cells).toHaveLength(1)
    expect(cells[0]?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'Hidden (#3)',
    )
    fireEvent.click(
      document.querySelector(
        'table [data-id="hilos-table-expand-57"]',
      ) as Element,
    )

    const marks = document.querySelectorAll('table dd [data-id="hilos-hidden"]')
    expect(marks).toHaveLength(2)
    expect(document.querySelector('table dd')?.textContent).not.toContain('—')
  })
})

describe('HilosCommunicationsDeliveriesPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    cleanup()
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   */
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
      /**
       * One handshake: who is behind the session, if anybody, and the node's
       * admin view mode, both as the backend stamps them (HIL-1253).
       *
       * @param user The person behind the session, or null for a guest.
       * @param viewMode The node's admin view mode.
       */
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

  it("a viewer finds a failed delivery's Retry standing in view mode", () => {
    const { context, sent } = seededContext()
    bindSession(context.scopes).handshake(null, true)
    renderPage(context)

    const button = retryButton()
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    fireEvent.click(button)
    expect(sent).toEqual([])
  })

  it('an admin on a node in the mode retries as today', () => {
    const { context, sent } = seededContext()
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    renderPage(context)

    const button = retryButton()
    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
    fireEvent.click(button)
    expect(sent[0]?.action).toBe('communications_delivery_retry')
    expect(sent[0]?.payload).toEqual({ deliveryId: 57 })
  })
})
