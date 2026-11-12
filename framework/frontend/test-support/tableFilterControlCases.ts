import { TableViewportController } from '@hilos/core/base'
import type {
  HilosTableFilter,
  HilosTableFilterView,
  TableViewportDescriptor,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** Inputs delivered to the real native filter control. */
export interface TableFilterControlInput {
  controller: TableViewportController<unknown>
  view: HilosTableFilterView
  placement: 'bar' | 'modal'
}

/** Owned native mount and event delivery, without queries or expectations. */
export interface TableFilterControlView {
  readonly root: HTMLElement
  unmount(): Promise<void> | void
  interact(action: () => void): Promise<void>
  enterDate(field: HTMLInputElement, value: string): Promise<void>
}

type Runner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>
type Mount = (input: TableFilterControlInput) => Promise<TableFilterControlView>
interface FilterFixture {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
  view(): HilosTableFilterView
}
interface Resources {
  create(
    filter: HilosTableFilter,
    initialFilter?: Record<string, unknown>,
  ): FilterFixture
  mount(
    fixture: FilterFixture,
    placement?: 'bar' | 'modal',
  ): Promise<TableFilterControlView>
}

const COLUMNS = [{ key: 'name', label: 'Name' }]
const KIND_FILTER: HilosTableFilter = {
  kind: 'select',
  key: 'kind',
  label: 'Kind',
  options: () => [
    { value: 'full', label: 'Full' },
    { value: 2, label: 'Partial' },
  ],
}
const KIND_FILTER_NAMED_ANY: HilosTableFilter = {
  kind: 'select',
  key: 'kind',
  label: 'Kind',
  anyLabel: 'All kinds',
  options: () => [{ value: 'full', label: 'Full' }],
}
const PERIOD_FILTER: HilosTableFilter = {
  kind: 'date_range',
  fromKey: 'from',
  toKey: 'to',
  label: 'Period',
}
const FAILED_FILTER: HilosTableFilter = {
  kind: 'toggle',
  key: 'state',
  label: 'Failed only',
  on: 'failed',
}
const COUNTS = {
  kind: {
    any: { count: 500, exact: false },
    options: {
      full: { count: 412, exact: true },
      '2': { count: 0, exact: true },
    },
  },
}

/** Require a real node even for assertions about missing values. */
function byId(root: HTMLElement, id: string): HTMLElement {
  const node = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (node === null) throw new Error(`Missing table filter control node: ${id}`)
  return node
}

function optionLabels(root: HTMLElement): string[] {
  return Array.from(
    root.querySelectorAll('[data-id^="hilos-dropdown-option-"]'),
  ).map((node) => node.textContent?.trim() ?? '')
}

/** Own controllers before ingest, mounts before steps, and attempt every release. */
async function withResources(
  runner: Runner,
  nativeMount: Mount,
  steps: (resources: Resources) => Promise<void>,
): Promise<void> {
  const controllers: TableViewportController<unknown>[] = []
  const mounts: TableFilterControlView[] = []
  const errors: unknown[] = []
  try {
    await steps({
      create(filter, initialFilter) {
        const sent: TableViewportDescriptor[] = []
        const controller = new TableViewportController<unknown>({
          resolve: (raw) => ({ name: String(raw.slots['name']) }),
          sendViewport: (descriptor) => sent.push(descriptor),
          initialFilter,
          frame: { title: 'Deliveries', filters: [filter], columns: COLUMNS },
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
          initialFilter ?? {},
          [],
        )
        return {
          controller,
          sent,
          view: () => controller.frame.filters.get()[0] as HilosTableFilterView,
        }
      },
      async mount(fixture, placement = 'bar') {
        const view = await nativeMount({
          controller: fixture.controller,
          view: fixture.view(),
          placement,
        })
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
    throw new AggregateError(
      errors,
      'Table filter control case and cleanup failed',
    )
}

/** Register the semantic union over select, facets, date range, toggle and placement. */
export function registerTableFilterControlCases(
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
  describe(`HilosTableFilterControl on ${sdk}`, () => {
    scenario(
      'F01 offers Any followed by the declared options in order',
      async (resources) => {
        const view = await resources.mount(resources.create(KIND_FILTER))
        expect(optionLabels(view.root)).toEqual(['Any', 'Full', 'Partial'])
      },
    )

    scenario('F02 uses the declared no-choice label', async (resources) => {
      const view = await resources.mount(
        resources.create(KIND_FILTER_NAMED_ANY),
      )
      expect(
        byId(view.root, 'hilos-dropdown-option--1').textContent?.trim(),
      ).toBe('All kinds')
    })

    scenario(
      'F03 sends one viewport with the declared numeric option value',
      async (resources) => {
        const fixture = resources.create(KIND_FILTER)
        const view = await resources.mount(fixture)
        await view.interact(() =>
          byId(view.root, 'hilos-dropdown-option-1').click(),
        )
        expect(fixture.sent).toHaveLength(1)
        expect(fixture.sent.at(-1)?.filter).toEqual({ kind: 2 })
      },
    )

    scenario(
      'F04 clears the selected key from a fresh mount of the same controller',
      async (resources) => {
        const fixture = resources.create(KIND_FILTER)
        const first = await resources.mount(fixture)
        await first.interact(() =>
          byId(first.root, 'hilos-dropdown-option-0').click(),
        )
        await first.unmount()
        expect(first.root.childElementCount).toBe(0)
        expect(first.root.isConnected).toBe(false)
        const next = await resources.mount(fixture)
        await next.interact(() =>
          byId(next.root, 'hilos-dropdown-option--1').click(),
        )
        expect(fixture.sent.at(-1)?.filter).not.toHaveProperty('kind')
      },
    )

    scenario(
      'F05 names the initially selected option in the toggle',
      async (resources) => {
        const view = await resources.mount(
          resources.create(KIND_FILTER, { kind: 'full' }),
        )
        expect(
          byId(view.root, 'hilos-dropdown-toggle').textContent?.trim(),
        ).toBe('Kind: Full')
      },
    )

    scenario(
      'F06 names Any while the filter has no value',
      async (resources) => {
        const view = await resources.mount(resources.create(KIND_FILTER))
        expect(
          byId(view.root, 'hilos-dropdown-toggle').textContent?.trim(),
        ).toBe('Kind: Any')
      },
    )

    scenario(
      'F07 preserves all labels with no facet nodes before counts arrive',
      async (resources) => {
        const view = await resources.mount(resources.create(KIND_FILTER))
        expect(
          view.root.querySelectorAll('[data-id^="hilos-table-facet-"]'),
        ).toHaveLength(0)
        expect(optionLabels(view.root)).toEqual(['Any', 'Full', 'Partial'])
      },
    )

    scenario(
      'F08 reports inexact, exact and zero counts separately from the label',
      async (resources) => {
        const fixture = resources.create(KIND_FILTER)
        fixture.controller.ingestFacetCounts(COUNTS)
        const view = await resources.mount(fixture)
        expect(
          byId(view.root, 'hilos-table-facet-kind-any').textContent?.trim(),
        ).toBe('500+')
        expect(
          byId(view.root, 'hilos-table-facet-kind-full').textContent?.trim(),
        ).toBe('412')
        expect(
          byId(view.root, 'hilos-table-facet-kind-2').textContent?.trim(),
        ).toBe('0')
        // Read the option's label independently of its count, without a styling selector.
        const option = byId(view.root, 'hilos-dropdown-option-0').cloneNode(
          true,
        ) as HTMLElement
        byId(option, 'hilos-table-facet-kind-full').remove()
        expect(option.textContent?.trim()).toBe('Full')
      },
    )

    scenario(
      'F09 keeps the declared numeric option value when counts are present',
      async (resources) => {
        const fixture = resources.create(KIND_FILTER)
        fixture.controller.ingestFacetCounts(COUNTS)
        const view = await resources.mount(fixture)
        await view.interact(() =>
          byId(view.root, 'hilos-dropdown-option-1').click(),
        )
        expect(fixture.sent.at(-1)?.filter).toEqual({ kind: 2 })
      },
    )

    scenario(
      'F10 sends both date bounds in one viewport when one is entered',
      async (resources) => {
        const fixture = resources.create(PERIOD_FILTER, { to: '2026-08-31' })
        const view = await resources.mount(fixture)
        await view.enterDate(
          byId(view.root, 'hilos-table-filter-from-from') as HTMLInputElement,
          '2026-08-01',
        )
        expect(fixture.sent).toHaveLength(1)
        expect(fixture.sent.at(-1)?.filter).toEqual({
          from: '2026-08-01',
          to: '2026-08-31',
        })
      },
    )

    scenario(
      'F11 clears both date bounds in one viewport',
      async (resources) => {
        const fixture = resources.create(PERIOD_FILTER, {
          from: '2026-08-01',
          to: '2026-08-31',
        })
        const view = await resources.mount(fixture)
        await view.interact(() =>
          byId(view.root, 'hilos-table-filter-from-clear').click(),
        )
        expect(fixture.sent).toHaveLength(1)
        expect(fixture.sent.at(-1)?.filter).toEqual({})
      },
    )

    scenario(
      'F12 names all four independent date range shapes',
      async (resources) => {
        const both = await resources.mount(
          resources.create(PERIOD_FILTER, {
            from: '2026-08-01',
            to: '2026-08-31',
          }),
        )
        const fromOnly = await resources.mount(
          resources.create(PERIOD_FILTER, { from: '2026-08-01' }),
        )
        const toOnly = await resources.mount(
          resources.create(PERIOD_FILTER, { to: '2026-08-31' }),
        )
        const neither = await resources.mount(resources.create(PERIOD_FILTER))
        expect(
          byId(both.root, 'hilos-table-filter-from').textContent?.trim(),
        ).toBe('Period: 2026-08-01 – 2026-08-31')
        expect(
          byId(fromOnly.root, 'hilos-table-filter-from').textContent?.trim(),
        ).toBe('Period: from 2026-08-01')
        expect(
          byId(toOnly.root, 'hilos-table-filter-from').textContent?.trim(),
        ).toBe('Period: until 2026-08-31')
        expect(
          byId(neither.root, 'hilos-table-filter-from').textContent?.trim(),
        ).toBe('Period')
      },
    )

    scenario(
      'F13 writes the declared value when switched on',
      async (resources) => {
        const fixture = resources.create(FAILED_FILTER)
        const view = await resources.mount(fixture)
        await view.interact(() =>
          byId(view.root, 'hilos-table-filter-state').click(),
        )
        expect(fixture.sent.at(-1)?.filter).toEqual({ state: 'failed' })
      },
    )

    scenario(
      'F14 starts checked and drops the key when switched off',
      async (resources) => {
        const fixture = resources.create(FAILED_FILTER, { state: 'failed' })
        const view = await resources.mount(fixture)
        const toggle = byId(
          view.root,
          'hilos-table-filter-state',
        ) as HTMLInputElement
        expect(toggle.checked).toBe(true)
        await view.interact(() => toggle.click())
        expect(fixture.sent.at(-1)?.filter).not.toHaveProperty('state')
      },
    )

    scenario(
      'F15 gives independent modal date and select controls their suffixed hooks',
      async (resources) => {
        const period = resources.create(PERIOD_FILTER)
        const range = await resources.mount(period, 'modal')
        const kind = resources.create(KIND_FILTER)
        kind.controller.ingestFacetCounts({
          kind: { any: { count: 3, exact: true }, options: {} },
        })
        const select = await resources.mount(kind, 'modal')
        for (const name of [
          'hilos-table-filter-from-modal',
          'hilos-table-filter-from-modal-from',
          'hilos-table-filter-from-modal-to',
          'hilos-table-filter-from-modal-clear',
        ]) {
          expect(range.root.querySelector(`[data-id="${name}"]`)).not.toBeNull()
        }
        expect(
          range.root.querySelector('[data-id="hilos-table-filter-from"]'),
        ).toBeNull()
        expect(
          select.root.querySelector(
            '[data-id="hilos-table-filter-kind-modal"]',
          ),
        ).not.toBeNull()
        expect(
          select.root.querySelector(
            '[data-id="hilos-table-facet-kind-any-modal"]',
          ),
        ).not.toBeNull()
      },
    )
  })
}
