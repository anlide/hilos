import type { HilosTableColumn } from '@hilos/core/base'
import type {
  TableBarMount,
  TableBarResources,
  TableBarRunner,
  TableBarView,
} from './tableBarFixtures.js'
import { withTableBarResources } from './tableBarFixtures.js'

const COLUMNS: readonly HilosTableColumn[] = [{ key: 'name', label: 'Name' }]

function windowed(resources: TableBarResources) {
  const fixture = resources.create({
    title: 'Backups',
    search: {},
    columns: COLUMNS,
    bulkActions: [
      {
        key: 'delete',
        label: 'Delete',
        refusalTitle: "Couldn't delete",
        danger: true,
        run: () => {
          throw new Error('the declaration is only read here')
        },
      },
    ],
  })
  fixture.controller.ingestWindow(
    [
      { rowKey: 'a', slots: { name: 'Alice' } },
      { rowKey: 'b', slots: { name: 'Bob' } },
    ],
    2,
    true,
    null,
    null,
    10,
  )

  return fixture
}

function required<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  selector: string,
): T {
  const node = root.querySelector<T>(selector)
  if (node === null) throw new Error(`Missing table bar node: ${selector}`)
  return node
}

/** Selection-panel behavior shared by the real Vue, React, and Angular bars. */
export function registerTableBarSelectionCases(
  sdk: string,
  runner: TableBarRunner,
  mount: TableBarMount,
): void {
  const { describe, it, expect } = runner
  function expectTitle(view: TableBarView): void {
    const title = required(view.root, '[data-id="hilos-table-title"]')
    expect(title.textContent?.trim()).toBe('Backups')
    expect(title.getAttribute('id')).toBe('table-title')
  }

  function expectOrdinaryBar(view: TableBarView): void {
    expectTitle(view)
    const controls = required(view.root, '[data-id="hilos-table-controls"]')
    const panel = required(view.root, '[data-id="hilos-table-selection"]')
    expect(controls.getAttribute('aria-hidden')).toBeNull()
    expect(panel.getAttribute('aria-hidden')).toBe('true')
  }

  function expectSelectionPanel(view: TableBarView): void {
    expectTitle(view)
    const controls = required(view.root, '[data-id="hilos-table-controls"]')
    const panel = required(view.root, '[data-id="hilos-table-selection"]')
    expect(controls.getAttribute('aria-hidden')).toBe('true')
    expect(panel.getAttribute('aria-hidden')).toBeNull()
  }

  describe('HilosTableBar selection panel on ' + sdk, () => {
    it('P01 keeps both selection strips mounted with ordinary controls exposed', () =>
      withTableBarResources(runner, mount, async (resources) => {
        const fixture = windowed(resources)
        const view = await resources.mount(fixture)

        expect(
          view.root.querySelector('[data-id="hilos-table-bar-slot"]'),
        ).not.toBeNull()
        expectOrdinaryBar(view)
      }))

    it('P02 omits the selection slot and panel without bulk actions', () =>
      withTableBarResources(runner, mount, async (resources) => {
        const view = await resources.mount(
          resources.create({
            title: 'Backups',
            search: {},
            columns: COLUMNS,
          }),
        )

        expect(
          view.root.querySelector('[data-id="hilos-table-bar-slot"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-table-controls"]'),
        ).not.toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-table-selection"]'),
        ).toBeNull()
      }))

    it('P03 keeps the open confirmation when clearing selection restores controls', () =>
      withTableBarResources(runner, mount, async (resources) => {
        const fixture = windowed(resources)
        const view = await resources.mount(fixture)

        await view.change(() => fixture.controller.selectRow('a', true))
        await view.change(() =>
          required(view.root, '[data-id="hilos-table-bulk-delete"]').click(),
        )
        const confirmation =
          view.root.ownerDocument.querySelector('[data-id="modal"]')
        expect(confirmation).not.toBeNull()

        await view.change(() => fixture.controller.clearSelection())

        expectOrdinaryBar(view)
        expect(view.root.ownerDocument.querySelector('[data-id="modal"]')).toBe(
          confirmation,
        )
        expect(confirmation?.isConnected).toBe(true)
      }))

    it('P04 exposes the selection panel after the first row is marked', () =>
      withTableBarResources(runner, mount, async (resources) => {
        const fixture = windowed(resources)
        const view = await resources.mount(fixture)

        await view.change(() => fixture.controller.selectRow('a', true))

        expectSelectionPanel(view)
      }))

    it('P05 keeps ordinary controls through bulk progress and its report', () =>
      withTableBarResources(runner, mount, async (resources) => {
        const fixture = windowed(resources)
        const view = await resources.mount(fixture)

        await view.change(() =>
          fixture.controller.ingestProgress({
            scope: 'bulk',
            progressKey: 'run-1',
            current: 12,
            total: 40,
          }),
        )
        expect(fixture.controller.selection.count.get()).toBe(0)
        expectOrdinaryBar(view)

        await view.change(() =>
          fixture.controller.ingestBulkReport({
            progressKey: 'run-1',
            touched: 39,
            untouched: [],
            untouchedOmitted: 0,
          }),
        )
        expectOrdinaryBar(view)
      }))
  })
}
