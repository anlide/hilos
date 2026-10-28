import { act, cleanup, render } from '@testing-library/react'
import {
  StrictMode,
  Suspense,
  useContext,
  useLayoutEffect,
  useRef,
} from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  TableViewportController,
  type TableGeometryRecord,
  type TableProjection,
} from '@hilos/core/base'
import {
  useTableTextOverflow,
  type TableTextOverflow,
  type TableTextOverflowController,
} from '../src/useTableTextOverflow.js'
import {
  HilosTableTextContext,
  TABLE_TEXT_COPY,
  useTableTextContext,
  useTableTextReading,
  useTableTextRegion,
  type TableTextContext,
} from '../src/hilosTableText.js'
import { useTableViewportGeometry } from '../src/useTableViewportGeometry.js'

// These boxes prove adapter wiring/lifetime, never native layout or component ARIA.
interface Metrics {
  width: number
  height: number
  scrollWidth: number
  scrollHeight: number
  rectWidth?: number
  rectHeight?: number
}

const controllers: TableViewportController<unknown>[] = []
const registrations: { dispose(): void }[] = []
const fontsDescriptor = Object.getOwnPropertyDescriptor(document, 'fonts')
afterEach(() => {
  cleanup()
  for (const registration of registrations.splice(0)) registration.dispose()
  for (const controller of controllers.splice(0)) controller.dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  if (fontsDescriptor) Object.defineProperty(document, 'fonts', fontsDescriptor)
  else Reflect.deleteProperty(document, 'fonts')
  document.body.replaceChildren()
})

function snapshot(controller: TableViewportController<unknown>) {
  controller.ingestWindow(
    [{ rowKey: 'a', slots: { name: 'Full live text' } }],
    1,
    true,
    null,
    null,
    10,
  )
}

function owner(mode: 'lines' | 'height' | 'content' = 'lines') {
  const controller = new TableViewportController<unknown>({
    resolve: (row) => row.slots,
    sendViewport: () => {},
    expandable: () => true,
    frame: {
      columns: [
        { key: 'name', label: 'Name', heightContent: 'text' },
        { key: 'parts', label: 'Parts', heightContent: 'parts' },
      ],
      rowHeight:
        mode === 'lines'
          ? { mode, count: 2 }
          : mode === 'height'
            ? { mode, rem: 4.5 }
            : { mode },
    },
  })
  snapshot(controller)
  const registration = controller.geometry.register(() => ({ records: [] }))
  controllers.push(controller)
  registrations.push(registration)
  const record = () => registration.measurement('a', 'wide')!.record
  const reader = () => {
    const captured = record()
    return () => (controller.isTextRecordCurrent(captured) ? captured : null)
  }
  return { controller, registration, record, reader }
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
    readonly unobserve = vi.fn((node: Node) => this.targets.delete(node))
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
  const readyCallbacks: (() => void)[] = []
  const ready = new Promise<void>((resolve) => {
    fontsReady = resolve
  })
  const then = ready.then.bind(ready)
  vi.spyOn(ready, 'then').mockImplementation((fulfilled, rejected) => {
    if (fulfilled) readyCallbacks.push(fulfilled as () => void)
    return then(fulfilled, rejected)
  })
  const fonts = Object.assign(new EventTarget(), { ready })
  Object.defineProperty(document, 'fonts', { configurable: true, value: fonts })
  const fontAdd = vi.spyOn(fonts, 'addEventListener')
  const fontRemove = vi.spyOn(fonts, 'removeEventListener')
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
    readyCallbacks,
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
      await act(async () => {
        const pending = [...frames.values()]
        frames.clear()
        for (const callback of pending) callback(0)
      })
    },
  }
}

interface HarnessProps {
  controller: TableTextOverflowController
  readRecord: () => TableGeometryRecord | null
  column?: string
  projection?: TableProjection
  limited?: boolean
  present?: boolean
  nodeKey?: number
  contentKey?: number
  text?: string
  parts?: boolean
  dom: ReturnType<typeof browser>
  box: Metrics
  onCommit: (adapter: TableTextOverflow) => void
  onRender?: () => void
  suspend?: boolean
}

function Harness({
  controller,
  readRecord,
  column = 'name',
  projection = 'wide',
  limited = true,
  present = true,
  nodeKey = 0,
  contentKey = 0,
  text = 'Full live text',
  parts = false,
  dom,
  box,
  onCommit,
  onRender,
  suspend = false,
}: HarnessProps) {
  const target = useRef<HTMLDivElement>(null)
  const content = useRef<HTMLDivElement>(null)
  const adapter = useTableTextOverflow(
    () => {
      const record = readRecord()
      return record
        ? { controller, record, columnKey: column, projection }
        : null
    },
    target,
    content,
    () => limited,
  )
  onRender?.()
  useLayoutEffect(() => onCommit(adapter))
  if (suspend) throw NEVER
  return (
    <section>
      {present && (
        <div
          key={nodeKey}
          id={adapter.contentId}
          className={`hilos-table-text${adapter.expanded ? ' is-open' : ''}`}
          ref={(node) => {
            target.current = node
            if (node) dom.metrics.set(node, box)
          }}
        >
          <div key={contentKey} ref={content}>
            {parts ? (
              <>
                <span>{text}</span>
                <b>Part</b>
              </>
            ) : (
              text
            )}
          </div>
        </div>
      )}
    </section>
  )
}
const NEVER = new Promise<never>(() => {})

