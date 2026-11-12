import { TABLE_VIEW_COPY, subscribeSignal } from '@hilos/core/base'
import type {
  HilosTableColumn,
  HilosTableFrame,
  HilosTableViews,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import type {
  TableBarMount,
  TableBarResources,
  TableBarRunner,
} from './tableBarFixtures.js'
import { withTableBarResources } from './tableBarFixtures.js'

type ViewRunner = TableBarRunner & Pick<typeof Vitest, 'vi'>

const COLUMNS: readonly HilosTableColumn[] = [{ key: 'name', label: 'Name' }]
const VIEWS: HilosTableViews = {
  tableKey: 'pictures',
  order: ['table', 'tiles'],
  imageColumn: 'name',
}
const BULK: Partial<HilosTableFrame> = {
  bulkActions: [
    {
      key: 'archive',
      label: 'Archive',
      refusalTitle: 'Cannot archive',
      run: () => {
        throw new Error('Not pressed')
      },
    },
  ],
}

function frame(extra: Partial<HilosTableFrame> = {}): HilosTableFrame {
  return { title: 'Pictures', columns: COLUMNS, views: VIEWS, ...extra }
}

function byId<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const node = root.querySelector<T>('[data-id="' + id + '"]')
  if (node === null) throw new Error('Missing table bar node: ' + id)
  return node
}

function buttons(root: ParentNode): HTMLButtonElement[] {
  return Array.from(byId(root, 'hilos-table-view').querySelectorAll('button'))
}

function reading(root: ParentNode): unknown[][] {
  return buttons(root).map((button) => [
    button.textContent?.trim(),
    button.getAttribute('aria-pressed'),
  ])
}

function withdrawn(node: HTMLElement): boolean {
  return node.closest('[hidden], [inert], [aria-hidden="true"]') !== null
}

/** Emulators need the browser's keyboard click delivered between the key events. */
function keyboardPress(button: HTMLButtonElement): void {
  button.focus()
  button.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
  )
  button.dispatchEvent(new MouseEvent('click', { bubbles: true, detail: 0 }))
  button.dispatchEvent(
    new KeyboardEvent('keyup', { key: 'Enter', bubbles: true }),
  )
}

/** Register each observer's release immediately, including during partial setup. */
async function withObservers(
  steps: (own: (release: () => void) => void) => Promise<void>,
): Promise<void> {
  const releases: (() => void)[] = []
  const errors: unknown[] = []
  try {
    await steps((release) => {
      releases.push(release)
    })
  } catch (error) {
    errors.push(error)
  } finally {
    for (const release of releases.reverse()) {
      try {
        release()
      } catch (error) {
        errors.push(error)
      }
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Table view observers failed', {
      cause: errors[0],
    })
}

function ingestRows(
  fixture: ReturnType<TableBarResources['create']>,
  two = false,
): void {
  fixture.controller.ingestWindow(
    two
      ? [
          { rowKey: 'a', slots: { name: 'A' } },
          { rowKey: 'b', slots: { name: 'B' } },
        ]
      : [{ rowKey: 'a', slots: { name: 'A' } }],
    two ? 2 : 1,
    true,
    null,
    null,
    10,
  )
}

function combined(
  resources: TableBarResources,
  runner: ViewRunner,
  bulk: boolean,
) {
  const copy = runner.vi.fn()
  const download = runner.vi.fn()
  const main = runner.vi.fn()
  const fixture = resources.create(
    frame({
      search: {},
      auxiliaryActions: [
        { key: 'copy', label: 'Copy the list', press: copy },
        { key: 'download', label: 'Download as a file', press: download },
      ],
      mainAction: { label: 'Create', press: main },
      ...(bulk ? BULK : {}),
    }),
  )
  ingestRows(fixture)
  return { ...fixture, copy, download, main }
}

function ordered(resources: TableBarResources) {
  const fixture = resources.create(
    {
      columns: [
        { key: 'channel', label: 'Kind', sortable: true },
        { key: 'createdAt', label: 'Date', sortable: true },
      ],
      views: {
        tableKey: 'ordered-pictures',
        order: ['table', 'tiles'],
        imageColumn: 'channel',
      },
    },
    undefined,
    [],
    false,
  )
  fixture.controller.ingestSubscriptionWindow(
    [],
    0,
    true,
    null,
    null,
    20,
    [{ field: 'createdAt', direction: 'desc' }],
    {},
    [],
  )
  return fixture
}

