import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, markRaw, nextTick } from 'vue'
import {
  bindPageScope,
  createAppPageRouter,
  createHilosRouter,
  HilosConnection,
  HilosPages,
  PAGE_SIGNAL_SCHEMAS,
  resolvePageTitle,
  ScopeManager,
  type WebSocketLike,
} from '@hilos/core/base'
import { type HilosModerationKindRow } from '@hilos/core/admin/moderation'

import HilosModerationKindPage from './HilosModerationKindPage.vue'
import HilosView from '../../HilosView.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)
const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

const PAGE = HilosPages.MODERATION_KIND
const TABLE = 'hilosModerationKinds'
const initial: HilosModerationKindRow = {
  kind: 'hilos_profile_name',
  title: 'Profile name',
  owner: 'framework',
  input: 'text',
  model: 'qwen2.5:3b',
  ownRuleCount: 0,
  profile: 'hilos.moderation.text',
  reasons: ['spam', 'threat', 'impersonation'],
  defaultRules: ['Allow polite names.', 'Block threats.'],
}

/** Real protocol, shared shell, head, name and title consumers; only the socket is scripted. */
async function harness(
  patch: Partial<HilosModerationKindRow> = {},
  options: { pending?: boolean; outlet?: boolean; rulesServed?: boolean } = {},
) {
  const first = { ...initial, ...patch }
  const listeners = new Map<string, (event: { data?: unknown }) => void>()
  const sent: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send: (text) => sent.push(JSON.parse(String(text))),
    close: () => {},
    addEventListener: (event, listener) => listeners.set(event, listener),
  }
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
    projectSchemas: PAGE_SIGNAL_SCHEMAS,
  })
  const scopes = new ScopeManager()
  const pages = bindPageScope(connection, scopes)
  const navigation = createHilosRouter(
    createAppPageRouter({}),
    pages,
    {
      pathname: () => `/hilos/moderation/${first.kind}`,
      pushState: () => {},
      replaceState: () => {},
      onPopState: () => () => {},
    },
    (page) => {
      const identity = pages.pageIdentity.get()
      return resolvePageTitle(
        page,
        {},
        'Chat',
        identity?.label,
        !pages.pageLoading.get(),
        identity?.pageHeadOwner?.label,
      )
    },
  )
  connection.connect()
  listeners.get('open')?.({})
  pages.releaseOnSession()
  navigation.start()
  releases.push(() => {
    navigation.stop()
    connection.close()
  })
  function receive(type: string, data: unknown): void {
    listeners.get('message')?.({ data: JSON.stringify({ type, data }) })
  }
  function answer(row: HilosModerationKindRow = first): void {
    receive('page_response', {
      page: PAGE,
      payload: {
        data: {
          pageLabel: 'Kind',
          pageLead: 'One declared check and the rules in force.',
          pageHeadOwner: {
            page: PAGE,
            label: 'Kind',
            lead: 'One declared check and the rules in force.',
            icon: 'bi-shield-check',
          },
          pageBreadcrumb: [
            { page: HilosPages.DASHBOARD, label: 'Hilos' },
            { page: HilosPages.MODERATION, label: 'Moderation' },
            { page: PAGE, label: 'Kind' },
          ],
          pageTabs: [
            { page: PAGE, label: 'Overview' },
            ...(options.rulesServed
              ? [{ page: HilosPages.MODERATION_KIND_RULES, label: 'Rules' }]
              : []),
          ],
          pageChildren: [],
        },
        windows: {
          [TABLE]: {
            rows: [{ rowKey: row.kind, slots: { kind: row } }],
            sort: [],
            limit: 1,
            totalCount: 1,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
          },
        },
      },
    })
  }
  function update(row: HilosModerationKindRow): void {
    receive('table_viewport_delta', {
      page: PAGE,
      tableKey: TABLE,
      kind: 'row_updated',
      rowKey: row.kind,
      row: { rowKey: row.kind, slots: { kind: row } },
    })
  }
  if (!options.pending) answer()
  const context = markRaw({ connection, scopes })
  const view = options.outlet
    ? mount(HilosView, {
        props: {
          pages: {
            [PAGE]: markRaw(
              defineComponent({
                setup: () => () => h(HilosModerationKindPage, { context }),
              }),
            ),
          },
        },
        global: { provide: { [hilosRouterKey as symbol]: navigation } },
      })
    : mount(HilosModerationKindPage, {
        props: { context },
        global: { provide: { [hilosRouterKey as symbol]: navigation } },
      })
  await nextTick()
  return {
    view,
    connection,
    scopes,
    pages,
    navigation,
    first,
    receive,
    answer,
    update,
    sent,
  }
}

