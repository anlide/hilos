// The Angular port of vue/src/HilosTableSelection.test.ts, under the same case
// names the Vue reference and the React port run. Every case mounts a host that
// binds the panel's inputs, and the confirmation renders inside it.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
import { ActionError, TableViewportController, createSignal } from '@hilos/core'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableBulkAction,
  HilosTableBulkReport,
  HilosTableFrame,
  HilosTableSelectionTarget,
  TableViewportDescriptor,
} from '@hilos/core'

import { HilosTableSelection } from '../src/HilosTableSelection.js'

/** A host binding the panel's inputs and recording what it dismisses. */
@Component({
  selector: 'test-table-selection-host',
  imports: [HilosTableSelection],
  template: `<hilos-table-selection
    [controller]="controller"
    [shown]="shown()"
    [report]="report"
    (dismiss)="dismissed.push($event)"
  />`,
})
class SelectionHost {
  controller!: TableViewportController<unknown>
  // A signal, so that taking the panel down reaches an OnPush child.
  readonly shown = signal(true)
  report: HilosTableBulkReport | null = null
  dismissed: string[] = []
}

/** The same host with the page's template for the name of an untouched row. */
@Component({
  selector: 'test-table-selection-named-host',
  imports: [HilosTableSelection],
  template: `
    <hilos-table-selection
      [controller]="controller"
      [shown]="true"
      [report]="report"
      [bulkUntouched]="untouched"
    />
    <ng-template #untouched let-rowKey>27.08 03:00 ({{ rowKey }})</ng-template>
  `,
})
class NamedSelectionHost {
  controller!: TableViewportController<unknown>
  report: HilosTableBulkReport | null = null
}

afterEach(() => {
  document.body.classList.remove('modal-open')
})

const COLUMNS = [{ key: 'name', label: 'Name' }]

/** The handle an accepted run answers with: the key its bar and report carry. */
function accepted(progressKey: string): ActionHandle<HilosTableBulkAccepted> {
  return {
    requestId: 'req-1',
    loading: createSignal(false),
    done: Promise.resolve({ reply: { progressKey, total: 2 } }),
  }
}

/** The handle a refused run answers with: the sentence the page sent back. */
function refused(): ActionHandle<HilosTableBulkAccepted> {
  return {
    requestId: 'req-1',
    loading: createSignal(false),
    done: Promise.reject(
      new ActionError('backups_delete', 'fail', 'The node is frozen'),
    ),
  }
}

/**
 * One declared operation that records what it was asked to run over.
 *
 * @param asked The targets it was called with, in order.
 * @param handle What the send answers with.
 */
function deleteAction(
  asked: HilosTableSelectionTarget[],
  handle: () => ActionHandle<HilosTableBulkAccepted> = () => accepted('run-1'),
): HilosTableBulkAction {
  return {
    key: 'delete',
    label: 'Delete',
    danger: true,
    run: (target) => {
      asked.push(target)

      return handle()
    },
  }
}

function makeController(
  actions: readonly HilosTableBulkAction[],
): TableViewportController<unknown> {
  const frame: HilosTableFrame = {
    title: 'Backups',
    columns: COLUMNS,
    bulkActions: actions,
  }
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    frame,
  })
  controller.ingestWindow(
    [
      { rowKey: 'a', slots: { name: 'Alice' } },
      { rowKey: 'b', slots: { name: 'Bob' } },
    ],
    2,
    true,
    null,
    null,
    10,
  )

  return controller
}

function mountPanel(
  controller: TableViewportController<unknown>,
  report: HilosTableBulkReport | null = null,
): ComponentFixture<SelectionHost> {
  const fixture = TestBed.createComponent(SelectionHost)
  fixture.componentInstance.controller = controller
  fixture.componentInstance.report = report
  fixture.detectChanges()

  return fixture
}

/**
 * A finished run's outcome.
 *
 * @param overrides What this run ended with, over the ordinary shape.
 */
function report(
  overrides: Partial<HilosTableBulkReport> = {},
): HilosTableBulkReport {
  return {
    progressKey: 'run-1',
    touched: 39,
    untouched: [],
    untouchedOmitted: 0,
    ...overrides,
  }
}

