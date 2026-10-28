import { describe, expect, expectTypeOf, it, vi } from 'vitest'
import { subscribeSignal } from '../../src/state/signal.js'
import {
  TableViewportGeometry,
  type TableColumnTracks,
  type TableGeometryCaptureRequest,
  type TableGeometryMeasurement,
  type TableGeometryRegistration,
  type TableGeometrySample,
  type TablePresentationChange,
  type TableProjection,
  type TableRecordExtent,
  type TableViewportGeometryView,
} from '../../src/table/tableViewportGeometry.js'

function extent(
  main = 30.25,
  work = 0,
  detail = 0,
  gap = 2.5,
): TableRecordExtent {
  return { main, work, detail, gap, total: main + work + detail + gap }
}

function ticket(
  view: TableGeometryRegistration,
  rowKey = 'a',
  projection: TableProjection = 'wide',
): TableGeometryMeasurement {
  const measurement = view.measurement(rowKey, projection)
  if (measurement === null) {
    throw new Error(`Missing geometry occurrence: ${rowKey}`)
  }

  return measurement
}

function measure(
  view: TableGeometryRegistration,
  value: TableRecordExtent | null,
  projection: TableProjection = 'wide',
  rowKey = 'a',
  workGap = 0,
): void {
  view.measure({
    measurement: ticket(view, rowKey, projection),
    extent: value,
    workGap,
  })
}

function setup(rowKeys: readonly string[] = ['a', 'b']) {
  const geometry = new TableViewportGeometry()
  geometry.replaceWindow(rowKeys)
  const capture = vi.fn(() => ({ records: [] }))
  const view = geometry.view.register(capture)

  return { geometry, view, capture }
}

const tracks: TableColumnTracks = {
  columnKeys: ['select', 'name', 'actions'],
  widths: [20.125, 120.75, 31.5],
  tableWidth: 174.375,
}

describe('TableViewportGeometry numeric measurements', () => {
  it('distinguishes an absent measurement from a fractional or zero extent', () => {
    const { view } = setup()
    expect(view.record('absent', 'wide')).toBeNull()
    expect(view.measurement('absent', 'wide')).toBeNull()
    expect(view.record('a', 'wide')).toMatchObject({
      live: null,
      retained: null,
    })
    measure(view, null)
    expect(view.record('a', 'wide')?.live).toBeNull()

    measure(view, extent(30.25, 11.125, 75.5, 5.625), 'wide', 'a', 1.125)
    expect(view.record('a', 'wide')?.live).toEqual({
      main: 30.25,
      work: 11.125,
      detail: 75.5,
      gap: 5.625,
      total: 122.5,
    })
    measure(view, null)
    expect(view.record('a', 'wide')?.live?.total).toBe(122.5)
    measure(view, extent(0, 0, 0, 0))
    expect(view.record('a', 'wide')?.live?.total).toBe(0)
  })

  it.each([NaN, Infinity, -Infinity, -0.25])(
    'refuses non-CSS-pixel input %s',
    (invalid) => {
      const { view } = setup()
      for (const field of ['main', 'work', 'detail', 'gap', 'total'] as const) {
        expect(() => measure(view, { ...extent(), [field]: invalid })).toThrow(
          RangeError,
        )
      }
      expect(view.record('a', 'wide')?.live).toBeNull()
      expect(() =>
        view.measureTracks(view.layout('wide'), {
          ...tracks,
          widths: [20, invalid, 30],
        }),
      ).toThrow(RangeError)
      expect(() =>
        view.measureTracks(view.layout('wide'), {
          ...tracks,
          tableWidth: invalid,
        }),
      ).toThrow(RangeError)
    },
  )

  it('requires a complete total and work separation that is already part of gap', () => {
    const { view } = setup()
    expect(() => measure(view, { ...extent(), total: 30 })).toThrow(RangeError)
    expect(() => measure(view, extent(1e308, 1e308, 0, 0))).toThrow(RangeError)
    expect(() => measure(view, extent(), 'wide', 'a', 1)).toThrow(RangeError)
    expect(() => measure(view, extent(30, 10, 0, 2), 'wide', 'a', 3)).toThrow(
      RangeError,
    )
    expect(() => measure(view, extent(30, 10, 0, 2), 'wide', 'a', NaN)).toThrow(
      RangeError,
    )
    measure(view, extent(0.1, 0.2, 0.3, 0.4))
    expect(view.record('a', 'wide')?.live?.total).toBe(1)
  })

  it('copies only numeric extent/track fields and freezes caller-visible data', () => {
    const { view } = setup()
    const incoming = { ...extent(), slots: { secret: 'discarded' } }
    measure(view, incoming)
    incoming.main = 900
    expect(view.record('a', 'wide')?.live).toEqual(extent())
    expect(Object.isFrozen(view.record('a', 'wide')?.live)).toBe(true)
    const widths = [...tracks.widths]
    const columnKeys = [...tracks.columnKeys]
    view.measureTracks(view.layout('wide'), {
      widths,
      columnKeys,
      tableWidth: tracks.tableWidth,
    })
    widths[0] = 900
    columnKeys[0] = 'changed'
    expect(view.tracks()).toEqual(tracks)
    expect(Object.isFrozen(view.tracks()?.widths)).toBe(true)
    expect(Object.isFrozen(ticket(view).record)).toBe(true)
    expectTypeOf<keyof TableViewportGeometryView>().toEqualTypeOf<'register'>()
  })

  it('tracks current live content, including deliberate collapse and ordinary work completion', () => {
    const { geometry, view } = setup()
    measure(view, extent(50, 12, 100, 5), 'wide', 'a', 2)
    measure(view, extent(50, 0, 0, 3))
    expect(view.record('a', 'wide')?.live?.total).toBe(53)
    geometry.reserveWork('a')
    geometry.depart('a', 'deleted')
    expect(view.record('a', 'wide')?.retained).toBe(53)
    expect(view.record('a', 'wide')?.workReserve?.total).toBe(0)
  })

  it('keeps mounts, projections and geometry owners independent', () => {
    const { geometry, view } = setup()
    const other = geometry.view.register(() => ({ records: [] }))
    const foreign = setup().view
    measure(view, extent(30))
    measure(view, extent(140), 'card')
    measure(view, extent(210), 'tiles')
    measure(other, extent(65))
    other.measure({
      measurement: ticket(view),
      extent: extent(999),
      workGap: 0,
    })
    foreign.measure({
      measurement: ticket(view),
      extent: extent(999),
      workGap: 0,
    })
    view.measure({
      measurement: { ...ticket(view) },
      extent: extent(999),
      workGap: 0,
    })
    expect(view.record('a', 'wide')?.live?.total).toBe(32.5)
    expect(other.record('a', 'wide')?.live?.total).toBe(67.5)
    expect(foreign.record('a', 'wide')?.live).toBeNull()
    geometry.depart('a', 'left_set')
    expect(view.record('a', 'card')?.retained).toBe(142.5)
    expect(view.record('a', 'tiles')?.retained).toBe(212.5)
    expect(other.record('a', 'card')?.retained).toBeNull()
    expect(other.record('a', 'tiles')?.retained).toBeNull()
  })
})

