import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { StrictMode } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { createRoot, type Root } from 'react-dom/client'
import {
  TableViewportController,
  bindTimeZoneState,
  createSignal,
  hilosTableFrozenLabel,
  hilosTimeZone,
} from '@hilos/core/base'
import * as core from '@hilos/core/base'
import type {
  ActionHandle,
  HilosConnection,
  HilosTableBulkAccepted,
  HilosTableBulkAction,
  HilosTableColumn,
  HilosTableProgress,
  ProjectSignal,
  ReadonlySignal,
} from '@hilos/core/base'
import type { ReactNode } from 'react'

import type {
  TableLiveInput,
  TableLiveView,
} from '../../test-support/tableLiveFixtures.js'
import { registerTableLiveFrozenCases } from '../../test-support/tableLiveFrozenCases.js'
import { registerTableLiveRoomCases } from '../../test-support/tableLiveRoomCases.js'

import { HilosTableLive } from '../src/HilosTableLive.js'

// The React port of vue/src/HilosTableLive.test.ts, under the same case names.

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
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

/** The real zone binding consumes these connection events, as at boot. */
function zoneConnection() {
  const listeners = new Map<string, Set<(signal: ProjectSignal) => void>>()
  return {
    projectTimeZone: 'UTC',
    on(event: string, listener: (signal: ProjectSignal) => void) {
      const eventListeners = listeners.get(event) ?? new Set()
      eventListeners.add(listener)
      listeners.set(event, eventListeners)
      return () => eventListeners.delete(listener)
    },
    receive(timeZone: string | null) {
      for (const listener of listeners.get('projectSignal') ?? []) {
        listener({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 1, timeZone } } },
        } as ProjectSignal)
      }
    },
    listenerCount() {
      return [...listeners.values()].reduce(
        (count, eventListeners) => count + eventListeners.size,
        0,
      )
    },
  }
}

