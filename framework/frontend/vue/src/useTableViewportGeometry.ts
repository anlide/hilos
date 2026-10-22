import {
  onBeforeUpdate,
  onMounted,
  onScopeDispose,
  onUpdated,
  shallowRef,
  watch,
  type Ref,
} from 'vue'
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
export function useTableViewportGeometry(
  controller: () => TableViewportGeometryView,
  root: Ref<HTMLElement | null>,
  table: Ref<HTMLTableElement | null>,
  columnKeys: () => readonly string[],
) {
  const revision = shallowRef(0)
  const tracks = shallowRef<TableColumnTracks | null>(null)
  const trackWidths = shallowRef<readonly number[]>([])
  const reasonFloors = shallowRef({ wide: 0, card: 0 })
  const elements = new Map<string, RecordElements>()
  const shells = new Map<string, Shell>()
  let registration: TableGeometryRegistration | null = null
  let unsubscribe: (() => void) | undefined
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
      root.value?.querySelector<HTMLElement>(
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
    } else if (table.value && main) {
      const cell = main.querySelector<HTMLElement>('td')
      if (cell) {
        const cellStyle = getComputedStyle(cell)
        const tableStyle = getComputedStyle(table.value)
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
    const node = table.value
    const rect = node ? box(node) : null
    const cells = node
      ? [...node.querySelectorAll<HTMLElement>('thead tr > th')]
      : []
    const keys = columnKeys()
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
    if (!mounted || stopped || !root.value?.isConnected) return
    const active = root.value?.ownerDocument.activeElement
    if (active instanceof HTMLElement && root.value?.contains(active))
      focused = active
  }

  function restoreFocus(): void {
    const previous = focused
    focused = null
    if (!previous || previous.isConnected || !root.value) return
    // A user who moved focus elsewhere during the update keeps that choice.
    const active = root.value.ownerDocument.activeElement
    if (active && active !== root.value.ownerDocument.body) return
    focusFallback()?.focus({ preventScroll: true })
  }

  function focusFallback(): HTMLElement | null {
    const node = root.value
    if (!mounted || stopped || !node || !box(node)) return null
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
    if (focusRoot === root.value) return
    releaseFocusFallback?.()
    focusRoot = root.value
    releaseFocusFallback = focusRoot
      ? registerFocusReturnFallback(focusRoot, focusFallback)
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
    if (!registration) return
    const next = registration.tracks()
    if (next !== tracks.value) {
      tracks.value = next
      trackWidths.value = next?.widths ?? []
      corrections = 0
    }
    revision.value++
  }

  function layoutSignature(): string {
    const node = root.value
    if (!node) return ''
    const width = box(node)?.width ?? 0
    if (width <= 0) return ''
    const wideWidth = box(table.value?.parentElement)?.width ?? 0
    if (wideWidth > 0) wideContainerWidth = wideWidth
    const style = getComputedStyle(node)
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
      getComputedStyle(node.ownerDocument.documentElement).fontSize,
    ].join('|')
  }

  function invalidate(): void {
    registration?.invalidateLayout('wide')
    registration?.invalidateLayout('card')
    calibratedLayout = null
  }

  function schedule(): void {
    if (!mounted || stopped || frame !== null) return
    frame = requestAnimationFrame(() => {
      frame = null
      const next = layoutSignature()
      if (next === '') return
      const changed = signature !== '' && signature !== next
      signature = next
      if (changed) {
        // Let Vue remove the old fixed colgroup widths before measuring the
        // new auto layout. Reading the still-fixed DOM would relatch old tracks.
        invalidate()
        return
      }
      measure()
    })
  }

  function measure(): void {
    const owner = registration
    if (!owner || !mounted || stopped || !root.value?.isConnected) return
    bindFocusFallback()
    if (root.value) observer?.observe(root.value)
    if (table.value?.parentElement) observer?.observe(table.value.parentElement)
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
      floors.wide !== reasonFloors.value.wide ||
      floors.card !== reasonFloors.value.card
    )
      reasonFloors.value = floors
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
          revision.value++
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
    const target = tracks.value
    const node = table.value
    const owner = registration
    if (!target || !node?.classList.contains('hilos-table-fixed') || !owner)
      return
    if (calibratedLayout !== owner.layout('wide')) {
      calibratedLayout = owner.layout('wide')
      corrections = 0
    }
    const actual = readTracks()
    if (!actual || corrections >= 4) return
    const widths = trackWidths.value.map((width, index) =>
      Math.max(0, width + target.widths[index]! - actual.widths[index]!),
    )
    if (
      widths.some(
        (width, index) => Math.abs(width - trackWidths.value[index]!) > 1 / 64,
      )
    ) {
      corrections++
      trackWidths.value = widths
    }
  }

  function bind(
    rowKey: string,
    occurrence: number,
    projection: TableProjection,
    part: Part,
    element: unknown,
  ): void {
    if (stopped) return
    const id = key(occurrence, projection)
    let record = elements.get(id)
    const node = element instanceof HTMLElement ? element : undefined
    if (!record && !node) return
    if (!record) {
      record = { rowKey, occurrence, projection }
      elements.set(id, record)
    }
    const previous = record[part]
    if (previous === node) return
    if (previous) observer?.unobserve(previous)
    record[part] = node
    if (node) observer?.observe(node)
    if (!record.main && !record.work && !record.detail) {
      elements.delete(id)
      shells.delete(id)
    }
  }

  function recordPixels(
    rowKey: string,
    occurrence: number,
    projection: TableProjection,
  ): number | undefined {
    void revision.value
    const state = registration?.record(rowKey, projection)
    const metrics = shells.get(key(occurrence, projection))
    if (!metrics || state?.retained == null) return undefined
    // Card padding and its outside gap are rem-based. Sass subtracts their
    // current values from the total in the same layout, before an observer
    // could compensate a font change in a later frame.
    if (projection === 'card') return state.retained
    return Math.max(0, state.retained - metrics.chrome - metrics.gap)
  }

  function workPixels(rowKey: string, projection: TableProjection): number {
    void revision.value
    const state = registration?.record(rowKey, projection)
    const gap =
      state && projection === 'wide'
        ? (shells.get(key(state.record.occurrence, projection))?.gap ?? 0)
        : 0
    return Math.max(0, (state?.workReserve?.total ?? 0) - gap)
  }

  watch(
    controller,
    (next) => {
      releaseFocusFallback?.()
      releaseFocusFallback = undefined
      focusRoot = null
      unsubscribe?.()
      registration?.dispose()
      observer?.disconnect()
      if (frame !== null) {
        cancelAnimationFrame(frame)
        frame = null
      }
      elements.clear()
      shells.clear()
      tracks.value = null
      trackWidths.value = []
      focused = null
      signature = ''
      wideContainerWidth = 0
      registration = next.register(capture)
      unsubscribe = subscribeSignal(registration.revision, publish)
      publish()
      schedule()
    },
    { immediate: true, flush: 'sync' },
  )
  watch(columnKeys, (next, previous) => {
    if (next.join('\0') !== previous.join('\0')) {
      invalidate()
      schedule()
    }
  })

  onBeforeUpdate(rememberFocus)
  onUpdated(measure)
  onMounted(() => {
    mounted = true
    observer =
      typeof ResizeObserver === 'undefined'
        ? undefined
        : new ResizeObserver(schedule)
    if (root.value) observer?.observe(root.value)
    if (table.value?.parentElement) observer?.observe(table.value.parentElement)
    for (const record of elements.values())
      for (const node of [record.main, record.work, record.detail])
        if (node) observer?.observe(node)
    mutations =
      typeof MutationObserver === 'undefined'
        ? undefined
        : new MutationObserver(schedule)
    for (
      let node: HTMLElement | null = root.value?.parentElement ?? null;
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
    signature = layoutSignature()
    measure()
  })
  function fontsChanged(): void {
    if (!stopped) {
      invalidate()
      schedule()
    }
  }
  onScopeDispose(() => {
    stopped = true
    releaseFocusFallback?.()
    releaseFocusFallback = undefined
    focusRoot = null
    if (frame !== null) cancelAnimationFrame(frame)
    observer?.disconnect()
    mutations?.disconnect()
    document.removeEventListener('visibilitychange', schedule)
    window.removeEventListener('resize', schedule)
    document.fonts?.removeEventListener('loadingdone', fontsChanged)
    unsubscribe?.()
    registration?.dispose()
    registration = null
    elements.clear()
    shells.clear()
    focused = null
  })
  return {
    revision,
    tracks,
    trackWidths,
    reasonFloors,
    bind,
    recordPixels,
    workPixels,
    layoutChanged: invalidate,
  }
}