describe('TableViewportGeometry validity and allocation', () => {
  it('rejects old occurrence and window callbacks even when the row key is reused', () => {
    const { geometry, view } = setup()
    const old = ticket(view)
    measure(view, extent(80))
    geometry.depart('a', 'deleted')
    geometry.addRecord('a')
    const reused = ticket(view)
    expect(reused.record.occurrence).not.toBe(old.record.occurrence)
    view.measure({ measurement: old, extent: extent(900), workGap: 0 })
    expect(view.record('a', 'wide')).toMatchObject({
      live: null,
      retained: null,
      reason: null,
    })
    measure(view, extent(90))
    geometry.replaceWindow(['a'])
    expect(ticket(view).record.windowGeneration).not.toBe(
      reused.record.windowGeneration,
    )
    expect(ticket(view).record.occurrence).not.toBe(reused.record.occurrence)
    view.measure({ measurement: reused, extent: extent(900), workGap: 0 })
    expect(view.record('a', 'wide')?.live).toBeNull()
    expect(view.record('b', 'wide')).toBeNull()
  })

  it('invalidates pre-departure live caches and ignores old layout/value callbacks', () => {
    const { geometry, view } = setup()
    measure(view, extent(120))
    measure(view, extent(200), 'card')
    const oldWide = ticket(view)
    const oldCard = ticket(view, 'a', 'card')
    view.invalidateLayout('wide')
    geometry.invalidateRows(['a'])
    view.measure({ measurement: oldWide, extent: extent(999), workGap: 0 })
    view.measure({ measurement: oldCard, extent: extent(999), workGap: 0 })
    geometry.depart('a', 'moved_out')
    expect(view.record('a', 'wide')?.retained).toBeNull()
    expect(view.record('a', 'card')?.retained).toBeNull()
    view.allocate(ticket(view), 49.75, 20.25)
    expect(view.record('a', 'wide')?.retained).toBe(49.75)
    expect(view.record('a', 'card')?.retained).toBeNull()
  })

  it('retains each usable projection and only grows for the complete readable reason floor', () => {
    const { geometry, view } = setup()
    measure(view, extent(70, 12.5, 80, 3.25), 'wide', 'a', 1.25)
    measure(view, extent(220, 15, 40, 8), 'card', 'a', 2)
    const live = ticket(view)
    geometry.depart('a', 'left_set')
    view.measure({ measurement: live, extent: extent(1), workGap: 0 })
    view.allocate(ticket(view), 9999, 20)
    expect(view.record('a', 'wide')).toMatchObject({
      reason: 'left_set',
      live: null,
      retained: 165.75,
    })
    view.invalidateLayout('wide')
    view.allocate(ticket(view), 3000, 190.125)
    view.invalidateLayout('wide')
    view.allocate(ticket(view), 4000, 25)
    expect(view.record('a', 'wide')?.retained).toBe(190.125)
    expect(view.record('a', 'card')?.retained).toBe(283)
    geometry.depart('a', 'deleted')
    expect(view.record('a', 'wide')?.reason).toBe('left_set')
  })

  it('latches the first adapter fallback in pixels across later layout and projection changes', () => {
    const { geometry, view } = setup()
    geometry.depart('a', 'deleted')
    view.allocate(ticket(view), 50.125, 24)
    view.allocate(ticket(view, 'a', 'card'), 63.5, 70.25)
    const stale = ticket(view)
    view.invalidateLayout('wide')
    view.invalidateLayout('card')
    view.allocate(stale, 800, 900)
    view.allocate(ticket(view), 500, 30)
    view.allocate(ticket(view, 'a', 'card'), 600, 24)
    expect(view.record('a', 'wide')?.retained).toBe(50.125)
    expect(view.record('a', 'card')?.retained).toBe(70.25)
    view.allocate(ticket(view), 1, 77.75)
    view.allocate(ticket(view), 1000, 22)
    expect(view.record('a', 'wide')?.retained).toBe(77.75)
  })

  it.each([NaN, Infinity, -1])(
    'refuses invalid fallback and reason floor %s',
    (invalid) => {
      const { geometry, view } = setup()
      geometry.depart('a', 'deleted')
      expect(() => view.allocate(ticket(view), invalid, 20)).toThrow(RangeError)
      expect(() => view.allocate(ticket(view), 50, invalid)).toThrow(RangeError)
      expect(view.record('a', 'wide')?.retained).toBeNull()
    },
  )

  it('combines early work and its gap with fresh remaining parts without double counting', () => {
    const { geometry, view } = setup()
    measure(view, extent(50.25, 12.5, 30, 6.25), 'wide', 'a', 2.25)
    measure(view, extent(80, 15, 40, 10), 'card', 'a', 3)
    measure(view, extent(160.125, 18.5, 60.25, 12.125), 'tiles', 'a', 4.125)
    geometry.reserveWork('a')
    expect(view.record('a', 'wide')?.workReserve).toEqual({
      work: 12.5,
      gap: 2.25,
      total: 14.75,
    })
    // Work's payload is now gone. Main/detail can still change while Apply waits.
    measure(view, extent(60.5, 0, 25.25, 4))
    measure(view, extent(170.25, 0, 40.5, 8), 'tiles')
    geometry.reserveWork('a')
    geometry.depart('a', 'deleted')
    expect(view.record('a', 'wide')?.retained).toBe(104.5)
    // The other projection still includes its bar and work gap: neither is added twice.
    expect(view.record('a', 'card')?.retained).toBe(145)
    expect(view.record('a', 'tiles')?.retained).toBe(241.375)
    measure(view, extent(1))
    geometry.reserveWork('a')
    expect(view.record('a', 'wide')?.retained).toBe(104.5)
  })

  it('keeps only the deliberate work reserve when remaining live content becomes invalid', () => {
    const { geometry, view } = setup()
    measure(view, extent(100, 12.5, 50, 5), 'wide', 'a', 2.5)
    geometry.reserveWork('a')
    view.invalidateLayout('wide')
    geometry.depart('a', 'deleted')
    expect(view.record('a', 'wide')?.retained).toBeNull()
    view.allocate(ticket(view), 50, 20)
    expect(view.record('a', 'wide')?.retained).toBe(65)
    view.allocate(ticket(view), 500, 20)
    expect(view.record('a', 'wide')?.retained).toBe(65)
  })

  it('refuses an overflowing merged reserve without publishing invalid or partial allocations', () => {
    const { geometry, view } = setup()
    measure(view, extent(0, 1e308, 0, 0))
    measure(view, extent(20), 'card')
    geometry.reserveWork('a')
    measure(view, extent(1e308, 0, 0, 0))
    expect(() => geometry.depart('a', 'deleted')).toThrow(RangeError)
    expect(view.record('a', 'wide')).toMatchObject({
      reason: null,
      retained: null,
    })
    expect(view.record('a', 'card')).toMatchObject({
      reason: null,
      retained: null,
    })
  })
})

