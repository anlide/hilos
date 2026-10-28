import { describe, expect, it } from 'vitest'
import {
  type TableAnchor,
  type TableViewportDescriptor,
} from '../../src/connection/HilosConnection.js'
import { type ActionHandle } from '../../src/connection/actionLifecycle.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { type HilosTableColumn } from '../../src/table/hilosTableColumn.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import { type HilosTableBulkAccepted } from '../../src/table/tableBulk.js'
import { createSignal, subscribeSignal } from '../../src/state/signal.js'
import { hilosTableCard } from '../../src/table/tableCard.js'
import { type HilosTableViews } from '../../src/table/tableView.js'
import {
  HILOS_TABLE_FACET_OPTION_LIMIT,
  type HilosTableFilterOption,
  type HilosTableFrame,
} from '../../src/table/tableFrame.js'

function makeController(
  frame: HilosTableFrame,
  initialFilter?: Record<string, unknown>,
  pageSize = 10,
) {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<TableRow>({
    resolve: (row) => row,
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter,
    frame,
  })

  // The window's size comes with the window since HIL-642, so a test that wants one hands it
  // over the same way the page's answer does.
  const open = (
    rows: readonly TableRow[] = [],
    totalCount = 0,
    totalExact = true,
    firstAnchor: TableAnchor | null = null,
    lastAnchor: TableAnchor | null = null,
  ): void =>
    controller.ingestWindow(
      rows,
      totalCount,
      totalExact,
      firstAnchor,
      lastAnchor,
      pageSize,
    )

  return { controller, sent, open }
}

/**
 * The sender of the declared operation, which these tests never press: what they
 * are about is the state behind the run, not the run itself.
 */
function neverRun(): ActionHandle<HilosTableBulkAccepted> {
  throw new Error('the declaration is only read here')
}

const backupsFrame: HilosTableFrame = {
  title: 'Backups',
  subtitle: 'Nightly and manual copies',
  search: { placeholder: 'Search backups' },
  filters: [
    {
      kind: 'select',
      key: 'kind',
      label: 'Kind',
      anyLabel: 'Any kind',
      options: () => [
        { value: 'full', label: 'Full' },
        { value: 'partial', label: 'Partial' },
      ],
    },
    {
      kind: 'date_range',
      fromKey: 'createdFrom',
      toKey: 'createdTo',
      label: 'Period',
    },
    { kind: 'toggle', key: 'failedOnly', label: 'Failed only', on: true },
  ],
  mainAction: { label: 'Create backup', press: () => undefined },
  columns: [
    { key: 'createdAt', label: 'Date', sortable: true },
    { key: 'kind', label: 'Kind' },
  ],
  bulkActions: [
    {
      key: 'delete',
      label: 'Delete',
      refusalTitle: "Couldn't delete",
      danger: true,
      run: neverRun,
    },
  ],
  empty: {
    title: 'Nothing here yet',
    hint: 'Your first backup will show up here',
  },
}

