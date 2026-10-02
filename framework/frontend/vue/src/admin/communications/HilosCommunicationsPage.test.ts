// The communications hub page (HIL-1255): channels table showing enablement,
// configured status, driver, and link to channel config. In view mode, a viewer
// sees a hidden mark in place of the enablement switch, while an admin sees the switch.
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
  HilosCommunicationsContext,
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import HilosCommunicationsPage from './HilosCommunicationsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const TABLE = 'hilosCommunicationsChannels'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.COMMUNICATIONS,
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

interface ChannelSlot {
  channel: string
  label: string
  enabled: unknown
  configured: boolean
  driver: string | null
  missingFields: number
}

const SMS_CHANNEL: ChannelSlot = {
  channel: 'sms',
  label: 'SMS',
  enabled: true,
  configured: true,
  driver: 'twilio',
  missingFields: 0,
}

type Listener = (signal: { data: unknown }) => void

function seededContext(channel: ChannelSlot): {
  context: HilosCommunicationsContext
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
} {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.COMMUNICATIONS)
  const windowListeners = new Set<Listener>()
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.COMMUNICATIONS,
      tableKey: TABLE,
      rows: [{ rowKey: channel.channel, slots: { channel } }],
      totalCount: 1,
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
      if (tableKey === TABLE) {
        serveWindow()
      }
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(_page: string, tableKey: string): boolean {
      if (tableKey === TABLE) {
        serveWindow()
      }

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
  } as unknown as HilosConnection

  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const actions = {
    dispatch(
      action: string,
      payload: Record<string, unknown>,
    ): {
      requestId: string
      loading: ReturnType<typeof createSignal<boolean>>
      done: Promise<{ ok: boolean }>
    } {
      const requestId = String(sent.length + 1)
      sent.push({ action, payload, requestId })

      return {
        requestId,
        loading: createSignal(false),
        done: Promise.resolve({ ok: true }),
      }
    },
  } as unknown as ActionLifecycle

  return {
    context: { connection, scopes, actions },
    sent,
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
})

describe('HilosCommunicationsPage', () => {
  it('renders an enablement switch for an admin and dispatches toggling', async () => {
    const { context, sent } = seededContext(SMS_CHANNEL)
    const wrapper = mount(HilosCommunicationsPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(wrapper)
    await nextTick()

    const switchEl = wrapper.find<HTMLInputElement>(
      'input[data-id="hilos-channel-enabled-sms"]',
    )
    expect(switchEl.exists()).toBe(true)
    expect(switchEl.element.checked).toBe(true)
    expect(wrapper.find('[data-id="hilos-hidden"]').exists()).toBe(false)

    await switchEl.trigger('click')
    await nextTick()

    expect(sent).toEqual([
      {
        action: 'communications_channel_set',
        payload: { channel: 'sms', field: 'enabled', value: false },
        requestId: expect.any(String),
      },
    ])
  })

  it('renders a hidden mark in place of the switch for a view-mode viewer', async () => {
    const { context, sent } = seededContext({
      ...SMS_CHANNEL,
      enabled: { _hidden: true },
    })
    const wrapper = mount(HilosCommunicationsPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(wrapper)
    await nextTick()

    expect(
      wrapper.find('input[data-id="hilos-channel-enabled-sms"]').exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="hilos-hidden"]').exists()).toBe(true)
    expect(sent).toEqual([])
  })
})