describe('TableViewportGeometry tracks and lifetime', () => {
  it('holds one ordered track vector until its wide layout changes', () => {
    const { geometry, view } = setup()
    const old = view.layout('wide')
    view.measureTracks(old, tracks)
    geometry.depart('a', 'deleted')
    geometry.depart('b', 'deleted')
    geometry.addRecord('c')
    view.measureTracks(old, {
      ...tracks,
      widths: [10, 90, 20],
      tableWidth: 124,
    })
    view.invalidateLayout('card')
    expect(view.tracks()).toEqual(tracks)
    view.invalidateLayout('wide')
    view.measureTracks(old, tracks)
    expect(view.tracks()).toBeNull()
    view.measureTracks(view.layout('card'), tracks)
    expect(view.tracks()).toBeNull()
    view.measureTracks(view.layout('wide'), null)
    expect(view.tracks()).toBeNull()
    const next = {
      ...tracks,
      widths: [19.5, 90.25, 30.125],
      tableWidth: 142.875,
    }
    view.measureTracks(view.layout('wide'), next)
    expect(view.tracks()).toEqual(next)
    geometry.replaceWindow(['c'])
    expect(view.tracks()).toBeNull()
  })

  it('refuses ambiguous column vectors', () => {
    const { view } = setup()
    expect(() =>
      view.measureTracks(view.layout('wide'), { ...tracks, widths: [1] }),
    ).toThrow(RangeError)
    expect(() =>
      view.measureTracks(view.layout('wide'), {
        ...tracks,
        columnKeys: ['a', 'a', 'b'],
      }),
    ).toThrow(RangeError)
    expect(view.tracks()).toBeNull()
  })

  it('releases evicted occurrences, unmounted registrations and disposed owners', () => {
    const { geometry, view, capture } = setup()
    const observer = ticket(view)
    geometry.retireRecord('a')
    expect(view.record('a', 'wide')).toBeNull()
    geometry.addRecord('a')
    view.measure({ measurement: observer, extent: extent(999), workGap: 0 })
    expect(view.record('a', 'wide')?.live).toBeNull()
    view.measureTracks(view.layout('wide'), tracks)
    const layout = view.layout('wide')
    view.dispose()
    view.dispose()
    expect(view.record('a', 'wide')).toBeNull()
    expect(view.tracks()).toBeNull()
    view.measureTracks(layout, tracks)
    view.measure({ measurement: observer, extent: extent(999), workGap: 0 })
    geometry.withPresentationUpdate({ kind: 'reset' }, () => undefined)
    expect(capture).not.toHaveBeenCalled()
    const remount = geometry.view.register(() => ({ records: [] }))
    expect(ticket(remount).layout.mount).not.toBe(observer.layout.mount)
    expect(remount.record('a', 'wide')?.live).toBeNull()
    geometry.dispose()
    expect(remount.record('a', 'wide')).toBeNull()
    expect(() => geometry.view.register(() => ({ records: [] }))).toThrow()
  })

  it.each(['wide', 'card', 'tiles'] as const)(
    'retains more %s slots than a page until an explicit window replacement, without compaction',
    (projection) => {
      const { geometry, view } = setup([])
      for (let index = 0; index < 150; index++) {
        const key = String(index)
        geometry.addRecord(key)
        measure(view, extent(30 + index / 4), projection, key)
        geometry.depart(key, 'deleted')
      }
      expect(view.record('0', projection)?.retained).toBe(32.5)
      expect(view.record('149', projection)?.retained).toBe(69.75)
      geometry.replaceWindow([])
      expect(view.record('0', projection)).toBeNull()
      expect(view.record('149', projection)).toBeNull()
    },
  )
})

