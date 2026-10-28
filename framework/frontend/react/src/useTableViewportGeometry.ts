import {
  useLayoutEffect,
  useMemo,
  useReducer,
  useState,
  type RefObject,
} from 'react'
import {
  captureTableFocusReturnTarget,
  registerFocusReturnFallback,
  subscribeSignal,
  type ReadonlySignal,
  type TableColumnTracks,
  type TableFocusReturnRow,
  type TableGeometryCaptureRequest,
  type TableGeometryMeasurement,
  type TableGeometryRecord,
  type TableGeometryRegistration,
  type TableGeometrySample,
  type TableProjection,
  type TableRecordGeometry,
  type TableViewportGeometryView,
} from '@hilos/core/base'
import { useSignal } from './useSignal.js'

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

function verticalChrome(element: HTMLElement): number {
  const style = getComputedStyle(element)
  return (
    pixels(style.paddingTop) +
    pixels(style.paddingBottom) +
    pixels(style.borderTopWidth) +
    pixels(style.borderBottomWidth)
  )
}

interface Bindings {
  readonly elements: Map<string, RecordElements>
  mount: ReturnType<typeof createMount> | null
}

const EMPTY_REVISION: ReadonlySignal<number> = { get: () => 0 }

/**
 * The controller owns numeric geometry. These DOM handles belong to one browser
 * mount only, so backend Hilos::$rt replication (runtime/rt-context.md and
 * architecture/truth-source.md) and durable DB storage have no reader here.
 * Registration and measurement happen only after commit; render is read-only.
 * Main refs identify the whole card or main tr; wide work/detail refs identify
 * adjacent rows, card work/detail refs identify blocks inside that card's body.
 * Static room-<projection> probes provide fallback/reason/replacement chrome.
 * The caller keeps a stable root with tabindex=-1 and keys rows by occurrence.
 */
export function useTableViewportGeometry(
  controller: TableViewportGeometryView,
  root: RefObject<HTMLElement | null>,
  table: RefObject<HTMLTableElement | null>,
  columnKeys: readonly string[],
  isCurrentRecord?: (record: TableGeometryRecord) => boolean,
) {
  const bindings = useMemo<Bindings>(
    () => ({ elements: new Map(), mount: null }),
    [controller],
  )
  const [mount, setMount] = useState<ReturnType<typeof createMount> | null>(
    null,
  )
  const [, refresh] = useReducer((value: number) => value + 1, 0)
  const current = mount?.bindings === bindings && mount.active() ? mount : null
  const revision = useSignal(current?.registration.revision ?? EMPTY_REVISION)

  useLayoutEffect(() => {
    const next = createMount(controller, root, table, bindings, refresh)
    bindings.mount = next
    setMount(next)
    return () => {
      bindings.mount = null
      next.dispose()
    }
  }, [controller, root, table, bindings])

  // Every committed row change gets a pre-paint measurement. Invalidating fixed
  // tracks takes a second commit with auto layout before any new track read.
  useLayoutEffect(() => {
    bindings.mount?.commit(columnKeys)
  })

  const bind = useMemo(
    () =>
      (
        rowKey: string,
        occurrence: number,
        projection: TableProjection,
        part: Part,
        node: HTMLElement | null,
      ): void => {
        const id = `${projection}:${occurrence}`
        let record = bindings.elements.get(id)
        if (!record && !node) return
        if (!record) {
          record = { rowKey, occurrence, projection }
          bindings.elements.set(id, record)
        }
        const previous = record[part]
        if (previous === node) return
        bindings.mount?.rememberFocus()
        bindings.mount?.unobserve(previous)
        record[part] = node ?? undefined
        if (node) bindings.mount?.observe(node)
        if (!record.main && !record.work && !record.detail) {
          bindings.elements.delete(id)
        }
      },
    [bindings],
  )

  const layoutChanged = useMemo(
    () => () => bindings.mount?.invalidate(),
    [bindings],
  )

  return {
    revision,
    tracks: current?.tracks ?? null,
    trackWidths: current?.trackWidths ?? [],
    reasonFloors: current?.reasonFloors ?? { wide: 0, card: 0 },
    bind,
    recordPixels: (
      rowKey: string,
      occurrence: number,
      projection: TableProjection,
    ) => current?.recordPixels(rowKey, occurrence, projection),
    workPixels: (rowKey: string, projection: TableProjection) =>
      current?.workPixels(rowKey, projection) ?? 0,
    // Text views consume the existing mount's exact committed scope reader.
    recordReader: (
      rowKey: string,
      occurrence: number,
      projection: TableProjection,
    ): (() => TableGeometryRecord | null) =>
      current?.recordReader(rowKey, occurrence, projection, isCurrentRecord) ??
      (() => null),
    layoutChanged,
  }
}

