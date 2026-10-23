import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  defineComponent,
  h,
  nextTick,
  onMounted,
  shallowRef,
  type VNodeChild,
} from 'vue'
import {
  HIDDEN_VALUE,
  TableViewportController,
  type HilosTableRowHeight,
} from '@hilos/core/base'
import HilosTableValue from './HilosTableValue.vue'
import HilosHideable from './HilosHideable.vue'

const mounted: { unmount(): void }[] = []
const owners: { dispose(): void }[] = []
afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
  for (const owner of owners.splice(0)) owner.dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.replaceChildren()
})

function fixture(
  slot: () => VNodeChild,
  policy: HilosTableRowHeight = { mode: 'lines', count: 2 },
  heightContent: 'text' | null = 'text',
) {
  const controller = new TableViewportController<unknown>({
    resolve: (row) => row.slots,
    sendViewport: () => {},
    frame: {
      columns: [{ key: 'name', label: 'Name', heightContent: 'text' }],
      rowHeight: policy,
    },
  })
  controller.ingestWindow(
    [{ rowKey: 'a', slots: { name: 'Live' } }],
    1,
    true,
    null,
    null,
    10,
  )
  const registration = controller.geometry.register(() => ({ records: [] }))
  owners.push(registration, controller)
  const view = mount(HilosTableValue, {
    props: {
      controller,
      readRecord: () => registration.measurement('a', 'wide')?.record ?? null,
      columnKey: 'name',
      label: 'Name',
      heightContent: heightContent ?? undefined,
      projection: 'wide',
    },
    slots: { default: slot },
    attachTo: document.body,
  })
  mounted.push(view)
  return { view, controller }
}

async function settle() {
  await nextTick()
  await new Promise((resolve) => setTimeout(resolve, 0))
  await nextTick()
}

/** A real child mount lets the probe run before the wrapper's DOM guard. */
function nestedInline(
  slot: () => VNodeChild,
  inspect?: (node: HTMLElement) => void,
) {
  return defineComponent({
    setup() {
      const target = shallowRef<HTMLElement | null>(null)
      onMounted(() => inspect?.(target.value!))
      return () => h('span', { ref: target }, [slot()])
    },
  })
}

