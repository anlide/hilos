import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { TableViewportController } from '@hilos/core/base'
import type { TableRow, TableViewportDescriptor } from '@hilos/core/base'

import HilosTableFooter from './HilosTableFooter.vue'

// The footer says nothing about the declaration, but a table only draws one
// when it has a frame — so the tests give it the smallest one there is.
const FRAME = { title: 'Backups', columns: [{ key: 'name', label: 'Name' }] }

function makeController(): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    frame: FRAME,
  })

  return { controller, sent }
}

function window(size: number): TableRow[] {
  return Array.from({ length: size }, (_unused, index) => ({
    rowKey: `row-${index}`,
    slots: { name: `Row ${index}` },
  }))
}

// Paging asks the server for the rows after the window's last one, so a window
// that carries no boundary cannot be paged from at all: these tests hand over
// the anchors a real window arrives with.
function ingestPage(
  controller: TableViewportController<unknown>,
  totalCount: number,
): void {
  const rows = window(20)
  controller.ingestWindow(
    rows,
    totalCount,
    true,
    { rowKey: rows[0]?.rowKey },
    { rowKey: rows[rows.length - 1]?.rowKey },
    20,
  )
}

function mountFooter(controller: TableViewportController<unknown>) {
  return mount(HilosTableFooter, { props: { controller } })
}

