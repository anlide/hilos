import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Comment, Fragment, h, nextTick, ref } from 'vue'
import {
  TableViewportController,
  type HilosTableColumn,
  type HilosTableRowMessage,
  type TableGeometryRegistration,
  type TableViewportDescriptor,
} from '@hilos/core/base'
import HilosTableRecordCard from './HilosTableRecordCard.vue'
import HilosTableValue from './HilosTableValue.vue'
import HilosViewportTable from './HilosViewportTable.vue'
import LoadingButton from './LoadingButton.vue'
import {
  hilosTableSelectionEdgeKey,
  type HilosTableSelectionEdge,
} from './hilosTableSelectionEdge.js'

enableAutoUnmount(afterEach)
const owners: TableViewportController<unknown>[] = []
afterEach(() => {
  owners.splice(0).forEach((owner) => owner.dispose())
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  localStorage.clear()
  document.body.replaceChildren()
})

const COLUMNS: readonly HilosTableColumn[] = [
  { key: 'name', label: 'Name', heightContent: 'text' },
  { key: 'state', label: 'State', card: 'badge' },
  { key: 'secret', label: 'Secret', detail: true, detailWide: true },
  { key: 'missing', label: 'Missing', detail: true },
  { key: 'actions', label: '' },
  { key: 'note', label: 'Note', heightContent: 'text' },
  { key: 'tail', label: 'Tail' },
]
const NAME = 'A long record name\nwith another line'
const LIVE = { name: NAME, note: 'Private note', secret: 'Private detail' }

function makeController(tiles = false) {
  const sent: TableViewportDescriptor[] = []
  const owner = new TableViewportController<unknown>({
    resolve: (raw) => raw.slots,
    rowMessage: (row) =>
      (row as { message?: HilosTableRowMessage }).message ?? null,
    sendViewport: (descriptor) => sent.push(descriptor),
    frame: {
      title: 'Records',
      columns: tiles
        ? [{ key: 'picture', label: 'Picture' }, ...COLUMNS]
        : COLUMNS,
      ...(tiles
        ? {
            views: {
              tableKey: 'records',
              order: ['table', 'tiles'] as const,
              imageColumn: 'picture',
            },
          }
        : {}),
      rowHeight: { mode: 'lines', count: 2 },
      bulkActions: [
        {
          key: 'archive',
          label: 'Archive',
          refusalTitle: "Couldn't archive",
          run: () => {
            throw new Error('This test never runs a bulk action')
          },
        },
      ],
    },
  })
  owners.push(owner)
  owner.ingestWindow([{ rowKey: 'a', slots: LIVE }], 1, true, null, null, 10)
  const leases: { registration: TableGeometryRegistration; dispose(): void }[] =
    []
  const geometry = owner.geometry
  const register = vi.fn((capture: Parameters<typeof geometry.register>[0]) => {
    const registration = geometry.register(capture)
    const dispose = vi.fn(() => registration.dispose())
    leases.push({ registration, dispose })
    return { ...registration, dispose }
  })
  // Count actual registrations without replacing the controller's geometry owner.
  Object.defineProperty(owner, 'geometry', { value: { register } })
  return { owner, sent, register, leases }
}

type SlotRow = { row: unknown; rowKey: string }
function slots() {
  const name = vi.fn(({ row }: SlotRow) =>
    h('span', { class: 'record-name' }, (row as typeof LIVE).name),
  )
  const note = vi.fn(({ row }: SlotRow) => (row as typeof LIVE).note)
  const detail = vi.fn(({ row }: SlotRow) => (row as typeof LIVE).secret)
  const progress = vi.fn(
    ({ progress }: { progress: unknown; rowKey: string }) =>
      h('span', { class: 'work-caption' }, JSON.stringify(progress)),
  )
  return {
    name,
    note,
    detail,
    progress,
    render: {
      'cell-name': name,
      'cell-note': note,
      'cell-state': () => h('span', { class: 'badge' }, 'Ready'),
      'cell-tail': () => h('span', { class: 'tail' }, 'Tail value'),
      'cell-actions': ({ rowKey }: SlotRow) => [
        h(
          LoadingButton,
          {
            disabled: true,
            'aria-describedby': `action-reason-${rowKey}`,
            'data-id': 'record-action',
          },
          () => 'Run',
        ),
        h('span', { id: `action-reason-${rowKey}` }, 'Work already running'),
      ],
      'detail-secret': detail,
      'row-progress': progress,
    },
  }
}

