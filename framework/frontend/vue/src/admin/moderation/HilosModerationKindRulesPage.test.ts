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
  type TableViewportController,
  type WebSocketLike,
} from '@hilos/core/base'
import {
  type HilosModerationKindRow,
  type HilosModerationRuleRow,
} from '@hilos/core/admin/moderation'

import HilosModerationKindRulesPage from './HilosModerationKindRulesPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import HilosView from '../../HilosView.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)
const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

const OWNER = HilosPages.MODERATION_KIND
const PAGE = HilosPages.MODERATION_KIND_RULES
const RULES = 'hilosModerationRules'
const TABLE = 'hilosModerationKinds'
const initial: HilosModerationKindRow = {
  kind: 'hilos_profile_name',
  title: 'Profile name',
  owner: 'framework',
  input: 'text',
  model: 'qwen2.5:3b',
  ownRuleCount: 1,
  profile: 'hilos.moderation.text',
  reasons: ['spam', 'threat', 'impersonation'],
  defaultRules: ['Allow polite names.', 'Block threats.'],
}

/** Real protocol, shared shell, head, name and title consumers; only the socket is scripted. */
async function harness(
  patch: Partial<HilosModerationKindRow> = {},
  options: { pending?: boolean; outlet?: boolean; empty?: boolean } = {},
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
      pathname: () => `/hilos/moderation/${first.kind}/rules`,
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
  function ruleWindow(rules = options.empty ? [] : ['Own rule']) {
    return {
      rows: rules.map((rule, index) => ({
        rowKey: String(index + 1),
        slots: { rule: { id: index + 1, kind: first.kind, rule } },
      })),
      sort: [{ field: 'id', direction: 'asc' }],
      limit: 25,
      totalCount: rules.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      rowsBefore: 0,
    }
  }
  function answer(
    row: HilosModerationKindRow = first,
    rules = options.empty ? [] : ['Own rule'],
  ): void {
    receive('page_response', {
      page: PAGE,
      payload: {
        data: {
          pageLabel: 'Rules',
          pageLead: 'One declared check and the rules in force.',
          pageHeadOwner: {
            page: OWNER,
            label: 'Kind',
            lead: 'One declared check and the rules in force.',
            icon: 'bi-shield-check',
          },
          pageBreadcrumb: [
            { page: HilosPages.DASHBOARD, label: 'Hilos' },
            { page: HilosPages.MODERATION, label: 'Moderation' },
            { page: OWNER, label: 'Kind' },
          ],
          pageTabs: [
            { page: OWNER, label: 'Overview' },
            { page: PAGE, label: 'Rules' },
          ],
          pageChildren: [],
        },
        windows: {
          [RULES]: ruleWindow(rules),
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
                setup: () => () => h(HilosModerationKindRulesPage, { context }),
              }),
            ),
          },
        },
        global: { provide: { [hilosRouterKey as symbol]: navigation } },
      })
    : mount(HilosModerationKindRulesPage, {
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
    ruleAnswer(rules: string[], requestId?: string) {
      receive('table_window', {
        page: PAGE,
        tableKey: RULES,
        ...ruleWindow(rules),
        ...(requestId ? { requestId } : {}),
      })
    },
  }
}

