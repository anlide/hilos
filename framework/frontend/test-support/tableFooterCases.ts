import { TableViewportController } from '@hilos/core/base'
import type { TableRow, TableViewportDescriptor } from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** The real footer reads the controller supplied by its table. */
export interface TableFooterInput {
  controller: TableViewportController<unknown>
}

/** Native lifecycle delivery only; queries and controller actions stay common. */
export interface TableFooterView {
  readonly root: HTMLElement
  change(action: () => void): Promise<void>
  unmount(): Promise<void> | void
}

type Runner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>
type Mount = (input: TableFooterInput) => Promise<TableFooterView>
interface FooterFixture {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
}
interface Resources {
  create(): FooterFixture
  mount(fixture: FooterFixture): Promise<TableFooterView>
}

const FRAME = { title: 'Backups', columns: [{ key: 'name', label: 'Name' }] }

function window(size: number): TableRow[] {
  return Array.from({ length: size }, (_unused, index) => ({
    rowKey: `row-${index}`,
    slots: { name: `Row ${index}` },
  }))
}

/** Paging needs the real window boundaries to send its next request. */
function ingestPage(
  controller: TableViewportController<unknown>,
  totalCount: number,
): void {
  const rows = window(20)
  controller.ingestWindow(
    rows,
    totalCount,
    true,
    { rowKey: rows[0]?.rowKey },
    { rowKey: rows[rows.length - 1]?.rowKey },
    20,
  )
}

