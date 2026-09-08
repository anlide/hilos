import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { TableViewportController } from '@hilos/core'
import type { TableRow, TableViewportDescriptor } from '@hilos/core'

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

  it('disables the step back on the first page and the step on at the last', () => {
    const { controller } = makeController()
    controller.ingestWindow(window(20), 20, true, null, null, 20)
    const wrapper = mountFooter(controller)

    expect(
      wrapper.find('[data-id="hilos-table-prev"]').attributes('disabled'),
    ).toBeDefined()
    expect(
      wrapper.find('[data-id="hilos-table-next"]').attributes('disabled'),
    ).toBeDefined()
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
