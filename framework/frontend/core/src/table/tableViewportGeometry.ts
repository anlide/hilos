import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'
import { type TableRemovalReason } from './tablePlaceholder.js'

/** The two independent layouts of one table view. */
export type TableProjection = 'wide' | 'card'

/** Local publication boundary; identities are resolved by the geometry owner. */
export type TablePresentationChange =
  | { readonly kind: 'rows'; readonly rowKeys: readonly string[] }
  | { readonly kind: 'window' | 'reset' | 'refusal' }

/**
 * Occupied fractional CSS pixels, including the actual border model. `gap`
 * includes all separation, including the last record's outer space. Card
 * margins belong here, outside the card border box, exactly once.
 */
export interface TableRecordExtent {
  readonly main: number
  readonly work: number
  readonly detail: number
  readonly gap: number
  readonly total: number
}

/** Used tracks, calibrated by the adapter rather than summed border boxes. */
export interface TableColumnTracks {
  readonly columnKeys: readonly string[]
  readonly widths: readonly number[]
  readonly tableWidth: number
}

/** Owner-issued identity; a reused key is a different occurrence. */
export interface TableGeometryRecord {
  readonly rowKey: string
  readonly windowGeneration: number
  readonly occurrence: number
}

/** Owner-issued identity for one mount's projection and layout. */
export interface TableGeometryLayout {
  readonly mount: number
  readonly windowGeneration: number
  readonly layoutGeneration: number
  readonly projection: TableProjection
}

/** An observer keeps this ticket, never reconstructs one from a row key. */
export interface TableGeometryMeasurement {
  readonly record: TableGeometryRecord
  readonly layout: TableGeometryLayout
}

/** Numeric work space captured before the progress payload is removed. */
export interface TableWorkReserve {
  readonly work: number
  readonly gap: number
  readonly total: number
}

/** No usable measurement is explicitly `null`, including hidden projections. */
export interface TableGeometrySample {
  readonly measurement: TableGeometryMeasurement
  readonly extent: TableRecordExtent | null
  /** Part of extent.gap owned exclusively by work; not an additional gap. */
  readonly workGap: number
}

/** A synchronous capture returns only identities and numbers. */
export interface TableGeometryCapture {
  readonly records: readonly TableGeometrySample[]
  readonly tracks?: {
    readonly layout: TableGeometryLayout
    readonly tracks: TableColumnTracks | null
  }
}

/** All affected live records and the wide layout, before publication begins. */
export interface TableGeometryCaptureRequest {
  readonly records: readonly TableGeometryMeasurement[]
  readonly layout: TableGeometryLayout
}

/** One mount's numeric record state; never a raw or resolved table row. */
export interface TableRecordGeometry {
  readonly record: TableGeometryRecord
  readonly reason: TableRemovalReason | null
  readonly live: TableRecordExtent | null
  readonly workReserve: TableWorkReserve | null
  readonly retained: number | null
}

/**
 * A mount can read and write only its own measurements. Disposing releases its
 * capture callback and every number; old observer tickets then become inert.
 */
export interface TableGeometryRegistration {
  readonly revision: ReadonlySignal<number>
  measurement(
    rowKey: string,
    projection: TableProjection,
  ): TableGeometryMeasurement | null
  layout(projection: TableProjection): TableGeometryLayout
  record(
    rowKey: string,
    projection: TableProjection,
  ): TableRecordGeometry | null
  tracks(): TableColumnTracks | null
  measure(sample: TableGeometrySample): void
  measureTracks(
    layout: TableGeometryLayout,
    tracks: TableColumnTracks | null,
  ): void
  invalidateLayout(projection: TableProjection): void
  /** Generic body plus shell/gap in CSS px; Sass/rem conversion stays in the adapter. */
  allocate(
    measurement: TableGeometryMeasurement,
    fallback: number,
    reasonFloor: number,
  ): void
  dispose(): void
}

/** The controller exposes this facade, keeping window/occurrence writes private. */
export interface TableViewportGeometryView {
  register(
    capture: (request: TableGeometryCaptureRequest) => TableGeometryCapture,
  ): TableGeometryRegistration
}

