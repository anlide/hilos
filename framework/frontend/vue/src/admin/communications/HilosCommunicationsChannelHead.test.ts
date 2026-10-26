import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  createSignal,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  CHANNEL_SUMMARY_DATA,
  createHilosChannelSummary,
  type HilosChannelSummary,
} from '@hilos/core/admin/communications'
import { afterEach, describe, expect, it } from 'vitest'
import { markRaw, nextTick } from 'vue'

import HilosCommunicationsChannelHead from './HilosCommunicationsChannelHead.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)

const CHANNEL_PAGES = [
  HilosPages.COMMUNICATIONS_CHANNEL,
  HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
  HilosPages.COMMUNICATIONS_DELIVERIES,
  HilosPages.COMMUNICATIONS_CHANNEL_FRAME,
] as const

const summary: HilosChannelSummary = {
  channel: 'email',
  label: 'Email',
  enabled: true,
  configured: true,
  driver: 'smtp',
  missingFields: 0,
  fieldCount: 7,
  secretFieldCount: 1,
  overriddenFieldCount: 2,
}

/** The same catalog identity and permitted summary arrive on every channel tab. */
function harness(
  page: string = HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  scope.data.set('pageLabel', 'Configuration')
  scope.data.set('pageHeadOwner', {
    page: HilosPages.COMMUNICATIONS_CHANNEL,
    label: 'Channel',
    lead: 'A single communication channel and its configuration.',
    icon: null,
  })
  scope.data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.COMMUNICATIONS, label: 'Communications' },
    { page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Channel' },
  ])
  scope.data.set('pageTabs', [
    { page: CHANNEL_PAGES[0], label: 'Overview' },
    { page: CHANNEL_PAGES[1], label: 'Configuration' },
    { page: CHANNEL_PAGES[2], label: 'Deliveries' },
    { page: CHANNEL_PAGES[3], label: 'Letter frame' },
  ])
  scope.data.set('pageChildren', [])
  scope.data.set(CHANNEL_SUMMARY_DATA, summary)
  const route = createSignal<PageRouteMatch>({
    page,
    params: { channelId: 'email' },
    admin: true,
  })
  const navigation: HilosRouter = {
    currentRoute: route,
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: resolveHilosPath,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  const view = mount(HilosCommunicationsChannelHead, {
    props: {
      page,
      scopes: markRaw(scopes),
      summary: markRaw(createHilosChannelSummary({ scopes })),
    },
    slots: { default: '<p data-id="body">Fields</p>' },
    global: { provide: { [hilosRouterKey as symbol]: navigation } },
  })

  return { scopes, scope, navigation, route, view }
}

