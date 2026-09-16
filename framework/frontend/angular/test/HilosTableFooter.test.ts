// The Angular port of vue/src/HilosTableFooter.test.ts, under the same case names
// the Vue reference and the React port run, for the layer the Angular footer
// draws: the range, the count, and the two steps. Every case mounts a host that
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
    controller.ingestWindow(window(20), 20, true, null, null, 20)
    const fixture = mountFooter(controller)

    expect(
      (byId(fixture, 'hilos-table-prev') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(
      (byId(fixture, 'hilos-table-next') as HTMLButtonElement).disabled,
    ).toBe(true)
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