function byId(root: HTMLElement, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Fail on a missing control instead of silently skipping a click. */
function button(root: HTMLElement, id: string): HTMLButtonElement {
  const node = byId(root, id)
  if (!(node instanceof HTMLButtonElement))
    throw new Error(`Missing table footer button: ${id}`)
  return node
}

function count(root: HTMLElement): string | undefined {
  return byId(root, 'hilos-table-count')?.textContent?.trim()
}

function pageNumbers(root: HTMLElement): string[] {
  return Array.from(
    root.querySelectorAll('[data-id^="hilos-table-page-"]'),
    (page) => page.textContent?.trim() ?? '',
  )
}

/** Register ownership before ingest or steps, then attempt every release. */
async function withResources(
  runner: Runner,
  nativeMount: Mount,
  steps: (resources: Resources) => Promise<void>,
): Promise<void> {
  const controllers: TableViewportController<unknown>[] = []
  const mounts: TableFooterView[] = []
  const errors: unknown[] = []
  try {
    await steps({
      create() {
        const sent: TableViewportDescriptor[] = []
        const controller = new TableViewportController<unknown>({
          resolve: (raw) => ({ name: String(raw.slots['name']) }),
          sendViewport: (descriptor) => sent.push(descriptor),
          frame: FRAME,
        })
        controllers.push(controller)
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
        return { controller, sent }
      },
      async mount(fixture) {
        const view = await nativeMount({ controller: fixture.controller })
        mounts.push(view)
        return view
      },
    })
  } catch (error) {
    errors.push(error)
  } finally {
    for (const view of mounts.reverse()) {
      for (const release of [
        () => view.unmount(),
        () => runner.expect(view.root.childElementCount).toBe(0),
        () => runner.expect(view.root.isConnected).toBe(false),
      ]) {
        try {
          await release()
        } catch (error) {
          errors.push(error)
        }
      }
    }
    for (const controller of controllers.reverse()) {
      try {
        controller.dispose()
      } catch (error) {
        errors.push(error)
      }
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Table footer case and cleanup failed')
}

/** One semantic union of range, retained departures and pager behavior. */
export function registerTableFooterCases(
  sdk: string,
  runner: Runner,
  mount: Mount,
): void {
  const { describe, it, expect } = runner
  function scenario(
    name: string,
    steps: (resources: Resources) => Promise<void>,
  ): void {
    it(name, () => withResources(runner, mount, steps))
  }
  describe(`HilosTableFooter on ${sdk}`, () => {
    scenario(
      'D01 prints the range on screen and an exact total',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.ingestWindow(window(20), 128, true, null, null, 20)
        const view = await resources.mount(fixture)
        expect(count(view.root)).toBe('1 – 20 of 128')
      },
    )

    scenario(
      'D02 marks a total that stopped at its ceiling with a trailing plus',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.ingestWindow(window(20), 500, false, null, null, 20)
        const view = await resources.mount(fixture)
        expect(count(view.root)).toBe('1 – 20 of 500+')
      },
    )

    scenario(
      'D03 counts the range from the page the window sits on',
      async (resources) => {
        const fixture = resources.create()
        ingestPage(fixture.controller, 128)
        const view = await resources.mount(fixture)
        await view.change(() => button(view.root, 'hilos-table-next').click())
        await view.change(() => ingestPage(fixture.controller, 128))
        expect(count(view.root)).toBe('21 – 40 of 128')
      },
    )

    scenario(
      'D04 draws nothing at all on an empty window',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.ingestWindow([], 0, true, null, null, 20)
        const view = await resources.mount(fixture)
        expect(byId(view.root, 'hilos-table-count')).toBeNull()
        expect(byId(view.root, 'hilos-table-prev')).toBeNull()
      },
    )

    it.each([
      {
        timing: 'before removal',
        beforeApply: '0 – 0 of 0',
        afterApply: '0 – 0 of 0',
      },
      {
        timing: 'before Apply',
        beforeApply: '0 – 0 of 0',
        afterApply: '0 – 0 of 0',
      },
      {
        timing: 'after Apply',
        beforeApply: '1 – 1 of 1',
        afterApply: '0 – 0 of 1',
      },
    ] as const)(
      'D05 keeps the retained zero range when zero count arrives $timing',
      ({ timing, beforeApply, afterApply }) =>
        withResources(runner, mount, async (resources) => {
          const fixture = resources.create()
          const { controller, sent } = fixture
          controller.ingestWindow(window(1), 1, true, null, null, 20)
          const view = await resources.mount(fixture)
          expect(count(view.root)).toBe('1 – 1 of 1')

          if (timing === 'before removal')
            await view.change(() => controller.ingestCount(0, true))
          await view.change(() =>
            controller.ingestDelta({
              kind: 'row_removed',
              rowKey: 'row-0',
              reason: 'deleted',
            }),
          )
          if (timing === 'before Apply')
            await view.change(() => controller.ingestCount(0, true))
          expect(controller.rows.get()[0]?.placeholder).toBe(false)
          expect(controller.rows.get()[0]?.row).toEqual({ name: 'Row 0' })
          expect(count(view.root)).toBe(beforeApply)

          await view.change(() => controller.apply())
          expect(controller.frame.body.get()).toBe('rows')
          expect(count(view.root)).toBe(afterApply)
          if (timing === 'after Apply')
            await view.change(() => controller.ingestCount(0, true))
          expect(count(view.root)).toBe('0 – 0 of 0')
          expect(controller.pendingCount.get()).toBe(0)
          expect(controller.rows.get()[0]?.placeholder).toBe(true)
          expect(byId(view.root, 'hilos-table-prev')).toBeNull()
          expect(byId(view.root, 'hilos-table-next')).toBeNull()
          expect(pageNumbers(view.root)).toEqual([])
          expect(sent).toEqual([])

          // Body changes even when the footer's range and count stay the same.
          await view.change(() =>
            controller.ingestWindow([], 0, true, null, null, 20),
          )
          expect(count(view.root)).toBeUndefined()
        }),
    )

    scenario(
      'D06 draws nothing at all when the window was refused',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.ingestWindow(window(20), 128, true, null, null, 20)
        fixture.controller.ingestRefusal('internal_error')
        const view = await resources.mount(fixture)
        expect(byId(view.root, 'hilos-table-count')).toBeNull()
        expect(byId(view.root, 'hilos-table-prev')).toBeNull()
      },
    )

    scenario(
      'D07 disables the step back on the first page and the step on at the last',
      async (resources) => {
        const fixture = resources.create()
        const { controller } = fixture
        controller.ingestWindow(window(20), 21, true, null, null, 20)
        const view = await resources.mount(fixture)
        expect(button(view.root, 'hilos-table-prev').disabled).toBe(true)
        expect(button(view.root, 'hilos-table-next').disabled).toBe(false)
        await view.change(() => {
          controller.setPage(1)
          controller.ingestWindow(window(1), 21, true, null, null, 20)
        })
        expect(button(view.root, 'hilos-table-next').disabled).toBe(true)
      },
    )

    scenario(
      'D08 draws no pager when the whole set fits on one page',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.ingestWindow(window(2), 2, true, null, null, 20)
        const view = await resources.mount(fixture)
        expect(count(view.root)).toBe('1 – 2 of 2')
        expect(byId(view.root, 'hilos-table-prev')).toBeNull()
        expect(byId(view.root, 'hilos-table-next')).toBeNull()
        expect(pageNumbers(view.root)).toEqual([])
      },
    )

    scenario(
      'D09 offers page numbers exactly while the count is exact',
      async (resources) => {
        const fixture = resources.create()
        ingestPage(fixture.controller, 128)
        const view = await resources.mount(fixture)
        expect(pageNumbers(view.root)).toEqual([
          '1',
          '2',
          '3',
          '4',
          '5',
          '6',
          '7',
        ])
      },
    )

    scenario(
      'D10 offers no page numbers while the count stands at its ceiling',
      async (resources) => {
        const fixture = resources.create()
        fixture.controller.ingestWindow(window(20), 500, false, null, null, 20)
        const view = await resources.mount(fixture)
        expect(pageNumbers(view.root)).toEqual([])
        expect(button(view.root, 'hilos-table-prev').textContent?.trim()).toBe(
          'Previous',
        )
        expect(button(view.root, 'hilos-table-next').textContent?.trim()).toBe(
          'Next',
        )
      },
    )

    scenario(
      'D11 jumps to the page whose number was pressed',
      async (resources) => {
        const fixture = resources.create()
        ingestPage(fixture.controller, 128)
        const view = await resources.mount(fixture)
        await view.change(() => button(view.root, 'hilos-table-page-4').click())
        expect(fixture.sent.at(-1)).toMatchObject({
          pageIndex: 3,
          anchor: null,
        })
      },
    )

    scenario(
      'D12 states the page the reader stands on rather than offering it again',
      async (resources) => {
        const fixture = resources.create()
        ingestPage(fixture.controller, 128)
        const view = await resources.mount(fixture)
        const current = button(view.root, 'hilos-table-page-1')
        expect(current.disabled).toBe(true)
        expect(current.getAttribute('aria-current')).toBe('page')
      },
    )

    scenario(
      'D13 passes over the pages between the ends when there are too many to draw',
      async (resources) => {
        const fixture = resources.create()
        ingestPage(fixture.controller, 400)
        const view = await resources.mount(fixture)
        await view.change(() => fixture.controller.setPage(9))
        const numbers = pageNumbers(view.root)
        expect(numbers.length).toBeLessThanOrEqual(5)
        expect(numbers.at(0)).toBe('1')
        expect(numbers.at(-1)).toBe('20')
        // Gaps are decorative ellipses; hidden arrow icons are not gaps.
        const gaps = Array.from(view.root.querySelectorAll('span')).filter(
          (node) => node.textContent?.trim() === '…',
        )
        expect(gaps).toHaveLength(2)
        for (const gap of gaps)
          expect(gap.getAttribute('aria-hidden')).toBe('true')
      },
    )

    scenario(
      'D14 leaves the steps their words only where no numbers stand beside them',
      async (resources) => {
        const fixture = resources.create()
        ingestPage(fixture.controller, 128)
        const view = await resources.mount(fixture)
        const previous = button(view.root, 'hilos-table-prev')
        expect(previous.textContent?.trim()).toBe('')
        expect(previous.getAttribute('aria-label')).toBe('Previous page')
        expect(
          button(view.root, 'hilos-table-next').getAttribute('aria-label'),
        ).toBe('Next page')
      },
    )

    scenario(
      'D15 steps the window on and back through the controller',
      async (resources) => {
        const fixture = resources.create()
        ingestPage(fixture.controller, 128)
        const view = await resources.mount(fixture)
        await view.change(() => button(view.root, 'hilos-table-next').click())
        expect(fixture.sent).toHaveLength(1)
        await view.change(() => ingestPage(fixture.controller, 128))
        await view.change(() => button(view.root, 'hilos-table-prev').click())
        expect(fixture.sent).toHaveLength(2)
      },
    )

    scenario(
      'D16 keeps a batch of last-row departures through a zero count and Apply',
      async (resources) => {
        const fixture = resources.create()
        const { controller } = fixture
        controller.ingestWindow(window(2), 2, true, null, null, 20)
        const view = await resources.mount(fixture)
        await view.change(() => {
          for (const rowKey of ['row-0', 'row-1']) {
            controller.ingestDelta({
              kind: 'row_removed',
              rowKey,
              reason: 'deleted',
            })
          }
          controller.ingestCount(0, true)
        })
        expect(controller.pendingCount.get()).toBe(2)
        expect(count(view.root)).toBe('0 – 0 of 0')
        await view.change(() => controller.apply())
        expect(controller.frame.body.get()).toBe('rows')
        expect(controller.rows.get().every((row) => row.placeholder)).toBe(true)
        expect(count(view.root)).toBe('0 – 0 of 0')
        expect(byId(view.root, 'hilos-table-prev')).toBeNull()
        expect(byId(view.root, 'hilos-table-next')).toBeNull()
      },
    )

    scenario(
      'D17 keeps the zero range after an own removal with no pending Apply',
      async (resources) => {
        const fixture = resources.create()
        const { controller } = fixture
        controller.ingestWindow(window(1), 1, true, null, null, 20)
        const view = await resources.mount(fixture)
        await view.change(() => {
          controller.ingestDelta({
            kind: 'row_removed',
            rowKey: 'row-0',
            reason: 'deleted',
            own: true,
          })
          controller.ingestCount(0, true)
        })
        expect(controller.frame.body.get()).toBe('rows')
        expect(controller.pendingCount.get()).toBe(0)
        expect(controller.rows.get()[0]?.placeholder).toBe(true)
        expect(count(view.root)).toBe('0 – 0 of 0')
      },
    )
  })
}
