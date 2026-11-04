import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  FocusTrap,
  TableViewportGeometry,
  type TableGeometryRegistration,
  type TableProjection,
} from '@hilos/core/base'
import { createTableViewportGeometry } from '../src/tableViewportGeometry.js'

// Numeric DOM fixtures prove the Angular adapter seam, not native CSS layout.
function rect(top: number, height: number, width = 200, left = 0): DOMRect {
  return new DOMRect(left, top, width, height)
}
const cleanups: (() => void)[] = []
const cssZoom = Object.getOwnPropertyDescriptor(
  HTMLElement.prototype,
  'currentCSSZoom',
)
const documentFonts = Object.getOwnPropertyDescriptor(document, 'fonts')
afterEach(() => {
  for (const cleanup of cleanups.splice(0)) cleanup()
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
    wideContainerWidth?: number
    zoom?: number
    cardChrome?: boolean
    separateBorders?: boolean
    empty?: boolean
  } = {},
) {
  const owner = new TableViewportGeometry()
  owner.replaceWindow(options.empty ? [] : ['a', 'b'])
  const wideVisible = { value: options.wideVisible ?? true }
  const cardVisible = { value: options.cardVisible ?? true }
  const tilesVisible = { value: options.tilesVisible ?? true }
  const zoom = { value: options.zoom ?? 1 }
  const fallbackHeight = { value: 48 }
  Object.defineProperty(HTMLElement.prototype, 'currentCSSZoom', {
    configurable: true,
    get(this: HTMLElement) {
      // A hidden table has no layout object, while its root stays zoomed.
      return this.dataset.box === 'table' && !wideVisible.value ? 1 : zoom.value
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
    // The whole tile card holds its 4:3 image frame and its body.
    ['tiles-main', rect(600, 300.5, 180)],
    ['tiles-image', rect(600, 135, 180)],
    ['tiles-detail', rect(770, 40.25, 180)],
    ['tiles-work', rect(816.25, 20.5, 180)],
    ['tiles-reason', rect(0, 20)],
    ['tiles-probe', rect(0, 120, 180)],
    ['tiles-column', rect(0, 120, 196)],
    ['tiles-grid', rect(0, 120, 392)],
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
  const styleOverrides = new Map<string, Readonly<Record<string, string>>>()
  const computedStyle = window.getComputedStyle.bind(window)
  vi.spyOn(window, 'getComputedStyle').mockImplementation((element) => {
    const id = (element as HTMLElement).dataset.box
    return new Proxy(computedStyle(element), {
      get(target, property) {
        const override =
          typeof property === 'string'
            ? styleOverrides.get(id ?? '')?.[property]
            : undefined
        if (override !== undefined) return override
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
        // A tile card has no margin of its own; its column carries g-3.
        if (
          id === 'tiles-main' &&
          (property === 'borderTopWidth' || property === 'borderBottomWidth')
        )
          return '0.5px'
        if (
          id === 'tiles-body' &&
          (property === 'paddingTop' || property === 'paddingBottom')
        )
          return '8px'
        if (property === 'marginTop' && id === 'tiles-col') return '16px'
        if (property === 'marginBottom' && id === 'tiles-detail') return '4px'
        if (property === 'marginTop' && id === 'tiles-work') return '6px'
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
  const mutationObserved = new Set<Element>()
  const mutationDisconnect = vi.fn(() => mutationObserved.clear())
  let mutate: (() => void) | undefined
  vi.stubGlobal(
    'MutationObserver',
    class {
      constructor(callback: () => void) {
        mutate = callback
      }
      observe(node: Element) {
        mutationObserved.add(node)
      }
      disconnect = mutationDisconnect
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
  const root = document.createElement('div')
  root.dataset.box = 'root'
  root.tabIndex = -1
  root.innerHTML = `
    <input data-id="hilos-table-search" data-box="search">
    <div data-box="wide-container"><table data-box="table"><thead><tr>
      <th data-box="header-name">Name</th><th data-box="header-state">State</th>
    </tr></thead><tbody></tbody></table></div>
    <div class="hilos-table-room-wide">
      <div class="hilos-table-replacement-row" data-box="wide-shell">
        <div class="hilos-table-reason-twins" data-box="wide-reason"></div>
      </div><div class="hilos-table-fallback"></div>
    </div>
    <div class="hilos-table-room-card">
      <div class="hilos-table-reason-twins" data-box="card-reason"></div>
      <div class="hilos-table-fallback"></div>
    </div>
    <div class="hilos-table-room">
      <div class="row" data-box="tiles-grid">
        <div class="col" data-box="tiles-column">
          <div class="card hilos-table-tile hilos-table-room-tiles" data-box="tiles-probe">
            <div class="card-body">
              <div class="hilos-table-reason-twins" data-box="tiles-reason"></div>
              <div class="hilos-table-fallback"></div>
            </div>
          </div>
        </div>
      </div>
    </div>`
  document.body.append(root)
  const table = root.querySelector('table')!
  let registration!: TableGeometryRegistration
  const captures = vi.fn(
    (capture: Parameters<typeof owner.view.register>[0]) => {
      registration = owner.view.register(capture)
      return registration
    },
  )
  const adapter = createTableViewportGeometry({ register: captures })
  cleanups.push(() => adapter.dispose())
  const targets = new Map<string, HTMLElement>()
  const releases = new Map<string, () => void>()
  function node(
    id: string,
    tag: string,
    parent: HTMLElement,
    content = '',
  ): HTMLElement {
    const element = document.createElement(tag)
    element.dataset.box = id
    element.textContent = content
    parent.append(element)
    return element
  }
  function bind(
    rowKey: string,
    projection: TableProjection,
    part: 'main' | 'work' | 'detail',
    element: HTMLElement,
  ): void {
    const id = `${projection}-${part}`
    targets.set(id, element)
    releases.set(
      id,
      adapter.bind(
        rowKey,
        owner.record(rowKey)!.occurrence,
        projection,
        part,
        element,
      ),
    )
  }
  function add(): void {
    const main = node('wide-main', 'tr', table.tBodies[0]!, 'live values')
    main.append(document.createElement('td'))
    bind('a', 'wide', 'main', main)
    bind(
      'a',
      'wide',
      'work',
      node('wide-work', 'tr', table.tBodies[0]!, 'work payload'),
    )
    bind(
      'a',
      'wide',
      'detail',
      node('wide-detail', 'tr', table.tBodies[0]!, 'detail values'),
    )
    const card = node('card-main', 'div', root)
    card.className = 'card'
    const body = node('card-body', 'div', card, 'live values')
    body.className = 'card-body'
    bind('a', 'card', 'main', card)
    bind(
      'a',
      'card',
      'detail',
      node('card-detail', 'div', body, 'detail values'),
    )
    bind('a', 'card', 'work', node('card-work', 'div', body, 'work payload'))
    const grid = node('tiles-live-grid', 'div', root)
    grid.className = 'row'
    const column = node('tiles-col', 'div', grid)
    column.className = 'col'
    const tile = node('tiles-main', 'div', column)
    tile.className = 'card hilos-table-tile'
    const image = node('tiles-image', 'div', tile)
    image.className = 'ratio ratio-4x3'
    const picture = document.createElement('img')
    picture.alt = 'live picture'
    image.append(picture)
    const tileBody = node('tiles-body', 'div', tile, 'live values')
    tileBody.className = 'card-body'
    bind('a', 'tiles', 'main', tile)
    bind(
      'a',
      'tiles',
      'detail',
      node('tiles-detail', 'div', tileBody, 'detail values'),
    )
    bind(
      'a',
      'tiles',
      'work',
      node('tiles-work', 'div', tileBody, 'work payload'),
    )
  }
  function commit(keys: readonly string[] = ['name', 'state']): void {
    table.classList.toggle('hilos-table-fixed', adapter.tracks() !== null)
    adapter.commit(root, table, keys)
  }
  function replace(): void {
    for (const projection of ['wide', 'card', 'tiles'] as const) {
      for (const part of ['work', 'detail'] as const) {
        const id = `${projection}-${part}`
        releases.get(id)?.()
        targets.get(id)?.remove()
      }
      const main = targets.get(`${projection}-main`)!
      main.replaceChildren()
      const body =
        projection === 'wide'
          ? node('cell', 'td', main)
          : node(`${projection}-body`, 'div', main)
      if (projection !== 'wide') body.className = 'card-body'
      const space = node('space', 'div', body)
      space.className = 'hilos-table-record-space'
      node(`${projection}-reason`, 'span', space, 'Removed')
    }
  }
  function depart(): void {
    owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
      owner.depart('a', 'deleted')
      replace()
    })
  }
  function reserve(): void {
    owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
      owner.reserveWork('a')
      for (const projection of ['wide', 'card', 'tiles'] as const) {
        const work = targets.get(`${projection}-work`)!
        work.textContent = ''
        work.className = 'hilos-table-work-reserve'
      }
    })
  }
  if (!options.empty) add()
  commit()
  return {
    owner,
    adapter,
    registration,
    captures,
    root,
    table,
    targets,
    releases,
    boxes,
    styleOverrides,
    zoom,
    fallbackHeight,
    wideVisible,
    cardVisible,
    tilesVisible,
    observed,
    disconnect,
    cancel,
    mutationObserved,
    mutationDisconnect,
    mutate: () => mutate?.(),
    commit,
    add,
    depart,
    reserve,
    resize: () => resize?.(),
    frame: () => {
      const callback = pending
      pending = undefined
      callback?.(0)
    },
    queued: () => pending,
  }
}

describe('Angular table geometry (numeric DOM seam)', () => {
  it.each([1, 1.25, 1.5])(
    'captures fractional complete footprints once at zoom %s before destructive publication',
    (zoom) => {
      const f = fixture({ zoom, cardChrome: true })
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
      // The tile's main box is its whole card; the image is never added again,
      // and the surviving column's gutter joins detail and work gaps once.
      expect(f.registration.record('a', 'tiles')?.live).toEqual({
        main: 233.75,
        work: 20.5,
        detail: 40.25,
        gap: 22,
        total: 316.5,
      })
      expect(f.adapter.tracks()).toEqual({
        columnKeys: ['name', 'state'],
        widths: [125.25, 75.5],
        tableWidth: 200.75,
      })
      expect(f.adapter.reasonFloors()).toEqual({
        wide: 22,
        card: 18,
        tiles: 20,
      })
      f.boxes.set('wide-detail', rect(78.75, 90.5))
      f.boxes.set('tiles-main', rect(600, 310.25, 180))
      f.depart()
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
      ).toBe(148.25)
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
      ).toBe(192.5)
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'tiles'),
      ).toBe(293.25)
      expect(f.root.textContent).not.toMatch(
        /live values|work payload|detail values/,
      )
      expect(f.root.querySelector('img')).toBeNull()
    },
  )

  it('captures a newly zoomed layout without waiting for resize or render', () => {
    const f = fixture()
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
  })

  it('reads the whole batch before its first mutation', () => {
    const f = fixture()
    const row = document.createElement('tr')
    f.table.tBodies[0]!.append(row)
    row.dataset.box = 'wide-b'
    f.boxes.set('wide-b', rect(160, 88.75))
    f.adapter.bind('b', f.owner.record('b')!.occurrence, 'wide', 'main', row)
    f.owner.withPresentationUpdate(
      { kind: 'rows', rowKeys: ['a', 'b'] },
      () => {
        f.owner.depart('a', 'deleted')
        row.remove()
        f.owner.depart('b', 'left_set')
      },
    )
    expect(f.registration.record('a', 'wide')?.retained).toBe(119.5)
    expect(f.registration.record('b', 'wide')?.retained).toBe(88.75)
  })

  it.each([1, 1.25, 1.5])(
    'retains early work and gaps once through Apply at zoom %s',
    (zoom) => {
      const f = fixture({ zoom })
      f.boxes.set('wide-work', rect(62.75, 18.5))
      f.boxes.set('wide-detail', rect(84.5, 60.75))
      f.reserve()
      expect(f.registration.record('a', 'wide')?.workReserve).toEqual({
        work: 18.5,
        gap: 2.5,
        total: 21,
      })
      expect(f.adapter.workPixels('a', 'card')).toBe(22.25)
      // The data-free reserve has the numeric size the template will supply.
      f.boxes.set('wide-work', rect(60.25, 21))
      f.boxes.set('wide-detail', rect(84.5, 60.75))
      f.boxes.set('card-work', rect(446.5, 22.25))
      f.commit()
      expect(f.adapter.workPixels('a', 'wide')).toBe(21)
      expect(f.adapter.workPixels('a', 'tiles')).toBe(22.5)
      f.depart()
      expect(f.registration.record('a', 'wide')?.retained).toBe(125.25)
      expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
      expect(f.registration.record('a', 'tiles')?.retained).toBe(316.5)
    },
  )

  it('includes separate-border outer spacing once without rescaling computed CSS', () => {
    const f = fixture({ zoom: 1.25, separateBorders: true })
    expect(f.registration.record('a', 'wide')?.live?.total).toBe(122.75)
    f.depart()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(118.5)
  })

  it.each([true, false])(
    'waits for first connected live content after initial empty (visible=%s)',
    (visible) => {
      const f = fixture({ empty: true, wideVisible: visible })
      expect(f.adapter.tracks()).toBeNull()
      f.owner.addRecord('a')
      f.add()
      f.commit()
      if (!visible) expect(f.adapter.tracks()).toBeNull()
      f.wideVisible.value = true
      f.commit()
      const tracks = f.adapter.tracks()
      expect(tracks?.widths).toEqual([125.25, 75.5])
      f.depart()
      f.owner.addRecord('c')
      f.commit()
      expect(f.adapter.tracks()).toBe(tracks)
    },
  )

  it('preserves valid hidden cache, but invalidates it on a hidden value update', () => {
    const f = fixture()
    f.cardVisible.value = false
    f.commit()
    f.depart()
    expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
  })

  it('allocates an unseen projection on first display only, then keeps its historical CSS pixels', () => {
    const f = fixture({ cardVisible: false, cardChrome: true, zoom: 1.25 })
    f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {})
    f.depart()
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBeUndefined()
    f.cardVisible.value = true
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(48)
    f.fallbackHeight.value = 96
    f.zoom.value = 1.5
    f.commit()
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(48)
    f.boxes.set('card-reason', rect(0, 260.25))
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(260.25)
    f.boxes.set('card-reason', rect(0, 18))
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(260.25)
  })

  it('allows live shrink and only grows departed geometry for its reason', () => {
    const f = fixture()
    f.boxes.set('wide-detail', rect(78.75, 25.5))
    f.commit()
    f.depart()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(83.25)
    f.boxes.set('wide-reason', rect(0, 150))
    f.boxes.set('wide-shell', rect(0, 151))
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(150)
    f.boxes.set('wide-reason', rect(0, 22))
    f.boxes.set('wide-shell', rect(0, 23))
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
    ).toBe(150)
  })

  it.each(['container', 'zoom', 'font', 'spacing', 'rem'] as const)(
    'releases fixed tracks on %s invalidation before reading the next auto commit',
    (kind) => {
      const f = fixture()
      f.table.classList.add('hilos-table-fixed')
      if (kind === 'container') f.boxes.set('root', rect(0, 600, 375))
      if (kind === 'zoom') f.zoom.value = 1.5
      const original = vi
        .mocked(window.getComputedStyle)
        .getMockImplementation()!
      if (kind === 'font' || kind === 'spacing' || kind === 'rem') {
        vi.spyOn(window, 'getComputedStyle').mockImplementation(
          (element) =>
            new Proxy(original(element), {
              get(target, property) {
                if (kind === 'font' && property === 'fontWeight') return '700'
                if (kind === 'spacing' && property === 'letterSpacing')
                  return '1.25px'
                if (
                  kind === 'rem' &&
                  element === document.documentElement &&
                  property === 'fontSize'
                )
                  return '20px'
                return Reflect.get(target, property)
              },
            }),
        )
      }
      f.resize()
      f.frame()
      expect(f.adapter.tracks()).toBeNull()
      // A callback or commit that still has fixed widths must not relatch them.
      f.adapter.commit(f.root, f.table, ['name', 'state'])
      expect(f.adapter.tracks()).toBeNull()
      f.boxes.set('header-name', rect(0, 20, 100.25))
      f.boxes.set('header-state', rect(0, 20, 75.5, 100.25))
      f.boxes.set('table', rect(0, 300, 175.75))
      f.commit()
      expect(f.adapter.tracks()?.widths).toEqual([100.25, 75.5])
    },
  )

  it('does not let an old node cleanup remove its newer tuple binding', () => {
    const f = fixture()
    const previous = f.targets.get('wide-main')!
    const newer = previous.cloneNode(true) as HTMLElement
    previous.replaceWith(newer)
    const occurrence = f.owner.record('a')!.occurrence
    const release = f.adapter.bind('a', occurrence, 'wide', 'main', newer)
    f.releases.get('wide-main')?.()
    expect(f.observed.has(newer)).toBe(true)
    f.depart()
    expect(f.registration.record('a', 'wide')?.retained).toBe(119.5)
    release()
    expect(f.observed.has(newer)).toBe(false)
  })

  it('does not match an old DOM occurrence to a reused row key', () => {
    const f = fixture()
    const occurrence = f.owner.record('a')!.occurrence
    f.owner.replaceWindow(['a'])
    f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () =>
      f.owner.depart('a', 'deleted'),
    )
    expect(f.registration.record('a', 'wide')?.retained).toBeNull()
    expect(f.adapter.recordPixels('a', occurrence, 'wide')).toBeUndefined()
  })

  it('restores a gone active control to visible search and preserves external focus', () => {
    const f = fixture()
    f.boxes.set('search', rect(0, 20, 100))
    const button = document.createElement('button')
    f.targets.get('wide-main')!.append(button)
    button.focus()
    f.depart()
    f.commit()
    expect(document.activeElement).toBe(f.root.querySelector('input'))
  })

  it('uses root for hidden or disabled search, preserving a later modal/user focus choice', () => {
    const f = fixture()
    const button = document.createElement('button')
    f.targets.get('wide-main')!.append(button)
    button.focus()
    f.depart()
    const external = document.createElement('button')
    document.body.append(external)
    external.focus()
    f.commit()
    expect(document.activeElement).toBe(external)
    // A hidden table cannot offer its root to a modal return fallback.
    f.root.remove()
    const focus = vi.spyOn(document, 'activeElement', 'get')
    f.commit()
    expect(focus).not.toHaveBeenCalled()
  })

  it('releases observers, listeners, registration and queued callbacks on dispose', () => {
    const f = fixture()
    f.resize()
    const late = f.queued()!
    f.adapter.dispose()
    expect(f.cancel).toHaveBeenCalled()
    expect(f.observed.size).toBe(0)
    const read = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    read.mockClear()
    late(0)
    f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {})
    window.dispatchEvent(new Event('resize'))
    document.dispatchEvent(new Event('visibilitychange'))
    expect(read).not.toHaveBeenCalled()
    expect(f.adapter.bind('a', 1, 'wide', 'main', f.root)).toBeTypeOf(
      'function',
    )
  })
  it.each(['connected', 'gone', 'same-key', 'hidden', 'disposed'] as const)(
    'uses the mount focus offer for a modal opener that is %s',
    (state) => {
      const f = fixture()
      f.boxes.set('search', rect(0, 20, 100))
      const opener = document.createElement('button')
      f.targets.get('wide-main')!.append(opener)
      opener.focus()
      const modal = document.createElement('div')
      modal.tabIndex = -1
      document.body.append(modal)
      const trap = new FocusTrap()
      trap.activate(modal, 'dialog')
      if (state !== 'connected') f.depart()
      if (state === 'same-key') {
        f.owner.replaceWindow(['a'])
        const replacement = document.createElement('button')
        replacement.dataset.id = 'same-old-control'
        f.root.append(replacement)
      }
      if (state === 'hidden') f.root.hidden = true
      if (state === 'disposed') f.adapter.dispose()
      trap.release()
      expect(document.activeElement).toBe(
        state === 'connected'
          ? opener
          : state === 'hidden' || state === 'disposed'
            ? modal
            : f.root.querySelector('input'),
      )
    },
  )

  it.each(['hidden', 'disabled'] as const)(
    'falls back to root when search is %s',
    (state) => {
      const f = fixture()
      f.boxes.set('search', rect(0, 20, 100))
      const search = f.root.querySelector('input')!
      if (state === 'hidden') search.hidden = true
      else search.disabled = true
      const opener = document.createElement('button')
      f.targets.get('wide-main')!.append(opener)
      opener.focus()
      f.depart()
      f.commit()
      expect(document.activeElement).toBe(f.root)
    },
  )

  it('calibrates fractional used boundaries without changing the core latch', () => {
    const f = fixture()
    const latch = f.adapter.tracks()
    f.boxes.set('header-name', rect(0, 20, 125.75))
    f.boxes.set('header-state', rect(0, 20, 75, 125.75))
    f.commit()
    expect(f.adapter.trackWidths()).toEqual([124.75, 76])
    expect(f.adapter.tracks()).toBe(latch)
    // Once the numeric col widths reach their used target, no more publication.
    f.boxes.set('header-name', rect(0, 20, 125.25))
    f.boxes.set('header-state', rect(0, 20, 75.5, 125.25))
    const widths = f.adapter.trackWidths()
    f.commit()
    expect(f.adapter.trackWidths()).toBe(widths)
  })

  it('guards a late fonts.ready and releases font/ancestor listeners', async () => {
    const descriptor = Object.getOwnPropertyDescriptor(document, 'fonts')
    let ready!: () => void
    const fonts = new EventTarget() as EventTarget & { ready: Promise<void> }
    fonts.ready = new Promise<void>((resolve) => {
      ready = resolve
    })
    Object.defineProperty(document, 'fonts', {
      configurable: true,
      value: fonts,
    })
    const remove = vi.spyOn(fonts, 'removeEventListener')
    const f = fixture()
    const revision = f.adapter.revision()
    const projections = ['wide', 'card', 'tiles'] as const
    const layouts = projections.map((projection) =>
      f.registration.layout(projection),
    )
    fonts.dispatchEvent(new Event('loadingdone'))
    expect(f.adapter.tracks()).toBeNull()
    expect(f.adapter.revision()).toBeGreaterThan(revision)
    for (const [index, projection] of projections.entries())
      expect(f.registration.layout(projection)).not.toBe(layouts[index])
    f.commit()
    f.adapter.dispose()
    const stopped = f.adapter.revision()
    ready()
    await Promise.resolve()
    fonts.dispatchEvent(new Event('loadingdone'))
    expect(f.adapter.revision()).toBe(stopped)
    expect(remove).toHaveBeenCalledWith('loadingdone', expect.any(Function))
    if (descriptor) Object.defineProperty(document, 'fonts', descriptor)
    else Reflect.deleteProperty(document, 'fonts')
  })

  it.each(['zero', 'disconnected', 'hidden'] as const)(
    'does not allocate unavailable %s targets or latch their headers',
    (state) => {
      const f = fixture({ empty: true })
      f.owner.addRecord('a')
      f.add()
      if (state === 'zero') f.wideVisible.value = false
      if (state === 'disconnected') f.targets.get('wide-main')!.remove()
      if (state === 'hidden') f.targets.get('wide-main')!.hidden = true
      f.commit()
      expect(f.adapter.tracks()).toBeNull()
      expect(f.registration.record('a', 'wide')?.live).toBeNull()
    },
  )
  it('does not read browser globals before the Angular browser lifecycle', () => {
    const node = document.createElement('div')
    document.body.append(node)
    expect(node.isConnected).toBe(true)
    const browserRead = vi.fn(() => {
      throw new Error('Browser APIs are unavailable before commit')
    })
    const bounds = vi
      .spyOn(node, 'getBoundingClientRect')
      .mockImplementation(browserRead)
    vi.stubGlobal('window', undefined)
    vi.stubGlobal('document', undefined)
    for (const name of [
      'getComputedStyle',
      'DOMRect',
      'ResizeObserver',
      'MutationObserver',
      'requestAnimationFrame',
    ])
      vi.stubGlobal(name, browserRead)
    const owner = new TableViewportGeometry()
    owner.replaceWindow(['a'])
    const capture = vi.fn<Parameters<typeof owner.view.register>[0]>()
    let registration!: TableGeometryRegistration
    const adapter = createTableViewportGeometry({
      register: (read) => {
        capture.mockImplementation(read)
        registration = owner.view.register(capture)
        return registration
      },
    })
    const cleanup = adapter.bind(
      'a',
      owner.record('a')!.occurrence,
      'wide',
      'main',
      node,
    )
    try {
      const wide = registration.measurement('a', 'wide')
      const card = registration.measurement('a', 'card')
      const tiles = registration.measurement('a', 'tiles')
      const layout = registration.layout('wide')
      const update = vi.fn()
      expect(() =>
        owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, update),
      ).not.toThrow()
      expect(update).toHaveBeenCalledOnce()
      expect(capture).toHaveReturnedWith({
        records: [
          { measurement: wide, extent: null, workGap: 0 },
          { measurement: card, extent: null, workGap: 0 },
          { measurement: tiles, extent: null, workGap: 0 },
        ],
        tracks: { layout, tracks: null },
      })
      // Track capture must stay inert even without any requested row samples.
      owner.withPresentationUpdate({ kind: 'rows', rowKeys: [] }, () => {})
      expect(capture).toHaveLastReturnedWith({
        records: [],
        tracks: { layout, tracks: null },
      })
      expect(bounds).not.toHaveBeenCalled()
      expect(browserRead).not.toHaveBeenCalled()
      expect(registration.tracks()).toBeNull()
      expect(registration.record('a', 'wide')?.live).toBeNull()
    } finally {
      vi.unstubAllGlobals()
      cleanup()
      adapter.dispose()
    }
  })
  it('observes root, wide container, table and ancestors, and guards retired mutation callbacks', () => {
    const f = fixture()
    expect(f.mutationObserved).toEqual(
      new Set([
        f.root,
        f.table,
        f.table.parentElement,
        document.body,
        document.documentElement,
      ]),
    )
    f.mutate()
    expect(f.queued()).toBeTypeOf('function')
    f.frame()
    f.adapter.dispose()
    expect(f.mutationDisconnect).toHaveBeenCalledExactlyOnceWith()
    expect(f.mutationObserved.size).toBe(0)
    expect(f.queued()).toBeUndefined()
    f.mutate()
    expect(f.queued()).toBeUndefined()
  })
})

