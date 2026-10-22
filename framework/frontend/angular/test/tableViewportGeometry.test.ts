import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  FocusTrap,
  TableViewportGeometry,
  type TableGeometryRegistration,
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
  const zoom = { value: options.zoom ?? 1 }
  const fallbackHeight = { value: 48 }
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
    <div><table data-box="table"><thead><tr>
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
    projection: 'wide' | 'card',
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
  }
  function commit(): void {
    table.classList.toggle('hilos-table-fixed', adapter.tracks() !== null)
    adapter.commit(root, table, ['name', 'state'])
  }
  function replace(): void {
    for (const projection of ['wide', 'card'] as const) {
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
          : node('card-body', 'div', main)
      if (projection === 'card') body.className = 'card-body'
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
      for (const projection of ['wide', 'card'] as const) {
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
    zoom,
    fallbackHeight,
    wideVisible,
    cardVisible,
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
      expect(f.adapter.tracks()).toEqual({
        columnKeys: ['name', 'state'],
        widths: [125.25, 75.5],
        tableWidth: 200.75,
      })
      expect(f.adapter.reasonFloors()).toEqual({ wide: 22, card: 18 })
      f.boxes.set('wide-detail', rect(78.75, 90.5))
      f.depart()
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'wide'),
      ).toBe(148.25)
      expect(
        f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
      ).toBe(218.5)
      expect(f.root.textContent).not.toMatch(
        /live values|work payload|detail values/,
      )
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
    ).toBe(238.75)
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
      f.depart()
      expect(f.registration.record('a', 'wide')?.retained).toBe(125.25)
      expect(f.registration.record('a', 'card')?.retained).toBe(218.5)
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
    ).toBe(74)
    f.fallbackHeight.value = 96
    f.zoom.value = 1.5
    f.commit()
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(74)
    f.boxes.set('card-reason', rect(0, 260.25))
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(286.25)
    f.boxes.set('card-reason', rect(0, 18))
    f.commit()
    expect(
      f.adapter.recordPixels('a', f.owner.record('a')!.occurrence, 'card'),
    ).toBe(286.25)
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
    fonts.dispatchEvent(new Event('loadingdone'))
    expect(f.adapter.tracks()).toBeNull()
    expect(f.adapter.revision()).toBeGreaterThan(revision)
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