describe('TableViewportGeometry synchronous presentation boundary', () => {
  it('captures all mounts before publishing numbers or running the update', () => {
    const geometry = new TableViewportGeometry()
    geometry.replaceWindow(['a', 'b'])
    const events: string[] = []
    const capture = (request: TableGeometryCaptureRequest) => {
      events.push('capture')

      return {
        records: request.records.map((measurement) => ({
          measurement,
          extent: extent(45),
          workGap: 0,
        })),
        tracks: { layout: request.layout, tracks },
      }
    }
    const first = geometry.view.register(capture)
    const second = geometry.view.register(capture)
    const stop = subscribeSignal(first.revision, () => events.push('publish'))
    geometry.withPresentationUpdate(
      { kind: 'rows', rowKeys: ['a', 'a', 'absent'] },
      () => {
        events.push('update')
        expect(first.record('a', 'wide')?.live?.total).toBe(47.5)
        expect(second.record('a', 'card')?.live?.total).toBe(47.5)
        expect(first.record('b', 'wide')?.live).toBeNull()
        expect(first.tracks()).toEqual(tracks)
        geometry.depart('a', 'deleted')
      },
    )
    stop()
    expect(events.slice(0, 2)).toEqual(['capture', 'capture'])
    expect(events.indexOf('update')).toBeGreaterThan(events.indexOf('publish'))
    expect(first.record('a', 'wide')?.retained).toBe(47.5)
  })

  it('reuses the outer preparation through nested updates after content has changed', () => {
    const geometry = new TableViewportGeometry()
    geometry.replaceWindow(['a'])
    let current = extent(50, 12, 30, 6)
    const capture = vi.fn((request: TableGeometryCaptureRequest) => ({
      records: request.records.map((measurement) => ({
        measurement,
        extent: current,
        workGap: current.work === 0 ? 0 : 2,
      })),
    }))
    const view = geometry.view.register(capture)
    const result = geometry.withPresentationUpdate(
      { kind: 'rows', rowKeys: ['a'] },
      () => {
        geometry.reserveWork('a')
        current = extent(50, 0, 30, 4)

        return geometry.withPresentationUpdate(
          { kind: 'rows', rowKeys: ['a'] },
          () => {
            geometry.depart('a', 'deleted')

            return 42
          },
        )
      },
    )
    expect(result).toBe(42)
    expect(capture).toHaveBeenCalledTimes(1)
    expect(view.record('a', 'wide')?.retained).toBe(98)
  })

  it('invalidates changed live projections in finally and recovers after an update throws', () => {
    const { geometry, view, capture } = setup()
    measure(view, extent(100), 'card')
    const before = ticket(view, 'a', 'card')
    const error = new Error('publication failed')
    expect(() =>
      geometry.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
        throw error
      }),
    ).toThrow(error)
    view.measure({ measurement: before, extent: extent(900), workGap: 0 })
    expect(view.record('a', 'card')?.live).toBeNull()
    expect(
      geometry.withPresentationUpdate({ kind: 'window' }, () => 'next'),
    ).toBe('next')
    expect(capture).toHaveBeenCalledTimes(2)
  })

  it('recovers from a throwing capture and does not run the destructive update', () => {
    const geometry = new TableViewportGeometry()
    geometry.replaceWindow(['a'])
    const capture = vi.fn(() => {
      throw new Error('capture failed')
    })
    const broken = geometry.view.register(capture)
    const update = vi.fn()
    expect(() =>
      geometry.withPresentationUpdate({ kind: 'window' }, update),
    ).toThrow('capture failed')
    expect(update).not.toHaveBeenCalled()
    broken.dispose()
    geometry.withPresentationUpdate({ kind: 'window' }, update)
    expect(update).toHaveBeenCalledTimes(1)
  })

  it.each(['window', 'reset', 'refusal'] as const)(
    'prepares every live record for %s',
    (kind) => {
      const geometry = new TableViewportGeometry()
      geometry.replaceWindow(['a', 'b', 'c'])
      geometry.depart('c', 'deleted')
      const capture = vi.fn((request: TableGeometryCaptureRequest) => {
        expect(
          request.records.map((measurement) => measurement.record.rowKey),
        ).toEqual(['a', 'a', 'a', 'b', 'b', 'b'])
        expect(
          request.records.map((measurement) => measurement.layout.projection),
        ).toEqual(['wide', 'card', 'tiles', 'wide', 'card', 'tiles'])

        return { records: [] as TableGeometrySample[] }
      })
      geometry.view.register(capture)
      const change: TablePresentationChange = { kind }
      geometry.withPresentationUpdate(change, () => geometry.replaceWindow([]))
      expect(capture).toHaveBeenCalledTimes(1)
    },
  )
})