function fixture(
  dom: ReturnType<typeof browser>,
  initial = owner(),
  options: {
    strict?: boolean
    limited?: boolean
    initiallyNull?: boolean
  } = {},
) {
  const wideBox: Metrics = {
    width: 200,
    height: 40,
    scrollWidth: 200,
    scrollHeight: 40,
  }
  const cardBox: Metrics = {
    width: 100,
    height: 40,
    scrollWidth: 100,
    scrollHeight: 80,
  }
  let wide!: TableTextOverflow
  let card!: TableTextOverflow
  let props = {
    controller: initial.controller,
    readRecord: options.initiallyNull ? () => null : initial.reader(),
    limited: options.limited ?? true,
    present: true,
    nodeKey: 0,
    contentKey: 0,
    column: 'name',
    text: 'Full live text',
    parts: false,
    projection: 'wide' as TableProjection,
  }
  const renders = vi.fn()
  function tree() {
    const children = (
      <>
        <Harness
          {...props}
          dom={dom}
          box={wideBox}
          onRender={renders}
          onCommit={(value) => {
            wide = value
          }}
        />
        <Harness
          {...props}
          projection="card"
          dom={dom}
          box={cardBox}
          onCommit={(value) => {
            card = value
          }}
        />
      </>
    )
    return options.strict ? <StrictMode>{children}</StrictMode> : children
  }
  const view = render(tree())
  return {
    ...initial,
    view,
    renders,
    wideBox,
    cardBox,
    get wide() {
      return wide
    },
    get card() {
      return card
    },
    target(projection: 'wide' | 'card') {
      return view.container.querySelectorAll<HTMLElement>('.hilos-table-text')[
        projection === 'wide' ? 0 : 1
      ]!
    },
    update(next: Partial<typeof props> = {}) {
      props = { ...props, ...next }
      view.rerender(tree())
    },
  }
}