interface GeometryRecord {
  readonly identity: TableGeometryRecord
  reason: TableRemovalReason | null
  workReserved: boolean
}

interface ProjectionRecord {
  measurement: TableGeometryMeasurement
  sample: {
    readonly extent: TableRecordExtent
    readonly workGap: number
  } | null
  reserve: TableWorkReserve | null
  retained: number | null
}

interface GeometryMount {
  readonly id: number
  capture:
    ((request: TableGeometryCaptureRequest) => TableGeometryCapture) | null
  readonly revision: WritableSignal<number>
  readonly layouts: Record<TableProjection, TableGeometryLayout>
  readonly records: Record<TableProjection, Map<string, ProjectionRecord>>
  tracks: TableColumnTracks | null
}

/** Refuse unusable input without rounding fractional CSS pixels. */
function checkNumber(value: number): void {
  if (!Number.isFinite(value) || value < 0) {
    throw new RangeError(
      'Table geometry requires finite nonnegative CSS pixels',
    )
  }
}

function copyExtent(extent: TableRecordExtent): TableRecordExtent {
  const { main, work, detail, gap, total } = extent
  for (const value of [main, work, detail, gap, total]) {
    checkNumber(value)
  }
  const sum = main + work + detail + gap
  checkNumber(sum)
  if (Math.abs(sum - total) > Number.EPSILON * Math.max(sum, total) * 8) {
    throw new RangeError('Table record total must include every occupied part')
  }

  return Object.freeze({ main, work, detail, gap, total })
}

function copyTracks(tracks: TableColumnTracks): TableColumnTracks {
  if (
    tracks.columnKeys.length !== tracks.widths.length ||
    new Set(tracks.columnKeys).size !== tracks.columnKeys.length
  ) {
    throw new RangeError('Table tracks require one width per unique column key')
  }
  for (const value of [...tracks.widths, tracks.tableWidth]) {
    checkNumber(value)
  }

  return Object.freeze({
    columnKeys: Object.freeze([...tracks.columnKeys]),
    widths: Object.freeze([...tracks.widths]),
    tableWidth: tracks.tableWidth,
  })
}

/**
 * Numeric geometry local to one controller's presentation lifetime.
 *
 * The controller owns window identities and presentation preparation here.
 * SCAFFOLD: DOM adapter wiring lands in later HIL-1749 slices. This model
 * deliberately has no DOM or table-row payloads.
 * Local storage extends the controller's window lifetime: Hilos::$rt shares
 * backend state across workers, whereas these pixels belong to one view only.
 */
export class TableViewportGeometry {
  readonly view: TableViewportGeometryView = Object.freeze({
    register: (capture: NonNullable<GeometryMount['capture']>) =>
      this.register(capture),
  })

  private readonly records = new Map<string, GeometryRecord>()
  private readonly mounts = new Set<GeometryMount>()
  private windowGeneration = 0
  private occurrence = 0
  private mount = 0
  private preparing = false
  private disposed = false

  /** Read the owner-issued identity without creating a view registration. */
  record(rowKey: string): TableGeometryRecord | null {
    return this.records.get(rowKey)?.identity ?? null
  }

  /**
   * Retire callbacks for an outgoing logical window. Its departed presentation
   * can keep numbers until the delayed replacement arrives; live caches and
   * work reserves no longer describe the requested window.
   */
  invalidateWindow(): void {
    this.windowGeneration++
    for (const [rowKey, record] of this.records) {
      this.records.set(rowKey, {
        ...record,
        identity: Object.freeze({
          ...record.identity,
          windowGeneration: this.windowGeneration,
        }),
        workReserved: false,
      })
    }
    for (const mount of this.mounts) {
      this.nextLayout(mount, 'wide')
      this.nextLayout(mount, 'card')
      mount.tracks = null
      for (const projection of ['wide', 'card'] as const) {
        for (const [rowKey, state] of mount.records[projection]) {
          const record = this.records.get(rowKey)
          if (record !== undefined) {
            state.sample = null
            state.reserve = null
            state.measurement = this.ticket(record, mount.layouts[projection])
          }
        }
      }
    }
    this.publish()
  }

