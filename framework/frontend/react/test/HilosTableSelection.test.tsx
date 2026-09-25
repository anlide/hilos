import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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
  shown?: boolean
}

function panel(
  controller: TableViewportController<unknown>,
  options: PanelOptions = {},
) {
  return (
    <HilosTableSelection
      controller={controller}
      shown={options.shown ?? true}
    />
  )
}

function renderPanel(
  controller: TableViewportController<unknown>,
  options: PanelOptions = {},
) {
  return render(panel(controller, options))
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

    const panelEl = byId('hilos-table-selection')
    expect(panelEl?.classList.contains('invisible')).toBe(true)
    expect(panelEl?.getAttribute('aria-hidden')).toBe('true')
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