describe('HilosCommunicationsChannelHead', () => {
  it.each(CHANNEL_PAGES)(
    'uses one real shared head and current row on %s',
    (page) => {
      const { view, navigation } = harness(page)
      expect(view.findAll('h1')).toHaveLength(1)
      expect(view.get('h1').text()).toBe('Email')
      expect(view.get('[data-id="channel-head-icon"] i').classes()).toContain(
        'bi-envelope-paper',
      )
      const crumb = view.get(
        '[data-id="hilos-breadcrumb"] li[aria-current="page"]',
      )
      expect(crumb.text()).toBe('Email')
      expect(crumb.find('a').exists()).toBe(false)
      expect(navigation.pageIdentity.get()?.breadcrumb.at(-1)?.page).toBe(
        HilosPages.COMMUNICATIONS_CHANNEL,
      )
      expect(view.findAll('[data-id="hilos-page-tabs"]')).toHaveLength(1)
      expect(
        view.get('nav[aria-label="Channel pages"]').findAll('a'),
      ).toHaveLength(4)
      for (const key of CHANNEL_PAGES) {
        const tab = view.get(`[data-id="hilos-page-tab-${key}"]`)
        expect(tab.attributes('href')).toBe(
          resolveHilosPath(key, { channelId: 'email' }),
        )
        expect(tab.attributes('aria-current')).toBe(
          key === page ? 'page' : undefined,
        )
      }
      expect(navigation.pageIdentity.get()?.label).toBe('Configuration')
      expect(view.find('[role="tablist"]').exists()).toBe(false)
      expect(view.find('[data-id^="hilos-admin-child-"]').exists()).toBe(false)
      expect(view.get('[data-id="body"]').text()).toBe('Fields')
      expect(view.get('[data-id="channel-head-enabled"]').text()).toBe(
        'Enabled',
      )
      expect(view.get('[data-id="channel-head-configured"]').text()).toBe(
        'Configured',
      )
    },
  )

  it('updates the live name, breadcrumb, badges and driver from the page summary', async () => {
    const { scope, view, navigation } = harness()
    scope.data.set(CHANNEL_SUMMARY_DATA, {
      ...summary,
      label: 'Outgoing mail',
      enabled: false,
      configured: false,
      missingFields: 2,
      driver: null,
    })
    await nextTick()

    expect(view.get('h1').text()).toBe('Outgoing mail')
    expect(navigation.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe(
      'Outgoing mail',
    )
    expect(view.get('[data-id="channel-head-enabled"]').text()).toBe('Disabled')
    expect(view.get('[data-id="channel-head-configured"]').text()).toBe(
      '2 missing',
    )
    expect(
      view.get('[data-id="channel-head-configured"]').attributes('title'),
    ).toBe('2 field(s) not set')
    expect(view.get('[data-id="channel-head-description"]').text()).toBe(
      'Channel email · driver —',
    )
  })

  it('keeps hidden enabled hidden without a false Disabled badge', async () => {
    const { scope, view } = harness()
    scope.data.set(CHANNEL_SUMMARY_DATA, {
      ...summary,
      enabled: { _hidden: true },
    })
    await nextTick()

    expect(view.find('[data-id="hilos-hidden"]').exists()).toBe(true)
    expect(view.find('[data-id="channel-head-enabled"]').exists()).toBe(false)
    expect(view.text()).not.toContain('Disabled')
    expect(view.text()).not.toContain('[object Object]')
    expect(view.get('[data-id="channel-head-configured"]').text()).toBe(
      'Configured',
    )
  })

  it.each([null, { ...summary, driver: 3 }])(
    'clears an absent or malformed summary: %j',
    async (invalid) => {
      const { scope, view, navigation } = harness()
      scope.data.set(CHANNEL_SUMMARY_DATA, invalid)
      await nextTick()

      expect(view.get('h1').text()).toBe('Channel')
      expect(navigation.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe(
        'Channel',
      )
      expect(view.find('[data-id="channel-head-description"]').exists()).toBe(
        false,
      )
      expect(view.text()).not.toContain('Email')
    },
  )

  it('accepts an empty label as a live name and releases the binding on unmount', async () => {
    const { scope, view, navigation } = harness()
    scope.data.set(CHANNEL_SUMMARY_DATA, { ...summary, label: '' })
    await nextTick()
    expect(view.get('h1').text()).toBe('')
    expect(navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('')

    view.unmount()
    expect(navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('Channel')
  })

  it('reads the new channel scope and forgets the old name across navigation', async () => {
    const { scopes, view, navigation, route } = harness()
    route.set({
      page: HilosPages.COMMUNICATIONS_DELIVERIES,
      params: { channelId: 'sms' },
      admin: true,
    })
    await view.setProps({ page: HilosPages.COMMUNICATIONS_DELIVERIES })
    const next = scopes.openPage(HilosPages.COMMUNICATIONS_DELIVERIES)
    next.data.set('pageLabel', 'Deliveries')
    next.data.set('pageHeadOwner', {
      page: HilosPages.COMMUNICATIONS_CHANNEL,
      label: 'Channel',
      lead: '',
      icon: null,
    })
    next.data.set(CHANNEL_SUMMARY_DATA, {
      ...summary,
      channel: 'sms',
      label: 'SMS',
      driver: 'twilio',
    })
    await nextTick()
    expect(navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('SMS')
    expect(view.get('h1').text()).toBe('SMS')
    expect(view.get('[data-id="channel-head-description"]').text()).toBe(
      'Channel sms · driver twilio',
    )

    scopes.dropPage()
    await nextTick()
    expect(navigation.pageIdentity.get()).toBeUndefined()
    expect(view.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    expect(view.find('h1').exists()).toBe(false)
    expect(view.find('[data-id="channel-head-description"]').exists()).toBe(
      false,
    )
  })

  it('does not bind a retained producer outside the three channel page scopes', () => {
    const { view, navigation } = harness(HilosPages.COMMUNICATIONS)
    expect(navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('Channel')
    expect(view.get('h1').text()).toBe('Channel')
    expect(view.find('[data-id="channel-head-description"]').exists()).toBe(
      false,
    )
  })
})