/** Retain the native root before React first renders the real Live. */
async function mountTableLive(input: TableLiveInput): Promise<TableLiveView> {
  const host = document.createElement('div')
  let root: Root | undefined
  let released = false
  async function unmount(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of [
      () =>
        act(async () => {
          root?.unmount()
        }),
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
      throw new AggregateError(errors, 'React table live cleanup failed', {
        cause: errors[0],
      })
  }
  try {
    document.body.appendChild(host)
    root = createRoot(host)
    await act(async () => {
      root!.render(
        <HilosTableLive
          controller={input.controller}
          columns={input.columns}
        />,
      )
    })
    return {
      root: host,
      unmount,
      async change(action) {
        await act(async () => action())
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'React table live setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableLiveRoomCases('React', { describe, it, expect }, mountTableLive)
registerTableLiveFrozenCases('React', { describe, it, expect }, mountTableLive)

describe('HilosTableLive', () => {
  it('updates the frozen line across UTC, Kyiv and Automatic without changing table data', () => {
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
    vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
      ...resolved,
      timeZone: 'America/New_York',
    })
    const connection = zoneConnection()
    const release = bindTimeZoneState(connection as unknown as HilosConnection)
    const receive = (timeZone: string | null) =>
      act(() => connection.receive(timeZone))
    receive('UTC')

    const subscribe = core.subscribeSignal
    const callbacks = vi.fn()
    const releases = vi.fn()
    let activeZoneSubscriptions = 0
    const subscriptions = vi
      .spyOn(core, 'subscribeSignal')
      .mockImplementation(
        <T,>(source: ReadonlySignal<T>, listener: (value: T) => void) => {
          let active = true
          const stop = subscribe(source, (value) => {
            if (source === hilosTimeZone) callbacks(value)
            listener(value)
          })
          if (source === hilosTimeZone) activeZoneSubscriptions++
          return () => {
            if (!active) return
            active = false
            if (source === hilosTimeZone) activeZoneSubscriptions--
            releases()
            stop()
          }
        },
      )

    const controller = makeController()
    const since = Date.parse('2026-10-09T10:11:12.000Z')
    controller.ingestFrozen(since)
    const rows = controller.rows.get()
    const expected = (timeZone: string) =>
      `This table has not updated since ${new Intl.DateTimeFormat(undefined, {
        timeStyle: 'medium',
        timeZone,
      }).format(since)}. The rows shown may be out of date.`
    const mounted = render(
      <StrictMode>
        <HilosTableLive controller={controller} columns={COLUMNS} />
      </StrictMode>,
    )
    const line = byId(mounted.container, 'hilos-table-frozen')!
    const element = line
    try {
      expect(line.textContent).toBe(expected('UTC'))
      expect(activeZoneSubscriptions).toBe(1)
      expect(connection.listenerCount()).toBe(2)

      receive('Europe/Kyiv')
      expect(line.textContent).toBe(expected('Europe/Kyiv'))
      expect(
        byId(mounted.container, 'hilos-table-live-status')?.textContent,
      ).toBe(expected('Europe/Kyiv'))
      expect(byId(mounted.container, 'hilos-table-frozen')).toBe(element)
      expect(controller.rows.get()).toBe(rows)
      expect(controller.frozenSince.get()).toBe(since)
      expect(activeZoneSubscriptions).toBe(1)
      expect(callbacks).toHaveBeenCalledTimes(1)

      receive('Europe/Kyiv')
      expect(callbacks).toHaveBeenCalledTimes(1)
      receive(null)
      expect(line.textContent).toBe(expected('America/New_York'))
      expect(byId(mounted.container, 'hilos-table-frozen')).toBe(element)
      expect(controller.rows.get()).toBe(rows)
      expect(controller.frozenSince.get()).toBe(since)
      expect(callbacks).toHaveBeenCalledTimes(2)

      mounted.unmount()
      expect(activeZoneSubscriptions).toBe(0)
      receive('UTC')
      expect(callbacks).toHaveBeenCalledTimes(2)

      release()
      const rebound = zoneConnection()
      const stop = bindTimeZoneState(rebound as unknown as HilosConnection)
      const receiveRebound = (timeZone: string | null) =>
        act(() => rebound.receive(timeZone))
      receiveRebound('UTC')
      const reboundMount = render(
        <StrictMode>
          <HilosTableLive controller={controller} columns={COLUMNS} />
        </StrictMode>,
      )
      try {
        expect(
          byId(reboundMount.container, 'hilos-table-frozen')?.textContent,
        ).toBe(expected('UTC'))
        expect(activeZoneSubscriptions).toBe(1)
        receiveRebound('Europe/Kyiv')
        expect(
          byId(reboundMount.container, 'hilos-table-frozen')?.textContent,
        ).toBe(expected('Europe/Kyiv'))
        expect(callbacks).toHaveBeenCalledTimes(3)
        reboundMount.unmount()
        expect(activeZoneSubscriptions).toBe(0)
        receiveRebound(null)
        expect(callbacks).toHaveBeenCalledTimes(3)
      } finally {
        stop()
      }
      expect(releases).toHaveBeenCalledTimes(subscriptions.mock.calls.length)
    } finally {
      mounted.unmount()
      release()
    }
  })

  it('renders its frozen label on the server without a window', () => {
    const controller = makeController()
    const since = Date.parse('2026-10-09T10:11:12.000Z')
    controller.ingestFrozen(since)
    const expected = hilosTableFrozenLabel(since)
    vi.stubGlobal('window', undefined)

    try {
      expect(
        renderToStaticMarkup(
          <HilosTableLive controller={controller} columns={COLUMNS} />,
        ),
      ).toContain(expected)
    } finally {
      vi.unstubAllGlobals()
    }
  })

  it('announces the supplied table progress phrase from the current bar', () => {
    const controller = makeController()
    const announcement = vi.fn(
      (progress: HilosTableProgress) =>
        `${String(progress.detail['title'])}: ${progress.current} of ${progress.total}`,
    )
    const { container } = render(
      <HilosTableLive
        controller={controller}
        columns={COLUMNS}
        tableProgressAnnouncement={announcement}
      />,
    )
    expect(announcement).not.toHaveBeenCalled()
    expect(byId(container, 'hilos-table-live-status')?.textContent).toBe('')

    act(() =>
      controller.ingestProgress({
        scope: 'table',
        progressKey: 'nightly',
        current: 3,
        total: 12,
        detail: { title: 'Nightly check' },
      }),
    )
    expect(announcement).toHaveBeenLastCalledWith({
      progressKey: 'nightly',
      current: 3,
      total: 12,
      fraction: 0.25,
      detail: { title: 'Nightly check' },
    })
    expect(
      byId(container, 'hilos-table-live-status')?.getAttribute('role'),
    ).toBe('status')
    expect(byId(container, 'hilos-table-live-status')?.textContent).toBe(
      'Nightly check: 3 of 12',
    )

    act(() =>
      controller.ingestProgress({
        scope: 'table',
        progressKey: 'nightly',
        current: 6,
        total: 12,
        detail: { title: 'Nightly check' },
      }),
    )
    expect(byId(container, 'hilos-table-live-status')?.textContent).toBe(
      'Nightly check: 6 of 12',
    )
    act(() =>
      controller.ingestProgress({
        scope: 'table',
        progressKey: 'nightly',
        current: 12,
        ended: true,
      }),
    )
    expect(byId(container, 'hilos-table-live-status')?.textContent).toBe('')
  })

  it('keeps the standard progress announcement when the page supplies none', () => {
    const controller = makeController()
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 3,
    })
    const { container } = renderLive(controller)

    expect(byId(container, 'hilos-table-live-status')?.textContent).toBe(
      'Work is running on this table.',
    )
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
    const { container } = render(
      <HilosTableLive
        controller={controller}
        columns={COLUMNS}
        tableProgressAnnouncement={announcement}
      />,
    )

    expect(byId(container, 'hilos-table-pending-row')).not.toBeNull()
    expect(byId(container, 'hilos-table-progress')).toBeNull()
    expect(byId(container, 'hilos-table-live-status')?.textContent).toBe(
      '1 row will move or leave. Also: new rows, a source is behind, work running.',
    )
    expect(announcement).not.toHaveBeenCalled()
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
