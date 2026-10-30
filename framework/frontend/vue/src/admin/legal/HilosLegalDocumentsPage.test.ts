import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  HilosPages,
  resolveHilosPath,
  ScopeManager,
  type HilosLegalContext,
  type HilosPageIdentity,
  type HilosRouter,
  type TableRow,
  type TableViewportController,
} from '@hilos/core/base'
import HilosLegalDocumentsPage from './HilosLegalDocumentsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const revision = {
  revisionId: 'current',
  publishedOn: '2026-09-27',
  effectiveOn: '2026-10-01',
  significance: 'substantial',
  setVersion: 1,
  deviationCount: 0,
}
const rows: TableRow[] = [
  {
    rowKey: 'terms',
    slots: {
      document: {
        documentLabel: 'Server terms label',
        publishedOn: '2026-09-27',
        declared: true,
        revision,
        covered: 4,
        window: 2,
        lapsed: 1,
      },
    },
  },
  {
    rowKey: 'retired',
    slots: {
      document: {
        documentLabel: 'retired',
        publishedOn: null,
        declared: false,
        revision: null,
        covered: 0,
        window: 0,
        lapsed: 0,
      },
    },
  },
]

/** Real carriers and table renderer; the connection replays the first page answer. */
function harness(
  policy: unknown = 'freeze',
  refusal: string | null = null,
  documents = rows,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.LEGAL_DOCUMENTS)
  scope.data.set('legalCatalogRefusal', refusal)
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const registered = new Set<string>()
  const controllers = new Map<string, TableViewportController<unknown>>()
  const sendViewport = vi.fn()
  const sendRendered = vi.fn()
  const dispatch = vi.fn()
  const answer = (value: unknown, documentRows = documents) => ({
    type: 'page_response',
    data: {
      page: 'hilos_legal_documents',
      payload: {
        windows: {
          hilosLegalDocuments: {
            rows: documentRows,
            totalCount: documentRows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field: 'publishedOn', direction: 'desc' }],
          },
          hilosLegalSettings: {
            rows: [
              {
                rowKey: 'legal.refusal_after_deadline',
                slots: { setting: { value, defaultValue: value } },
              },
            ],
            totalCount: 1,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: null,
          },
        },
      },
    },
  })
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (frame: never) => void) {
        const group = listeners.get(event) ?? new Set()
        listeners.set(event, group)
        group.add(listener)
        if (event === 'projectSignal') listener(answer(policy) as never)
        return () => group.delete(listener)
      },
      registerTableWindow(
        key: string,
        controller: TableViewportController<unknown>,
      ) {
        registered.add(key)
        controllers.set(key, controller)
      },
      unregisterTableWindow(key: string) {
        registered.delete(key)
      },
      sendTableViewport: sendViewport,
      sendTableRendered: sendRendered,
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext
  const router = {
    currentRoute: createSignal({
      page: HilosPages.LEGAL_DOCUMENTS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/legal/documents'),
    pageIdentity: createSignal<HilosPageIdentity>({
      label: 'Documents',
      lead: '',
      children: [],
      breadcrumb: [],
    }),
    resolvePath: resolveHilosPath,
  } as unknown as HilosRouter
  const view = mount(HilosLegalDocumentsPage, {
    props: { context: markRaw(context) },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  return {
    view,
    registered,
    controllers,
    sendViewport,
    sendRendered,
    dispatch,
    scope,
    policy(value: unknown) {
      for (const listener of listeners.get('projectSignal') ?? [])
        listener(answer(value) as never)
    },
    listenerCount: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
  }
}

describe('Legal Documents with the real table frame', () => {
  it('renders the first server window, search, counts, links and opening order without a mount request', async () => {
    const h = harness()
    try {
      await nextTick()
      expect(h.registered).toEqual(
        new Set(['hilosLegalDocuments', 'hilosLegalSettings']),
      )
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(
        h.sendRendered.mock.calls.map(([page, key]) => [page, key]),
      ).toEqual([
        ['hilos_legal_documents', 'hilosLegalSettings'],
        ['hilos_legal_documents', 'hilosLegalDocuments'],
      ])
      expect(h.view.findAll('h1').map((heading) => heading.text())).toEqual([
        'Documents',
      ])
      const table = h.view.get('table')
      expect(h.view.findAll('table')).toHaveLength(1)
      expect(table.attributes('aria-labelledby')).toBe(
        h.view.get('h1').attributes('id'),
      )
      expect(h.view.find('input[aria-label="Document or key"]').exists()).toBe(
        true,
      )
      expect(
        table
          .findAll('th[aria-sort]')
          .map((header) => [header.text(), header.attributes('aria-sort')]),
      ).toEqual([
        ['Document', 'none'],
        ['Revision', 'descending'],
        ['Covered', 'none'],
        ['In the window', 'none'],
        ['Frozen', 'none'],
      ])
      const body = table.findAll('tbody tr')
      expect(
        body.map((row) => row.get('[data-id="legal-document-row"]').text()),
      ).toEqual(['Server terms label', 'retired'])
      expect(body[0]!.text()).toContain('2026-09-27 · substantial')
      expect(body[0]!.text()).toContain('Effective from 2026-10-01')
      expect(body[0]!.get('[data-id="legal-count-covered"]').text()).toBe('4')
      expect(body[0]!.get('[data-id="legal-count-window"]').text()).toBe('2')
      expect(
        body[0]!.get('[data-id="legal-count-lapsed-link"]').attributes('href'),
      ).toBe('/hilos/users/terms')
      expect(body[0]!.get('[data-id="legal-count-lapsed-link"]').text()).toBe(
        '1',
      )
      expect(
        body[0]!.get('[data-id="legal-document-open"]').attributes('href'),
      ).toBe('/hilos/legal/document/terms')
      expect(body[1]!.text()).toContain('Not in code')
      expect(
        body[1]!.find('[data-id="legal-count-lapsed-link"]').exists(),
      ).toBe(false)
      expect(body[1]!.get('[data-id="legal-count-lapsed"]').text()).toBe('0')
      expect(
        body[1]!.get('[data-id="legal-document-open"]').attributes('href'),
      ).toBe('/hilos/legal/document/retired')
      expect(table.text()).not.toContain('Freeze access')
      await table.get('th[aria-sort] button').trigger('click')
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        'hilos_legal_documents',
        'hilosLegalDocuments',
        expect.objectContaining({
          sort: [{ field: 'documentLabel', direction: 'asc' }],
        }),
      )
    } finally {
      h.view.unmount()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
    for (const controller of h.controllers.values())
      expect(controller.rows.get()).toEqual([])
  })

  it.each([
    ['document', '/hilos/legal/document/document'],
    ['documents', '/hilos/legal/document/documents'],
    ['acceptances', '/hilos/legal/document/acceptances'],
    ['settings', '/hilos/legal/document/settings'],
    ['terms /?#%', '/hilos/legal/document/terms%20%2F%3F%23%25'],
    ['terms%2Fprivacy', '/hilos/legal/document/terms%252Fprivacy'],
  ])(
    'opens the actual row key %s through the shared resolver',
    async (key, path) => {
      const h = harness('freeze', null, [{ ...rows[1]!, rowKey: key }])
      try {
        await nextTick()
        const open = h.view.get('[data-id="legal-document-open"]')
        expect(open.attributes('data-document')).toBe(key)
        expect(open.attributes('href')).toBe(path)
      } finally {
        h.view.unmount()
      }
    },
  )

  it('links the past-deadline count to the people it counts, only when there are any (HIL-945)', async () => {
    const documents: TableRow[] = [
      {
        rowKey: 'terms',
        slots: {
          document: {
            documentLabel: 'Terms of use',
            publishedOn: '2026-09-27',
            declared: true,
            revision,
            covered: 1,
            window: 0,
            lapsed: 2,
          },
        },
      },
      {
        rowKey: 'privacy',
        slots: {
          document: {
            documentLabel: 'Privacy policy',
            publishedOn: '2026-09-27',
            declared: true,
            revision,
            covered: 3,
            window: 0,
            lapsed: 0,
          },
        },
      },
    ]
    const h = harness('freeze', null, documents)
    try {
      await nextTick()
      const body = h.view.get('table').findAll('tbody tr')
      expect(
        body[0]!.get('[data-id="legal-count-lapsed-link"]').attributes('href'),
      ).toBe('/hilos/users/terms')
      expect(body[0]!.get('[data-id="legal-count-lapsed"]').text()).toBe('2')
      expect(
        body[1]!.find('[data-id="legal-count-lapsed-link"]').exists(),
      ).toBe(false)
      expect(body[1]!.get('[data-id="legal-count-lapsed"]').text()).toBe('0')
    } finally {
      h.view.unmount()
    }
  })

  it('reacts to remind and hidden policy in the header without putting policy words in cells', async () => {
    const h = harness('remind')
    try {
      for (const [value, label] of [
        ['freeze', 'Frozen'],
        ['remind', 'Past deadline'],
        [{ _hidden: true }, 'Past deadline'],
      ] as const) {
        h.policy(value)
        await nextTick()
        expect(
          h.view.get('table').findAll('th[aria-sort]').at(-1)?.text(),
        ).toBe(label)
        expect(h.view.get('tbody').text()).not.toContain(label)
      }
      expect(h.sendViewport).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
  })

  it('uses the standard empty state', async () => {
    const h = harness('freeze', null, [])
    try {
      await nextTick()
      expect(h.view.text()).toContain(
        'The project has not declared any documents.',
      )
      expect(h.sendViewport).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
  })

  it('shows one catalog refusal as text in place of the table and disposes both carriers', async () => {
    const h = harness('freeze', '<b>Broken legal catalog</b>')
    await nextTick()
    expect(h.view.findAll('[role="alert"]')).toHaveLength(1)
    expect(h.view.get('[role="alert"]').text()).toContain(
      '<b>Broken legal catalog</b>',
    )
    expect(h.view.get('[role="alert"]').find('b').exists()).toBe(false)
    expect(h.view.find('table').exists()).toBe(false)
    h.view.unmount()
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })
})