describe('HilosTableValue', () => {
  it.each([
    { mode: 'content' },
    { mode: 'lines', count: 3 },
    { mode: 'height', rem: 4.5 },
  ] as const)(
    'wraps the initial DOM before the safety verdict without capping it (%j)',
    async (policy) => {
      const initial: string[][] = []
      const Nested = nestedInline(
        () => 'UnbrokenToken'.repeat(200),
        (node) =>
          initial.push([...node.parentElement!.parentElement!.classList]),
      )
      const { view } = fixture(() => h(Nested), policy)
      expect(initial).toEqual([['hilos-table-text']])
      await settle()
      expect(view.get('.hilos-table-text').text()).toBe(
        'UnbrokenToken'.repeat(200),
      )
      expect(view.find('[class*="hilos-table-text-lines-"]').exists()).toBe(
        policy.mode === 'lines',
      )
      expect(view.find('[class*="hilos-table-text-height-"]').exists()).toBe(
        policy.mode === 'height',
      )
    },
  )

  it('leaves an undeclared value slot intrinsic, including controls and blocks', () => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { view } = fixture(() => h('button', 'Act'), undefined, null)
    expect(view.html()).toBe('<button>Act</button>')
    expect(view.text()).toBe('Act')
    expect(view.find('.hilos-table-text').exists()).toBe(false)
    expect(warning).not.toHaveBeenCalled()
  })

  it('starts a newly declared text region pending before the parent can measure it', async () => {
    const value = 'UnbrokenToken'.repeat(200)
    const initial: string[][] = []
    const Nested = nestedInline(
      () => value,
      (node) => initial.push([...node.parentElement!.parentElement!.classList]),
    )
    const { view } = fixture(() => h(Nested), undefined, null)
    await settle()
    initial.length = 0
    await view.setProps({ heightContent: 'text' })
    expect(initial).toEqual([['hilos-table-text']])
    await settle()
    expect(view.get('.hilos-table-text-lines-2').text()).toBe(value)
    await view.setProps({ heightContent: undefined })
    await settle()
    expect(view.find('.hilos-table-text').exists()).toBe(false)
    expect(view.text()).toBe(value)
  })

  it.each([
    [{ mode: 'content' }, 'hilos-table-text', false],
    [{ mode: 'lines', count: 2 }, 'hilos-table-text-lines-2', true],
    [{ mode: 'height', rem: 4.5 }, 'hilos-table-text-height-4-5', true],
  ] as const)(
    'renders plain/inline text using %j',
    async (policy, preset, room) => {
      const { view } = fixture(
        () => ['Full ', h('strong', 'formatted'), h('br'), 'text'],
        policy,
      )
      await settle()
      const text = view.get('.hilos-table-text')
      expect(text.classes()).toContain(preset)
      expect(text.text()).toBe('Full formattedtext')
      expect(text.attributes('aria-hidden')).toBeUndefined()
      expect(text.findAll('strong')).toHaveLength(1)
      expect(view.findAll('[aria-hidden="true"][inert]')).toHaveLength(
        room ? 1 : 0,
      )
      expect(view.find('button').exists()).toBe(false)
      if (room) {
        const holder = view.get('.invisible')
        expect(holder.text()).toBe('Show in full')
        expect(
          holder.findAll('button, [id], [data-id], [tabindex], input'),
        ).toHaveLength(0)
        expect(holder.get('.btn').classes()).toEqual([
          'btn',
          'btn-link',
          'btn-sm',
          'p-0',
        ])
      }
    },
  )

  it.each(['Short', '', 'Long value '.repeat(100)])(
    'keeps one full live value and the declared reserve (%#)',
    async (value) => {
      const { view } = fixture(() => value)
      await settle()
      expect(view.findAll('.hilos-table-text-lines-2')).toHaveLength(1)
      expect(view.get('.hilos-table-text').element.textContent).toBe(value)
      expect(view.get('.invisible').text()).toBe('Show in full')
    },
  )

  it.each([
    () => h('a', { href: '/somewhere' }, 'Link'),
    () => h('button', 'Action'),
    () => h('input'),
    () => h('img', { alt: 'Picture' }),
    () => h('video'),
    () => h('div', 'Block'),
    () => h('span', { role: 'button', tabindex: 0 }, 'Custom control'),
    () => h('span', { class: 'badge' }, 'Badge'),
    () => h(HilosHideable, { value: HIDDEN_VALUE }),
    () => h('span', { style: 'display: block' }, 'Block span'),
  ])('keeps unsafe actual DOM intrinsic and diagnoses it', async (slot) => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { view } = fixture(() => ['Plain ', slot()])
    await settle()
    expect(view.find('.hilos-table-text').exists()).toBe(false)
    expect(view.find('.hilos-table-text-lines-2').exists()).toBe(false)
    expect(view.find('.invisible').exists()).toBe(false)
    expect(warning).toHaveBeenCalledOnce()
    expect(warning).toHaveBeenCalledWith(
      expect.stringContaining("heightContent: 'parts'"),
    )
  })

  it('inspects nested component DOM and live safe/unsafe transitions without losing the value', async () => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const unsafe = shallowRef(false)
    const Nested = nestedInline(() =>
      unsafe.value ? [h('button', 'Act')] : [h('em', 'Safe')],
    )
    const { view } = fixture(() => h(Nested))
    await settle()
    expect(view.find('.hilos-table-text-lines-2').exists()).toBe(true)
    const node = view.get('.hilos-table-text').element
    unsafe.value = true
    await settle()
    expect(view.find('.hilos-table-text-lines-2').exists()).toBe(false)
    expect(view.get('button').text()).toBe('Act')
    expect(warning).toHaveBeenCalledOnce()
    unsafe.value = false
    await settle()
    expect(view.get('.hilos-table-text').element).toBe(node)
    expect(view.find('.hilos-table-text-lines-2').exists()).toBe(true)
    expect(view.text()).toContain('Safe')
  })

  it('reads text in two projections with separate ids/overflow, one intent and stable focus', async () => {
    const frames = new Map<number, FrameRequestCallback>()
    let serial = 0
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
      frames.set(++serial, callback)
      return serial
    })
    vi.stubGlobal('cancelAnimationFrame', (id: number) => frames.delete(id))
    let long = true
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue(
      new DOMRect(0, 0, 200, 40),
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
            return long && this.closest('[data-projection="card"]') ? 80 : 40
          return 200
        },
      )
    const first = fixture(() => 'Initial')
    const other = fixture(() => 'Other table')
    const firstProps = first.view.props()
    const otherProps = other.view.props()
    first.view.unmount()
    other.view.unmount()
    const value = shallowRef('Full live text')
    const action = vi.fn()
    const view = mount(
      () =>
        h('main', { onClick: action }, [
          ...(['wide', 'card'] as const).map((projection) =>
            h('section', { 'data-projection': projection }, [
              h(
                HilosTableValue,
                { ...firstProps, projection },
                { default: () => value.value },
              ),
            ]),
          ),
          h('section', { 'data-projection': 'other' }, [
            h(HilosTableValue, otherProps, { default: () => 'Other table' }),
          ]),
        ]),
      { attachTo: document.body },
    )
    mounted.push(view)
    async function flush() {
      await nextTick()
      const pending = [...frames.values()]
      frames.clear()
      for (const callback of pending) callback(0)
      await nextTick()
    }
    await flush()
    const texts = view.findAll('.hilos-table-text')
    expect(new Set(texts.map((node) => node.attributes('id'))).size).toBe(3)
    expect(view.findAll('[data-id="hilos-table-text-toggle"]')).toHaveLength(1)
    const selector =
      '[data-projection="card"] [data-id="hilos-table-text-toggle"]'
    const button = view.get(selector)
    const element = button.element as HTMLButtonElement
    expect(document.getElementById(button.attributes('aria-controls')!)).toBe(
      texts[1].element,
    )
    element.focus()
    await button.trigger('click')
    await flush()
    expect(view.findAll('.is-open')).toHaveLength(2)
    expect(view.find('[data-projection="other"] .is-open').exists()).toBe(false)
    expect(action).not.toHaveBeenCalled()
    value.value = 'Short'
    long = false
    await flush()
    expect(button.text()).toBe('Collapse')
    await button.trigger('click')
    await flush()
    expect(view.get(selector).element).toBe(element)
    expect(button.text()).toBe('Show in full')
    expect(document.activeElement).toBe(element)
    element.blur()
    await nextTick()
    expect(view.find(selector).exists()).toBe(false)
    expect(
      view.findAll('.hilos-table-text').map((node) => node.text()),
    ).toEqual(['Short', 'Short', 'Other table'])
  })

  it('disconnects the DOM guard and ignores queued callbacks after unmount', async () => {
    const callbacks: MutationCallback[] = []
    const disconnect = vi.fn()
    vi.stubGlobal(
      'MutationObserver',
      class {
        constructor(callback: MutationCallback) {
          callbacks.push(callback)
        }
        observe() {}
        disconnect = disconnect
      },
    )
    const { view } = fixture(() => h('em', 'Value'))
    await nextTick()
    view.unmount()
    expect(disconnect).toHaveBeenCalled()
    for (const callback of callbacks)
      expect(() => callback([], {} as MutationObserver)).not.toThrow()
  })
})