  /** Replace the logical window, including snapshots with identical row keys. */
  replaceWindow(rowKeys: readonly string[]): void {
    this.windowGeneration++
    this.records.clear()
    for (const mount of this.mounts) {
      mount.records.wide.clear()
      mount.records.card.clear()
      mount.tracks = null
      this.nextLayout(mount, 'wide')
      this.nextLayout(mount, 'card')
    }
    for (const rowKey of new Set(rowKeys)) {
      this.createRecord(rowKey)
    }
    this.publish()
  }

  /** Mint a fresh occurrence for an append or an immediate own-create reuse. */
  addRecord(rowKey: string): TableGeometryRecord {
    this.removeRecord(rowKey)
    const identity = this.createRecord(rowKey)
    this.publish()

    return identity
  }

  /** Explicit edge eviction/reuse retires both projections in every mount. */
  retireRecord(rowKey: string): void {
    this.removeRecord(rowKey)
    this.publish()
  }

  /** Invalidate live values in hidden projections too; reserves survive. */
  invalidateRows(rowKeys: readonly string[]): void {
    for (const mount of this.mounts) {
      for (const projection of ['wide', 'card'] as const) {
        for (const rowKey of rowKeys) {
          const record = this.records.get(rowKey)
          const state = mount.records[projection].get(rowKey)
          if (record?.reason === null && state !== undefined) {
            state.sample = null
            state.measurement = this.ticket(record, mount.layouts[projection])
          }
        }
      }
    }
    this.publish()
  }

  /** Capture work before a foreign departure strips its payload, once only. */
  reserveWork(rowKey: string): void {
    const record = this.records.get(rowKey)
    if (record === undefined || record.reason !== null || record.workReserved) {
      return
    }
    record.workReserved = true
    for (const mount of this.mounts) {
      for (const projection of ['wide', 'card'] as const) {
        const state = mount.records[projection].get(rowKey)
        if (state?.sample === undefined || state.sample === null) {
          continue
        }
        const { extent, workGap } = state.sample
        state.reserve = Object.freeze({
          work: extent.work,
          gap: workGap,
          total: extent.work + workGap,
        })
      }
    }
    this.publish()
  }

  /** A later live value canceled the pending departure and its numeric reserve. */
  releaseWork(rowKey: string): void {
    const record = this.records.get(rowKey)
    if (
      record === undefined ||
      record.reason !== null ||
      !record.workReserved
    ) {
      return
    }
    record.workReserved = false
    for (const mount of this.mounts) {
      for (const projection of ['wide', 'card'] as const) {
        const state = mount.records[projection].get(rowKey)
        if (state !== undefined) {
          state.reserve = null
        }
      }
    }
    this.publish()
  }

  /** Retire live measurements coherently before publishing the placeholder. */
  depart(rowKey: string, reason: TableRemovalReason): void {
    const record = this.records.get(rowKey)
    if (record === undefined || record.reason !== null) {
      return
    }
    const allocations: {
      readonly state: ProjectionRecord
      readonly retained: number | null
    }[] = []
    for (const mount of this.mounts) {
      for (const projection of ['wide', 'card'] as const) {
        const state = mount.records[projection].get(rowKey)
        if (state === undefined) {
          continue
        }
        let retained: number | null = null
        if (state.sample !== null) {
          const { extent, workGap } = state.sample
          retained =
            extent.total +
            Math.max(0, (state.reserve?.work ?? 0) - extent.work) +
            Math.max(0, (state.reserve?.gap ?? 0) - workGap)
          checkNumber(retained)
        }
        allocations.push({ state, retained })
      }
    }
    record.reason = reason
    for (const { state, retained } of allocations) {
      state.retained = retained
      state.sample = null
      state.measurement = this.ticket(record, state.measurement.layout)
    }
    this.publish()
  }

