import { signal } from '@angular/core'
import {
  registerFocusReturnFallback,
  subscribeSignal,
  type TableColumnTracks,
  type TableGeometryCaptureRequest,
  type TableGeometryMeasurement,
  type TableGeometryRegistration,
  type TableGeometrySample,
  type TableProjection,
  type TableViewportGeometryView,
} from '@hilos/core/base'

type Part = 'main' | 'work' | 'detail'
interface RecordElements {
  readonly rowKey: string
  readonly occurrence: number
  readonly projection: TableProjection
  main?: HTMLElement
  work?: HTMLElement
  detail?: HTMLElement
}
interface Shell {
  readonly chrome: number
  readonly gap: number
  readonly fallback: number
}

/** CSS-pixel reads never turn an unavailable layout into a zero measurement. */
function pixels(value: string): number {
  const result = Number.parseFloat(value)
  return Number.isFinite(result) ? Math.max(0, result) : 0
}

/** Effective layout zoom includes ancestors; computed CSS and DPR do not. */
function layoutZoom(element: HTMLElement): number {
  const zoom = element.currentCSSZoom
  return Number.isFinite(zoom) && zoom > 0 ? zoom : 1
}

function box(element: HTMLElement | null | undefined): DOMRect | null {
  if (!element?.isConnected) return null
  const rect = element.getBoundingClientRect()
  const zoom = layoutZoom(element)
  // Normalize new viewport rectangles only. Computed margins/chrome already
  // use local CSS px, as do all retained allocations supplied by the owner.
  const local = new DOMRect(
    rect.x / zoom,
    rect.y / zoom,
    rect.width / zoom,
    rect.height / zoom,
  )
  return local.width > 0 &&
    local.height > 0 &&
    [local.x, local.y, local.width, local.height].every(Number.isFinite)
    ? local
    : null
}

function visibleBox(element: HTMLElement | undefined | null): DOMRect | null {
  if (!element || element.closest('[hidden], [inert]')) return null
  for (
    let node: HTMLElement | null = element;
    node;
    node = node.parentElement
  ) {
    const style = getComputedStyle(node)
    if (
      style.display === 'none' ||
      style.visibility === 'hidden' ||
      style.visibility === 'collapse'
    )
      return null
  }
  return box(element)
}

function verticalChrome(element: HTMLElement): number {
  const style = getComputedStyle(element)
  return (
    pixels(style.paddingTop) +
    pixels(style.paddingBottom) +
    pixels(style.borderTopWidth) +
    pixels(style.borderBottomWidth)
  )
}

/**
 * One view's DOM lifetime. The controller owns identities, retention and numeric
 * caches; these maps hold only connected targets and CSS box conversions. RT
 * replicates backend state, whereas these handles belong to this browser mount.
 */
