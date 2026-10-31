import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, markRaw, nextTick, type Component } from 'vue'
import {
  bindAccessReaction,
  bindPageScope,
  bindSessionScope,
  createAppPageRouter,
  createHilosConnection,
  createHilosRouter,
  HilosPages,
  ScopeManager,
  sessionAdminViewMode,
  sessionUserId,
  sessionUserIsAdmin,
  type HilosLegalContext,
  type TableRow,
  type TableSortOrder,
  type TableViewportController,
  type WebSocketLike,
} from '@hilos/core/base'
import HilosView from '../src/HilosView.vue'
import HilosViewportTable from '../src/HilosViewportTable.vue'
import HilosLegalDocumentSetPage from '../src/admin/legal/HilosLegalDocumentSetPage.vue'
import HilosLegalDocumentDeviationsPage from '../src/admin/legal/HilosLegalDocumentDeviationsPage.vue'
import HilosLegalDocumentRevisionsPage from '../src/admin/legal/HilosLegalDocumentRevisionsPage.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'
import {
  clauseRows,
  deviationRows,
  revisionRows,
  legalDocument,
} from './legalDocumentFixture.js'

interface TableCase {
  component: Component
  page: string
  tableKey: string
  rows: TableRow[]
  sort: TableSortOrder
  field: string
}
const cases: TableCase[] = [
  {
    component: HilosLegalDocumentSetPage,
    page: HilosPages.LEGAL_DOCUMENT_SET,
    tableKey: 'hilosLegalDocumentSet',
    rows: clauseRows,
    sort: [],
    field: 'clauseKey',
  },
  {
    component: HilosLegalDocumentDeviationsPage,
    page: HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
    tableKey: 'hilosLegalDocumentDeviations',
    rows: deviationRows,
    sort: [],
    field: 'direction',
  },
  {
    component: HilosLegalDocumentRevisionsPage,
    page: HilosPages.LEGAL_DOCUMENT_REVISIONS,
    tableKey: 'hilosLegalRevisions',
    rows: revisionRows,
    sort: [{ field: 'publishedOn', direction: 'desc' }],
    field: 'effectiveOn',
  },
]
interface WindowMetadata {
  sort?: TableSortOrder
  totalCount?: number
  rowsBefore?: number
}