describe('TableViewportController frame declaration', () => {
  const views: HilosTableViews = {
    tableKey: 'products',
    order: ['table', 'tiles'],
    imageColumn: 'picture',
  }

  it('defaults to table without opting into tiles', () => {
    const { controller } = makeController(backupsFrame)
    expect(controller.frame.view.get()).toBe('table')
    expect(controller.frame.tileCard.get()).toBeNull()
    expect(controller.frame.imageColumn.get()).toBeNull()
    expect(() => controller.setView('tiles')).toThrow(TypeError)
    expect(() => controller.setView('table')).not.toThrow()
    controller.dispose()
  })

  it.each<{ order: HilosTableViews['order'] }>([
    { order: ['table', 'tiles'] },
    { order: ['tiles', 'table'] },
  ])('opens in the first declared view $order', ({ order }) => {
    const { controller } = makeController({
      columns: [{ key: 'picture', label: 'Picture' }],
      views: { ...views, order },
    })
    expect(controller.frame.view.get()).toBe(order[0])
    controller.setView(order[1])
    expect(controller.frame.view.get()).toBe(order[1])
    expect(() => controller.setView('grid' as 'tiles')).toThrow(TypeError)
    controller.dispose()
  })

  it('rejects an invalid views declaration before registering effects', () => {
    expect(() => makeController({ columns: [], views })).toThrow(TypeError)
  })

  it('derives tiles without the image while preserving columns, rendered keys and the ordinary card', () => {
    const initial: readonly HilosTableColumn[] = [
      { key: 'picture', label: 'Picture', reads: ['imageUrl'] },
      { key: 'name', label: 'Name' },
      { key: 'state', label: 'State', card: 'badge' },
      { key: 'price', label: 'Price', card: 'field' },
      { key: 'hidden', label: 'Hidden', card: 'hidden' },
      { key: 'description', label: 'Description', detail: true },
      { key: 'actions', label: '', reads: [] },
    ]
    const columns = createSignal(initial)
    const sentRendered: (readonly string[])[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => undefined,
      sendRendered: (keys) => sentRendered.push(keys),
      frame: { columns: () => columns.get(), views },
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: {} }],
      1,
      true,
      null,
      null,
      10,
    )
    const rendered = controller.descriptor()?.rendered
    expect(rendered).toEqual([
      'picture',
      'imageUrl',
      'name',
      'state',
      'price',
      'hidden',
      'description',
    ])
    expect(controller.frame.columns.get()).toBe(initial)
    expect(controller.frame.card.get()).toEqual(hilosTableCard(initial))
    expect(controller.frame.card.get().title).toBe(initial[0])
    expect(controller.frame.imageColumn.get()).toBe(initial[0])
    expect(controller.frame.tileCard.get()).toEqual({
      title: initial[1],
      badge: initial[2],
      fields: [initial[3]],
      actions: initial[6],
    })
    controller.setView('tiles')
    expect(controller.frame.columns.get()).toBe(initial)
    expect(controller.descriptor()?.rendered).toBe(rendered)
    const next = initial.map((column) => ({
      ...column,
      label: `${column.label}!`,
    }))
    columns.set(next)
    expect(controller.frame.imageColumn.get()).toBe(next[0])
    expect(controller.frame.tileCard.get()?.title).toBe(next[1])
    expect(controller.frame.card.get()).toEqual(hilosTableCard(next))
    expect(sentRendered).toEqual([])
    controller.dispose()
  })

  it('validates the image column on every dynamic columns update', () => {
    const columns = createSignal<readonly HilosTableColumn[]>([
      { key: 'picture', label: 'Picture' },
    ])
    const { controller } = makeController({
      columns: () => columns.get(),
      views,
    })
    expect(() => columns.set([])).toThrow(TypeError)
    expect(() =>
      columns.set([{ key: 'picture', label: 'Picture', detail: true }]),
    ).toThrow(TypeError)
    const picture = { key: 'picture', label: 'New picture' }
    columns.set([picture])
    expect(controller.frame.imageColumn.get()).toBe(picture)
    controller.dispose()
    expect(() => columns.set([])).not.toThrow()
  })

  it('changes only the view with no sends or new window/row identities', () => {
    const sent: TableViewportDescriptor[] = []
    const rendered: (readonly string[])[] = []
    const focused: string[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      sendRendered: (keys) => rendered.push(keys),
      sendFocus: (key) => focused.push(key),
      frame: {
        ...backupsFrame,
        columns: [
          { key: 'picture', label: 'Picture' },
          { key: 'name', label: 'Name', sortable: true, heightContent: 'text' },
          { key: 'description', label: 'Description', detail: true },
        ],
        rowHeight: { mode: 'lines', count: 3 },
        views,
      },
    })
    const row = { rowKey: 'a', slots: {} }
    controller.ingestWindow([row], 30, true, { id: 1 }, { id: 1 }, 10)
    controller.setSearch('camera')
    controller.setFilter('kind', 'full')
    controller.setOrder([{ field: 'name', direction: 'desc' }])
    controller.nextPage()
    controller.ingestWindow([row], 30, true, { id: 1 }, { id: 1 }, 10)
    controller.selectRow('a', true)
    controller.expandRow('a', true)
    controller.focusRow('a')
    const registration = controller.geometry.register(() => ({ records: [] }))
    const record = registration.measurement('a', 'wide')!.record
    controller.expandText(record, 'name', true)
    controller.ingestDelta({ kind: 'row_moved', rowKey: 'a', row, position: 1 })
    controller.ingestProgress({
      scope: 'row',
      rowKey: 'a',
      progressKey: 'export',
      current: 1,
      total: 2,
    })

    const before = {
      rows: controller.rows.get(),
      filter: controller.filter.get(),
      search: controller.search.get(),
      order: controller.order.get(),
      footer: controller.frame.footer.get(),
      selection: controller.selection.target.get(),
      progress: controller.progress.rows.get(),
      text: controller.expandedText.get(),
      focus: controller.focusedRow.get(),
      pending: controller.pendingCount.get(),
      descriptor: controller.descriptor(),
      sends: [sent.length, rendered.length, focused.length],
    }
    expect(before.rows[0]).toMatchObject({
      pending: 'move',
      selected: true,
      expanded: true,
    })
    expect(before.filter).toEqual({ search: 'camera', kind: 'full' })
    expect(before.pending).toBe(1)
    expect(before.progress.get('a')?.fraction).toBe(0.5)
    expect(before.footer.page).toBe(1)
    expect(before.text.get(record.occurrence)?.has('name')).toBe(true)
    const changes: string[] = []
    const unsubscribe = subscribeSignal(controller.frame.view, (view) =>
      changes.push(view),
    )
    controller.setView('tiles')
    controller.setView('tiles')
    expect(changes).toEqual(['tiles'])
    expect(controller.rows.get()).toBe(before.rows)
    expect(registration.measurement('a', 'wide')?.record).toBe(record)
    expect(controller.filter.get()).toBe(before.filter)
    expect(controller.search.get()).toBe(before.search)
    expect(controller.order.get()).toBe(before.order)
    expect(controller.frame.footer.get()).toBe(before.footer)
    expect(controller.selection.target.get()).toBe(before.selection)
    expect(controller.progress.rows.get()).toBe(before.progress)
    expect(controller.expandedText.get()).toBe(before.text)
    expect(controller.focusedRow.get()).toBe(before.focus)
    expect(controller.pendingCount.get()).toBe(before.pending)
    expect(controller.descriptor()).toEqual(before.descriptor)
    expect(controller.descriptor()?.rendered).toBe(before.descriptor?.rendered)
    expect([sent.length, rendered.length, focused.length]).toEqual(before.sends)
    expect(
      controller.frame.orders.get().every(({ narrowOnly }) => !narrowOnly),
    ).toBe(true)
    controller.setView('table')
    expect(
      controller.frame.orders.get().every(({ narrowOnly }) => narrowOnly),
    ).toBe(true)
    unsubscribe()
    controller.dispose()
  })

  it('keeps the chosen view across refusal, resets and fresh windows', () => {
    const { controller, open } = makeController({
      columns: [{ key: 'picture', label: 'Picture' }],
      views,
    })
    open([{ rowKey: 'a', slots: {} }], 1)
    controller.setView('tiles')
    controller.ingestRefusal('internal_error')
    expect(controller.frame.view.get()).toBe('tiles')
    controller.resetWindowRequests()
    controller.resetFilters()
    expect(controller.frame.view.get()).toBe('tiles')
    open([{ rowKey: 'b', slots: {} }], 1)
    expect(controller.frame.view.get()).toBe('tiles')
    controller.dispose()
    controller.setView('table')
    expect(controller.frame.view.get()).toBe('tiles')
  })

  it('normalizes omission without changing columns, rendered keys or the wire descriptor', () => {
    const columns = [{ key: 'name', label: 'Name', reads: ['description'] }]
    const { controller: legacy, open: openLegacy } = makeController({ columns })
    const { controller: explicit, open: openExplicit } = makeController({
      columns,
      rowHeight: { mode: 'content' },
    })
    openLegacy([{ rowKey: 'a', slots: {} }], 1)
    openExplicit([{ rowKey: 'a', slots: {} }], 1)
    expect(legacy.frame.rowHeight).toEqual({ mode: 'content' })
    expect(legacy.frame.columns.get()).toBe(columns)
    expect(legacy.descriptor()?.rendered).toEqual(['name', 'description'])
    expect(legacy.descriptor()).toEqual(explicit.descriptor())
    expect(legacy.descriptor()).not.toHaveProperty('rowHeight')
    expect(legacy.descriptor()).not.toHaveProperty('heightContent')
    legacy.dispose()
    explicit.dispose()
  })

  it.each(['text', 'parts'] as const)(
    'exposes one normalized policy for participating %s columns in both projections',
    (heightContent) => {
      const { controller, open } = makeController({
        rowHeight: { mode: 'height', rem: 4.5 },
        columns: [{ key: 'name', label: 'Name', heightContent }],
      })
      open([{ rowKey: 'a', slots: {} }], 1)
      expect(controller.frame.rowHeight).toEqual({ mode: 'height', rem: 4.5 })
      expect(controller.frame.columns.get()[0]?.heightContent).toBe(
        heightContent,
      )
      expect(controller.frame.card.get().title?.heightContent).toBe(
        heightContent,
      )
      expect(controller.descriptor()?.rendered).toEqual(['name'])
      controller.dispose()
    },
  )

  it.each([
    { mode: 'unknown' },
    { mode: 'lines', count: '3' },
    { mode: 'lines', count: 3, rem: 3 },
    { mode: 'height', rem: Infinity },
    null,
  ])(
    'validates untyped rowHeight at controller construction %#',
    (rowHeight) => {
      expect(() =>
        makeController({
          columns: [],
          rowHeight,
        } as unknown as HilosTableFrame),
      ).toThrow(/rowHeight/)
    },
  )

  it.each([
    { key: 'detail', label: 'Detail', detail: true, heightContent: 'text' },
    { key: 'actions', label: '', reads: [], heightContent: 'parts' },
    { key: 'state', label: 'State', card: 'badge', heightContent: 'text' },
    { key: 'name', label: 'Name', heightContent: 'unknown' },
    { key: 'name', label: 'Name', heightContent: null },
    { key: 'name', label: 'Name', heightContent: false },
  ])(
    'refuses inapplicable heightContent even with intrinsic content %#',
    (column) => {
      expect(() =>
        makeController({ columns: [column] as HilosTableColumn[] }),
      ).toThrow(/heightContent/)
    },
  )

  it.each([
    { mode: 'lines', count: 3 },
    { mode: 'height', rem: 4.5 },
  ] as const)(
    'refuses limited policy $mode without participating main text',
    (rowHeight) => {
      for (const columns of [
        [],
        [{ key: 'name', label: 'Name' }],
        [{ key: 'detail', label: 'Detail', detail: true }],
        [{ key: 'state', label: 'State', card: 'badge' as const }],
        [{ key: 'actions', label: '', reads: [] }],
      ]) {
        expect(() => makeController({ columns, rowHeight })).toThrow(
          /requires main text/,
        )
      }
    },
  )

  it('validates dynamic columns before they can replace a valid frame', () => {
    const columns = createSignal<readonly HilosTableColumn[]>([
      { key: 'name', label: 'Name', heightContent: 'text' },
    ])
    const { controller } = makeController({
      columns: () => columns.get(),
      rowHeight: { mode: 'lines', count: 3 },
    })
    expect(() => columns.set([])).toThrow(/requires main text/)
    expect(() =>
      columns.set([
        { key: 'name', label: 'Name', heightContent: 'parts', detail: true },
      ]),
    ).toThrow(/heightContent/)
    columns.set([{ key: 'name', label: 'Name', heightContent: 'parts' }])
    expect(controller.frame.columns.get()[0]?.heightContent).toBe('parts')
    expect(controller.frame.rowHeight).toEqual({ mode: 'lines', count: 3 })
    controller.dispose()
  })

  it('resolves late columns, filters and placeholder, then clears a vanished node and order', () => {
    const cluster = createSignal(false)
    const sent: TableViewportDescriptor[] = []
    const rendered: Array<readonly string[]> = []
    const facets: Array<Readonly<Record<string, readonly unknown[]>>> = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      sendRendered: (keys) => rendered.push([...keys]),
      sendFacets: (options) => facets.push(options),
      frame: {
        search: { placeholder: () => (cluster.get() ? 'Key or node' : 'Key') },
        columns: () =>
          cluster.get()
            ? [
                { key: 'key', label: 'Key' },
                { key: 'node', label: 'Node', sortable: true },
              ]
            : [{ key: 'key', label: 'Key' }],
        filters: () =>
          cluster.get()
            ? [
                {
                  key: 'node',
                  kind: 'select',
                  label: 'Node',
                  options: () => [{ value: 'n1', label: 'n1' }],
                },
              ]
            : [],
      },
    })

    expect(controller.frame.columns.get().map(({ key }) => key)).toEqual([
      'key',
    ])
    expect(controller.frame.searchPlaceholder.get()).toBe('Key')
    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )
    expect(rendered).toEqual([['key']])

    cluster.set(true)
    expect(controller.frame.columns.get().map(({ key }) => key)).toEqual([
      'key',
      'node',
    ])
    expect(controller.frame.card.get()?.fields.map(({ key }) => key)).toContain(
      'node',
    )
    expect(controller.frame.searchPlaceholder.get()).toBe('Key or node')
    expect(controller.orders.map(({ key }) => key)).toEqual([
      'node-asc',
      'node-desc',
    ])
    expect(rendered.at(-1)).toEqual(['key', 'node'])
    expect(facets.at(-1)).toEqual({ node: ['n1'] })

    controller.setFilter('node', 'n1')
    controller.setOrder([{ field: 'node', direction: 'asc' }])
    cluster.set(false)
    expect(controller.filter.get()).toEqual({})
    expect(controller.order.get()).toBeUndefined()
    expect(sent.at(-1)?.rendered).toEqual(['key'])
    expect(rendered.at(-1)).toEqual(['key'])
    expect(facets.at(-1)).toEqual({})
  })

  it('reports an empty rendered set after the last drawn column disappears', () => {
    const visible = createSignal(true)
    const rendered: Array<readonly string[]> = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      sendRendered: (keys) => rendered.push([...keys]),
      frame: {
        columns: () =>
          visible.get() ? [{ key: 'status', label: 'Status' }] : [],
      },
    })
    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )
    expect(rendered).toEqual([['status']])

    visible.set(false)
    expect(rendered).toEqual([['status'], []])
    expect(controller.descriptor()?.rendered).toEqual([])
  })

  it('reads the declaration back unchanged', () => {
    const { controller } = makeController(backupsFrame)

    expect(controller.frame.declaration).toBe(backupsFrame)
  })

  it('gives the unchanging parts of the frame straight off the declaration', () => {
    const { controller } = makeController(backupsFrame)
    const declaration = controller.frame.declaration

    expect(declaration?.title).toBe('Backups')
    expect(declaration?.subtitle).toBe('Nightly and manual copies')
    expect(declaration?.search?.placeholder).toBe('Search backups')
    expect(declaration?.mainAction?.label).toBe('Create backup')
    expect(controller.frame.columns.get().map((column) => column.key)).toEqual([
      'createdAt',
      'kind',
    ])
    expect(declaration?.bulkActions).toEqual([
      {
        key: 'delete',
        label: 'Delete',
        refusalTitle: "Couldn't delete",
        danger: true,
        run: neverRun,
      },
    ])
    expect(declaration?.empty?.title).toBe('Nothing here yet')
  })

  it('reads a select filter its options fresh, not frozen at declaration time', () => {
    let channels = [{ value: 'email', label: 'Email' }]
    const { controller } = makeController({
      title: 'Deliveries',
      columns: [{ key: 'channel', label: 'Channel' }],
      filters: [
        {
          kind: 'select',
          key: 'channel',
          label: 'Channel',
          options: () => channels,
        },
      ],
    })
    const declared = controller.frame.filters.get()[0]?.filter
    if (declared?.kind !== 'select') {
      throw new Error('the declared filter should be a select')
    }

    expect(declared.options()).toEqual([{ value: 'email', label: 'Email' }])

    channels = [
      { value: 'email', label: 'Email' },
      { value: 'sms', label: 'SMS' },
    ]

    expect(declared.options().map((option) => option.value)).toEqual([
      'email',
      'sms',
    ])
  })

  it('gives the same frame state object on every read', () => {
    const { controller } = makeController(backupsFrame)

    expect(controller.frame).toBe(controller.frame)
  })
})