export function createTableViewportGeometry(
  controller: TableViewportGeometryView,
) {
  const revision = signal(0)
  const tracks = signal<TableColumnTracks | null>(null)
  const trackWidths = signal<readonly number[]>([])
  const reasonFloors = signal({ wide: 0, card: 0 })
  let root: HTMLElement | null = null
  let table: HTMLTableElement | null = null
  let columnKeys: readonly string[] = []
  let awaitingAutoLayout = false
  const bindings = new Map<string, object>()
  const elements = new Map<string, RecordElements>()
  const shells = new Map<string, Shell>()
  let registration: TableGeometryRegistration | null = null
  let observer: ResizeObserver | undefined
  let mutations: MutationObserver | undefined
  let frame: number | null = null
  let mounted = false
  let stopped = false
  let signature = ''
  let wideContainerWidth = 0
  let calibratedLayout: object | null = null
  let corrections = 0
  let focused: HTMLElement | null = null
  let releaseFocusFallback: (() => void) | undefined
  let focusRoot: HTMLElement | null = null

  function key(occurrence: number, projection: TableProjection): string {
    return `${projection}:${occurrence}`
  }

  function probe(projection: TableProjection): HTMLElement | null {
    return (
      root?.querySelector<HTMLElement>(`.hilos-table-room-${projection}`) ??
      null
    )
  }

  function shell(record: RecordElements): Shell {
    const room = probe(record.projection)
    const fallback = room?.querySelector<HTMLElement>('.hilos-table-fallback')
    const main = record.main
    const style = main ? getComputedStyle(main) : null
    let chrome = 0
    let gap = 0
    if (record.projection === 'card' && main && style) {
      chrome = verticalChrome(main)
      const body = main.querySelector<HTMLElement>('.card-body')
      if (body) chrome += verticalChrome(body)
      gap = pixels(style.marginTop) + pixels(style.marginBottom)
    } else if (table && main) {
      const cell = main.querySelector<HTMLElement>('td')
      if (cell) {
        const cellStyle = getComputedStyle(cell)
        const tableStyle = getComputedStyle(table)
        const collapsed = tableStyle.borderCollapse === 'collapse'
        chrome =
          (pixels(cellStyle.borderTopWidth) +
            pixels(cellStyle.borderBottomWidth)) /
          (collapsed ? 2 : 1)
        if (!collapsed)
          gap = pixels(tableStyle.borderSpacing.split(' ').at(-1) ?? '')
        // The static replacement twin has the same border model and zero cell
        // padding. Reading its real row-to-block difference accounts for used
        // collapsed borders rather than adding the declared borders twice.
        const replacement = room?.querySelector<HTMLElement>(
          '.hilos-table-replacement-row',
        )
        const content = room?.querySelector<HTMLElement>(
          '.hilos-table-reason-twins',
        )
        const outer = box(replacement)
        const inner = box(content)
        if (outer && inner) chrome = Math.max(0, outer.height - inner.height)
      }
    }
    const result = {
      chrome,
      gap,
      fallback:
        (fallback ? pixels(getComputedStyle(fallback).height) : 0) +
        chrome +
        gap,
    }
    shells.set(key(record.occurrence, record.projection), result)
    return result
  }

  function read(measurement: TableGeometryMeasurement): TableGeometrySample {
    const { rowKey, occurrence } = measurement.record
    const projection = measurement.layout.projection
    const record = elements.get(key(occurrence, projection))
    const main = record?.rowKey === rowKey ? visibleBox(record.main) : null
    if (!record || !main) return { measurement, extent: null, workGap: 0 }
    const work = box(record.work)
    const detail = box(record.detail)
    const metrics = shell(record)
    const reserve = record.work?.classList.contains('hilos-table-work-reserve')
      ? registration?.record(rowKey, projection)?.workReserve
      : null
    const workHeight = work ? (reserve?.work ?? work.height) : 0
    if (projection === 'card') {
      const detailHeight = detail?.height ?? 0
      const previous = record.work?.previousElementSibling
      const previousBox = previous instanceof HTMLElement ? box(previous) : null
      // Adjacent block margins may collapse. The previous block keeps its
      // bottom margin when work disappears, so reserve only the added gap.
      const addedWorkGap =
        work && previousBox && previous instanceof HTMLElement
          ? Math.max(
              0,
              work.top -
                previousBox.bottom -
                pixels(getComputedStyle(previous).marginBottom),
            )
          : record.work
            ? pixels(getComputedStyle(record.work).marginTop)
            : 0
      const workGap = work && record.work ? (reserve?.gap ?? addedWorkGap) : 0
      const detailGap =
        detail && record.detail
          ? pixels(getComputedStyle(record.detail).marginBottom)
          : 0
      const gap = metrics.gap + workGap + detailGap
      return {
        measurement,
        extent: {
          main: Math.max(
            0,
            main.height - workHeight - detailHeight - workGap - detailGap,
          ),
          work: workHeight,
          detail: detailHeight,
          gap,
          total: main.height + metrics.gap,
        },
        workGap,
      }
    }
    const parts = [main, work, detail].filter(
      (rect): rect is DOMRect => rect !== null,
    )
    const last = parts.at(-1)!
    const total = last.bottom - main.top + metrics.gap
    const occupied = main.height + workHeight + (detail?.height ?? 0)
    const gap = Math.max(0, total - occupied)
    // The empty reserve already packs its former leading gap inside its box.
    // Tell the owner that gap is present so departure cannot add it a second time.
    const workGap = work
      ? Math.min(gap, reserve?.gap ?? Math.max(0, work.top - main.bottom))
      : 0
    return {
      measurement,
      extent: {
        main: main.height,
        work: workHeight,
        detail: detail?.height ?? 0,
        gap,
        total: occupied + gap,
      },
      workGap,
    }
  }

  function readTracks(): TableColumnTracks | null {
    // Headers and an initial empty tile have not sized any live content. Both
    // synchronous capture and post-commit measurement must wait for a connected
    // live wide row; otherwise the first append inherits empty-only fixed tracks.
    // Once sampled, the owner retains those tracks through all-placeholder/append.
    const live = [...elements.values()].some((record) => {
      if (record.projection !== 'wide') return false
      const state = registration?.record(record.rowKey, 'wide')
      return (
        state?.reason === null &&
        state.record.occurrence === record.occurrence &&
        visibleBox(record.main) !== null
      )
    })
    if (!live || awaitingAutoLayout) return null
    const node = table
    const rect = node ? box(node) : null
    const cells = node
      ? [...node.querySelectorAll<HTMLElement>('thead tr > th')]
      : []
    const keys = columnKeys
    if (!rect || cells.length !== keys.length) return null
    const boxes = cells
      .map(box)
      .filter((cell): cell is DOMRect => cell !== null)
    if (boxes.length !== keys.length) return null
    return {
      columnKeys: [...keys],
      widths: boxes.map(
        (cell, index) => (boxes[index + 1]?.left ?? cell.right) - cell.left,
      ),
      tableWidth: rect.width,
    }
  }

  function rememberFocus(): void {
    if (!mounted || stopped || !root?.isConnected) return
    const active = root?.ownerDocument.activeElement
    if (active instanceof HTMLElement && root?.contains(active))
      focused = active
  }

  function restoreFocus(): void {
    const previous = focused
    focused = null
    if (!previous || previous.isConnected || !root) return
    // A user who moved focus elsewhere during the update keeps that choice.
    const active = root.ownerDocument.activeElement
    if (active && active !== root.ownerDocument.body) return
    focusFallback()?.focus({ preventScroll: true })
  }

  function focusFallback(): HTMLElement | null {
    const node = root
    if (!mounted || stopped || !node || !visibleBox(node)) return null
    const search = [
      ...node.querySelectorAll<HTMLElement>('[data-id="hilos-table-search"]'),
    ].find((node) => {
      const style = getComputedStyle(node)
      return (
        visibleBox(node) !== null &&
        style.visibility !== 'hidden' &&
        style.visibility !== 'collapse' &&
        !node.closest('[inert], [hidden]') &&
        !node.matches(':disabled')
      )
    })
    return search ?? node
  }

  function bindFocusFallback(): void {
    if (focusRoot === root) return
    releaseFocusFallback?.()
    focusRoot = root
    releaseFocusFallback = focusRoot
      ? registerFocusReturnFallback(focusRoot, focusFallback)
      : undefined
  }

  function capture(request: TableGeometryCaptureRequest) {
    if (!mounted || stopped)
      return {
        records: request.records.map((measurement) => ({
          measurement,
          extent: null,
          workGap: 0,
        })),
        tracks: { layout: request.layout, tracks: null },
      }
    rememberFocus()
    return {
      records: request.records.map(read),
      tracks: { layout: request.layout, tracks: readTracks() },
    }
  }

  function publish(): void {
    if (!registration) return
    const next = registration.tracks()
    if (next !== tracks()) {
      tracks.set(next)
      trackWidths.set(next?.widths ?? [])
      corrections = 0
    }
    revision.update((value) => value + 1)
  }

  function layoutSignature(): string {
    const node = root
    if (!node) return ''
    const width = box(node)?.width ?? 0
    if (width <= 0) return ''
    const wideWidth = box(table?.parentElement)?.width ?? 0
    if (wideWidth > 0) wideContainerWidth = wideWidth
    const style = getComputedStyle(node)
    return [
      width,
      wideContainerWidth || width,
      // Zoom can change wrapping or used border widths even when a fixed
      // container keeps the same local width. Release its old track latch.
      layoutZoom(node),
      table ? layoutZoom(table) : 1,
      style.fontFamily,
      style.fontSize,
      style.lineHeight,
      style.fontWeight,
      style.fontStyle,
      style.letterSpacing,
      style.wordSpacing,
      getComputedStyle(node.ownerDocument.documentElement).fontSize,
      ...[table, table?.parentElement].flatMap((element) => {
        if (!element) return []
        const font = getComputedStyle(element)
        return [
          font.fontFamily,
          font.fontSize,
          font.lineHeight,
          font.fontWeight,
          font.fontStyle,
          font.letterSpacing,
          font.wordSpacing,
        ]
      }),
    ].join('|')
  }

  function invalidate(): void {
    awaitingAutoLayout = true
    registration?.invalidateLayout('wide')
    registration?.invalidateLayout('card')
    calibratedLayout = null
  }

  function schedule(): void {
    if (!mounted || stopped || frame !== null) return
    frame = requestAnimationFrame(() => {
      frame = null
      if (stopped || !mounted || checkLayout() || awaitingAutoLayout) return
      measure()
    })
  }

  function checkLayout(): boolean {
    const next = layoutSignature()
    if (next === '') return false
    const changed = signature !== '' && signature !== next
    signature = next
    if (changed) invalidate()
    return changed
  }

  function measure(): void {
    const owner = registration
    if (!owner || !mounted || stopped || !root?.isConnected) return
    bindFocusFallback()
    if (root) observer?.observe(root)
    if (table?.parentElement) observer?.observe(table.parentElement)
    const floors = { wide: 0, card: 0 }
    for (const projection of ['wide', 'card'] as const) {
      floors[projection] =
        box(
          probe(projection)?.querySelector<HTMLElement>(
            '.hilos-table-reason-twins',
          ) ?? undefined,
        )?.height ?? 0
    }
    if (
      floors.wide !== reasonFloors().wide ||
      floors.card !== reasonFloors().card
    )
      reasonFloors.set(floors)
    for (const record of elements.values()) {
      const measurement = owner.measurement(record.rowKey, record.projection)
      if (!measurement || measurement.record.occurrence !== record.occurrence)
        continue
      const state = owner.record(record.rowKey, record.projection)
      if (!state) continue
      if (state.reason === null) {
        const sample = read(measurement)
        if (
          sample.extent &&
          JSON.stringify(sample.extent) !== JSON.stringify(state.live)
        )
          owner.measure(sample)
      } else {
        // A never-shown projection gets its generic base on first display.
        // Hidden chrome and zero rectangles cannot allocate or grow that base.
        if (!visibleBox(record.main)) continue
        const previous = shells.get(key(record.occurrence, record.projection))
        const metrics = shell(record)
        if (
          previous &&
          (previous.chrome !== metrics.chrome || previous.gap !== metrics.gap)
        )
          revision.update((value) => value + 1)
        const reason = box(
          record.main?.querySelector<HTMLElement>(
            '.hilos-table-record-space > span',
          ),
        )
        const floor = (reason?.height ?? 0) + metrics.chrome + metrics.gap
        if (state.retained === null || state.retained < floor)
          owner.allocate(measurement, metrics.fallback, floor)
      }
    }
    if (owner.tracks() === null)
      owner.measureTracks(owner.layout('wide'), readTracks())
    calibrate()
    restoreFocus()
  }

  // Convert sampled used boundaries to col widths while the original content
  // is still mounted. Fractional border/residual corrections are DOM concerns,
  // not another retention policy or an update to the core's latched tracks.
  function calibrate(): void {
    const target = tracks()
    const node = table
    const owner = registration
    if (!target || !node?.classList.contains('hilos-table-fixed') || !owner)
      return
    if (calibratedLayout !== owner.layout('wide')) {
      calibratedLayout = owner.layout('wide')
      corrections = 0
    }
    const actual = readTracks()
    if (!actual || corrections >= 4) return
    const widths = trackWidths().map((width, index) =>
      Math.max(0, width + target.widths[index]! - actual.widths[index]!),
    )
    if (
      widths.some(
        (width, index) => Math.abs(width - trackWidths()[index]!) > 1 / 64,
      )
    ) {
      corrections++
      trackWidths.set(widths)
    }
  }

  /** Bind exactly this node/tuple; a retired cleanup cannot unbind its successor. */
  function bind(
    rowKey: string,
    occurrence: number,
    projection: TableProjection,
    part: Part,
    node: HTMLElement,
  ): () => void {
    if (stopped) return () => {}
    const id = key(occurrence, projection)
    const bindingKey = `${id}:${part}`
    const ticket = {}
    let record = elements.get(id)
    if (!record) {
      record = { rowKey, occurrence, projection }
      elements.set(id, record)
    }
    const previous = record[part]
    if (previous) observer?.unobserve(previous)
    record[part] = node
    bindings.set(bindingKey, ticket)
    observer?.observe(node)
    return () => {
      if (bindings.get(bindingKey) !== ticket || record[part] !== node) return
      observer?.unobserve(node)
      delete record[part]
      bindings.delete(bindingKey)
      if (!record.main && !record.work && !record.detail) {
        elements.delete(id)
        shells.delete(id)
      }
    }
  }

  function recordPixels(
    rowKey: string,
    occurrence: number,
    projection: TableProjection,
  ): number | undefined {
    void revision()
    const state = registration?.record(rowKey, projection)
    const metrics = shells.get(key(occurrence, projection))
    if (
      !metrics ||
      state?.retained == null ||
      state.record.occurrence !== occurrence
    )
      return undefined
    // Card padding and its outside gap are rem-based. Sass subtracts their
    // current values from the total in the same layout, before an observer
    // could compensate a font change in a later frame.
    if (projection === 'card') return state.retained
    return Math.max(0, state.retained - metrics.chrome - metrics.gap)
  }

  function workPixels(rowKey: string, projection: TableProjection): number {
    void revision()
    const state = registration?.record(rowKey, projection)
    const gap =
      state && projection === 'wide'
        ? (shells.get(key(state.record.occurrence, projection))?.gap ?? 0)
        : 0
    return Math.max(0, (state?.workReserve?.total ?? 0) - gap)
  }

  registration = controller.register(capture)
  const unsubscribe = subscribeSignal(registration.revision, publish)
  publish()

  // Called only from Angular's browser after-render lifecycle. Construction and
  // target binding are safe during SSR; no document/window is read before here.
  function mount(): void {
    mounted = true
    observer =
      typeof ResizeObserver === 'undefined'
        ? undefined
        : new ResizeObserver(schedule)
    mutations =
      typeof MutationObserver === 'undefined'
        ? undefined
        : new MutationObserver(schedule)
    for (let node: HTMLElement | null = root; node; node = node.parentElement)
      mutations?.observe(node, {
        attributes: true,
        attributeFilter: ['class', 'style', 'hidden'],
      })
    for (const node of [table, table?.parentElement])
      if (node)
        mutations?.observe(node, {
          attributes: true,
          attributeFilter: ['class', 'style', 'hidden'],
        })
    for (const record of elements.values())
      for (const node of [record.main, record.work, record.detail])
        if (node) observer?.observe(node)
    root?.ownerDocument.addEventListener('visibilitychange', schedule)
    root?.ownerDocument.defaultView?.addEventListener('resize', schedule)
    root?.ownerDocument.fonts?.addEventListener('loadingdone', fontsChanged)
    void root?.ownerDocument.fonts?.ready.then(() => {
      if (!stopped) fontsChanged()
    })
    signature = layoutSignature()
  }

  /** Reconcile current targets only after a real Angular commit. */
  function commit(
    node: HTMLElement,
    wide: HTMLTableElement,
    keys: readonly string[],
  ): void {
    if (stopped) return
    root = node
    table = wide
    if (!mounted) mount()
    if (columnKeys.length && columnKeys.join('\0') !== keys.join('\0')) {
      columnKeys = [...keys]
      invalidate()
      return
    }
    columnKeys = [...keys]
    if (checkLayout()) return
    // The invalidation publication must reach the template before auto layout
    // is sampled. A still-fixed DOM is never a new layout's measurement.
    if (awaitingAutoLayout && wide.classList.contains('hilos-table-fixed'))
      return
    awaitingAutoLayout = false
    measure()
  }

  function fontsChanged(): void {
    if (!stopped) {
      invalidate()
      schedule()
    }
  }

  function dispose(): void {
    if (stopped) return
    stopped = true
    releaseFocusFallback?.()
    releaseFocusFallback = undefined
    focusRoot = null
    if (frame !== null) cancelAnimationFrame(frame)
    frame = null
    observer?.disconnect()
    mutations?.disconnect()
    root?.ownerDocument.removeEventListener('visibilitychange', schedule)
    root?.ownerDocument.defaultView?.removeEventListener('resize', schedule)
    root?.ownerDocument.fonts?.removeEventListener('loadingdone', fontsChanged)
    unsubscribe()
    registration?.dispose()
    registration = null
    elements.clear()
    bindings.clear()
    shells.clear()
    focused = null
    root = null
    table = null
  }
  return {
    revision: revision.asReadonly(),
    tracks: tracks.asReadonly(),
    trackWidths: trackWidths.asReadonly(),
    reasonFloors: reasonFloors.asReadonly(),
    bind,
    recordPixels,
    workPixels,
    commit,
    dispose,
  }
}

/** Internal Angular adapter; it is deliberately absent from SDK barrels. */
export type TableViewportGeometryAdapter = ReturnType<
  typeof createTableViewportGeometry
>
