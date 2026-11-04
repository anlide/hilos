import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, shallowRef } from 'vue'
import { TableViewportGeometry } from '@hilos/core/base'
import { useTableViewportGeometry } from '../src/useTableViewportGeometry.js'

// Rectangles exercise adapter unit conversion, synchronous wiring and lifetime.
// They do not simulate table layout, fonts or used collapsed borders; real CSS
// and the zoom-before-mount/departure browser matrix need separate browser proof.
function rect(top: number, height: number, width = 200, left = 0): DOMRect {
  return new DOMRect(left, top, width, height)
}

const mounted: { unmount(): void }[] = []
const cssZoom = Object.getOwnPropertyDescriptor(
  HTMLElement.prototype,
  'currentCSSZoom',
)
const documentFonts = Object.getOwnPropertyDescriptor(document, 'fonts')
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  if (cssZoom)
    Object.defineProperty(HTMLElement.prototype, 'currentCSSZoom', cssZoom)
  else Reflect.deleteProperty(HTMLElement.prototype, 'currentCSSZoom')
  if (documentFonts) Object.defineProperty(document, 'fonts', documentFonts)
  else Reflect.deleteProperty(document, 'fonts')
  document.body.replaceChildren()
})

function fixture(
  options: {
    cardVisible?: boolean
    wideVisible?: boolean
    tilesVisible?: boolean
    zoom?: number
    cardChrome?: boolean
    separateBorders?: boolean
    keyedRoot?: boolean
    wideContainerWidth?: number
  } = {},
) {
  const owner = new TableViewportGeometry()
  owner.replaceWindow(['a', 'b'])
  const registered = vi.fn(owner.view.register)
  const current = shallowRef<typeof owner.view>({ register: registered })
  const initial = current.value
  const departed = shallowRef(false)
  const working = shallowRef(true)
  const expanded = shallowRef(true)
  const redraw = shallowRef(0)
  const wideVisible = shallowRef(options.wideVisible ?? true)
  const cardVisible = shallowRef(options.cardVisible ?? true)
  const tilesVisible = shallowRef(options.tilesVisible ?? false)
  const zoom = shallowRef(options.zoom ?? 1)
  const fallbackHeight = shallowRef(48)
  const columnKeys = shallowRef<readonly string[]>(['name', 'state'])
  const fontSize = shallowRef('16px')
  const rootFontSize = shallowRef('16px')
  const tileFontSize = shallowRef('14px')
  const tileGutter = shallowRef('16.5px')
  const tileZoom = shallowRef(1)
  Object.defineProperty(HTMLElement.prototype, 'currentCSSZoom', {
    configurable: true,
    get(this: HTMLElement) {
      return (
        zoom.value *
        (this.dataset.box?.startsWith('tiles') ? tileZoom.value : 1)
      )
    },
  })
  const boxes = new Map<string, DOMRect>([
    ['root', rect(0, 600, 500)],
    ['wide-container', rect(0, 300, options.wideContainerWidth ?? 500)],
    ['table', rect(0, 300, 200.75)],
    ['header-name', rect(0, 20, 125.25)],
    ['header-state', rect(0, 20, 75.5, 125.25)],
    ['wide-main', rect(20, 40.25)],
    ['wide-work', rect(60.25, 18.5)],
    ['wide-detail', rect(78.75, 60.75)],
    ['card-main', rect(300, 210.5)],
    ['card-work', rect(446.5, 20.25)],
    ['card-detail', rect(370, 70.5)],
    ['wide-reason', rect(0, 22)],
    ['wide-shell', rect(0, 23)],
    ['card-reason', rect(0, 18)],
    ['tiles-grid', rect(300, 450, 866)],
    ['tiles-column', rect(300, 427, 216.5)],
    ['tiles-probe', rect(300, 50, 200)],
    ['tiles-main', rect(300, 410.5)],
    ['tiles-image', rect(300.75, 150.25)],
    ['tiles-detail', rect(570, 70.5)],
    ['tiles-work', rect(646.5, 20.25)],
    ['tiles-reason', rect(0, 20)],
  ])
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function (this: HTMLElement) {
      const id = this.dataset.box ?? ''
      if (
        (!wideVisible.value &&
          (id.startsWith('wide') ||
            id.startsWith('header') ||
            id === 'table')) ||
        (!cardVisible.value && id.startsWith('card')) ||
        (!tilesVisible.value && id.startsWith('tiles'))
      )
        return rect(0, 0, 0)
      const local = boxes.get(id) ?? rect(0, 0, 0)
      const effectiveZoom = this.currentCSSZoom
      return new DOMRect(
        local.x * effectiveZoom,
        local.y * effectiveZoom,
        local.width * effectiveZoom,
        local.height * effectiveZoom,
      )
    },
  )
  const computedStyle = window.getComputedStyle.bind(window)
  vi.spyOn(window, 'getComputedStyle').mockImplementation((element) => {
    const id = (element as HTMLElement).dataset.box
    return new Proxy(computedStyle(element), {
      get(target, property) {
        if (property === 'fontSize') {
          if (element === document.documentElement) return rootFontSize.value
          if (id === 'root') return fontSize.value
          if (id === 'tiles-probe') return tileFontSize.value
        }
        if (
          property === 'height' &&
          element.classList.contains('hilos-table-fallback')
        )
          return `${fallbackHeight.value}px`
        // Effective zoom comes from ancestors, while computed chrome is local.
        if (property === 'zoom') return '1'
        if (property === 'borderCollapse')
          return options.separateBorders ? 'separate' : 'collapse'
        if (property === 'borderSpacing') return '0px 3.25px'
        if (
          options.cardChrome &&
          id === 'card-main' &&
          (property === 'borderTopWidth' || property === 'borderBottomWidth')
        )
          return '0.75px'
        if (
          options.cardChrome &&
          id === 'card-body' &&
          (property === 'paddingTop' || property === 'paddingBottom')
        )
          return '8.25px'
        if (property === 'marginBottom' && id === 'card-main') return '8px'
        if (property === 'marginBottom' && id === 'card-detail') return '4px'
        if (property === 'marginTop' && id === 'card-work') return '6px'
        if (
          id === 'tiles-main' &&
          (property === 'borderTopWidth' || property === 'borderBottomWidth')
        )
          return '0.75px'
        if (
          id === 'tiles-body' &&
          (property === 'paddingTop' || property === 'paddingBottom')
        )
          return '8.25px'
        if (property === 'marginTop' && id === 'tiles-column')
          return tileGutter.value
        if (property === 'marginBottom' && id === 'tiles-detail') return '4px'
        if (property === 'marginTop' && id === 'tiles-work') return '6px'
        return Reflect.get(target, property)
      },
    })
  })
  const observed = new Set<Element>()
  let resize: (() => void) | undefined
  const resizeCallbacks: (() => void)[] = []
  const disconnect = vi.fn(() => observed.clear())
  vi.stubGlobal(
    'ResizeObserver',
    class {
      constructor(callback: () => void) {
        resize = callback
        resizeCallbacks.push(callback)
      }
      observe(node: Element) {
        observed.add(node)
      }
      unobserve(node: Element) {
        observed.delete(node)
      }
      disconnect = disconnect
    },
  )
  let pending: FrameRequestCallback | undefined
  const cancel = vi.fn(() => {
    pending = undefined
  })
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    pending = callback
    return 1
  })
  vi.stubGlobal('cancelAnimationFrame', cancel)
  let adapter!: ReturnType<typeof useTableViewportGeometry>
  const wrapper = mount(
    defineComponent({
      setup() {
        const root = shallowRef<HTMLElement | null>(null)
        const table = shallowRef<HTMLTableElement | null>(null)
        adapter = useTableViewportGeometry(
          () => current.value,
          root,
          table,
          () => columnKeys.value,
        )
        const bind =
          (
            projection: 'wide' | 'card' | 'tiles',
            part: 'main' | 'work' | 'detail',
          ) =>
          (element: unknown) =>
            adapter.bind(
              'a',
              owner.record('a')!.occurrence,
              projection,
              part,
              element,
            )
        return () => {
          void redraw.value
          void adapter.revision.value
          const rootProps = {
            ref: root,
            key: options.keyedRoot ? (current.value === initial ? 0 : 1) : 0,
            'data-box': 'root',
            tabindex: -1,
          }
          return h('div', rootProps, [
            h('div', { class: 'hilos-table-room-wide' }, [
              h(
                'div',
                {
                  class: 'hilos-table-replacement-row',
                  'data-box': 'wide-shell',
                },
                [
                  h('div', {
                    class: 'hilos-table-reason-twins',
                    'data-box': 'wide-reason',
                  }),
                ],
              ),
              h('div', { class: 'hilos-table-fallback' }),
            ]),
            h('div', { class: 'hilos-table-room-card' }, [
              h('div', {
                class: 'hilos-table-reason-twins',
                'data-box': 'card-reason',
              }),
              h('div', { class: 'hilos-table-fallback' }),
            ]),
            h('div', { 'data-box': 'wide-container' }, [
              h('table', { ref: table, 'data-box': 'table' }, [
                h('thead', [
                  h('tr', [
                    h('th', { 'data-box': 'header-name' }),
                    h('th', { 'data-box': 'header-state' }),
                  ]),
                ]),
                h('tbody', [
                  h(
                    'tr',
                    {
                      ref: (node: unknown) =>
                        adapter.bind(
                          'b',
                          owner.record('b')!.occurrence,
                          'wide',
                          'main',
                          node,
                        ),
                      'data-box': 'wide-main',
                    },
                    [h('td', 'Remaining record')],
                  ),
                  h(
                    'tr',
                    { ref: bind('wide', 'main'), 'data-box': 'wide-main' },
                    [
                      h(
                        'td',
                        departed.value
                          ? [
                              h(
                                'div',
                                {
                                  class: 'hilos-table-record-space',
                                  'data-allocation': adapter.recordPixels(
                                    'a',
                                    owner.record('a')!.occurrence,
                                    'wide',
                                  ),
                                },
                                h(
                                  'span',
                                  { 'data-box': 'wide-reason' },
                                  'Removed',
                                ),
                              ),
                            ]
                          : 'live wide values',
                      ),
                    ],
                  ),
                  !departed.value && working.value
                    ? h(
                        'tr',
                        { ref: bind('wide', 'work'), 'data-box': 'wide-work' },
                        [h('td', 'work payload')],
                      )
                    : null,
                  !departed.value && expanded.value
                    ? h(
                        'tr',
                        {
                          ref: bind('wide', 'detail'),
                          'data-box': 'wide-detail',
                        },
                        [h('td', 'detail values')],
                      )
                    : null,
                ]),
              ]),
            ]),
            h(
              'div',
              { ref: bind('card', 'main'), 'data-box': 'card-main' },
              h(
                'div',
                { class: 'card-body', 'data-box': 'card-body' },
                departed.value
                  ? [
                      h(
                        'div',
                        {
                          class: 'hilos-table-record-space',
                          'data-card-allocation': adapter.recordPixels(
                            'a',
                            owner.record('a')!.occurrence,
                            'card',
                          ),
                        },
                        h('span', { 'data-box': 'card-reason' }, 'Removed'),
                      ),
                    ]
                  : [
                      'live card values',
                      expanded.value
                        ? h(
                            'div',
                            {
                              ref: bind('card', 'detail'),
                              'data-box': 'card-detail',
                            },
                            'detail values',
                          )
                        : null,
                      working.value
                        ? h(
                            'div',
                            {
                              ref: bind('card', 'work'),
                              'data-box': 'card-work',
                            },
                            'work payload',
                          )
                        : null,
                    ],
              ),
            ),
            h('div', { 'data-box': 'tiles-grid' }, [
              h('div', { class: 'col', 'data-box': 'tiles-column' }, [
                h(
                  'div',
                  {
                    class: 'hilos-table-room-tiles',
                    'data-box': 'tiles-probe',
                  },
                  [
                    h('div', {
                      class: 'hilos-table-reason-twins',
                      'data-box': 'tiles-reason',
                    }),
                    h('div', { class: 'hilos-table-fallback' }),
                  ],
                ),
                h(
                  'div',
                  { ref: bind('tiles', 'main'), 'data-box': 'tiles-main' },
                  [
                    !departed.value
                      ? h('div', { 'data-box': 'tiles-image' }, 'image slot')
                      : null,
                    h(
                      'div',
                      { class: 'card-body', 'data-box': 'tiles-body' },
                      departed.value
                        ? h(
                            'div',
                            {
                              class: 'hilos-table-record-space',
                              'data-tile-allocation': adapter.recordPixels(
                                'a',
                                owner.record('a')!.occurrence,
                                'tiles',
                              ),
                            },
                            h(
                              'span',
                              { 'data-box': 'tiles-reason' },
                              'Removed',
                            ),
                          )
                        : [
                            'live tile values',
                            expanded.value
                              ? h(
                                  'div',
                                  {
                                    ref: bind('tiles', 'detail'),
                                    'data-box': 'tiles-detail',
                                  },
                                  'tile details',
                                )
                              : null,
                            working.value
                              ? h(
                                  'div',
                                  {
                                    ref: bind('tiles', 'work'),
                                    'data-box': 'tiles-work',
                                  },
                                  'tile work',
                                )
                              : null,
                          ],
                    ),
                  ],
                ),
              ]),
            ]),
          ])
        }
      },
    }),
    { attachTo: document.body },
  )
  mounted.push(wrapper)
  function depart() {
    owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
      owner.depart('a', 'deleted')
      departed.value = true
    })
  }
  return {
    owner,
    current,
    registration: registered.mock.results[0]!.value,
    wrapper,
    adapter,
    boxes,
    working,
    expanded,
    redraw,
    wideVisible,
    cardVisible,
    tilesVisible,
    zoom,
    fallbackHeight,
    columnKeys,
    fontSize,
    rootFontSize,
    tileFontSize,
    tileGutter,
    tileZoom,
    observed,
    disconnect,
    cancel,
    depart,
    resize: () => resize?.(),
    resizeCallbacks,
    pendingFrame: () => pending,
    frame: () => {
      const callback = pending
      pending = undefined
      callback?.(0)
    },
    hasFrame: () => pending !== undefined,
  }
}