describe('Angular tile geometry (numeric DOM seam)', () => {
  it('keeps tiles independent of the wide tracks and the card numbers', () => {
    const f = fixture()
    const tracks = f.adapter.tracks()
    const wide = f.registration.record('a', 'wide')?.live
    const card = f.registration.record('a', 'card')?.live
    f.boxes.set('tiles-main', rect(600, 340.5, 180))
    f.resize()
    f.frame()
    expect(f.registration.record('a', 'tiles')?.live?.total).toBe(356.5)
    expect(f.registration.record('a', 'wide')?.live).toBe(wide)
    expect(f.registration.record('a', 'card')?.live).toBe(card)
    expect(f.adapter.tracks()).toBe(tracks)
    f.depart()
    expect(f.registration.record('a', 'wide')?.retained).toBe(119.5)
    expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
    expect(f.registration.record('a', 'tiles')?.retained).toBe(356.5)
  })

  it.each(['zero', 'hidden'] as const)(
    'reads a %s live tile as no measurement and keeps its valid cache',
    (state) => {
      const f = fixture()
      const tile = f.targets.get('tiles-main')!
      if (state === 'zero') f.boxes.set('tiles-main', rect(600, 0, 0))
      else tile.hidden = true
      f.resize()
      f.frame()
      expect(f.registration.record('a', 'tiles')?.live?.total).toBe(316.5)
      f.depart()
      f.commit()
      const occurrence = f.owner.record('a')!.occurrence
      expect(f.adapter.recordPixels('a', occurrence, 'tiles')).toBe(283.5)
    },
  )

  it('allocates a never-shown tile its generic numeric floor on first display only', () => {
    const f = fixture({ tilesVisible: false })
    f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {})
    f.depart()
    f.commit()
    const occurrence = f.owner.record('a')!.occurrence
    expect(f.adapter.recordPixels('a', occurrence, 'tiles')).toBeUndefined()
    f.tilesVisible.value = true
    // A tile-only signature change can measure in this committed layout.
    f.commit()
    // The published body height is the 3rem fallback; its shell stays in layout.
    expect(f.adapter.recordPixels('a', occurrence, 'tiles')).toBe(48)
    expect(f.root.querySelector('img')).toBeNull()
    f.fallbackHeight.value = 96
    f.commit()
    expect(f.adapter.recordPixels('a', occurrence, 'tiles')).toBe(48)
    f.boxes.set('tiles-reason', rect(0, 260.25))
    f.commit()
    expect(f.adapter.recordPixels('a', occurrence, 'tiles')).toBe(260.25)
    f.boxes.set('tiles-reason', rect(0, 20))
    f.commit()
    expect(f.adapter.recordPixels('a', occurrence, 'tiles')).toBe(260.25)
  })

  it.each([
    'tile width',
    'columns',
    'gutter',
    'tile font',
    'tile zoom',
  ] as const)(
    'releases the tile layout on a %s change read from the DOM grid',
    (kind) => {
      const f = fixture()
      const layout = f.registration.layout('tiles')
      if (kind === 'tile width') f.boxes.set('tiles-probe', rect(0, 120, 150))
      // Three columns in the same grid width: no viewport query is involved.
      if (kind === 'columns') f.boxes.set('tiles-column', rect(0, 120, 130.5))
      if (kind === 'tile zoom')
        Object.defineProperty(
          f.root.querySelector('[data-box="tiles-probe"]')!,
          'currentCSSZoom',
          {
            configurable: true,
            value: 1.5,
          },
        )
      const original = vi
        .mocked(window.getComputedStyle)
        .getMockImplementation()!
      vi.spyOn(window, 'getComputedStyle').mockImplementation(
        (element) =>
          new Proxy(original(element), {
            get(target, property) {
              const id = (element as HTMLElement).dataset.box
              if (
                kind === 'gutter' &&
                id === 'tiles-column' &&
                property === 'marginTop'
              )
                return '24px'
              if (
                kind === 'tile font' &&
                id === 'tiles-probe' &&
                property === 'fontSize'
              )
                return '18px'
              return Reflect.get(target, property)
            },
          }),
      )
      f.resize()
      f.frame()
      expect(f.registration.layout('tiles')).not.toBe(layout)
      expect(f.registration.record('a', 'tiles')?.live?.total).toBe(316.5)
      f.commit()
      expect(f.registration.record('a', 'tiles')?.live?.total).toBe(316.5)
      const measured = f.registration.layout('tiles')
      f.resize()
      f.frame()
      expect(f.registration.layout('tiles')).toBe(measured)
    },
  )

  it.each(['columns', 'gutter'] as const)(
    'keeps wide/card tickets and calibrated widths when only tile %s change at the same root width',
    (kind) => {
      const f = fixture()
      f.commit()
      // Exhaust the bounded fractional corrections. A tile-only change must
      // preserve this calibration as well as the owner's fixed-width latch.
      f.boxes.set('header-name', rect(0, 20, 125.75))
      f.boxes.set('header-state', rect(0, 20, 75, 125.75))
      for (let pass = 0; pass < 4; pass++) f.commit()
      const widths = f.adapter.trackWidths()
      expect(widths).toEqual([123.25, 77.5])
      const tracks = f.adapter.tracks()
      const wideLayout = f.registration.layout('wide')
      const cardLayout = f.registration.layout('card')
      const tilesLayout = f.registration.layout('tiles')
      const wide = f.registration.record('a', 'wide')?.live
      const card = f.registration.record('a', 'card')?.live
      if (kind === 'columns') f.boxes.set('tiles-column', rect(0, 120, 130.5))
      else f.styleOverrides.set('tiles-column', { paddingLeft: '24px' })
      f.boxes.set('tiles-main', rect(600, 340.5, 180))
      // Both post-render commit and the observer frame use the same checker.
      if (kind === 'columns') f.commit()
      else {
        f.resize()
        f.frame()
      }
      expect(f.registration.layout('tiles')).not.toBe(tilesLayout)
      expect(f.registration.record('a', 'tiles')?.live?.total).toBe(356.5)
      expect(f.registration.layout('wide')).toBe(wideLayout)
      expect(f.registration.layout('card')).toBe(cardLayout)
      expect(f.registration.record('a', 'wide')?.live).toBe(wide)
      expect(f.registration.record('a', 'card')?.live).toBe(card)
      expect(f.adapter.tracks()).toBe(tracks)
      expect(f.adapter.trackWidths()).toBe(widths)
      expect(f.table.classList.contains('hilos-table-fixed')).toBe(true)
      f.depart()
      expect(f.registration.record('a', 'wide')?.retained).toBe(119.5)
      expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
      expect(f.registration.record('a', 'tiles')?.retained).toBe(356.5)
    },
  )

  it.each([
    'wide container',
    'root',
    'root zoom',
    'table zoom',
    'table font',
    'column keys',
  ] as const)(
    'releases wide/card for a real %s change and waits for the auto-layout commit',
    (kind) => {
      const f = fixture()
      f.commit()
      const wideLayout = f.registration.layout('wide')
      const cardLayout = f.registration.layout('card')
      const tilesLayout = f.registration.layout('tiles')
      const keys =
        kind === 'column keys' ? ['name', 'status'] : ['name', 'state']
      if (kind === 'wide container')
        f.boxes.set('wide-container', rect(0, 300, 450))
      if (kind === 'root') f.boxes.set('root', rect(0, 600, 450))
      if (kind === 'root zoom') f.zoom.value = 1.25
      if (kind === 'table zoom')
        Object.defineProperty(f.table, 'currentCSSZoom', {
          configurable: true,
          value: 1.25,
        })
      if (kind === 'table font')
        f.styleOverrides.set('table', { fontSize: '18px' })
      f.commit(keys)
      expect(f.adapter.tracks()).toBeNull()
      expect(f.registration.layout('wide')).not.toBe(wideLayout)
      expect(f.registration.layout('card')).not.toBe(cardLayout)
      expect(f.registration.record('a', 'wide')?.live).toBeNull()
      expect(f.registration.record('a', 'card')?.live).toBeNull()
      if (kind === 'root' || kind === 'root zoom' || kind === 'column keys')
        expect(f.registration.layout('tiles')).not.toBe(tilesLayout)
      else expect(f.registration.layout('tiles')).toBe(tilesLayout)
      // A tile invalidation while wide waits cannot finish its auto-layout cycle.
      f.adapter.tilesLayoutChanged()
      f.frame()
      f.adapter.commit(f.root, f.table, keys)
      expect(f.adapter.tracks()).toBeNull()
      expect(f.registration.record('a', 'wide')?.live).toBeNull()
      f.boxes.set('header-name', rect(0, 20, 100.25))
      f.boxes.set('header-state', rect(0, 20, 75.5, 100.25))
      f.boxes.set('table', rect(0, 300, 175.75))
      f.commit(keys)
      expect(f.adapter.tracks()?.columnKeys).toEqual(keys)
      expect(f.adapter.tracks()?.widths).toEqual([100.25, 75.5])
      expect(f.registration.record('a', 'wide')?.live?.total).toBe(119.5)
      expect(f.registration.record('a', 'card')?.live?.total).toBe(218.5)
      expect(f.registration.record('a', 'tiles')?.live?.total).toBe(316.5)
    },
  )

  it.each([1, 1.25])(
    'keeps wide/card geometry and calibration through view switches at zoom %s',
    (zoom) => {
      const f = fixture({ wideContainerWidth: 480, zoom })
      f.commit()
      expect(f.table.classList.contains('hilos-table-fixed')).toBe(true)
      f.boxes.set('header-name', rect(0, 20, 125.75))
      f.boxes.set('header-state', rect(0, 20, 75, 125.75))
      for (let pass = 0; pass < 4; pass++) f.commit()
      expect(f.adapter.trackWidths()).toEqual([123.25, 77.5])
      const tracks = f.adapter.tracks()
      const widths = f.adapter.trackWidths()
      const wideLayout = f.registration.layout('wide')
      const cardLayout = f.registration.layout('card')
      const wideTicket = f.registration.measurement('a', 'wide')
      const cardTicket = f.registration.measurement('a', 'card')
      const wide = f.registration.record('a', 'wide')?.live
      const card = f.registration.record('a', 'card')?.live
      // A departed wide row keeps its allocation through the whole Tiles visit.
      const row = document.createElement('tr')
      row.dataset.box = 'wide-b'
      f.table.tBodies[0]!.append(row)
      f.boxes.set('wide-b', rect(160, 88.75))
      f.adapter.bind('b', f.owner.record('b')!.occurrence, 'wide', 'main', row)
      f.commit()
      f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['b'] }, () =>
        f.owner.depart('b', 'deleted'),
      )
      expect(f.registration.record('b', 'wide')?.retained).toBe(88.75)
      f.wideVisible.value = false
      f.cardVisible.value = false
      expect(f.root.currentCSSZoom).toBe(zoom)
      expect(f.table.currentCSSZoom).toBe(1)
      f.adapter.tilesLayoutChanged()
      expect(f.adapter.tracks()).toBe(tracks)
      f.commit()
      expect(f.table.classList.contains('hilos-table-fixed')).toBe(true)
      expect(f.adapter.trackWidths()).toBe(widths)
      expect(f.registration.layout('wide')).toBe(wideLayout)
      expect(f.registration.layout('card')).toBe(cardLayout)
      expect(f.registration.measurement('a', 'wide')).toBe(wideTicket)
      expect(f.registration.measurement('a', 'card')).toBe(cardTicket)
      expect(f.registration.record('a', 'tiles')?.live?.total).toBe(316.5)
      expect(f.registration.record('a', 'wide')?.live).toBe(wide)
      expect(f.registration.record('a', 'card')?.live).toBe(card)
      expect(f.registration.record('b', 'wide')?.retained).toBe(88.75)
      // Zero wide rectangles neither re-sign the layout nor block later frames.
      const layout = f.registration.layout('tiles')
      f.commit()
      expect(f.registration.layout('tiles')).toBe(layout)
      f.boxes.set('tiles-main', rect(600, 320.5, 180))
      f.resize()
      f.frame()
      expect(f.registration.record('a', 'tiles')?.live?.total).toBe(336.5)
      expect(f.adapter.tracks()).toBe(tracks)
      // Return with a live row: restarting calibration would change these widths.
      f.wideVisible.value = true
      f.cardVisible.value = true
      f.tilesVisible.value = false
      f.adapter.tilesLayoutChanged()
      f.commit()
      expect(f.table.currentCSSZoom).toBe(zoom)
      expect(f.adapter.tracks()).toBe(tracks)
      expect(f.adapter.trackWidths()).toBe(widths)
      expect(f.registration.measurement('a', 'wide')).toBe(wideTicket)
      expect(f.registration.measurement('a', 'card')).toBe(cardTicket)
      f.wideVisible.value = false
      f.cardVisible.value = false
      f.tilesVisible.value = true
      f.adapter.tilesLayoutChanged()
      f.commit()
      f.depart()
      expect(f.registration.record('a', 'tiles')?.retained).toBe(336.5)
      expect(f.registration.record('a', 'wide')?.retained).toBe(119.5)
      expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
      expect(f.registration.record('b', 'wide')?.retained).toBe(88.75)
      f.wideVisible.value = true
      f.cardVisible.value = true
      f.tilesVisible.value = false
      f.adapter.tilesLayoutChanged()
      f.commit()
      expect(f.adapter.tracks()).toBe(tracks)
      expect(f.adapter.trackWidths()).toBe(widths)
      expect(f.registration.layout('wide')).toBe(wideLayout)
      expect(f.registration.layout('card')).toBe(cardLayout)
      expect(f.registration.record('a', 'wide')?.retained).toBe(119.5)
      expect(f.registration.record('b', 'wide')?.retained).toBe(88.75)
    },
  )

  it.each([
    'initial mount',
    'table replacement',
    'controller replacement',
  ] as const)(
    'starts a hidden wide projection with its own environment after %s',
    (lifecycle) => {
      const f = fixture({
        wideVisible: lifecycle !== 'initial mount',
        zoom: lifecycle === 'initial mount' ? 1.25 : 1.5,
      })
      let adapter = f.adapter
      let registration = f.registration
      let table = f.table
      f.wideVisible.value = false
      f.zoom.value = 1.25
      if (lifecycle === 'table replacement') {
        table = f.table.cloneNode(true) as HTMLTableElement
        f.table.replaceWith(table)
      }
      if (lifecycle === 'controller replacement') {
        adapter.dispose()
        adapter = createTableViewportGeometry({
          register: (capture) => {
            registration = f.owner.view.register(capture)
            return registration
          },
        })
        cleanups.push(adapter.dispose)
      }
      for (const projection of ['wide', 'card', 'tiles'] as const)
        for (const part of ['main', 'work', 'detail'] as const)
          adapter.bind(
            'a',
            f.owner.record('a')!.occurrence,
            projection,
            part,
            f.root.querySelector(`[data-box="${projection}-${part}"]`)!,
          )
      function commit(): void {
        table.classList.toggle('hilos-table-fixed', adapter.tracks() !== null)
        adapter.commit(f.root, table, ['name', 'state'])
      }
      commit()
      commit()
      const layout = registration.layout('wide')
      expect(table.currentCSSZoom).toBe(1)
      expect(adapter.tracks()).toBeNull()
      expect(registration.record('a', 'wide')?.live).toBeNull()
      f.wideVisible.value = true
      commit()
      expect(table.currentCSSZoom).toBe(1.25)
      expect(registration.layout('wide')).toBe(layout)
      expect(adapter.tracks()?.widths).toEqual([125.25, 75.5])
      expect(registration.record('a', 'wide')?.live?.total).toBe(119.5)
    },
  )

  it('ignores image load and measures actual tile resize, with late callbacks inert on dispose', () => {
    const f = fixture()
    const layout = f.registration.layout('tiles')
    const wideLayout = f.registration.layout('wide')
    const cardLayout = f.registration.layout('card')
    const wide = f.registration.record('a', 'wide')?.live
    const card = f.registration.record('a', 'card')?.live
    const tiles = f.registration.record('a', 'tiles')?.live
    const tracks = f.adapter.tracks()
    const avatar = document.createElement('img')
    f.targets.get('wide-main')!.append(avatar)
    avatar.dispatchEvent(new Event('load'))
    expect(f.registration.layout('tiles')).toBe(layout)
    expect(f.adapter.tracks()).toBe(tracks)
    const picture = f.targets.get('tiles-main')!.querySelector('img')!
    picture.dispatchEvent(new Event('load'))
    expect(f.registration.layout('tiles')).toBe(layout)
    expect(f.registration.layout('wide')).toBe(wideLayout)
    expect(f.registration.layout('card')).toBe(cardLayout)
    expect(f.registration.record('a', 'tiles')?.live).toBe(tiles)
    expect(f.registration.record('a', 'wide')?.live).toBe(wide)
    expect(f.registration.record('a', 'card')?.live).toBe(card)
    expect(f.adapter.tracks()).toBe(tracks)
    expect(f.queued()).toBeUndefined()
    f.commit()
    expect(f.registration.record('a', 'tiles')?.live?.total).toBe(316.5)
    f.boxes.set('tiles-main', rect(600, 340.5, 180))
    f.resize()
    f.frame()
    expect(f.registration.record('a', 'tiles')?.live?.total).toBe(356.5)
    expect(f.registration.layout('tiles')).toBe(layout)
    expect(f.adapter.tracks()).toBe(tracks)
    f.resize()
    const late = f.queued()!
    f.adapter.dispose()
    const read = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    read.mockClear()
    picture.dispatchEvent(new Event('load'))
    f.adapter.tilesLayoutChanged()
    late(0)
    f.frame()
    expect(read).not.toHaveBeenCalled()
  })

  it('does not let an old tile cleanup unbind its successor', () => {
    const f = fixture()
    const previous = f.targets.get('tiles-main')!
    const newer = previous.cloneNode(true) as HTMLElement
    previous.replaceWith(newer)
    const occurrence = f.owner.record('a')!.occurrence
    const release = f.adapter.bind('a', occurrence, 'tiles', 'main', newer)
    f.releases.get('tiles-main')?.()
    expect(f.observed.has(newer)).toBe(true)
    f.depart()
    expect(f.registration.record('a', 'tiles')?.retained).toBe(316.5)
    release()
    expect(f.observed.has(newer)).toBe(false)
  })

  it('keeps an old tile occurrence and a late frame out of a reused key', () => {
    const f = fixture()
    const occurrence = f.owner.record('a')!.occurrence
    f.boxes.set('tiles-main', rect(600, 330.5, 180))
    f.resize()
    f.owner.replaceWindow(['a'])
    f.frame()
    expect(f.registration.record('a', 'tiles')?.live).toBeNull()
    f.owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () =>
      f.owner.depart('a', 'deleted'),
    )
    expect(f.registration.record('a', 'tiles')?.retained).toBeNull()
    expect(f.adapter.recordPixels('a', occurrence, 'tiles')).toBeUndefined()
  })
})

