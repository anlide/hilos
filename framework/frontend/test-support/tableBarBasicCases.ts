import type { HilosTableFrame } from '@hilos/core/base'
import type {
  TableBarMount,
  TableBarResources,
  TableBarRunner,
  TableBarView,
} from './tableBarFixtures.js'
import { withTableBarResources } from './tableBarFixtures.js'

const COLUMNS = [{ key: 'name', label: 'Name' }]
const FILTERED: HilosTableFrame = {
  title: 'Deliveries',
  search: {},
  filters: [
    { kind: 'toggle', key: 'state', label: 'Failed only', on: 'failed' },
    { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
  ],
  columns: COLUMNS,
}

function required<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  selector: string,
): T {
  const node = root.querySelector<T>(selector)
  if (node === null) throw new Error(`Missing table bar node: ${selector}`)
  return node
}

function byId<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  return required<T>(root, `[data-id="${id}"]`)
}

/** The document is a portal scope, never a replacement for the native host. */
function dialogs(view: TableBarView, initial: Set<Element>): HTMLElement[] {
  return Array.from(
    view.root.ownerDocument.querySelectorAll<HTMLElement>('[role="dialog"]'),
  ).filter((dialog) => !initial.has(dialog))
}

/** Basic Bar behavior through the real controller and each SDK's real Modal. */
export function registerTableBarBasicCases(
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
  describe(`HilosTableBar basic on ${sdk}`, () => {
    for (const bulk of [false, true]) {
      scenario(
        `B00 disables unknown subscription controls until recovery, bulk: ${bulk}`,
        async (resources) => {
          const fixture = resources.create(
            {
              columns: [{ key: 'name', label: 'Name', sortable: true }],
              search: {},
              filters: [
                {
                  kind: 'select',
                  key: 'state',
                  label: 'State',
                  options: () => [{ value: 'up', label: 'Up' }],
                },
                {
                  kind: 'toggle',
                  key: 'unread',
                  label: 'Unread only',
                  on: true,
                },
                {
                  kind: 'date_range',
                  fromKey: 'from',
                  toKey: 'to',
                  label: 'Period',
                },
              ],
              mainAction: { label: 'Create', press: () => {} },
              ...(bulk
                ? {
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
                : {}),
            },
            { document: 'terms', unread: true },
            undefined,
            false,
          )
          const { controller, sent } = fixture
          controller.ingestRefusal('internal_error')
          const view = await resources.mount(fixture)
          const controls = [
            '[data-id="hilos-table-search"]',
            '[data-id="hilos-table-filter-unread"]',
            '[data-id="hilos-table-filter-from"]',
            '[data-id="hilos-table-filter-state"] [data-id="hilos-dropdown-toggle"]',
            '[data-id="hilos-table-order"] [data-id="hilos-dropdown-toggle"]',
            '[data-id="hilos-table-filter-reset"]',
            '[data-id="hilos-table-filters-open"]',
          ]
          for (const selector of controls)
            expect(
              required<HTMLInputElement | HTMLButtonElement>(
                view.root,
                selector,
              ).disabled,
            ).toBe(true)
          expect(
            byId<HTMLButtonElement>(view.root, 'hilos-table-main-action')
              .disabled,
          ).toBe(false)
          expect(controller.filter.get()).toEqual({
            document: 'terms',
            unread: true,
          })
          expect(sent).toEqual([])
          await view.change(() =>
            controller.ingestSubscriptionWindow(
              [],
              0,
              true,
              null,
              null,
              37,
              undefined,
              { document: 'terms', unread: true },
              [],
            ),
          )
          for (const selector of controls)
            expect(
              required<HTMLInputElement | HTMLButtonElement>(
                view.root,
                selector,
              ).disabled,
            ).toBe(false)
          await view.type(byId(view.root, 'hilos-table-search'), 'retention')
          expect(sent.at(-1)).toMatchObject({
            filter: { document: 'terms', unread: true, search: 'retention' },
            limit: 37,
            sort: null,
          })
        },
      )
    }

    scenario(
      'B01 shows the declared title, id and subtitle',
      async (resources) => {
        const fixture = resources.create({
          title: 'Backups',
          subtitle: 'Every copy this installation keeps',
          columns: COLUMNS,
        })
        const view = await resources.mount(fixture)
        const title = byId(view.root, 'hilos-table-title')
        expect(title.textContent?.trim()).toBe('Backups')
        expect(title.id).toBe('table-title')
        expect(
          byId(view.root, 'hilos-table-subtitle').textContent?.trim(),
        ).toBe('Every copy this installation keeps')
      },
    )

    scenario('B02 draws no undeclared subtitle', async (resources) => {
      const view = await resources.mount(
        resources.create({ title: 'Backups', columns: COLUMNS }),
      )
      expect(
        view.root.querySelector('[data-id="hilos-table-subtitle"]'),
      ).toBeNull()
    })

    scenario(
      'B03 omits heading and subtitle without a title, keeping search',
      async (resources) => {
        const view = await resources.mount(
          resources.create({
            subtitle: 'Every copy this installation keeps',
            search: {},
            columns: COLUMNS,
          }),
        )
        expect(
          view.root.querySelector('[data-id="hilos-table-title"]'),
        ).toBeNull()
        expect(view.root.querySelector('h2')).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-table-subtitle"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-table-search"]'),
        ).not.toBeNull()
      },
    )

    scenario('B04 draws no undeclared search', async (resources) => {
      const view = await resources.mount(
        resources.create({ title: 'Backups', columns: COLUMNS }),
      )
      expect(
        view.root.querySelector('[data-id="hilos-table-search"]'),
      ).toBeNull()
    })

    scenario(
      'B05 names search with the declared placeholder',
      async (resources) => {
        const view = await resources.mount(
          resources.create({
            title: 'Backups',
            search: { placeholder: 'Search backups…' },
            columns: COLUMNS,
          }),
        )
        const field = byId(view.root, 'hilos-table-search')
        expect(field.getAttribute('placeholder')).toBe('Search backups…')
        expect(field.getAttribute('aria-label')).toBe('Search backups…')
      },
    )

    scenario('B06 falls back to Search…', async (resources) => {
      const view = await resources.mount(
        resources.create({ title: 'Backups', search: {}, columns: COLUMNS }),
      )
      expect(
        byId(view.root, 'hilos-table-search').getAttribute('placeholder'),
      ).toBe('Search…')
    })

    scenario(
      'B07 sends one window for one search input event',
      async (resources) => {
        const fixture = resources.create({
          title: 'Backups',
          search: {},
          columns: COLUMNS,
        })
        const view = await resources.mount(fixture)
        await view.type(byId(view.root, 'hilos-table-search'), 'nig')
        expect(fixture.sent).toHaveLength(1)
        expect(fixture.sent.at(-1)?.filter).toMatchObject({ search: 'nig' })
      },
    )

    scenario(
      'B08 offers clear while search holds text and drops its key',
      async (resources) => {
        const fixture = resources.create({
          title: 'Backups',
          search: {},
          columns: COLUMNS,
        })
        const view = await resources.mount(fixture)
        expect(
          view.root.querySelector('[data-id="hilos-table-search-clear"]'),
        ).toBeNull()
        await view.type(byId(view.root, 'hilos-table-search'), 'nig')
        await view.change(() =>
          byId(view.root, 'hilos-table-search-clear').click(),
        )
        expect(fixture.sent.at(-1)?.filter).not.toHaveProperty('search')
      },
    )

    scenario(
      'B09 presses the declared main action once per click',
      async (resources) => {
        let pressed = 0
        const fixture = resources.create({
          title: 'Backups',
          columns: COLUMNS,
          mainAction: {
            label: 'Create backup',
            press: () => {
              pressed += 1
            },
          },
        })
        const view = await resources.mount(fixture)
        const button = byId(view.root, 'hilos-table-main-action')
        expect(button.textContent?.trim()).toBe('Create backup')
        await view.change(() => button.click())
        expect(pressed).toBe(1)
      },
    )

    scenario('B10 draws each declared filter control', async (resources) => {
      const view = await resources.mount(resources.create(FILTERED))
      expect(
        view.root.querySelector('[data-id="hilos-table-filter-state"]'),
      ).not.toBeNull()
      expect(
        view.root.querySelector('[data-id="hilos-table-filter-unread"]'),
      ).not.toBeNull()
    })

    scenario(
      'B11 counts active filters but never search',
      async (resources) => {
        const view = await resources.mount(
          resources.create(FILTERED, { state: 'failed' }),
        )
        expect(
          byId(view.root, 'hilos-table-filter-badge').textContent?.trim(),
        ).toBe('1 filter')
        await view.type(byId(view.root, 'hilos-table-search'), 'nig')
        expect(
          byId(view.root, 'hilos-table-filter-badge').textContent?.trim(),
        ).toBe('1 filter')
        await view.change(() =>
          byId(view.root, 'hilos-table-filter-unread').click(),
        )
        expect(
          byId(view.root, 'hilos-table-filter-badge').textContent?.trim(),
        ).toBe('2 filters')
      },
    )

    scenario('B12 omits the badge with no active filter', async (resources) => {
      const view = await resources.mount(resources.create(FILTERED))
      expect(
        view.root.querySelector('[data-id="hilos-table-filter-badge"]'),
      ).toBeNull()
    })

    scenario(
      'B13 resets to opening filters and clears the search field',
      async (resources) => {
        const fixture = resources.create(FILTERED, { state: 'failed' })
        const view = await resources.mount(fixture)
        await view.type(byId(view.root, 'hilos-table-search'), 'nig')
        await view.change(() =>
          byId(view.root, 'hilos-table-filter-unread').click(),
        )
        await view.change(() =>
          byId(view.root, 'hilos-table-filter-reset').click(),
        )
        expect(fixture.sent.at(-1)?.filter).toEqual({ state: 'failed' })
        expect(
          byId<HTMLInputElement>(view.root, 'hilos-table-search').value,
        ).toBe('')
      },
    )

    scenario(
      'B14 opens the real filters modal and closes it with Done',
      async (resources) => {
        const initial = new Set<Element>(
          document.querySelectorAll('[role="dialog"]'),
        )
        const view = await resources.mount(resources.create(FILTERED))
        expect(dialogs(view, initial)).toEqual([])
        await view.change(() => {
          const open = byId(view.root, 'hilos-table-filters-open')
          open.focus()
          open.click()
        })
        expect(dialogs(view, initial)).toHaveLength(1)
        const modal = dialogs(view, initial)[0]!
        expect(
          modal.querySelectorAll('[data-id="hilos-table-filter-state-modal"]'),
        ).toHaveLength(1)
        await view.change(() => byId(modal, 'hilos-table-filters-done').click())
        expect(dialogs(view, initial)).toEqual([])
        expect(modal.isConnected).toBe(false)
      },
    )

    scenario(
      'B15 keeps the Filters caption free of its count',
      async (resources) => {
        const view = await resources.mount(
          resources.create(FILTERED, { state: 'failed' }),
        )
        await view.change(() =>
          byId(view.root, 'hilos-table-filter-unread').click(),
        )
        expect(
          byId(view.root, 'hilos-table-filters-open').textContent?.trim(),
        ).toBe('Filters')
      },
    )

    scenario(
      'B16 offers no filter opener or dialog without filters',
      async (resources) => {
        const initial = new Set<Element>(
          document.querySelectorAll('[role="dialog"]'),
        )
        const view = await resources.mount(
          resources.create({ title: 'Backups', search: {}, columns: COLUMNS }),
        )
        expect(
          view.root.querySelector('[data-id="hilos-table-filters-open"]'),
        ).toBeNull()
        expect(dialogs(view, initial)).toEqual([])
      },
    )

    scenario('B17 draws no undeclared main action', async (resources) => {
      const view = await resources.mount(
        resources.create({ title: 'Backups', columns: COLUMNS }),
      )
      expect(
        view.root.querySelector('[data-id="hilos-table-main-action"]'),
      ).toBeNull()
    })
  })
}
