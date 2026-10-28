import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, shallowRef } from 'vue'
import {
  TableViewportController,
  type TableGeometryRecord,
  type TableProjection,
} from '@hilos/core/base'
import {
  useTableTextOverflow,
  type TableTextOverflow,
} from '../src/useTableTextOverflow.js'

type FixtureProjection = Extract<TableProjection, 'wide' | 'card'>

// Mocked boxes prove measurement wiring and lifetime, never CSS wrapping.
// The real font/wrapping/visible-control proof belongs to the browser slice.
interface Metrics {
  width: number
  height: number
  scrollWidth: number
  scrollHeight: number
  rectWidth?: number
  rectHeight?: number
}

const mounted: { unmount(): void }[] = []
const controllers: TableViewportController<unknown>[] = []
const registrations: { dispose(): void }[] = []
const fontsDescriptor = Object.getOwnPropertyDescriptor(document, 'fonts')
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
  for (const registration of registrations.splice(0)) registration.dispose()
  for (const controller of controllers.splice(0)) controller.dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  if (fontsDescriptor) Object.defineProperty(document, 'fonts', fontsDescriptor)
  else Reflect.deleteProperty(document, 'fonts')
  document.body.replaceChildren()
})

function owner() {
  const controller = new TableViewportController<unknown>({
    resolve: (row) => row.slots,
    sendViewport: () => {},
    frame: {
      columns: [
        { key: 'name', label: 'Name', heightContent: 'text' },
        { key: 'parts', label: 'Parts', heightContent: 'parts' },
      ],
      rowHeight: { mode: 'lines', count: 2 },
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
  controllers.push(controller)
  registrations.push(registration)
  return {
    controller,
    registration,
    record: () => registration.measurement('a', 'wide')!.record,
  }
}

function browser(
  options: { realMutations?: boolean; observers?: boolean } = {},
) {
  const frames = new Map<number, FrameRequestCallback>()
  const history: FrameRequestCallback[] = []
  let serial = 0
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    history.push(callback)
    frames.set(++serial, callback)
    return serial
  })
  const cancel = vi.fn((id: number) => frames.delete(id))
  vi.stubGlobal('cancelAnimationFrame', cancel)

  class Observer {
    readonly targets = new Map<Node, MutationObserverInit | undefined>()
    readonly observe = vi.fn((node: Node, init?: MutationObserverInit) => {
      this.targets.set(node, init)
    })
    readonly disconnect = vi.fn(() => this.targets.clear())
    constructor(readonly callback: () => void) {}
  }
  const resizes: Observer[] = []
  const mutations: Observer[] = []
  vi.stubGlobal(
    'ResizeObserver',
    options.observers === false
      ? undefined
      : class extends Observer {
          constructor(callback: () => void) {
            super(callback)
            resizes.push(this)
          }
        },
  )
  if (!options.realMutations)
    vi.stubGlobal(
      'MutationObserver',
      options.observers === false
        ? undefined
        : class extends Observer {
            constructor(callback: () => void) {
              super(callback)
              mutations.push(this)
            }
          },
    )

  let fontsReady!: () => void
  const fonts = Object.assign(new EventTarget(), {
    ready: new Promise<void>((resolve) => {
      fontsReady = resolve
    }),
  })
  const fontAdd = vi.spyOn(fonts, 'addEventListener')
  const fontRemove = vi.spyOn(fonts, 'removeEventListener')
  Object.defineProperty(document, 'fonts', {
    configurable: true,
    value: fonts,
  })
  const metrics = new WeakMap<HTMLElement, Metrics>()
  const readRect = vi
    .spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    .mockImplementation(function (this: HTMLElement) {
      const box = metrics.get(this)
      return new DOMRect(
        0,
        0,
        box?.rectWidth ?? box?.width ?? 0,
        box?.rectHeight ?? box?.height ?? 0,
      )
    })
  for (const property of [
    'clientWidth',
    'clientHeight',
    'scrollWidth',
    'scrollHeight',
  ] as const)
    vi.spyOn(HTMLElement.prototype, property, 'get').mockImplementation(
      function (this: HTMLElement) {
        const box = metrics.get(this)
        if (!box) return 0
        if (property === 'clientWidth') return box.width
        if (property === 'clientHeight') return box.height
        return box[property]
      },
    )

  return {
    frames,
    history,
    cancel,
    metrics,
    readRect,
    resizes,
    mutations,
    fonts,
    fontsReady,
    fontAdd,
    fontRemove,
    resize(node: HTMLElement, box: Metrics) {
      const previous = metrics.get(node)
      metrics.set(node, box)
      if (previous?.width === box.width && previous.height === box.height)
        return
      for (const observer of resizes)
        if (observer.targets.has(node)) observer.callback()
    },
    async layout() {
      await nextTick()
      const pending = [...frames.values()]
      frames.clear()
      for (const callback of pending) callback(0)
      await nextTick()
    },
  }
}

function fixture(
  dom: ReturnType<typeof browser>,
  initial = owner(),
  projections: readonly FixtureProjection[] = ['wide', 'card'],
) {
  const controller = shallowRef(initial.controller)
  const record = shallowRef<TableGeometryRecord | null>(initial.record())
  const column = shallowRef('name')
  const limited = shallowRef(true)
  const text = shallowRef('Full live text')
  const parts = shallowRef(false)
  const nodeKey = shallowRef(0)
  const contentKey = shallowRef(0)
  const present = shallowRef(true)
  const allowed = shallowRef(true)
  const adapters = new Map<TableProjection, TableTextOverflow>()
  const targets = new Map<TableProjection, HTMLElement>()
  const contents = new Map<TableProjection, HTMLElement>()
  const boxes: Record<FixtureProjection, Metrics> = {
    wide: { width: 200, height: 40, scrollWidth: 200, scrollHeight: 40 },
    card: { width: 100, height: 40, scrollWidth: 100, scrollHeight: 80 },
  }
  // A deliberately nonreactive guard exercises callbacks before a Vue update.
  let current = true
  const Projection = defineComponent({
    props: { projection: { type: String, required: true } },
    setup(props) {
      const projection = props.projection as FixtureProjection
      const target = shallowRef<HTMLElement | null>(null)
      const content = shallowRef<HTMLElement | null>(null)
      const adapter = useTableTextOverflow(
        () =>
          allowed.value && current && record.value
            ? {
                controller: controller.value,
                record: record.value,
                columnKey: column.value,
                projection,
              }
            : null,
        target,
        content,
        () => limited.value,
      )
      adapters.set(projection, adapter)
      return () =>
        h('section', { 'data-projection': projection }, [
          present.value
            ? h(
                'div',
                {
                  key: nodeKey.value,
                  ref: (element: unknown) => {
                    const node = element instanceof HTMLElement ? element : null
                    if (node) {
                      targets.set(projection, node)
                      dom.metrics.set(node, boxes[projection])
                    }
                    target.value = node
                  },
                  id: adapter.contentId.value,
                  class: [
                    'hilos-table-text',
                    limited.value && 'hilos-table-text-lines-2',
                    adapter.expanded.value && 'is-open',
                  ],
                },
                h(
                  'div',
                  {
                    key: contentKey.value,
                    ref: (element: unknown) => {
                      const node =
                        element instanceof HTMLElement ? element : null
                      if (node) contents.set(projection, node)
                      content.value = node
                    },
                  },
                  parts.value
                    ? [h('span', text.value), h('span', ' second plain part')]
                    : text.value,
                ),
              )
            : null,
        ])
    },
  })
  const wrapper = mount(
    () =>
      h(
        'main',
        projections.map((projection) => h(Projection, { projection })),
      ),
    { attachTo: document.body },
  )
  mounted.push(wrapper)
  return {
    wrapper,
    controller,
    record,
    column,
    limited,
    text,
    parts,
    nodeKey,
    contentKey,
    present,
    allowed,
    adapters,
    targets,
    contents,
    boxes,
    wide: adapters.get('wide')!,
    card: adapters.get('card')!,
    retireBeforeRender() {
      current = false
    },
  }
}

describe('useTableTextOverflow', () => {
  it('measures both projections independently, with unique ids and one controller intent', async () => {
    const dom = browser()
    const view = fixture(dom)
    const command = vi.spyOn(view.controller.value, 'expandText')
    expect(view.wide.overflow.value).toBeNull()
    expect(view.card.overflow.value).toBeNull()
    await dom.layout()
    expect(view.wide.overflow.value).toBe(false)
    expect(view.card.overflow.value).toBe(true)
    expect(view.wide.contentId.value).not.toBe(view.card.contentId.value)
    expect(view.wide.contentId.value).toMatch(/-wide$/)
    expect(view.card.contentId.value).toMatch(/-card$/)
    for (const projection of ['wide', 'card'] as const) {
      expect(view.targets.get(projection)!.id).toBe(
        view.adapters.get(projection)!.contentId.value,
      )
      expect(view.targets.get(projection)!.textContent).toBe('Full live text')
    }
    expect(view.wrapper.findAll('.hilos-table-text')).toHaveLength(2)
    view.card.setExpanded(true)
    expect(command).toHaveBeenCalledExactlyOnceWith(
      view.record.value,
      'name',
      true,
    )
    expect(view.wide.expanded.value).toBe(true)
    expect(view.card.expanded.value).toBe(true)
    expect(view.controller.value.expandedText.get()).toEqual(
      new Map([[view.record.value!.occurrence, new Set(['name'])]]),
    )
    await nextTick()
    expect(view.wrapper.findAll('.is-open')).toHaveLength(2)
    view.column.value = 'parts'
    expect(view.wide.expanded.value).toBe(false)
    expect(view.card.expanded.value).toBe(false)
  })

  it('gives every value its own id even when another value uses the same projection', async () => {
    const dom = browser()
    const view = fixture(dom, owner(), ['wide', 'wide', 'card', 'card'])
    await dom.layout()
    const ids = view.wrapper
      .findAll('.hilos-table-text')
      .map((node) => node.attributes('id'))
    expect(ids).toHaveLength(4)
    expect(new Set(ids).size).toBe(4)
  })

  it('rechecks text and parts mutations, including live shortening and empty content', async () => {
    const dom = browser({ realMutations: true })
    const view = fixture(dom)
    await dom.layout()
    view.text.value = 'A longer value with a long unbroken word'
    view.boxes.wide.scrollHeight = 80
    await dom.layout()
    expect(view.wide.overflow.value).toBe(true)
    view.parts.value = true
    view.column.value = 'parts'
    await dom.layout()
    expect(view.contents.get('wide')!.children).toHaveLength(2)
    expect(view.wide.overflow.value).toBe(true)
    // Imperative live content changes also flow through the actual observer.
    view.contents.get('wide')!.firstChild!.textContent = 'Short'
    view.boxes.wide.scrollHeight = 40
    await new Promise((resolve) => setTimeout(resolve, 0))
    await dom.layout()
    expect(view.wide.overflow.value).toBe(false)
    view.parts.value = false
    view.text.value = ''
    view.boxes.card.scrollHeight = 40
    await dom.layout()
    expect(view.card.overflow.value).toBe(false)
    expect(view.targets.get('card')!.textContent).toBe('')
  })

  it('keeps intent and collapsed overflow through natural expanded layout and live shortening', async () => {
    const dom = browser()
    const view = fixture(dom)
    await dom.layout()
    view.card.setExpanded(true)
    view.boxes.card.height = 80
    await dom.layout()
    expect(view.card.expanded.value).toBe(true)
    expect(view.card.overflow.value).toBe(true)
    view.text.value = 'Short'
    view.boxes.card.height = 40
    view.boxes.card.scrollHeight = 40
    dom.mutations.at(-1)!.callback()
    await dom.layout()
    expect(view.card.expanded.value).toBe(true)
    expect(view.card.overflow.value).toBe(true)
    const focus = document.createElement('button')
    document.body.append(focus)
    focus.focus()
    view.card.setExpanded(false)
    view.card.requestMeasure()
    await dom.layout()
    expect(view.card.expanded.value).toBe(false)
    expect(view.card.overflow.value).toBe(false)
    expect(document.activeElement).toBe(focus)
  })

  it.each(['hidden', 'visibility', 'zero', 'disconnected'] as const)(
    'does not publish false or clear intent for an unavailable %s projection',
    async (unavailable) => {
      const dom = browser()
      const view = fixture(dom)
      await dom.layout()
      const node = view.targets.get('card')!
      const parent = node.parentElement!
      view.boxes.card.scrollHeight = 40
      if (unavailable === 'hidden') parent.hidden = true
      if (unavailable === 'visibility')
        parent.setAttribute('style', 'visibility:hidden')
      if (unavailable === 'zero') view.boxes.card.rectWidth = 0
      if (unavailable === 'disconnected') parent.remove()
      view.card.requestMeasure()
      await dom.layout()
      expect(view.card.overflow.value).toBe(true)
      view.wide.setExpanded(true)
      await dom.layout()
      expect(view.card.expanded.value).toBe(true)
      if (unavailable === 'hidden') parent.hidden = false
      if (unavailable === 'visibility') parent.removeAttribute('style')
      if (unavailable === 'zero') delete view.boxes.card.rectWidth
      if (unavailable === 'disconnected') view.wrapper.element.append(parent)
      view.card.setExpanded(false)
      await dom.layout()
      expect(view.card.overflow.value).toBe(false)
    },
  )

  it('keeps unknown until a hidden/zero initial target has a usable box', async () => {
    const dom = browser()
    const view = fixture(dom)
    view.targets.get('card')!.parentElement!.hidden = true
    view.boxes.wide.width = 0
    await dom.layout()
    expect(view.wide.overflow.value).toBeNull()
    expect(view.card.overflow.value).toBeNull()
    view.targets.get('card')!.parentElement!.hidden = false
    view.boxes.wide.width = 200
    for (const observer of dom.mutations) observer.callback()
    await dom.layout()
    expect(view.wide.overflow.value).toBe(false)
    expect(view.card.overflow.value).toBe(true)
  })

  it('wires width, ancestor spacing/classes/styles/hidden, fonts and visibility notifications', async () => {
    const dom = browser()
    const view = fixture(dom)
    await dom.layout()
    const node = view.targets.get('wide')!
    const resize = dom.resizes.find((observer) => observer.targets.has(node))!
    const mutation = dom.mutations.find((observer) =>
      observer.targets.has(node),
    )!
    expect(resize.targets.has(node.parentElement!)).toBe(true)
    expect(mutation.targets.get(node)).toMatchObject({
      childList: true,
      characterData: true,
      subtree: true,
    })
    expect(
      mutation.targets.get(document.documentElement)?.attributeFilter,
    ).toEqual(['class', 'style', 'hidden', 'inert', 'open', 'dir'])
    const notifications = [
      () => resize.callback(),
      () => mutation.callback(),
      () => dom.fonts.dispatchEvent(new Event('loadingdone')),
      () => document.dispatchEvent(new Event('visibilitychange')),
      () => window.dispatchEvent(new Event('resize')),
      () => node.dispatchEvent(new Event('transitionend')),
    ]
    for (const notify of notifications) {
      view.boxes.wide.scrollHeight = 80
      notify()
      notify()
      expect(dom.frames.size).toBeGreaterThan(0)
      await dom.layout()
      expect(view.wide.overflow.value).toBe(true)
      view.boxes.wide.scrollHeight = 40
      notify()
      await dom.layout()
      expect(view.wide.overflow.value).toBe(false)
    }
    view.boxes.wide.scrollWidth = 240
    resize.callback()
    await dom.layout()
    expect(view.wide.overflow.value).toBe(true)
    view.boxes.wide.scrollWidth = 200
    dom.fontsReady()
    await Promise.resolve()
    await dom.layout()
    expect(view.wide.overflow.value).toBe(false)
  })

  it('observes natural content resizing inside an unchanged clipped box in both projections', async () => {
    const dom = browser()
    const view = fixture(dom)
    view.boxes.card.scrollHeight = 40
    for (const projection of ['wide', 'card'] as const)
      dom.metrics.set(view.contents.get(projection)!, {
        width: view.boxes[projection].width,
        height: 40,
        scrollWidth: view.boxes[projection].width,
        scrollHeight: 40,
      })
    await dom.layout()
    for (const projection of ['wide', 'card'] as const) {
      const adapter = view.adapters.get(projection)!
      const node = view.targets.get(projection)!
      const content = view.contents.get(projection)!
      const resize = dom.resizes.find((observer) =>
        observer.targets.has(content),
      )
      // A stylesheet changes the one live content's natural height, without a
      // Vue update, target/ancestor mutation, window resize or font-load event.
      expect(
        resize,
        `${projection}: natural content is an observed target`,
      ).toBeDefined()
      expect(resize!.targets.has(node)).toBe(true)
      expect(content.textContent).toBe('Full live text')
      expect(adapter.overflow.value).toBe(false)
      view.boxes[projection].scrollHeight = 192
      dom.resize(content, {
        width: node.clientWidth,
        height: 192,
        scrollWidth: node.clientWidth,
        scrollHeight: 192,
      })
      await dom.layout()
      expect(node.clientHeight).toBe(40)
      expect(content.clientHeight).toBe(192)
      expect(adapter.overflow.value).toBe(true)
      view.boxes[projection].scrollHeight = 40
      dom.resize(content, {
        width: node.clientWidth,
        height: 40,
        scrollWidth: node.clientWidth,
        scrollHeight: 40,
      })
      await dom.layout()
      expect(adapter.overflow.value).toBe(false)
    }
  })

  it('releases replaced natural content while retaining the outer window and controller intent', async () => {
    const dom = browser()
    const view = fixture(dom)
    await dom.layout()
    view.card.setExpanded(true)
    await dom.layout()
    const node = view.targets.get('card')!
    const content = view.contents.get('card')!
    const oldObservers = [...dom.resizes, ...dom.mutations]
    view.contentKey.value++
    await nextTick()
    expect(view.targets.get('card')).toBe(node)
    expect(view.contents.get('card')).not.toBe(content)
    expect(content.isConnected).toBe(false)
    expect(view.card.expanded.value).toBe(true)
    for (const observer of oldObservers) expect(observer.targets.size).toBe(0)
    await dom.layout()
    const before = dom.readRect.mock.calls.length
    const queued = dom.frames.size
    for (const observer of oldObservers) observer.callback()
    expect(dom.frames.size).toBe(queued)
    expect(dom.readRect.mock.calls).toHaveLength(before)
    expect(
      dom.resizes.some((observer) =>
        observer.targets.has(view.contents.get('card')!),
      ),
    ).toBe(true)
    view.card.setExpanded(false)
    await dom.layout()
    expect(view.card.overflow.value).toBe(true)
  })

  it('follows real ancestor style/visibility changes and a reparented target', async () => {
    const dom = browser({ realMutations: true })
    const view = fixture(dom)
    await dom.layout()
    const node = view.targets.get('card')!
    const parent = node.parentElement!
    parent.setAttribute('style', 'word-spacing: 2px; visibility: hidden')
    view.boxes.card.scrollHeight = 40
    await new Promise((resolve) => setTimeout(resolve, 0))
    await dom.layout()
    expect(view.card.overflow.value).toBe(true)
    parent.setAttribute('class', 'new-spacing')
    parent.removeAttribute('style')
    const nextParent = document.createElement('article')
    parent.append(nextParent)
    nextParent.append(node)
    await new Promise((resolve) => setTimeout(resolve, 0))
    await dom.layout()
    expect(view.card.overflow.value).toBe(false)
    expect(
      dom.resizes.some((observer) => observer.targets.has(nextParent)),
    ).toBe(true)
  })

  it('rebinds controller, exact record and target, cancelling every old observer/frame/font callback', async () => {
    const dom = browser()
    const view = fixture(dom)
    await dom.layout()
    view.wide.setExpanded(true)
    await dom.layout()
    const oldController = view.controller.value
    const oldRecord = view.record.value!
    const oldNode = view.targets.get('wide')!
    const oldObservers = [...dom.resizes, ...dom.mutations]
    const oldFrames = [...dom.history]
    const oldFontCallbacks = dom.fontAdd.mock.calls.map(
      (call) => call[1] as () => void,
    )
    const replacement = owner()
    view.controller.value = replacement.controller
    view.record.value = replacement.record()
    view.nodeKey.value++
    await nextTick()
    expect(view.wide.expanded.value).toBe(false)
    expect(view.wide.overflow.value).toBeNull()
    expect(view.targets.get('wide')).not.toBe(oldNode)
    expect(oldNode.isConnected).toBe(false)
    for (const observer of oldObservers) expect(observer.targets.size).toBe(0)
    await dom.layout()
    view.wide.requestMeasure()
    const queued = dom.frames.size
    for (const observer of oldObservers) observer.callback()
    for (const callback of oldFrames) callback(0)
    for (const callback of oldFontCallbacks) callback()
    expect(dom.frames.size).toBe(queued)
    expect(view.wide.expanded.value).toBe(false)
    oldController.expandText(oldRecord, 'name', false)
    oldController.expandText(oldRecord, 'name', true)
    expect(view.wide.expanded.value).toBe(false)
    view.wide.setExpanded(true)
    expect(
      replacement.controller.expandedText
        .get()
        .get(replacement.record().occurrence)
        ?.has('name'),
    ).toBe(true)
    expect(dom.cancel).toHaveBeenCalled()
  })

  it('rejects stale callbacks before render and does not inherit intent when a row key is reused', async () => {
    const dom = browser()
    const original = owner()
    const view = fixture(dom, original)
    await dom.layout()
    view.card.setExpanded(true)
    await dom.layout()
    const oldRecord = view.record.value!
    const oldObservers = [...dom.resizes, ...dom.mutations]
    view.card.requestMeasure()
    const lateFrame = dom.history.at(-1)!
    view.retireBeforeRender()
    const command = vi.spyOn(original.controller, 'expandText')
    view.card.setExpanded(false)
    expect(command).not.toHaveBeenCalled()
    const before = dom.readRect.mock.calls.length
    lateFrame(0)
    for (const observer of oldObservers) observer.callback()
    expect(dom.readRect.mock.calls).toHaveLength(before)
    // Reset the live scope through a fresh mount, retaining the old callbacks.
    view.wrapper.unmount()
    original.controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Replacement' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const replacement = fixture(dom, original)
    expect(replacement.record.value!.rowKey).toBe(oldRecord.rowKey)
    expect(replacement.record.value).not.toBe(oldRecord)
    expect(replacement.card.expanded.value).toBe(false)
    original.controller.expandText(oldRecord, 'name', true)
    expect(replacement.card.expanded.value).toBe(false)
    for (const observer of oldObservers) observer.callback()
    await dom.layout()
    expect(replacement.card.overflow.value).toBe(true)
  })

  it('resets measurement on record and target replacement within the same mount', async () => {
    const dom = browser()
    const original = owner()
    const view = fixture(dom, original)
    await dom.layout()
    const oldId = view.card.contentId.value
    const oldObserver = dom.resizes.at(-1)!
    original.controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'New occurrence' } }],
      1,
      true,
      null,
      null,
      10,
    )
    view.record.value = original.record()
    expect(view.card.overflow.value).toBeNull()
    expect(view.card.contentId.value).toBe(oldId)
    oldObserver.callback()
    await dom.layout()
    expect(view.card.overflow.value).toBe(true)
    view.present.value = false
    await nextTick()
    expect(view.card.overflow.value).toBeNull()
    view.present.value = true
    view.boxes.card.scrollHeight = 40
    await dom.layout()
    expect(view.card.overflow.value).toBe(false)
    view.allowed.value = false
    expect(view.card.overflow.value).toBeNull()
    expect(view.card.expanded.value).toBe(false)
  })

  it('does not measure content policy, and can switch back to a limited window', async () => {
    const dom = browser()
    const view = fixture(dom)
    view.limited.value = false
    await dom.layout()
    expect(view.card.overflow.value).toBeNull()
    expect(dom.readRect).not.toHaveBeenCalled()
    const command = vi.spyOn(view.controller.value, 'expandText')
    view.card.setExpanded(true)
    expect(command).not.toHaveBeenCalled()
    expect(view.wrapper.findAll('.hilos-table-text-lines-2')).toHaveLength(0)
    view.limited.value = true
    await dom.layout()
    expect(view.card.overflow.value).toBe(true)
  })

  it.each(['dispose', 'unmount'] as const)(
    'releases all resources on %s; late callbacks are inert',
    async (ending) => {
      const dom = browser()
      const view = fixture(dom)
      await dom.layout()
      view.wide.requestMeasure()
      const lateFrames = [...dom.history]
      const oldObservers = [...dom.resizes, ...dom.mutations]
      const fontCallbacks = dom.fontAdd.mock.calls.map(
        (call) => call[1] as () => void,
      )
      const command = vi.spyOn(view.controller.value, 'expandText')
      if (ending === 'unmount') view.wrapper.unmount()
      else for (const adapter of view.adapters.values()) adapter.dispose()
      expect(dom.frames.size).toBe(0)
      expect(dom.fontRemove.mock.calls.length).toBe(
        dom.fontAdd.mock.calls.length,
      )
      for (const observer of oldObservers) {
        expect(observer.targets.size).toBe(0)
        expect(observer.disconnect).toHaveBeenCalled()
      }
      const before = dom.readRect.mock.calls.length
      for (const callback of lateFrames) callback(0)
      for (const observer of oldObservers) observer.callback()
      for (const callback of fontCallbacks) callback()
      dom.fontsReady()
      await Promise.resolve()
      document.dispatchEvent(new Event('visibilitychange'))
      window.dispatchEvent(new Event('resize'))
      view.wide.requestMeasure()
      view.card.setExpanded(true)
      view.card.dispose()
      expect(command).not.toHaveBeenCalled()
      expect(dom.frames.size).toBe(0)
      expect(dom.readRect.mock.calls).toHaveLength(before)
      expect(view.card.overflow.value).toBeNull()
    },
  )

  it('keeps explicit measurement usable without optional observer/font APIs', async () => {
    const dom = browser({ observers: false })
    Object.defineProperty(document, 'fonts', {
      configurable: true,
      value: undefined,
    })
    const view = fixture(dom)
    await dom.layout()
    expect(view.card.overflow.value).toBe(true)
    view.boxes.card.scrollHeight = 40
    view.card.requestMeasure()
    await dom.layout()
    expect(view.card.overflow.value).toBe(false)
  })
})