function byId(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function text(fixture: ComponentFixture<unknown>, id: string): string {
  return byId(fixture, id)?.textContent?.replace(/\s+/g, ' ').trim() ?? ''
}

function click(fixture: ComponentFixture<unknown>, id: string): void {
  byId(fixture, id)?.click()
  fixture.detectChanges()
}

/** Press a button and wait out the promises the send settles through. */
async function press(
  fixture: ComponentFixture<unknown>,
  id: string,
): Promise<void> {
  byId(fixture, id)?.click()
  await new Promise((resolve) => setTimeout(resolve, 0))
  fixture.detectChanges()
}

describe('HilosTableSelection', () => {
  it('counts the marked rows of this page, and says the condition in words', () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)

    expect(text(fixture, 'hilos-table-selection-count')).toBe(
      '1 marked on this page',
    )
    expect(byId(fixture, 'hilos-table-select-all-filtered')).not.toBeNull()

    controller.selectAllByFilter()
    fixture.detectChanges()

    // The size of a large set is a ceiling rather than a count, so the panel says
    // what it is sure of; and the button that took the condition is gone, because
    // pressing it again would change nothing.
    expect(text(fixture, 'hilos-table-selection-count')).toBe(
      'All rows matching the filter',
    )
    expect(byId(fixture, 'hilos-table-select-all-filtered')).toBeNull()
  })

  it('drops the marks through the core', () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)

    click(fixture, 'hilos-table-selection-clear')

    expect(controller.selection.count.get()).toBe(0)
  })

  it('disables the declared operations while a run is going', () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)
    const button = () =>
      byId(fixture, 'hilos-table-bulk-delete') as HTMLButtonElement

    expect(button().disabled).toBe(false)

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-1',
      current: 1,
      total: 4,
    })
    fixture.detectChanges()

    expect(button().disabled).toBe(true)
  })

  it('confirms every operation and sends the target the core gives at that moment', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked)])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)

    click(fixture, 'hilos-table-bulk-delete')
    expect(byId(fixture, 'modal')).not.toBeNull()
    expect(fixture.nativeElement.textContent).toContain(
      '1 row on this page will be affected',
    )
    expect(asked).toHaveLength(0)

    // Between the press and the confirmation the reader marked one more row: the
    // target is read HERE, so the run takes both.
    controller.selectRow('b', true)
    fixture.detectChanges()

    await press(fixture, 'hilos-table-bulk-confirm')

    expect(asked).toEqual([{ kind: 'rows', rowKeys: ['a', 'b'] }])
    expect(byId(fixture, 'modal')).toBeNull()
  })

  it('keeps the confirmation open with the refusal it was answered with', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked, refused)])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)

    click(fixture, 'hilos-table-bulk-delete')
    await press(fixture, 'hilos-table-bulk-confirm')

    expect(byId(fixture, 'modal')).not.toBeNull()
    expect(fixture.nativeElement.textContent).toContain('The node is frozen')
  })

  it('names the running operation on its own bar and stays neutral on another', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked)])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)

    click(fixture, 'hilos-table-bulk-delete')
    await press(fixture, 'hilos-table-bulk-confirm')

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-1',
      current: 12,
      total: 40,
    })
    fixture.detectChanges()
    expect(text(fixture, 'hilos-table-progress-bulk')).toBe('Delete: 12 of 40')

    // Work under a key this panel never asked for: it knows nothing about what it
    // is, so it says only that something is running over the marked rows.
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'someone-else',
      current: 3,
      total: 9,
    })
    fixture.detectChanges()
    expect(text(fixture, 'hilos-table-progress-bulk')).toBe(
      'Working on the marked rows',
    )
  })

  it('drops the total from the caption of work that named none', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked)])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)

    click(fixture, 'hilos-table-bulk-delete')
    await press(fixture, 'hilos-table-bulk-confirm')

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-1',
      current: 12,
    })
    fixture.detectChanges()

    expect(text(fixture, 'hilos-table-progress-bulk')).toBe('Delete')
  })

  it('reads the outcome out of the report, calmly when nothing was left alone', () => {
    const controller = makeController([deleteAction([])])
    const fixture = mountPanel(controller, report())

    const plate = byId(fixture, 'hilos-table-bulk-report')
    expect(plate?.textContent).toContain('Changed 39 rows')
    expect(plate?.textContent).not.toContain('untouched')
    expect(plate?.classList.contains('alert-success')).toBe(true)
  })

  it('names every untouched row and counts the names that did not fit', () => {
    const controller = makeController([deleteAction([])])
    const fixture = mountPanel(
      controller,
      report({
        untouched: [{ rowKey: 'r7', reason: 'Someone deleted it first' }],
        untouchedOmitted: 4128,
      }),
    )

    const plate = byId(fixture, 'hilos-table-bulk-report')
    expect(plate?.classList.contains('alert-warning')).toBe(true)
    const words = text(fixture, 'hilos-table-bulk-report')
    expect(words).toContain('Changed 39 rows, 4129 untouched')
    expect(words).toContain('r7')
    expect(words).toContain('Someone deleted it first')
    expect(words).toContain('and 4128 more')
  })

  it('prints the human name a page gave the untouched row', () => {
    const controller = makeController([deleteAction([])])
    const fixture = TestBed.createComponent(NamedSelectionHost)
    fixture.componentInstance.controller = controller
    fixture.componentInstance.report = report({
      untouched: [{ rowKey: 'r7', reason: 'Already gone' }],
    })
    fixture.detectChanges()

    expect(text(fixture, 'hilos-table-bulk-report')).toContain(
      '27.08 03:00 (r7)',
    )
  })

  it('tells the bar above which run the reader dismissed', () => {
    const controller = makeController([deleteAction([])])
    const fixture = mountPanel(controller, report())

    click(fixture, 'hilos-table-bulk-report-close')

    expect(fixture.componentInstance.dismissed).toEqual(['run-1'])
  })

  it('keeps an open confirmation when the panel stops standing', () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)

    click(fixture, 'hilos-table-bulk-delete')
    expect(byId(fixture, 'modal')).not.toBeNull()

    // The strip goes when the marks do — a window can arrive with none of them
    // left — and the dialog holds the focus and the page's scroll lock, neither of
    // which is the strip's to hand back on its way out.
    fixture.componentInstance.shown.set(false)
    fixture.detectChanges()

    expect(byId(fixture, 'hilos-table-selection')).toBeNull()
    expect(byId(fixture, 'modal')).not.toBeNull()
  })

  it('names itself to a screen reader as one group', () => {
    const controller = makeController([deleteAction([])])
    const fixture = mountPanel(controller)

    const group = byId(fixture, 'hilos-table-selection')
    expect(group?.getAttribute('role')).toBe('group')
    expect(group?.getAttribute('aria-label')).toBe('Selection')
  })
})
