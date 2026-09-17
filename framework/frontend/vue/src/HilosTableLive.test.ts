import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import { TableViewportController } from '@hilos/core'
import type { HilosTableColumn, HilosTableProgress } from '@hilos/core'

import HilosTableLive from './HilosTableLive.vue'

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

function mountLive(controller: TableViewportController<unknown>) {
  return mount(HilosTableLive, { props: { controller, columns: COLUMNS } })
}

describe('HilosTableLive', () => {
  it('stands exactly one hidden twin with nothing, one and three messages live', async () => {
    const controller = makeController()
    const wrapper = mountLive(controller)
    expect(wrapper.findAll('[data-id="hilos-table-live-idle"]')).toHaveLength(1)

    controller.ingestAnnounce('b', 'above', 2, true)
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('[data-id="hilos-table-live-idle"]')).toHaveLength(1)

    liveThree(controller)
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('[data-id="hilos-table-live-idle"]')).toHaveLength(1)
  })

  it('keeps the twin out of sight and out of the accessibility tree', () => {
    const wrapper = mountLive(makeController())
    const twin = wrapper.get('[data-id="hilos-table-live-idle"]')

    expect(twin.classes()).toContain('invisible')
    expect(twin.attributes('aria-hidden')).toBe('true')
    // The twin holds room and takes no focus, so there is no button inside it.
    expect(twin.find('button').exists()).toBe(false)
  })

  it('lays the message out exactly as the twin, only placed over it', async () => {
    const controller = makeController()
    const wrapper = mountLive(controller)
    controller.ingestAnnounce('b', 'above', 2, true)
    await wrapper.vm.$nextTick()

    const twin = wrapper.get('[data-id="hilos-table-live-idle"]')
    const message = wrapper.get('[data-id="hilos-table-announce"]')
    expect(layoutClasses(message.classes())).toEqual(
      layoutClasses(twin.classes()),
    )
    // Out of the flow, so the message cannot add a pixel to the room.
    expect(message.classes()).toContain('position-absolute')
  })

  it('draws no line with nothing to say, while the room still stands', () => {
    const wrapper = mountLive(makeController())

    expect(wrapper.find('[data-id="hilos-table-live-slot"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('.position-absolute').exists()).toBe(false)
    expect(wrapper.get('[data-id="hilos-table-live-status"]').text()).toBe('')
  })

  it('gives the line to the senior message and the others their icons', () => {
    const controller = makeController()
    liveThree(controller)
    const wrapper = mountLive(controller)

    expect(wrapper.findAll('.position-absolute')).toHaveLength(1)
    expect(wrapper.get('[data-id="hilos-table-pending-row"]').text()).toContain(
      '1 row will move or leave',
    )
    expect(wrapper.find('[data-id="hilos-table-apply"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-table-announce"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-stale"]').exists()).toBe(false)

    const icons = wrapper.findAll('[data-id="hilos-table-live-rest"] i')
    expect(icons.map((icon) => icon.classes())).toEqual([
      ['bi', 'flex-shrink-0', 'bi-arrow-down-circle'],
      ['bi', 'flex-shrink-0', 'bi-snow'],
    ])
    expect(
      icons.every((icon) => icon.attributes('aria-hidden') === 'true'),
    ).toBe(true)
  })

  it('speaks the senior sentence and names the rest in the region that always stands', () => {
    const controller = makeController()
    liveThree(controller)
    const wrapper = mountLive(controller)

    const status = wrapper.get('[data-id="hilos-table-live-status"]')
    expect(status.attributes('role')).toBe('status')
    expect(status.text()).toBe(
      '1 row will move or leave. Also: new rows above the window, a source is behind.',
    )
  })

  it('keeps the live region off the line itself', () => {
    const controller = makeController()
    liveThree(controller)
    const wrapper = mountLive(controller)

    expect(
      wrapper
        .get('[data-id="hilos-table-pending-row"]')
        .find('[role]')
        .exists(),
    ).toBe(false)
  })

  it('names the columns built from the quiet source on the freshness line', () => {
    const controller = makeController()
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['connections'],
    })
    const wrapper = mountLive(controller)

    expect(wrapper.get('[data-id="hilos-table-stale"]').text()).toBe(
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
})