describe('HilosModerationKindPage', () => {
  it('renders the real shared head with one h1, ordinary crumb links, live name/title and no tab row or mutation controls', async () => {
    const { view, navigation, sent, update, first } = await harness()
    expect(view.findAll('h1').map((heading) => heading.text())).toEqual([
      'Profile name',
    ])
    const crumbs = view.get('[data-id="hilos-breadcrumb"]')
    expect(
      crumbs.findAll('a').map((link) => [link.text(), link.attributes('href')]),
    ).toEqual([
      ['Hilos', '/hilos'],
      ['Moderation', '/hilos/moderation'],
    ])
    expect(crumbs.get('li:last-child').text()).toBe('Profile name')
    expect(crumbs.get('li:last-child').find('a').exists()).toBe(false)
    expect(view.get('[data-id="hilos-moderation-kind-owner"]').text()).toBe(
      'Framework',
    )
    expect(view.get('[data-id="hilos-moderation-kind-key"]').text()).toBe(
      'hilos_profile_name',
    )
    expect(view.find('[aria-label="Kind pages"]').exists()).toBe(false)
    expect(
      view.find('button, input, select, table, [role="tablist"]').exists(),
    ).toBe(false)
    expect(navigation.currentTitle.get()).toBe('Profile name · Chat')
    expect(sent.filter((frame) => frame.type === 'table_viewport')).toEqual([])
    update({ ...first, title: 'Updated title' })
    await nextTick()
    expect(view.get('h1').text()).toBe('Updated title')
    expect(crumbs.get('li:last-child').text()).toBe('Updated title')
    expect(navigation.currentTitle.get()).toBe('Updated title · Chat')
    view.unmount()
    expect(navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
  })

  it.each([
    [
      'text',
      'framework',
      0,
      'Text model',
      'Framework',
      'Built-in until you add a rule of your own',
    ],
    [
      'image',
      'framework',
      1,
      'Vision model',
      'Framework',
      '1 rule of your own',
    ],
    [
      'text_with_files',
      'project',
      4,
      'Text model',
      'Project',
      '4 rules of your own',
    ],
  ])(
    'draws %s / %s / %s rules with semantic definition rows',
    async (input, owner, ownRuleCount, label, badge, rules) => {
      const { view } = await harness({
        input: String(input),
        owner: String(owner),
        ownRuleCount: Number(ownRuleCount),
      })
      expect(view.findAll('dl dt').map((term) => term.text())).toEqual([
        'Checked by',
        'Can refuse for',
        'Rules in force',
      ])
      expect(view.findAll('dl dd')).toHaveLength(3)
      expect(
        view.get('[data-id="hilos-moderation-kind-model"]').text(),
      ).toContain(label)
      expect(
        view.get('[data-id="hilos-moderation-kind-model"] code').text(),
      ).toBe('qwen2.5:3b')
      expect(view.get('[data-id="hilos-moderation-kind-profile"]').text()).toBe(
        'hilos.moderation.text',
      )
      expect(view.get('[data-id="hilos-moderation-kind-owner"]').text()).toBe(
        badge,
      )
      expect(
        view
          .get('[data-id="hilos-moderation-kind-rules"]')
          .text()
          .replace(/\s+/g, ' '),
      ).toBe(rules)
      expect(
        view
          .get('[data-id="hilos-moderation-kind-reasons"]')
          .findAll('.badge')
          .map((reason) => reason.text()),
      ).toEqual(initial.reasons)
    },
  )

  it('preserves HiddenValue and updates model/count in place without Apply', async () => {
    const { view, first, update } = await harness({ model: { _hidden: true } })
    const model = () => view.get('[data-id="hilos-moderation-kind-model"]')
    expect(model().get('[data-id="hilos-hidden"]').text()).toBe('Hidden')
    expect(view.text()).not.toContain('[object Object]')
    expect(view.text()).not.toContain('qwen2.5:3b')
    update({ ...first, model: 'new-model', ownRuleCount: 2 })
    await nextTick()
    expect(model().text()).toContain('new-model')
    expect(model().find('[data-id="hilos-hidden"]').exists()).toBe(false)
    expect(
      view
        .get('[data-id="hilos-moderation-kind-rules"]')
        .text()
        .replace(/\s+/g, ' '),
    ).toBe('2 rules of your own')
    expect(view.find('[data-id="hilos-table-pending-row"]').exists()).toBe(
      false,
    )
  })

  it('keeps long keys, profiles and reason codes in wrapping Bootstrap markup', async () => {
    const long = 'long_value_'.repeat(40)
    const { view } = await harness({
      kind: long,
      profile: long,
      reasons: [long, 'spam'],
    })
    expect(
      view.get('[data-id="hilos-moderation-kind-key"]').classes(),
    ).toContain('text-break')
    expect(
      view.get('[data-id="hilos-moderation-kind-model"]').classes(),
    ).toContain('text-break')
    const reasons = view.get('[data-id="hilos-moderation-kind-reasons"]')
    expect(reasons.classes()).toContain('flex-wrap')
    expect(reasons.get('.badge').classes()).toEqual(
      expect.arrayContaining(['text-wrap', 'text-break', 'mw-100']),
    )
    expect(reasons.get('.badge').text()).toBe(long)
    expect(view.find('[style]').exists()).toBe(false)
  })

  it('shows a placeholder until the full answer, clears old route data/name, and ignores a late row from another kind', async () => {
    const { view, navigation, scopes, first, answer, update } = await harness(
      {},
      { pending: true },
    )
    expect(
      view.find('[data-id="hilos-moderation-kind-loading"]').exists(),
    ).toBe(true)
    expect(
      view.find('[data-id="hilos-moderation-kind-overview"]').exists(),
    ).toBe(false)
    answer()
    await nextTick()
    expect(view.get('h1').text()).toBe('Profile name')
    navigation.navigate('/hilos/moderation/chat_message')
    await nextTick()
    expect(view.text()).not.toContain('Profile name')
    expect(
      view.find('[data-id="hilos-moderation-kind-overview"]').exists(),
    ).toBe(false)
    update(first)
    await nextTick()
    expect(
      view.find('[data-id="hilos-moderation-kind-overview"]').exists(),
    ).toBe(false)
    answer({
      ...first,
      kind: 'chat_message',
      title: 'Chat message',
      owner: 'project',
    })
    await nextTick()
    expect(view.get('h1').text()).toBe('Chat message')
    expect(navigation.currentTitle.get()).toBe('Chat message · Chat')
    scopes.openPage(PAGE)
    await nextTick()
    expect(view.text()).not.toContain('Chat message')
    expect(
      view.find('[data-id="hilos-moderation-kind-overview"]').exists(),
    ).toBe(false)
  })

  it('speaks about refused and frozen windows through the stock accessible states', async () => {
    const { view, receive, answer } = await harness()
    receive('table_viewport_frozen', {
      page: PAGE,
      tableKey: TABLE,
      since: 1000,
    })
    await nextTick()
    expect(view.get('[data-id="hilos-table-live-status"]').text()).toContain(
      'may be out of date',
    )
    expect(view.get('[data-id="hilos-table-frozen"]').text()).toContain(
      'has not updated',
    )
    receive('table_window_refused', {
      page: PAGE,
      tableKey: TABLE,
      errorCode: 'unavailable',
    })
    await nextTick()
    expect(
      view.get('[data-id="hilos-table-unavailable"]').attributes('role'),
    ).toBe('status')
    expect(
      view.find('[data-id="hilos-moderation-kind-overview"]').exists(),
    ).toBe(false)
    expect(view.text()).not.toContain('Built-in')
    answer()
    await nextTick()
    expect(view.find('[data-id="hilos-table-unavailable"]').exists()).toBe(
      false,
    )
    expect(view.find('[data-id="hilos-table-frozen"]').exists()).toBe(false)
  })

  it('lets the real outlet replace an unknown kind with the server 404', async () => {
    const { view, receive, navigation } = await harness({}, { outlet: true })
    expect(view.get('h1').text()).toBe('Profile name')
    navigation.navigate('/hilos/moderation/unknown')
    receive('subscription_page_error', {
      page: PAGE,
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Kind not found',
      message: 'This kind does not exist.',
    })
    await nextTick()
    expect(view.text()).toContain('Kind not found')
    expect(view.text()).toContain('This kind does not exist.')
    expect(
      view.find('[data-id="hilos-moderation-kind-overview"]').exists(),
    ).toBe(false)
    expect(view.text()).not.toContain('Built-in')
  })
})

it('uses the supplied two-tab kind head without changing the Overview body', async () => {
  const { view } = await harness({}, { rulesServed: true })
  const tabs = view.get('[aria-label="Kind pages"]')
  expect(
    tabs
      .findAll('a')
      .map((link) => [link.text(), link.attributes('aria-current')]),
  ).toEqual([
    ['Overview', 'page'],
    ['Rules', undefined],
  ])
  expect(tabs.findAll('a')[1].attributes('href')).toBe(
    '/hilos/moderation/hilos_profile_name/rules',
  )
  expect(view.findAll('h1')).toHaveLength(1)
  expect(
    view.get('[data-id="hilos-moderation-kind-overview"]').findAll('dt'),
  ).toHaveLength(3)
})
