import { HILOS_TABLE_OPENING_ORDER_KEY } from '@hilos/core/base'
import type {
  HilosTableColumn,
  HilosTableFrame,
  HilosTableSortOrder,
  TableSortOrder,
} from '@hilos/core/base'
import type {
  TableBarMount,
  TableBarResources,
  TableBarRunner,
} from './tableBarFixtures.js'
import { withTableBarResources } from './tableBarFixtures.js'

const ORDERED_COLUMNS: readonly HilosTableColumn[] = [
  { key: 'channel', label: 'Kind', sortable: true },
  { key: 'createdAt', label: 'Date', sortable: true },
]

const DECLARED_ORDERS: readonly HilosTableSortOrder[] = [
  {
    key: 'by_channel',
    components: [
      { field: 'channel', direction: 'asc' },
      { field: 'createdAt', direction: 'desc' },
    ],
  },
  {
    key: 'by_date_up',
    components: [
      { field: 'createdAt', direction: 'asc' },
      { field: 'channel', direction: 'asc' },
    ],
  },
]

const OPENING_ORDER: TableSortOrder = [
  { field: 'createdAt', direction: 'desc' },
]

const SOURCED_COLUMNS: readonly HilosTableColumn[] = [
  { key: 'channel', label: 'Kind', sortable: true, source: 'channels' },
  { key: 'createdAt', label: 'Date', sortable: true },
  { key: 'state', label: 'State', sortable: true },
]

const SOURCED_ORDERS: readonly HilosTableSortOrder[] = [
  {
    key: 'by_channel',
    components: [
      { field: 'channel', direction: 'asc' },
      { field: 'createdAt', direction: 'desc' },
    ],
  },
  {
    key: 'by_state',
    components: [
      { field: 'state', direction: 'asc' },
      { field: 'createdAt', direction: 'desc' },
    ],
  },
]

function required<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  selector: string,
): T {
  const node = root.querySelector<T>(selector)
  if (node === null) throw new Error(`Missing table bar node: ${selector}`)
  return node
}

function orderItems(root: ParentNode): HTMLElement[] {
  return Array.from(
    root.querySelectorAll<HTMLElement>('[data-id^="hilos-table-order-"]'),
  ).filter(
    (item) => !item.dataset['id']?.startsWith('hilos-table-order-stale-'),
  )
}

function createOrdered(
  resources: TableBarResources,
  columns: readonly HilosTableColumn[] = ORDERED_COLUMNS,
  declaredOrders: readonly HilosTableSortOrder[] = DECLARED_ORDERS,
) {
  const fixture = resources.create(
    { title: 'Deliveries', columns },
    undefined,
    declaredOrders,
    false,
  )
  fixture.controller.ingestSubscriptionWindow(
    [],
    0,
    true,
    null,
    null,
    20,
    OPENING_ORDER,
    {},
    [],
  )
  return fixture
}

function createSourced(resources: TableBarResources, staleSources: string[]) {
  const fixture = createOrdered(resources, SOURCED_COLUMNS, SOURCED_ORDERS)
  fixture.controller.ingestWindow(
    [{ rowKey: '1', slots: {}, staleSources }],
    1,
    true,
    null,
    null,
    20,
  )
  return fixture
}