describe('TableViewportController frame filters', () => {
  it('shows a filter with a value as active and one without as not', () => {
    const { controller } = makeController(backupsFrame)

    expect(controller.frame.filters.get().map((view) => view.active)).toEqual([
      false,
      false,
      false,
    ])

    controller.setFilter('kind', 'full')

    const views = controller.frame.filters.get()
    expect(views[0]?.active).toBe(true)
    expect(views[0]?.value).toBe('full')
    expect(views[1]?.active).toBe(false)
    expect(controller.frame.activeFilterCount.get()).toBe(1)
  })

  it('counts a date range active on either bound alone', () => {
    const { controller } = makeController(backupsFrame)
    controller.setFilter('createdTo', '2026-09-01')

    const range = controller.frame.filters.get()[1]
    expect(range?.active).toBe(true)
    expect(range?.value).toEqual({ from: undefined, to: '2026-09-01' })
    expect(controller.frame.activeFilterCount.get()).toBe(1)
  })

  it('counts neither the search nor a filter-map key nobody declared', () => {
    const { controller } = makeController(backupsFrame, { tenant: 'acme' })
    controller.setSearch('nightly')

    expect(controller.frame.activeFilterCount.get()).toBe(0)

    controller.setFilter('failedOnly', true)

    expect(controller.frame.activeFilterCount.get()).toBe(1)
  })

  it('counts a route preset only when a control is declared for its key', () => {
    const { controller } = makeController(backupsFrame, { kind: 'full' })

    expect(controller.frame.activeFilterCount.get()).toBe(1)
  })

  it('sends one window when a filter value changes', () => {
    const { controller, sent, open } = makeController(backupsFrame)
    open()
    controller.setFilter('kind', 'full')

    expect(sent).toHaveLength(1)
    // A declared frame sends the fields its columns draw with every window (HIL-880).
    expect(sent[0]).toEqual({
      requestId: expect.any(String),
      filter: { kind: 'full' },
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
      rendered: ['createdAt', 'kind'],
    })
  })

  it('sets both bounds of a date range in ONE window change', () => {
    const { controller, sent } = makeController(backupsFrame)
    controller.setFilters({
      createdFrom: '2026-08-01',
      createdTo: '2026-09-01',
    })

    expect(sent).toHaveLength(1)
    expect(sent[0]?.filter).toEqual({
      createdFrom: '2026-08-01',
      createdTo: '2026-09-01',
    })
  })

  it('clears the keys given an empty value and leaves the rest alone', () => {
    const { controller, sent } = makeController(backupsFrame)
    controller.setFilters({
      createdFrom: '2026-08-01',
      createdTo: '2026-09-01',
    })
    controller.setFilter('kind', 'full')
    controller.setFilters({ createdFrom: null, createdTo: '' })

    expect(sent.at(-1)?.filter).toEqual({ kind: 'full' })
  })

  it('returns a window change to the first page and drops the anchor', () => {
    const { controller, sent, open } = makeController(backupsFrame)
    open([], 50, true, null, null)
    controller.setPage(2)
    controller.setFilters({ kind: 'full' })

    expect(controller.frame.footer.get().page).toBe(0)
    expect(sent.at(-1)?.pageIndex).toBeNull()
    expect(sent.at(-1)?.anchor).toBeNull()
  })

  it('resets the filters back to the initial ones, not to an empty map', () => {
    const { controller, sent } = makeController(backupsFrame, {
      channel: 'email',
    })
    controller.setFilter('kind', 'full')
    controller.setSearch('nightly')
    controller.resetFilters()

    expect(sent).toHaveLength(3)
    expect(sent.at(-1)?.filter).toEqual({ channel: 'email' })
    expect(controller.search.get()).toBe('')
    expect(controller.frame.activeFilterCount.get()).toBe(0)
  })
})

