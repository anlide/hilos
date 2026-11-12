import {
  TABLE_VIEW_STORAGE_KEY,
  TableViewportController,
} from '@hilos/core/base'
import type { HilosTableFrame, TableRow } from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** The real public table receives only its controller. */
export interface TableControllerReplacementInput {
  controller: TableViewportController<unknown>
}

/** Native delivery on one public owner; queries and expectations stay common. */
export interface TableControllerReplacementView {
  readonly root: HTMLElement
  replaceController(next: TableViewportController<unknown>): Promise<void>
  change(action: () => void): Promise<void>
  unmount(): Promise<void> | void
}

type Runner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>
type Mount = (
  input: TableControllerReplacementInput,
) => Promise<TableControllerReplacementView>

interface Resources {
  own(release: () => unknown): void
  create(
    frame: HilosTableFrame,
    known?: boolean,
  ): TableViewportController<unknown>
  mount(
    controller: TableViewportController<unknown>,
  ): Promise<TableControllerReplacementView>
}

const COLUMNS = [{ key: 'name', label: 'Name' }]

/** Own each controller before ingest and each successful native mount on return. */
async function withReplacementResources(
  nativeMount: Mount,
  steps: (resources: Resources) => Promise<void>,
): Promise<void> {
  const releases: (() => unknown)[] = []
  const errors: unknown[] = []
  try {
    await steps({
      own: (release) => releases.push(release),
      create(frame, known = false) {
        const controller = new TableViewportController<unknown>({
          resolve: (raw) => ({ name: String(raw.slots['name']) }),
          sendViewport: () => {},
          frame,
        })
        releases.push(() => controller.dispose())
        if (known)
          controller.ingestSubscriptionWindow(
            [],
            0,
            true,
            null,
            null,
            10,
            undefined,
            {},
            [],
          )
        return controller
      },
      async mount(controller) {
        const view = await nativeMount({ controller })
        releases.push(() => view.unmount())
        return view
      },
    })
  } catch (error) {
    errors.push(error)
  } finally {
    for (const release of releases.reverse()) {
      try {
        await release()
      } catch (error) {
        errors.push(error)
      }
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(
      errors,
      'Table controller replacement cleanup failed',
      {
        cause: errors[0],
      },
    )
}

function window(size: number): TableRow[] {
  return Array.from({ length: size }, (_unused, index) => ({
    rowKey: `row-${index}`,
    slots: { name: `Row ${index}` },
  }))
}

/** Shared output at the public owner, beside the retained Angular child lifecycle proofs. */
export function registerTableControllerReplacementCases(
  sdk: string,
  runner: Runner,
  mount: Mount,
): void {
  const { describe, it, expect } = runner
  function viewReading(
    root: HTMLElement,
  ): [string | undefined, string | null][] {
    const group = root.querySelector('[data-id="hilos-table-view"]')
    expect(group).not.toBeNull()
    if (group === null) throw new Error('Missing table view group')
    return Array.from(group.querySelectorAll('button'), (button) => [
      button.textContent?.trim(),
      button.getAttribute('aria-pressed'),
    ])
  }

  describe('HilosViewportTable controller replacement on ' + sdk, () => {
    // Angular Bar origin: four output observations and three group preconditions.
    it('VC01 follows the current view choices and releases the former controller', () =>
      withReplacementResources(mount, async (resources) => {
        const storage = localStorage
        const previous = storage.getItem(TABLE_VIEW_STORAGE_KEY)
        resources.own(() => {
          if (previous === null) storage.removeItem(TABLE_VIEW_STORAGE_KEY)
          else storage.setItem(TABLE_VIEW_STORAGE_KEY, previous)
        })
        storage.removeItem(TABLE_VIEW_STORAGE_KEY)
        const first = resources.create({
          title: 'Pictures',
          columns: COLUMNS,
          views: {
            tableKey: 'pictures',
            order: ['table', 'tiles'],
            imageColumn: 'name',
          },
        })
        const second = resources.create({
          title: 'Pictures',
          columns: COLUMNS,
          views: {
            tableKey: 'pictures',
            order: ['tiles', 'table'],
            imageColumn: 'name',
          },
        })
        const plain = resources.create({ columns: COLUMNS }, true)
        const view = await resources.mount(first)
        await view.change(() => {
          const tiles = view.root.querySelector<HTMLButtonElement>(
            '[data-id="hilos-table-view-tiles"]',
          )
          if (tiles === null) throw new Error('Missing Tiles button')
          tiles.click()
        })
        expect(viewReading(view.root)).toEqual([
          ['Table', 'false'],
          ['Tiles', 'true'],
        ])

        await view.replaceController(second)
        expect(viewReading(view.root)).toEqual([
          ['Tiles', 'true'],
          ['Table', 'false'],
        ])
        await view.change(() => first.setView('table'))
        expect(viewReading(view.root)).toEqual([
          ['Tiles', 'true'],
          ['Table', 'false'],
        ])

        await view.replaceController(plain)
        expect(
          view.root.querySelector('[data-id="hilos-table-view"]'),
        ).toBeNull()
      }))

    // Angular Footer's settled count is common; its four get-call proofs stay native.
    it('DC01 follows the current count and releases the public table on unmount', () =>
      withReplacementResources(mount, async (resources) => {
        const frame = { title: 'Backups', columns: COLUMNS }
        const first = resources.create(frame, true)
        first.ingestWindow(window(1), 1, true, null, null, 20)
        const second = resources.create(frame, true)
        second.ingestWindow(window(2), 2, true, null, null, 20)
        const view = await resources.mount(first)
        const count = () =>
          view.root
            .querySelector('[data-id="hilos-table-count"]')
            ?.textContent?.trim()
        expect(count()).toBe('1 – 1 of 1')

        await view.replaceController(second)
        expect(count()).toBe('1 – 2 of 2')
        // Extra public-owner proof: the old source cannot change the current output.
        await view.change(() => {
          first.ingestRefusal('internal_error')
          first.ingestCount(9, true)
        })
        expect(count()).toBe('1 – 2 of 2')

        await view.unmount()
        await view.change(() => {
          second.ingestRefusal('internal_error')
          second.ingestCount(9, true)
        })
        expect(view.root.childElementCount).toBe(0)
        expect(view.root.isConnected).toBe(false)
      }))
  })
}
