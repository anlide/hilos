import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { vi } from 'vitest'
import {
  bindPageScope,
  createSignal,
  HilosPages,
  type TableRow,
} from '@hilos/core/base'
import type {
  HilosStatusWorkRow,
  HilosStatusWorksData,
} from '@hilos/core/admin/status'

import HilosStatusWorksPage from '../src/admin/status/HilosStatusWorksPage.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'
import { statusHarness } from './statusFixtures.js'

export const announcement: HilosStatusWorkRow = {
  id: 7,
  publicId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  title: 'Database upgrade',
  description: null,
  componentKeys: ['service'],
  startsAt: Date.UTC(2031, 0, 2, 3, 4, 5),
  endsAt: Date.UTC(2031, 0, 2, 4, 5, 6),
  version: 1,
  canceledAt: null,
  consumedAt: null,
  expiredAt: null,
  lastMailQueuedAt: null,
}

export function workRow(patch: Partial<HilosStatusWorkRow> = {}): TableRow {
  const work = { ...announcement, ...patch }
  return { rowKey: String(work.id), slots: { work } }
}

export const worksData: HilosStatusWorksData = {
  components: [
    { componentKey: 'service', name: 'Application' },
    { componentKey: 'queue', name: 'Message queue' },
    { componentKey: 'database', name: 'Database' },
  ],
  upcomingCount: 1,
  nextWork: {
    id: announcement.publicId,
    title: announcement.title,
    description: announcement.description,
    componentKeys: [...announcement.componentKeys],
    startsAt: announcement.startsAt,
    endsAt: announcement.endsAt,
  },
}

export const body = () => new DOMWrapper(document.body)
export async function settled(): Promise<void> {
  await flushPromises()
  await nextTick()
}

/** Real ingestion, normalized entity rows, viewport focus and tracked action handles. */
export async function worksPage(cleanups: (() => void)[], viewer = false) {
  const h = statusHarness(HilosPages.STATUS_WORKS)
  cleanups.push(h.dispose)
  if (viewer) h.viewer()
  const pages = bindPageScope(h.context.connection, h.context.scopes)
  pages.subscribe(HilosPages.STATUS_WORKS)
  const router = {
    ...h.router,
    currentPath: createSignal('/hilos/status/works'),
    pageIdentity: createSignal({
      ...h.router.pageIdentity.get()!,
      pageTabs: [
        ...h.router.pageIdentity.get()!.pageTabs!,
        { page: HilosPages.STATUS_WORKS, label: 'Scheduled maintenance' },
      ],
    }),
    resolvePath: (page: string) =>
      page === HilosPages.STATUS_WORKS
        ? '/hilos/status/works'
        : h.router.resolvePath(page),
  }
  const wrapper = mount(HilosStatusWorksPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  cleanups.push(() => wrapper.unmount())
  const answer = (payload: Record<string, unknown>) =>
    h.emit('projectSignal', {
      type: 'page_response',
      data: { page: HilosPages.STATUS_WORKS, payload },
    })
  const delta = (data: Record<string, unknown>) =>
    h.emit('tableViewportDelta', {
      data: {
        page: HilosPages.STATUS_WORKS,
        tableKey: 'hilosStatusWorks',
        ...data,
      },
    })
  let live = announcement
  await nextTick()
  return {
    ...h,
    wrapper,
    window(
      rows: readonly TableRow[] = [workRow()],
      data = worksData,
      filter = {},
    ) {
      answer({
        data: { statusWorks: data },
        windows: {
          hilosStatusWorks: {
            rows,
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field: 'startsAt', direction: 'asc' }],
            filter,
          },
        },
      })
    },
    data: (data: HilosStatusWorksData) =>
      answer({ data: { statusWorks: data } }),
    delta,
    update(patch: Partial<HilosStatusWorkRow>, own = false) {
      live = { ...live, ...patch }
      delta({ kind: 'row_updated', rowKey: '7', row: workRow(live), own })
    },
    async open(kind: 'create' | 'edit' | 'cancel') {
      const button = wrapper.get(
        kind === 'create'
          ? '[data-id="hilos-table-main-action"]'
          : `table [data-id="hilos-status-work-${kind}-7"]`,
      )
      ;(button.element as HTMLElement).focus()
      await button.trigger('click')
      await settled()
      return button
    },
  }
}

export const workForm = () => body().get('[data-id="hilos-status-work-form"]')
export const workSave = () => body().get('[data-id="hilos-status-work-save"]')
export const field = (name: string) =>
  body().get(`[data-id="hilos-status-work-${name}"]`)

const WINDOW_PARTS = [
  'Day',
  'Month',
  'Year',
  'Hours',
  'Minutes',
  'Seconds',
] as const

/** One edge's part of the works window field, named as the picker names it. */
export const windowPart = (
  edge: 'Starts' | 'Ends',
  name: (typeof WINDOW_PARTS)[number],
) =>
  body().get(
    `[data-id="hilos-status-work-window"] input[aria-label="${edge} ${name}"]`,
  )

/** The shown texts of one window edge: day, month, year, hours, minutes, seconds. */
export const windowTexts = (edge: 'Starts' | 'Ends') =>
  WINDOW_PARTS.map(
    (name) => (windowPart(edge, name).element as HTMLInputElement).value,
  )

/**
 * Press one key on a control the way a keyboard does: a bubbling, cancelable
 * keydown. A real key comes after the listeners it bubbles through were
 * attached, and Vue skips a listener attached no earlier than the event, so a
 * frozen fake clock moves one millisecond first; no timer fires.
 *
 * @param target The control that has the key.
 * @param key The key's name.
 */
export async function press(
  target: Pick<DOMWrapper<Element>, 'element'>,
  key: string,
): Promise<KeyboardEvent> {
  if (vi.isFakeTimers()) vi.setSystemTime(Date.now() + 1)
  const event = new KeyboardEvent('keydown', {
    key,
    bubbles: true,
    cancelable: true,
  })
  target.element.dispatchEvent(event)
  await flushPromises()
  return event
}

/**
 * Type one edge of the works window part by part, as a keyboard does: each
 * part is focused, cleared and typed digit by digit.
 *
 * @param edge The edge prefix the form gives the picker.
 * @param texts Day, month, year, hours, minutes and seconds, in this order.
 */
export async function typeWindowEdge(
  edge: 'Starts' | 'Ends',
  texts: readonly [string, string, string, string, string, string],
): Promise<void> {
  for (const [index, name] of WINDOW_PARTS.entries()) {
    const input = windowPart(edge, name)
    ;(input.element as HTMLInputElement).focus()
    for (const key of ['Backspace', ...texts[index]!]) await press(input, key)
  }
}

export async function fillWork(): Promise<void> {
  await field('title').setValue('  Upgrade <database>  ')
  await typeWindowEdge('Starts', ['02', '01', '2031', '12', '04', '05'])
  await typeWindowEdge('Ends', ['02', '01', '2031', '13', '05', '06'])
  await field('component-service').setValue(true)
  await field('description').setValue('  Plain text <b>only</b>  ')
}
