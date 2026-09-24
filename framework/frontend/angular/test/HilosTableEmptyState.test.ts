// The Angular port of vue/src/HilosTableEmptyState.test.ts, under the same case
// names the Vue reference and the React port run, for the four worded states of
// the body of a table. Every case mounts a host that binds the inputs and hands
// the page's own words over as a template, the way the table does.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it, vi } from 'vitest'
import { TableViewportController } from '@hilos/core'
import type { HilosTableFrame, TableViewportDescriptor } from '@hilos/core'

import { HilosTableEmptyState } from '../src/HilosTableEmptyState.js'

/** A host binding the inputs the state reads, with the page's words as a template. */
@Component({
  selector: 'test-table-empty-state-host',
  imports: [HilosTableEmptyState],
  template: `
    <hilos-table-empty-state
      [controller]="controller"
      [kind]="kind"
      [fallback]="words"
    />
    <ng-template #words><span class="none-yet">No rows.</span></ng-template>
  `,
})
class EmptyStateHost {
  controller!: TableViewportController<unknown>
  kind: 'empty' | 'empty_filtered' | 'empty_page' | 'unavailable' = 'empty'
}

function makeController(frame?: HilosTableFrame): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter: { channel: 'mail' },
    frame,
  })
  controller.ingestWindow([], 0, true, null, null, 10)

  return { controller, sent }
}

function mountState(
  controller: TableViewportController<unknown>,
  kind: 'empty' | 'empty_filtered' | 'empty_page' | 'unavailable',
): ComponentFixture<EmptyStateHost> {
  const fixture = TestBed.createComponent(EmptyStateHost)
  fixture.componentInstance.controller = controller
  fixture.componentInstance.kind = kind
  fixture.detectChanges()

  return fixture
}

function byId(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

const FILTERED_FRAME: HilosTableFrame = {
  title: 'Deliveries',
  search: {},
  columns: [{ key: 'name', label: 'Name' }],
  filters: [
    {
      kind: 'select',
      key: 'status',
      label: 'Status',
      options: () => [{ value: 'failed', label: 'Failed' }],
    },
    { kind: 'date_range', fromKey: 'from', toKey: 'to', label: 'Period' },
    { kind: 'toggle', key: 'mine', label: 'Mine only', on: true },
  ],
}

describe('HilosTableEmptyState', () => {
  it('says the page own words and offers its main action', () => {
    const press = vi.fn()
    const { controller } = makeController({
      title: 'Backups',
      columns: [{ key: 'name', label: 'Name' }],
      empty: {
        title: 'Nothing here yet',
        hint: 'Your first backup will show up here',
      },
      mainAction: { label: 'Create backup', press },
    })
    const fixture = mountState(controller, 'empty')

    expect(byId(fixture, 'hilos-table-empty')?.getAttribute('role')).toBe(
      'status',
    )
    expect(byId(fixture, 'hilos-table-empty-title')?.textContent?.trim()).toBe(
      'Nothing here yet',
    )
    expect(byId(fixture, 'hilos-table-empty-hint')?.textContent?.trim()).toBe(
      'Your first backup will show up here',
    )

    const action = byId(fixture, 'hilos-table-empty-action')
    expect(action?.textContent?.trim()).toBe('Create backup')
    action?.click()
    expect(press).toHaveBeenCalledTimes(1)
  })

  it('falls back to what the page passed in the slot when it declared no empty state', () => {
    const { controller } = makeController()
    const fixture = mountState(controller, 'empty')

    expect(
      (fixture.nativeElement as HTMLElement).querySelector(
        '[data-id="hilos-table-empty"] .none-yet',
      )?.textContent,
    ).toBe('No rows.')
    expect(byId(fixture, 'hilos-table-empty-title')).toBeNull()
    expect(byId(fixture, 'hilos-table-empty-action')).toBeNull()
  })

  it('names the query and every active filter it found nothing for', () => {
    const { controller } = makeController(FILTERED_FRAME)
    controller.setSearch('night')
    controller.setFilter('status', 'failed')
    controller.setFilters({ from: '2026-08-01', to: '2026-08-31' })
    controller.setFilter('mine', true)
    const fixture = mountState(controller, 'empty_filtered')

    const state = byId(fixture, 'hilos-table-no-matches')
    expect(state?.getAttribute('role')).toBe('status')
    expect(state?.textContent).toContain('Nothing found')
    expect(
      byId(fixture, 'hilos-table-no-matches-terms')?.textContent?.trim(),
    ).toBe(
      'No rows match “night” · Status: Failed · Period: 2026-08-01 – 2026-08-31 · Mine only',
    )

    // A half-open range leaves the missing bound out of the sentence.
    controller.setFilters({ from: '', to: '2026-08-31' })
    fixture.detectChanges()
    expect(
      byId(fixture, 'hilos-table-no-matches-terms')?.textContent,
    ).toContain('Period: until 2026-08-31')
    controller.setFilters({ from: '2026-08-01', to: '' })
    fixture.detectChanges()
    expect(
      byId(fixture, 'hilos-table-no-matches-terms')?.textContent,
    ).toContain('Period: from 2026-08-01')
  })

  it('offers the way back to the rows when the window is empty over a set that is not', () => {
    const { controller, sent } = makeController(FILTERED_FRAME)
    controller.ingestWindow(
      [{ rowKey: 'a', slots: {} }],
      21,
      true,
      null,
      null,
      10,
      0,
    )
    controller.setPage(2)
    // The third page came back holding nothing while the set holds twenty-one rows: the
    // rows under that address moved while the page was open.
    controller.ingestWindow([], 21, true, null, null, 10, 20)
    const fixture = mountState(controller, 'empty_page')

    const state = byId(fixture, 'hilos-table-empty-page')
    expect(state?.getAttribute('role')).toBe('status')
    expect(state?.textContent).toContain('Nothing on this page')
    expect(state?.textContent).toContain(
      'These rows moved while the page was open.',
    )

    byId(fixture, 'hilos-table-empty-page-back')?.click()

    // The same thing Back in the footer does, and it asks for a place rather than a filter.
    expect(sent.at(-1)?.pageIndex).toBe(1)
  })

  it('says the list is unavailable when the server refused the window', () => {
    const { controller } = makeController()
    controller.ingestRefusal('internal_error')
    const fixture = mountState(controller, 'unavailable')

    const state = byId(fixture, 'hilos-table-unavailable')
    expect(state?.getAttribute('role')).toBe('status')
    expect(
      byId(fixture, 'hilos-table-unavailable-title')?.textContent?.trim(),
    ).toBe('List unavailable')
    expect(
      byId(fixture, 'hilos-table-unavailable-hint')?.textContent?.trim(),
    ).toBe(
      'The rows of this list could not be fetched. The rest of the page still works.',
    )
    expect(state?.querySelector('button')).toBeNull()
  })

  it('resets the search and the filters back to the ones the table opened with', () => {
    const { controller, sent } = makeController(FILTERED_FRAME)
    controller.setSearch('night')
    controller.setFilter('status', 'failed')
    const fixture = mountState(controller, 'empty_filtered')

    byId(fixture, 'hilos-table-no-matches-reset')?.click()

    expect(sent.at(-1)?.filter).toEqual({ channel: 'mail' })
  })
})
