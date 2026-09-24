// The delivery journal's retry (HIL-1049): the row action sends the retry with
// the delivery's id and, once the backend answers, leaves the window alone — the
// re-queued row arrives live, so nothing asks the server for the window again.
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import type {
  HilosDeliveriesContext,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

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
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** One failed email delivery, the only kind a retry is offered on. */
const FAILED_DELIVERY = {
  createdAt: '2026-09-23 10:00:00',
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

function seededContext(): {
  context: HilosDeliveriesContext
  viewportRequests: () => number
  answer: (requestId: string) => void
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
} {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.COMMUNICATIONS_DELIVERIES)
  const windowListeners = new Set<Listener>()
  let viewportCalls = 0
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.COMMUNICATIONS_DELIVERIES,
      tableKey: TABLE,
      rows: [{ rowKey: '57', slots: { delivery: FAILED_DELIVERY } }],
      totalCount: 1,
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
      viewportCalls += 1
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
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
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
    expect((retryButton() as HTMLButtonElement).disabled).toBe(true)

    answer(sent[0]?.requestId ?? '')
    await nextTick()
    await nextTick()

    // The button is free again, so the answer was taken - and it asked for nothing.
    expect((retryButton() as HTMLButtonElement).disabled).toBe(false)
    expect(viewportRequests()).toBe(requestsBeforeRetry)
  })
})