function rows(count: number, from = 0): TableRow[] {
  return Array.from({ length: count }, (_, index) => ({
    rowKey: `row-${from + index}`,
    slots: {},
  }))
}

describe('TableViewportController frame facets', () => {
  /**
   * A controller that asks for counts, its frame opening with a channel dropdown
   * whose options the test supplies ahead of the backups filters.
   *
   * @param options The channel dropdown's options, read fresh like a page's own.
   * @return The controller, the declarations it sent, the windows it sent, and a window opener.
   */
  function makeCounted(options: () => readonly HilosTableFilterOption[]) {
    const declared: Array<Readonly<Record<string, readonly unknown[]>>> = []
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      sendFacets: (facets) => declared.push(facets),
      frame: {
        ...backupsFrame,
        filters: [
          { kind: 'select', key: 'channel', label: 'Channel', options },
          ...(typeof backupsFrame.filters === 'function'
            ? backupsFrame.filters()
            : (backupsFrame.filters ?? [])),
        ],
      },
    })
    const open = (): void =>
      controller.ingestWindow([], 0, true, null, null, 10)

    return { controller, declared, sent, open }
  }

  const twoChannels = (): readonly HilosTableFilterOption[] => [
    { value: 'email', label: 'Email' },
    { value: 'sms', label: 'SMS' },
  ]

  it('declares the options of its dropdown filters once the first window lands, and once', () => {
    const { declared, open } = makeCounted(twoChannels)
    expect(declared).toEqual([])

    open()
    open()

    expect(declared).toEqual([
      { channel: ['email', 'sms'], kind: ['full', 'partial'] },
    ])
  })
  it('leaves a dropdown offering more than twenty options out of the declaration', () => {
    const many = Array.from(
      { length: HILOS_TABLE_FACET_OPTION_LIMIT + 1 },
      (_, index) => ({ value: `channel-${index}`, label: `Channel ${index}` }),
    )
    const { declared, open } = makeCounted(() => many)

    open()

    expect(declared).toEqual([{ kind: ['full', 'partial'] }])
  })
  it('declares nothing while no dropdown offers anything to count', () => {
    const declared: Array<Readonly<Record<string, readonly unknown[]>>> = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => undefined,
      sendFacets: (facets) => declared.push(facets),
      frame: { ...backupsFrame, filters: [] },
    })

    controller.ingestWindow([], 0, true, null, null, 10)

    expect(declared).toEqual([])
  })
  it('declares again when the options of a dropdown change after the first window', () => {
    const channels = createSignal<readonly HilosTableFilterOption[]>([])
    const { declared, open } = makeCounted(() => channels.get())
    open()

    channels.set(twoChannels())

    expect(declared).toEqual([
      { channel: [], kind: ['full', 'partial'] },
      { channel: ['email', 'sms'], kind: ['full', 'partial'] },
    ])
  })

  it('sends no frame for a change before the first window, which carries it instead', () => {
    const channels = createSignal<readonly HilosTableFilterOption[]>([])
    const { declared, open } = makeCounted(() => channels.get())

    channels.set(twoChannels())
    expect(declared).toEqual([])
    open()

    expect(declared).toEqual([
      { channel: ['email', 'sms'], kind: ['full', 'partial'] },
    ])
  })
  it('shows no numbers until counts arrive, and never beside a range or a toggle', () => {
    const { controller } = makeCounted(twoChannels)

    expect(controller.frame.filters.get().map((view) => view.facets)).toEqual([
      null,
      null,
      null,
      null,
    ])
  })

  it('lays each frame of counts over the ones held, filter by filter', () => {
    const { controller } = makeCounted(twoChannels)

    controller.ingestFacetCounts({
      channel: {
        any: { count: 500, exact: false },
        options: {
          email: { count: 412, exact: true },
          sms: { count: 88, exact: true },
        },
      },
      kind: {
        any: { count: 30, exact: true },
        options: { full: { count: 20, exact: true } },
      },
    })
    controller.ingestFacetCounts({
      kind: {
        any: { count: 12, exact: true },
        options: { full: { count: 0, exact: true } },
      },
    })

    const [channel, kind, range, toggle] = controller.frame.filters.get()
    expect(channel?.facets?.any).toEqual({ count: 500, exact: false })
    expect(channel?.facets?.options.get('email')).toEqual({
      count: 412,
      exact: true,
    })
    expect(kind?.facets?.any).toEqual({ count: 12, exact: true })
    expect(kind?.facets?.options.get('full')).toEqual({ count: 0, exact: true })
    expect(range?.facets).toBeNull()
    expect(toggle?.facets).toBeNull()
  })

  it('reports its options with the window it holds, and never inside a window it sends', () => {
    const { controller, sent, open } = makeCounted(twoChannels)
    expect(controller.descriptor()).toBeNull()

    open()
    controller.setFilter('channel', 'email')

    expect(controller.descriptor()?.facets).toEqual({
      channel: ['email', 'sms'],
      kind: ['full', 'partial'],
    })
    expect(sent.at(-1)?.facets).toBeUndefined()
  })

  it('reports no options with its window when it asks for no counts', () => {
    const { controller, open } = makeController(backupsFrame)
    open()

    expect(controller.descriptor()?.facets).toBeUndefined()
  })
})

