import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  hilosToasts,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  createSignal,
  pageIdentity,
  resolveHilosPath,
} from '@hilos/core'
import type { HilosChannelSummary } from '@hilos/core/admin/communications'
import type {
  HilosCommunicationsContext,
  HilosConnection,
  HilosPageIdentity,
  HilosPageKey,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosCommunicationsChannelPage } from '../src/admin/communications/HilosCommunicationsChannelPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const SUMMARY: HilosChannelSummary = {
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

const HEAD_IDENTITY: HilosPageIdentity = {
  label: 'Channel',
  lead: 'A single communication channel and its configuration.',
  pageHeadOwner: {
    page: HilosPages.COMMUNICATIONS_CHANNEL,
    label: 'Channel',
    lead: 'A single communication channel and its configuration.',
    icon: 'bi-envelope-paper',
  },
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.COMMUNICATIONS, label: 'Communications' },
    { page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Channel' },
  ],
  pageTabs: [
    { page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Overview' },
    {
      page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
      label: 'Configuration',
    },
    { page: HilosPages.COMMUNICATIONS_DELIVERIES, label: 'Deliveries' },
  ],
  children: [],
}

const resolvePath: HilosRouter['resolvePath'] = (page, params) =>
  resolveHilosPath(page as HilosPageKey, params)

type Listener = (frame: Record<string, unknown>) => void

function communicationsHarness(): {
  context: HilosCommunicationsContext
  route: ReturnType<typeof createSignal<PageRouteMatch>>
  sent: Array<{ action: string; payload: unknown; requestId?: string }>
  registerTableWindow: ReturnType<typeof vi.fn>
  putSummary: (summary: HilosChannelSummary | null) => void
  settle: (success?: boolean) => Promise<void>
  openOtherPage: () => void
} {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.COMMUNICATIONS_CHANNEL)
  for (const [key, value] of Object.entries({
    pageLabel: 'Channel',
    pageLead: HEAD_IDENTITY.lead,
    pageHeadOwner: HEAD_IDENTITY.pageHeadOwner,
    pageBreadcrumb: HEAD_IDENTITY.breadcrumb,
    pageTabs: HEAD_IDENTITY.pageTabs,
    pageChildren: [],
    channelSummary: SUMMARY,
  })) {
    scope.data.set(key, value)
  }

  const route = createSignal<PageRouteMatch>({
    page: HilosPages.COMMUNICATIONS_CHANNEL,
    params: { channelId: 'email' },
    admin: true,
  })
  const listeners = new Map<string, Set<Listener>>()
  const sent: Array<{ action: string; payload: unknown; requestId?: string }> =
    []
  const registerTableWindow = vi.fn()
  const connection = {
    registerTableWindow,
    unregisterTableWindow: vi.fn(),
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(() => true),
    sendTableRendered: vi.fn(() => true),
    sendAction(action: string, payload: unknown, requestId?: string): boolean {
      sent.push({ action, payload, requestId })

      return true
    },
    on(event: string, listener: Listener): () => void {
      const current = listeners.get(event) ?? new Set()
      current.add(listener)
      listeners.set(event, current)

      return () => current.delete(listener)
    },
  } as unknown as HilosConnection
  const context: HilosCommunicationsContext = {
    connection,
    scopes,
    actions: new ActionLifecycle(connection),
  }
  function putSummary(summary: HilosChannelSummary | null): void {
    scopes.page()?.data.set('channelSummary', summary)
  }

  return {
    context,
    route,
    sent,
    registerTableWindow,
    putSummary,
    async settle(success = true): Promise<void> {
      const last = sent.at(-1)
      const event = success ? 'actionSuccess' : 'actionError'
      for (const listener of listeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The channel refused the change.',
        })
      }
      await Promise.resolve()
    },
    openOtherPage(): void {
      scopes.openPage(HilosPages.COMMUNICATIONS)
    },
  }
}

