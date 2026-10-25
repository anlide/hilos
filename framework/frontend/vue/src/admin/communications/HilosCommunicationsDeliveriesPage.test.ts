// The delivery journal's retry (HIL-1049): the row action sends the retry with
// the delivery's id and, once the backend answers, leaves the window alone — the
// re-queued row arrives live, so nothing asks the server for the window again.
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  applyServerTime,
  bindAdminAccess,
  bindSessionScope,
  bindTimeZoneState,
  createSignal,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  hilosTimeZone,
  ScopeManager,
} from '@hilos/core/base'
import type {
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core/base'
import type { HilosDeliveriesContext } from '@hilos/core/admin/communications'

import HilosCommunicationsDeliveriesPage from './HilosCommunicationsDeliveriesPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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

function seededContext(
  deliveryOrRows:
    | Record<string, unknown>
    | Array<{
        rowKey: string
        slots: { delivery: Record<string, unknown> }
      }> = FAILED_DELIVERY,
): {
  context: HilosDeliveriesContext
  viewportRequests: () => number
  answer: (requestId: string) => void
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
  connection: HilosConnection
  setOwnZone: (timeZone: string | null) => void
} {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.COMMUNICATIONS_DELIVERIES)
  const listeners = new Map<string, Set<Listener>>()
  let viewportCalls = 0
  const rows = Array.isArray(deliveryOrRows)
    ? deliveryOrRows
    : [{ rowKey: '57', slots: { delivery: deliveryOrRows } }]
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.COMMUNICATIONS_DELIVERIES,
      tableKey: TABLE,
      rows,
      totalCount: rows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 25,
    }
    for (const listener of listeners.get('tableWindow') ?? []) {
      listener({ data })
    }
  }

  const connection = {
    projectTimeZone: 'UTC',
    registerTableWindow(): void {
      serveWindow()
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): boolean {
      viewportCalls += 1
      serveWindow()

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    on(event: string, listener: Listener): () => void {
      const group = listeners.get(event) ?? new Set()
      group.add(listener)
      listeners.set(event, group)

      return () => group.delete(listener)
    },
  } as unknown as HilosConnection

  const setOwnZone = (timeZone: string | null): void => {
    for (const listener of listeners.get('projectSignal') ?? []) {
      listener({
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      })
    }
  }

  const successListeners = new Set<Listener>()
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
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
    viewportRequests: () => viewportCalls,
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
    sent,
    connection,
    setOwnZone,
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  applyServerTime(Date.now())
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
})

async function mountPage(context: HilosDeliveriesContext) {
  const wrapper = mount(HilosCommunicationsDeliveriesPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

// The row's controls stand in the document twice — once in the table and once
// in the card the same row becomes on a narrow screen — so the button is looked
// up through the table, which says which of the two is clicked.
function retryButton(): HTMLElement {
  return document.querySelector(
    'table [data-id="hilos-delivery-retry-57"]',
  ) as HTMLElement
}

describe('HilosCommunicationsDeliveriesPage retry', () => {
  it('sends the retry and does not ask for the window again once it is answered', async () => {
    const { context, viewportRequests, answer, sent } = seededContext()
    await mountPage(context)
    const requestsBeforeRetry = viewportRequests()

    retryButton().click()
    await nextTick()

    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('communications_delivery_retry')
    expect(sent[0]?.payload).toEqual({ deliveryId: 57 })
    expect(retryButton().getAttribute('aria-disabled')).toBe('true')

    answer(sent[0]?.requestId ?? '')
    await nextTick()
    await nextTick()

    // The button is free again, so the answer was taken - and it asked for nothing.
    expect((retryButton() as HTMLButtonElement).disabled).toBe(false)
    expect(viewportRequests()).toBe(requestsBeforeRetry)
  })
})

describe('HilosCommunicationsDeliveriesPage with personal texts hidden (HIL-1260)', () => {
  const HIDDEN = { _hidden: true }

  it('draws the mark beside the recipient id, and in place of the title and the error', async () => {
    const { context } = seededContext({
      ...FAILED_DELIVERY,
      userLabel: HIDDEN,
      notificationTitle: HIDDEN,
      lastError: HIDDEN,
    })
    await mountPage(context)

    // The only cell of the row the mark stands in is the recipient's: the title and
    // the error live in the row's details, closed until it is expanded.
    const cells = Array.from(
      document.querySelectorAll('table [data-id="hilos-table-row-57"] td'),
    ).filter((cell) => cell.querySelector('[data-id="hilos-hidden"]') !== null)
    expect(cells).toHaveLength(1)
    expect(cells[0]?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'Hidden (#3)',
    )
    ;(
      document.querySelector(
        'table [data-id="hilos-table-expand-57"]',
      ) as HTMLElement
    ).click()
    await nextTick()

    const marks = document.querySelectorAll('table dd [data-id="hilos-hidden"]')
    expect(marks).toHaveLength(2)
    expect(document.querySelector('table dd')?.textContent).not.toContain('—')
  })
})

describe('HilosCommunicationsDeliveriesPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   */
  function bindSession() {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
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

  it("a viewer finds a failed delivery's Retry standing in view mode", async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, sent } = seededContext()
    await mountPage(context)

    const button = retryButton() as HTMLButtonElement
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    button.click()
    await nextTick()
    expect(sent).toEqual([])
  })

  it('an admin on a node in the mode retries as today', async () => {
    const { handshake } = bindSession()
    handshake({ id: 1, admin: true }, true)
    const { context, sent } = seededContext()
    await mountPage(context)

    const button = retryButton() as HTMLButtonElement
    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
    button.click()
    await nextTick()
    expect(sent[0]?.action).toBe('communications_delivery_retry')
    expect(sent[0]?.payload).toEqual({ deliveryId: 57 })
  })
})

