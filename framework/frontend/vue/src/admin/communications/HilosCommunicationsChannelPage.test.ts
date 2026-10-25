// The channel Overview consumes the same answer as the real shared head. The
// controls use the action lifecycle; neither acknowledgement nor a press is a
// settings echo, and no table is mounted on this tab.
import { flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  computedSignal,
  createAppPageRouter,
  createSignal,
  hilosToasts,
  ingest,
  pageIdentity,
  resolvePageTitle,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
  type Scope,
} from '@hilos/core/base'
import {
  CHANNEL_SUMMARY_DATA,
  type HilosChannelSummary,
  type HilosCommunicationsContext,
} from '@hilos/core/admin/communications'

import HilosCommunicationsChannelPage from './HilosCommunicationsChannelPage.vue'
import HilosSwitch from '../../HilosSwitch.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const PAGE = HilosPages.COMMUNICATIONS_CHANNEL
const SMS: HilosChannelSummary = {
  channel: 'sms',
  label: 'SMS',
  enabled: true,
  configured: false,
  driver: 'twilio',
  missingFields: 1,
  fieldCount: 8,
  secretFieldCount: 3,
  overriddenFieldCount: 2,
}

/** The approved complete identity, alongside the summary in the page answer. */
function answer(scope: Scope, summary: HilosChannelSummary): void {
  ingest(scope, {
    data: {
      pageLabel: 'Channel',
      pageLead: 'A single communication channel: its summary and its switch.',
      pageHeadOwner: {
        page: PAGE,
        label: 'Channel',
        lead: 'A single communication channel: its summary and its switch.',
        icon: null,
      },
      pageBreadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.COMMUNICATIONS, label: 'Communications' },
        { page: PAGE, label: 'Channel' },
      ],
      pageTabs: [
        { page: PAGE, label: 'Overview' },
        {
          page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
          label: 'Configuration',
        },
        { page: HilosPages.COMMUNICATIONS_DELIVERIES, label: 'Deliveries' },
      ],
      pageChildren: [],
      [CHANNEL_SUMMARY_DATA]: summary,
    },
  })
}

const mounted: ReturnType<typeof mount>[] = []
const releases: (() => void)[] = []
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
  for (const release of releases.splice(0)) release()
  hilosToasts.clear()
  vi.useRealTimers()
})

function harness(
  initial: HilosChannelSummary | null = SMS,
  viewer = false,
  viewMode = viewer,
) {
  const scopes = new ScopeManager()
  ingest(scopes.session, {
    entities: { currentUser: { id: 1, admin: !viewer, name: 'Olena' } },
    data: { adminViewMode: viewMode },
  })
  releases.push(bindAdminAccess(scopes))
  const scope = scopes.openPage(PAGE)
  if (initial) answer(scope, initial)
  const identity = pageIdentity(scopes)
  const routes = createAppPageRouter({})
  const route = createSignal<PageRouteMatch>({
    page: PAGE,
    params: { channelId: initial?.channel ?? 'sms' },
    admin: true,
  })
  const navigation: HilosRouter = {
    currentRoute: route,
    currentPath: createSignal(
      `/hilos/communications/${initial?.channel ?? 'sms'}`,
    ),
    currentTitle: computedSignal(() => {
      const current = identity.get()

      return resolvePageTitle(
        PAGE,
        {},
        'Demo',
        current?.label,
        current !== undefined,
        current?.pageHeadOwner?.label,
      )
    }),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: identity,
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => routes.path(page, params),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  const listeners = new Map<
    string,
    Set<(signal: Record<string, unknown>) => void>
  >()
  const sent: Array<{ action: string; payload: unknown; requestId?: string }> =
    []
  const connection = {
    registerTableWindow: vi.fn(),
    unregisterTableWindow: vi.fn(),
    sendTableViewport: vi.fn(),
    sendTableRendered: vi.fn(),
    sendTableRowFocus: vi.fn(),
    sendAction(action: string, payload: unknown, requestId?: string): boolean {
      sent.push({ action, payload, requestId })

      return true
    },
    on(event: string, listener: (signal: Record<string, unknown>) => void) {
      const set = listeners.get(event) ?? new Set()
      set.add(listener)
      listeners.set(event, set)

      return () => set.delete(listener)
    },
  }
  const actions = new ActionLifecycle(connection as unknown as HilosConnection)
  const context: HilosCommunicationsContext = {
    connection: connection as unknown as HilosConnection,
    scopes,
    actions,
  }

  return {
    context,
    scope,
    navigation,
    route,
    sent,
    connection,
    update(summary: unknown): void {
      ingest(scopes.page()!, { data: { [CHANNEL_SUMMARY_DATA]: summary } })
    },
    reply(outcome: 'success' | 'fail', text?: string): void {
      const last = sent.at(-1)!
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of listeners.get(event) ?? []) {
        listener({
          action: last.action,
          requestId: last.requestId,
          ...(outcome === 'success' ? { message: text } : { reason: text }),
        })
      }
    },
  }
}