  /**
   * Capture every affected live record and tracks before any update. Nested
   * calls reuse that preparation; finally invalidates remaining live caches
   * even if publication throws. This is synchronous, never an async batch.
   */
  withPresentationUpdate<T>(
    change: TablePresentationChange,
    update: () => T,
  ): T {
    if (this.preparing) {
      return update()
    }
    this.preparing = true
    const rowKeys =
      change.kind === 'rows' ? [...change.rowKeys] : [...this.records.keys()]
    try {
      // Collect all DOM reads before numeric publication can notify a view.
      const captures = [...this.mounts].map((mount) => ({
        mount,
        capture: mount.capture?.(this.captureRequest(mount, rowKeys)),
      }))
      for (const { mount, capture } of captures) {
        if (capture === undefined || !this.mounts.has(mount)) {
          continue
        }
        for (const sample of capture.records) {
          this.measure(mount, sample)
        }
        if (capture.tracks !== undefined) {
          this.measureTracks(
            mount,
            capture.tracks.layout,
            capture.tracks.tracks,
          )
        }
      }

      return update()
    } finally {
      try {
        this.invalidateRows(rowKeys)
      } finally {
        this.preparing = false
      }
    }
  }

  /** Unmount/controller replacement releases callbacks as well as numbers. */
  dispose(): void {
    this.disposed = true
    for (const mount of [...this.mounts]) {
      this.disposeMount(mount)
    }
    this.records.clear()
  }

  private createRecord(rowKey: string): TableGeometryRecord {
    const identity = Object.freeze({
      rowKey,
      windowGeneration: this.windowGeneration,
      occurrence: ++this.occurrence,
    })
    this.records.set(rowKey, { identity, reason: null, workReserved: false })

    return identity
  }

  private removeRecord(rowKey: string): void {
    this.records.delete(rowKey)
    for (const mount of this.mounts) {
      mount.records.wide.delete(rowKey)
      mount.records.card.delete(rowKey)
    }
  }

  private register(
    capture: NonNullable<GeometryMount['capture']>,
  ): TableGeometryRegistration {
    if (this.disposed) {
      throw new Error('Cannot register a disposed table geometry owner')
    }
    const id = ++this.mount
    const mount: GeometryMount = {
      id,
      capture,
      revision: createSignal(0),
      layouts: {
        wide: this.layout(id, 'wide', 0),
        card: this.layout(id, 'card', 0),
      },
      records: { wide: new Map(), card: new Map() },
      tracks: null,
    }
    this.mounts.add(mount)

    return Object.freeze({
      revision: { get: () => mount.revision.get() },
      measurement: (rowKey: string, projection: TableProjection) =>
        this.state(mount, rowKey, projection)?.measurement ?? null,
      layout: (projection: TableProjection) => mount.layouts[projection],
      record: (rowKey: string, projection: TableProjection) => {
        const record = this.records.get(rowKey)
        const state = this.state(mount, rowKey, projection)

        return record === undefined || state === null
          ? null
          : Object.freeze({
              record: record.identity,
              reason: record.reason,
              live: state.sample?.extent ?? null,
              workReserve: state.reserve,
              retained: state.retained,
            })
      },
      tracks: () => mount.tracks,
      measure: (sample: TableGeometrySample) => this.measure(mount, sample),
      measureTracks: (
        layout: TableGeometryLayout,
        tracks: TableColumnTracks | null,
      ) => this.measureTracks(mount, layout, tracks),
      invalidateLayout: (projection: TableProjection) => {
        if (!this.mounts.has(mount)) {
          return
        }
        this.nextLayout(mount, projection)
        for (const [rowKey, state] of mount.records[projection]) {
          const record = this.records.get(rowKey)
          if (record !== undefined) {
            state.sample = null
            state.measurement = this.ticket(record, mount.layouts[projection])
          }
        }
        if (projection === 'wide') {
          mount.tracks = null
        }
        this.publish(mount)
      },
      allocate: (
        measurement: TableGeometryMeasurement,
        fallback: number,
        reasonFloor: number,
      ) => {
        const state = this.currentState(mount, measurement)
        if (
          state === null ||
          this.records.get(measurement.record.rowKey)?.reason === null
        ) {
          return
        }
        checkNumber(fallback)
        checkNumber(reasonFloor)
        // An early work reserve is numeric empty space, never a work payload.
        const initial = state.retained ?? fallback + (state.reserve?.total ?? 0)
        checkNumber(initial)
        state.retained = Math.max(initial, reasonFloor)
        this.publish(mount)
      },
      dispose: () => this.disposeMount(mount),
    })
  }