/** Order behavior shared by the real Vue, React, and Angular table bars. */
export function registerTableBarOrderCases(
  sdk: string,
  runner: TableBarRunner,
  mount: TableBarMount,
): void {
  const { describe, it, expect } = runner
  function scenario(
    name: string,
    steps: (resources: TableBarResources) => Promise<void>,
  ): void {
    it(name, () => withTableBarResources(runner, mount, steps))
  }

  describe(`HilosTableBar order on ${sdk}`, () => {
    scenario(
      'O01 omits the order menu without sortable columns or composite orders',
      async (resources) => {
        const frame: HilosTableFrame = {
          title: 'Backups',
          search: {},
          columns: [{ key: 'name', label: 'Name' }],
        }
        const view = await resources.mount(resources.create(frame))

        expect(
          view.root.querySelector('[data-id="hilos-table-order"]'),
        ).toBeNull()
      },
    )

    scenario(
      'O02 offers the four single-column orders without views or composites',
      async (resources) => {
        const fixture = createOrdered(resources, ORDERED_COLUMNS, [])
        const view = await resources.mount(fixture)

        expect(
          orderItems(view.root).map((item) => [
            item.textContent?.trim(),
            item.getAttribute('role'),
            item.getAttribute('aria-selected'),
          ]),
        ).toEqual([
          ['Date ↓', 'option', 'true'],
          ['Kind ↑', 'option', 'false'],
          ['Kind ↓', 'option', 'false'],
          ['Date ↑', 'option', 'false'],
        ])
        expect(
          view.root.querySelector('[data-id="hilos-table-view"]'),
        ).toBeNull()
      },
    )

    scenario(
      'O05 picks a single-column order for the window',
      async (resources) => {
        const fixture = createOrdered(resources, ORDERED_COLUMNS, [])
        const view = await resources.mount(fixture)

        await view.change(() =>
          required(
            view.root,
            '[data-id="hilos-table-order-channel-desc"]',
          ).click(),
        )

        expect(fixture.sent.at(-1)?.sort).toEqual([
          { field: 'channel', direction: 'desc' },
        ])
      },
    )

    scenario(
      'O06 offers the opening order, both column directions, and each declared order and mirror',
      async (resources) => {
        const view = await resources.mount(createOrdered(resources))

        expect(
          orderItems(view.root).map((item) => item.textContent?.trim()),
        ).toEqual([
          'Date ↓',
          'Kind ↑',
          'Kind ↓',
          'Date ↑',
          'Kind ↑, then Date ↓',
          'Kind ↓, then Date ↑',
          'Date ↑, then Kind ↑',
          'Date ↓, then Kind ↓',
        ])
        expect(
          view.root.querySelector(
            `[data-id="hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}"]`,
          ),
        ).not.toBeNull()
      },
    )

    scenario(
      'O07 names the order currently running on the button',
      async (resources) => {
        const fixture = createOrdered(resources)
        const view = await resources.mount(fixture)
        const toggle = required(view.root, '[data-id="hilos-dropdown-toggle"]')

        expect(toggle.textContent?.trim()).toBe('Order: Date ↓')
        await view.change(() =>
          required(
            view.root,
            '[data-id="hilos-table-order-by_channel"]',
          ).click(),
        )
        expect(toggle.textContent?.trim()).toBe('Order: Kind ↑, then Date ↓')
      },
    )

    scenario(
      'O08 sends a declared composite order whole',
      async (resources) => {
        const fixture = createOrdered(resources)
        const view = await resources.mount(fixture)

        await view.change(() =>
          required(
            view.root,
            '[data-id="hilos-table-order-by_channel"]',
          ).click(),
        )

        expect(fixture.sent.at(-1)?.sort).toEqual([
          { field: 'channel', direction: 'asc' },
          { field: 'createdAt', direction: 'desc' },
        ])
      },
    )

    scenario(
      'O09 returns home through the opening order',
      async (resources) => {
        const fixture = createOrdered(resources)
        const view = await resources.mount(fixture)

        await view.change(() =>
          required(
            view.root,
            '[data-id="hilos-table-order-by_channel"]',
          ).click(),
        )
        await view.change(() =>
          required(
            view.root,
            `[data-id="hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}"]`,
          ).click(),
        )

        expect(fixture.sent.at(-1)?.sort).toEqual(OPENING_ORDER)
      },
    )

    scenario(
      'O10 sends nothing when the opening order is already selected',
      async (resources) => {
        const fixture = createOrdered(resources)
        const view = await resources.mount(fixture)

        await view.change(() =>
          required(
            view.root,
            `[data-id="hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}"]`,
          ).click(),
        )

        expect(fixture.sent).toEqual([])
      },
    )

    scenario(
      'O11 selects the column order after the controller changes sort',
      async (resources) => {
        const fixture = createOrdered(resources)
        const view = await resources.mount(fixture)

        await view.change(() => fixture.controller.setSort('channel'))

        const selected = view.root.querySelectorAll<HTMLElement>(
          '[role="option"][aria-selected="true"]',
        )
        expect(selected).toHaveLength(1)
        expect(selected[0]?.dataset['id']).toBe('hilos-table-order-channel-asc')
        expect(
          required(
            view.root,
            '[data-id="hilos-dropdown-toggle"]',
          ).textContent?.trim(),
        ).toBe('Order: Kind ↑')
      },
    )

    scenario(
      'O12 marks stale-source orders while keeping them selectable',
      async (resources) => {
        const fixture = createSourced(resources, ['channels'])
        const view = await resources.mount(fixture)

        expect(
          view.root.querySelector(
            '[data-id="hilos-table-order-stale-by_channel"]',
          ),
        ).not.toBeNull()
        expect(
          view.root.querySelector(
            '[data-id="hilos-table-order-stale-channel-desc"]',
          ),
        ).not.toBeNull()
        expect(
          view.root.querySelector(
            '[data-id="hilos-table-order-stale-by_state"]',
          ),
        ).toBeNull()
        expect(
          view.root.querySelector(
            '[data-id="hilos-table-order-stale-state-asc"]',
          ),
        ).toBeNull()
        expect(
          required(view.root, '[data-id="hilos-table-order-by_channel"]')
            .textContent,
        ).toContain('Sorting by this column may be wrong')

        await view.change(() =>
          required(
            view.root,
            '[data-id="hilos-table-order-by_channel"]',
          ).click(),
        )
        expect(fixture.sent.at(-1)?.sort).toEqual([
          { field: 'channel', direction: 'asc' },
          { field: 'createdAt', direction: 'desc' },
        ])
      },
    )

    scenario(
      'O13 marks no order when all sources are current',
      async (resources) => {
        const view = await resources.mount(createSourced(resources, []))

        expect(
          view.root.querySelectorAll('[data-id^="hilos-table-order-stale-"]'),
        ).toHaveLength(0)
      },
    )
  })
}
