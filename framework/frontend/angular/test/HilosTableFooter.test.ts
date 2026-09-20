// The Angular port of vue/src/HilosTableFooter.test.ts, under the same case names
// the Vue reference and the React port run, for the layer the Angular footer
// draws: the range, the count, and the pager. Every case mounts a host that
// binds the controller input.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import { TableViewportController } from '@hilos/core'
import type { TableRow, TableViewportDescriptor } from '@hilos/core'

import { HilosTableFooter } from '../src/HilosTableFooter.js'

// The footer says nothing about the declaration, but a table only draws one
// when it has a frame — so the tests give it the smallest one there is.
const FRAME = { title: 'Backups', columns: [{ key: 'name', label: 'Name' }] }

/** A host binding the controller the footer reads. */
@Component({
  selector: 'test-table-footer-host',
  imports: [HilosTableFooter],
  template: `<hilos-table-footer [controller]="controller" />`,
})
class FooterHost {
  controller!: TableViewportController<unknown>
}

function makeController(): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    frame: FRAME,
  })

  return { controller, sent }
}

function window(size: number): TableRow[] {
  return Array.from({ length: size }, (_unused, index) => ({
    rowKey: `row-${index}`,
    slots: { name: `Row ${index}` },
  }))
}

// Paging asks the server for the rows after the window's last one, so a window
// that carries no boundary cannot be paged from at all: these tests hand over
// the anchors a real window arrives with.
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

function mountFooter(
  controller: TableViewportController<unknown>,
): ComponentFixture<FooterHost> {
  const fixture = TestBed.createComponent(FooterHost)
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

function pageNumbers(fixture: ComponentFixture<unknown>): string[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll(
      '[data-id^="hilos-table-page-"]',
    ),
    (page) => page.textContent?.trim() ?? '',
  )
}

describe('HilosTableFooter', () => {
  it('prints the range on screen and an exact total', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 128, true, null, null, 20)
    const fixture = mountFooter(controller)

    expect(byId(fixture, 'hilos-table-count')?.textContent?.trim()).toBe(
      '1 – 20 of 128',
    )
  })

  it('marks a total that stopped at its ceiling with a trailing plus', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 500, false, null, null, 20)
    const fixture = mountFooter(controller)

    expect(byId(fixture, 'hilos-table-count')?.textContent?.trim()).toBe(
      '1 – 20 of 500+',
    )
  })

  it('counts the range from the page the window sits on', () => {
    const { controller } = makeController()
    ingestPage(controller, 128)
    const fixture = mountFooter(controller)

    byId(fixture, 'hilos-table-next')?.click()
    ingestPage(controller, 128)
    fixture.detectChanges()

    expect(byId(fixture, 'hilos-table-count')?.textContent?.trim()).toBe(
      '21 – 40 of 128',
    )
  })

  it('draws nothing at all on an empty window', () => {
    const { controller } = makeController()
    controller.ingestWindow([], 0, true, null, null, 20)
    const fixture = mountFooter(controller)

    expect(byId(fixture, 'hilos-table-count')).toBeNull()
    expect(byId(fixture, 'hilos-table-prev')).toBeNull()
  })

  it('disables the step back on the first page and the step on at the last', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 21, true, null, null, 20)
    const fixture = mountFooter(controller)

    expect(
      (byId(fixture, 'hilos-table-prev') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(
      (byId(fixture, 'hilos-table-next') as HTMLButtonElement).disabled,
    ).toBe(false)

    controller.setPage(1)
    controller.ingestWindow(window(1), 21, true, null, null, 20)
    fixture.detectChanges()

    expect(
      (byId(fixture, 'hilos-table-next') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('draws no pager when the whole set fits on one page', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(2), 2, true, null, null, 20)
    const fixture = mountFooter(controller)

    expect(byId(fixture, 'hilos-table-count')?.textContent?.trim()).toBe(
      '1 – 2 of 2',
    )
    expect(byId(fixture, 'hilos-table-prev')).toBeNull()
    expect(byId(fixture, 'hilos-table-next')).toBeNull()
    expect(pageNumbers(fixture)).toEqual([])
  })

  it('offers page numbers exactly while the count is exact', () => {
    const { controller } = makeController()
    ingestPage(controller, 128) // 7 pages of 20
    const fixture = mountFooter(controller)

    expect(pageNumbers(fixture)).toEqual(['1', '2', '3', '4', '5', '6', '7'])
  })

  it('offers no page numbers while the count stands at its ceiling', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 500, false, null, null, 20)
    const fixture = mountFooter(controller)

    expect(pageNumbers(fixture)).toEqual([])
    expect(byId(fixture, 'hilos-table-prev')?.textContent?.trim()).toBe(
      'Previous',
    )
    expect(byId(fixture, 'hilos-table-next')?.textContent?.trim()).toBe('Next')
  })

  it('jumps to the page whose number was pressed', () => {
    const { controller, sent } = makeController()
    ingestPage(controller, 128)
    const fixture = mountFooter(controller)

    byId(fixture, 'hilos-table-page-4')?.click()

    expect(sent.at(-1)).toMatchObject({ pageIndex: 3, anchor: null })
  })

  it('states the page the reader stands on rather than offering it again', () => {
    const { controller } = makeController()
    ingestPage(controller, 128)
    const fixture = mountFooter(controller)
    const current = byId(fixture, 'hilos-table-page-1') as HTMLButtonElement

    expect(current.disabled).toBe(true)
    expect(current.getAttribute('aria-current')).toBe('page')
  })

  it('passes over the pages between the ends when there are too many to draw', () => {
    const { controller } = makeController()
    // 400 rows of 20 make 20 pages — more than the pager has room for.
    ingestPage(controller, 400)
    const fixture = mountFooter(controller)

    controller.setPage(9) // the tenth page, with ends far on either side
    fixture.detectChanges()

    const numbers = pageNumbers(fixture)
    expect(numbers.length).toBeLessThanOrEqual(5)
    expect(numbers.at(0)).toBe('1')
    expect(numbers.at(-1)).toBe('20')
    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[aria-hidden="true"].disabled',
      ),
    ).toHaveLength(2)
  })

  it('leaves the steps their words only where no numbers stand beside them', () => {
    const { controller } = makeController()
    ingestPage(controller, 128)
    const fixture = mountFooter(controller)
    const previous = byId(fixture, 'hilos-table-prev') as HTMLElement

    expect(previous.textContent?.trim()).toBe('')
    expect(previous.getAttribute('aria-label')).toBe('Previous page')
    expect(byId(fixture, 'hilos-table-next')?.getAttribute('aria-label')).toBe(
      'Next page',
    )
  })

  it('steps the window on and back through the controller', () => {
    const { controller, sent } = makeController()
    ingestPage(controller, 128)
    const fixture = mountFooter(controller)

    byId(fixture, 'hilos-table-next')?.click()
    expect(sent).toHaveLength(1)

    ingestPage(controller, 128)
    fixture.detectChanges()
    byId(fixture, 'hilos-table-prev')?.click()

    expect(sent).toHaveLength(2)
  })
})
