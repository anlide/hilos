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
  HilosTableFrame,
  HilosTableSelectionTarget,
  TableViewportDescriptor,
} from '@hilos/core'

import { HilosTableSelection } from '../src/HilosTableSelection.js'

/** A host binding the panel's inputs. */
@Component({
  selector: 'test-table-selection-host',
  imports: [HilosTableSelection],
  template: `<hilos-table-selection
    [controller]="controller"
    [shown]="shown()"
  />`,
})
class SelectionHost {
  controller!: TableViewportController<unknown>
  // A signal, so that taking the panel down reaches an OnPush child.
  readonly shown = signal(true)
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
    refusalTitle: "Couldn't delete the marked rows",
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
): ComponentFixture<SelectionHost> {
  const fixture = TestBed.createComponent(SelectionHost)
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

  it('heads the refusal details with what the refused operation failed to do', async () => {
    const controller = makeController([deleteAction([], refused)])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)

    click(fixture, 'hilos-table-bulk-delete')
    await press(fixture, 'hilos-table-bulk-confirm')
    click(fixture, 'hilos-action-error-details')

    const dialogs = (fixture.nativeElement as HTMLElement).querySelectorAll(
      '[role="dialog"]',
    )
    const titles = [...dialogs].map((dialog) =>
      dialog.getAttribute('aria-label'),
    )
    expect(titles).toContain("Couldn't delete the marked rows")
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

    const panel = byId(fixture, 'hilos-table-selection')
    expect(panel?.classList.contains('invisible')).toBe(true)
    expect(panel?.getAttribute('aria-hidden')).toBe('true')
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
