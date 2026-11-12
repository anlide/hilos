// The Angular port of vue/src/HilosTableLive.test.ts, under the same case names the
// Vue reference and the React port run.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  TableViewportController,
  createSignal,
  hilosTableFrozenLabel,
} from '@hilos/core/base'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableBulkAction,
  HilosTableColumn,
  HilosTableProgress,
} from '@hilos/core/base'

import type {
  TableLiveInput,
  TableLiveView,
} from '../../test-support/tableLiveFixtures.js'
import { registerTableLiveFrozenCases } from '../../test-support/tableLiveFrozenCases.js'
import { registerTableLiveRoomCases } from '../../test-support/tableLiveRoomCases.js'

import { HilosTableLive } from '../src/HilosTableLive.js'
import {
  bindTestTimeZone,
  type TimeZoneTestBinding,
} from './timeZoneTestHarness.js'

let timeZoneBinding: TimeZoneTestBinding | null = null

afterEach(() => {
  timeZoneBinding?.release()
  timeZoneBinding = null
  vi.restoreAllMocks()
  document.body.classList.remove('modal-open')
})

interface Row {
  name: string
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name' },
  { key: 'presence', label: 'Presence', source: 'connections' },
]

function makeController(): TableViewportController<Row> {
  const controller = new TableViewportController<Row>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: () => undefined,
    frame: { columns: COLUMNS },
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

// Three messages at once: a waiting change, a new row the window cannot show, and
// a source gone quiet on the shown row.
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
    refusalTitle: "Couldn't delete",
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
  tableProgressAnnouncement?: (progress: HilosTableProgress) => string,
): ComponentFixture<HilosTableLive<Row>> {
  const fixture = TestBed.createComponent<HilosTableLive<Row>>(HilosTableLive)
  fixture.componentRef.setInput('controller', controller)
  fixture.componentRef.setInput('columns', COLUMNS)
  if (tableProgressAnnouncement !== undefined) {
    fixture.componentRef.setInput(
      'tableProgressAnnouncement',
      tableProgressAnnouncement,
    )
  }
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

/** Own the fixture before input delivery and destroy before resetting TestBed. */
async function mountTableLive(input: TableLiveInput): Promise<TableLiveView> {
  const host = document.createElement('div')
  let fixture: ComponentFixture<HilosTableLive<unknown>> | undefined
  let released = false
  async function unmount(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of [
      () => fixture?.destroy(),
      () => (fixture?.nativeElement as HTMLElement | undefined)?.remove(),
      () => TestBed.resetTestingModule(),
      () => host.remove(),
    ]) {
      try {
        await release()
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(errors, 'Angular table live cleanup failed', {
        cause: errors[0],
      })
  }
  try {
    document.body.appendChild(host)
    fixture = TestBed.createComponent<HilosTableLive<unknown>>(HilosTableLive)
    host.appendChild(fixture.nativeElement as HTMLElement)
    fixture.componentRef.setInput('controller', input.controller)
    fixture.componentRef.setInput('columns', input.columns)
    fixture.detectChanges()
    await fixture.whenStable()
    return {
      root: host,
      unmount,
      async change(action) {
        action()
        fixture!.detectChanges()
        await fixture!.whenStable()
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Angular table live setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableLiveRoomCases('Angular', { describe, it, expect }, mountTableLive)
registerTableLiveFrozenCases(
  'Angular',
  { describe, it, expect },
  mountTableLive,
)

describe('HilosTableLive', () => {
  it('reformats the frozen line for the live reader zone without replacing it', () => {
    const since = Date.parse('2026-10-09T23:30:00.000Z')
    const controller = makeController()
    controller.ingestFrozen(since)
    const fixture = mountLive(controller)
    const line = query(fixture, '[data-id="hilos-table-frozen"]') as HTMLElement
    timeZoneBinding = bindTestTimeZone('America/New_York')

    const receiveAndRead = (timeZone: string | null): string => {
      timeZoneBinding?.receive(timeZone)
      fixture.detectChanges()
      expect(query(fixture, '[data-id="hilos-table-frozen"]')).toBe(line)
      const actual = line.textContent?.trim() ?? ''
      expect(actual).toBe(hilosTableFrozenLabel(since))
      return actual
    }

    const utc = receiveAndRead('UTC')
    const kyiv = receiveAndRead('Europe/Kyiv')
    const automatic = receiveAndRead(null)
    expect(utc).not.toBe(kyiv)
    expect(kyiv).not.toBe(automatic)
    expect(timeZoneBinding.listenerCount()).toBe(2)

    fixture.destroy()
    const destroyedText = line.textContent
    timeZoneBinding.rebind()
    expect(timeZoneBinding.listenerCount()).toBe(2)
    timeZoneBinding.receive('UTC')
    expect(line.textContent).toBe(destroyedText)

    const rebound = mountLive(controller)
    expect(
      query(rebound, '[data-id="hilos-table-frozen"]')?.textContent?.trim(),
    ).toBe(hilosTableFrozenLabel(since))
    timeZoneBinding.receive('Europe/Kyiv')
    rebound.detectChanges()
    expect(
      query(rebound, '[data-id="hilos-table-frozen"]')?.textContent?.trim(),
    ).toBe(hilosTableFrozenLabel(since))
    rebound.destroy()
  })

  it('announces the supplied table progress phrase from the current bar', () => {
    const controller = makeController()
    const announcement = vi.fn(
      (progress: HilosTableProgress) =>
        `${String(progress.detail['title'])}: ${progress.current} of ${progress.total}`,
    )
    const fixture = mountLive(controller, announcement)
    expect(announcement).not.toHaveBeenCalled()
    expect(
      query(fixture, '[data-id="hilos-table-live-status"]')?.textContent,
    ).toBe('')

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 3,
      total: 12,
      detail: { title: 'Nightly check' },
    })
    fixture.detectChanges()

    expect(announcement).toHaveBeenLastCalledWith({
      progressKey: 'nightly',
      current: 3,
      total: 12,
      fraction: 0.25,
      detail: { title: 'Nightly check' },
    })
    expect(
      query(fixture, '[data-id="hilos-table-live-status"]')?.getAttribute(
        'role',
      ),
    ).toBe('status')
    expect(
      query(fixture, '[data-id="hilos-table-live-status"]')?.textContent,
    ).toBe('Nightly check: 3 of 12')

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 6,
      total: 12,
      detail: { title: 'Nightly check' },
    })
    fixture.detectChanges()
    expect(
      query(fixture, '[data-id="hilos-table-live-status"]')?.textContent,
    ).toBe('Nightly check: 6 of 12')

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 12,
      ended: true,
    })
    fixture.detectChanges()
    expect(
      query(fixture, '[data-id="hilos-table-live-status"]')?.textContent,
    ).toBe('')
  })

  it('keeps the standard progress announcement when the page supplies none', () => {
    const controller = makeController()
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 3,
    })
    const fixture = mountLive(controller)

    expect(
      query(fixture, '[data-id="hilos-table-live-status"]')?.textContent,
    ).toBe('Work is running on this table.')
  })

  it('preserves higher priority messages and the Also queue without calling the progress announcer', () => {
    const controller = makeController()
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 3,
    })
    liveThree(controller)
    const announcement = vi.fn(() => 'Nightly check is running')
    const fixture = mountLive(controller, announcement)

    expect(query(fixture, '[data-id="hilos-table-pending-row"]')).not.toBeNull()
    expect(query(fixture, '[data-id="hilos-table-progress"]')).toBeNull()
    expect(
      query(fixture, '[data-id="hilos-table-live-status"]')?.textContent,
    ).toBe(
      '1 row will move or leave. Also: new rows, a source is behind, work running.',
    )
    expect(announcement).not.toHaveBeenCalled()
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
