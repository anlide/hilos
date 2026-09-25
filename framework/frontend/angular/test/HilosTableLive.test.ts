// The Angular port of vue/src/HilosTableLive.test.ts, under the same case names the
// Vue reference and the React port run.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
import { TableViewportController, createSignal } from '@hilos/core'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableBulkAction,
  HilosTableColumn,
} from '@hilos/core'

import { HilosTableLive } from '../src/HilosTableLive.js'

afterEach(() => {
  document.body.classList.remove('modal-open')
})

interface Row {
  name: string
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name' },
  { key: 'presence', label: 'Presence', source: 'connections' },
]

// The classes that place and color a row, which the twin and the message are
// allowed to differ by; everything else is the layout both must share.
const PLACEMENT = new Set([
  'invisible',
  'position-absolute',
  'top-0',
  'start-0',
  'w-100',
])

function layoutClasses(element: Element): string[] {
  return Array.from(element.classList)
    .filter((name) => !PLACEMENT.has(name) && !name.startsWith('alert-'))
    .sort()
}

function makeController(): TableViewportController<Row> {
  const controller = new TableViewportController<Row>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: () => undefined,
  })
  controller.ingestWindow(
    [{ rowKey: 'a', slots: { name: 'Alice' } }],
    1,
    true,
    null,
    null,
    10,
  )

  return controller
}

// Three messages at once: a waiting change, a row created above the window, and a
// source gone quiet on the shown row.
function liveThree(controller: TableViewportController<Row>): void {
  controller.ingestDelta({
    kind: 'row_moved',
    rowKey: 'a',
    row: { rowKey: 'a', slots: { name: 'Alicia' } },
  })
  controller.ingestAnnounce('b', 'above', 2, true)
  controller.ingestDelta({
    kind: 'row_stale',
    rowKey: 'a',
    staleSources: ['connections'],
  })
}

function accepted(progressKey: string): ActionHandle<HilosTableBulkAccepted> {
  return {
    requestId: 'req-1',
    loading: createSignal(false),
    done: Promise.resolve({ reply: { progressKey, total: 2 } }),
  }
}

function deleteAction(label = 'Delete'): HilosTableBulkAction {
  return {
    key: 'delete',
    label,
    danger: true,
    run: () => accepted('run-1'),
  }
}

/** A host handing the room the project's words and control beside the track. */
@Component({
  selector: 'test-table-live-progress-host',
  imports: [HilosTableLive],
  template: `
    <hilos-table-live
      [controller]="controller"
      [columns]="columns"
      [tableProgress]="title"
      [tableProgressAction]="stop"
    />
    <ng-template #title let-progress>
      <span data-id="page-progress-title">{{ progress.detail['title'] }}</span>
    </ng-template>
    <ng-template #stop let-progress>
      <button type="button" data-id="page-progress-stop">
        Stop {{ progress.progressKey }}
      </button>
    </ng-template>
  `,
})
class TableLiveProgressHost {
  controller!: TableViewportController<Row>
  columns = COLUMNS
}

/** A host handing the room the template for untouched rows in bulk details. */
@Component({
  selector: 'test-table-live-named-host',
  imports: [HilosTableLive],
  template: `
    <hilos-table-live
      [controller]="controller"
      [columns]="columns"
      [bulkUntouched]="untouched"
    />
    <ng-template #untouched let-rowKey>27.08 03:00 ({{ rowKey }})</ng-template>
  `,
})
class TableLiveNamedHost {
  controller!: TableViewportController<Row>
  columns = COLUMNS
}

function mountLive(
  controller: TableViewportController<Row>,
): ComponentFixture<HilosTableLive<Row>> {
  const fixture = TestBed.createComponent<HilosTableLive<Row>>(HilosTableLive)
  fixture.componentRef.setInput('controller', controller)
  fixture.componentRef.setInput('columns', COLUMNS)
  fixture.detectChanges()

  return fixture
}

function query(
  fixture: ComponentFixture<unknown>,
  selector: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(selector)
}

function click(fixture: ComponentFixture<unknown>, selector: string): void {
  ;(query(fixture, selector) as HTMLElement | null)?.click()
  fixture.detectChanges()
}

function all(fixture: ComponentFixture<unknown>, selector: string): Element[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll(selector),
  )
}

