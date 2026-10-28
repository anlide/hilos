import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, shallowRef, onErrorCaptured } from 'vue'
import {
  HIDDEN_VALUE,
  TableViewportController,
  type Hideable,
  type HilosTableRowHeight,
  type TableGeometryRecord,
  type TableProjection,
} from '@hilos/core/base'
import HilosTableText from './HilosTableText.vue'
import HilosTableValue from './HilosTableValue.vue'

type FixtureProjection = Extract<TableProjection, 'wide' | 'card'>

const mounted: { unmount(): void }[] = []
const owners: { dispose(): void }[] = []
afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
  for (const owner of owners.splice(0)) owner.dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.replaceChildren()
})

function owner(policy: HilosTableRowHeight = { mode: 'lines', count: 2 }) {
  const controller = new TableViewportController<unknown>({
    resolve: (row) => row.slots,
    sendViewport: () => {},
    frame: {
      columns: [
        { key: 'name', label: 'Name', heightContent: 'parts' },
        { key: 'other', label: 'Other', heightContent: 'parts' },
      ],
      rowHeight: policy,
    },
  })
  controller.ingestWindow(
    [{ rowKey: 'a', slots: { name: 'Full live text' } }],
    1,
    true,
    null,
    null,
    10,
  )
  const registration = controller.geometry.register(() => ({ records: [] }))
  owners.push(registration, controller)
  return {
    controller,
    registration,
    record: registration.measurement('a', 'wide')!.record,
  }
}

// Synthetic boxes prove wiring/focus, not CSS wrapping. Browser proof is slice 7.
function layout() {
  const frames = new Map<number, FrameRequestCallback>()
  const history: FrameRequestCallback[] = []
  let serial = 0
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    history.push(callback)
    frames.set(++serial, callback)
    return serial
  })
  vi.stubGlobal('cancelAnimationFrame', (id: number) => frames.delete(id))
  const heights: Record<FixtureProjection, number> = { wide: 40, card: 80 }
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function () {
      return new DOMRect(0, 0, 200, 40)
    },
  )
  for (const property of [
    'clientWidth',
    'clientHeight',
    'scrollWidth',
    'scrollHeight',
  ] as const)
    vi.spyOn(HTMLElement.prototype, property, 'get').mockImplementation(
      function (this: HTMLElement) {
        if (property === 'clientHeight') return 40
        if (property === 'scrollHeight')
          return heights[
            (this.closest('[data-projection]')?.getAttribute(
              'data-projection',
            ) as FixtureProjection) ?? 'wide'
          ]
        return 200
      },
    )
  return {
    heights,
    history,
    frames,
    async flush() {
      await nextTick()
      const pending = [...frames.values()]
      frames.clear()
      for (const callback of pending) callback(0)
      await nextTick()
    },
  }
}

function fixture(
  options: {
    policy?: HilosTableRowHeight
    projections?: FixtureProjection[]
    regions?: number
    columns?: string[]
  } = {},
) {
  const initial = owner(options.policy)
  const controller = shallowRef(initial.controller)
  const record = shallowRef<TableGeometryRecord | null>(initial.record)
  const value = shallowRef<Hideable<string>>('Full live text')
  const count = shallowRef(options.regions ?? 1)
  const present = shallowRef(true)
  const errors: unknown[] = []
  const action = vi.fn()
  let current = true
  const App = defineComponent({
    setup: () => {
      onErrorCaptured((error) => {
        errors.push(error)
        return false
      })
      return () =>
        h(
          'main',
          { onClick: action },
          present.value
            ? (options.projections ?? ['wide', 'card']).flatMap((projection) =>
                (options.columns ?? ['name']).map((columnKey) =>
                  h(
                    'section',
                    { 'data-projection': projection, 'data-column': columnKey },
                    [
                      h(
                        HilosTableValue,
                        {
                          controller: controller.value,
                          readRecord: () => (current ? record.value : null),
                          columnKey,
                          label: columnKey === 'name' ? 'Name' : 'Other',
                          heightContent: 'parts',
                          projection,
                        },
                        {
                          default: () => [
                            h('span', { class: 'badge' }, 'Ready'),
                            ...Array.from({ length: count.value }, (_, key) =>
                              h(HilosTableText, { key, value: value.value }),
                            ),
                            h('a', { href: '/next' }, 'Link'),
                            h(
                              'button',
                              { 'data-id': 'own-action', type: 'button' },
                              'Act',
                            ),
                          ],
                        },
                      ),
                    ],
                  ),
                ),
              )
            : [],
        )
    },
  })
  const view = mount(App, {
    attachTo: document.body,
    global: {
      config: {
        errorHandler: (error) => {
          errors.push(error)
        },
      },
    },
  })
  mounted.push(view)
  return {
    view,
    controller,
    record,
    value,
    count,
    present,
    errors,
    action,
    retireBeforeRender: () => {
      current = false
    },
  }
}