function orderItems(root: ParentNode): HTMLElement[] {
  return Array.from(
    root.querySelectorAll<HTMLElement>('[data-id^="hilos-table-order-"]'),
  ).filter(
    (item) => !item.dataset['id']?.startsWith('hilos-table-order-stale-'),
  )
}

function orderReading(root: ParentNode): unknown[][] {
  return orderItems(root).map((item) => [
    item.textContent?.trim(),
    item.getAttribute('role'),
    item.getAttribute('aria-selected'),
  ])
}

const EVERY_ORDER = [
  ['Date ↓', 'option', 'true'],
  ['Kind ↑', 'option', 'false'],
  ['Kind ↓', 'option', 'false'],
  ['Date ↑', 'option', 'false'],
]

/** One semantic suite, registered through each SDK's real native Bar mount. */
export function registerTableBarViewCases(
  sdk: string,
  runner: ViewRunner,
  mount: TableBarMount,
): void {
  const { describe, it, expect } = runner
  function scenario(
    name: string,
    steps: (resources: TableBarResources) => Promise<void>,
  ): void {
    it(name, () => withTableBarResources(runner, mount, steps))
  }

  describe('HilosTableBar views on ' + sdk, () => {
    for (const bulk of [false, true]) {
      for (const two of [false, true]) {
        scenario(
          'V01 keeps one reachable native choice and focus in both directions; two rows: ' +
            two +
            '; bulk: ' +
            bulk,
          async (resources) => {
            const fixture = resources.create(frame(bulk ? BULK : {}))
            ingestRows(fixture, two)
            const view = await resources.mount(fixture, 'pictures-title')
            const group = byId(view.root, 'hilos-table-view')
            expect(
              view.root.querySelectorAll(
                '[role="group"][aria-label="' + TABLE_VIEW_COPY.group + '"]',
              ),
            ).toHaveLength(1)
            expect(group.getAttribute('role')).toBe('group')
            expect(group.getAttribute('aria-label')).toBe(TABLE_VIEW_COPY.group)
            expect(
              buttons(view.root).map((button) => [
                button.textContent?.trim(),
                button.type,
                button.getAttribute('aria-pressed'),
                button.dataset['id'],
                button.disabled,
              ]),
            ).toEqual([
              ['Table', 'button', 'true', 'hilos-table-view-table', false],
              ['Tiles', 'button', 'false', 'hilos-table-view-tiles', false],
            ])
            expect(
              Array.from(group.querySelectorAll('i')).map((icon) =>
                icon.getAttribute('aria-hidden'),
              ),
            ).toEqual(['true', 'true'])
            if (bulk) {
              await view.change(() => fixture.controller.selectRow('a', true))
              expect(withdrawn(byId(view.root, 'hilos-table-selection'))).toBe(
                false,
              )
              expect(withdrawn(group)).toBe(false)
              expect(
                byId(view.root, 'hilos-table-bar-slot').contains(group),
              ).toBe(false)
              expect(
                view.root.querySelector('[data-id="hilos-table-controls"]'),
              ).toBeNull()
            } else {
              expect(
                byId(view.root, 'hilos-table-controls').contains(group),
              ).toBe(true)
            }
            for (const choice of ['tiles', 'table'] as const) {
              const button = byId<HTMLButtonElement>(
                view.root,
                'hilos-table-view-' + choice,
              )
              await view.change(() => keyboardPress(button))
              expect(byId(view.root, 'hilos-table-view-' + choice)).toBe(button)
              expect(view.root.ownerDocument.activeElement).toBe(button)
              expect(fixture.controller.frame.view.get()).toBe(choice)
              expect(reading(view.root)).toEqual(
                choice === 'tiles'
                  ? [
                      ['Table', 'false'],
                      ['Tiles', 'true'],
                    ]
                  : [
                      ['Table', 'true'],
                      ['Tiles', 'false'],
                    ],
              )
              expect(
                view.root.querySelectorAll('[data-id="hilos-table-view"]'),
              ).toHaveLength(1)
              expect(fixture.sent).toEqual([])
              expect(fixture.controller.selection.count.get()).toBe(
                bulk ? 1 : 0,
              )
            }
          },
        )
      }

      scenario(
        'V03 observes only real transitions, including repeated active clicks; bulk: ' +
          bulk,
        async (resources) => {
          const fixture = resources.create(frame(bulk ? BULK : {}))
          const view = await resources.mount(fixture)
          const changes: string[] = []
          await withObservers(async (own) => {
            own(
              subscribeSignal(fixture.controller.frame.view, (value) =>
                changes.push(value),
              ),
            )
            await view.change(() =>
              byId(view.root, 'hilos-table-view-table').click(),
            )
            expect(changes).toEqual([])
            expect(reading(view.root)).toEqual([
              ['Table', 'true'],
              ['Tiles', 'false'],
            ])
            for (const choice of ['tiles', 'tiles'])
              await view.change(() =>
                byId(view.root, 'hilos-table-view-' + choice).click(),
              )
            expect(changes).toEqual(['tiles'])
            expect(reading(view.root)).toEqual([
              ['Table', 'false'],
              ['Tiles', 'true'],
            ])
            expect(fixture.sent).toEqual([])
            for (const choice of ['table', 'table'])
              await view.change(() =>
                byId(view.root, 'hilos-table-view-' + choice).click(),
              )
            expect(changes).toEqual(['tiles', 'table'])
            expect(reading(view.root)).toEqual([
              ['Table', 'true'],
              ['Tiles', 'false'],
            ])
            expect(fixture.sent).toEqual([])
          })
        },
      )

      scenario(
        'V04 retains the rich UI search, nontrivial order, marks and observers; bulk: ' +
          bulk,
        async (resources) => {
          const rendered: string[][] = []
          const fixture = resources.create(
            frame({
              columns: [{ key: 'name', label: 'Name', sortable: true }],
              search: {},
              ...(bulk ? BULK : {}),
            }),
            undefined,
            undefined,
            false,
            (keys) => rendered.push([...keys]),
          )
          fixture.controller.ingestSubscriptionWindow(
            [
              { rowKey: 'a', slots: { name: 'A' } },
              { rowKey: 'b', slots: { name: 'B' } },
            ],
            2,
            true,
            null,
            null,
            10,
            undefined,
            {},
            [],
          )
          const view = await resources.mount(fixture)
          const search = byId<HTMLInputElement>(view.root, 'hilos-table-search')
          expect(fixture.controller.windowControlsAvailable.get()).toBe(true)
          expect(search.disabled).toBe(false)
          await view.type(search, 'retention')
          await view.change(() => {
            fixture.controller.setOrder([{ field: 'name', direction: 'asc' }])
            if (bulk) fixture.controller.selectRow('b', true)
          })
          expect(fixture.controller.search.get()).toBe('retention')
          expect(fixture.controller.filter.get()).toEqual({
            search: 'retention',
          })
          expect(fixture.controller.descriptor()?.sort).toEqual([
            { field: 'name', direction: 'asc' },
          ])
          expect(fixture.controller.selection.count.get()).toBe(bulk ? 1 : 0)
          expect(search.value).toBe('retention')
          const state = () => ({
            search: fixture.controller.search.get(),
            filter: fixture.controller.filter.get(),
            orderLabel: fixture.controller.frame.orderLabel.get(),
            sort: fixture.controller.descriptor()?.sort,
            marked: fixture.controller.selection.count.get(),
            sent: fixture.sent.length,
            rendered: rendered.length,
          })
          const before = state()
          await withObservers(async (own) => {
            const spies = []
            for (const method of [
              'apply',
              'setOrder',
              'resetOrder',
              'setSearch',
              'resetFilters',
            ] as const) {
              const spy = runner.vi.spyOn(fixture.controller, method)
              own(() => spy.mockRestore())
              spies.push(spy)
            }
            const changes: string[] = []
            own(
              subscribeSignal(fixture.controller.frame.view, (value) =>
                changes.push(value),
              ),
            )
            await view.change(() =>
              byId(view.root, 'hilos-table-view-table').click(),
            )
            expect(changes).toEqual([])
            expect(reading(view.root)).toEqual([
              ['Table', 'true'],
              ['Tiles', 'false'],
            ])
            expect(state()).toEqual(before)
            for (const choice of ['tiles', 'tiles'])
              await view.change(() =>
                byId(view.root, 'hilos-table-view-' + choice).click(),
              )
            expect(changes).toEqual(['tiles'])
            expect(reading(view.root)).toEqual([
              ['Table', 'false'],
              ['Tiles', 'true'],
            ])
            expect(state()).toEqual(before)
            for (const choice of ['table', 'table'])
              await view.change(() =>
                byId(view.root, 'hilos-table-view-' + choice).click(),
              )
            expect(changes).toEqual(['tiles', 'table'])
            expect(reading(view.root)).toEqual([
              ['Table', 'true'],
              ['Tiles', 'false'],
            ])
            for (const spy of spies) expect(spy).not.toHaveBeenCalled()
            expect(state()).toEqual(before)
            expect(byId(view.root, 'hilos-table-search')).toBe(search)
            expect(search.value).toBe('retention')
          })
        },
      )

      scenario(
        'V06 keeps other controls mounted before any first window; bulk: ' +
          bulk,
        async (resources) => {
          const main = runner.vi.fn()
          const fixture = resources.create(
            frame({
              search: {},
              filters: [
                {
                  kind: 'toggle',
                  key: 'unread',
                  label: 'Unread only',
                  on: true,
                },
              ],
              mainAction: { label: 'Create', press: main },
              ...(bulk ? BULK : {}),
            }),
            undefined,
            undefined,
            false,
          )
          const view = await resources.mount(fixture)
          expect(
            view.root.querySelector('[data-id="hilos-table-search"]'),
          ).not.toBeNull()
          expect(
            view.root.querySelector('[data-id="hilos-table-main-action"]'),
          ).not.toBeNull()
          expect(reading(view.root)).toEqual([
            ['Table', 'true'],
            ['Tiles', 'false'],
          ])
          await view.change(() =>
            byId(view.root, 'hilos-table-view-tiles').click(),
          )
          expect(fixture.controller.frame.view.get()).toBe('tiles')
          expect(reading(view.root)).toEqual([
            ['Table', 'false'],
            ['Tiles', 'true'],
          ])
          expect(main).not.toHaveBeenCalled()
          expect(fixture.sent).toEqual([])
        },
      )

      scenario(
        'V08 omits all view hooks without a declaration but retains search; bulk: ' +
          bulk,
        async (resources) => {
          const fixture = resources.create({
            columns: COLUMNS,
            search: {},
            ...(bulk ? BULK : {}),
          })
          const view = await resources.mount(fixture)
          expect(
            view.root.querySelector('[data-id="hilos-table-view"]'),
          ).toBeNull()
          expect(
            view.root.querySelector('[data-id^="hilos-table-view-"]'),
          ).toBeNull()
          expect(
            view.root.querySelector(
              '[role="group"][aria-label="' + TABLE_VIEW_COPY.group + '"]',
            ),
          ).toBeNull()
          expect(
            view.root.querySelector('[data-id="hilos-table-search"]'),
          ).not.toBeNull()
          expect(
            view.root.querySelector('[data-id="hilos-table-bar-slot"]') !==
              null,
          ).toBe(bulk)
        },
      )

      scenario(
        'V12 exposes distinct named view and list groups alongside main and search; bulk: ' +
          bulk,
        async (resources) => {
          const fixture = combined(resources, runner, bulk)
          const view = await resources.mount(fixture)
          const views = byId(view.root, 'hilos-table-view')
          const actions = byId(view.root, 'hilos-table-actions')
          expect(views.getAttribute('role')).toBe('group')
          expect(views.getAttribute('aria-label')).toBe(TABLE_VIEW_COPY.group)
          expect(actions.getAttribute('role')).toBe('group')
          expect(actions.getAttribute('aria-label')).toBe('List actions')
          expect(views.contains(actions) || actions.contains(views)).toBe(false)
          expect(
            Array.from(actions.querySelectorAll('button')).map((button) =>
              button.textContent?.trim(),
            ),
          ).toEqual(['Copy the list', 'Download as a file'])
          expect(
            buttons(view.root).map((button) => button.textContent?.trim()),
          ).toEqual(['Table', 'Tiles'])
          expect(
            byId(view.root, 'hilos-table-main-action').textContent?.trim(),
          ).toBe('Create')
          expect(
            view.root.querySelector('[data-id="hilos-table-search"]'),
          ).not.toBeNull()
          expect(fixture.copy).not.toHaveBeenCalled()
          expect(fixture.download).not.toHaveBeenCalled()
          expect(fixture.main).not.toHaveBeenCalled()
        },
      )

      scenario(
        'V13 presses only its own group and retains the view; bulk: ' + bulk,
        async (resources) => {
          const fixture = combined(resources, runner, bulk)
          const view = await resources.mount(fixture)
          await view.change(() =>
            byId(view.root, 'hilos-table-view-tiles').click(),
          )
          expect(fixture.controller.frame.view.get()).toBe('tiles')
          expect(fixture.copy).not.toHaveBeenCalled()
          expect(fixture.download).not.toHaveBeenCalled()
          expect(fixture.main).not.toHaveBeenCalled()
          await view.change(() =>
            byId(view.root, 'hilos-table-action-copy').click(),
          )
          expect(fixture.copy).toHaveBeenCalledExactlyOnceWith()
          expect(fixture.main).not.toHaveBeenCalled()
          expect(fixture.download).not.toHaveBeenCalled()
          await view.change(() =>
            byId(view.root, 'hilos-table-main-action').click(),
          )
          expect(fixture.copy).toHaveBeenCalledExactlyOnceWith()
          expect(fixture.download).not.toHaveBeenCalled()
          expect(fixture.main).toHaveBeenCalledExactlyOnceWith()
          expect(fixture.controller.frame.view.get()).toBe('tiles')
          expect(reading(view.root)).toEqual([
            ['Table', 'false'],
            ['Tiles', 'true'],
          ])
          expect(fixture.sent).toEqual([])
        },
      )

      scenario(
        'V14 keeps two view choices with an empty list-action declaration; bulk: ' +
          bulk,
        async (resources) => {
          const view = await resources.mount(
            resources.create(
              frame({ auxiliaryActions: [], ...(bulk ? BULK : {}) }),
            ),
          )
          expect(
            view.root.querySelector('[data-id="hilos-table-actions"]'),
          ).toBeNull()
          expect(buttons(view.root)).toHaveLength(2)
        },
      )
    }

    scenario(
      'V01 keeps reverse keyboard focus before a first window',
      async (resources) => {
        const fixture = resources.create(frame(), undefined, undefined, false)
        const view = await resources.mount(fixture)
        for (const choice of ['tiles', 'table'] as const) {
          const button = byId<HTMLButtonElement>(
            view.root,
            'hilos-table-view-' + choice,
          )
          await view.change(() => keyboardPress(button))
          expect(view.root.ownerDocument.activeElement).toBe(button)
          expect(byId(view.root, 'hilos-table-view-' + choice)).toBe(button)
        }
      },
    )

    for (const { order, words } of [
      { order: ['table', 'tiles'], words: ['Table', 'Tiles'] },
      { order: ['tiles', 'table'], words: ['Tiles', 'Table'] },
    ] as const) {
      scenario(
        'V02 follows declared order ' + order.join('/'),
        async (resources) => {
          const fixture = resources.create(
            frame({ views: { ...VIEWS, order } }),
          )
          const view = await resources.mount(fixture)
          expect(reading(view.root)).toEqual([
            [words[0], 'true'],
            [words[1], 'false'],
          ])
          expect(
            buttons(view.root).map((button) => button.dataset['id']),
          ).toEqual(order.map((choice) => 'hilos-table-view-' + choice))
          await view.change(() => buttons(view.root)[1]!.click())
          expect(fixture.controller.frame.view.get()).toBe(order[1])
          expect(reading(view.root)).toEqual([
            [words[0], 'false'],
            [words[1], 'true'],
          ])
          await view.change(() => buttons(view.root)[0]!.click())
          expect(fixture.controller.frame.view.get()).toBe(order[0])
          expect(reading(view.root)).toEqual([
            [words[0], 'true'],
            [words[1], 'false'],
          ])
          expect(fixture.sent).toEqual([])
        },
      )
    }

    scenario(
      'V04 retains the original public-search and unread-filter preparation',
      async (resources) => {
        const fixture = resources.create(
          frame({
            ...BULK,
            search: {},
            filters: [
              { kind: 'toggle', key: 'unread', label: 'Unread', on: true },
            ],
          }),
        )
        ingestRows(fixture, true)
        const view = await resources.mount(fixture)
        await view.change(() => fixture.controller.setSearch('needle'))
        await view.change(() => fixture.controller.selectRow('b', true))
        const asked = fixture.sent.length
        const filter = fixture.controller.filter.get()
        const orderLabel = fixture.controller.frame.orderLabel.get()
        for (const choice of ['tiles', 'table', 'tiles'])
          await view.change(() =>
            byId(view.root, 'hilos-table-view-' + choice).click(),
          )
        expect(fixture.sent).toHaveLength(asked)
        expect(fixture.controller.search.get()).toBe('needle')
        expect(fixture.controller.filter.get()).toEqual(filter)
        expect(fixture.controller.frame.orderLabel.get()).toBe(orderLabel)
        expect(fixture.controller.selection.count.get()).toBe(1)
        expect(fixture.controller.frame.view.get()).toBe('tiles')
      },
    )

    for (const body of ['empty', 'empty_filtered'] as const) {
      scenario(
        'V05 leaves the choice enabled for known ' + body,
        async (resources) => {
          const fixture = resources.create(
            frame({ search: {} }),
            body === 'empty_filtered' ? { search: 'missing' } : undefined,
          )
          fixture.controller.ingestWindow([], 0, true, null, null, 10)
          expect(fixture.controller.frame.body.get()).toBe(body)
          const view = await resources.mount(fixture)
          expect(buttons(view.root).map((button) => button.disabled)).toEqual([
            false,
            false,
          ])
          await view.change(() =>
            byId(view.root, 'hilos-table-view-tiles').click(),
          )
          expect(fixture.controller.frame.view.get()).toBe('tiles')
          expect(fixture.sent).toEqual([])
        },
      )
    }

    scenario(
      'V07 leaves the local choice enabled while refused window controls wait',
      async (resources) => {
        const fixture = resources.create(
          frame({
            search: {},
            filters: [
              { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
            ],
          }),
          { unread: true },
          undefined,
          false,
        )
        fixture.controller.ingestRefusal('internal_error')
        const view = await resources.mount(fixture)
        expect(
          byId<HTMLInputElement>(view.root, 'hilos-table-search').disabled,
        ).toBe(true)
        expect(buttons(view.root).map((button) => button.disabled)).toEqual([
          false,
          false,
        ])
        await view.change(() =>
          byId(view.root, 'hilos-table-view-tiles').click(),
        )
        expect(fixture.controller.frame.view.get()).toBe('tiles')
        expect(fixture.sent).toEqual([])
      },
    )

    scenario(
      'V09 draws neither a view group nor ordinary controls for a bare table',
      async (resources) => {
        const view = await resources.mount(
          resources.create({ columns: COLUMNS }),
        )
        expect(
          view.root.querySelector('[data-id="hilos-table-view"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-table-controls"]'),
        ).toBeNull()
      },
    )

    scenario(
      'V10 retains every semantic order option through layout-only switches',
      async (resources) => {
        const fixture = ordered(resources)
        const view = await resources.mount(fixture)
        expect(orderReading(view.root)).toEqual(EVERY_ORDER)
        expect(fixture.sent).toEqual([])
        await view.change(() =>
          byId(view.root, 'hilos-table-view-tiles').click(),
        )
        expect(orderReading(view.root)).toEqual(EVERY_ORDER)
        expect(
          [
            byId(view.root, 'hilos-table-controls'),
            byId(view.root, 'hilos-table-order'),
            ...orderItems(view.root),
          ].some(withdrawn),
        ).toBe(false)
        expect(fixture.sent).toEqual([])
        await view.change(() =>
          byId(view.root, 'hilos-table-view-table').click(),
        )
        expect(orderReading(view.root)).toEqual(EVERY_ORDER)
        expect(fixture.sent).toEqual([])
      },
    )

    for (const direction of ['asc', 'desc'] as const) {
      scenario(
        'V10 sends the explicit ' + direction + ' order picked in Tiles',
        async (resources) => {
          const fixture = ordered(resources)
          const view = await resources.mount(fixture)
          await view.change(() =>
            byId(view.root, 'hilos-table-view-tiles').click(),
          )
          expect(fixture.sent).toEqual([])
          await view.change(() =>
            byId(view.root, 'hilos-table-order-channel-' + direction).click(),
          )
          expect(fixture.sent.at(-1)).toMatchObject({
            sort: [{ field: 'channel', direction }],
          })
          expect(fixture.controller.frame.view.get()).toBe('tiles')
          expect(reading(view.root)).toEqual([
            ['Table', 'false'],
            ['Tiles', 'true'],
          ])
        },
      )
    }

    scenario(
      'V15 keeps the view outside withdrawn combined controls while marked',
      async (resources) => {
        const fixture = combined(resources, runner, true)
        const view = await resources.mount(fixture)
        await view.change(() => fixture.controller.selectRow('a', true))
        const controls = byId(view.root, 'hilos-table-controls')
        const group = byId(view.root, 'hilos-table-view')
        expect(controls.getAttribute('aria-hidden')).toBe('true')
        expect(controls.contains(byId(view.root, 'hilos-table-actions'))).toBe(
          true,
        )
        expect(controls.contains(group)).toBe(false)
        expect(withdrawn(group)).toBe(false)
        await view.change(() =>
          byId(view.root, 'hilos-table-view-tiles').click(),
        )
        expect(fixture.controller.frame.view.get()).toBe('tiles')
        expect(fixture.controller.selection.count.get()).toBe(1)
        expect(fixture.sent).toEqual([])
      },
    )
  })
}