describe('HilosTableLive', () => {
  it('stands exactly one hidden twin with nothing, one and three messages live', () => {
    const controller = makeController()
    const fixture = mountLive(controller)
    expect(all(fixture, '[data-id="hilos-table-live-idle"]')).toHaveLength(1)

    controller.ingestAnnounce('b', 'above', 2, true)
    fixture.detectChanges()
    expect(all(fixture, '[data-id="hilos-table-live-idle"]')).toHaveLength(1)

    liveThree(controller)
    fixture.detectChanges()
    expect(all(fixture, '[data-id="hilos-table-live-idle"]')).toHaveLength(1)
  })

  it('keeps the twin out of sight and out of the accessibility tree', () => {
    const fixture = mountLive(makeController())
    const twin = query(
      fixture,
      '[data-id="hilos-table-live-idle"]',
    ) as HTMLElement

    expect(twin.classList.contains('invisible')).toBe(true)
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    // The twin holds room and takes no focus, so there is no button inside it.
    expect(twin.querySelector('button')).toBeNull()
  })

  it('lays the message out exactly as the twin, only placed over it', () => {
    const controller = makeController()
    const fixture = mountLive(controller)
    controller.ingestAnnounce('b', 'above', 2, true)
    fixture.detectChanges()

    const twin = query(
      fixture,
      '[data-id="hilos-table-live-idle"]',
    ) as HTMLElement
    const message = query(
      fixture,
      '[data-id="hilos-table-announce"]',
    ) as HTMLElement
    expect(layoutClasses(message)).toEqual(layoutClasses(twin))
    // Out of the flow, so the message cannot add a pixel to the room.
    expect(message.classList.contains('position-absolute')).toBe(true)
  })

  it('draws no line with nothing to say, while the room still stands', () => {
    const fixture = mountLive(makeController())

    expect(query(fixture, '[data-id="hilos-table-live-slot"]')).not.toBeNull()
    expect(query(fixture, '.position-absolute')).toBeNull()
    expect(
      query(fixture, '[data-id="hilos-table-live-status"]')?.textContent,
    ).toBe('')
  })

  it('gives the line to the senior message and the others their icons', () => {
    const controller = makeController()
    liveThree(controller)
    const fixture = mountLive(controller)

    expect(all(fixture, '.position-absolute')).toHaveLength(1)
    expect(
      query(
        fixture,
        '[data-id="hilos-table-pending-row"]',
      )?.textContent?.replace(/\s+/g, ' '),
    ).toContain('1 row will move or leave')
    expect(query(fixture, '[data-id="hilos-table-apply"]')).not.toBeNull()
    expect(query(fixture, '[data-id="hilos-table-announce"]')).toBeNull()
    expect(query(fixture, '[data-id="hilos-table-stale"]')).toBeNull()

    // Sorted, because the order Angular writes a bound class list in is its own.
    const icons = all(fixture, '[data-id="hilos-table-live-rest"] i')
    expect(icons.map((icon) => Array.from(icon.classList).sort())).toEqual([
      ['bi', 'bi-arrow-down-circle', 'flex-shrink-0'],
      ['bi', 'bi-snow', 'flex-shrink-0'],
    ])
    expect(
      icons.every((icon) => icon.getAttribute('aria-hidden') === 'true'),
    ).toBe(true)
  })

  it('speaks the senior sentence and names the rest in the region that always stands', () => {
    const controller = makeController()
    liveThree(controller)
    const fixture = mountLive(controller)

    const status = query(
      fixture,
      '[data-id="hilos-table-live-status"]',
    ) as HTMLElement
    expect(status.getAttribute('role')).toBe('status')
    expect(status.textContent).toBe(
      '1 row will move or leave. Also: new rows above the window, a source is behind.',
    )
  })

  it('keeps the live region off the line itself', () => {
    const controller = makeController()
    liveThree(controller)
    const fixture = mountLive(controller)

    expect(
      query(fixture, '[data-id="hilos-table-pending-row"]')?.querySelector(
        '[role]',
      ),
    ).toBeNull()
  })

  it('names the columns built from the quiet source on the freshness line', () => {
    const controller = makeController()
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['connections'],
    })
    const fixture = mountLive(controller)

    expect(
      query(fixture, '[data-id="hilos-table-stale"]')?.textContent?.trim(),
    ).toBe(
      'Presence is not updating: the link to its source was lost. The other columns are live.',
    )
  })

  // The placing classes sit on the track's own element, the way an Angular component
  // takes a class from its user.
  it('draws running work on one line, the track along its bottom edge', () => {
    const controller = makeController()
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
      detail: { title: 'Nightly check' },
    })
    const fixture = TestBed.createComponent(TableLiveProgressHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    const line = query(
      fixture,
      '[data-id="hilos-table-progress"]',
    ) as HTMLElement
    expect(
      line
        .querySelector('[data-id="page-progress-title"]')
        ?.textContent?.trim(),
    ).toBe('Nightly check')
    expect(
      line.querySelector('[data-id="page-progress-stop"]')?.textContent?.trim(),
    ).toBe('Stop nightly')
    const host = line.querySelector('hilos-table-progress') as HTMLElement
    expect(
      host.querySelector('[role="progressbar"]')?.getAttribute('aria-valuenow'),
    ).toBe('28')
    expect(Array.from(host.classList)).toEqual(
      expect.arrayContaining(['position-absolute', 'bottom-0', 'w-100']),
    )
  })

  it('names the running bulk operation on its own bar and stays neutral on another', async () => {
    const controller = makeController()
    controller.runBulk(deleteAction(), { kind: 'rows', rowKeys: ['a'] })
    await Promise.resolve()

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-1',
      current: 12,
      total: 40,
    })
    const fixture = mountLive(controller)

    const line = query(
      fixture,
      '[data-id="hilos-table-progress-bulk"]',
    ) as HTMLElement
    expect(line.textContent).toContain('Delete: 12 of 40')
    const track = line.querySelector('[role="progressbar"]') as HTMLElement
    expect(track.getAttribute('aria-label')).toBe('Working on the marked rows')
    const host = line.querySelector('hilos-table-progress') as HTMLElement
    expect(Array.from(host.classList)).toEqual(
      expect.arrayContaining(['position-absolute', 'bottom-0', 'w-100']),
    )

    // Work under another key: neutral caption
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'someone-else',
      current: 3,
      total: 9,
    })
    fixture.detectChanges()
    expect(
      query(fixture, '[data-id="hilos-table-progress-bulk"]')?.textContent,
    ).toContain('Working on the marked rows')
  })

  it('drops the total from the bulk caption of work that named none', async () => {
    const controller = makeController()
    controller.runBulk(deleteAction(), { kind: 'rows', rowKeys: ['a'] })
    await Promise.resolve()

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-1',
      current: 12,
    })
    const fixture = mountLive(controller)

    expect(
      query(fixture, '[data-id="hilos-table-progress-bulk"]')?.textContent,
    ).toContain('Delete')
  })

  it('reads the outcome out of the report, calmly when nothing was left alone', () => {
    const controller = makeController()
    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [],
      untouchedOmitted: 0,
    })
    const fixture = mountLive(controller)

    const plate = query(
      fixture,
      '[data-id="hilos-table-bulk-report"]',
    ) as HTMLElement
    expect(plate.textContent).toContain('Changed 39 rows')
    expect(plate.textContent).not.toContain('untouched')
    expect(plate.classList.contains('alert-success')).toBe(true)
    expect(
      query(fixture, '[data-id="hilos-table-bulk-report-details"]'),
    ).toBeNull()
    expect(
      query(fixture, '[data-id="hilos-table-bulk-report-close"]'),
    ).not.toBeNull()
  })

  it('names every untouched row and counts the names that did not fit in details modal', () => {
    const controller = makeController()
    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [{ rowKey: 'r7', reason: 'Someone deleted it first' }],
      untouchedOmitted: 4128,
    })
    const fixture = mountLive(controller)

    const plate = query(
      fixture,
      '[data-id="hilos-table-bulk-report"]',
    ) as HTMLElement
    expect(plate.classList.contains('alert-warning')).toBe(true)
    expect(plate.textContent).toContain('Changed 39 rows, 4129 untouched')

    click(fixture, '[data-id="hilos-table-bulk-report-details"]')

    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    const list = document.querySelector(
      '[data-id="hilos-table-bulk-report-list"]',
    )
    expect(list?.textContent).toContain('r7')
    expect(list?.textContent).toContain('Someone deleted it first')
    expect(list?.textContent).toContain('and 4128 more')
  })

  it('prints the human name a page gave the untouched row in details modal', () => {
    const controller = makeController()
    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [{ rowKey: 'r7', reason: 'Already gone' }],
      untouchedOmitted: 0,
    })
    const fixture = TestBed.createComponent(TableLiveNamedHost)
    fixture.componentInstance.controller = controller
    fixture.detectChanges()

    click(fixture, '[data-id="hilos-table-bulk-report-details"]')

    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    const list = document.querySelector(
      '[data-id="hilos-table-bulk-report-list"]',
    )
    expect(list?.textContent).toContain('27.08 03:00 (r7)')
  })

  it('dismisses the report through the controller', () => {
    const controller = makeController()
    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [],
      untouchedOmitted: 0,
    })
    const fixture = mountLive(controller)

    click(fixture, '[data-id="hilos-table-bulk-report-close"]')

    expect(controller.bulk.report.get()).toBeNull()
    expect(query(fixture, '[data-id="hilos-table-bulk-report"]')).toBeNull()
  })
})
