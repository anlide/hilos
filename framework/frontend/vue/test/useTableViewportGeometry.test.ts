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
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  if (cssZoom)
    Object.defineProperty(HTMLElement.prototype, 'currentCSSZoom', cssZoom)
  else Reflect.deleteProperty(HTMLElement.prototype, 'currentCSSZoom')
  document.body.replaceChildren()
})

function fixture(
  options: {
    cardVisible?: boolean
    wideVisible?: boolean
    zoom?: number
    cardChrome?: boolean
    separateBorders?: boolean
  } = {},
) {
  const owner = new TableViewportGeometry()
  owner.replaceWindow(['a', 'b'])
  const registered = vi.fn(owner.view.register)
  const current = shallowRef<typeof owner.view>({ register: registered })
  const departed = shallowRef(false)
  const working = shallowRef(true)
  const expanded = shallowRef(true)
  const redraw = shallowRef(0)
  const wideVisible = shallowRef(options.wideVisible ?? true)
  const cardVisible = shallowRef(options.cardVisible ?? true)
  const zoom = shallowRef(options.zoom ?? 1)
  const fallbackHeight = shallowRef(48)
  Object.defineProperty(HTMLElement.prototype, 'currentCSSZoom', {
    configurable: true,
    get: () => zoom.value,
  })
  const boxes = new Map<string, DOMRect>([
    ['root', rect(0, 600, 500)],
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
  ])
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function (this: HTMLElement) {
      const id = this.dataset.box ?? ''
      if (
        (!wideVisible.value &&
          (id.startsWith('wide') ||
            id.startsWith('header') ||
            id === 'table')) ||
        (!cardVisible.value && id.startsWith('card'))
      )
        return rect(0, 0, 0)
      const local = boxes.get(id) ?? rect(0, 0, 0)
      return new DOMRect(
        local.x * zoom.value,
        local.y * zoom.value,
        local.width * zoom.value,
        local.height * zoom.value,
      )
    },
  )
  const computedStyle = window.getComputedStyle.bind(window)
  vi.spyOn(window, 'getComputedStyle').mockImplementation((element) => {
    const id = (element as HTMLElement).dataset.box
    return new Proxy(computedStyle(element), {
      get(target, property) {
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
        return Reflect.get(target, property)
      },
    })
  })
  const observed = new Set<Element>()
  let resize: (() => void) | undefined
  const disconnect = vi.fn(() => observed.clear())
  vi.stubGlobal(
    'ResizeObserver',
    class {
      constructor(callback: () => void) {
        resize = callback
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
          () => ['name', 'state'],
        )
        const bind =
          (projection: 'wide' | 'card', part: 'main' | 'work' | 'detail') =>
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
          return h('div', { ref: root, 'data-box': 'root', tabindex: -1 }, [
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
    zoom,
    fallbackHeight,
    observed,
    disconnect,
    cancel,
    depart,
    resize: () => resize?.(),
    frame: () => {
      const callback = pending
      pending = undefined
      callback?.(0)
    },
    hasFrame: () => pending !== undefined,
  }
}

describe('useTableViewportGeometry (numeric DOM stubs)', () => {
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
      expect(f.adapter.reasonFloors.value).toEqual({ wide: 22, card: 18 })
      f.depart()
      // The first replacement already has local numbers, before any observer.
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
      ).toBe(118.5)
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
      ).toBe(218.5)
      await nextTick()
      expect(
        f.wrapper.get('[data-allocation]').attributes('data-allocation'),
      ).toBe('118.5')
      expect(
        f.wrapper
          .get('[data-card-allocation]')
          .attributes('data-card-allocation'),
      ).toBe('218.5')
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
    ).toBe(238.75)
    await nextTick()
    expect(
      f.wrapper.get('[data-allocation]').attributes('data-allocation'),
    ).toBe('148.25')
    expect(
      f.wrapper
        .get('[data-card-allocation]')
        .attributes('data-card-allocation'),
    ).toBe('238.75')
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
      ).toBe(218.5)
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
    ).toBe(286.25)
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
    ).toBe(286.25)
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
    // 48px Sass body + 16.5px padding + 1.5px border + 8px outer margin.
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(74)
    f.zoom.value = 1.25
    f.fallbackHeight.value = 96
    f.redraw.value++
    await nextTick()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(74)
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
    ).toBe(218.5)
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
    ).toBe(218.5)
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
    ).toBe(218.5)
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
    ).toBe(56)
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
    ).toBe(218.5)
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