/** Reader identities are issued by the existing owner, independently of DOM sizes. */
function readerFixture() {
  const owner = new TableViewportGeometry()
  owner.replaceWindow(['a'])
  let current = true
  const adapter = createTableViewportGeometry(owner.view, () => current)
  cleanups.push(adapter.dispose)
  const root = document.createElement('div')
  const table = document.createElement('table')
  root.append(table)
  document.body.append(root)
  const commit = () => adapter.commit(root, table, [])
  return {
    owner,
    adapter,
    root,
    table,
    commit,
    reject: () => {
      current = false
    },
  }
}

describe('Angular exact text record reader', () => {
  it('never binds an early reader retroactively, and reuses a current reader', () => {
    const f = readerFixture()
    const identity = f.owner.record('a')!
    const early = f.adapter.recordReader('a', identity.occurrence, 'wide')
    expect(early()).toBeNull()
    f.commit()
    const read = f.adapter.recordReader('a', identity.occurrence, 'wide')
    expect(read()).toBe(identity)
    expect(early()).toBeNull()
    expect(f.adapter.recordReader('a', identity.occurrence, 'wide')).toBe(read)
    expect(
      f.adapter.recordReader('a', identity.occurrence + 1, 'wide')(),
    ).toBeNull()
    const absent = f.adapter.recordReader('b', identity.occurrence, 'card')
    f.owner.addRecord('b')
    expect(absent()).toBeNull()
  })

  it.each(['outgoing', 'departure', 'window', 'detached', 'dispose'] as const)(
    'rejects %s before Angular can flush',
    (change) => {
      const f = readerFixture()
      f.commit()
      const identity = f.owner.record('a')!
      const wide = f.adapter.recordReader('a', identity.occurrence, 'wide')
      const card = f.adapter.recordReader('a', identity.occurrence, 'card')
      const tiles = f.adapter.recordReader('a', identity.occurrence, 'tiles')
      expect(wide()).toBe(identity)
      expect(card()).toBe(identity)
      expect(tiles()).toBe(identity)
      expect(tiles).not.toBe(card)
      if (change === 'outgoing') f.reject()
      if (change === 'departure') f.owner.depart('a', 'deleted')
      if (change === 'window') f.owner.replaceWindow(['a'])
      if (change === 'detached') f.root.remove()
      if (change === 'dispose') f.adapter.dispose()
      expect(wide()).toBeNull()
      expect(card()).toBeNull()
      expect(tiles()).toBeNull()
    },
  )

  it('keeps separate mounts and does not let old disposal retire the successor', () => {
    const f = readerFixture()
    f.commit()
    const occurrence = f.owner.record('a')!.occurrence
    const old = f.adapter.recordReader('a', occurrence, 'wide')
    const newer = createTableViewportGeometry(f.owner.view)
    cleanups.push(newer.dispose)
    newer.commit(f.root, f.table, [])
    const read = newer.recordReader('a', occurrence, 'wide')
    f.adapter.dispose()
    expect(old()).toBeNull()
    expect(read()).toBe(f.owner.record('a'))
  })

  it('captures the currently expanded main extent with work/detail before departure', () => {
    const f = fixture()
    const identity = f.owner.record('a')!
    const read = f.adapter.recordReader('a', identity.occurrence, 'wide')
    f.boxes.set('wide-main', rect(20, 140.25))
    f.boxes.set('wide-work', rect(160.25, 18.5))
    f.boxes.set('wide-detail', rect(178.75, 60.75))
    f.depart()
    expect(read()).toBeNull()
    expect(f.registration.record('a', 'wide')?.retained).toBe(219.5)
  })
})
