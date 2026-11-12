import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h, markRaw, nextTick, ref } from 'vue'
import * as core from '@hilos/core/base'
import {
  TableViewportController,
  bindTimeZoneState,
  createSignal,
} from '@hilos/core/base'
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

import type {
  TableLiveInput,
  TableLiveView,
} from '../../test-support/tableLiveFixtures.js'
import { registerTableLiveFrozenCases } from '../../test-support/tableLiveFrozenCases.js'
import { registerTableLiveRoomCases } from '../../test-support/tableLiveRoomCases.js'

import HilosTableLive from './HilosTableLive.vue'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  vi.restoreAllMocks()
})

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

function layoutClasses(classes: readonly string[]): string[] {
  return classes
    .filter((name) => !PLACEMENT.has(name) && !name.startsWith('alert-'))
    .sort()
}

// The controller is typed as unknown so the component's generic `R` lines up,
// which @vue/test-utils does not infer from the prop value.
function makeController(): TableViewportController<unknown> {
  const controller = new TableViewportController<unknown>({
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
function liveThree(controller: TableViewportController<unknown>): void {
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

function mountLive(
  controller: TableViewportController<unknown>,
  slots: Record<string, unknown> = {},
) {
  return mount(HilosTableLive, {
    props: { controller, columns: COLUMNS },
    slots,
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

/** Retain the wrapper before the shell first renders the real Live. */
async function mountTableLive(input: TableLiveInput): Promise<TableLiveView> {
  const host = document.createElement('div')
  const ready = ref(false)
  let wrapper: VueWrapper | undefined
  const renderErrors: unknown[] = []
  function checkErrors(): void {
    if (renderErrors.length === 1) throw renderErrors.shift()
    if (renderErrors.length > 1) {
      const errors = renderErrors.splice(0)
      throw new AggregateError(errors, 'Vue table live failed', {
        cause: errors[0],
      })
    }
  }
  let released = false
  async function unmount(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of [
      () => wrapper?.unmount(),
      () => nextTick(),
      checkErrors,
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
      throw new AggregateError(errors, 'Vue table live cleanup failed', {
        cause: errors[0],
      })
  }
  try {
    document.body.appendChild(host)
    wrapper = mount(
      {
        render: () =>
          ready.value
            ? h(HilosTableLive, {
                controller: input.controller,
                columns: input.columns,
              })
            : null,
      },
      {
        attachTo: host,
        global: {
          config: { errorHandler: (error) => renderErrors.push(error) },
        },
      },
    )
    ready.value = true
    await nextTick()
    checkErrors()
    return {
      root: host,
      unmount,
      async change(action) {
        action()
        await nextTick()
        checkErrors()
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Vue table live setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableLiveRoomCases('Vue', { describe, it, expect }, mountTableLive)
registerTableLiveFrozenCases('Vue', { describe, it, expect }, mountTableLive)

describe('HilosTableLive', () => {
  it('updates the frozen line and announcement across UTC, Kyiv and Automatic without new table data', async () => {
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
    vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
      ...resolved,
      timeZone: 'America/New_York',
    })
    const listeners = new Set<(signal: ProjectSignal) => void>()
    const connection = {
      projectTimeZone: 'UTC',
      on(event: string, listener: (signal: ProjectSignal) => void) {
        if (event === 'projectSignal') listeners.add(listener)
        return () => listeners.delete(listener)
      },
    }
    const receive = (timeZone: string | null) => {
      for (const listener of listeners) {
        listener({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 1, timeZone } } },
        } as ProjectSignal)
      }
    }
    const release = bindTimeZoneState(connection as unknown as HilosConnection)
    receive('UTC')
    const subscribe = core.subscribeSignal
    const callbacks = vi.fn()
    const releases = vi.fn()
    const subscriptions = vi
      .spyOn(core, 'subscribeSignal')
      .mockImplementation(
        <T>(source: ReadonlySignal<T>, listener: (value: T) => void) => {
          const stop = subscribe(source, (value) => {
            callbacks(value)
            listener(value)
          })
          return () => {
            releases()
            stop()
          }
        },
      )
    const controller = markRaw(makeController())
    const since = Date.parse('2026-10-09T10:11:12.000Z')
    controller.ingestFrozen(since)
    const wrapper = mountLive(controller)
    const line = wrapper.get('[data-id="hilos-table-frozen"]')
    const element = line.element
    const rows = controller.rows.get()
    const expected = (timeZone: string) =>
      `This table has not updated since ${new Intl.DateTimeFormat(undefined, {
        timeStyle: 'medium',
        timeZone,
      }).format(since)}. The rows shown may be out of date.`
    try {
      expect(line.text()).toBe(expected('UTC'))
      for (const zone of ['Europe/Kyiv', null]) {
        receive(zone)
        await wrapper.vm.$nextTick()
        expect(line.text()).toBe(expected(zone ?? 'America/New_York'))
        expect(wrapper.get('[data-id="hilos-table-live-status"]').text()).toBe(
          expected(zone ?? 'America/New_York'),
        )
        expect(wrapper.get('[data-id="hilos-table-frozen"]').element).toBe(
          element,
        )
        expect(wrapper.props('controller')).toBe(controller)
        expect(controller.rows.get()).toBe(rows)
        expect(controller.frozenSince.get()).toBe(since)
      }
      expect(callbacks).toHaveBeenCalledTimes(2)
    } finally {
      wrapper.unmount()
      receive('UTC')
      release()
      expect(releases).toHaveBeenCalledTimes(subscriptions.mock.calls.length)
      expect(callbacks).toHaveBeenCalledTimes(2)
      expect(listeners.size).toBe(0)
    }
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
    const wrapper = mount(HilosTableLive, {
      props: { controller, columns: COLUMNS },
      slots: {
        'table-progress': (props: { progress: HilosTableProgress }) =>
          h(
            'span',
            { 'data-id': 'page-progress-title' },
            String(props.progress.detail['title']),
          ),
        'table-progress-action': (props: { progress: HilosTableProgress }) =>
          h(
            'button',
            { 'data-id': 'page-progress-stop' },
            `Stop ${props.progress.progressKey}`,
          ),
      },
    })

    const line = wrapper.get('[data-id="hilos-table-progress"]')
    expect(line.get('[data-id="page-progress-title"]').text()).toBe(
      'Nightly check',
    )
    expect(line.get('[data-id="page-progress-stop"]').text()).toBe(
      'Stop nightly',
    )
    const track = line.get('[role="progressbar"]')
    expect(track.attributes('aria-valuenow')).toBe('28')
    expect(track.classes()).toEqual(
      expect.arrayContaining(['position-absolute', 'bottom-0', 'w-100']),
    )
  })

  it('announces the actual table work through the slot, updates it and clears it in the same region', async () => {
    const controller = makeController()
    const announce = vi.fn(
      ({ progress }: { progress: HilosTableProgress }) =>
        `${String(progress.detail['task'])}: ${progress.current} of ${progress.total}.`,
    )
    const wrapper = mountLive(controller, {
      'table-progress-announcement': announce,
    })
    const status = wrapper.get('[data-id="hilos-table-live-status"]')
    const region = status.element
    expect(status.text()).toBe('')
    expect(announce).not.toHaveBeenCalled()

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
      detail: { task: 'Nightly audit' },
    })
    await wrapper.vm.$nextTick()

    expect(announce.mock.lastCall?.[0].progress).toBe(
      controller.progress.table.get(),
    )
    expect(status.text()).toBe('Nightly audit: 34 of 120.')
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(status.attributes('role')).toBe('status')
    expect(status.classes()).toContain('visually-hidden')

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 78,
      total: 120,
      detail: { task: 'Nightly verification' },
    })
    await wrapper.vm.$nextTick()

    expect(announce.mock.lastCall?.[0].progress).toBe(
      controller.progress.table.get(),
    )
    expect(status.text()).toBe('Nightly verification: 78 of 120.')
    const calls = announce.mock.calls.length

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 120,
      total: 120,
      ended: true,
    })
    await wrapper.vm.$nextTick()

    expect(status.text()).toBe('')
    expect(announce).toHaveBeenCalledTimes(calls)
    expect(wrapper.get('[data-id="hilos-table-live-status"]').element).toBe(
      region,
    )
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.find('[data-id="hilos-table-progress"]').exists()).toBe(
      false,
    )
  })

  it('keeps the senior announcement, rest and room geometry when a progress slot is supplied', async () => {
    const controller = makeController()
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    liveThree(controller)
    const announce = vi.fn(() => 'Nightly audit is running.')
    const wrapper = mountLive(controller, {
      'table-progress-announcement': announce,
    })
    const twin = wrapper.get('[data-id="hilos-table-live-idle"]')
    const line = wrapper.get('[data-id="hilos-table-pending-row"]')
    const status = wrapper.get('[data-id="hilos-table-live-status"]')

    expect(announce).not.toHaveBeenCalled()
    expect(status.text()).toBe(
      '1 row will move or leave. Also: new rows, a source is behind, work running.',
    )
    expect(layoutClasses(line.classes())).toEqual(layoutClasses(twin.classes()))
    expect(wrapper.findAll('[data-id="hilos-table-live-idle"]')).toHaveLength(1)
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(line.find('[role]').exists()).toBe(false)
    expect(
      wrapper
        .findAll('[data-id="hilos-table-live-rest"] i')
        .map((icon) => icon.classes()),
    ).toEqual([
      ['bi', 'flex-shrink-0', 'bi-arrow-down-circle'],
      ['bi', 'flex-shrink-0', 'bi-snow'],
      ['bi', 'flex-shrink-0', 'bi-arrow-repeat'],
    ])

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 120,
      total: 120,
      ended: true,
    })
    await wrapper.vm.$nextTick()

    expect(status.text()).toBe(
      '1 row will move or leave. Also: new rows, a source is behind.',
    )
    expect(wrapper.get('[data-id="hilos-table-pending-row"]').element).toBe(
      line.element,
    )
    expect(layoutClasses(line.classes())).toEqual(layoutClasses(twin.classes()))
    expect(announce).not.toHaveBeenCalled()
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
    const wrapper = mountLive(controller)

    const line = wrapper.get('[data-id="hilos-table-progress-bulk"]')
    expect(line.text()).toContain('Delete: 12 of 40')
    const track = line.get('[role="progressbar"]')
    expect(track.attributes('aria-label')).toBe('Working on the marked rows')
    expect(track.classes()).toEqual(
      expect.arrayContaining(['position-absolute', 'bottom-0', 'w-100']),
    )

    // Work under another key: neutral caption
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'someone-else',
      current: 3,
      total: 9,
    })
    await wrapper.vm.$nextTick()
    expect(
      wrapper.get('[data-id="hilos-table-progress-bulk"]').text(),
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
    const wrapper = mountLive(controller)

    expect(
      wrapper.get('[data-id="hilos-table-progress-bulk"]').text(),
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
    const wrapper = mountLive(controller)

    const plate = wrapper.get('[data-id="hilos-table-bulk-report"]')
    expect(plate.text()).toContain('Changed 39 rows')
    expect(plate.text()).not.toContain('untouched')
    expect(plate.classes()).toContain('alert-success')
    expect(
      wrapper.find('[data-id="hilos-table-bulk-report-details"]').exists(),
    ).toBe(false)
    expect(
      wrapper.find('[data-id="hilos-table-bulk-report-close"]').exists(),
    ).toBe(true)
  })

  it('names every untouched row and counts the names that did not fit in details modal', async () => {
    const controller = makeController()
    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [{ rowKey: 'r7', reason: 'Someone deleted it first' }],
      untouchedOmitted: 4128,
    })
    const wrapper = mountLive(controller)

    const plate = wrapper.get('[data-id="hilos-table-bulk-report"]')
    expect(plate.classes()).toContain('alert-warning')
    expect(plate.text()).toContain('Changed 39 rows, 4129 untouched')

    const detailsBtn = wrapper.find(
      '[data-id="hilos-table-bulk-report-details"]',
    )
    expect(detailsBtn.exists()).toBe(true)
    await detailsBtn.trigger('click')

    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    const list = document.querySelector(
      '[data-id="hilos-table-bulk-report-list"]',
    )
    expect(list?.textContent).toContain('r7')
    expect(list?.textContent).toContain('Someone deleted it first')
    expect(list?.textContent).toContain('and 4128 more')
  })

  it('prints the human name a page gave the untouched row in details modal', async () => {
    const controller = makeController()
    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [{ rowKey: 'r7', reason: 'Already gone' }],
      untouchedOmitted: 0,
    })
    const wrapper = mountLive(controller, {
      'bulk-untouched': (props: { rowKey: string; reason: string }) =>
        `27.08 03:00 (${props.rowKey})`,
    })

    await wrapper
      .find('[data-id="hilos-table-bulk-report-details"]')
      .trigger('click')

    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    const list = document.querySelector(
      '[data-id="hilos-table-bulk-report-list"]',
    )
    expect(list?.textContent).toContain('27.08 03:00 (r7)')
  })

  it('dismisses the report through the controller', async () => {
    const controller = makeController()
    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [],
      untouchedOmitted: 0,
    })
    const wrapper = mountLive(controller)

    await wrapper
      .find('[data-id="hilos-table-bulk-report-close"]')
      .trigger('click')

    expect(controller.bulk.report.get()).toBeNull()
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-id="hilos-table-bulk-report"]').exists()).toBe(
      false,
    )
  })
})
