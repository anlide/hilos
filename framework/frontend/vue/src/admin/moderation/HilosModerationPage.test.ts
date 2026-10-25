import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  HilosPages,
  ScopeManager,
  createSignal,
  type HilosConnection,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
  type TableRow,
} from '@hilos/core/base'
import {
  HILOS_MODERATION_COPY,
  HILOS_MODERATION_KINDS_TABLE,
  HILOS_MODERATION_KIND_SLOT,
  type HilosModerationContext,
} from '@hilos/core/admin/moderation'

import HilosModerationPage from './HilosModerationPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function router(pageIdentityData?: HilosPageIdentity): HilosRouter {
  const identity: HilosPageIdentity = pageIdentityData ?? {
    label: 'Moderation',
    lead: 'What is checked before it is shown, and by which rules.',
    breadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.MODERATION, label: 'Moderation' },
    ],
    children: [],
    pageHeadOwner: {
      page: HilosPages.MODERATION,
      label: 'Moderation',
      icon: 'bi-shield-check',
      lead: 'What is checked before it is shown, and by which rules.',
    },
    pageTabs: [],
  }

  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.MODERATION,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/moderation'),
    currentTitle: createSignal('Moderation'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: (pageKey) =>
      pageKey === HilosPages.DASHBOARD
        ? '/hilos'
        : pageKey === HilosPages.MODERATION
          ? '/hilos/moderation'
          : undefined,
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

function seededContext(customRows?: TableRow[]): {
  context: HilosModerationContext
  registered: Set<string>
  viewports: unknown[][]
  rendered: unknown[][]
  serveWindow: (tableKey?: string, rows?: TableRow[]) => void
} {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MODERATION)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const registered = new Set<string>()
  const viewports: unknown[][] = []
  const rendered: unknown[][] = []

  const defaultRows: TableRow[] = [
    {
      rowKey: 'hilos_profile_name',
      slots: {
        [HILOS_MODERATION_KIND_SLOT]: {
          kind: 'hilos_profile_name',
          title: 'Profile name',
          owner: 'framework',
          input: 'text',
          model: 'qwen2.5:3b',
          ownRuleCount: 2,
        },
      },
    },
    {
      rowKey: 'hilos_profile_photo',
      slots: {
        [HILOS_MODERATION_KIND_SLOT]: {
          kind: 'hilos_profile_photo',
          title: 'Profile photo',
          owner: 'framework',
          input: 'image',
          model: { _hidden: true },
          ownRuleCount: 0,
        },
      },
    },
    {
      rowKey: 'chat_message',
      slots: {
        [HILOS_MODERATION_KIND_SLOT]: {
          kind: 'chat_message',
          title: 'Chat message',
          owner: 'project',
          input: 'text_with_files',
          model: 'qwen2.5:3b',
          ownRuleCount: 1,
        },
      },
    },
  ]

  const serveWindow = (
    tableKey: string = HILOS_MODERATION_KINDS_TABLE,
    rowsToServe: TableRow[] = customRows ?? defaultRows,
  ): void => {
    const data = {
      page: HilosPages.MODERATION,
      tableKey,
      rows: rowsToServe,
      totalCount: rowsToServe.length,
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
      registered.add(tableKey)
      serveWindow(tableKey)
    },
    unregisterTableWindow(tableKey: string): void {
      registered.delete(tableKey)
    },
    tableWindowDescriptors: () => ({}),
    sendTableViewport(
      page: string,
      tableKey: string,
      descriptor: unknown,
    ): boolean {
      viewports.push([page, tableKey, descriptor])
      serveWindow(tableKey)

      return true
    },
    sendTableRendered(...args: unknown[]): boolean {
      rendered.push(args)

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }

      return () => {}
    },
  } as unknown as HilosConnection

  return {
    context: {
      connection,
      scopes,
    },
    registered,
    viewports,
    rendered,
    serveWindow,
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
})

