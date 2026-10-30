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
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'

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

function deferred<T>(): {
  promise: Promise<T>
  resolve(value: T): void
  reject(reason: unknown): void
} {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })

  return { promise, resolve, reject }
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

/**
 * Mount the panel over a controller, on an admin page when asked.
 *
 * @param controller The table's controller.
 * @param viewMode The admin page's view mode, or undefined for no admin page.
 */
function mountPanel(
  controller: TableViewportController<unknown>,
  viewMode?: boolean,
): ComponentFixture<SelectionHost> {
  if (viewMode !== undefined) {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: HILOS_ADMIN_VIEW_MODE,
          useValue: signal(viewMode).asReadonly(),
        },
      ],
    })
  }
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

  it('locks through the reply, retries a refusal, and leaves later progress to the table', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const reply = deferred<{ reply: HilosTableBulkAccepted }>()
    let runs = 0
    const controller = makeController([
      deleteAction(asked, () => {
        runs += 1

        return runs === 1
          ? {
              requestId: 'req-1',
              loading: createSignal(false),
              done: reply.promise,
            }
          : accepted('run-2')
      }),
    ])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller)

    click(fixture, 'hilos-table-bulk-delete')
    const modal = byId(fixture, 'modal') as HTMLElement
    const message = modal.querySelector('p') as HTMLParagraphElement
    const originalMessage = message.textContent
    const cancel = Array.from(
      modal.querySelectorAll<HTMLButtonElement>('button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')!

    await press(fixture, 'hilos-table-bulk-confirm')

    const close = byId(fixture, 'modal-close') as HTMLButtonElement
    expect(close.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    close.click()
    cancel.click()
    modal.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    modal.click()
    fixture.detectChanges()
    expect(byId(fixture, 'modal')).toBe(modal)

    reply.reject(
      new ActionError('backups_delete', 'fail', 'The node is frozen'),
    )
    await new Promise((resolve) => setTimeout(resolve, 0))
    fixture.detectChanges()

    expect(byId(fixture, 'modal')).toBe(modal)
    expect(modal.querySelector('p')).toBe(message)
    expect(message.textContent).toBe(originalMessage)
    expect(fixture.nativeElement.textContent).toContain('The node is frozen')
    expect(close.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(runs).toBe(1)

    await press(fixture, 'hilos-table-bulk-confirm')

    expect(asked).toHaveLength(2)
    expect(byId(fixture, 'modal')).toBeNull()
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-2',
      current: 1,
      total: 4,
    })
    fixture.detectChanges()
    expect(byId(fixture, 'modal')).toBeNull()
    expect(
      (byId(fixture, 'hilos-table-bulk-delete') as HTMLButtonElement).disabled,
    ).toBe(true)

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-2',
      current: 4,
      total: 4,
      ended: true,
    })
    fixture.detectChanges()
    expect(
      (byId(fixture, 'hilos-table-bulk-delete') as HTMLButtonElement).disabled,
    ).toBe(false)
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

describe('HilosTableSelection in the admin view mode', () => {
  it('disables the operations and keeps the marks live', () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked)])
    controller.selectRow('a', true)
    const fixture = mountPanel(controller, true)

    const operation = byId(
      fixture,
      'hilos-table-bulk-delete',
    ) as HTMLButtonElement
    expect(operation.disabled).toBe(true)
    expect(operation.getAttribute('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )

    const selectAll = byId(
      fixture,
      'hilos-table-select-all-filtered',
    ) as HTMLButtonElement
    const clear = byId(
      fixture,
      'hilos-table-selection-clear',
    ) as HTMLButtonElement
    expect(selectAll.disabled).toBe(false)
    expect(clear.disabled).toBe(false)

    click(fixture, 'hilos-table-select-all-filtered')
    expect(controller.selection.target.get()?.kind).toBe('filter')
    click(fixture, 'hilos-table-selection-clear')
    expect(controller.selection.count.get()).toBe(0)
    expect(asked).toEqual([])
  })

  it('leaves the operations untouched outside the mode', () => {
    for (const viewMode of [undefined, false]) {
      TestBed.resetTestingModule()
      const controller = makeController([deleteAction([])])
      controller.selectRow('a', true)
      const fixture = mountPanel(controller, viewMode)

      const operation = byId(
        fixture,
        'hilos-table-bulk-delete',
      ) as HTMLButtonElement
      expect(operation.disabled).toBe(false)
      expect(operation.getAttribute('aria-describedby')).toBeNull()
    }
  })
})
