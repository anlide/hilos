// The Angular peer of vue/src/admin/communications/HilosCommunicationsPage.test.ts
// (HIL-1255), under the same case names: the channels table draws the enablement
// switch for an admin and dispatches its toggle, and a viewer of the admin view
// mode sent a hidden enablement sees the hidden mark in place of the switch.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
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
import { describe, expect, it } from 'vitest'

import { HilosCommunicationsPage } from '../src/admin/communications/HilosCommunicationsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

function mountPage(
  context: HilosCommunicationsContext,
): ComponentFixture<HilosCommunicationsPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosCommunicationsPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

describe('HilosCommunicationsPage', () => {
  it('renders an enablement switch for an admin and dispatches toggling', () => {
    const { context, sent } = seededContext(SMS_CHANNEL)
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    const switchEl = root.querySelector<HTMLInputElement>(
      'input[data-id="hilos-channel-enabled-sms"]',
    )
    expect(switchEl).not.toBeNull()
    expect(switchEl?.checked).toBe(true)
    expect(root.querySelector('[data-id="hilos-hidden"]')).toBeNull()

    switchEl?.click()
    fixture.detectChanges()

    expect(sent).toEqual([
      {
        action: 'communications_channel_set',
        payload: { channel: 'sms', field: 'enabled', value: false },
        requestId: expect.any(String),
      },
    ])
  })

  it('renders a hidden mark in place of the switch for a view-mode viewer', () => {
    const { context, sent } = seededContext({
      ...SMS_CHANNEL,
      enabled: { _hidden: true },
    })
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    expect(
      root.querySelector('input[data-id="hilos-channel-enabled-sms"]'),
    ).toBeNull()
    expect(root.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(sent).toEqual([])
  })
})
