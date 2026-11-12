import { HILOS_VIEW_MODE_STRIP_TEXT_ID } from '@hilos/core/base'
import {
  withTableSelectionResources,
  type TableSelectionMount,
  type TableSelectionResources,
  type TableSelectionRunner,
} from './tableSelectionFixtures.js'

function byId(root: HTMLElement, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** A missing native button must fail instead of silently skipping an action. */
function button(root: HTMLElement, id: string): HTMLButtonElement {
  const node = byId(root, id)
  if (!(node instanceof HTMLButtonElement))
    throw new Error(`Missing table selection button: ${id}`)
  return node
}

function count(root: HTMLElement): string | undefined {
  return byId(root, 'hilos-table-selection-count')?.textContent?.trim()
}

/** Selection state and view-mode proof on the three real panel implementations. */
export function registerTableSelectionStateCases(
  sdk: string,
  runner: TableSelectionRunner,
  mount: TableSelectionMount,
): void {
  const { describe, it, expect } = runner
  function scenario(
    name: string,
    steps: (resources: TableSelectionResources) => Promise<void>,
  ): void {
    it(name, () => withTableSelectionResources(runner, mount, steps))
  }
  describe(`HilosTableSelection state on ${sdk}`, () => {
    scenario(
      'S01 counts page marks and names the filtered condition',
      async (resources) => {
        const fixture = resources.create()
        const { controller } = fixture
        controller.selectRow('a', true)
        const view = await resources.mount(fixture)
        expect(count(view.root)).toBe('1 marked on this page')
        expect(
          byId(view.root, 'hilos-table-select-all-filtered'),
        ).not.toBeNull()
        await view.change(() => controller.selectAllByFilter())
        expect(count(view.root)).toBe('All rows matching the filter')
        expect(byId(view.root, 'hilos-table-select-all-filtered')).toBeNull()
      },
    )

    scenario('S02 clears the marks through the core', async (resources) => {
      const fixture = resources.create()
      fixture.controller.selectRow('a', true)
      const view = await resources.mount(fixture)
      await view.change(() =>
        button(view.root, 'hilos-table-selection-clear').click(),
      )
      expect(fixture.controller.selection.count.get()).toBe(0)
    })

    scenario(
      'S03 disables operations while a bulk run is going',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.selectRow('a', true)
        const view = await resources.mount(fixture)
        expect(button(view.root, 'hilos-table-bulk-delete').disabled).toBe(
          false,
        )
        await view.change(() =>
          fixture.controller.ingestProgress({
            scope: 'bulk',
            progressKey: 'run-1',
            current: 1,
            total: 4,
          }),
        )
        expect(button(view.root, 'hilos-table-bulk-delete').disabled).toBe(true)
      },
    )

    scenario(
      'S08 keeps its open dialog when the same panel stops standing',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.selectRow('a', true)
        const view = await resources.mount(fixture)
        const document = view.root.ownerDocument
        const initial = new Set(document.querySelectorAll('[role="dialog"]'))
        await view.change(() =>
          button(view.root, 'hilos-table-bulk-delete').click(),
        )
        const dialogs = Array.from(
          document.querySelectorAll('[role="dialog"]'),
        ).filter((dialog) => !initial.has(dialog))
        expect(dialogs).toHaveLength(1)
        const dialog = dialogs[0]!
        const panel = byId(view.root, 'hilos-table-selection')
        await view.updateShown(false)
        expect(byId(view.root, 'hilos-table-selection')).toBe(panel)
        expect(panel?.getAttribute('aria-hidden')).toBe('true')
        expect(dialog.isConnected).toBe(true)
        expect(
          Array.from(document.querySelectorAll('[role="dialog"]')).filter(
            (node) => !initial.has(node),
          ),
        ).toEqual([dialog])
      },
    )

    scenario(
      'S09 names the unselected panel as one group',
      async (resources) => {
        const view = await resources.mount(resources.create())
        const group = byId(view.root, 'hilos-table-selection')
        expect(group?.getAttribute('role')).toBe('group')
        expect(group?.getAttribute('aria-label')).toBe('Selection')
      },
    )

    scenario(
      'S10 disables operations in view mode and keeps selection live',
      async (resources) => {
        const fixture = resources.create()
        const { controller, asked } = fixture
        controller.selectRow('a', true)
        const view = await resources.mount(fixture, { adminViewMode: true })
        const operation = button(view.root, 'hilos-table-bulk-delete')
        expect(operation.disabled).toBe(true)
        expect(operation.getAttribute('aria-describedby')).toBe(
          HILOS_VIEW_MODE_STRIP_TEXT_ID,
        )
        const selectAll = button(view.root, 'hilos-table-select-all-filtered')
        const clear = button(view.root, 'hilos-table-selection-clear')
        expect(selectAll.disabled).toBe(false)
        expect(clear.disabled).toBe(false)
        await view.change(() => selectAll.click())
        expect(controller.selection.target.get()?.kind).toBe('filter')
        await view.change(() => clear.click())
        expect(controller.selection.count.get()).toBe(0)
        expect(asked).toEqual([])
      },
    )

    scenario(
      'S11 leaves operations enabled with absent and false providers',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.selectRow('a', true)
        const absent = await resources.mount(fixture)
        const outside = button(absent.root, 'hilos-table-bulk-delete')
        expect(outside.disabled).toBe(false)
        expect(outside.getAttribute('aria-describedby')).toBeNull()
        await absent.unmount()

        const off = await resources.mount(fixture, { adminViewMode: false })
        const operation = button(off.root, 'hilos-table-bulk-delete')
        expect(operation.disabled).toBe(false)
        expect(operation.getAttribute('aria-describedby')).toBeNull()
      },
    )
  })
}