/** Only the socket is scripted; session, access, outlet and table bindings are real. */
function accessWindowHarness(admin: boolean, table: TableCase) {
  const listeners = new Map<
    string,
    Array<(event: { data?: unknown }) => void>
  >()
  const frames: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send: (data) => frames.push(JSON.parse(String(data))),
    close() {},
    addEventListener(type, listener) {
      listeners.set(type, [...(listeners.get(type) ?? []), listener])
    },
  }
  const emit = (event: string, data: { data?: unknown } = {}) => {
    for (const listener of listeners.get(event) ?? []) listener(data)
  }
  const project = (type: string, data: unknown) =>
    emit('message', { data: JSON.stringify({ type, data }) })
  const { connection } = createHilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
  })
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes)
  const pages = bindPageScope(connection, scopes)
  const routes = createAppPageRouter({})
  const { page, tableKey, component } = table
  let path = routes.path(page, { documentKey: 'terms' })!
  const router = createHilosRouter(routes, pages, {
    pathname: () => path,
    pushState: (value) => {
      path = value
    },
    replaceState: (value) => {
      path = value
    },
    onPopState: () => () => {},
  })
  const stopAccess = bindAccessReaction(
    router,
    sessionUserIsAdmin(scopes),
    sessionUserId(scopes),
    sessionAdminViewMode(scopes),
  )
  const session = (isAdmin: boolean) => {
    project('handshake_response', {
      entities: { currentUser: { id: 7, admin: isAdmin } },
      data: { adminViewMode: true },
    })
    pages.releaseOnSession()
  }
  const context = markRaw({
    connection,
    scopes,
    actions: { dispatch: vi.fn() },
  } as unknown as HilosLegalContext)
  connection.connect()
  emit('open')
  session(admin)
  router.start()
  const view = mount(HilosView, {
    props: {
      pages: {
        [page]: markRaw(defineComponent(() => () => h(component, { context }))),
      },
    },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  const window = (
    rows: readonly TableRow[],
    metadata: WindowMetadata = {},
  ) => ({
    rows,
    totalCount: rows.length,
    totalExact: true,
    limit: 25,
    sort: table.sort,
    firstAnchor: rows[0] ? { rowKey: rows[0].rowKey } : null,
    lastAnchor: rows.at(-1) ? { rowKey: rows.at(-1)!.rowKey } : null,
    rowsBefore: 0,
    ...metadata,
  })
  return {
    view,
    frames,
    scopes,
    connection,
    pages,
    session,
    tableKey,
    context,
    router,
    answer(rows: readonly TableRow[], metadata: WindowMetadata = {}) {
      project('page_response', {
        page,
        payload: {
          data: { legalDocument },
          windows: { [tableKey]: window(rows, metadata) },
        },
      })
    },
    refuse() {
      project('page_response', {
        page,
        payload: {
          data: { legalDocument },
          refusedWindows: { [tableKey]: { errorCode: 'internal_error' } },
        },
      })
    },
    window(rows: readonly TableRow[], metadata: WindowMetadata = {}) {
      project('table_window', {
        page,
        tableKey,
        ...window(rows, metadata),
        requestId: frames.at(-1)?.requestId,
      })
    },
    reconnect(admin: boolean) {
      connection.close()
      connection.connect()
      emit('open')
      session(admin)
    },
    dispose() {
      view.unmount()
      stopAccess()
      router.stop()
      pages.unsubscribe()
      connection.close()
    },
  }
}

describe.each(cases)('Legal held $tableKey after access re-answer', (table) => {
  it.each([false, true])(
    'restores search after a real remount (admin: %s)',
    async (admin) => {
      const test = accessWindowHarness(admin, table)
      try {
        test.answer(table.rows)
        await nextTick()
        const previous = test.view.getComponent(table.component).vm
        await test.view
          .get('input[type="search"]')
          .setValue('no matching clause')
        test.window([])
        await nextTick()
        expect(
          test.view.get('[data-id="hilos-table-no-matches"]').text(),
        ).toContain('no matching clause')
        const before = test.frames.length

        test.session(!admin)
        await nextTick()
        expect(test.view.findComponent(table.component).exists()).toBe(false)
        expect(test.view.find('input[type="search"]').exists()).toBe(false)
        expect(
          test.scopes.page()?.data.signal('legalDocument').get(),
        ).toBeUndefined()
        expect(test.scopes.page()?.tables.signal(test.tableKey).get()).toEqual(
          [],
        )
        expect(test.frames).toHaveLength(before)

        test.answer([])
        await nextTick()
        expect(test.view.getComponent(table.component).vm === previous).toBe(
          false,
        )
        expect(
          (test.view.get('input[type="search"]').element as HTMLInputElement)
            .value,
        ).toBe('no matching clause')
        expect(
          test.view.get('[data-id="hilos-table-no-matches"]').text(),
        ).toContain('no matching clause')
        expect(
          test.connection.tableWindowDescriptors()[test.tableKey]?.filter,
        ).toEqual({
          document: 'terms',
          search: 'no matching clause',
        })
        expect(test.frames.slice(before).map(({ type }) => type)).toEqual([
          'table_rendered',
        ])
        expect(test.context.actions.dispatch).not.toHaveBeenCalled()

        await test.view.get('input[type="search"]').setValue('retention')
        expect(test.frames.at(-1)).toMatchObject({
          type: 'table_viewport',
          filter: { document: 'terms', search: 'retention' },
        })
        test.window(table.rows)
        await nextTick()
        expect(
          test.view.findAll('tbody tr[data-id^="hilos-table-row-"]'),
        ).toHaveLength(table.rows.length)
      } finally {
        test.dispose()
      }
    },
  )

  it.each([false, true])(
    'keeps order, page and reconnect scope after remount (admin: %s)',
    async (admin) => {
      const test = accessWindowHarness(admin, table)
      const order: TableSortOrder = [{ field: table.field, direction: 'desc' }]
      const metadata = { totalCount: 75, rowsBefore: 50, sort: order }
      try {
        test.answer(table.rows, { totalCount: 75 })
        await nextTick()
        await test.view.get('input[type="search"]').setValue('retention')
        test.window(table.rows, { totalCount: 75 })
        const sort = `[data-id="hilos-table-sort-${table.field}"]`
        await test.view.get(sort).trigger('click')
        await test.view.get(sort).trigger('click')
        test.window(table.rows, { totalCount: 75 })
        await nextTick()
        await test.view.get('[data-id="hilos-table-page-3"]').trigger('click')
        test.window(table.rows, metadata)
        await nextTick()
        const held = test.connection.tableWindowDescriptors()[test.tableKey]
        expect(held).toMatchObject({
          filter: { document: 'terms', search: 'retention' },
          sort: order,
          pageIndex: 2,
        })
        const before = test.frames.length
        test.session(!admin)
        await nextTick()
        expect(test.view.findComponent(table.component).exists()).toBe(false)
        expect(test.view.text()).not.toContain('retention')
        expect(test.frames).toHaveLength(before)
        test.answer(table.rows, metadata)
        await nextTick()

        expect(test.view.get('[aria-current="page"]').text()).toBe('3')
        expect(
          test.view.get(sort).element.parentElement?.getAttribute('aria-sort'),
        ).toBe('descending')
        expect(
          (test.view.get('input[type="search"]').element as HTMLInputElement)
            .value,
        ).toBe('retention')
        expect(test.connection.tableWindowDescriptors()[test.tableKey]).toEqual(
          held,
        )
        expect(test.frames.slice(before).map(({ type }) => type)).toEqual([
          'table_rendered',
        ])

        test.reconnect(!admin)
        expect(test.frames.at(-1)).toMatchObject({
          type: 'page_subscribe',
          tableWindows: { [test.tableKey]: held },
        })
        test.answer(table.rows, metadata)
        await nextTick()
        await test.view.get('[data-id="hilos-table-prev"]').trigger('click')
        expect(test.frames.at(-1)).toMatchObject({
          type: 'table_viewport',
          filter: held!.filter,
          sort: order,
          anchorDirection: 'before',
          anchor: { rowKey: table.rows[0]!.rowKey },
        })
        test.window(table.rows, { totalCount: 75, rowsBefore: 25 })
        await nextTick()
        expect(test.view.get('[aria-current="page"]').text()).toBe('2')
        // The next click returns to the original server opening order, even though
        // the replacement's first answer arrived in the reader's chosen order.
        await test.view.get(sort).trigger('click')
        expect(test.frames.at(-1)).toMatchObject({
          type: 'table_viewport',
          filter: held!.filter,
          ...(table.sort.length > 0 ? { sort: table.sort } : {}),
          anchor: null,
          anchorDirection: 'after',
        })
        expect(
          test.connection.tableWindowDescriptors()[test.tableKey]?.sort,
        ).toEqual(table.sort.length > 0 ? table.sort : null)
      } finally {
        test.dispose()
      }
    },
  )

  it('starts the opening preset when navigation replaces an access wait', async () => {
    const test = accessWindowHarness(false, table)
    try {
      test.answer(table.rows)
      await nextTick()
      await test.view.get('input[type="search"]').setValue('old search')
      test.window([])
      test.session(true)
      await nextTick()
      expect(test.view.findComponent(table.component).exists()).toBe(false)
      test.router.navigate(test.router.currentPath.get())
      expect(test.frames.at(-1)).toEqual({
        type: 'page_subscribe',
        page: table.page,
        params: { documentKey: 'terms' },
      })
      const before = test.frames.length
      test.answer(table.rows)
      await nextTick()
      expect(
        (test.view.get('input[type="search"]').element as HTMLInputElement)
          .value,
      ).toBe('')
      expect(
        test.connection.tableWindowDescriptors()[test.tableKey]?.filter,
      ).toEqual({ document: 'terms' })
      expect(test.frames.slice(before).map(({ type }) => type)).toEqual([
        'table_rendered',
      ])
    } finally {
      test.dispose()
    }
  })

  it('preserves known controls when the replacement receives a refused window', async () => {
    const test = accessWindowHarness(false, table)
    try {
      test.answer(table.rows)
      await nextTick()
      await test.view.get('input[type="search"]').setValue('retention')
      test.window(table.rows)
      const held = test.connection.tableWindowDescriptors()[test.tableKey]
      const before = test.frames.length
      test.session(true)
      await nextTick()
      expect(test.view.findComponent(table.component).exists()).toBe(false)
      test.refuse()
      await nextTick()
      expect(
        test.view.find('[data-id="hilos-table-unavailable"]').exists(),
      ).toBe(true)
      expect(
        (test.view.get('input[type="search"]').element as HTMLInputElement)
          .value,
      ).toBe('retention')
      expect(test.connection.tableWindowDescriptors()[test.tableKey]).toEqual(
        held,
      )
      expect(test.frames).toHaveLength(before)
      test.answer(table.rows)
      await nextTick()
      expect(
        test.view.find('[data-id="hilos-table-unavailable"]').exists(),
      ).toBe(false)
      expect(test.connection.tableWindowDescriptors()[test.tableKey]).toEqual(
        held,
      )
      expect(test.frames).toHaveLength(before)
    } finally {
      test.dispose()
    }
  })

  it('keeps an unknown refused window unknown through the access wait', async () => {
    const test = accessWindowHarness(false, table)
    try {
      test.refuse()
      await nextTick()
      expect(
        test.view.find('[data-id="hilos-table-unavailable"]').exists(),
      ).toBe(true)
      expect(test.connection.tableWindowDescriptors()).toEqual({})
      const search = test.view.get('input[type="search"]')
      expect((search.element as HTMLInputElement).disabled).toBe(true)
      const { controller } = test.view
        .getComponent(HilosViewportTable)
        .props() as {
        controller: TableViewportController<unknown>
      }
      controller.setSearch('no matching clause')
      controller.setFilter('document', 'privacy')
      controller.setSort(table.field)
      controller.setPage(2)
      expect(controller.filter.get()).toEqual({ document: 'terms' })
      expect(controller.search.get()).toBe('')
      expect((search.element as HTMLInputElement).value).toBe('')
      expect(controller.order.get()).toBeUndefined()
      expect(
        test.frames.filter(({ type }) => type === 'table_viewport'),
      ).toEqual([])
      const before = test.frames.length
      test.session(true)
      await nextTick()
      expect(test.view.findComponent(table.component).exists()).toBe(false)
      expect(test.connection.tableWindowDescriptors()).toEqual({})
      test.answer(table.rows)
      await nextTick()
      expect(
        test.view.find('[data-id="hilos-table-unavailable"]').exists(),
      ).toBe(false)
      expect(
        test.connection.tableWindowDescriptors()[table.tableKey],
      ).toMatchObject({
        filter: { document: 'terms' },
        limit: 25,
        sort: table.sort.length ? table.sort : null,
      })
      expect(test.frames.slice(before).map(({ type }) => type)).toEqual([
        'table_rendered',
      ])
      expect(
        (test.view.get('input[type="search"]').element as HTMLInputElement)
          .value,
      ).toBe('')
      expect(
        (test.view.get('input[type="search"]').element as HTMLInputElement)
          .disabled,
      ).toBe(false)
      await test.view.get('input[type="search"]').setValue('retention')
      expect(test.frames.at(-1)).toMatchObject({
        type: 'table_viewport',
        filter: { document: 'terms', search: 'retention' },
        limit: 25,
      })
    } finally {
      test.dispose()
    }
  })

  it('reopens a cold refused active link without sending unknown controls', async () => {
    const test = accessWindowHarness(false, table)
    try {
      test.refuse()
      await nextTick()
      const previous = test.view.getComponent(table.component).vm
      const search = test.view.get('input[type="search"]')
      expect((search.element as HTMLInputElement).disabled).toBe(true)
      const { controller } = test.view
        .getComponent(HilosViewportTable)
        .props() as {
        controller: TableViewportController<unknown>
      }
      controller.setSearch('no matching clause')
      controller.setFilter('document', 'privacy')
      controller.setSort(table.field)
      controller.setPage(2)
      expect(controller.filter.get()).toEqual({ document: 'terms' })
      expect(controller.search.get()).toBe('')
      expect((search.element as HTMLInputElement).value).toBe('')
      expect(controller.order.get()).toBeUndefined()
      expect(test.connection.tableWindowDescriptors()).toEqual({})
      expect(
        test.frames.filter(({ type }) => type === 'table_viewport'),
      ).toEqual([])
      test.router.navigate(test.router.currentPath.get())
      await nextTick()
      expect(test.frames.at(-1)).toEqual({
        type: 'page_subscribe',
        page: table.page,
        params: { documentKey: 'terms' },
      })
      const before = test.frames.length
      test.answer(table.rows)
      await nextTick()
      expect(test.view.getComponent(table.component).vm === previous).toBe(
        false,
      )
      expect(
        test.connection.tableWindowDescriptors()[test.tableKey],
      ).toMatchObject({
        filter: { document: 'terms' },
        limit: 25,
        sort: table.sort.length ? table.sort : null,
      })
      expect(
        test.view
          .findAll('tbody tr[data-id^="hilos-table-row-"]')
          .map((row) => row.attributes('data-id')),
      ).toEqual(table.rows.map(({ rowKey }) => `hilos-table-row-${rowKey}`))
      expect(
        (test.view.get('input[type="search"]').element as HTMLInputElement)
          .value,
      ).toBe('')
      expect(test.frames.slice(before).map(({ type }) => type)).toEqual([
        'table_rendered',
      ])
      await test.view.get('input[type="search"]').setValue('retention')
      expect(test.frames.at(-1)).toMatchObject({
        type: 'table_viewport',
        filter: { document: 'terms', search: 'retention' },
        limit: 25,
      })
    } finally {
      test.dispose()
    }
  })
})