describe('TableViewportController frame footer', () => {
  it('numbers the shown rows from one, and says a first page has nothing before it', () => {
    const { controller, open } = makeController(backupsFrame)
    open(rows(10), 128, true, null, null)

    expect(controller.frame.footer.get()).toEqual({
      firstRow: 1,
      lastRow: 10,
      totalCount: 128,
      totalExact: true,
      page: 0,
      pageCount: 13,
      hasPreviousPage: false,
      hasNextPage: true,
      paginated: true,
    })
  })

  it('marks a set fitting on one page as not paginated', () => {
    const { controller, open } = makeController(backupsFrame)
    open(rows(10), 10, true, null, null)

    const footer = controller.frame.footer.get()
    expect(footer.pageCount).toBe(1)
    expect(footer.paginated).toBe(false)
  })

  it('moves the range with the page and opens the way back', () => {
    const { controller, open } = makeController(backupsFrame)
    open(rows(10), 128, true, null, null)
    controller.setPage(2)
    open(rows(10, 20), 128, true, null, null)

    const footer = controller.frame.footer.get()
    expect([footer.firstRow, footer.lastRow]).toEqual([21, 30])
    expect(footer.page).toBe(2)
    expect(footer.hasPreviousPage).toBe(true)
  })

  it('gives a range of zeroes on an empty window', () => {
    const { controller, open } = makeController(backupsFrame)
    open([], 0, true, null, null)

    const footer = controller.frame.footer.get()
    expect([footer.firstRow, footer.lastRow, footer.totalCount]).toEqual([
      0, 0, 0,
    ])
  })

  it('passes an inexact count on with no page count behind it', () => {
    const { controller, open } = makeController(backupsFrame)
    open(rows(10), 500, false, null, null)

    const footer = controller.frame.footer.get()
    expect(footer.totalExact).toBe(false)
    expect(footer.pageCount).toBeNull()
    expect(footer.hasNextPage).toBe(true)
    expect(footer.paginated).toBe(true)
  })
})