describe('TableViewportGeometry tiles alongside wide and card', () => {
  it('captures three distinct footprints before removing content and retains independent numeric allocations', () => {
    const geometry = new TableViewportGeometry()
    geometry.replaceWindow(['a'])
    let content: Record<TableProjection, TableRecordExtent> | null = {
      wide: extent(50.25, 12.5, 30, 6.25),
      card: extent(80, 15, 40, 10),
      tiles: extent(160.125, 18.5, 60.25, 12.125),
    }
    const workGaps = { wide: 2.25, card: 3, tiles: 4.125 }
    const capture = vi.fn((request: TableGeometryCaptureRequest) => {
      expect(content).not.toBeNull()
      expect(request.records.map(({ layout }) => layout.projection)).toEqual([
        'wide',
        'card',
        'tiles',
      ])

      return {
        records: request.records.map((measurement) => ({
          measurement,
          extent: content![measurement.layout.projection],
          workGap: workGaps[measurement.layout.projection],
        })),
        tracks: { layout: request.layout, tracks },
      }
    })
    const view = geometry.view.register(capture)
    const identity = geometry.record('a')
    geometry.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
      geometry.reserveWork('a')
      content = null
      geometry.depart('a', 'left_set')
    })
    expect(capture).toHaveBeenCalledTimes(1)
    for (const [projection, retained, work, gap, total] of [
      ['wide', 99, 12.5, 2.25, 14.75],
      ['card', 145, 15, 3, 18],
      ['tiles', 251, 18.5, 4.125, 22.625],
    ] as const) {
      expect(view.record('a', projection)).toEqual({
        record: identity,
        reason: 'left_set',
        live: null,
        workReserve: { work, gap, total },
        retained,
      })
    }
    const stale = ticket(view, 'a', 'tiles')
    view.invalidateLayout('tiles')
    view.allocate(stale, 900, 900)
    expect(view.record('a', 'tiles')?.retained).toBe(251)
    view.allocate(ticket(view, 'a', 'tiles'), 900, 300.125)
    view.allocate(ticket(view, 'a', 'tiles'), 900, 20)
    geometry.releaseWork('a')
    geometry.depart('a', 'deleted')
    expect(view.record('a', 'tiles')).toMatchObject({
      reason: 'left_set',
      retained: 300.125,
      workReserve: { total: 22.625 },
    })
    expect(view.record('a', 'wide')?.retained).toBe(99)
    expect(view.record('a', 'card')?.retained).toBe(145)
    expect(view.tracks()).toEqual(tracks)
  })

  it('releases all work reserves and preserves fresh independent reserves across layout and value invalidation', () => {
    const { geometry, view } = setup()
    measure(view, extent(40, 10, 0, 4), 'wide', 'a', 2)
    measure(view, extent(80, 20, 0, 8), 'card', 'a', 4)
    measure(view, extent(160, 30, 0, 12), 'tiles', 'a', 6)
    geometry.reserveWork('a')
    geometry.releaseWork('a')
    for (const projection of ['wide', 'card', 'tiles'] as const) {
      expect(view.record('a', projection)?.workReserve).toBeNull()
    }
    measure(view, extent(45, 11, 0, 5), 'wide', 'a', 2.5)
    measure(view, extent(85, 21, 0, 9), 'card', 'a', 4.5)
    measure(view, extent(165, 31, 0, 13), 'tiles', 'a', 6.5)
    geometry.reserveWork('a')
    const wide = ticket(view)
    const card = ticket(view, 'a', 'card')
    const tiles = ticket(view, 'a', 'tiles')
    view.invalidateLayout('tiles')
    expect(ticket(view)).toBe(wide)
    expect(ticket(view, 'a', 'card')).toBe(card)
    view.measure({ measurement: tiles, extent: extent(999), workGap: 0 })
    expect(view.record('a', 'tiles')?.live).toBeNull()
    measure(view, extent(170), 'tiles')
    measure(view, null, 'tiles')
    expect(view.record('a', 'tiles')?.live?.total).toBe(172.5)
    geometry.invalidateRows(['a'])
    view.measure({ measurement: wide, extent: extent(999), workGap: 0 })
    view.measure({ measurement: card, extent: extent(999), workGap: 0 })
    geometry.reserveWork('a')
    geometry.depart('a', 'deleted')
    for (const [projection, reserve, fallback, retained] of [
      ['wide', 13.5, 50, 63.5],
      ['card', 25.5, 70, 95.5],
      ['tiles', 37.5, 90, 127.5],
    ] as const) {
      expect(view.record('a', projection)).toMatchObject({
        live: null,
        retained: null,
        workReserve: { total: reserve },
      })
      view.allocate(ticket(view, 'a', projection), fallback, 20)
      view.allocate(ticket(view, 'a', projection), 900, 20)
      expect(view.record('a', projection)?.retained).toBe(retained)
    }
  })

  it('keeps an unmeasurable hidden tile null until its own first visible fallback', () => {
    const { geometry, view } = setup()
    measure(view, extent(40), 'wide')
    measure(view, extent(90), 'card')
    // An adapter reports hidden or zero-size DOM as null, never zero pixels.
    measure(view, null, 'tiles')
    geometry.reserveWork('a')
    geometry.depart('a', 'moved_out')
    expect(view.record('a', 'tiles')).toMatchObject({
      live: null,
      retained: null,
      workReserve: null,
    })
    view.allocate(ticket(view, 'a', 'tiles'), 70.25, 80.5)
    view.invalidateLayout('tiles')
    view.allocate(ticket(view, 'a', 'tiles'), 500, 20)
    expect(view.record('a', 'tiles')?.retained).toBe(80.5)
    expect(view.record('a', 'wide')?.retained).toBe(42.5)
    expect(view.record('a', 'card')?.retained).toBe(92.5)
  })

  it('accepts column tracks only from the wide layout and leaves them intact on tile invalidation', () => {
    const { view } = setup()
    view.measureTracks(view.layout('tiles'), tracks)
    expect(view.tracks()).toBeNull()
    view.measureTracks(view.layout('wide'), tracks)
    const wide = view.layout('wide')
    view.invalidateLayout('tiles')
    view.measureTracks(view.layout('tiles'), {
      ...tracks,
      widths: [10, 20, 30],
      tableWidth: 60,
    })
    expect(view.layout('wide')).toBe(wide)
    expect(view.tracks()).toEqual(tracks)
  })

  it.each(['wide', 'card', 'tiles'] as const)(
    'retires %s tickets, reserves and allocations on eviction, reuse, window replacement, reset and disposal',
    (projection) => {
      const { geometry, view, capture } = setup()
      measure(view, extent(120, 11, 35, 4), projection)
      geometry.reserveWork('a')
      geometry.depart('a', 'deleted')
      measure(view, extent(70, 7, 12, 3), projection, 'b')
      geometry.reserveWork('b')
      const departed = ticket(view, 'a', projection)
      const live = ticket(view, 'b', projection)
      geometry.invalidateWindow()
      const outgoing = ticket(view, 'a', projection)
      expect(outgoing.layout).not.toBe(departed.layout)
      expect(outgoing.record.occurrence).toBe(departed.record.occurrence)
      expect(outgoing.record.windowGeneration).not.toBe(
        departed.record.windowGeneration,
      )
      expect(view.record('a', projection)).toMatchObject({
        retained: 170,
        workReserve: null,
      })
      expect(view.record('b', projection)).toMatchObject({
        live: null,
        workReserve: null,
      })
      view.allocate(departed, 999, 999)
      view.measure({ measurement: live, extent: extent(999), workGap: 0 })
      expect(view.record('a', projection)?.retained).toBe(170)
      expect(view.record('b', projection)?.live).toBeNull()
      geometry.replaceWindow(['a'])
      const fresh = ticket(view, 'a', projection)
      expect(fresh.record.occurrence).not.toBe(outgoing.record.occurrence)
      expect(view.record('b', projection)).toBeNull()
      view.allocate(outgoing, 999, 999)
      view.measure({ measurement: live, extent: extent(999), workGap: 0 })
      expect(view.record('a', projection)).toMatchObject({
        live: null,
        retained: null,
        workReserve: null,
        reason: null,
      })
      measure(view, extent(80), projection)
      geometry.depart('a', 'left_set')
      const reused = geometry.addRecord('a')
      expect(reused.occurrence).not.toBe(fresh.record.occurrence)
      expect(view.record('a', projection)).toMatchObject({
        live: null,
        retained: null,
        workReserve: null,
        reason: null,
      })
      view.measure({ measurement: fresh, extent: extent(999), workGap: 0 })
      geometry.retireRecord('a')
      expect(view.record('a', projection)).toBeNull()
      geometry.addRecord('a')
      geometry.withPresentationUpdate({ kind: 'reset' }, () =>
        geometry.replaceWindow([]),
      )
      expect(view.measurement('a', projection)).toBeNull()
      geometry.addRecord('a')
      const beforeDispose = ticket(view, 'a', projection)
      view.dispose()
      view.allocate(beforeDispose, 999, 999)
      view.measure({
        measurement: beforeDispose,
        extent: extent(999),
        workGap: 0,
      })
      expect(view.record('a', projection)).toBeNull()
      capture.mockClear()
      const remount = geometry.view.register(() => ({ records: [] }))
      expect(ticket(remount, 'a', projection).layout.mount).not.toBe(
        beforeDispose.layout.mount,
      )
      remount.measure({
        measurement: beforeDispose,
        extent: extent(999),
        workGap: 0,
      })
      expect(remount.record('a', projection)?.live).toBeNull()
      geometry.dispose()
      remount.measure({
        measurement: beforeDispose,
        extent: extent(999),
        workGap: 0,
      })
      geometry.withPresentationUpdate({ kind: 'reset' }, () => undefined)
      expect(capture).not.toHaveBeenCalled()
      expect(remount.record('a', projection)).toBeNull()
    },
  )
})

