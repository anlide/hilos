import { act, cleanup, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, vi } from 'vitest'
import {
  ActionLifecycle,
  bindAdminAccess,
  createSignal,
  hilosToasts,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type HilosPageKey,
  type PageRouteMatch,
} from '@hilos/core/base'
import type {
  HilosChannelSummary,
  HilosCommunicationsContext,
} from '@hilos/core/admin/communications'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

export const channelSummary: HilosChannelSummary = {
  channel: 'email',
  label: 'Email',
  enabled: true,
  configured: true,
  driver: 'smtp',
  missingFields: 0,
  fieldCount: 6,
  secretFieldCount: 1,
  overriddenFieldCount: 2,
}
const releases: (() => void)[] = []
afterEach(() => {
  cleanup()
  for (const release of releases.splice(0)) release()
  hilosToasts.clear()
})

/** Real scope, identity and action lifecycle with a controlled table transport. */
export function communicationsHarness(
  page: string = HilosPages.COMMUNICATIONS_CHANNEL,
  viewer = false,
) {
  const scopes = new ScopeManager()
  scopes.session.data.set('adminViewMode', viewer)
  releases.push(bindAdminAccess(scopes))
  const route = createSignal<PageRouteMatch>({
    page,
    params: { channelId: 'email' },
    admin: true,
  })
  const scope = scopes.openPage(page)
  const shell = (key: string) => {
    const hub = key === HilosPages.COMMUNICATIONS
    return {
      pageLabel: hub ? 'Communications' : 'Channel',
      pageLead: 'Delivery channels.',
      pageHeadOwner: {
        page: hub ? key : HilosPages.COMMUNICATIONS_CHANNEL,
        label: hub ? 'Communications' : 'Channel',
        lead: 'Delivery channels.',
        icon: 'bi-envelope-paper',
      },
      pageBreadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.COMMUNICATIONS, label: 'Communications' },
        ...(hub
          ? []
          : [{ page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Channel' }]),
      ],
      pageTabs: hub
        ? [{ page: key, label: 'Overview' }]
        : [
            { page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Overview' },
            {
              page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
              label: 'Configuration',
            },
            { page: HilosPages.COMMUNICATIONS_DELIVERIES, label: 'Deliveries' },
            {
              page: HilosPages.COMMUNICATIONS_CHANNEL_FRAME,
              label: 'Letter frame',
            },
          ],
      pageChildren: [],
    }
  }
  function putSummary(value: HilosChannelSummary | null = channelSummary) {
    scopes.page()?.data.set('channelSummary', value)
  }
  function answerPage(
    key = page,
    value: HilosChannelSummary | null = channelSummary,
  ) {
    const current = scopes.page()
    if (!current) return
    for (const [name, value] of Object.entries(shell(key)))
      current.data.set(name, value)
    putSummary(value)
  }
  answerPage()
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const sent: { action: string; payload: unknown; requestId?: string }[] = []
  const emit = (event: string, frame: unknown) => {
    for (const listener of listeners.get(event) ?? []) listener(frame as never)
  }
  const table =
    page === HilosPages.COMMUNICATIONS
      ? 'hilosCommunicationsChannels'
      : page === HilosPages.COMMUNICATIONS_DELIVERIES
        ? 'hilosNotificationDeliveries'
        : 'hilosCommunicationsChannelFields'
  function pushWindow(requestId?: string) {
    emit('tableWindow', {
      data: {
        page,
        tableKey: table,
        requestId,
        rows:
          page === HilosPages.COMMUNICATIONS
            ? [{ rowKey: 'email', slots: { channel: channelSummary } }]
            : [],
        totalCount: page === HilosPages.COMMUNICATIONS ? 1 : 0,
        totalExact: true,
        offset: 0,
        limit: 20,
        firstAnchor: null,
        lastAnchor: null,
      },
    })
  }
  const unregister = vi.fn(),
    focus = vi.fn(),
    viewport = vi.fn<HilosConnection['sendTableViewport']>(
      (_page, _table, descriptor) => {
        pushWindow(descriptor.requestId)
        return true
      },
    )
  const connection = {
    on(event: string, listener: (frame: never) => void) {
      const set = listeners.get(event) ?? new Set()
      listeners.set(event, set)
      set.add(listener)
      return () => {
        set.delete(listener)
      }
    },
    registerTableWindow: () => pushWindow(),
    unregisterTableWindow: unregister,
    tableWindowDescriptors: () => ({}),
    sendTableViewport: viewport,
    sendTableRendered: () => true,
    sendTableRowFocus: focus,
    sendAction(action: string, payload: unknown, requestId?: string) {
      sent.push({ action, payload, requestId })
      return true
    },
  } as unknown as HilosConnection
  const context: HilosCommunicationsContext = {
    connection,
    scopes,
    actions: new ActionLifecycle(connection),
  }
  const router = {
    currentRoute: route,
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageLoading: createSignal(false),
    pageError: createSignal(null),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    navigate: vi.fn(),
    resolvePath: (key: string, params?: Record<string, string>) =>
      resolveHilosPath(key as HilosPageKey, params),
  } as unknown as HilosRouter
  const wrap = (node: ReactNode) => (
    <HilosRouterContext.Provider value={router}>
      {node}
    </HilosRouterContext.Provider>
  )
  return {
    context,
    scopes,
    scope,
    route,
    router,
    sent,
    unregister,
    focus,
    viewport,
    wrap,
    mount: (node: ReactNode) => render(wrap(node)),
    putSummary,
    answerPage,
    reply(success = true) {
      const last = sent.at(-1)
      emit(success ? 'actionSuccess' : 'actionError', {
        kind: success ? 'actionSuccess' : 'actionError',
        ...last,
        reason: 'Channel refused the change.',
      })
    },
    async settle(success = true) {
      await act(async () => {
        this.reply(success)
        await Promise.resolve()
      })
    },
  }
}

export function channelEl<T extends HTMLElement = HTMLElement>(
  id: string,
): T | null {
  return document.querySelector<T>(`[data-id="${id}"]`)
}