  private state(
    mount: GeometryMount,
    rowKey: string,
    projection: TableProjection,
  ): ProjectionRecord | null {
    const record = this.records.get(rowKey)
    if (!this.mounts.has(mount) || record === undefined) {
      return null
    }
    let state = mount.records[projection].get(rowKey)
    if (state === undefined) {
      state = {
        measurement: this.ticket(record, mount.layouts[projection]),
        sample: null,
        reserve: null,
        retained: null,
      }
      mount.records[projection].set(rowKey, state)
    }

    return state
  }

  private currentState(
    mount: GeometryMount,
    measurement: TableGeometryMeasurement,
  ): ProjectionRecord | null {
    const { record, layout } = measurement
    if (
      !this.mounts.has(mount) ||
      mount.layouts[layout.projection] !== layout ||
      this.records.get(record.rowKey)?.identity !== record
    ) {
      return null
    }
    const state = mount.records[layout.projection].get(record.rowKey)

    return state?.measurement === measurement ? state : null
  }

  private measure(mount: GeometryMount, sample: TableGeometrySample): void {
    const state = this.currentState(mount, sample.measurement)
    if (
      state === null ||
      this.records.get(sample.measurement.record.rowKey)?.reason !== null ||
      sample.extent === null
    ) {
      return
    }
    const extent = copyExtent(sample.extent)
    checkNumber(sample.workGap)
    if (
      sample.workGap > extent.gap ||
      (extent.work === 0 && sample.workGap !== 0)
    ) {
      throw new RangeError('Work separation must belong to the measured work')
    }
    state.sample = { extent, workGap: sample.workGap }
    this.publish(mount)
  }

  private measureTracks(
    mount: GeometryMount,
    layout: TableGeometryLayout,
    tracks: TableColumnTracks | null,
  ): void {
    if (
      !this.mounts.has(mount) ||
      layout !== mount.layouts.wide ||
      tracks === null ||
      mount.tracks !== null
    ) {
      return
    }
    mount.tracks = copyTracks(tracks)
    this.publish(mount)
  }

  private captureRequest(
    mount: GeometryMount,
    rowKeys: readonly string[],
  ): TableGeometryCaptureRequest {
    const records: TableGeometryMeasurement[] = []
    for (const rowKey of new Set(rowKeys)) {
      if (this.records.get(rowKey)?.reason !== null) {
        continue
      }
      for (const projection of ['wide', 'card'] as const) {
        const state = this.state(mount, rowKey, projection)
        if (state !== null) {
          records.push(state.measurement)
        }
      }
    }

    return Object.freeze({
      records: Object.freeze(records),
      layout: mount.layouts.wide,
    })
  }

  private ticket(
    record: GeometryRecord,
    layout: TableGeometryLayout,
  ): TableGeometryMeasurement {
    return Object.freeze({ record: record.identity, layout })
  }

  private layout(
    mount: number,
    projection: TableProjection,
    layoutGeneration: number,
  ): TableGeometryLayout {
    return Object.freeze({
      mount,
      windowGeneration: this.windowGeneration,
      layoutGeneration,
      projection,
    })
  }

  private nextLayout(mount: GeometryMount, projection: TableProjection): void {
    mount.layouts[projection] = this.layout(
      mount.id,
      projection,
      mount.layouts[projection].layoutGeneration + 1,
    )
  }

  private publish(mount?: GeometryMount): void {
    for (const target of mount === undefined ? this.mounts : [mount]) {
      target.revision.set(target.revision.get() + 1)
    }
  }

  private disposeMount(mount: GeometryMount): void {
    if (!this.mounts.delete(mount)) {
      return
    }
    mount.capture = null
    mount.records.wide.clear()
    mount.records.card.clear()
    mount.tracks = null
    this.publish(mount)
  }
}