describe('TableViewportGeometry controller identity integration', () => {
  it('retires both projections of a live text layout without changing departed allocations', () => {
    const { geometry, view } = setup()
    for (const projection of ['wide', 'card'] as const) {
      measure(view, extent(30, 10, 20), projection, 'a')
      measure(view, extent(40, 10, 30), projection, 'b')
    }
    geometry.depart('b', 'deleted')
    const tickets = [ticket(view, 'a', 'wide'), ticket(view, 'a', 'card')]
    const departed = [view.record('b', 'wide'), view.record('b', 'card')]
    geometry.invalidateRows(['a', 'b'])
    for (const [index, projection] of (['wide', 'card'] as const).entries()) {
      expect(ticket(view, 'a', projection)).not.toBe(tickets[index])
      expect(ticket(view, 'a', projection).record).toBe(tickets[index]!.record)
      expect(view.record('a', projection)?.live).toBeNull()
      expect(view.record('b', projection)).toEqual(departed[index])
      expect(view.record('b', projection)?.retained).toBe(82.5)
    }
  })

  it('reads the same owner identity without a mount and does not create missing records', () => {
    const { geometry, view } = setup()
    expect(geometry.record('a')).toBe(ticket(view).record)
    expect(geometry.record('absent')).toBeNull()
    geometry.retireRecord('a')
    expect(geometry.record('a')).toBeNull()
  })

  it('retires a logical generation while keeping only outgoing departed allocations', () => {
    const { geometry, view } = setup()
    measure(view, extent(80))
    measure(view, extent(90), 'wide', 'b')
    geometry.reserveWork('b')
    geometry.depart('a', 'deleted')
    const old = ticket(view)
    const oldLive = ticket(view, 'b')
    view.measureTracks(view.layout('wide'), tracks)
    geometry.invalidateWindow()
    const current = ticket(view)
    expect(current.record).toBe(geometry.record('a'))
    expect(current.record.occurrence).toBe(old.record.occurrence)
    expect(current.record.windowGeneration).not.toBe(
      old.record.windowGeneration,
    )
    expect(view.record('a', 'wide')?.retained).toBe(82.5)
    expect(view.record('b', 'wide')).toMatchObject({
      live: null,
      workReserve: null,
    })
    view.allocate(old, 999, 999)
    view.measure({ measurement: oldLive, extent: extent(999), workGap: 0 })
    expect(view.record('a', 'wide')?.retained).toBe(82.5)
    expect(view.record('b', 'wide')?.live).toBeNull()
    expect(view.tracks()).toBeNull()
    geometry.replaceWindow(['a'])
    expect(view.record('a', 'wide')).toMatchObject({
      reason: null,
      retained: null,
    })
    expect(ticket(view).record.occurrence).not.toBe(old.record.occurrence)
  })
})