function fixture(edge: HilosTableSelectionEdge = 'start', tiles = false) {
  const f = makeController(tiles)
  const content = slots()
  const picture = vi.fn(({ row, rowKey }: SlotRow) => {
    expect(row).toBe(f.owner.rows.get()[0]!.row)
    expect(rowKey).toBe('a')
    return h('img', {
      src: '/test/record.png',
      alt: 'Record picture',
      class: 'w-100 h-100 object-fit-cover',
    })
  })
  const wrapper = mount(HilosViewportTable, {
    props: { controller: f.owner },
    attachTo: document.body,
    slots: { ...content.render, ...(tiles ? { 'cell-picture': picture } : {}) },
    global: { provide: { [hilosTableSelectionEdgeKey as symbol]: edge } },
  })
  const renderer = () => wrapper.getComponent(HilosTableRecordCard)
  const card = () => wrapper.get('[data-id="hilos-table-card-a"]')
  return { ...f, content, wrapper, renderer, card, picture }
}

// Numeric rectangles prove DOM binding/capture, not browser wrapping or CSS layout.
function numericDOM() {
  const observed = new Set<Element>()
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe(node: Element) {
        observed.add(node)
      }
      unobserve(node: Element) {
        observed.delete(node)
      }
      disconnect() {
        observed.clear()
      }
    },
  )
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function (this: HTMLElement) {
      const id = this.dataset.id
      if (id === 'hilos-table-card-a') return new DOMRect(0, 300, 400, 260)
      if (id === 'hilos-table-tile-a') return new DOMRect(0, 300, 240, 420)
      if (id === 'hilos-table-row-detail-a')
        return new DOMRect(0, this.tagName === 'TR' ? 100 : 360, 400, 60)
      if (id === 'hilos-table-progress-row-a')
        return new DOMRect(0, this.tagName === 'TR' ? 80 : 430, 400, 24)
      if (this.classList.contains('hilos-table-work-reserve'))
        return new DOMRect(
          0,
          430,
          400,
          Number(this.style.getPropertyValue('--hilos-table-work-px')),
        )
      if (this.classList.contains('hilos-button-row'))
        return new DOMRect(0, 400, 400, 30)
      return new DOMRect(0, 0, 400, 40)
    },
  )
  return observed
}

