// The Angular port of vue/src/HilosTableBar.test.ts, under the same case names the
// Vue reference and the React port run, for the layer the Angular bar draws:
// title, search, filters, badge, main action, and the filters modal. Every case
// mounts a host that binds the two inputs.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
import { TableViewportController } from '@hilos/core'
import type { HilosTableFrame, TableViewportDescriptor } from '@hilos/core'

import { HilosTableBar } from '../src/HilosTableBar.js'

const COLUMNS = [{ key: 'name', label: 'Name' }]

/** A host binding the controller and the id the title carries. */
@Component({
  selector: 'test-table-bar-host',
  imports: [HilosTableBar],
  template: `<hilos-table-bar
    [controller]="controller"
    titleId="table-title"
  />`,
})
class BarHost {
  controller!: TableViewportController<unknown>
}

afterEach(() => {
  document.body.classList.remove('modal-open')
})

function makeController(
  frame: HilosTableFrame,
  initialFilter?: Record<string, unknown>,
): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter,
    frame,
  })

  return { controller, sent }
}

const FILTERED: HilosTableFrame = {
  title: 'Deliveries',
  search: {},
  filters: [
    { kind: 'toggle', key: 'state', label: 'Failed only', on: 'failed' },
    { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
  ],
  columns: COLUMNS,
}

function mountBar(
  controller: TableViewportController<unknown>,
): ComponentFixture<BarHost> {
  const fixture = TestBed.createComponent(BarHost)
  fixture.componentInstance.controller = controller
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

function type(
  fixture: ComponentFixture<unknown>,
  id: string,
  value: string,
): void {
  const field = byId(fixture, id) as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
  fixture.detectChanges()
}

function click(fixture: ComponentFixture<unknown>, id: string): void {
  byId(fixture, id)?.click()
  fixture.detectChanges()
}

describe('HilosTableBar', () => {
  it('shows the declared title and subtitle, the title carrying the given id', () => {
    const { controller } = makeController({
      title: 'Backups',
      subtitle: 'Every copy this installation keeps',
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    const title = byId(fixture, 'hilos-table-title')
    expect(title?.textContent?.trim()).toBe('Backups')
    expect(title?.id).toBe('table-title')
    expect(byId(fixture, 'hilos-table-subtitle')?.textContent?.trim()).toBe(
      'Every copy this installation keeps',
    )
  })

  it('draws no subtitle when the declaration carries none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-subtitle')).toBeNull()
  })

  it('draws no search box when the table does not declare search', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-search')).toBeNull()
  })

  it('names the search field with its declared placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: { placeholder: 'Search backups…' },
      columns: COLUMNS,
    })
    const field = byId(mountBar(controller), 'hilos-table-search')

    expect(field?.getAttribute('placeholder')).toBe('Search backups…')
    expect(field?.getAttribute('aria-label')).toBe('Search backups…')
  })

  it('falls back to "Search…" when the declaration names no placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const field = byId(mountBar(controller), 'hilos-table-search')

    expect(field?.getAttribute('placeholder')).toBe('Search…')
  })

  it('sends a window on every keystroke of the search field', () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    type(fixture, 'hilos-table-search', 'nig')

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toMatchObject({ search: 'nig' })
  })

  it('offers the clear button only while the search holds text, and clearing drops the key', () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)
    expect(byId(fixture, 'hilos-table-search-clear')).toBeNull()

    type(fixture, 'hilos-table-search', 'nig')
    click(fixture, 'hilos-table-search-clear')

    expect(sent.at(-1)?.filter).not.toHaveProperty('search')
  })

  it('presses the declared main action exactly once per click', () => {
    let pressed = 0
    const { controller } = makeController({
      title: 'Backups',
      mainAction: {
        label: 'Create backup',
        press: () => {
          pressed += 1
        },
      },
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-main-action')?.textContent?.trim()).toBe(
      'Create backup',
    )
    click(fixture, 'hilos-table-main-action')

    expect(pressed).toBe(1)
  })

  it('draws a control per declared filter', () => {
    const { controller } = makeController(FILTERED)
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-filter-state')).not.toBeNull()
    expect(byId(fixture, 'hilos-table-filter-unread')).not.toBeNull()
  })

  it('counts the filters holding a value in the badge, and never the search', () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    const fixture = mountBar(controller)
    const badge = (): string | undefined =>
      byId(fixture, 'hilos-table-filter-badge')?.textContent?.trim()
    expect(badge()).toBe('1 filter')

    type(fixture, 'hilos-table-search', 'nig')
    expect(badge()).toBe('1 filter')

    click(fixture, 'hilos-table-filter-unread')
    expect(badge()).toBe('2 filters')
  })

  it('shows no badge while no filter holds a value', () => {
    const { controller } = makeController(FILTERED)
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-filter-badge')).toBeNull()
  })

  it('resets to the filters the table opened with and clears the search with them', () => {
    const { controller, sent } = makeController(FILTERED, { state: 'failed' })
    const fixture = mountBar(controller)
    type(fixture, 'hilos-table-search', 'nig')
    click(fixture, 'hilos-table-filter-unread')

    click(fixture, 'hilos-table-filter-reset')

    expect(sent.at(-1)?.filter).toEqual({ state: 'failed' })
    expect(
      (byId(fixture, 'hilos-table-search') as HTMLInputElement).value,
    ).toBe('')
  })

  it('opens the filters in a modal and closes it with Done', () => {
    const { controller } = makeController(FILTERED)
    const fixture = mountBar(controller)
    expect(byId(fixture, 'modal')).toBeNull()

    click(fixture, 'hilos-table-filters-open')
    expect(byId(fixture, 'modal')).not.toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-table-filter-state-modal"]',
      ),
    ).toHaveLength(1)

    click(fixture, 'hilos-table-filters-done')

    expect(byId(fixture, 'modal')).toBeNull()
  })

  it('keeps the button that opens the filters free of their number', () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    const fixture = mountBar(controller)
    click(fixture, 'hilos-table-filter-unread')

    const open = byId(fixture, 'hilos-table-filters-open')
    expect(open?.textContent?.trim()).toBe('Filters')
    expect(open?.querySelector('.badge')).toBeNull()
  })

  it('offers no way into the filters when the table declares none, and mounts no modal either', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-filters-open')).toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('hilos-modal'),
    ).toBeNull()
  })

  it('draws no main action when the table declares none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-main-action')).toBeNull()
  })
})