describe('TableViewportGeometry canceled departure reserve', () => {
  it('releases a canceled live departure reserve, but never a departed allocation', () => {
    const { geometry, view } = setup()
    measure(view, extent(50, 12, 0, 4), 'wide', 'a', 2)
    geometry.reserveWork('a')
    geometry.releaseWork('a')
    expect(view.record('a', 'wide')?.workReserve).toBeNull()
    measure(view, extent(50, 0, 0, 2))
    geometry.reserveWork('a')
    expect(view.record('a', 'wide')?.workReserve?.total).toBe(0)
    geometry.depart('a', 'deleted')
    geometry.releaseWork('a')
    expect(view.record('a', 'wide')?.retained).toBe(52)
  })
})

describe('TableViewportGeometry text presentation boundary', () => {
  it('captures before collapse, retires only measurements and retains the final full footprint on departure', () => {
    const geometry = new TableViewportGeometry()
    geometry.replaceWindow(['a'])
    let expanded = true
    const seen: boolean[] = []
    const view = geometry.view.register((request) => {
      seen.push(expanded)
      return {
        records: request.records.map((measurement) => ({
          measurement,
          extent: extent(expanded ? 120.25 : 40.25, 11.5, 30.75, 4.5),
          workGap: 1.5,
        })),
        tracks: { layout: request.layout, tracks },
      }
    })
    const old = ticket(view)
    geometry.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
      expanded = false
    })
    expect(seen).toEqual([true])
    expect(ticket(view).record).toBe(old.record)
    expect(ticket(view)).not.toBe(old)
    view.measure({ measurement: old, extent: extent(999), workGap: 0 })
    expect(view.record('a', 'wide')?.live).toBeNull()
    geometry.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
      geometry.depart('a', 'deleted')
    })
    expect(seen).toEqual([true, false])
    expect(view.record('a', 'wide')).toMatchObject({
      record: old.record,
      live: null,
      retained: 87,
    })
    expect(view.record('a', 'card')?.retained).toBe(87)
    expect(view.tracks()).toEqual(tracks)
    geometry.dispose()
  })
})