describe('HilosTableRecordCard inside the real viewport table', () => {
  it.each(['start', 'end'] as const)(
    'shares selection, detail, text intent, marks, work and action reasons in Tiles with %s selection',
    async (edge) => {
      numericDOM()
      const f = fixture(edge, true)
      await nextTick()
      const tile = () => f.wrapper.get('[data-id="hilos-table-tile-a"]')
      const tileRenderer = () =>
        f.wrapper
          .findAllComponents(HilosTableRecordCard)
          .find(
            (renderer) =>
              (renderer.props() as { projection: string }).projection ===
              'tiles',
          )!
      const read = tileRenderer()
        .getComponent(HilosTableValue)
        .props('readRecord')
      const record = read()!
      expect(record).not.toBeNull()
      // Generic SFC props need an explicit shape at the test-utils boundary.
      const inputs = tileRenderer().props() as {
        card: unknown
        imageColumn: unknown
      }
      expect(inputs.card).toBe(f.owner.frame.tileCard.get())
      expect(inputs.imageColumn).toBe(f.owner.frame.imageColumn.get())
      expect(tile().attributes('role')).toBeUndefined()
      expect(tile().element.parentElement?.getAttribute('role')).toBe(
        'listitem',
      )
      expect(tile().classes()).toEqual(['card', 'hilos-table-tile'])
      const image = tile().get('[data-id="hilos-table-tile-image-a"]')
      expect(image.classes()).toEqual([
        'ratio',
        'ratio-4x3',
        'bg-body-tertiary',
        'rounded-top',
        'border-bottom',
      ])
      expect(image.get('img').attributes()).toMatchObject({
        src: '/test/record.png',
        alt: 'Record picture',
      })
      expect(tile().findAll('img')).toHaveLength(1)
      expect(
        image
          .find('.hilos-table-text, .hilos-table-text-window, button')
          .exists(),
      ).toBe(false)
      expect(
        tile()
          .findAll('dt')
          .map((label) => label.text()),
      ).toEqual(['Note', 'Tail'])
      expect(tile().get('.record-name').element.textContent).toBe(NAME)
      expect(
        tile().get('.record-name').element.closest('.text-break'),
      ).not.toBeNull()
      expect(
        f.wrapper.get('tr[data-id="hilos-table-row-a"]').findAll('img'),
      ).toHaveLength(1)
      await f.card().get('[data-id="hilos-table-select-a"]').setValue(true)
      await f.card().get('[data-id="hilos-table-expand-a"]').trigger('click')
      f.owner.expandText(record, 'name', true)
      f.owner.ingestProgress({
        scope: 'row',
        rowKey: 'a',
        progressKey: 'job',
        current: 1,
        total: 2,
      })
      f.owner.ingestDelta({
        kind: 'row_stale',
        rowKey: 'a',
        staleSources: ['people'],
      })
      f.owner.ingestDelta({
        kind: 'row_moved',
        rowKey: 'a',
        row: { rowKey: 'a', slots: LIVE },
        position: 0,
      })
      await nextTick()
      const occurrence = f.owner.rows.get()[0]!.occurrence
      await f.wrapper.get('[data-id="hilos-table-view-tiles"]').trigger('click')
      expect(f.owner.rows.get()[0]!.occurrence).toBe(occurrence)
      expect(
        tile().get('[data-id="hilos-table-select-a"]').element,
      ).toHaveProperty('checked', true)
      expect(
        tile()
          .get('[data-id="hilos-table-expand-a"]')
          .attributes('aria-expanded'),
      ).toBe('true')
      const detail = tile().get('[data-id="hilos-table-row-detail-a"]')
      expect(
        tile()
          .get('[data-id="hilos-table-expand-a"]')
          .attributes('aria-controls'),
      ).toBe(detail.attributes('id'))
      expect(detail.attributes('id')).not.toBe(
        f.card().get('[data-id="hilos-table-row-detail-a"]').attributes('id'),
      )
      expect(detail.text()).toContain('Private detail')
      expect(tile().find('.is-open').exists()).toBe(true)
      expect(tile().get('.is-open[id]').attributes('id')).not.toBe(
        f.card().get('.is-open[id]').attributes('id'),
      )
      expect(tile().get('[data-id="hilos-table-pending-move-a"]').text()).toBe(
        'Will move',
      )
      expect(
        tile()
          .get('[data-id="hilos-table-stale-row-a"]')
          .attributes('aria-hidden'),
      ).toBe('true')
      expect(
        tile().get('[role="progressbar"]').attributes('aria-valuenow'),
      ).toBe('50')
      expect(
        tile().get('[data-id="record-action"]').attributes(),
      ).toHaveProperty('disabled')
      expect(
        tile().get('[data-id="record-action"]').attributes('aria-describedby'),
      ).toBe('action-reason-a')
      expect(tile().get('[id="action-reason-a"]').text()).toBe(
        'Work already running',
      )
      expect(read()).toBe(record)
      expect(f.register).toHaveBeenCalledTimes(1)
      expect(f.sent).toEqual([])
    },
  )

  it.each(['absent', 'empty', 'comment', 'fragment'] as const)(
    'shows the same ratio No image fallback for an %s picture slot',
    async (kind) => {
      const f = makeController(true)
      const show = ref(false)
      const wrapper = mount({
        setup: () => () =>
          h(
            HilosViewportTable,
            { controller: f.owner },
            {
              'cell-name': () => NAME,
              ...(kind === 'absent'
                ? {}
                : {
                    'cell-picture': () =>
                      show.value
                        ? h('img', {
                            src: '/test/later.png',
                            alt: 'Later picture',
                          })
                        : kind === 'empty'
                          ? []
                          : kind === 'comment'
                            ? h(Comment, 'v-if')
                            : h(Fragment, [h(Comment)]),
                  }),
            },
          ),
      })
      const image = () => wrapper.get('[data-id="hilos-table-tile-image-a"]')
      expect(image().text()).toBe('No image')
      expect(
        image().get('.bi-file-earmark-text').attributes('aria-hidden'),
      ).toBe('true')
      expect(image().find('img').exists()).toBe(false)
      expect(
        wrapper
          .get('[data-id="hilos-table-tile-a"]')
          .find('[data-id="hilos-table-placeholder"]')
          .exists(),
      ).toBe(false)
      show.value = true
      await nextTick()
      if (kind === 'absent') expect(image().text()).toBe('No image')
      else {
        expect(image().findAll('img')).toHaveLength(1)
        expect(image().text()).toBe('')
        expect(image().classes()).toContain('ratio-4x3')
      }
    },
  )

  it('retains the column and numeric tile footprint while removing all payload from every projection', async () => {
    const observed = numericDOM()
    const f = fixture('start', true)
    const tile = () => f.wrapper.get('[data-id="hilos-table-tile-a"]')
    const column = tile().element.parentElement
    f.owner.expandRow('a', true)
    f.owner.ingestProgress({
      scope: 'row',
      rowKey: 'a',
      progressKey: 'job',
      current: 1,
      total: 2,
      detail: { secret: 'Work payload' },
    })
    await nextTick()
    expect(observed.has(tile().element)).toBe(true)
    expect(
      observed.has(tile().get('[data-id="hilos-table-row-detail-a"]').element),
    ).toBe(true)
    expect(
      observed.has(
        tile().get('[data-id="hilos-table-progress-row-a"]').element,
      ),
    ).toBe(true)
    f.owner.ingestDelta({ kind: 'row_removed', rowKey: 'a', reason: 'deleted' })
    await nextTick()
    expect(tile().find('.work-caption').exists()).toBe(false)
    expect(tile().get('.hilos-table-work-reserve').attributes('style')).toBe(
      '--hilos-table-work-px: 24;',
    )
    f.picture.mockClear()
    f.content.name.mockClear()
    f.content.note.mockClear()
    f.content.detail.mockClear()
    f.owner.apply()
    await nextTick()
    expect(tile().element.parentElement).toBe(column)
    expect(tile().get('[data-id="hilos-table-placeholder"]').text()).toBe(
      'Removed',
    )
    expect(tile().get('.hilos-table-record-space').attributes('style')).toBe(
      '--hilos-table-record-px: 420;',
    )
    for (const record of [
      tile(),
      f.card(),
      f.wrapper.get('tr[data-id="hilos-table-row-a"]'),
    ]) {
      expect(
        record
          .find(
            'img, [src], button, input, dl, [id], .hilos-table-work-reserve',
          )
          .exists(),
      ).toBe(false)
      expect(record.text()).toBe('Removed')
    }
    for (const slot of [
      f.picture,
      f.content.name,
      f.content.note,
      f.content.detail,
    ])
      expect(slot).not.toHaveBeenCalled()
    expect(f.register).toHaveBeenCalledTimes(1)
  })

  it.each(['start', 'end'] as const)(
    'keeps %s selection, page slots, marks and detail in the shared renderer',
    async (edge) => {
      const f = fixture(edge)
      expect(f.register).toHaveBeenCalledTimes(1)
      expect(f.sent).toEqual([])
      // Test-utils cannot infer props from a generic SFC selector.
      const inputs = f.renderer().props() as { view: unknown; card: unknown }
      expect(inputs.view).toBe(f.owner.rows.get()[0])
      expect(inputs.card).toBe(f.owner.frame.card.get())
      expect(f.card().classes()).toEqual(['card', 'mb-2'])
      expect(
        f.card().get('.record-name').element.closest('.fw-medium.text-break'),
      ).not.toBeNull()
      expect(f.card().get('.record-name').element.textContent).toBe(NAME)
      expect(f.card().get('.badge').text()).toBe('Ready')
      expect(
        f
          .card()
          .findAll('dt')
          .map((node) => node.text()),
      ).toEqual(['Note', 'Tail'])
      const mark = f.card().get('[data-id="hilos-table-select-a"]')
      if (edge === 'start')
        expect(mark.element.parentElement?.firstElementChild).toBe(mark.element)
      else
        expect(mark.element.parentElement?.lastElementChild).toBe(mark.element)
      await mark.setValue(true)
      expect(f.owner.selection.count.get()).toBe(1)
      expect(
        (
          f.wrapper.get('tr [data-id="hilos-table-select-a"]')
            .element as HTMLInputElement
        ).checked,
      ).toBe(true)
      await f.card().get('[data-id="hilos-table-expand-a"]').trigger('click')
      const detail = f.card().get('[data-id="hilos-table-row-detail-a"]')
      const toggle = f.card().get('[data-id="hilos-table-expand-a"]')
      expect(toggle.attributes('aria-controls')).toBe(detail.attributes('id'))
      expect(toggle.attributes('aria-expanded')).toBe('true')
      expect(detail.attributes('id')).not.toBe(
        f.wrapper
          .get('tr[data-id="hilos-table-row-detail-a"]')
          .attributes('id'),
      )
      expect(detail.findAll('dd').map((node) => node.text())).toEqual([
        'Private detail',
        '—',
      ])
      expect(detail.get('dt').classes()).toContain('col-12')
      for (const slot of [f.content.name, f.content.note, f.content.detail]) {
        expect(slot).toHaveBeenCalled()
        for (const [props] of slot.mock.calls) {
          expect(props.row).toBe(f.owner.rows.get()[0]!.row)
          expect(props.rowKey).toBe('a')
        }
      }
      f.owner.ingestDelta({
        kind: 'row_stale',
        rowKey: 'a',
        staleSources: ['people'],
      })
      f.owner.ingestDelta({
        kind: 'row_moved',
        rowKey: 'a',
        row: { rowKey: 'a', slots: LIVE },
        position: 0,
      })
      await nextTick()
      expect(f.card().classes()).toContain('border-warning')
      expect(
        f.card().get('[data-id="hilos-table-pending-move-a"]').text(),
      ).toBe('Will move')
      expect(
        f
          .card()
          .get('[data-id="hilos-table-stale-row-a"]')
          .attributes('aria-hidden'),
      ).toBe('true')
      expect(
        f
          .card()
          .get('.hilos-button-row [data-id="record-action"]')
          .attributes(),
      ).toHaveProperty('disabled')
      expect(
        f
          .card()
          .get('[data-id="record-action"]')
          .attributes('aria-describedby'),
      ).toBe('action-reason-a')
      expect(f.card().get('[id="action-reason-a"]').text()).toBe(
        'Work already running',
      )
      expect(f.register).toHaveBeenCalledTimes(1)
      expect(f.sent).toEqual([])
    },
  )

  it('keeps the merged message in place of the tail fields after a live update', async () => {
    const f = fixture()
    const renderer = f.renderer().element
    f.owner.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: {
        rowKey: 'a',
        slots: {
          ...LIVE,
          message: { fromColumn: 'note', text: 'Uses default values' },
        },
      },
    })
    await nextTick()
    expect(f.renderer().element).toBe(renderer)
    expect(f.card().findAll('dt')).toHaveLength(0)
    expect(f.card().find('.tail').exists()).toBe(false)
    expect(f.card().get('[data-id="hilos-table-row-message"]').text()).toBe(
      'Uses default values',
    )
    expect(
      f.wrapper
        .get('tr [data-id="hilos-table-row-message"]')
        .attributes('colspan'),
    ).toBe('2')
  })

  it('binds main/detail/work DOM to the parent and retains only numeric space on departure', async () => {
    const observed = numericDOM()
    const f = fixture()
    f.owner.expandRow('a', true)
    f.owner.ingestProgress({
      scope: 'row',
      rowKey: 'a',
      progressKey: 'job',
      current: 1,
      total: 2,
      detail: { private: 'Work payload' },
    })
    await nextTick()
    expect(observed.has(f.card().element)).toBe(true)
    expect(
      observed.has(
        f.card().get('[data-id="hilos-table-row-detail-a"]').element,
      ),
    ).toBe(true)
    expect(
      observed.has(
        f.card().get('[data-id="hilos-table-progress-row-a"]').element,
      ),
    ).toBe(true)
    const bar = f.owner.progress.rows.get().get('a')!
    expect(f.content.progress).toHaveBeenCalledWith(
      expect.objectContaining({ progress: bar, rowKey: 'a' }),
    )
    expect(
      f.card().get('[role="progressbar"]').attributes('aria-valuenow'),
    ).toBe('50')
    f.owner.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'left_set',
    })
    await nextTick()
    const reserve = f.card().get('.hilos-table-work-reserve')
    expect(reserve.attributes('style')).toBe('--hilos-table-work-px: 24;')
    expect(reserve.text()).toBe('')
    expect(reserve.attributes()).toHaveProperty('inert')
    expect(reserve.find('button, input, [data-id], img').exists()).toBe(false)
    expect(f.card().find('.work-caption').exists()).toBe(false)
    f.content.name.mockClear()
    f.content.note.mockClear()
    f.content.detail.mockClear()
    f.content.progress.mockClear()
    f.owner.apply()
    await nextTick()
    expect(f.card().get('.hilos-table-record-space').attributes('style')).toBe(
      '--hilos-table-record-px: 260;',
    )
    expect(f.card().get('[data-id="hilos-table-placeholder"]').text()).toBe(
      'No longer in this list',
    )
    expect(
      f
        .card()
        .find(
          'button, input, dl, img, [id], .work-caption, .hilos-table-work-reserve',
        )
        .exists(),
    ).toBe(false)
    for (const text of [NAME, 'Private note', 'Private detail', 'Work payload'])
      expect(f.card().text()).not.toContain(text)
    for (const slot of [
      f.content.name,
      f.content.note,
      f.content.detail,
      f.content.progress,
    ])
      expect(slot).not.toHaveBeenCalled()
  })

  it.each(['replacement', 'unmount'] as const)(
    'releases exact card readers and the one registration on %s',
    async (action) => {
      numericDOM()
      const f = fixture()
      const values = f.renderer().findAllComponents(HilosTableValue)
      const read = values[0]!.props('readRecord')
      const record = read()!
      expect(record).not.toBeNull()
      expect(values[1]!.props('readRecord')()).toBe(record)
      f.owner.expandText(record, 'name', true)
      await nextTick()
      expect(f.card().find('.is-open').exists()).toBe(true)
      if (action === 'replacement') {
        const next = makeController()
        await f.wrapper.setProps({ controller: next.owner })
        expect(next.register).toHaveBeenCalledTimes(1)
        const fresh = f
          .renderer()
          .findComponent(HilosTableValue)
          .props('readRecord')()
        expect(fresh).not.toBe(record)
        expect(f.card().find('.is-open').exists()).toBe(false)
        f.owner.ingestDelta({
          kind: 'row_updated',
          rowKey: 'a',
          row: { rowKey: 'a', slots: { name: 'Obsolete' } },
        })
        await nextTick()
        expect(f.card().text()).not.toContain('Obsolete')
        f.wrapper.unmount()
        expect(next.leases[0]!.dispose).toHaveBeenCalledTimes(1)
      } else f.wrapper.unmount()
      expect(read()).toBeNull()
      expect(f.leases[0]!.dispose).toHaveBeenCalledTimes(1)
      expect(f.register).toHaveBeenCalledTimes(1)
    },
  )

  it('follows slots that are added and removed without changing the card layout', async () => {
    const f = makeController()
    const show = ref(false)
    const wrapper = mount({
      setup: () => () =>
        h(
          HilosViewportTable,
          { controller: f.owner },
          {
            'cell-name': () => 'Name',
            ...(show.value ? { 'cell-note': () => 'Now present' } : {}),
          },
        ),
    })
    const card = () => wrapper.get('[data-id="hilos-table-card-a"]')
    expect(card().find('dt').exists()).toBe(false)
    show.value = true
    await nextTick()
    expect(card().get('dt').text()).toBe('Note')
    expect(card().get('dd .hilos-table-text').text()).toBe('Now present')
    show.value = false
    await nextTick()
    expect(card().find('dt').exists()).toBe(false)
    expect(f.register).toHaveBeenCalledTimes(1)
  })
})