describe('React text overflow lifetime', () => {
  it('keeps independent projection measurements and ids with shared intent, separate from detail and other columns', async () => {
    const dom = browser()
    const view = fixture(dom)
    expect(view.wide.overflow).toBeNull()
    expect(view.card.overflow).toBeNull()
    await dom.layout()
    expect(view.wide.overflow).toBe(false)
    expect(view.card.overflow).toBe(true)
    expect(view.wide.contentId).not.toBe(view.card.contentId)
    expect(view.wide.contentId).toMatch(/-wide$/)
    expect(view.card.contentId).toMatch(/-card$/)
    const command = vi.spyOn(view.controller, 'expandText')
    act(() => view.card.setExpanded(true))
    expect(command).toHaveBeenCalledExactlyOnceWith(view.record(), 'name', true)
    expect(view.wide.expanded).toBe(true)
    expect(view.card.expanded).toBe(true)
    expect(view.controller.rows.get()[0]!.expanded).toBe(false)
    act(() => view.controller.expandRow('a', true))
    expect(view.controller.rows.get()[0]!.expanded).toBe(true)
    expect(view.wide.expanded).toBe(true)
    view.update({ column: 'parts' })
    expect(view.wide.expanded).toBe(false)
    act(() => view.wide.setExpanded(true))
    expect(view.controller.expandedText.get()).toEqual(
      new Map([[view.record().occurrence, new Set(['name', 'parts'])]]),
    )
  })

  it('gives each value a unique id across same-projection siblings and tables', () => {
    const dom = browser()
    const first = owner()
    const second = owner()
    const views = [
      fixture(dom, first),
      fixture(dom, first),
      fixture(dom, second),
    ]
    const ids = views.flatMap((view) => [
      view.wide.contentId,
      view.card.contentId,
    ])
    expect(new Set(ids).size).toBe(6)
  })

  it('binds after an initial null reader and keeps the binding when getter functions change', async () => {
    const dom = browser()
    const view = fixture(dom, owner(), { initiallyNull: true })
    expect(dom.resizes).toHaveLength(0)
    expect(view.wide.binding).toBeNull()
    view.update({ readRecord: view.reader() })
    await dom.layout()
    expect(view.card.overflow).toBe(true)
    const binding = view.card.binding
    const observers = dom.resizes.length
    view.update({ readRecord: view.reader(), text: 'A live update' })
    await dom.layout()
    expect(view.card.binding).toBe(binding)
    expect(dom.resizes).toHaveLength(observers)
  })

  it('reads real text/parts mutations, width and empty content', async () => {
    const dom = browser({ realMutations: true })
    const view = fixture(dom)
    await dom.layout()
    view.wideBox.scrollHeight = 80
    view.update({ text: 'Long unbroken text', parts: true, column: 'parts' })
    await dom.layout()
    expect(view.wide.overflow).toBe(true)
    const natural = view.target('wide').firstElementChild!
    expect(natural.children).toHaveLength(2)
    natural.firstChild!.textContent = 'Short'
    view.wideBox.scrollHeight = 40
    await act(async () => {
      await Promise.resolve()
    })
    await dom.layout()
    expect(view.wide.overflow).toBe(false)
    view.cardBox.scrollHeight = 40
    view.update({ text: '', parts: false })
    await dom.layout()
    expect(view.card.overflow).toBe(false)
    expect(view.target('card').textContent).toBe('')
    view.wideBox.scrollWidth = 201
    window.dispatchEvent(new Event('resize'))
    await dom.layout()
    expect(view.wide.overflow).toBe(true)
  })

  it('preserves intent and the collapsed result during expansion and live shortening; collapse waits for commit', async () => {
    const dom = browser()
    const view = fixture(dom)
    await dom.layout()
    act(() => view.card.setExpanded(true))
    view.cardBox.height = 80
    await dom.layout()
    expect(view.card.overflow).toBe(true)
    view.cardBox.height = 40
    view.cardBox.scrollHeight = 40
    view.update({ text: 'Short' })
    await dom.layout()
    expect(view.card.expanded).toBe(true)
    expect(view.card.overflow).toBe(true)
    const read = dom.readRect.mock.calls.length
    act(() => {
      view.card.setExpanded(false)
      view.card.requestMeasure()
      for (const callback of [...dom.frames.values()]) callback(0)
      dom.frames.clear()
      expect(view.target('card').classList.contains('is-open')).toBe(true)
      expect(dom.readRect.mock.calls).toHaveLength(read)
      expect(view.card.overflow).toBe(true)
    })
    await dom.layout()
    expect(view.card.overflow).toBe(false)
  })

  it.each([
    'hidden',
    'inert',
    'visibility',
    'collapse',
    'contentVisibility',
    'display',
    'zero',
    'document',
    'disconnected',
  ] as const)(
    'keeps the last usable result while %s is unavailable',
    async (unavailable) => {
      const dom = browser()
      const view = fixture(dom)
      await dom.layout()
      const parent = view.target('card').parentElement!
      view.cardBox.scrollHeight = 40
      const computed = window.getComputedStyle.bind(window)
      const readStyle = vi
        .spyOn(globalThis, 'getComputedStyle')
        .mockImplementation((element) => {
          const result = computed(element)
          if (element === parent) {
            if (unavailable === 'visibility')
              return { ...result, visibility: 'hidden' } as CSSStyleDeclaration
            if (unavailable === 'collapse')
              return {
                ...result,
                visibility: 'collapse',
              } as CSSStyleDeclaration
            if (unavailable === 'contentVisibility')
              return {
                ...result,
                contentVisibility: 'hidden',
              } as CSSStyleDeclaration
            if (unavailable === 'display')
              return { ...result, display: 'none' } as CSSStyleDeclaration
          }
          return result
        })
      if (unavailable === 'hidden') parent.hidden = true
      if (unavailable === 'inert') parent.setAttribute('inert', '')
      if (unavailable === 'zero') view.cardBox.rectWidth = 0
      if (unavailable === 'document')
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
      if (unavailable === 'disconnected') parent.remove()
      view.card.requestMeasure()
      await dom.layout()
      expect(view.card.overflow).toBe(true)
      act(() => view.wide.setExpanded(true))
      expect(view.card.expanded).toBe(true)
      readStyle.mockRestore()
      if (unavailable === 'disconnected') view.view.container.append(parent)
    },
  )

  it('keeps hidden/zero initial layout unknown, then measures after visibility returns', async () => {
    const dom = browser()
    const view = fixture(dom)
    view.target('card').parentElement!.hidden = true
    view.wideBox.width = 0
    await dom.layout()
    expect(view.wide.overflow).toBeNull()
    expect(view.card.overflow).toBeNull()
    view.target('card').parentElement!.hidden = false
    view.wideBox.width = 200
    for (const observer of dom.mutations) observer.callback()
    await dom.layout()
    expect(view.wide.overflow).toBe(false)
    expect(view.card.overflow).toBe(true)
  })

  it('observes the natural content inside an unchanged rem cap, including its replacement', async () => {
    const dom = browser()
    const view = fixture(dom, owner('height'))
    view.cardBox.scrollHeight = 40
    await dom.layout()
    for (const projection of ['wide', 'card'] as const) {
      const node = view.target(projection)
      const content = node.firstElementChild as HTMLElement
      const adapter = () => (projection === 'wide' ? view.wide : view.card)
      dom.metrics.set(content, {
        width: node.clientWidth,
        height: 40,
        scrollWidth: node.clientWidth,
        scrollHeight: 40,
      })
      const resize = dom.resizes.find((observer) =>
        observer.targets.has(content),
      )!
      expect(resize.targets.has(node)).toBe(true)
      const box = projection === 'wide' ? view.wideBox : view.cardBox
      box.scrollHeight = 192
      dom.resize(content, {
        width: node.clientWidth,
        height: 192,
        scrollWidth: node.clientWidth,
        scrollHeight: 192,
      })
      await dom.layout()
      expect(node.clientHeight).toBe(40)
      expect(adapter().overflow).toBe(true)
      box.scrollHeight = 40
      dom.resize(content, {
        width: node.clientWidth,
        height: 40,
        scrollWidth: node.clientWidth,
        scrollHeight: 40,
      })
      await dom.layout()
      expect(adapter().overflow).toBe(false)
    }
    act(() => view.card.setExpanded(true))
    const outer = view.target('card')
    const oldContent = outer.firstElementChild
    const oldObservers = [...dom.resizes, ...dom.mutations]
    const oldCommand = view.card.setExpanded
    view.update({ contentKey: 1 })
    expect(view.target('card')).toBe(outer)
    expect(outer.firstElementChild).not.toBe(oldContent)
    expect(view.card.expanded).toBe(true)
    expect(view.card.overflow).toBeNull()
    for (const observer of oldObservers) expect(observer.targets.size).toBe(0)
    const command = vi.spyOn(view.controller, 'expandText')
    act(() => oldCommand(false))
    expect(command).not.toHaveBeenCalled()
    expect(
      dom.resizes.some((observer) =>
        observer.targets.has(outer.firstElementChild!),
      ),
    ).toBe(true)
  })

  it('follows notifications for width, ancestry, visibility and fonts, coalescing RAFs', async () => {
    const dom = browser()
    const view = fixture(dom)
    await dom.layout()
    const node = view.target('wide')
    const resize = dom.resizes.find((observer) => observer.targets.has(node))!
    const mutation = dom.mutations.find((observer) =>
      observer.targets.has(node),
    )!
    expect(resize.targets.has(document.documentElement)).toBe(true)
    expect(mutation.targets.get(node)).toMatchObject({
      childList: true,
      characterData: true,
      subtree: true,
    })
    expect(
      mutation.targets.get(document.documentElement)?.attributeFilter,
    ).toEqual(['class', 'style', 'hidden', 'inert', 'open', 'dir'])
    for (const notify of [
      () => resize.callback(),
      () => mutation.callback(),
      () => dom.fonts.dispatchEvent(new Event('loadingdone')),
      () => document.dispatchEvent(new Event('visibilitychange')),
      () => window.dispatchEvent(new Event('resize')),
      () => node.dispatchEvent(new Event('transitionend')),
      () => node.dispatchEvent(new Event('animationend')),
    ]) {
      view.wideBox.scrollHeight = 80
      notify()
      const count = dom.frames.size
      notify()
      expect(dom.frames.size).toBe(count)
      await dom.layout()
      expect(view.wide.overflow).toBe(true)
      view.wideBox.scrollHeight = 40
      notify()
      await dom.layout()
      expect(view.wide.overflow).toBe(false)
    }
    view.wideBox.scrollHeight = 80
    dom.fontsReady()
    await Promise.resolve()
    await dom.layout()
    expect(view.wide.overflow).toBe(true)
  })

  it('follows a reparented target through actual ancestor mutations', async () => {
    const dom = browser({ realMutations: true })
    const view = fixture(dom)
    await dom.layout()
    const node = view.target('card')
    const oldParent = node.parentElement!
    oldParent.hidden = true
    view.cardBox.scrollHeight = 40
    await act(async () => {
      await Promise.resolve()
    })
    await dom.layout()
    expect(view.card.overflow).toBe(true)
    oldParent.hidden = false
    const parent = document.createElement('article')
    oldParent.append(parent)
    parent.append(node)
    await act(async () => {
      await Promise.resolve()
    })
    await dom.layout()
    expect(view.card.overflow).toBe(false)
    expect(dom.resizes.some((observer) => observer.targets.has(parent))).toBe(
      true,
    )
    // Restore React's host relationship before its unmount.
    oldParent.append(node)
    parent.remove()
  })

  it.each([
    'controller',
    'record',
    'target',
    'projection',
    'column',
    'limited',
    'absent',
  ] as const)(
    'releases the previous binding on %s replacement',
    async (change) => {
      const dom = browser()
      const view = fixture(dom)
      await dom.layout()
      view.wide.requestMeasure()
      const old = view.wide
      const oldObservers = dom.resizes.filter((observer) =>
        observer.targets.has(view.target('wide')),
      )
      const frames = [...dom.history]
      const ready = [...dom.readyCallbacks]
      if (change === 'controller') {
        const replacement = owner()
        view.update({
          controller: replacement.controller,
          readRecord: replacement.reader(),
        })
      }
      if (change === 'record') {
        snapshot(view.controller)
        view.update({ readRecord: view.reader() })
      }
      if (change === 'target') view.update({ nodeKey: 1 })
      if (change === 'projection') view.update({ projection: 'card' })
      if (change === 'column') view.update({ column: 'parts' })
      if (change === 'limited') view.update({ limited: false })
      if (change === 'absent') view.update({ present: false })
      for (const observer of oldObservers) expect(observer.targets.size).toBe(0)
      expect(view.wide.overflow).toBeNull()
      await dom.layout()
      const before = dom.readRect.mock.calls.length
      const command = vi.spyOn(view.controller, 'expandText')
      act(() => {
        old.setExpanded(true)
        old.requestMeasure()
        for (const frame of frames) frame(0)
        for (const callback of ready) callback()
      })
      // Card callbacks may still be live when only the wide projection changes;
      // none of these callbacks reads DOM synchronously.
      expect(dom.readRect.mock.calls).toHaveLength(before)
      expect(command).not.toHaveBeenCalled()
    },
  )

  it.each(['reset', 'refusal', 'window', 'reuse', 'departure'] as const)(
    'rejects saved commands and queued callbacks on %s before React flush, with empty intent',
    async (change) => {
      const dom = browser()
      const view = fixture(dom)
      await dom.layout()
      view.wide.requestMeasure()
      const old = view.wide
      const oldRecord = view.record()
      const observers = [...dom.resizes, ...dom.mutations]
      const frames = [...dom.history]
      const ready = [...dom.readyCallbacks]
      const intent = view.controller.expandedText.get()
      const command = vi.spyOn(view.controller, 'expandText')
      const before = dom.readRect.mock.calls.length
      const renders = view.renders.mock.calls.length
      act(() => {
        if (change === 'reset') view.controller.resetWindowRequests()
        if (change === 'refusal') view.controller.ingestRefusal('forbidden')
        if (change === 'window') snapshot(view.controller)
        if (change === 'reuse')
          view.controller.ingestOwnCreate(
            { rowKey: 'a', slots: { name: 'Reused' } },
            0,
            1,
            true,
            null,
            null,
          )
        if (change === 'departure')
          view.controller.ingestDelta({
            kind: 'row_removed',
            rowKey: 'a',
            reason: 'deleted',
            own: true,
          })
        expect(view.controller.expandedText.get()).toBe(intent)
        expect(view.renders).toHaveBeenCalledTimes(renders)
        old.setExpanded(true)
        for (const observer of observers) observer.callback()
        for (const callback of frames) callback(0)
        for (const callback of ready) callback()
        expect(command).not.toHaveBeenCalled()
        expect(dom.readRect.mock.calls).toHaveLength(before)
        expect(dom.frames.size).toBe(0)
      })
      for (const observer of observers) expect(observer.targets.size).toBe(0)
      snapshot(view.controller)
      view.update({ readRecord: view.reader() })
      expect(view.record()).not.toBe(oldRecord)
      expect(view.wide.expanded).toBe(false)
      act(() => old.setExpanded(true))
      expect(command).not.toHaveBeenCalled()
      await dom.layout()
      expect(view.card.overflow).toBe(true)
    },
  )

  it.each([
    'command',
    'resize',
    'mutation',
    'raf',
    'fonts-ready',
    'fonts-loading',
  ] as const)(
    'rechecks the owner reader first at the %s boundary, independently of other callbacks',
    async (boundary) => {
      const dom = browser()
      const view = fixture(dom)
      await dom.layout()
      view.wide.requestMeasure()
      const node = view.target('wide')
      const resize = dom.resizes.find((observer) => observer.targets.has(node))!
      const mutation = dom.mutations.find((observer) =>
        observer.targets.has(node),
      )!
      const frame = dom.frames.get(
        (view.wide.binding as { frame: number }).frame,
      )!
      const font = dom.fontAdd.mock.calls[0]![1] as () => void
      const ready = dom.readyCallbacks[0]!
      const old = view.wide
      const command = vi.spyOn(view.controller, 'expandText')
      const before = dom.readRect.mock.calls.length
      const natural = node.firstElementChild!
      const parent = natural.parentElement
      const readParent = vi.fn(() => parent)
      Object.defineProperty(natural, 'parentElement', {
        configurable: true,
        get: readParent,
      })
      act(() => {
        view.controller.resetWindowRequests()
        if (boundary === 'command') old.setExpanded(true)
        if (boundary === 'resize') resize.callback()
        if (boundary === 'mutation') mutation.callback()
        if (boundary === 'raf') frame(0)
        if (boundary === 'fonts-ready') ready()
        if (boundary === 'fonts-loading') font()
        expect(old.binding).toMatchObject({
          owner: null,
          scope: null,
          node: null,
          content: null,
          publish: null,
        })
        expect(command).not.toHaveBeenCalled()
        expect(dom.readRect.mock.calls).toHaveLength(before)
        expect(readParent).not.toHaveBeenCalled()
        expect(resize.targets.size).toBe(0)
        expect(mutation.targets.size).toBe(0)
      })
    },
  )

  it('discards a measurement if the record retires during the DOM read', async () => {
    const dom = browser()
    const view = fixture(dom)
    await dom.layout()
    const node = view.target('wide')
    const rect = node.getBoundingClientRect()
    Object.defineProperty(node, 'getBoundingClientRect', {
      configurable: true,
      value: () => {
        view.controller.resetWindowRequests()
        return rect
      },
    })
    view.wideBox.scrollHeight = 80
    view.wide.requestMeasure()
    await dom.layout()
    expect(view.wide.overflow).toBe(false)
    expect(view.wide.binding).toMatchObject({
      owner: null,
      scope: null,
      node: null,
      content: null,
    })
  })

  it('does no DOM work for content policy and can return to a limited binding', async () => {
    const dom = browser()
    const view = fixture(dom, owner(), { limited: false })
    await dom.layout()
    expect(dom.readRect).not.toHaveBeenCalled()
    expect(dom.resizes).toHaveLength(0)
    expect(dom.fontAdd).not.toHaveBeenCalled()
    const command = vi.spyOn(view.controller, 'expandText')
    act(() => view.card.setExpanded(true))
    expect(command).not.toHaveBeenCalled()
    view.update({ limited: true })
    await dom.layout()
    expect(view.card.overflow).toBe(true)
  })

  it('survives StrictMode replay and releases subscriptions, refs and every callback at unmount', async () => {
    const dom = browser()
    const source = owner()
    const getIntent = vi.spyOn(source.controller.expandedText, 'get')
    const view = fixture(dom, source, { strict: true })
    await dom.layout()
    expect(view.card.overflow).toBe(true)
    const active = dom.resizes.filter((observer) => observer.targets.size)
    expect(active).toHaveLength(2)
    expect(
      dom.resizes.filter((observer) => !observer.targets.size).length,
    ).toBeGreaterThan(0)
    act(() => view.wide.setExpanded(true))
    expect(view.card.expanded).toBe(true)
    view.wide.requestMeasure()
    const old = [view.wide, view.card]
    const ticket = view.wide.binding
    const frames = [...dom.history]
    const ready = [...dom.readyCallbacks]
    view.view.unmount()
    expect(ticket).toMatchObject({
      owner: null,
      scope: null,
      node: null,
      content: null,
      publish: null,
      cleanups: [],
      ancestors: [],
    })
    expect(dom.frames.size).toBe(0)
    expect(dom.fontRemove.mock.calls.length).toBe(dom.fontAdd.mock.calls.length)
    for (const observer of [...dom.resizes, ...dom.mutations])
      expect(observer.targets.size).toBe(0)
    const before = dom.readRect.mock.calls.length
    const reads = getIntent.mock.calls.length
    for (const adapter of old) {
      adapter.setExpanded(false)
      adapter.requestMeasure()
      adapter.dispose()
    }
    for (const frame of frames) frame(0)
    for (const observer of [...dom.resizes, ...dom.mutations])
      observer.callback()
    for (const callback of ready) callback()
    dom.fontsReady()
    await Promise.resolve()
    window.dispatchEvent(new Event('resize'))
    document.dispatchEvent(new Event('visibilitychange'))
    expect(dom.frames.size).toBe(0)
    expect(dom.readRect.mock.calls).toHaveLength(before)
    expect(getIntent.mock.calls).toHaveLength(reads)
    source.controller.expandText(source.record(), 'name', false)
    const remount = fixture(dom, source, { strict: true })
    await dom.layout()
    expect(remount.card.expanded).toBe(false)
    expect(remount.card.overflow).toBe(true)
  })

  it('removes the useSignal subscription on controller replacement and unmount', () => {
    const dom = browser()
    const first = owner()
    const second = owner()
    const mirror = (source: ReturnType<typeof owner>) => ({
      expandedText: { get: vi.fn(() => source.controller.expandedText.get()) },
      expandText: source.controller.expandText.bind(source.controller),
    })
    const firstMirror = mirror(first)
    const secondMirror = mirror(second)
    let adapter!: TableTextOverflow
    const props = {
      dom,
      box: { width: 100, height: 40, scrollWidth: 100, scrollHeight: 80 },
      onCommit: (value: TableTextOverflow) => {
        adapter = value
      },
    }
    const view = render(
      <StrictMode>
        <Harness
          {...props}
          controller={firstMirror}
          readRecord={first.reader()}
        />
      </StrictMode>,
    )
    act(() => first.controller.expandText(first.record(), 'name', true))
    expect(adapter.expanded).toBe(true)
    view.rerender(
      <StrictMode>
        <Harness
          {...props}
          controller={secondMirror}
          readRecord={second.reader()}
        />
      </StrictMode>,
    )
    expect(adapter.expanded).toBe(false)
    const firstReads = firstMirror.expandedText.get.mock.calls.length
    act(() => first.controller.expandText(first.record(), 'name', false))
    expect(firstMirror.expandedText.get).toHaveBeenCalledTimes(firstReads)
    expect(adapter.expanded).toBe(false)
    act(() => second.controller.expandText(second.record(), 'name', true))
    expect(adapter.expanded).toBe(true)
    view.unmount()
    const secondReads = secondMirror.expandedText.get.mock.calls.length
    second.controller.expandText(second.record(), 'name', false)
    expect(secondMirror.expandedText.get).toHaveBeenCalledTimes(secondReads)
  })

  it('still measures without optional observer and font APIs', async () => {
    const dom = browser({ observers: false })
    Object.defineProperty(document, 'fonts', {
      configurable: true,
      value: undefined,
    })
    const view = fixture(dom)
    await dom.layout()
    expect(view.card.overflow).toBe(true)
    view.cardBox.scrollHeight = 40
    view.card.requestMeasure()
    await dom.layout()
    expect(view.card.overflow).toBe(false)
  })

  it('does not create resources, commands or context registrations for an abandoned render', () => {
    const dom = browser()
    const source = owner()
    const command = vi.spyOn(source.controller, 'expandText')
    const onCommit = vi.fn()
    const view = render(
      <Suspense fallback={<span>Pending</span>}>
        <Harness
          controller={source.controller}
          readRecord={source.reader()}
          dom={dom}
          box={{ width: 100, height: 40, scrollWidth: 100, scrollHeight: 80 }}
          onCommit={onCommit}
          suspend
        />
      </Suspense>,
    )
    expect(view.container.textContent).toBe('Pending')
    expect(dom.resizes).toHaveLength(0)
    expect(dom.mutations).toHaveLength(0)
    expect(dom.frames.size).toBe(0)
    expect(dom.readRect).not.toHaveBeenCalled()
    expect(command).not.toHaveBeenCalled()
    expect(onCommit).not.toHaveBeenCalled()
  })
})

