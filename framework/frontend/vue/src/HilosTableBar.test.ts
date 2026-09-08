import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { TableViewportController } from '@hilos/core'
import type { HilosTableFrame, TableViewportDescriptor } from '@hilos/core'

import HilosModal from './HilosModal.vue'
import HilosTableBar from './HilosTableBar.vue'

const COLUMNS = [{ key: 'name', label: 'Name' }]

// The controller is typed as unknown so it lines up with the generic SFC, whose
// `R` @vue/test-utils does not infer from the prop value.
function makeController(
  frame: HilosTableFrame,
  initialFilter?: Record<string, unknown>,
): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter,
    frame,
  })

  return { controller, sent }
}

const FILTERED: HilosTableFrame = {
  title: 'Deliveries',
  search: {},
  filters: [
    { kind: 'toggle', key: 'state', label: 'Failed only', on: 'failed' },
    { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
  ],
  columns: COLUMNS,
}

function mountBar(controller: TableViewportController<unknown>) {
  return mount(HilosTableBar, {
    props: { controller, titleId: 'table-title' },
  })
}

describe('HilosTableBar', () => {
  it('shows the declared title and subtitle, the title carrying the given id', () => {
    const { controller } = makeController({
      title: 'Backups',
      subtitle: 'Every copy this installation keeps',
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    const title = wrapper.find('[data-id="hilos-table-title"]')
    expect(title.text()).toBe('Backups')
    expect(title.attributes('id')).toBe('table-title')
    expect(wrapper.find('[data-id="hilos-table-subtitle"]').text()).toBe(
      'Every copy this installation keeps',
    )
  })

  it('draws no subtitle when the declaration carries none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-subtitle"]').exists()).toBe(
      false,
    )
  })

  it('draws no search box when the table does not declare search', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-search"]').exists()).toBe(false)
  })

  it('names the search field with its declared placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: { placeholder: 'Search backups…' },
      columns: COLUMNS,
    })
    const field = mountBar(controller).find('[data-id="hilos-table-search"]')

    expect(field.attributes('placeholder')).toBe('Search backups…')
    expect(field.attributes('aria-label')).toBe('Search backups…')
  })

  it('falls back to "Search…" when the declaration names no placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const field = mountBar(controller).find('[data-id="hilos-table-search"]')

    expect(field.attributes('placeholder')).toBe('Search…')
  })

  it('sends a window on every keystroke of the search field', async () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    await wrapper.find('[data-id="hilos-table-search"]').setValue('nig')

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toMatchObject({ search: 'nig' })
  })

  it('offers the clear button only while the search holds text, and clearing drops the key', async () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)
    expect(wrapper.find('[data-id="hilos-table-search-clear"]').exists()).toBe(
      false,
    )

    await wrapper.find('[data-id="hilos-table-search"]').setValue('nig')
    await wrapper.find('[data-id="hilos-table-search-clear"]').trigger('click')

    expect(sent.at(-1)?.filter).not.toHaveProperty('search')
  })

  it('presses the declared main action exactly once per click', async () => {
    let pressed = 0
    const { controller } = makeController({
      title: 'Backups',
      mainAction: {
        label: 'Create backup',
        press: () => {
          pressed += 1
        },
      },
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    const button = wrapper.find('[data-id="hilos-table-main-action"]')
    expect(button.text()).toBe('Create backup')
    await button.trigger('click')

    expect(pressed).toBe(1)
  })

  it('draws a control per declared filter', () => {
    const { controller } = makeController(FILTERED)
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-filter-state"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-table-filter-unread"]').exists()).toBe(
      true,
    )
  })

  it('counts the filters holding a value in the badge, and never the search', async () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    const wrapper = mountBar(controller)
    expect(wrapper.find('[data-id="hilos-table-filter-badge"]').text()).toBe(
      '1 filter',
    )

    await wrapper.find('[data-id="hilos-table-search"]').setValue('nig')
    expect(wrapper.find('[data-id="hilos-table-filter-badge"]').text()).toBe(
      '1 filter',
    )

    await wrapper.find('[data-id="hilos-table-filter-unread"]').setValue(true)
    expect(wrapper.find('[data-id="hilos-table-filter-badge"]').text()).toBe(
      '2 filters',
    )
  })

  it('shows no badge while no filter holds a value', () => {
    const { controller } = makeController(FILTERED)
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-filter-badge"]').exists()).toBe(
      false,
    )
  })

  it('resets to the filters the table opened with and clears the search with them', async () => {
    const { controller, sent } = makeController(FILTERED, { state: 'failed' })
    const wrapper = mountBar(controller)
    await wrapper.find('[data-id="hilos-table-search"]').setValue('nig')
    await wrapper.find('[data-id="hilos-table-filter-unread"]').setValue(true)

    await wrapper.find('[data-id="hilos-table-filter-reset"]').trigger('click')

    expect(sent.at(-1)?.filter).toEqual({ state: 'failed' })
    expect(
      (
        wrapper.find('[data-id="hilos-table-search"]')
          .element as HTMLInputElement
      ).value,
    ).toBe('')
  })

  it('opens the filters in a modal and closes it with Done', async () => {
    const { controller } = makeController(FILTERED)
    const wrapper = mountBar(controller)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()

    await wrapper.find('[data-id="hilos-table-filters-open"]').trigger('click')
    const modal = document.querySelector('[data-id="modal"]')
    expect(modal).not.toBeNull()
    expect(
      modal?.querySelector('[data-id="hilos-table-filter-state"]'),
    ).not.toBeNull()

    const done = modal?.querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-filters-done"]',
    )
    done?.click()
    await wrapper.vm.$nextTick()

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    wrapper.unmount()
  })

  it('offers no way into the filters when the table declares none, and mounts no modal either', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-filters-open"]').exists()).toBe(
      false,
    )
    expect(wrapper.findComponent(HilosModal).exists()).toBe(false)
    wrapper.unmount()
  })

  it('draws no main action when the table declares none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-main-action"]').exists()).toBe(
      false,
    )
  })
})