async function mountPage(h: ReturnType<typeof harness>) {
  const view = mount(HilosCommunicationsChannelPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: h.navigation } },
  })
  mounted.push(view)
  await nextTick()

  return view
}

function enabled(view: ReturnType<typeof mount>) {
  return view.get<HTMLInputElement>('[data-id="hilos-channel-enabled"]')
}

describe('HilosCommunicationsChannelPage Overview', () => {
  it('draws one real head, owner breadcrumb, row and summary tiles without fields or tables', async () => {
    const h = harness()
    const view = await mountPage(h)
    expect(view.findAll('h1')).toHaveLength(1)
    expect(view.get('h1').text()).toBe('SMS')
    expect(view.get('[data-id="channel-head-icon"] i').classes()).toContain(
      'bi-envelope-paper',
    )
    const crumbs = view.get('nav[aria-label="Breadcrumb"]')
    expect(crumbs.findAll('li').map((item) => item.text())).toEqual([
      'Hilos',
      'Communications',
      'SMS',
    ])
    expect(crumbs.get('[aria-current="page"]').find('a').exists()).toBe(false)
    const row = view.get('nav[aria-label="Channel pages"]')
    expect(
      row.findAll('a').map((link) => [link.text(), link.attributes('href')]),
    ).toEqual([
      ['Overview', '/hilos/communications/sms'],
      ['Configuration', '/hilos/communications/sms/configuration'],
      ['Deliveries', '/hilos/communications/sms/deliveries'],
    ])
    expect(
      row.findAll('[aria-current="page"]').map((link) => link.text()),
    ).toEqual(['Overview'])
    expect(view.get('[data-id="channel-head-enabled"]').text()).toBe('Enabled')
    expect(view.get('[data-id="channel-head-configured"]').text()).toBe(
      '1 missing',
    )
    expect(
      view.get('[data-id="channel-head-configured"]').attributes('title'),
    ).toBe('1 field(s) not set')
    expect(view.get('[data-id="channel-head-description"]').text()).toBe(
      'Channel sms · driver twilio',
    )
    expect(
      view.get('[data-id="channel-summary-configuration"]').text(),
    ).toContain('fields hold a bare default')
    expect(view.get('[data-id="channel-summary-fields"]').text()).toContain('8')
    expect(view.get('[data-id="channel-summary-fields"]').text()).toContain(
      '3 secret, set in env only',
    )
    expect(view.get('[data-id="channel-summary-overridden"]').text()).toContain(
      '2',
    )
    expect(view.get('[data-id="channel-summary-overridden"]').text()).toContain(
      'reset returns them to env or the default',
    )
    expect(view.get('[data-id="channel-controls"]').text()).toContain(
      'When off, no new deliveries go out through it; queued ones still do.',
    )
    expect(view.get('[data-id="channel-controls"]').text()).toContain(
      'One notification to you, through this channel only.',
    )
    expect(view.findAll('table')).toHaveLength(0)
    expect(view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
    expect(view.find('[data-id^="hilos-channel-field-"]').exists()).toBe(false)
    expect(view.find('[data-id="modal"]').exists()).toBe(false)
    for (const call of [
      h.connection.registerTableWindow,
      h.connection.sendTableViewport,
      h.connection.sendTableRendered,
      h.connection.sendTableRowFocus,
    ]) {
      expect(call).not.toHaveBeenCalled()
    }
    expect(h.sent).toEqual([])
  })

  it('updates counts, status, name and navigator title from summary-only data', async () => {
    const h = harness()
    const view = await mountPage(h)
    h.update({
      ...SMS,
      label: 'Text messages',
      configured: true,
      missingFields: 0,
      enabled: false,
      driver: null,
      fieldCount: 0,
      secretFieldCount: 0,
      overriddenFieldCount: 0,
    })
    await nextTick()
    expect(view.get('h1').text()).toBe('Text messages')
    expect(
      view.get('nav[aria-label="Breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Text messages')
    expect(h.navigation.currentTitle.get()).toBe('Text messages · Demo')
    expect(view.get('[data-id="channel-head-enabled"]').text()).toBe('Disabled')
    expect(view.get('[data-id="channel-head-configured"]').text()).toBe(
      'Configured',
    )
    expect(
      view.get('[data-id="channel-head-configured"]').attributes('title'),
    ).toBe('0 field(s) not set')
    expect(view.get('[data-id="channel-head-description"]').text()).toBe(
      'Channel sms · driver —',
    )
    expect(
      view.get('[data-id="channel-summary-configuration"]').text(),
    ).toContain('every field has a value')
    expect(view.get('[data-id="channel-summary-fields"] .fs-4').text()).toBe(
      '0',
    )
    expect(view.get('[data-id="channel-summary-fields"] p').text()).toBe(
      'no secrets',
    )
    expect(
      view.get('[data-id="channel-summary-overridden"] .fs-4').text(),
    ).toBe('0')
    expect(view.get('[data-id="channel-summary-overridden"] p').text()).toBe(
      'every value comes from env or the default',
    )
    expect(h.sent).toEqual([])
  })

  it('shows no assumed data before the answer and clears invalid summaries', async () => {
    const h = harness(null)
    const view = await mountPage(h)
    expect(view.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    expect(view.find('h1').exists()).toBe(false)
    expect(view.find('[data-id="channel-controls"]').exists()).toBe(false)
    answer(h.scope, SMS)
    await nextTick()
    expect(view.get('h1').text()).toBe('SMS')
    h.update({ ...SMS, fieldCount: -1 })
    await nextTick()
    expect(view.get('h1').text()).toBe('Channel')
    expect(view.find('[data-id="channel-controls"]').exists()).toBe(false)
    expect(view.find('[data-id="channel-head-enabled"]').exists()).toBe(false)
  })

  it('never carries a channel summary across route changes, cleared or unrelated scopes, and disposes its name binding', async () => {
    const h = harness()
    const view = await mountPage(h)
    h.route.set({
      page: PAGE,
      params: { channelId: 'email' },
      admin: true,
    })
    await nextTick()
    expect(view.get('h1').text()).toBe('Channel')
    expect(view.find('[data-id="channel-head-description"]').exists()).toBe(
      false,
    )
    expect(view.find('[data-id="channel-controls"]').exists()).toBe(false)
    h.context.scopes.dropPage()
    await nextTick()
    expect(view.find('h1').exists()).toBe(false)
    expect(h.navigation.currentTitle.get()).toBe('')
    const fresh = h.context.scopes.openPage(PAGE)
    answer(fresh, { ...SMS, channel: 'email', label: 'Email' })
    await nextTick()
    expect(view.get('h1').text()).toBe('Email')
    expect(h.navigation.currentTitle.get()).toBe('Email · Demo')
    h.scope.data.set(CHANNEL_SUMMARY_DATA, { ...SMS, label: 'Old scope' })
    await nextTick()
    expect(view.get('h1').text()).toBe('Email')
    const other = h.context.scopes.openPage(
      HilosPages.COMMUNICATIONS_DELIVERIES,
    )
    answer(other, { ...SMS, channel: 'email', label: 'Other tab' })
    await nextTick()
    expect(view.get('h1').text()).toBe('Channel')
    expect(view.find('[data-id="channel-controls"]').exists()).toBe(false)
    const back = h.context.scopes.openPage(PAGE)
    answer(back, { ...SMS, channel: 'email', label: 'Email again' })
    await nextTick()
    expect(view.get('h1').text()).toBe('Email again')
    view.unmount()
    mounted.splice(mounted.indexOf(view), 1)
    expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Channel',
    )
  })
})

describe('HilosCommunicationsChannelPage actions', () => {
  it('guards repeats immediately, delays the spinner 300 ms and accepts only a server summary as the new value', async () => {
    const h = harness()
    const view = await mountPage(h)
    vi.useFakeTimers()
    const toggle = view.getComponent(HilosSwitch)
    toggle.vm.$emit('toggle', false)
    toggle.vm.$emit('toggle', false)
    await nextTick()
    expect(h.sent).toEqual([
      {
        action: 'communications_channel_set',
        payload: { channel: 'sms', field: 'enabled', value: false },
        requestId: expect.any(String),
      },
    ])
    expect(enabled(view).element.disabled).toBe(true)
    expect(enabled(view).attributes('aria-busy')).toBe('true')
    expect(enabled(view).element.checked).toBe(true)
    expect(view.get('[data-id="channel-head-enabled"]').text()).toBe('Enabled')
    await vi.advanceTimersByTimeAsync(299)
    expect(toggle.find('.spinner-border').exists()).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    expect(toggle.find('.spinner-border').exists()).toBe(true)
    h.reply('success')
    await vi.advanceTimersByTimeAsync(0)
    await nextTick()
    expect(enabled(view).element.disabled).toBe(false)
    expect(enabled(view).element.checked).toBe(true)
    expect(toggle.find('.spinner-border').exists()).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
    h.update({ ...SMS, enabled: false })
    await nextTick()
    expect(enabled(view).element.checked).toBe(false)
    expect(view.get('[data-id="channel-head-enabled"]').text()).toBe('Disabled')
  })

  it('preserves the old value and server refusal, then permits another toggle', async () => {
    const h = harness()
    const view = await mountPage(h)
    await enabled(view).trigger('click')
    h.reply('fail', 'This channel is locked.')
    await flushPromises()
    expect(enabled(view).element.checked).toBe(true)
    expect(enabled(view).element.disabled).toBe(false)
    expect(hilosToasts.toasts.get()).toMatchObject([
      { severity: 'error', message: 'This channel is locked.' },
    ])
    await enabled(view).trigger('click')
    expect(h.sent).toHaveLength(2)
    h.reply('success')
    await flushPromises()
  })

  it('lets an admin on a node in view mode track test sending without changing settings and keeps backend toast/refusal words', async () => {
    const h = harness(SMS, false, true)
    const view = await mountPage(h)
    vi.useFakeTimers()
    const test = view.get('[data-id="hilos-channel-test"]')
    const runner = view.getComponent(LoadingButton)
    runner.vm.$emit('click', new MouseEvent('click'))
    runner.vm.$emit('click', new MouseEvent('click'))
    await nextTick()
    expect(h.sent).toEqual([
      {
        action: 'communications_channel_test',
        payload: { channel: 'sms' },
        requestId: expect.any(String),
      },
    ])
    expect(test.attributes('aria-busy')).toBe('true')
    expect(test.attributes('aria-disabled')).toBe('true')
    await test.trigger('click')
    expect(h.sent).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(299)
    expect(test.find('.spinner-border').exists()).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    expect(test.find('.spinner-border').exists()).toBe(true)
    h.reply('success', 'Test notification queued.')
    await vi.advanceTimersByTimeAsync(0)
    await nextTick()
    expect(hilosToasts.toasts.get()).toMatchObject([
      { severity: 'success', message: 'Test notification queued.' },
    ])
    expect(h.scope.data.signal(CHANNEL_SUMMARY_DATA).get()).toEqual(SMS)
    await test.trigger('click')
    h.reply('fail', 'No phone number is set.')
    await vi.advanceTimersByTimeAsync(0)
    await nextTick()
    expect(hilosToasts.toasts.get().at(-1)).toMatchObject({
      severity: 'error',
      message: 'No phone number is set.',
    })
    expect(h.sent.map((sent) => sent.action)).toEqual([
      'communications_channel_test',
      'communications_channel_test',
    ])
  })

  it('keeps hidden enabled hidden and viewer mutations disabled through the standard controls', async () => {
    const h = harness({ ...SMS, enabled: { _hidden: true } }, true)
    const view = await mountPage(h)
    expect(view.find('[data-id="channel-head-enabled"]').exists()).toBe(false)
    expect(view.find('[data-id="hilos-channel-enabled"]').exists()).toBe(false)
    expect(view.findAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    expect(view.text()).not.toContain('Disabled')
    expect(view.get('[data-id="channel-summary-fields"]').text()).toContain(
      '3 secret, set in env only',
    )
    const test = view.get<HTMLButtonElement>('[data-id="hilos-channel-test"]')
    expect(test.element.disabled).toBe(true)
    expect(test.attributes('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    test.element.click()
    h.update({ ...SMS, enabled: false })
    await nextTick()
    expect(enabled(view).element.disabled).toBe(true)
    expect(enabled(view).element.checked).toBe(false)
    expect(enabled(view).attributes('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    enabled(view).element.click()
    await nextTick()
    expect(h.sent).toEqual([])
  })
})