async function mountPage(
  context: HilosModerationContext,
  pageIdentityData?: HilosPageIdentity,
): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(HilosModerationPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: {
      provide: {
        [hilosRouterKey as symbol]: router(pageIdentityData),
      },
    },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

describe('HilosModerationPage', () => {
  it('renders the shared head with single h1, section owner and no tabs or CRUD actions', async () => {
    const { context } = seededContext()
    const wrapper = await mountPage(context)

    const headings = wrapper.findAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].text()).toBe('Moderation')

    expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-head-action"]').exists()).toBe(
      false,
    )
    const crudButtons = wrapper
      .findAll('button')
      .filter((button) => /create|add|new|delete/i.test(button.text()))
    expect(crudButtons).toHaveLength(0)
  })

  it('renders table rows with kind code, declared by badge, model name and rule counts', async () => {
    const { context } = seededContext()
    const wrapper = await mountPage(context)

    expect(
      wrapper.find('[data-id="hilos-moderation-kinds-table"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-id="hilos-moderation-kinds-table"] table').exists(),
    ).toBe(true)

    // Row 1: Profile name (framework, text model, 2 rules)
    const nameCode = wrapper.find(
      'table code[data-id="hilos-moderation-row-hilos_profile_name"]',
    )
    expect(nameCode.text()).toBe('hilos_profile_name')
    expect(wrapper.text()).toContain('Profile name')
    expect(wrapper.text()).toContain(HILOS_MODERATION_COPY.framework)
    expect(wrapper.text()).toContain('Text model')
    expect(wrapper.text()).toContain('qwen2.5:3b')
    expect(wrapper.text()).toContain('2 rules')

    const openName = wrapper.find(
      'table a[data-id="hilos-moderation-open-hilos_profile_name"]',
    )
    expect(openName.attributes('href')).toBe(
      '/hilos/moderation/hilos_profile_name',
    )
    expect(openName.attributes('aria-label')).toBe('Open Profile name')
    expect(openName.text()).toBe(HILOS_MODERATION_COPY.open)

    // Row 2: Profile photo (framework, image/vision model, hidden model value, 0 rules / Built-in)
    const photoCode = wrapper.find(
      'table code[data-id="hilos-moderation-row-hilos_profile_photo"]',
    )
    expect(photoCode.text()).toBe('hilos_profile_photo')
    expect(wrapper.text()).toContain('Profile photo')
    expect(wrapper.text()).toContain('Vision model')
    expect(wrapper.find('[data-id="hilos-hidden"]').exists()).toBe(true)
    expect(wrapper.text()).toContain(HILOS_MODERATION_COPY.builtIn)

    const openPhoto = wrapper.find(
      'table a[data-id="hilos-moderation-open-hilos_profile_photo"]',
    )
    expect(openPhoto.attributes('href')).toBe(
      '/hilos/moderation/hilos_profile_photo',
    )
    expect(openPhoto.attributes('aria-label')).toBe('Open Profile photo')
    expect(openPhoto.text()).toBe(HILOS_MODERATION_COPY.open)

    // Row 3: Chat message (project, text model, 1 rule)
    const chatCode = wrapper.find(
      'table code[data-id="hilos-moderation-row-chat_message"]',
    )
    expect(chatCode.text()).toBe('chat_message')
    expect(wrapper.text()).toContain('Chat message')
    expect(wrapper.text()).toContain(HILOS_MODERATION_COPY.project)
    expect(wrapper.text()).toContain('1 rule')

    const openChat = wrapper.find(
      'table a[data-id="hilos-moderation-open-chat_message"]',
    )
    expect(openChat.attributes('href')).toBe('/hilos/moderation/chat_message')
    expect(openChat.attributes('aria-label')).toBe('Open Chat message')
    expect(openChat.text()).toBe(HILOS_MODERATION_COPY.open)
  })

  it('connects table accessible name to the page heading id', async () => {
    const { context } = seededContext()
    const wrapper = await mountPage(context)

    const headingId = wrapper.get('h1').attributes('id')
    expect(headingId).toBeDefined()
    expect(wrapper.get('table').attributes('aria-labelledby')).toBe(headingId)
  })

  it('starts the table window subscription on mount and disposes on unmount', async () => {
    const { context, registered } = seededContext()
    const wrapper = await mountPage(context)

    expect(registered.has(HILOS_MODERATION_KINDS_TABLE)).toBe(true)

    wrapper.unmount()
    expect(registered.has(HILOS_MODERATION_KINDS_TABLE)).toBe(false)
  })
})