describe('React text context and reading seam', () => {
  it('registers parts only after commit, with idempotent cleanup under StrictMode', () => {
    const source = owner()
    let context!: TableTextContext
    const counts: number[] = []
    const register = vi.fn()
    function Region() {
      const provided = useContext(HilosTableTextContext)!
      useTableTextRegion(provided)
      return null
    }
    function Provider({
      present,
      suspend = false,
    }: {
      present: boolean
      suspend?: boolean
    }) {
      context = useTableTextContext({
        controller: source.controller,
        readRecord: source.reader(),
        columnKey: 'parts',
        label: 'Parts',
        heightContent: 'parts',
        projection: 'wide',
      })
      counts.push(context.regions)
      const actual = context.register
      const tracked = () => {
        register()
        return actual()
      }
      // Keep the production registration function stable across provider renders.
      const trackedRef = useRef(tracked)
      if (suspend) throw NEVER
      return (
        <HilosTableTextContext.Provider
          value={{ ...context, register: trackedRef.current }}
        >
          {present && <Region />}
        </HilosTableTextContext.Provider>
      )
    }
    const view = render(
      <StrictMode>
        <Provider present />
      </StrictMode>,
    )
    expect(counts[0]).toBe(0)
    expect(context.regions).toBe(1)
    view.rerender(
      <StrictMode>
        <Provider present={false} />
      </StrictMode>,
    )
    expect(context.regions).toBe(0)
    let release!: () => void
    act(() => {
      release = context.register()
    })
    expect(context.regions).toBe(1)
    act(() => {
      release()
      release()
    })
    expect(context.regions).toBe(0)
    const before = register.mock.calls.length
    render(
      <Suspense fallback="Pending">
        <Provider present suspend />
      </Suspense>,
    )
    expect(register).toHaveBeenCalledTimes(before)
  })

  it('prepares policy, names and local focus without dropping the focused disclosure after shortening/collapse', async () => {
    const dom = browser()
    const source = owner('height')
    const box = { width: 100, height: 40, scrollWidth: 100, scrollHeight: 80 }
    let reading!: ReturnType<typeof useTableTextReading>
    const readRecord = source.reader()
    function Reading({
      column = 'name',
      label = 'Name',
      eligible = true,
    }: {
      column?: string
      label?: string
      eligible?: boolean
    }) {
      const context = useTableTextContext({
        controller: source.controller,
        readRecord,
        columnKey: column,
        label,
        heightContent: 'text',
        projection: 'wide',
      })
      const target = useRef<HTMLDivElement>(null)
      const content = useRef<HTMLDivElement>(null)
      const value = useTableTextReading(context, target, content, eligible)
      useLayoutEffect(() => {
        reading = value
      })
      return (
        <div
          ref={(node) => {
            target.current = node
            if (node) dom.metrics.set(node, box)
          }}
          className={`${value.textClass}${value.expanded ? ' is-open' : ''}`}
        >
          <div ref={content}>Text</div>
        </div>
      )
    }
    const view = render(<Reading />)
    await dom.layout()
    expect(reading.textClass).toBe(
      'hilos-table-text hilos-table-text-height-4-5',
    )
    expect(reading.limited).toBe(true)
    expect(reading.caption).toBe(TABLE_TEXT_COPY.expand)
    expect(reading.accessibleName).toBe('Show in full: Name')
    expect(reading.showControl).toBe(true)
    act(() => {
      reading.setFocused(true)
      reading.toggle()
    })
    expect(reading.focused).toBe(true)
    expect(reading.accessibleName).toBe('Collapse: Name')
    box.scrollHeight = 40
    view.rerender(<Reading label="Updated name" />)
    await dom.layout()
    expect(reading.accessibleName).toBe('Collapse: Updated name')
    act(() => reading.toggle())
    await dom.layout()
    expect(reading.overflow).toBe(false)
    expect(reading.caption).toBe('Show in full')
    expect(reading.focused).toBe(true)
    expect(reading.showControl).toBe(true)
    act(() => reading.setFocused(false))
    expect(reading.showControl).toBe(false)
    act(() => reading.setFocused(true))
    view.rerender(<Reading column="parts" />)
    expect(reading.focused).toBe(false)
    view.rerender(<Reading eligible={false} />)
    expect(reading.limited).toBe(false)
    expect(reading.showControl).toBe(false)
    expect(reading.textClass).toBe('hilos-table-text')
  })

  it.each(['reset', 'refusal', 'window', 'reuse', 'departure'] as const)(
    'uses the existing geometry reader from its first commit through synchronous %s',
    async (change) => {
      const dom = browser()
      const source = owner()
      source.registration.dispose()
      const register = vi.fn(source.controller.geometry.register)
      const geometryOwner = { register }
      let adapter!: TableTextOverflow
      let readRecord!: () => TableGeometryRecord | null
      const rendered: (TableGeometryRecord | null)[] = []
      const box = { width: 100, height: 40, scrollWidth: 100, scrollHeight: 80 }
      function Integration() {
        const root = useRef<HTMLDivElement>(null)
        const table = useRef<HTMLTableElement>(null)
        const geometry = useTableViewportGeometry(
          geometryOwner,
          root,
          table,
          ['name'],
          (record) => source.controller.isTextRecordCurrent(record),
        )
        const row = source.controller.rows.get()[0]
        const reader = row
          ? geometry.recordReader('a', row.occurrence, 'wide')
          : () => null
        rendered.push(reader())
        useLayoutEffect(() => {
          readRecord = reader
        })
        return (
          <div ref={root}>
            {row && (
              <div
                ref={(node) =>
                  geometry.bind('a', row.occurrence, 'wide', 'main', node)
                }
              >
                <Harness
                  controller={source.controller}
                  readRecord={reader}
                  dom={dom}
                  box={box}
                  onCommit={(value) => {
                    adapter = value
                  }}
                />
              </div>
            )}
          </div>
        )
      }
      const view = render(<Integration />)
      expect(rendered[0]).toBeNull()
      expect(readRecord()).not.toBeNull()
      expect(register).toHaveBeenCalledTimes(1)
      await dom.layout()
      expect(adapter.overflow).toBe(true)
      const old = adapter
      const renders = rendered.length
      const command = vi.spyOn(source.controller, 'expandText')
      const intent = source.controller.expandedText.get()
      const observer = dom.resizes.find((observer) =>
        observer.targets.has(
          view.container.querySelector('.hilos-table-text')!,
        ),
      )!
      const ready = [...dom.readyCallbacks]
      act(() => {
        if (change === 'reset') source.controller.resetWindowRequests()
        if (change === 'refusal') source.controller.ingestRefusal('forbidden')
        if (change === 'window') snapshot(source.controller)
        if (change === 'reuse')
          source.controller.ingestOwnCreate(
            { rowKey: 'a', slots: { name: 'Reused' } },
            0,
            1,
            true,
            null,
            null,
          )
        if (change === 'departure')
          source.controller.ingestDelta({
            kind: 'row_removed',
            rowKey: 'a',
            reason: 'deleted',
            own: true,
          })
        expect(rendered).toHaveLength(renders)
        expect(readRecord()).toBeNull()
        expect(source.controller.expandedText.get()).toBe(intent)
        // The controller has already captured the outgoing live geometry.
        const before = dom.readRect.mock.calls.length
        observer.callback()
        for (const callback of ready) callback()
        old.setExpanded(true)
        expect(command).not.toHaveBeenCalled()
        expect(dom.readRect.mock.calls).toHaveLength(before)
      })
      view.unmount()
    },
  )
})