/** A fresh effect setup owns every callback, including StrictMode replay. */
function createMount(
  controller: TableViewportGeometryView,
  root: RefObject<HTMLElement | null>,
  table: RefObject<HTMLTableElement | null>,
  bindings: Bindings,
  refresh: () => void,
) {
  const elements = bindings.elements
  const shells = new Map<string, Shell>()
  // Immutable owner snapshots, refreshed outside render: record() lazily mints
  // measurement state. Rendering a newly published occurrence must not do that.
  let snapshots = new Map<string, TableRecordGeometry>()
  let tracks: TableColumnTracks | null = null
  let trackWidths: readonly number[] = []
  let reasonFloors = { wide: 0, card: 0 }
  let columnKeys: readonly string[] = []
  let frame: number | null = null
  let stopped = false
  let signature = ''
  let wideContainerWidth = 0
  let calibratedLayout: object | null = null
  let corrections = 0
  let focused: HTMLElement | null = null
  let releaseFocusFallback: (() => void) | undefined
  let focusRoot: HTMLElement | null = null
  let releasingTracks = false

  function key(occurrence: number, projection: TableProjection): string {
    return `${projection}:${occurrence}`
  }

  function probe(projection: TableProjection): HTMLElement | null {
    return (
      root.current?.querySelector<HTMLElement>(
        `.hilos-table-room-${projection}`,
      ) ?? null
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
    } else if (table.current && main) {
      const cell = main.querySelector<HTMLElement>('td')
      if (cell) {
        const cellStyle = getComputedStyle(cell)
        const tableStyle = getComputedStyle(table.current)
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
    if (
      stopped ||
      registration?.measurement(
        measurement.record.rowKey,
        measurement.layout.projection,
      ) !== measurement ||
      (measurement.layout.projection === 'wide' &&
        releasingTracks &&
        table.current?.classList.contains('hilos-table-fixed'))
    )
      return { measurement, extent: null, workGap: 0 }
    const { rowKey, occurrence } = measurement.record
    const projection = measurement.layout.projection
    const record = elements.get(key(occurrence, projection))
    const main = record?.rowKey === rowKey ? box(record.main) : null
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
      const mainHeight =
        main.height - workHeight - detailHeight - workGap - detailGap
      if (mainHeight < 0) return { measurement, extent: null, workGap: 0 }
      return {
        measurement,
        extent: {
          main: mainHeight,
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
    const workGap = work
      ? Math.min(gap, Math.max(0, work.top - main.bottom))
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
    if (
      releasingTracks &&
      table.current?.classList.contains('hilos-table-fixed')
    )
      return null
    const live = [...elements.values()].some((record) => {
      if (record.projection !== 'wide') return false
      const state = registration?.record(record.rowKey, 'wide')
      return (
        state?.reason === null &&
        state.record.occurrence === record.occurrence &&
        box(record.main) !== null
      )
    })
    if (!live) return null
    const node = table.current
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
    const widths = boxes.map(
      (cell, index) => (boxes[index + 1]?.left ?? cell.right) - cell.left,
    )
    if (widths.some((width) => !Number.isFinite(width) || width <= 0))
      return null
    return { columnKeys: [...keys], widths, tableWidth: rect.width }
  }

  function rememberFocus(): void {
    if (stopped || !root.current?.isConnected) return
    const active = root.current?.ownerDocument.activeElement
    if (active instanceof HTMLElement && root.current?.contains(active))
      focused = active
  }

  function restoreFocus(): void {
    const previous = focused
    focused = null
    if (!previous || previous.isConnected || !root.current) return
    // A user who moved focus elsewhere during the update keeps that choice.
    const active = root.current.ownerDocument.activeElement
    if (active && active !== root.current.ownerDocument.body) return
    focusFallback()?.focus({ preventScroll: true })
  }

  function focusFallback(): HTMLElement | null {
    const node = root.current
    if (stopped || !node || !box(node)) return null
    const search = [
      ...node.querySelectorAll<HTMLElement>('[data-id="hilos-table-search"]'),
    ].find((node) => {
      const style = getComputedStyle(node)
      return (
        box(node) !== null &&
        style.visibility !== 'hidden' &&
        style.visibility !== 'collapse' &&
        !node.closest('[inert], [hidden]') &&
        !node.matches(':disabled')
      )
    })
    return search ?? node
  }

  function bindFocusFallback(): void {
    if (focusRoot === root.current) return
    releaseFocusFallback?.()
    focusRoot = root.current
    const node = focusRoot
    releaseFocusFallback = node
      ? registerFocusReturnFallback(node, focusFallback, (opener) =>
          captureTableFocusReturnTarget(opener, () => {
            if (
              stopped ||
              !node.isConnected ||
              root.current !== node ||
              bindings.mount?.registration !== registration
            )
              return []
            const rows: TableFocusReturnRow[] = []
            for (const record of elements.values()) {
              const state = registration.record(
                record.rowKey,
                record.projection,
              )
              if (
                state?.reason !== null ||
                state.record.occurrence !== record.occurrence
              )
                continue
              rows.push({
                rowKey: record.rowKey,
                projection: record.projection,
                parts: [record.main, record.work, record.detail].filter(
                  (part): part is HTMLElement =>
                    !!part?.isConnected && node.contains(part),
                ),
              })
            }
            return rows
          }),
        )
      : undefined
  }

  function capture(request: TableGeometryCaptureRequest) {
    rememberFocus()
    return {
      records: request.records.map(read),
      tracks: { layout: request.layout, tracks: readTracks() },
    }
  }

  function publish(): void {
    if (stopped) return
    const previous = snapshots
    snapshots = new Map()
    let scopeChanged = false
    for (const record of elements.values()) {
      const state = registration.record(record.rowKey, record.projection)
      if (state?.record.occurrence !== record.occurrence) continue
      const id = `${record.projection}:${record.rowKey}`
      snapshots.set(id, state)
      const before = previous.get(id)
      if (before?.record !== state.record || before.reason !== state.reason)
        scopeChanged = true
    }
    // A new ref can arrive after the owner's notification, including a hidden
    // projection with no measurable box. Bind its identity on the next render.
    if (scopeChanged || previous.size !== snapshots.size) refresh()
    const next = registration.tracks()
    if (next !== tracks) {
      tracks = next
      trackWidths = next?.widths ?? []
      corrections = 0
    }
  }

  /** Capture one committed identity; an unbound reader stays unbound forever. */
  function recordReader(
    rowKey: string,
    occurrence: number,
    projection: TableProjection,
    isCurrentRecord?: (record: TableGeometryRecord) => boolean,
  ): () => TableGeometryRecord | null {
    const id = `${projection}:${rowKey}`
    const identity = snapshots.get(id)?.record
    return () => {
      if (
        stopped ||
        bindings.mount?.registration !== registration ||
        identity?.occurrence !== occurrence ||
        (isCurrentRecord && !isCurrentRecord(identity))
      )
        return null
      const state = snapshots.get(id)
      return state?.reason === null && state.record === identity
        ? identity
        : null
    }
  }

  function layoutSignature(): string {
    const node = root.current
    if (!node) return ''
    const width = box(node)?.width ?? 0
    if (width <= 0) return ''
    const wideWidth = box(table.current?.parentElement)?.width ?? 0
    if (wideWidth > 0) wideContainerWidth = wideWidth
    const style = getComputedStyle(node)
    const header = table.current?.querySelector('thead th')
    const headerStyle = header ? getComputedStyle(header) : null
    return [
      width,
      wideContainerWidth || width,
      // Zoom can change wrapping or used border widths even when a fixed
      // container keeps the same local width. Release its old track latch.
      layoutZoom(node),
      style.fontFamily,
      style.fontSize,
      style.lineHeight,
      style.fontWeight,
      style.fontStyle,
      style.letterSpacing,
      style.wordSpacing,
      style.paddingLeft,
      style.paddingRight,
      getComputedStyle(node.ownerDocument.documentElement).fontSize,
      table.current ? getComputedStyle(table.current).borderCollapse : '',
      table.current ? getComputedStyle(table.current).borderSpacing : '',
      headerStyle?.fontFamily,
      headerStyle?.fontSize,
      headerStyle?.lineHeight,
      headerStyle?.paddingTop,
      headerStyle?.paddingBottom,
      headerStyle?.paddingLeft,
      headerStyle?.paddingRight,
    ].join('|')
  }

  function invalidate(): void {
    if (stopped) return
    releasingTracks = true
    registration?.invalidateLayout('wide')
    registration?.invalidateLayout('card')
    calibratedLayout = null
    refresh()
  }

  function schedule(): void {
    if (stopped || frame !== null) return
    frame = window.requestAnimationFrame(() => {
      if (stopped) return
      frame = null
      const next = layoutSignature()
      if (next === '') return
      const changed = signature !== '' && signature !== next
      signature = next
      if (changed) {
        // Let React commit without the old fixed colgroup widths before measuring the
        // new auto layout. Reading the still-fixed DOM would relatch old tracks.
        invalidate()
        return
      }
      measure()
    })
  }

  function measure(): void {
    const owner = registration
    if (!owner || stopped || !root.current?.isConnected) return
    bindFocusFallback()
    const nextSignature = layoutSignature()
    if (signature && nextSignature && signature !== nextSignature) {
      signature = nextSignature
      invalidate()
      return
    }
    if (nextSignature) signature = nextSignature
    if (releasingTracks) {
      if (table.current?.classList.contains('hilos-table-fixed')) return
      releasingTracks = false
    }
    if (root.current) observer?.observe(root.current)
    if (table.current?.parentElement)
      observer?.observe(table.current.parentElement)
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
      floors.wide !== reasonFloors.wide ||
      floors.card !== reasonFloors.card
    ) {
      reasonFloors = floors
      refresh()
    }
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
        if (!box(record.main)) continue
        const previous = shells.get(key(record.occurrence, record.projection))
        const metrics = shell(record)
        if (
          previous &&
          (previous.chrome !== metrics.chrome || previous.gap !== metrics.gap)
        )
          refresh()
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
    const target = tracks
    const node = table.current
    const owner = registration
    if (!target || !node?.classList.contains('hilos-table-fixed') || !owner)
      return
    if (calibratedLayout !== owner.layout('wide')) {
      calibratedLayout = owner.layout('wide')
      corrections = 0
    }
    const actual = readTracks()
    if (!actual || corrections >= 4) return
    const widths = trackWidths.map((width, index) =>
      Math.max(0, width + target.widths[index]! - actual.widths[index]!),
    )
    if (
      widths.some(
        (width, index) => Math.abs(width - trackWidths[index]!) > 1 / 64,
      )
    ) {
      corrections++
      trackWidths = widths
      refresh()
    }
  }

  function recordPixels(
    rowKey: string,
    occurrence: number,
    projection: TableProjection,
  ): number | undefined {
    const state = snapshots.get(`${projection}:${rowKey}`)
    const metrics = shells.get(key(occurrence, projection))
    if (
      !metrics ||
      state?.record.occurrence !== occurrence ||
      state.retained == null
    )
      return undefined
    // Card padding and its outside gap are rem-based. Sass subtracts their
    // current values from the total in the same layout, before an observer
    // could compensate a font change in a later frame.
    if (projection === 'card') return state.retained
    return Math.max(0, state.retained - metrics.chrome - metrics.gap)
  }

  function workPixels(rowKey: string, projection: TableProjection): number {
    const state = snapshots.get(`${projection}:${rowKey}`)
    const gap =
      state && projection === 'wide'
        ? (shells.get(key(state.record.occurrence, projection))?.gap ?? 0)
        : 0
    return Math.max(0, (state?.workReserve?.total ?? 0) - gap)
  }

  const registration: TableGeometryRegistration = controller.register(capture)
  const unsubscribe = subscribeSignal(registration.revision, publish)
  const document = root.current!.ownerDocument
  const window = document.defaultView!
  const observer =
    typeof window.ResizeObserver === 'undefined'
      ? undefined
      : new window.ResizeObserver(schedule)
  const mutations =
    typeof window.MutationObserver === 'undefined'
      ? undefined
      : new window.MutationObserver(schedule)
  for (
    let node: HTMLElement | null = root.current;
    node;
    node = node.parentElement
  )
    mutations?.observe(node, {
      attributes: true,
      attributeFilter: ['class', 'style', 'hidden'],
    })
  document.addEventListener('visibilitychange', schedule)
  window.addEventListener('resize', schedule)
  document.fonts?.addEventListener('loadingdone', fontsChanged)
  void document.fonts?.ready.then(() => {
    if (!stopped) fontsChanged()
  })
  for (const record of elements.values())
    for (const node of [record.main, record.work, record.detail])
      if (node) observer?.observe(node)
  bindFocusFallback()
  publish()

  function fontsChanged(event?: Event): void {
    if (stopped) return
    const faces = (event as FontFaceSetLoadEvent | undefined)?.fontfaces
    const familyName = (name: string) =>
      name
        .trim()
        .replace(/^["']|["']$/g, '')
        .toLowerCase()
    // FontFaceSet also reports graphical faces used only by pseudo-elements,
    // including repeated bootstrap-icons loads during native screenshots. Their
    // chrome is still measured, but they do not change the table's text tracks.
    // Do not release those tracks with no live row from which to relatch them.
    const loaded =
      faces && new Set(faces.map((font) => familyName(font.family)))
    const nodes = root.current
      ? [root.current, ...root.current.querySelectorAll<HTMLElement>('*')]
      : []
    const textChanged =
      !loaded ||
      nodes.some((node) =>
        getComputedStyle(node)
          .fontFamily.split(',')
          .some((family) => loaded.has(familyName(family))),
      )
    if (textChanged) invalidate()
    schedule()
  }

  return {
    bindings,
    registration,
    get tracks() {
      return tracks
    },
    get trackWidths() {
      return trackWidths
    },
    get reasonFloors() {
      return reasonFloors
    },
    active: () => !stopped,
    rememberFocus,
    recordPixels,
    recordReader,
    workPixels,
    invalidate,
    observe: (node: HTMLElement) => observer?.observe(node),
    unobserve: (node: HTMLElement | undefined) => {
      if (node) observer?.unobserve(node)
    },
    commit(keys: readonly string[]): void {
      if (stopped) return
      if (columnKeys.join('\0') !== keys.join('\0')) {
        const hadKeys = columnKeys.length > 0
        columnKeys = [...keys]
        if (hadKeys) {
          invalidate()
          return
        }
      }
      for (const id of shells.keys()) if (!elements.has(id)) shells.delete(id)
      measure()
      publish()
    },
    dispose(): void {
      stopped = true
      releaseFocusFallback?.()
      releaseFocusFallback = undefined
      focusRoot = null
      if (frame !== null) window.cancelAnimationFrame(frame)
      observer?.disconnect()
      mutations?.disconnect()
      document.removeEventListener('visibilitychange', schedule)
      window.removeEventListener('resize', schedule)
      document.fonts?.removeEventListener('loadingdone', fontsChanged)
      unsubscribe()
      registration.dispose()
      elements.clear()
      shells.clear()
      snapshots.clear()
      tracks = null
      trackWidths = []
      focused = null
    },
  }
}