function mountPage(
  context: HilosCommunicationsContext,
): ComponentFixture<HilosCommunicationsChannelPage> {
  TestBed.configureTestingModule({
    providers: [
      { provide: HILOS_ROUTER, useValue: communicationsHarnessRouter },
    ],
  })
  const fixture = TestBed.createComponent(HilosCommunicationsChannelPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

let communicationsHarnessRouter: HilosRouter
const releases: (() => void)[] = []

afterEach(() => {
  TestBed.resetTestingModule()
  hilosToasts.clear()
  for (const release of releases.splice(0)) release()
})

function element(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

describe('HilosCommunicationsChannelPage Overview', () => {
  it('draws the live summary and shared head without creating a fields table', () => {
    const h = communicationsHarness()
    communicationsHarnessRouter = {
      currentRoute: h.route,
      currentPath: createSignal(''),
      currentTitle: createSignal(''),
      pageError: createSignal(null),
      pageLoading: createSignal(false),
      pageIdentity: pageIdentity(h.context.scopes),
      dashboardSections: createSignal(undefined),
      resolvePath,
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
      onLeave: () => () => {},
      navigate: () => {},
      replacePath: () => {},
      start: () => {},
      stop: () => {},
    } as unknown as HilosRouter
    const fixture = mountPage(h.context)

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll('h1'),
    ).toHaveLength(1)
    expect(element(fixture, 'hilos-page-head-title')?.textContent?.trim()).toBe(
      'Email',
    )
    expect(element(fixture, 'channel-head-icon')).not.toBeNull()
    expect(element(fixture, 'channel-head-enabled')?.textContent).toContain(
      'Enabled',
    )
    expect(element(fixture, 'channel-head-configured')?.textContent).toContain(
      'Configured',
    )
    expect(element(fixture, 'channel-head-description')?.textContent).toContain(
      'Channel email · driver smtp',
    )
    expect(element(fixture, 'channel-summary-fields')?.textContent).toContain(
      '6',
    )
    expect(element(fixture, 'channel-summary-fields')?.textContent).toContain(
      '1 secret, set in env only',
    )
    expect(
      element(fixture, 'channel-summary-overridden')?.textContent,
    ).toContain('2')
    expect(
      element(fixture, 'hilos-page-tabs')?.getAttribute('aria-label'),
    ).toBe('Channel pages')
    expect(
      element(
        fixture,
        `hilos-page-tab-${HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION}`,
      )?.getAttribute('href'),
    ).toBe('/hilos/communications/email/configuration')
    expect(
      element(
        fixture,
        `hilos-page-tab-${HilosPages.COMMUNICATIONS_DELIVERIES}`,
      )?.getAttribute('href'),
    ).toBe('/hilos/communications/email/deliveries')
    expect(element(fixture, 'hilos-viewport-table')).toBeNull()
    expect(h.registerTableWindow).not.toHaveBeenCalled()

    h.putSummary({
      ...SUMMARY,
      label: 'Work mail',
      configured: false,
      missingFields: 3,
      fieldCount: 8,
      overriddenFieldCount: 4,
    })
    fixture.detectChanges()
    expect(element(fixture, 'hilos-page-head-title')?.textContent?.trim()).toBe(
      'Work mail',
    )
    expect(
      element(fixture, 'channel-summary-configuration')?.textContent,
    ).toContain('3 missing')
    expect(element(fixture, 'channel-summary-fields')?.textContent).toContain(
      '8',
    )
    expect(
      element(fixture, 'channel-summary-overridden')?.textContent,
    ).toContain('4')
    expect(h.sent).toEqual([])
  })

  it('keeps the server value on a refused toggle and sends only once while busy', async () => {
    const h = communicationsHarness()
    communicationsHarnessRouter = {
      currentRoute: h.route,
      currentPath: createSignal(''),
      currentTitle: createSignal(''),
      pageError: createSignal(null),
      pageLoading: createSignal(false),
      pageIdentity: pageIdentity(h.context.scopes),
      dashboardSections: createSignal(undefined),
      resolvePath,
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
      onLeave: () => () => {},
      navigate: () => {},
      replacePath: () => {},
      start: () => {},
      stop: () => {},
    } as unknown as HilosRouter
    const fixture = mountPage(h.context)
    const toggle = element(fixture, 'hilos-channel-enabled') as HTMLInputElement

    toggle.click()
    toggle.click()
    fixture.detectChanges()
    expect(h.sent).toHaveLength(1)
    expect(h.sent[0]).toMatchObject({
      action: 'communications_channel_set',
      payload: { channel: 'email', field: 'enabled', value: false },
    })
    expect(toggle.checked).toBe(true)
    expect(toggle.getAttribute('aria-busy')).toBe('true')

    await h.settle(false)
    fixture.detectChanges()
    expect(toggle.checked).toBe(true)
    expect(toggle.getAttribute('aria-busy')).toBeNull()
  })

  it('shows hidden enablement as hidden, not Disabled, and blocks a viewer test', () => {
    const h = communicationsHarness()
    h.context.scopes.session.data.set('adminViewMode', true)
    releases.push(bindAdminAccess(h.context.scopes))
    h.putSummary({ ...SUMMARY, enabled: { _hidden: true } })
    communicationsHarnessRouter = {
      currentRoute: h.route,
      currentPath: createSignal(''),
      currentTitle: createSignal(''),
      pageError: createSignal(null),
      pageLoading: createSignal(false),
      pageIdentity: pageIdentity(h.context.scopes),
      dashboardSections: createSignal(undefined),
      resolvePath,
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
      onLeave: () => () => {},
      navigate: () => {},
      replacePath: () => {},
      start: () => {},
      stop: () => {},
    } as unknown as HilosRouter
    const fixture = mountPage(h.context)
    const enabled = element(fixture, 'channel-head-enabled')

    expect(enabled?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(enabled?.textContent).not.toContain('Disabled')
    expect(element(fixture, 'hilos-channel-enabled')).toBeNull()
    const testButton = element(
      fixture,
      'hilos-channel-test',
    ) as HTMLButtonElement
    expect(testButton.disabled).toBe(true)
    expect(testButton.getAttribute('aria-describedby')).toContain(
      'hilos-view-mode-strip-text',
    )
    testButton.click()
    fixture.detectChanges()
    expect(h.sent).toEqual([])
  })

  it('clears the live channel summary when the route or page scope moves elsewhere', () => {
    const h = communicationsHarness()
    communicationsHarnessRouter = {
      currentRoute: h.route,
      currentPath: createSignal(''),
      currentTitle: createSignal(''),
      pageError: createSignal(null),
      pageLoading: createSignal(false),
      pageIdentity: pageIdentity(h.context.scopes),
      dashboardSections: createSignal(undefined),
      resolvePath,
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
      onLeave: () => () => {},
      navigate: () => {},
      replacePath: () => {},
      start: () => {},
      stop: () => {},
    } as unknown as HilosRouter
    const fixture = mountPage(h.context)

    h.route.set({
      page: HilosPages.COMMUNICATIONS_CHANNEL,
      params: { channelId: 'sms' },
      admin: true,
    })
    fixture.detectChanges()
    expect(element(fixture, 'channel-summary-configuration')).toBeNull()
    expect(element(fixture, 'hilos-page-head-title')?.textContent?.trim()).toBe(
      'Channel',
    )

    h.putSummary({ ...SUMMARY, channel: 'sms', label: 'SMS', driver: 'twilio' })
    fixture.detectChanges()
    expect(element(fixture, 'hilos-page-head-title')?.textContent?.trim()).toBe(
      'SMS',
    )

    h.openOtherPage()
    fixture.detectChanges()
    expect(element(fixture, 'channel-summary-configuration')).toBeNull()
  })
})