describe('useTableViewportGeometry (numeric DOM stubs)', () => {
  it.each([1, 1.25, 1.5])(
    'captures the complete tile box and the surviving column gutter once at zoom %s',
    async (zoom) => {
      const f = fixture({ tilesVisible: true, zoom })
      await nextTick()
      expect(f.registration.record('a', 'tiles')?.live).toEqual({
        main: 313.75,
        work: 20.25,
        detail: 70.5,
        gap: 22.5,
        total: 427,
      })
      expect(f.registration.record('a', 'card')?.live?.total).toBe(218.5)
      expect(f.adapter.reasonFloors.value).toEqual({
        wide: 22,
        card: 18,
        tiles: 20,
      })
      expect(
        f.observed.has(f.wrapper.get('[data-box="tiles-main"]').element),
      ).toBe(true)
      expect(
        f.observed.has(f.wrapper.get('[data-box="tiles-work"]').element),
      ).toBe(true)
      expect(
        f.observed.has(f.wrapper.get('[data-box="tiles-detail"]').element),
      ).toBe(true)
      // Capture the changed main box synchronously before destroying image/body.
      f.boxes.set('tiles-main', rect(300, 448.25))
      f.depart()
      expect(f.registration.record('a', 'tiles')?.retained).toBe(464.75)
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'tiles'),
      ).toBe(430.25)
      await nextTick()
      expect(
        f.wrapper
          .get('[data-tile-allocation]')
          .attributes('data-tile-allocation'),
      ).toBe('430.25')
      expect(f.wrapper.get('[data-box="tiles-main"]').text()).toBe('Removed')
      expect(f.wrapper.find('[data-box="tiles-image"]').exists()).toBe(false)
      f.tilesVisible.value = false
      f.resize()
      f.frame()
      await nextTick()
      expect(f.registration.record('a', 'tiles')?.retained).toBe(464.75)
    },
  )

  it('allocates only a tile shell plus its Sass fallback on first visibility, then grows only for the reason', async () => {
    const f = fixture({ tilesVisible: false })
    f.depart()
    await nextTick()
    expect(f.registration.record('a', 'tiles')?.retained).toBeNull()
    f.tilesVisible.value = true
    f.resize()
    f.frame()
    await nextTick()
    expect(f.registration.record('a', 'tiles')?.retained).toBe(82.5)
    expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
    f.boxes.set('tiles-reason', rect(0, 300.25))
    f.fallbackHeight.value = 80
    f.resize()
    f.frame()
    await nextTick()
    expect(f.registration.record('a', 'tiles')?.retained).toBe(334.75)
    f.boxes.set('tiles-reason', rect(0, 20))
    f.fallbackHeight.value = 48
    f.resize()
    f.frame()
    await nextTick()
    expect(f.registration.record('a', 'tiles')?.retained).toBe(334.75)
  })

  it.each(['frame', 'post-commit'] as const)(
    'invalidates only tiles for width/count, gutter, font and zoom changes through %s',
    async (entry) => {
      const f = fixture({ tilesVisible: true })
      await nextTick()
      f.depart()
      await nextTick()
      const wide = f.registration.layout('wide')
      const card = f.registration.layout('card')
      const tracks = f.adapter.tracks.value
      const widths = f.adapter.trackWidths.value
      const wideTicket = f.registration.measurement('b', 'wide')
      for (const change of ['width/count', 'gutter', 'font', 'zoom']) {
        const previous = f.registration.layout('tiles')
        if (change === 'width/count') {
          f.boxes.set('tiles-probe', rect(300, 50, 250))
          f.boxes.set('tiles-column', rect(300, 427, 288.6666666667))
        } else if (change === 'gutter') f.tileGutter.value = '20px'
        else if (change === 'font') f.tileFontSize.value = '18px'
        else f.tileZoom.value = 1.25
        if (entry === 'frame') {
          f.resize()
          f.frame()
        } else f.redraw.value++
        await nextTick()
        expect(f.registration.layout('tiles')).not.toBe(previous)
        expect(f.registration.layout('wide')).toBe(wide)
        expect(f.registration.layout('card')).toBe(card)
        expect(f.registration.measurement('b', 'wide')).toBe(wideTicket)
        expect(f.adapter.tracks.value).toBe(tracks)
        expect(f.adapter.trackWidths.value).toBe(widths)
        expect(f.registration.record('a', 'wide')?.retained).toBe(119.5)
        expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
        expect(f.registration.record('a', 'tiles')?.retained).toBe(427)
      }
    },
  )

  it.each(['frame', 'post-commit'] as const)(
    'keeps wide/card layouts, tracks and departures through a hidden-visible-hidden tile cycle via %s',
    async (entry) => {
      const f = fixture({ wideContainerWidth: 460 })
      await nextTick()
      f.depart()
      await nextTick()
      const wide = f.registration.layout('wide')
      const card = f.registration.layout('card')
      const tracks = f.adapter.tracks.value
      const widths = f.adapter.trackWidths.value
      const wideTicket = f.registration.measurement('b', 'wide')
      for (const visible of [true, false]) {
        const tiles = f.registration.layout('tiles')
        f.tilesVisible.value = visible
        f.wideVisible.value = !visible
        f.cardVisible.value = !visible
        if (entry === 'frame') {
          f.resize()
          f.frame()
        } else f.redraw.value++
        await nextTick()
        expect(f.registration.layout('tiles')).not.toBe(tiles)
        expect(f.registration.layout('wide')).toBe(wide)
        expect(f.registration.layout('card')).toBe(card)
        expect(f.registration.measurement('b', 'wide')).toBe(wideTicket)
        expect(f.adapter.tracks.value).toBe(tracks)
        expect(f.adapter.trackWidths.value).toBe(widths)
        expect(f.registration.record('a', 'wide')?.retained).toBe(119.5)
        expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
        expect(f.registration.record('a', 'tiles')?.retained).toBe(82.5)
      }
    },
  )

  it.each([
    'wide-container',
    'root',
    'font',
    'root-font',
    'font-completion',
    'column-keys',
  ])(
    'releases tracks and relatches after commit for a real %s change',
    async (change) => {
      const fonts = new EventTarget()
      if (change === 'font-completion')
        Object.defineProperty(document, 'fonts', {
          configurable: true,
          value: Object.assign(fonts, { ready: Promise.resolve() }),
        })
      const f = fixture({ tilesVisible: true })
      await nextTick()
      f.depart()
      await nextTick()
      const wide = f.registration.layout('wide')
      const card = f.registration.layout('card')
      const tiles = f.registration.layout('tiles')
      if (change === 'wide-container')
        f.boxes.set('wide-container', rect(0, 300, 375))
      else if (change === 'root') f.boxes.set('root', rect(0, 600, 375))
      else if (change === 'font') f.fontSize.value = '18px'
      else if (change === 'root-font') f.rootFontSize.value = '20px'
      else if (change === 'column-keys')
        f.columnKeys.value = ['name', 'renamed']
      f.boxes.set('header-name', rect(0, 20, 100.25))
      f.boxes.set('header-state', rect(0, 20, 75.5, 100.25))
      f.boxes.set('table', rect(0, 300, 175.75))
      if (change === 'font-completion') {
        fonts.dispatchEvent(new Event('loadingdone'))
        expect(f.adapter.tracks.value).toBeNull()
        await nextTick()
      }
      f.resize()
      f.frame()
      if (change === 'column-keys') {
        // The column watcher runs before Vue commits the changed structure.
        await nextTick()
      } else if (change !== 'font-completion')
        expect(f.adapter.tracks.value).toBeNull()
      expect(f.registration.layout('wide')).not.toBe(wide)
      expect(f.registration.layout('card')).not.toBe(card)
      if (change === 'wide-container')
        expect(f.registration.layout('tiles')).toBe(tiles)
      else expect(f.registration.layout('tiles')).not.toBe(tiles)
      await nextTick()
      expect(f.adapter.tracks.value).toEqual({
        columnKeys:
          change === 'column-keys' ? ['name', 'renamed'] : ['name', 'state'],
        widths: [100.25, 75.5],
        tableWidth: 175.75,
      })
      expect(f.registration.record('a', 'wide')?.retained).toBe(119.5)
      expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
      expect(f.registration.record('a', 'tiles')?.retained).toBe(427)
    },
  )

  it('keeps tile exact readers local to the window, occurrence, registration and mount', async () => {
    const f = fixture({ tilesVisible: true })
    const old = f.owner.record('a')!
    const read = f.adapter.recordReader('a', old.occurrence, 'tiles')
    expect(read()).toBe(old)
    f.owner.replaceWindow(['a', 'b'])
    expect(read()).toBeNull()
    f.redraw.value++
    await nextTick()
    const fresh = f.adapter.recordReader(
      'a',
      f.owner.record('a')!.occurrence,
      'tiles',
    )
    expect(fresh()).toBe(f.owner.record('a'))
    const next = new TableViewportGeometry()
    next.replaceWindow(['a', 'b'])
    f.current.value = next.view
    expect(fresh()).toBeNull()
    f.wrapper.unmount()
    f.resize()
    f.frame()
    expect(f.observed.size).toBe(0)
    expect(f.hasFrame()).toBe(false)
    expect(fresh()).toBeNull()
  })

  it('makes already queued resize and frame callbacks inert after controller replacement', () => {
    const f = fixture({ tilesVisible: true })
    const lateResize = f.resizeCallbacks[0]!
    f.resize()
    const lateFrame = f.pendingFrame()!
    const next = new TableViewportGeometry()
    next.replaceWindow(['a', 'b'])
    const registered = vi.fn(next.view.register)
    f.current.value = { register: registered }
    const registration = registered.mock.results[0]!.value
    const layout = registration.layout('tiles')
    const scheduled = f.pendingFrame()
    lateResize()
    lateFrame(0)
    expect(f.pendingFrame()).toBe(scheduled)
    expect(registration.layout('tiles')).toBe(layout)
    expect(registration.record('a', 'tiles')?.live).toBeNull()
    f.wrapper.unmount()
    lateResize()
    lateFrame(0)
    expect(f.hasFrame()).toBe(false)
  })

  it('reads exact live identities and retires saved readers before DOM replacement', async () => {
    const f = fixture()
    const identity = f.registration.measurement('a', 'wide')!.record
    const wide = f.adapter.recordReader('a', identity.occurrence, 'wide')
    const card = f.adapter.recordReader('a', identity.occurrence, 'card')
    expect(wide()).toBe(identity)
    expect(card()).toBe(identity)
    expect(f.adapter.recordReader('a', -1, 'wide')()).toBeNull()
    f.adapter.layoutChanged()
    expect(wide()).toBe(identity)
    f.owner.invalidateWindow()
    expect(f.owner.record('a')!.occurrence).toBe(identity.occurrence)
    expect(f.owner.record('a')).not.toBe(identity)
    expect(wide()).toBeNull()
    expect(card()).toBeNull()
    // Ref callbacks from the outgoing DOM may still run on a Vue update.
    f.redraw.value++
    await nextTick()
    expect(wide()).toBeNull()
    f.owner.replaceWindow(['a', 'b'])
    expect(wide()).toBeNull()
    const fresh = f.adapter.recordReader(
      'a',
      f.owner.record('a')!.occurrence,
      'wide',
    )
    expect(fresh()).toBe(f.owner.record('a'))
    f.depart()
    expect(fresh()).toBeNull()
    f.wrapper.unmount()
    expect(fresh()).toBeNull()
  })

  it('invalidates old readers synchronously on controller replacement', () => {
    const f = fixture()
    const read = f.adapter.recordReader(
      'a',
      f.owner.record('a')!.occurrence,
      'wide',
    )
    const next = new TableViewportGeometry()
    next.replaceWindow(['a', 'b'])
    f.current.value = next.view
    expect(read()).toBeNull()
    const current = f.adapter.recordReader(
      'a',
      next.record('a')!.occurrence,
      'wide',
    )
    expect(current()).toBe(next.record('a'))
    next.dispose()
    expect(current()).toBeNull()
  })

  it.each(['measure', 'capture', 'hidden'] as const)(
    'waits for connected live wide content after initial empty %s',
    async (entry) => {
      const owner = new TableViewportGeometry()
      owner.replaceWindow([])
      const live = shallowRef(false)
      const visible = shallowRef(entry !== 'hidden')
      const retired = shallowRef(false)
      let adapter!: ReturnType<typeof useTableViewportGeometry>
      let registration!: ReturnType<typeof owner.view.register>
      vi.spyOn(
        HTMLElement.prototype,
        'getBoundingClientRect',
      ).mockImplementation(function (this: HTMLElement) {
        if (!visible.value) return rect(0, 0, 0)
        if (this.tagName === 'TH')
          return (this as HTMLTableCellElement).cellIndex === 0
            ? rect(0, 20, 125.25)
            : rect(0, 20, 75.5, 125.25)
        return rect(0, 40, 200.75)
      })
      const wrapper = mount(
        {
          setup() {
            const root = shallowRef<HTMLElement | null>(null)
            const table = shallowRef<HTMLTableElement | null>(null)
            adapter = useTableViewportGeometry(
              () => ({
                register(capture) {
                  registration = owner.view.register(capture)
                  return registration
                },
              }),
              root,
              table,
              () => ['name', 'state'],
            )
            return () =>
              h('div', { ref: root, 'data-visible': visible.value }, [
                h('table', { ref: table }, [
                  h('thead', [h('tr', [h('th', 'Name'), h('th', 'State')])]),
                  h(
                    'tbody',
                    live.value
                      ? [
                          h(
                            'tr',
                            {
                              ref: (node: unknown) =>
                                adapter.bind(
                                  'a',
                                  owner.record('a')!.occurrence,
                                  'wide',
                                  'main',
                                  node,
                                ),
                            },
                            [
                              h(
                                'td',
                                retired.value ? 'Removed' : 'Live content',
                              ),
                            ],
                          ),
                        ]
                      : [],
                  ),
                ]),
              ])
          },
        },
        { attachTo: document.body },
      )
      mounted.push(wrapper)
      await nextTick()
      if (entry === 'capture') {
        registration.invalidateLayout('wide')
        owner.withPresentationUpdate({ kind: 'window' }, () => {})
      }
      expect(adapter.tracks.value, 'no header-only latch').toBeNull()
      owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () =>
        owner.addRecord('a'),
      )
      expect(
        adapter.tracks.value,
        'capture still sees the empty DOM',
      ).toBeNull()
      live.value = true
      await nextTick()
      if (entry === 'hidden') {
        expect(
          adapter.tracks.value,
          'hidden live rows do not latch zero tracks',
        ).toBeNull()
        visible.value = true
        await nextTick()
      }
      const tracks = adapter.tracks.value
      expect(tracks).toEqual({
        columnKeys: ['name', 'state'],
        widths: [125.25, 75.5],
        tableWidth: 200.75,
      })
      owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
        owner.depart('a', 'deleted')
        retired.value = true
      })
      await nextTick()
      expect(
        adapter.tracks.value,
        'all-placeholder retains the live latch',
      ).toBe(tracks)
      owner.addRecord('b')
      await nextTick()
      expect(
        adapter.tracks.value,
        'append retains the calibrated live latch',
      ).toBe(tracks)
    },
  )

  it.each([1.25, 1.5])(
    'reads fractional local extents, tracks and reason twins with ancestor zoom %s already active on mount',
    async (zoom) => {
      const f = fixture({ zoom, cardChrome: true })
      await nextTick()
      expect(f.registration.record('a', 'wide')?.live).toEqual({
        main: 40.25,
        work: 18.5,
        detail: 60.75,
        gap: 0,
        total: 119.5,
      })
      expect(f.registration.record('a', 'card')?.live).toEqual({
        main: 113.75,
        work: 20.25,
        detail: 70.5,
        gap: 14,
        total: 218.5,
      })
      expect(f.adapter.tracks.value).toEqual({
        columnKeys: ['name', 'state'],
        widths: [125.25, 75.5],
        tableWidth: 200.75,
      })
      expect(f.adapter.reasonFloors.value).toEqual({
        wide: 22,
        card: 18,
        tiles: 0,
      })
      f.depart()
      // The first replacement already has local numbers, before any observer.
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
      ).toBe(118.5)
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
      ).toBe(192.5)
      await nextTick()
      expect(
        f.wrapper.get('[data-allocation]').attributes('data-allocation'),
      ).toBe('118.5')
      expect(
        f.wrapper
          .get('[data-card-allocation]')
          .attributes('data-card-allocation'),
      ).toBe('192.5')
    },
  )

  it('captures a newly zoomed live layout synchronously before departure without an observer pass', async () => {
    const f = fixture()
    await nextTick()
    f.zoom.value = 1.25
    f.boxes.set('wide-detail', rect(78.75, 90.5))
    f.boxes.set('card-main', rect(300, 230.75))
    f.depart()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(148.25)
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(230.75)
    await nextTick()
    expect(
      f.wrapper.get('[data-allocation]').attributes('data-allocation'),
    ).toBe('148.25')
    expect(
      f.wrapper
        .get('[data-card-allocation]')
        .attributes('data-card-allocation'),
    ).toBe('230.75')
  })

  it.each([1.25, 1.5])(
    'keeps local work and intervening gaps once through early work removal at zoom %s',
    async (zoom) => {
      const f = fixture({ zoom })
      await nextTick()
      f.boxes.set('wide-work', rect(62.75, 18.5))
      f.boxes.set('wide-detail', rect(84.5, 60.75))
      f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
        f.owner.reserveWork('a')
        f.working.value = false
      })
      expect(f.registration.record('a', 'wide')?.workReserve).toEqual({
        work: 18.5,
        gap: 2.5,
        total: 21,
      })
      expect(f.registration.record('a', 'card')?.workReserve).toEqual({
        work: 20.25,
        gap: 2,
        total: 22.25,
      })
      f.boxes.set('wide-detail', rect(63.5, 60.75))
      f.boxes.set('card-main', rect(300, 188.25))
      await nextTick()
      expect(f.adapter.workPixels('a', 'wide')).toBe(21)
      expect(f.adapter.workPixels('a', 'card')).toBe(22.25)
      f.depart()
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
      ).toBe(124.25)
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
      ).toBe(210.5)
    },
  )

  it('counts computed separate-border spacing once in local units, including the last record gap', async () => {
    const f = fixture({ zoom: 1.25, separateBorders: true })
    await nextTick()
    expect(f.registration.record('a', 'wide')?.live).toEqual({
      main: 40.25,
      work: 18.5,
      detail: 60.75,
      gap: 3.25,
      total: 122.75,
    })
    f.depart()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(118.5)
  })

  it('grows only for the shown local reason and shell, retaining that allocation through further zoom changes', async () => {
    const f = fixture({ zoom: 1.25, cardChrome: true })
    await nextTick()
    f.depart()
    await nextTick()
    f.boxes.set('wide-reason', rect(0, 150.5))
    f.boxes.set('wide-shell', rect(0, 151.5))
    f.boxes.set('card-reason', rect(0, 260.25))
    f.redraw.value++
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(150.5)
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(260.25)
    f.zoom.value = 1.5
    f.boxes.set('wide-reason', rect(0, 22))
    f.boxes.set('wide-shell', rect(0, 23))
    f.boxes.set('card-reason', rect(0, 18))
    f.redraw.value++
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(150.5)
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(260.25)
  })

  it('allocates hidden projection fallback only on first display under zoom and never rescales its history', async () => {
    const f = fixture({ zoom: 1.25, cardVisible: false, cardChrome: true })
    await nextTick()
    f.depart()
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBeUndefined()
    f.cardVisible.value = true
    f.zoom.value = 1.5
    f.redraw.value++
    await nextTick()
    // The 48px Sass fallback excludes 18px chrome and the 8px outer gap.
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(48)
    f.zoom.value = 1.25
    f.fallbackHeight.value = 96
    f.redraw.value++
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(48)
  })

  it('invalidates tracks when effective zoom changes with the same local container width, retaining departed extents', async () => {
    const f = fixture({ zoom: 1.25 })
    await nextTick()
    f.depart()
    await nextTick()
    f.zoom.value = 1.5
    f.boxes.set('header-name', rect(0, 20, 100.25))
    f.boxes.set('header-state', rect(0, 20, 75.5, 100.25))
    f.boxes.set('table', rect(0, 300, 175.75))
    f.resize()
    f.frame()
    expect(f.adapter.tracks.value).toBeNull()
    await nextTick()
    expect(f.adapter.tracks.value).toEqual({
      columnKeys: ['name', 'state'],
      widths: [100.25, 75.5],
      tableWidth: 175.75,
    })
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(118.5)
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(210.5)
  })

  it('captures all old connected parts before the first destructive mutation, independently by projection', async () => {
    const f = fixture()
    await nextTick()
    // New live geometry after the previous commit must be read synchronously.
    f.boxes.set('wide-detail', rect(78.75, 90.5))
    f.depart()
    // Retention is already available before the replacement DOM is committed.
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(148.25)
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(210.5)
    await nextTick()
    expect(
      f.wrapper.get('[data-allocation]').attributes('data-allocation'),
    ).toBe('148.25')
    expect(f.wrapper.text()).not.toMatch(/live|payload|detail values/)
    expect(
      [...f.observed].some((node) => node.textContent === 'work payload'),
    ).toBe(false)
  })

  it('latches tracks across departure, append and all-placeholder state', async () => {
    const f = fixture()
    await nextTick()
    const first = f.adapter.tracks.value
    expect(first).toEqual({
      columnKeys: ['name', 'state'],
      widths: [125.25, 75.5],
      tableWidth: 200.75,
    })
    f.depart()
    f.owner.depart('b', 'left_set')
    f.owner.addRecord('c')
    f.boxes.set('header-name', rect(0, 20, 90))
    f.redraw.value++
    await nextTick()
    expect(f.adapter.tracks.value).toBe(first)
  })

  it.each([
    ['post-commit', false],
    ['frame', false],
    ['post-commit', true],
    ['frame', true],
  ] as const)(
    'detects a layout change after replacement: first=%s, keyed root=%s',
    async (first, keyedRoot) => {
      const f = fixture({ cardVisible: false, keyedRoot })
      await nextTick()
      const root = f.wrapper.element
      const replacement = new TableViewportGeometry()
      replacement.replaceWindow(['a', 'b'])
      f.current.value = replacement.view
      if (first === 'frame') f.frame()
      await nextTick()
      expect(f.wrapper.element === root).toBe(!keyedRoot)
      expect(f.adapter.tracks.value?.tableWidth).toBe(200.75)
      f.boxes.set('root', rect(0, 600, 375))
      f.boxes.set('header-name', rect(0, 20, 100.25))
      f.boxes.set('header-state', rect(0, 20, 75.5, 100.25))
      f.boxes.set('table', rect(0, 300, 175.75))
      f.resize()
      f.frame()
      if (keyedRoot && first === 'post-commit') {
        // The old root's frame must not sample its replacement. A fresh frame
        // must still process the resize coalesced while that frame was pending.
        expect(f.adapter.tracks.value?.tableWidth).toBe(200.75)
        expect(f.hasFrame()).toBe(true)
        f.frame()
      }
      // Release fixed tracks before Vue commits and measures the auto layout.
      expect(f.adapter.tracks.value).toBeNull()
      await nextTick()
      expect(f.adapter.tracks.value).toEqual({
        columnKeys: ['name', 'state'],
        widths: [100.25, 75.5],
        tableWidth: 175.75,
      })
    },
  )

  it('waits for a visible replacement sample and keeps it through its first frame', async () => {
    const f = fixture({ cardVisible: false })
    await nextTick()
    const replacement = new TableViewportGeometry()
    replacement.replaceWindow(['a', 'b'])
    f.boxes.set('root', rect(0, 0, 0))
    f.wideVisible.value = false
    f.current.value = replacement.view
    await nextTick()
    expect(f.adapter.tracks.value).toBeNull()
    f.frame()
    expect(f.adapter.tracks.value).toBeNull()
    f.boxes.set('root', rect(0, 600, 375))
    f.boxes.set('header-name', rect(0, 20, 100.25))
    f.boxes.set('header-state', rect(0, 20, 75.5, 100.25))
    f.boxes.set('table', rect(0, 300, 175.75))
    f.wideVisible.value = true
    f.redraw.value++
    await nextTick()
    const first = f.adapter.tracks.value
    expect(first).toEqual({
      columnKeys: ['name', 'state'],
      widths: [100.25, 75.5],
      tableWidth: 175.75,
    })
    f.resize()
    f.frame()
    expect(f.adapter.tracks.value).toBe(first)
    await nextTick()
    expect(f.adapter.tracks.value).toBe(first)
  })

  it('remeasures tracks on a real container change while keeping historical departed pixels', async () => {
    const f = fixture()
    await nextTick()
    f.depart()
    await nextTick()
    const retained = f.adapter.recordPixels(
      'a',
      f.owner.record('a')!.occurrence,
      'wide',
    )
    f.boxes.set('root', rect(0, 600, 375))
    f.boxes.set('header-name', rect(0, 20, 100.25))
    f.boxes.set('header-state', rect(0, 20, 75.5, 100.25))
    f.boxes.set('table', rect(0, 300, 175.75))
    f.resize()
    f.frame()
    // The old latch goes before the next auto-layout commit is sampled.
    expect(f.adapter.tracks.value).toBeNull()
    await nextTick()
    expect(f.adapter.tracks.value?.widths).toEqual([100.25, 75.5])
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(retained)
  })

  it('does not overwrite a visible cache with hidden zeros; a hidden live update invalidates it', async () => {
    const f = fixture()
    await nextTick()
    f.cardVisible.value = false
    f.redraw.value++
    await nextTick()
    // No destructive value update: the last visible cache is still usable.
    f.depart()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(210.5)
    await nextTick()
  })

  it('uses a projection-local Sass fallback after a hidden live update, then keeps it through later layout changes', async () => {
    const f = fixture()
    await nextTick()
    f.cardVisible.value = false
    f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {})
    f.depart()
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBeUndefined()
    f.cardVisible.value = true
    f.boxes.set('card-main', rect(0, 800))
    f.boxes.set('root', rect(0, 600, 375))
    f.resize()
    f.frame()
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(48)
  })

  it('allows live shrink, but keeps departed historical allocation and only grows it for the reason', async () => {
    const f = fixture()
    await nextTick()
    f.boxes.set('wide-detail', rect(78.75, 25.5))
    f.redraw.value++
    await nextTick()
    f.depart()
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(83.25)
    f.boxes.set('wide-reason', rect(0, 150))
    f.boxes.set('wide-shell', rect(0, 151))
    f.redraw.value++
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(150)
    f.boxes.set('wide-reason', rect(0, 22))
    f.boxes.set('wide-shell', rect(0, 23))
    f.redraw.value++
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(150)
  })

  it('cancels scheduled reads and releases every target and callback on unmount', async () => {
    const f = fixture()
    await nextTick()
    f.resize()
    expect(f.hasFrame()).toBe(true)
    f.wrapper.unmount()
    expect(f.cancel).toHaveBeenCalled()
    expect(f.observed.size).toBe(0)
    const read = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    read.mockClear()
    f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {})
    expect(read).not.toHaveBeenCalled()
  })

  it('does not read document focus from a detached view during a queued update', async () => {
    const f = fixture()
    await nextTick()
    f.wrapper.element.remove()
    const focus = vi.spyOn(document, 'activeElement', 'get')
    f.redraw.value++
    await nextTick()
    expect(focus).not.toHaveBeenCalled()
  })

  it('keeps early work numerically and merges it once with the remaining live parts', async () => {
    const f = fixture()
    await nextTick()
    f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
      f.owner.reserveWork('a')
      f.working.value = false
    })
    f.boxes.set('wide-detail', rect(60.25, 60.75))
    f.boxes.set('card-main', rect(300, 188.25))
    await nextTick()
    expect(f.adapter.workPixels('a', 'wide')).toBe(18.5)
    // The 6px leading margin shares 4px already owned by detail.
    expect(f.adapter.workPixels('a', 'card')).toBe(22.25)
    expect(f.wrapper.text()).not.toContain('work payload')
    f.depart()
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(118.5)
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(210.5)
  })

  it('detaches the previous controller registration without disposing its owner', async () => {
    const f = fixture()
    await nextTick()
    const next = new TableViewportGeometry()
    next.replaceWindow(['a'])
    f.current.value = next.view
    await nextTick()
    const read = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    read.mockClear()
    f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {})
    expect(read).not.toHaveBeenCalled()
    const other = f.owner.view.register(() => ({ records: [] }))
    expect(other.measurement('a', 'wide')).not.toBeNull()
    other.dispose()
  })
})