describe('HilosCommunicationsDeliveriesPage time zone reactivity', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) {
      release()
    }
  })

  it('renders delivery dates in UTC and Pacific/Honolulu across the day boundary, updates without server frames, handles null/0, ignores clock offset, and cleans up on unmount', async () => {
    // Known moment: 2026-10-24 03:30:00 UTC (in Honolulu UTC-10 this is 2026-10-23 17:30:00, crossing the day boundary)
    const at = Date.parse('2026-10-24T03:30:00Z')
    const deliveredAt = at + 15_000

    const rows = [
      {
        rowKey: '57',
        slots: {
          delivery: {
            ...FAILED_DELIVERY,
            createdAt: at,
            deliveredAt,
          },
        },
      },
      {
        rowKey: '58',
        slots: {
          delivery: {
            ...FAILED_DELIVERY,
            createdAt: 0,
            deliveredAt: null,
          },
        },
      },
    ]

    const { context, viewportRequests, connection, setOwnZone } =
      seededContext(rows)
    const releaseTime = bindTimeZoneState(connection)
    releases.push(releaseTime)

    // 1. Initial render in UTC
    setOwnZone('UTC')
    const wrapper = await mountPage(context)
    expect(hilosTimeZone.get()).toBe('UTC')

    const formatIndependent = (moment: number, timeZone: string): string =>
      new Intl.DateTimeFormat(undefined, {
        timeZone,
        dateStyle: 'medium',
        timeStyle: 'medium',
      }).format(moment)

    const expectedUtcCreatedAt = formatIndependent(at, 'UTC')
    const expectedUtcDeliveredAt = formatIndependent(deliveredAt, 'UTC')
    const expectedUtcEpochZero = formatIndependent(0, 'UTC')

    const row57 = document.querySelector(
      'table [data-id="hilos-table-row-57"]',
    )!
    const row58 = document.querySelector(
      'table [data-id="hilos-table-row-58"]',
    )!

    const cells57 = row57.querySelectorAll('td')
    const cells58 = row58.querySelectorAll('td')

    expect(cells57[0]?.textContent?.trim()).toBe(expectedUtcCreatedAt)
    expect(cells57[4]?.textContent?.trim()).toBe(expectedUtcDeliveredAt)
    expect(cells58[0]?.textContent?.trim()).toBe(expectedUtcEpochZero)
    expect(cells58[4]?.textContent?.trim()).toBe('—')

    const requestsBeforeZoneChange = viewportRequests()

    // 2. Change time zone on already mounted component to Pacific/Honolulu
    setOwnZone('Pacific/Honolulu')
    await nextTick()
    expect(hilosTimeZone.get()).toBe('Pacific/Honolulu')

    // No new server viewport request was made!
    expect(viewportRequests()).toBe(requestsBeforeZoneChange)

    const expectedHonoluluCreatedAt = formatIndependent(at, 'Pacific/Honolulu')
    const expectedHonoluluDeliveredAt = formatIndependent(
      deliveredAt,
      'Pacific/Honolulu',
    )
    const expectedHonoluluEpochZero = formatIndependent(0, 'Pacific/Honolulu')

    expect(cells57[0]?.textContent?.trim()).toBe(expectedHonoluluCreatedAt)
    expect(cells57[4]?.textContent?.trim()).toBe(expectedHonoluluDeliveredAt)
    expect(cells58[0]?.textContent?.trim()).toBe(expectedHonoluluEpochZero)
    expect(cells58[4]?.textContent?.trim()).toBe('—')

    // Confirm day boundary was crossed (UTC date is Oct 24, Honolulu date is Oct 23)
    expect(expectedHonoluluCreatedAt).not.toBe(expectedUtcCreatedAt)

    // 3. Clock offset does not alter displayed timestamp
    applyServerTime(Date.now() + 5 * 3_600_000)
    await nextTick()

    expect(cells57[0]?.textContent?.trim()).toBe(expectedHonoluluCreatedAt)
    expect(cells57[4]?.textContent?.trim()).toBe(expectedHonoluluDeliveredAt)

    // 4. Cleanup on unmount
    wrapper.unmount()
  })
})