describe('HilosTableFooter', () => {
  it('prints the range on screen and an exact total', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 128, true, null, null, 20)
    const wrapper = mountFooter(controller)

    expect(wrapper.find('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 20 of 128',
    )
  })

  it('marks a total that stopped at its ceiling with a trailing plus', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 500, false, null, null, 20)
    const wrapper = mountFooter(controller)

    expect(wrapper.find('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 20 of 500+',
    )
  })

  it('counts the range from the page the window sits on', async () => {
    const { controller } = makeController()
    ingestPage(controller, 128)
    const wrapper = mountFooter(controller)

    await wrapper.find('[data-id="hilos-table-next"]').trigger('click')
    ingestPage(controller, 128)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-table-count"]').text()).toBe(
      '21 – 40 of 128',
    )
  })

  it('draws nothing at all on an empty window', () => {
    const { controller } = makeController()
    controller.ingestWindow([], 0, true, null, null, 20)
    const wrapper = mountFooter(controller)

    expect(wrapper.find('[data-id="hilos-table-count"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-prev"]').exists()).toBe(false)
  })

  it.each(['before', 'after'] as const)(
    'keeps the footer when zero arrives %s Apply, until a fresh empty window',
    async (countTiming) => {
      const { controller } = makeController()
      controller.ingestWindow(window(1), 1, true, null, null, 20)
      const wrapper = mountFooter(controller)
      controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'row-0',
        reason: 'deleted',
      })
      if (countTiming === 'before') controller.ingestCount(0, true)
      await wrapper.vm.$nextTick()
      expect(wrapper.find('[data-id="hilos-table-count"]').text()).toBe(
        countTiming === 'before' ? '0 – 0 of 0' : '1 – 1 of 1',
      )

      controller.apply()
      await wrapper.vm.$nextTick()
      expect(wrapper.find('[data-id="hilos-table-count"]').text()).toBe(
        countTiming === 'before' ? '0 – 0 of 0' : '0 – 0 of 1',
      )
      controller.ingestCount(0, true)
      await wrapper.vm.$nextTick()
      expect(wrapper.find('[data-id="hilos-table-count"]').text()).toBe(
        '0 – 0 of 0',
      )

      controller.ingestWindow([], 0, true, null, null, 20)
      await wrapper.vm.$nextTick()
      expect(wrapper.find('[data-id="hilos-table-count"]').exists()).toBe(false)
      wrapper.unmount()
    },
  )

  it('draws nothing at all when the window was refused', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 128, true, null, null, 20)
    controller.ingestRefusal('internal_error')
    const wrapper = mountFooter(controller)

    expect(wrapper.find('[data-id="hilos-table-count"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-prev"]').exists()).toBe(false)
  })

  it('disables the step back on the first page and the step on at the last', async () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 21, true, null, null, 20)
    const wrapper = mountFooter(controller)

    expect(
      wrapper.find('[data-id="hilos-table-prev"]').attributes('disabled'),
    ).toBeDefined()
    expect(
      wrapper.find('[data-id="hilos-table-next"]').attributes('disabled'),
    ).toBeUndefined()

    controller.setPage(1)
    controller.ingestWindow(window(1), 21, true, null, null, 20)
    await wrapper.vm.$nextTick()

    expect(
      wrapper.find('[data-id="hilos-table-next"]').attributes('disabled'),
    ).toBeDefined()
  })

  it('draws no pager when the whole set fits on one page', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(2), 2, true, null, null, 20)
    const wrapper = mountFooter(controller)

    expect(wrapper.find('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 2 of 2',
    )
    expect(wrapper.find('[data-id="hilos-table-prev"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-next"]').exists()).toBe(false)
    expect(wrapper.find('[data-id^="hilos-table-page-"]').exists()).toBe(false)
  })

  it('offers page numbers exactly while the count is exact', () => {
    const { controller } = makeController()
    ingestPage(controller, 128) // 7 pages of 20
    const wrapper = mountFooter(controller)

    expect(
      wrapper
        .findAll('[data-id^="hilos-table-page-"]')
        .map((page) => page.text()),
    ).toEqual(['1', '2', '3', '4', '5', '6', '7'])
  })

  it('offers no page numbers while the count stands at its ceiling', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 500, false, null, null, 20)
    const wrapper = mountFooter(controller)

    expect(wrapper.find('[data-id^="hilos-table-page-"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-prev"]').text()).toBe('Previous')
    expect(wrapper.find('[data-id="hilos-table-next"]').text()).toBe('Next')
  })

  it('jumps to the page whose number was pressed', async () => {
    const { controller, sent } = makeController()
    ingestPage(controller, 128)
    const wrapper = mountFooter(controller)

    await wrapper.find('[data-id="hilos-table-page-4"]').trigger('click')

    expect(sent.at(-1)).toMatchObject({ pageIndex: 3, anchor: null })
  })

  it('states the page the reader stands on rather than offering it again', () => {
    const { controller } = makeController()
    ingestPage(controller, 128)
    const wrapper = mountFooter(controller)
    const current = wrapper.find('[data-id="hilos-table-page-1"]')

    expect(current.attributes('disabled')).toBeDefined()
    expect(current.attributes('aria-current')).toBe('page')
  })

  it('passes over the pages between the ends when there are too many to draw', async () => {
    const { controller } = makeController()
    // 400 rows of 20 make 20 pages — more than the pager has room for.
    ingestPage(controller, 400)
    const wrapper = mountFooter(controller)

    controller.setPage(9) // the tenth page, with ends far on either side
    await wrapper.vm.$nextTick()

    const numbers = wrapper
      .findAll('[data-id^="hilos-table-page-"]')
      .map((page) => page.text())
    expect(numbers.length).toBeLessThanOrEqual(5)
    expect(numbers.at(0)).toBe('1')
    expect(numbers.at(-1)).toBe('20')
    expect(wrapper.findAll('[aria-hidden="true"].disabled')).toHaveLength(2)
  })

  it('leaves the steps their words only where no numbers stand beside them', () => {
    const { controller } = makeController()
    ingestPage(controller, 128)
    const wrapper = mountFooter(controller)
    const previous = wrapper.find('[data-id="hilos-table-prev"]')

    expect(previous.text()).toBe('')
    expect(previous.attributes('aria-label')).toBe('Previous page')
    expect(
      wrapper.find('[data-id="hilos-table-next"]').attributes('aria-label'),
    ).toBe('Next page')
  })

  it('steps the window on and back through the controller', async () => {
    const { controller, sent } = makeController()
    ingestPage(controller, 128)
    const wrapper = mountFooter(controller)

    await wrapper.find('[data-id="hilos-table-next"]').trigger('click')
    expect(sent).toHaveLength(1)

    ingestPage(controller, 128)
    await wrapper.vm.$nextTick()
    await wrapper.find('[data-id="hilos-table-prev"]').trigger('click')

    expect(sent).toHaveLength(2)
  })
})