describe('HilosTableText', () => {
  it('requires a parts context', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    expect(() => mount(HilosTableText, { props: { value: 'Text' } })).toThrow(
      'parts context',
    )
    const initial = owner()
    expect(() =>
      mount(HilosTableValue, {
        props: {
          controller: initial.controller,
          readRecord: () => initial.record,
          columnKey: 'name',
          label: 'Name',
          projection: 'wide',
          heightContent: 'text',
        },
        slots: { default: () => h(HilosTableText, { value: 'Text' }) },
      }),
    ).toThrow('parts context')
  })

  it.each([0, 2])(
    'rejects %s main regions in limited parts',
    async (regions) => {
      const state = fixture({ regions, projections: ['wide'] })
      await nextTick()
      expect(
        state.errors.some(
          (error) =>
            error instanceof TypeError && error.message.includes('exactly one'),
        ),
      ).toBe(true)
      expect(state.view.find('.hilos-table-text-lines-2').exists()).toBe(false)
    },
  )

  it('cleans region registrations and validates nested live mounts/removals', async () => {
    const state = fixture({ projections: ['wide'] })
    await nextTick()
    expect(state.errors).toEqual([])
    state.count.value = 2
    await nextTick()
    expect(state.errors).not.toEqual([])
    state.errors.length = 0
    state.count.value = 1
    await nextTick()
    expect(state.errors).toEqual([])
    expect(state.view.findAll('.hilos-table-text-lines-2')).toHaveLength(1)
    state.present.value = false
    await nextTick()
    expect(state.errors).toEqual([])
    state.present.value = true
    await nextTick()
    expect(state.errors).toEqual([])
  })

  it('counts regions mounted by a child that updates independently of the wrapper', async () => {
    const initial = owner()
    const count = shallowRef(1)
    const errors: unknown[] = []
    const Nested = () =>
      Array.from({ length: count.value }, (_, key) =>
        h(HilosTableText, { key, value: 'Nested value' }),
      )
    const view = mount(HilosTableValue, {
      props: {
        controller: initial.controller,
        readRecord: () => initial.record,
        columnKey: 'name',
        label: 'Name',
        heightContent: 'parts',
        projection: 'wide',
      },
      slots: { default: () => h(Nested) },
      global: {
        config: {
          errorHandler: (error) => {
            errors.push(error)
          },
        },
      },
      attachTo: document.body,
    })
    mounted.push(view)
    await nextTick()
    expect(errors).toEqual([])
    count.value = 0
    await nextTick()
    expect(errors).toHaveLength(1)
    expect(String(errors[0])).toContain('exactly one')
    count.value = 1
    await nextTick()
    expect(errors).toHaveLength(1)
    expect(view.findAll('.hilos-table-text-lines-2')).toHaveLength(1)
  })

  it.each([0, 1, 2])(
    'does not impose the limited-region count on content parts (%s)',
    async (regions) => {
      const state = fixture({ policy: { mode: 'content' }, regions })
      await nextTick()
      expect(state.errors).toEqual([])
      expect(state.view.find('.invisible').exists()).toBe(false)
      expect(
        state.view.find('[data-id="hilos-table-text-toggle"]').exists(),
      ).toBe(false)
      expect(state.view.findAll('.hilos-table-text')).toHaveLength(regions * 2)
    },
  )

  it('limits only the main value and keeps labels, badges, links and controls outside', async () => {
    const dom = layout()
    const state = fixture({ policy: { mode: 'height', rem: 4.5 } })
    await dom.flush()
    expect(state.errors).toEqual([])
    const texts = state.view.findAll('.hilos-table-text-height-4-5')
    expect(texts).toHaveLength(2)
    for (const text of texts) {
      expect(text.text()).toBe('Full live text')
      expect(text.find('a, button, .badge').exists()).toBe(false)
      expect(text.attributes('aria-hidden')).toBeUndefined()
    }
    expect(state.view.findAll('a')).toHaveLength(2)
    expect(state.view.findAll('[data-id="own-action"]')).toHaveLength(2)
    const control = state.view.get('[data-id="hilos-table-text-toggle"]')
    expect(control.attributes('type')).toBe('button')
    expect(control.attributes('aria-label')).toBe('Show in full: Name')
    expect(control.attributes('aria-expanded')).toBe('false')
    expect(document.getElementById(control.attributes('aria-controls')!)).toBe(
      texts[1].element,
    )
    expect(texts[0].attributes('id')).not.toBe(texts[1].attributes('id'))
    const holder = state.view.get('.invisible')
    expect(holder.attributes('inert')).toBe('')
    expect(holder.attributes('aria-hidden')).toBe('true')
    expect(holder.findAll('button, [id], [data-id], [tabindex]')).toHaveLength(
      0,
    )
    expect(holder.get('.btn').classes()).toEqual(control.classes())
  })

  it('shares intent across projections, keeps overflow separate and isolates columns and tables', async () => {
    const dom = layout()
    const state = fixture({ columns: ['name', 'other'] })
    const otherTable = fixture()
    await dom.flush()
    const ids = [...document.querySelectorAll('.hilos-table-text')].map(
      (node) => node.id,
    )
    // Separate Vue apps can configure app.config.idPrefix; within each app every
    // projection/column has a distinct useId. The table fixture shares one app.
    expect(
      new Set(
        state.view
          .findAll('.hilos-table-text')
          .map((node) => node.attributes('id')),
      ).size,
    ).toBe(4)
    expect(ids).toHaveLength(6)
    const button = state.view.get(
      '[data-projection="card"][data-column="name"] button[data-id="hilos-table-text-toggle"]',
    )
    const command = vi.spyOn(state.controller.value, 'expandText')
    await button.trigger('click')
    await dom.flush()
    expect(command).toHaveBeenCalledExactlyOnceWith(
      state.record.value,
      'name',
      true,
    )
    expect(state.view.findAll('[data-column="name"] .is-open')).toHaveLength(2)
    expect(state.view.findAll('[data-column="other"] .is-open')).toHaveLength(0)
    expect(otherTable.view.find('.is-open').exists()).toBe(false)
    expect(state.action).not.toHaveBeenCalled()
  })

  it('keeps the same focused button through shortening/collapse until blur without moving focus', async () => {
    const dom = layout()
    const state = fixture()
    await dom.flush()
    const selector =
      '[data-projection="card"] [data-id="hilos-table-text-toggle"]'
    const button = state.view.get(selector)
    const element = button.element as HTMLButtonElement
    element.focus()
    await button.trigger('click')
    await dom.flush()
    state.value.value = 'Short'
    dom.heights.card = 40
    await dom.flush()
    expect(state.view.get(selector).element).toBe(element)
    expect(button.text()).toBe('Collapse')
    expect(button.attributes('aria-label')).toBe('Collapse: Name')
    await button.trigger('click')
    await dom.flush()
    expect(button.text()).toBe('Show in full')
    expect(button.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(element)
    expect(state.view.get(selector).element).toBe(element)
    element.blur()
    await nextTick()
    expect(state.view.find(selector).exists()).toBe(false)
  })

  it('uses the hidden mark outside clipping with reserve, no disclosure or string extraction', async () => {
    const dom = layout()
    const state = fixture()
    const hidden = Object.freeze({
      _hidden: true as const,
      toString() {
        throw new Error('Hidden was stringified')
      },
    })
    // isHiddenValue accepts exactly the wire key; a non-enumerable trap detects
    // accidental display conversion without changing that boundary shape.
    const trapped = { _hidden: true as const }
    Object.defineProperty(trapped, 'toString', { value: hidden.toString })
    state.value.value = trapped
    await dom.flush()
    expect(state.view.findAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    expect(
      state.view.find('[data-id="hilos-table-text-toggle"]').exists(),
    ).toBe(false)
    for (const mark of state.view.findAll('[data-id="hilos-hidden"]'))
      expect(mark.element.closest('.hilos-table-text')).toBeNull()
    for (const reserve of state.view.findAll('.hilos-table-text-lines-2')) {
      expect(reserve.text()).toBe('')
      expect(reserve.classes()).toContain('is-open')
      expect(reserve.attributes('id')).toBeUndefined()
    }
    expect(state.view.text()).not.toContain('_hidden')
    state.value.value = ''
    await dom.flush()
    expect(state.view.find('[data-id="hilos-hidden"]').exists()).toBe(false)
    expect(state.view.findAll('.hilos-table-text-lines-2')).toHaveLength(2)
    expect(state.view.findAll('.invisible')).toHaveLength(2)
    state.value.value = HIDDEN_VALUE
    await dom.flush()
    expect(state.view.findAll('[data-id="hilos-hidden"]')).toHaveLength(2)
  })

  it('rebinds controller/exact occurrence and drops old frames and intent on teardown', async () => {
    const dom = layout()
    const state = fixture()
    await dom.flush()
    await state.view.get('[data-id="hilos-table-text-toggle"]').trigger('click')
    const oldController = state.controller.value
    const oldRecord = state.record.value!
    const oldFrames = [...dom.history]
    const replacement = owner()
    state.controller.value = replacement.controller
    state.record.value = replacement.record
    await dom.flush()
    expect(state.view.find('.is-open').exists()).toBe(false)
    for (const callback of oldFrames) callback(0)
    oldController.expandText(oldRecord, 'name', false)
    oldController.expandText(oldRecord, 'name', true)
    await dom.flush()
    expect(state.view.find('.is-open').exists()).toBe(false)
    state.record.value = null
    await dom.flush()
    expect(
      state.view.find('[data-id="hilos-table-text-toggle"]').exists(),
    ).toBe(false)
    state.view.unmount()
    for (const callback of dom.history) expect(() => callback(0)).not.toThrow()
    expect(dom.frames.size).toBe(0)
  })

  it('checks the live geometry reader before a command or late callback, ahead of render', async () => {
    const dom = layout()
    const state = fixture()
    await dom.flush()
    const button = state.view.get('[data-id="hilos-table-text-toggle"]')
    const command = vi.spyOn(state.controller.value, 'expandText')
    state.retireBeforeRender()
    for (const callback of dom.history) callback(0)
    await button.trigger('click')
    expect(command).not.toHaveBeenCalled()
    expect(state.controller.value.expandedText.get().size).toBe(0)
    state.record.value = null
    await dom.flush()
    expect(
      state.view.find('[data-id="hilos-table-text-toggle"]').exists(),
    ).toBe(false)
  })

  it('does not revive a focus hold when hidden text becomes a short visible value', async () => {
    const dom = layout()
    const state = fixture()
    await dom.flush()
    const button = state.view.get('[data-id="hilos-table-text-toggle"]')
    ;(button.element as HTMLButtonElement).focus()
    await nextTick()
    state.value.value = HIDDEN_VALUE
    await dom.flush()
    state.value.value = 'Short'
    dom.heights.card = 40
    await dom.flush()
    expect(
      state.view.find('[data-id="hilos-table-text-toggle"]').exists(),
    ).toBe(false)
  })
})
