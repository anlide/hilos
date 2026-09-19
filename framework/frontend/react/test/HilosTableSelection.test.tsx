import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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
import type { ReactNode } from 'react'

import { HilosTableSelection } from '../src/HilosTableSelection.js'

// The React port of vue/src/HilosTableSelection.test.ts, under the same case
// names. The confirmation portals to <body>, so every query reads the document
// rather than the render.

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

const COLUMNS = [{ key: 'name', label: 'Name' }]

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

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

interface PanelOptions {
  report?: HilosTableBulkReport | null
  shown?: boolean
  dismissed?: string[]
  bulkUntouched?: (rowKey: string, reason: string) => ReactNode
}

function panel(
  controller: TableViewportController<unknown>,
  options: PanelOptions = {},
) {
  const dismissed = options.dismissed ?? []

  return (
    <HilosTableSelection
      controller={controller}
      shown={options.shown ?? true}
      report={options.report ?? null}
      onDismiss={(progressKey) => dismissed.push(progressKey)}
      bulkUntouched={options.bulkUntouched}
    />
  )
}

function renderPanel(
  controller: TableViewportController<unknown>,
  options: PanelOptions = {},
) {
  return render(panel(controller, options))
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

/** Press a button and wait out the promises the send settles through. */
async function press(id: string): Promise<void> {
  await act(async () => {
    fireEvent.click(byId(id) as HTMLElement)
    await new Promise((resolve) => setTimeout(resolve, 0))
  })
}

describe('HilosTableSelection', () => {
  it('counts the marked rows of this page, and says the condition in words', () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    renderPanel(controller)

    expect(byId('hilos-table-selection-count')?.textContent).toBe(
      '1 marked on this page',
    )
    expect(byId('hilos-table-select-all-filtered')).not.toBeNull()

    act(() => controller.selectAllByFilter())

    // The size of a large set is a ceiling rather than a count, so the panel says
    // what it is sure of; and the button that took the condition is gone, because
    // pressing it again would change nothing.
    expect(byId('hilos-table-selection-count')?.textContent).toBe(
      'All rows matching the filter',
    )
    expect(byId('hilos-table-select-all-filtered')).toBeNull()
  })

  it('drops the marks through the core', () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    renderPanel(controller)

    fireEvent.click(byId('hilos-table-selection-clear') as HTMLElement)

    expect(controller.selection.count.get()).toBe(0)
  })

  it('disables the declared operations while a run is going', () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    renderPanel(controller)

    expect(
      (byId('hilos-table-bulk-delete') as HTMLButtonElement).disabled,
    ).toBe(false)

    act(() =>
      controller.ingestProgress({
        scope: 'bulk',
        progressKey: 'run-1',
        current: 1,
        total: 4,
      }),
    )

    expect(
      (byId('hilos-table-bulk-delete') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('confirms every operation and sends the target the core gives at that moment', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked)])
    controller.selectRow('a', true)
    renderPanel(controller)

    fireEvent.click(byId('hilos-table-bulk-delete') as HTMLElement)
    expect(byId('modal')).not.toBeNull()
    expect(document.body.textContent).toContain(
      '1 row on this page will be affected',
    )
    expect(asked).toHaveLength(0)

    // Between the press and the confirmation the reader marked one more row: the
    // target is read HERE, so the run takes both.
    act(() => controller.selectRow('b', true))

    await press('hilos-table-bulk-confirm')

    expect(asked).toEqual([{ kind: 'rows', rowKeys: ['a', 'b'] }])
    expect(byId('modal')).toBeNull()
  })

  it('keeps the confirmation open with the refusal it was answered with', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked, refused)])
    controller.selectRow('a', true)
    renderPanel(controller)

    fireEvent.click(byId('hilos-table-bulk-delete') as HTMLElement)
    await press('hilos-table-bulk-confirm')

    expect(byId('modal')).not.toBeNull()
    expect(document.body.textContent).toContain('The node is frozen')
  })

  it('names the running operation on its own bar and stays neutral on another', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked)])
    controller.selectRow('a', true)
    renderPanel(controller)

    fireEvent.click(byId('hilos-table-bulk-delete') as HTMLElement)
    await press('hilos-table-bulk-confirm')

    act(() =>
      controller.ingestProgress({
        scope: 'bulk',
        progressKey: 'run-1',
        current: 12,
        total: 40,
      }),
    )
    expect(byId('hilos-table-progress-bulk')?.textContent).toBe(
      'Delete: 12 of 40',
    )

    // Work under a key this panel never asked for: it knows nothing about what it
    // is, so it says only that something is running over the marked rows.
    act(() =>
      controller.ingestProgress({
        scope: 'bulk',
        progressKey: 'someone-else',
        current: 3,
        total: 9,
      }),
    )
    expect(byId('hilos-table-progress-bulk')?.textContent).toBe(
      'Working on the marked rows',
    )
  })

  it('drops the total from the caption of work that named none', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked)])
    controller.selectRow('a', true)
    renderPanel(controller)

    fireEvent.click(byId('hilos-table-bulk-delete') as HTMLElement)
    await press('hilos-table-bulk-confirm')

    act(() =>
      controller.ingestProgress({
        scope: 'bulk',
        progressKey: 'run-1',
        current: 12,
      }),
    )

    expect(byId('hilos-table-progress-bulk')?.textContent).toBe('Delete')
  })

  it('reads the outcome out of the report, calmly when nothing was left alone', () => {
    const controller = makeController([deleteAction([])])
    renderPanel(controller, { report: report() })

    const plate = byId('hilos-table-bulk-report')
    expect(plate?.textContent).toContain('Changed 39 rows')
    expect(plate?.textContent).not.toContain('untouched')
    expect(plate?.classList.contains('alert-success')).toBe(true)
  })

  it('names every untouched row and counts the names that did not fit', () => {
    const controller = makeController([deleteAction([])])
    renderPanel(controller, {
      report: report({
        untouched: [{ rowKey: 'r7', reason: 'Someone deleted it first' }],
        untouchedOmitted: 4128,
      }),
    })

    const plate = byId('hilos-table-bulk-report')
    expect(plate?.classList.contains('alert-warning')).toBe(true)
    expect(plate?.textContent).toContain('Changed 39 rows, 4129 untouched')
    expect(plate?.textContent).toContain('r7')
    expect(plate?.textContent).toContain('Someone deleted it first')
    expect(plate?.textContent).toContain('and 4128 more')
  })

  it('prints the human name a page gave the untouched row', () => {
    const controller = makeController([deleteAction([])])
    renderPanel(controller, {
      report: report({ untouched: [{ rowKey: 'r7', reason: 'Already gone' }] }),
      bulkUntouched: (rowKey) => `27.08 03:00 (${rowKey})`,
    })

    expect(byId('hilos-table-bulk-report')?.textContent).toContain(
      '27.08 03:00 (r7)',
    )
  })

  it('tells the bar above which run the reader dismissed', () => {
    const controller = makeController([deleteAction([])])
    const dismissed: string[] = []
    renderPanel(controller, { report: report(), dismissed })

    fireEvent.click(byId('hilos-table-bulk-report-close') as HTMLElement)

    expect(dismissed).toEqual(['run-1'])
  })

  it('keeps an open confirmation when the panel stops standing', () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    const view = renderPanel(controller)

    fireEvent.click(byId('hilos-table-bulk-delete') as HTMLElement)
    expect(byId('modal')).not.toBeNull()

    // The strip goes when the marks do — a window can arrive with none of them
    // left — and the dialog holds the focus and the page's scroll lock, neither of
    // which is the strip's to hand back on its way out.
    view.rerender(panel(controller, { shown: false }))

    expect(byId('hilos-table-selection')).toBeNull()
    expect(byId('modal')).not.toBeNull()
  })

  it('names itself to a screen reader as one group', () => {
    const controller = makeController([deleteAction([])])
    renderPanel(controller)

    const group = byId('hilos-table-selection')
    expect(group?.getAttribute('role')).toBe('group')
    expect(group?.getAttribute('aria-label')).toBe('Selection')
  })
})