describe('HilosModerationKindRulesPage', () => {
  it('draws one real shared head, owner crumbs/title, Overview and current Rules, and one named table', async () => {
    const { view, navigation, first, update, sent } = await harness()
    expect(view.findAll('h1').map((heading) => heading.text())).toEqual([
      'Profile name',
    ])
    const crumbs = view.get('[data-id="hilos-breadcrumb"]')
    expect(crumbs.get('li:last-child').text()).toBe('Profile name')
    expect(crumbs.text()).not.toContain('Rules')
    const tabs = view.get('[aria-label="Kind pages"]')
    expect(
      tabs
        .findAll('a')
        .map((link) => [
          link.text(),
          link.attributes('href'),
          link.attributes('aria-current'),
        ]),
    ).toEqual([
      ['Overview', '/hilos/moderation/hilos_profile_name', undefined],
      ['Rules', '/hilos/moderation/hilos_profile_name/rules', 'page'],
    ])
    expect(tabs.get('ul').classes()).toContain('flex-nowrap')
    expect(tabs.get('ul').classes()).toContain('overflow-x-auto')
    expect(view.find('[role="tablist"]').exists()).toBe(false)
    expect(view.get('[data-id="hilos-moderation-kind-owner"]').text()).toBe(
      'Framework',
    )
    expect(view.get('[data-id="hilos-moderation-kind-key"]').text()).toBe(
      first.kind,
    )
    expect(view.findAllComponents(HilosViewportTable)).toHaveLength(1)
    const table = view.get('table')
    expect(table.attributes('aria-labelledby')).toBe(
      view.get('h1').attributes('id'),
    )
    expect(view.text()).toContain('Own rule')
    expect(
      view.find('[data-id="hilos-moderation-rules-built-in"]').exists(),
    ).toBe(false)
    expect(
      view
        .find(
          'button[aria-label*="Edit"], button[aria-label*="Delete"], [data-id="hilos-table-main-action"]',
        )
        .exists(),
    ).toBe(false)
    expect(
      view
        .findAll('thead th')
        .map((cell) => cell.text())
        .join(' '),
    ).toContain('Rule')
    expect(view.text()).not.toContain('Add rule')
    expect(sent.filter((frame) => frame.type === 'table_viewport')).toEqual([])
    update({ ...first, title: 'New title', owner: 'project' })
    await nextTick()
    expect(view.get('h1').text()).toBe('New title')
    expect(crumbs.get('li:last-child').text()).toBe('New title')
    expect(navigation.currentTitle.get()).toBe('New title · Chat')
    expect(view.get('[data-id="hilos-moderation-kind-owner"]').text()).toBe(
      'Project',
    )
  })

  it('shows the design empty copy and plain built-in texts only when metadata says no own rules', async () => {
    const { view, first, update } = await harness(
      {
        ownRuleCount: 0,
        defaultRules: ['<script>plain</script>', 'Second rule'],
      },
      { empty: true },
    )
    expect(view.text()).toContain('No rules of your own yet')
    expect(view.text()).toContain(
      'Profile name is checked by its built-in rules below.',
    )
    const builtIn = view.get('[data-id="hilos-moderation-rules-built-in"]')
    expect(builtIn.findAll('li').map((li) => li.text())).toEqual([
      '<script>plain</script>',
      'Second rule',
    ])
    expect(builtIn.find('script').exists()).toBe(false)
    expect(builtIn.text()).toContain('Your first rule replaces them all.')
    update({ ...first, title: 'Renamed kind', defaultRules: [] })
    await nextTick()
    expect(view.text()).toContain(
      'Renamed kind is checked by its built-in rules below.',
    )
    expect(builtIn.findAll('li')).toHaveLength(0)
    update({ ...first, ownRuleCount: 2 })
    await nextTick()
    expect(
      view.find('[data-id="hilos-moderation-rules-built-in"]').exists(),
    ).toBe(false)
  })

  it.each([0, 2])(
    'keeps Nothing found separate from built-ins when metadata ownRuleCount is %s',
    async (ownRuleCount) => {
      const { view, ruleAnswer, sent } = await harness({ ownRuleCount })
      const { controller } = view.getComponent(HilosViewportTable).props() as {
        controller: TableViewportController<HilosModerationRuleRow>
      }
      controller.setSearch('no-match')
      const requestId = String(
        sent.filter((frame) => frame.type === 'table_viewport').at(-1)
          ?.requestId,
      )
      ruleAnswer([], requestId)
      await nextTick()
      expect(view.text()).toContain('Nothing found')
      expect(view.text()).not.toContain('No rules of your own yet')
      expect(
        view.find('[data-id="hilos-moderation-rules-built-in"]').exists(),
      ).toBe(ownRuleCount === 0)
    },
  )

  it('keeps retained rules beside the independently updated built-in policy', async () => {
    const { view, first, update, receive } = await harness()
    receive('table_viewport_delta', {
      page: PAGE,
      tableKey: RULES,
      kind: 'row_removed',
      rowKey: '1',
      reason: 'deleted',
    })
    update({ ...first, ownRuleCount: 0 })
    await nextTick()
    expect(view.text()).toContain('Own rule')
    expect(
      view.find('[data-id="hilos-moderation-rules-built-in"]').exists(),
    ).toBe(true)
    expect(
      (
        view.getComponent(HilosViewportTable).props() as {
          controller: TableViewportController<HilosModerationRuleRow>
        }
      ).controller.pendingCount.get(),
    ).toBe(1)
  })

  it('uses loading until both own windows arrive and clears stale route rows and policy', async () => {
    const { view, answer, navigation, first } = await harness(
      { ownRuleCount: 0 },
      { pending: true, empty: true },
    )
    expect(
      view.get('[data-id="hilos-moderation-rules-loading"]').attributes('role'),
    ).toBe('status')
    expect(
      view.find('[data-id="hilos-moderation-rules-built-in"]').exists(),
    ).toBe(false)
    expect(view.text()).not.toContain('No rules of your own yet')
    answer()
    await nextTick()
    expect(
      view.find('[data-id="hilos-moderation-rules-loading"]').exists(),
    ).toBe(false)
    navigation.navigate('/hilos/moderation/second/rules')
    await nextTick()
    expect(
      view.find('[data-id="hilos-moderation-rules-loading"]').exists(),
    ).toBe(true)
    expect(view.text()).not.toContain(first.title)
    expect(
      view.find('[data-id="hilos-moderation-rules-built-in"]').exists(),
    ).toBe(false)
  })

  it.each([TABLE, RULES])(
    'shows %s refusal and freeze through the stock accessible surfaces',
    async (tableKey) => {
      const { view, receive, answer } = await harness(
        { ownRuleCount: 0 },
        { empty: true },
      )
      receive('table_viewport_frozen', { page: PAGE, tableKey, since: 1000 })
      await nextTick()
      expect(view.text()).toContain('has not updated')
      receive('table_window_refused', {
        page: PAGE,
        tableKey,
        errorCode: 'unavailable',
      })
      await nextTick()
      expect(
        view.get('[data-id="hilos-table-unavailable"]').attributes('role'),
      ).toBe('status')
      expect(view.text()).not.toContain('No rules of your own yet')
      expect(
        view.find('[data-id="hilos-moderation-rules-built-in"]').exists(),
      ).toBe(false)
      answer()
      await nextTick()
      expect(view.find('[data-id="hilos-table-unavailable"]').exists()).toBe(
        false,
      )
      expect(view.find('[data-id="hilos-table-frozen"]').exists()).toBe(false)
    },
  )

  it('uses the SDK text cells and cards for long plain rules without inline styles', async () => {
    const { view, ruleAnswer } = await harness({ kind: 'long_key_'.repeat(50) })
    const long = '<b>plain</b> long rule '.repeat(200)
    ruleAnswer([long])
    await nextTick()
    expect(view.get('tbody').text()).toContain(long.trim())
    expect(view.get('tbody').find('b').exists()).toBe(false)
    expect(view.findAll('.hilos-table-text').length).toBeGreaterThan(0)
    expect(
      view.get('[data-id="hilos-moderation-kind-key"]').classes(),
    ).toContain('text-break')
    for (const element of view.findAll('[style]')) {
      const style = (element.element as HTMLElement).style
      expect(
        Array.from(style).every((property) => property.startsWith('--hilos-')),
      ).toBe(true)
    }
  })

  it('lets the real outlet replace an unknown kind with the ordinary 404', async () => {
    const { view, receive } = await harness({}, { outlet: true })
    receive('subscription_page_error', {
      page: PAGE,
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Kind not found',
      message: 'This kind does not exist.',
    })
    await nextTick()
    expect(view.text()).toContain('Kind not found')
    expect(view.findComponent(HilosViewportTable).exists()).toBe(false)
    expect(view.find('[aria-label="Kind pages"]').exists()).toBe(false)
  })
})
