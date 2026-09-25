import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { TableViewportController, createSignal } from '@hilos/core'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableBulkAction,
  HilosTableColumn,
  HilosTableProgress,
} from '@hilos/core'
import type { ReactNode } from 'react'

import { HilosTableLive } from '../src/HilosTableLive.js'

// The React port of vue/src/HilosTableLive.test.ts, under the same case names.

afterEach(() => {
  cleanup()
  document.body.innerHTML = ''
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

function renderLive(
  controller: TableViewportController<Row>,
  bulkUntouched?: (rowKey: string, reason: string) => ReactNode,
) {
  return render(
    <HilosTableLive
      controller={controller}
      columns={COLUMNS}
      bulkUntouched={bulkUntouched}
    />,
  )
}

function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

describe('HilosTableLive', () => {
  it('stands exactly one hidden twin with nothing, one and three messages live', () => {
    const controller = makeController()
    const { container } = renderLive(controller)
    const twins = () =>
      container.querySelectorAll('[data-id="hilos-table-live-idle"]')
    expect(twins()).toHaveLength(1)

    act(() => controller.ingestAnnounce('b', 'above', 2, true))
    expect(twins()).toHaveLength(1)

    act(() => liveThree(controller))
    expect(twins()).toHaveLength(1)
  })

  it('keeps the twin out of sight and out of the accessibility tree', () => {
    const { container } = renderLive(makeController())
    const twin = byId(container, 'hilos-table-live-idle') as HTMLElement

    expect(twin.classList.contains('invisible')).toBe(true)
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    // The twin holds room and takes no focus, so there is no button inside it.
    expect(twin.querySelector('button')).toBeNull()
  })

  it('lays the message out exactly as the twin, only placed over it', () => {
    const controller = makeController()
    const { container } = renderLive(controller)
    act(() => controller.ingestAnnounce('b', 'above', 2, true))

    const twin = byId(container, 'hilos-table-live-idle') as HTMLElement
    const message = byId(container, 'hilos-table-announce') as HTMLElement
    expect(layoutClasses(message)).toEqual(layoutClasses(twin))
    // Out of the flow, so the message cannot add a pixel to the room.
    expect(message.classList.contains('position-absolute')).toBe(true)
  })

  it('draws no line with nothing to say, while the room still stands', () => {
    const { container } = renderLive(makeController())

    expect(byId(container, 'hilos-table-live-slot')).not.toBeNull()
    expect(container.querySelector('.position-absolute')).toBeNull()
    expect(byId(container, 'hilos-table-live-status')?.textContent).toBe('')
  })

  it('gives the line to the senior message and the others their icons', () => {
    const controller = makeController()
    liveThree(controller)
    const { container } = renderLive(controller)

    expect(container.querySelectorAll('.position-absolute')).toHaveLength(1)
    expect(byId(container, 'hilos-table-pending-row')?.textContent).toContain(
      '1 row will move or leave',
    )
    expect(byId(container, 'hilos-table-apply')).not.toBeNull()
    expect(byId(container, 'hilos-table-announce')).toBeNull()
    expect(byId(container, 'hilos-table-stale')).toBeNull()

    const icons = Array.from(
      container.querySelectorAll('[data-id="hilos-table-live-rest"] i'),
    )
    expect(icons.map((icon) => Array.from(icon.classList))).toEqual([
      ['bi', 'flex-shrink-0', 'bi-arrow-down-circle'],
      ['bi', 'flex-shrink-0', 'bi-snow'],
    ])
    expect(
      icons.every((icon) => icon.getAttribute('aria-hidden') === 'true'),
    ).toBe(true)
  })

  it('speaks the senior sentence and names the rest in the region that always stands', () => {
    const controller = makeController()
    liveThree(controller)
    const { container } = renderLive(controller)

    const status = byId(container, 'hilos-table-live-status') as HTMLElement
    expect(status.getAttribute('role')).toBe('status')
    expect(status.textContent).toBe(
      '1 row will move or leave. Also: new rows above the window, a source is behind.',
    )
  })

  it('keeps the live region off the line itself', () => {
    const controller = makeController()
    liveThree(controller)
    const { container } = renderLive(controller)

    expect(
      byId(container, 'hilos-table-pending-row')?.querySelector('[role]'),
    ).toBeNull()
  })

  it('names the columns built from the quiet source on the freshness line', () => {
    const controller = makeController()
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['connections'],
    })
    const { container } = renderLive(controller)

    expect(byId(container, 'hilos-table-stale')?.textContent).toBe(
      'Presence is not updating: the link to its source was lost. The other columns are live.',
    )
  })

  it('draws running work on one line, the track along its bottom edge', () => {
    const controller = makeController()
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
      detail: { title: 'Nightly check' },
    })
    const { container } = render(
      <HilosTableLive
        controller={controller}
        columns={COLUMNS}
        tableProgress={(progress: HilosTableProgress) => (
          <span data-id="page-progress-title">
            {String(progress.detail['title'])}
          </span>
        )}
        tableProgressAction={(progress: HilosTableProgress) => (
          <button type="button" data-id="page-progress-stop">
            {`Stop ${progress.progressKey}`}
          </button>
        )}
      />,
    )

    const line = byId(container, 'hilos-table-progress') as HTMLElement
    expect(byId(line, 'page-progress-title')?.textContent).toBe('Nightly check')
    expect(byId(line, 'page-progress-stop')?.textContent).toBe('Stop nightly')
    const track = line.querySelector('[role="progressbar"]') as HTMLElement
    expect(track.getAttribute('aria-valuenow')).toBe('28')
    expect(track.classList.contains('position-absolute')).toBe(true)
    expect(track.classList.contains('bottom-0')).toBe(true)
    expect(track.classList.contains('w-100')).toBe(true)
  })

  it('names the running bulk operation on its own bar and stays neutral on another', async () => {
    const controller = makeController()
    controller.runBulk(deleteAction(), { kind: 'rows', rowKeys: ['a'] })
    await Promise.resolve()

    act(() =>
      controller.ingestProgress({
        scope: 'bulk',
        progressKey: 'run-1',
        current: 12,
        total: 40,
      }),
    )
    const { container } = renderLive(controller)

    const line = byId(container, 'hilos-table-progress-bulk') as HTMLElement
    expect(line.textContent).toContain('Delete: 12 of 40')
    const track = line.querySelector('[role="progressbar"]') as HTMLElement
    expect(track.getAttribute('aria-label')).toBe('Working on the marked rows')
    expect(track.classList.contains('position-absolute')).toBe(true)
    expect(track.classList.contains('bottom-0')).toBe(true)
    expect(track.classList.contains('w-100')).toBe(true)

    // Work under another key: neutral caption
    act(() =>
      controller.ingestProgress({
        scope: 'bulk',
        progressKey: 'someone-else',
        current: 3,
        total: 9,
      }),
    )
    expect(byId(container, 'hilos-table-progress-bulk')?.textContent).toContain(
      'Working on the marked rows',
    )
  })

  it('drops the total from the bulk caption of work that named none', async () => {
    const controller = makeController()
    controller.runBulk(deleteAction(), { kind: 'rows', rowKeys: ['a'] })
    await Promise.resolve()

    act(() =>
      controller.ingestProgress({
        scope: 'bulk',
        progressKey: 'run-1',
        current: 12,
      }),
    )
    const { container } = renderLive(controller)

    expect(byId(container, 'hilos-table-progress-bulk')?.textContent).toContain(
      'Delete',
    )
  })

  it('reads the outcome out of the report, calmly when nothing was left alone', () => {
    const controller = makeController()
    act(() =>
      controller.ingestBulkReport({
        progressKey: 'run-1',
        touched: 39,
        untouched: [],
        untouchedOmitted: 0,
      }),
    )
    const { container } = renderLive(controller)

    const plate = byId(container, 'hilos-table-bulk-report') as HTMLElement
    expect(plate.textContent).toContain('Changed 39 rows')
    expect(plate.textContent).not.toContain('untouched')
    expect(plate.classList.contains('alert-success')).toBe(true)
    expect(byId(container, 'hilos-table-bulk-report-details')).toBeNull()
    expect(byId(container, 'hilos-table-bulk-report-close')).not.toBeNull()
  })

  it('names every untouched row and counts the names that did not fit in details modal', () => {
    const controller = makeController()
    act(() =>
      controller.ingestBulkReport({
        progressKey: 'run-1',
        touched: 39,
        untouched: [{ rowKey: 'r7', reason: 'Someone deleted it first' }],
        untouchedOmitted: 4128,
      }),
    )
    const { container } = renderLive(controller)

    const plate = byId(container, 'hilos-table-bulk-report') as HTMLElement
    expect(plate.classList.contains('alert-warning')).toBe(true)
    expect(plate.textContent).toContain('Changed 39 rows, 4129 untouched')

    const detailsBtn = byId(
      container,
      'hilos-table-bulk-report-details',
    ) as HTMLElement
    expect(detailsBtn).not.toBeNull()
    act(() => {
      detailsBtn.click()
    })

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
    act(() =>
      controller.ingestBulkReport({
        progressKey: 'run-1',
        touched: 39,
        untouched: [{ rowKey: 'r7', reason: 'Already gone' }],
        untouchedOmitted: 0,
      }),
    )
    const { container } = renderLive(
      controller,
      (rowKey) => `27.08 03:00 (${rowKey})`,
    )

    const detailsBtn = byId(
      container,
      'hilos-table-bulk-report-details',
    ) as HTMLElement
    act(() => {
      detailsBtn.click()
    })

    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    const list = document.querySelector(
      '[data-id="hilos-table-bulk-report-list"]',
    )
    expect(list?.textContent).toContain('27.08 03:00 (r7)')
  })

  it('dismisses the report through the controller', () => {
    const controller = makeController()
    act(() =>
      controller.ingestBulkReport({
        progressKey: 'run-1',
        touched: 39,
        untouched: [],
        untouchedOmitted: 0,
      }),
    )
    const { container } = renderLive(controller)

    const closeBtn = byId(
      container,
      'hilos-table-bulk-report-close',
    ) as HTMLElement
    act(() => {
      closeBtn.click()
    })

    expect(controller.bulk.report.get()).toBeNull()
    expect(byId(container, 'hilos-table-bulk-report')).toBeNull()
  })
})