describe('TableViewportController frame body', () => {
  it('walks loading -> empty -> empty_filtered -> rows', () => {
    const { controller, open } = makeController(backupsFrame)

    expect(controller.frame.body.get()).toBe('loading')

    open([], 0, true, null, null)

    expect(controller.frame.body.get()).toBe('empty')

    controller.setFilter('kind', 'full')
    open([], 0, true, null, null)

    expect(controller.frame.body.get()).toBe('empty_filtered')

    open(rows(3), 3, true, null, null)

    expect(controller.frame.body.get()).toBe('rows')
  })

  it('calls an empty set under a search filtered, not empty', () => {
    const { controller, open } = makeController(backupsFrame)
    open([], 0, true, null, null)
    controller.setSearch('nightly')
    open([], 0, true, null, null)

    expect(controller.frame.body.get()).toBe('empty_filtered')
  })

  it('calls an empty window over a set that is not empty a page, not a set', () => {
    const { controller, open } = makeController(backupsFrame)

    // The rows this window was asked for moved out from under its address. Saying "no
    // backups yet" here would tell the reader the set is gone beside a footer counting it.
    open([], 21, true, null, null)

    expect(controller.frame.body.get()).toBe('empty_page')
  })

  it('keeps the rows body for retained placeholders over an empty set', () => {
    const { controller, open } = makeController(backupsFrame)
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.ingestCount(0, true)

    controller.apply()

    expect(controller.rows.get()).toEqual([
      {
        rowKey: 'a',
        occurrence: 1,
        row: null,
        placeholder: true,
        pending: null,
        removal: 'deleted',
        highlighted: false,
        selected: false,
        expanded: false,
        expandable: false,
        refused: false,
        rowMessage: null,
        staleSources: [],
      },
    ])
    expect(controller.frame.body.get()).toBe('rows')
  })

  it('calls a refused window unavailable', () => {
    const { controller } = makeController(backupsFrame)
    controller.ingestRefusal('internal_error')

    expect(controller.frame.body.get()).toBe('unavailable')
    expect(controller.frame.refusal.get()).toBe('internal_error')
  })

  it('still calls an empty filtered set filtered when no window of it could hold rows', () => {
    const { controller, open } = makeController(backupsFrame)
    controller.setFilter('kind', 'full')

    open([], 0, true, null, null)

    expect(controller.frame.body.get()).toBe('empty_filtered')
  })
})
