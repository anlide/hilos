import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { ActionError, TableViewportController, createSignal } from '@hilos/core'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableBulkAction,
  HilosTableFrame,
  HilosTableSelectionTarget,
  TableViewportDescriptor,
} from '@hilos/core'

import HilosTableSelection from './HilosTableSelection.vue'

// The confirmation teleports to <body>, so assertions about it query the
// document and not the wrapper.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

const COLUMNS = [{ key: 'name', label: 'Name' }]

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

// The controller is typed as unknown so it lines up with the generic SFC, whose
// `R` @vue/test-utils does not infer from the prop value.
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

function mountPanel(
  controller: TableViewportController<unknown>,
  shown = true,
) {
  return mount(HilosTableSelection, {
    props: { controller, shown },
  })
}

describe('HilosTableSelection', () => {
  it('counts the marked rows of this page, and says the condition in words', async () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    const wrapper = mountPanel(controller)

    expect(wrapper.find('[data-id="hilos-table-selection-count"]').text()).toBe(
      '1 marked on this page',
    )
    expect(
      wrapper.find('[data-id="hilos-table-select-all-filtered"]').exists(),
    ).toBe(true)

    controller.selectAllByFilter()
    await wrapper.vm.$nextTick()

    // The size of a large set is a ceiling rather than a count, so the panel says
    // what it is sure of; and the button that took the condition is gone, because
    // pressing it again would change nothing.
    expect(wrapper.find('[data-id="hilos-table-selection-count"]').text()).toBe(
      'All rows matching the filter',
    )
    expect(
      wrapper.find('[data-id="hilos-table-select-all-filtered"]').exists(),
    ).toBe(false)
  })

  it('drops the marks through the core', async () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    const wrapper = mountPanel(controller)

    await wrapper
      .find('[data-id="hilos-table-selection-clear"]')
      .trigger('click')

    expect(controller.selection.count.get()).toBe(0)
  })

  it('disables the declared operations while a run is going', async () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    const wrapper = mountPanel(controller)
    const button = wrapper.find('[data-id="hilos-table-bulk-delete"]')

    expect(button.attributes('disabled')).toBeUndefined()

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-1',
      current: 1,
      total: 4,
    })
    await wrapper.vm.$nextTick()

    expect(
      wrapper
        .find('[data-id="hilos-table-bulk-delete"]')
        .attributes('disabled'),
    ).toBeDefined()
  })

  it('confirms every operation and sends the target the core gives at that moment', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked)])
    controller.selectRow('a', true)
    const wrapper = mountPanel(controller)

    await wrapper.find('[data-id="hilos-table-bulk-delete"]').trigger('click')
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.body.textContent).toContain(
      '1 row on this page will be affected',
    )
    expect(asked).toHaveLength(0)

    // Between the press and the confirmation the reader marked one more row: the
    // target is read HERE, so the run takes both.
    controller.selectRow('b', true)
    await wrapper.vm.$nextTick()

    const confirm = document.querySelector<HTMLElement>(
      '[data-id="hilos-table-bulk-confirm"]',
    )
    confirm?.click()
    await flushPromises()

    expect(asked).toEqual([{ kind: 'rows', rowKeys: ['a', 'b'] }])
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('keeps the confirmation open with the refusal it was answered with', async () => {
    const asked: HilosTableSelectionTarget[] = []
    const controller = makeController([deleteAction(asked, refused)])
    controller.selectRow('a', true)
    const wrapper = mountPanel(controller)

    await wrapper.find('[data-id="hilos-table-bulk-delete"]').trigger('click')
    document
      .querySelector<HTMLElement>('[data-id="hilos-table-bulk-confirm"]')
      ?.click()
    await flushPromises()

    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.body.textContent).toContain('The node is frozen')
  })

  it('keeps an open confirmation when the panel stops standing', async () => {
    const controller = makeController([deleteAction([])])
    controller.selectRow('a', true)
    const wrapper = mountPanel(controller)

    await wrapper.find('[data-id="hilos-table-bulk-delete"]').trigger('click')
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()

    await wrapper.setProps({ shown: false })

    const panel = wrapper.find('[data-id="hilos-table-selection"]')
    expect(panel.classes()).toContain('invisible')
    expect(panel.attributes('aria-hidden')).toBe('true')
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
  })

  it('names itself to a screen reader as one group', () => {
    const controller = makeController([deleteAction([])])
    const wrapper = mountPanel(controller)

    const panel = wrapper.find('[data-id="hilos-table-selection"]')
    expect(panel.attributes('role')).toBe('group')
    expect(panel.attributes('aria-label')).toBe('Selection')
  })
})
