import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import {
  Fragment,
  StrictMode,
  useLayoutEffect,
  useRef,
  type CSSProperties,
} from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import {
  FocusTrap,
  TableViewportController,
  ScopeManager,
  bindTableViewport,
  subscribeSignal,
  type HilosConnection,
  type TableGeometryRecord,
  type TableGeometryRegistration,
  type TableViewportGeometryView,
} from '@hilos/core/base'
import { normalizeTableRow } from '../../core/src/state/normalizer.js'
import { useTableViewportGeometry } from '../src/useTableViewportGeometry.js'
import { useSignal } from '../src/useSignal.js'

type Geometry = ReturnType<typeof useTableViewportGeometry>
const KEYS = ['column:name', 'state']
const controllers: TableViewportController<string>[] = []

function controller(names = ['a', 'b']) {
  const result = new TableViewportController<string>({
    resolve: (row) => String(row.slots.name ?? row.rowKey),
    sendViewport: () => {},
    frame: { columns: [{ key: 'name', label: 'Name' }] },
  })
  controllers.push(result)
  snapshot(result, names)
  return result
}

function snapshot(target: TableViewportController<string>, names = ['a', 'b']) {
  target.ingestWindow(
    names.map((rowKey) => ({ rowKey, slots: { name: rowKey } })),
    names.length,
    true,
    null,
    null,
    10,
  )
}

function depart(
  target: TableViewportController<string>,
  rowKey = 'a',
  own = true,
) {
  target.ingestDelta({ kind: 'row_removed', rowKey, reason: 'deleted', own })
}

function instrument(target: TableViewportController<string>) {
  const registrations: TableGeometryRegistration[] = []
  const captures = vi.fn()
  const facade: TableViewportGeometryView = {
    register(capture) {
      const owner = target.geometry.register((request) => {
        const result = capture(request)
        captures(result)
        return result
      })
      const registration = {
        ...owner,
        dispose: vi.fn(owner.dispose),
        measure: vi.fn(owner.measure),
        measurement: vi.fn(owner.measurement),
        record: vi.fn(owner.record),
        invalidateLayout: vi.fn(owner.invalidateLayout),
      }
      registrations.push(registration)
      return registration
    },
  }
  return { facade, registrations, captures }
}

/** Only the adapter's DOM contract, never a replacement production table. */
function Harness({
  target,
  facade = target.geometry,
  keys = KEYS,
  search = 'visible',
  onCommit,
  onRender,
  onPlaceholder,
}: {
  target: TableViewportController<string>
  facade?: TableViewportGeometryView
  keys?: readonly string[]
  search?: 'visible' | 'hidden' | 'disabled' | 'absent'
  onCommit?: (geometry: Geometry) => void
  onRender?: (geometry: Geometry) => void
  onPlaceholder?: (pixels: number | undefined) => void
}) {
  const root = useRef<HTMLDivElement>(null)
  const table = useRef<HTMLTableElement>(null)
  const geometry = useTableViewportGeometry(
    facade,
    root,
    table,
    keys,
    (record) => target.isTextRecordCurrent(record),
  )
  const rows = useSignal(target.rows)
  const progress = useSignal(target.progress.rows)
  onRender?.(geometry)
  useLayoutEffect(() => {
    onCommit?.(geometry)
  })
  const placeholder = (
    rowKey: string,
    occurrence: number,
    projection: 'wide' | 'card',
  ) => {
    const pixels = geometry.recordPixels(rowKey, occurrence, projection)
    if (projection === 'wide') onPlaceholder?.(pixels)
    return (
      <div
        className="hilos-table-record-space"
        style={{ '--hilos-table-record-px': pixels } as CSSProperties}
      >
        <span>Removed</span>
      </div>
    )
  }
  return (
    <div ref={root} tabIndex={-1} data-id="geometry-root">
      {search !== 'absent' && (
        <input
          data-id="hilos-table-search"
          hidden={search === 'hidden'}
          disabled={search === 'disabled'}
          aria-label="Search"
        />
      )}
      <div className="wide-projection">
        <table
          ref={table}
          className={geometry.tracks ? 'hilos-table-fixed' : ''}
          style={
            {
              '--hilos-table-width-px': geometry.tracks?.tableWidth,
            } as CSSProperties
          }
        >
          <colgroup>
            {keys.map((key, index) => (
              <col
                key={key}
                style={
                  {
                    '--hilos-table-track-px': geometry.trackWidths[index],
                  } as CSSProperties
                }
              />
            ))}
          </colgroup>
          <thead>
            <tr>
              {keys.map((key) => (
                <th key={key}>{key}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <Fragment key={row.occurrence}>
                <tr
                  data-record={row.rowKey}
                  data-part="main"
                  ref={(node) =>
                    geometry.bind(
                      row.rowKey,
                      row.occurrence,
                      'wide',
                      'main',
                      node,
                    )
                  }
                >
                  <td colSpan={keys.length}>
                    {row.placeholder ? (
                      placeholder(row.rowKey, row.occurrence, 'wide')
                    ) : (
                      <button data-id={`control-${row.rowKey}`}>
                        {row.row}
                      </button>
                    )}
                  </td>
                </tr>
                {!row.placeholder &&
                  (progress.has(row.rowKey) ||
                    geometry.workPixels(row.rowKey, 'wide') > 0) && (
                    <tr
                      data-record={row.rowKey}
                      data-part="work"
                      className={
                        progress.has(row.rowKey)
                          ? ''
                          : 'hilos-table-work-reserve'
                      }
                      ref={(node) =>
                        geometry.bind(
                          row.rowKey,
                          row.occurrence,
                          'wide',
                          'work',
                          node,
                        )
                      }
                    >
                      <td>
                        <div
                          className="hilos-table-work-space"
                          style={
                            {
                              '--hilos-table-work-px': geometry.workPixels(
                                row.rowKey,
                                'wide',
                              ),
                            } as CSSProperties
                          }
                        >
                          {progress.has(row.rowKey) ? 'Work payload' : ''}
                        </div>
                      </td>
                    </tr>
                  )}
                {!row.placeholder && row.expanded && (
                  <tr
                    data-record={row.rowKey}
                    data-part="detail"
                    ref={(node) =>
                      geometry.bind(
                        row.rowKey,
                        row.occurrence,
                        'wide',
                        'detail',
                        node,
                      )
                    }
                  >
                    <td>Private detail</td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
        <div className="hilos-table-room-wide" inert aria-hidden="true">
          <div className="hilos-table-replacement-row">
            <span className="hilos-table-reason-twins">Removed</span>
          </div>
          <div className="hilos-table-fallback" />
        </div>
      </div>
      <div className="card-projection">
        {rows.map((row) => (
          <div
            className="card"
            data-record={row.rowKey}
            data-part="main"
            key={row.occurrence}
            ref={(node) =>
              geometry.bind(row.rowKey, row.occurrence, 'card', 'main', node)
            }
          >
            <div className="card-body">
              {row.placeholder ? (
                placeholder(row.rowKey, row.occurrence, 'card')
              ) : (
                <>
                  <div>
                    <button>{row.row}</button>
                  </div>
                  {row.expanded && (
                    <div
                      data-record={row.rowKey}
                      data-part="detail"
                      ref={(node) =>
                        geometry.bind(
                          row.rowKey,
                          row.occurrence,
                          'card',
                          'detail',
                          node,
                        )
                      }
                    >
                      Private detail
                    </div>
                  )}
                  {(progress.has(row.rowKey) ||
                    geometry.workPixels(row.rowKey, 'card') > 0) && (
                    <div
                      data-record={row.rowKey}
                      data-part="work"
                      className={
                        progress.has(row.rowKey)
                          ? ''
                          : 'hilos-table-work-reserve hilos-table-work-space'
                      }
                      style={
                        {
                          '--hilos-table-work-px': geometry.workPixels(
                            row.rowKey,
                            'card',
                          ),
                        } as CSSProperties
                      }
                      ref={(node) =>
                        geometry.bind(
                          row.rowKey,
                          row.occurrence,
                          'card',
                          'work',
                          node,
                        )
                      }
                    >
                      {progress.has(row.rowKey) ? 'Work payload' : ''}
                    </div>
                  )}
                </>
              )}
            </div>
          </div>
        ))}
        <div className="hilos-table-room-card" inert aria-hidden="true">
          <span className="hilos-table-reason-twins">Removed</span>
          <div className="hilos-table-fallback" />
        </div>
      </div>
    </div>
  )
}

// Synthetic CSS boxes prove ordering, conversion and identity. Native layout and
// Sass are covered by the separate browser slice, never by these mocked rects.
function numericDOM() {
  const layout = {
    zoom: 1,
    width: 400,
    main: 41.25,
    card: 260.75,
    reason: 18,
    fallback: 48,
    font: 16,
    cardHidden: false,
    wideHidden: false,
    spacing: 0,
  }
  const reads: string[] = []
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function (this: HTMLElement) {
      const rect = (top: number, height: number, width = layout.width, x = 0) =>
        new DOMRect(
          x * layout.zoom,
          top * layout.zoom,
          width * layout.zoom,
          height * layout.zoom,
        )
      if (
        this.closest('[hidden]') ||
        (layout.cardHidden && this.closest('.card-projection')) ||
        (layout.wideHidden && this.closest('.wide-projection'))
      )
        return new DOMRect()
      const projection = this.closest('.card-projection') ? 'card' : 'wide'
      if (this.dataset.record)
        reads.push(`${projection}:${this.dataset.record}:${this.dataset.part}`)
      if (this.dataset.part === 'main')
        return rect(
          projection === 'card' ? 300 : 40,
          projection === 'card' ? layout.card : layout.main,
        )
      if (this.dataset.part === 'work')
        return rect(
          projection === 'card' ? 450 : 81.25,
          projection === 'card' ? 24.5 : 18.5,
        )
      if (this.dataset.part === 'detail')
        return rect(projection === 'card' ? 380 : 99.75, 60.25)
      if (this.matches('.card-body > div') && this.querySelector('button'))
        return rect(320, 126)
      if (this.classList.contains('hilos-table-replacement-row'))
        return rect(0, layout.reason + 1)
      if (
        this.classList.contains('hilos-table-reason-twins') ||
        this.matches('.hilos-table-record-space > span')
      )
        return rect(0, layout.reason)
      if (this.tagName === 'TH') {
        const cells = [...this.parentElement!.children]
        const index = cells.indexOf(this)
        const table = this.closest('table')!
        const fixed = Number(
          table.style.getPropertyValue('--hilos-table-width-px'),
        )
        const width = (fixed || layout.width) / cells.length
        return rect(0, 20, width, index * width)
      }
      if (this.tagName === 'TABLE')
        return rect(
          0,
          180,
          Number(this.style.getPropertyValue('--hilos-table-width-px')) ||
            layout.width,
        )
      return rect(0, 100)
    },
  )
  vi.spyOn(HTMLElement.prototype, 'currentCSSZoom', 'get').mockImplementation(
    () => layout.zoom,
  )
  const nativeStyle = window.getComputedStyle.bind(window)
  vi.spyOn(window, 'getComputedStyle').mockImplementation((node) => {
    const original = nativeStyle(node)
    const values: Record<string, string> = {
      fontSize: `${layout.font}px`,
      fontFamily: 'GeometryText, sans-serif',
      borderCollapse: 'collapse',
      borderSpacing: `0px ${layout.spacing}px`,
      paddingTop: node.classList.contains('card-body') ? '8px' : '0px',
      paddingBottom: node.classList.contains('card-body') ? '8px' : '0px',
      borderTopWidth: node.classList.contains('card') ? '1px' : '0px',
      borderBottomWidth: node.classList.contains('card') ? '1px' : '0px',
      marginTop:
        node.getAttribute('data-part') === 'work' && node.tagName !== 'TR'
          ? '4px'
          : '0px',
      marginBottom:
        node.classList.contains('card') ||
        (node.getAttribute('data-part') === 'detail' && node.tagName !== 'TR')
          ? '8px'
          : '0px',
      ...(node.classList.contains('hilos-table-fallback')
        ? { height: `${layout.fallback}px` }
        : {}),
    }
    return new Proxy(original, {
      get(target, key) {
        return typeof key === 'string' && key in values
          ? values[key]
          : Reflect.get(target, key)
      },
    })
  })
  return { layout, reads }
}

function observers() {
  const resizes: { fire(): void; disconnect: ReturnType<typeof vi.fn> }[] = []
  const mutations: { fire(): void; disconnect: ReturnType<typeof vi.fn> }[] = []
  class Resize {
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
    constructor(callback: () => void) {
      resizes.push({ fire: callback, disconnect: this.disconnect })
    }
  }
  class Mutation {
    observe = vi.fn()
    disconnect = vi.fn()
    constructor(callback: () => void) {
      mutations.push({ fire: callback, disconnect: this.disconnect })
    }
  }
  vi.stubGlobal('ResizeObserver', Resize)
  vi.stubGlobal('MutationObserver', Mutation)
  const queued = new Map<number, FrameRequestCallback>()
  let id = 0
  vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
    queued.set(++id, callback)
    return id
  })
  vi.spyOn(window, 'cancelAnimationFrame').mockImplementation((key) => {
    queued.delete(key)
  })
  const fonts = new EventTarget()
  let ready!: () => void
  Object.defineProperty(fonts, 'ready', {
    value: new Promise<void>((resolve) => {
      ready = resolve
    }),
  })
  vi.stubGlobal('FontFaceSet', EventTarget)
  Object.defineProperty(document, 'fonts', { configurable: true, value: fonts })
  return {
    resizes,
    mutations,
    queued,
    ready,
    fonts,
    flush() {
      const callbacks = [...queued.values()]
      queued.clear()
      act(() => {
        callbacks.forEach((callback) => callback(0))
      })
    },
  }
}

beforeEach(() => {
  // jsdom has no layout zoom implementation, so expose the browser's read seam.
  Object.defineProperty(HTMLElement.prototype, 'currentCSSZoom', {
    configurable: true,
    get: () => 1,
  })
})
afterEach(() => {
  cleanup()
  controllers.splice(0).forEach((target) => target.dispose())
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.replaceChildren()
  Reflect.deleteProperty(document, 'fonts')
})

describe('React table geometry commit boundary', () => {
  it('reads immutable owner snapshots during render without creating core measurement state', () => {
    numericDOM()
    const target = controller(['a'])
    const source = instrument(target)
    const renderReads: number[] = []
    render(
      <Harness
        target={target}
        facade={source.facade}
        onRender={(geometry) => {
          const owner = source.registrations.at(-1)
          if (!owner) return
          const before = vi.mocked(owner.record).mock.calls.length
          const measurements = vi.mocked(owner.measurement).mock.calls.length
          for (const row of target.rows.get()) {
            geometry.recordPixels(row.rowKey, row.occurrence, 'wide')
            geometry.workPixels(row.rowKey, 'wide')
            for (const projection of ['wide', 'card'] as const)
              geometry.recordReader(row.rowKey, row.occurrence, projection)()
          }
          renderReads.push(vi.mocked(owner.record).mock.calls.length - before)
          expect(vi.mocked(owner.measurement).mock.calls.length).toBe(
            measurements,
          )
        }}
      />,
    )
    act(() => snapshot(target, ['a', 'c']))
    act(() => depart(target))
    expect(renderReads.length).toBeGreaterThan(2)
    expect(renderReads.every((count) => count === 0)).toBe(true)
  })

  it('captures the whole batch before the first destructive synchronous publication', () => {
    const { layout, reads } = numericDOM()
    layout.zoom = 2
    const target = controller()
    const source = instrument(target)
    target.expandRow('a', true)
    target.ingestProgress({
      scope: 'row',
      rowKey: 'a',
      progressKey: 'job',
      current: 1,
      total: 2,
    })
    const first: (number | undefined)[] = []
    let geometry!: Geometry
    const view = render(
      <Harness
        target={target}
        facade={source.facade}
        onCommit={(value) => {
          geometry = value
        }}
        onPlaceholder={(value) => first.push(value)}
      />,
    )
    const owner = source.registrations.at(-1)!
    const stop = subscribeSignal(target.rows, () => {
      expect(reads).toEqual(
        expect.arrayContaining([
          'wide:a:detail',
          'wide:a:work',
          'wide:b:main',
          'card:b:main',
        ]),
      )
    })
    reads.length = 0
    act(() =>
      target.withPresentationUpdate(
        { kind: 'rows', rowKeys: ['a', 'b'] },
        () => {
          depart(target, 'a')
          depart(target, 'b')
        },
      ),
    )
    expect(source.captures).toHaveBeenCalledTimes(1)
    expect(owner.record('a', 'wide')?.retained).toBe(120)
    expect(owner.record('a', 'card')?.retained).toBe(268.75)
    expect(first[0]).toBe(119)
    expect(
      geometry.recordPixels('a', target.rows.get()[0]!.occurrence, 'card'),
    ).toBe(268.75)
    expect(geometry.trackWidths).toEqual([200, 200])
    expect(view.container.textContent).not.toContain('Private detail')
    expect(view.container.textContent).not.toContain('Work payload')
    stop()
  })

  it('preserves work removed before Apply once and lets later work-end stay gone', () => {
    numericDOM()
    const target = controller()
    const source = instrument(target)
    target.expandRow('a', true)
    target.ingestProgress({
      scope: 'row',
      rowKey: 'a',
      progressKey: 'job',
      current: 1,
      total: 2,
    })
    let geometry!: Geometry
    const view = render(
      <Harness
        target={target}
        facade={source.facade}
        onCommit={(value) => {
          geometry = value
        }}
      />,
    )
    act(() => depart(target, 'a', false))
    expect(geometry.workPixels('a', 'wide')).toBe(18.5)
    expect(
      view.container.querySelectorAll('.hilos-table-work-reserve'),
    ).toHaveLength(2)
    expect(view.container.textContent).not.toContain('Work payload')
    act(() => target.apply())
    expect(source.registrations[0]!.record('a', 'wide')?.retained).toBe(120)
    act(() =>
      target.ingestProgress({
        scope: 'row',
        rowKey: 'a',
        progressKey: 'job',
        current: 2,
        ended: true,
      }),
    )
    expect(source.registrations[0]!.record('a', 'wide')?.retained).toBe(120)
    expect(
      view.container.querySelectorAll('.hilos-table-work-reserve'),
    ).toHaveLength(0)
  })

  it('does not resurrect work that ended while the record was live', () => {
    numericDOM()
    const target = controller()
    const source = instrument(target)
    target.ingestProgress({
      scope: 'row',
      rowKey: 'a',
      progressKey: 'job',
      current: 1,
      total: 2,
    })
    render(<Harness target={target} facade={source.facade} />)
    act(() =>
      target.ingestProgress({
        scope: 'row',
        rowKey: 'a',
        progressKey: 'job',
        current: 2,
        ended: true,
      }),
    )
    act(() => depart(target))
    expect(source.registrations[0]!.record('a', 'wide')?.retained).toBe(41.25)
  })

  it('does not capture an old occurrence for a reused key published before commit', () => {
    numericDOM()
    const target = controller(['a'])
    const source = instrument(target)
    render(<Harness target={target} facade={source.facade} />)
    const firstOccurrence = target.rows.get()[0]!.occurrence
    act(() => {
      snapshot(target, ['a'])
      depart(target)
    })
    expect(target.rows.get()[0]!.occurrence).not.toBe(firstOccurrence)
    const latest = source.captures.mock.calls.at(-1)![0]
    expect(
      latest.records.every(
        (sample: { extent: unknown }) => sample.extent === null,
      ),
    ).toBe(true)
    expect(source.registrations[0]!.record('a', 'wide')?.retained).toBe(49)
  })

  it('captures connected DOM before a real binder normalizes its entity', () => {
    const { layout } = numericDOM()
    const target = controller(['a'])
    const source = instrument(target)
    const scopes = new ScopeManager()
    const scope = scopes.openPage('main')
    normalizeTableRow(scope, {
      rowKey: 'a',
      slots: { user: { id: 7, name: 'old' } },
    })
    const listeners = new Map<string, (signal: unknown) => void>()
    const connection = {
      on: (event: string, listener: (signal: unknown) => void) => {
        listeners.set(event, listener)
        return () => listeners.delete(event)
      },
      registerTableWindow: () => {},
      unregisterTableWindow: () => {},
    } as unknown as HilosConnection
    const unbind = bindTableViewport(
      connection,
      scopes,
      { tableKey: 'people', page: 'main' },
      target,
    )
    render(<Harness target={target} facade={source.facade} />)
    let before = 0
    const stop = subscribeSignal(
      scope.entities.signal({ type: 'user', id: 7 }),
      () => {
        before = source.captures.mock.calls.length
        expect(source.captures.mock.calls[0]![0].records[0].extent.main).toBe(
          41.25,
        )
        layout.main = 1
      },
    )
    act(() =>
      listeners.get('tableWindow')!({
        data: {
          page: 'main',
          tableKey: 'people',
          rows: [{ rowKey: 'a', slots: { user: { id: 7, name: 'new' } } }],
          totalCount: 1,
          totalExact: true,
          limit: 10,
          firstAnchor: null,
          lastAnchor: null,
        },
      }),
    )
    expect(before).toBe(1)
    stop()
    unbind()
    scopes.dropPage()
  })
})

describe('React table geometry layout lifetime', () => {
  it('refreshes live values and honors an intentional detail collapse', () => {
    const { layout } = numericDOM()
    const target = controller()
    const source = instrument(target)
    target.expandRow('a', true)
    render(<Harness target={target} facade={source.facade} />)
    expect(source.registrations[0]!.record('a', 'wide')?.live?.total).toBe(120)
    act(() => target.expandRow('a', false))
    expect(source.registrations[0]!.record('a', 'wide')?.live?.total).toBe(
      41.25,
    )
    layout.main = 66.5
    act(() =>
      target.ingestDelta({
        kind: 'row_updated',
        rowKey: 'b',
        row: { rowKey: 'b', slots: { name: 'Updated' } },
      }),
    )
    expect(source.registrations[0]!.record('b', 'wide')?.live?.total).toBe(66.5)
    act(() => depart(target, 'b'))
    expect(source.registrations[0]!.record('b', 'wide')?.retained).toBe(66.5)
  })

  it('refuses hidden tracks and measures them on the first visible layout', () => {
    const { layout } = numericDOM()
    const events = observers()
    layout.wideHidden = true
    const target = controller()
    const source = instrument(target)
    render(<Harness target={target} facade={source.facade} />)
    expect(source.registrations[0]!.tracks()).toBeNull()
    layout.wideHidden = false
    events.resizes[0]!.fire()
    events.flush()
    expect(source.registrations[0]!.tracks()?.tableWidth).toBe(400)
  })

  it('keeps all-departed tracks through unrelated font completions and the next append', () => {
    numericDOM()
    const events = observers()
    const target = controller(['a'])
    const source = instrument(target)
    render(<Harness target={target} facade={source.facade} />)
    const owner = source.registrations[0]!
    const tracks = owner.tracks()
    const completion = (family: string) => {
      const event = new Event('loadingdone')
      Object.defineProperty(event, 'fontfaces', { value: [{ family }] })
      return event
    }
    act(() => depart(target))
    for (let repeat = 0; repeat < 3; repeat++) {
      act(() => events.fonts.dispatchEvent(completion('FixtureIcons')))
      events.flush()
      expect(owner.tracks()).toBe(tracks)
    }
    act(() =>
      target.ingestAppend(
        { rowKey: 'b', slots: { name: 'New live value' } },
        1,
        true,
        null,
        null,
      ),
    )
    expect(owner.tracks()).toBe(tracks)
    expect(owner.invalidateLayout).not.toHaveBeenCalled()

    act(() => events.fonts.dispatchEvent(completion('GeometryText')))
    events.flush()
    expect(owner.invalidateLayout).toHaveBeenCalledTimes(2)
    expect(owner.tracks()).not.toBe(tracks)
  })

  it('drops retired allocation and tracks immediately on refusal and a new snapshot', () => {
    numericDOM()
    const target = controller(['a'])
    const source = instrument(target)
    render(<Harness target={target} facade={source.facade} />)
    act(() => depart(target))
    expect(source.registrations[0]!.record('a', 'wide')?.retained).toBe(41.25)
    act(() => target.ingestRefusal('forbidden'))
    expect(source.registrations[0]!.record('a', 'wide')).toBeNull()
    expect(source.registrations[0]!.tracks()).toBeNull()
    act(() => snapshot(target, ['a']))
    expect(source.registrations[0]!.record('a', 'wide')?.retained).toBeNull()
    expect(source.registrations[0]!.record('a', 'wide')?.live?.total).toBe(
      41.25,
    )
  })

  it('waits for the first live row instead of latching empty headers', () => {
    numericDOM()
    const target = controller([])
    const source = instrument(target)
    render(<Harness target={target} facade={source.facade} />)
    expect(source.registrations[0]!.tracks()).toBeNull()
    act(() => snapshot(target, ['a']))
    expect(source.registrations[0]!.tracks()?.widths).toEqual([200, 200])
  })

  it('releases fixed tracks in committed DOM before reading the new natural layout', () => {
    const { layout } = numericDOM()
    const events = observers()
    const target = controller()
    let geometry!: Geometry
    const view = render(
      <Harness
        target={target}
        onCommit={(value) => {
          geometry = value
        }}
      />,
    )
    expect(geometry.tracks?.tableWidth).toBe(400)
    layout.width = 600
    events.resizes[0]!.fire()
    events.flush()
    expect(geometry.tracks?.tableWidth).toBe(600)
    expect(view.container.querySelector('table')?.className).toBe(
      'hilos-table-fixed',
    )
    view.rerender(
      <Harness
        target={target}
        keys={['column:name', 'extra', 'state']}
        onCommit={(value) => {
          geometry = value
        }}
      />,
    )
    expect(geometry.tracks?.columnKeys).toEqual([
      'column:name',
      'extra',
      'state',
    ])
    expect(geometry.trackWidths).toEqual([200, 200, 200])
  })

  it('invalidates live hidden geometry and latches fallback in CSS px only on display', () => {
    const { layout } = numericDOM()
    const events = observers()
    const target = controller(['a'])
    const source = instrument(target)
    let geometry!: Geometry
    render(
      <Harness
        target={target}
        facade={source.facade}
        onCommit={(value) => {
          geometry = value
        }}
      />,
    )
    layout.cardHidden = true
    act(() =>
      target.ingestDelta({
        kind: 'row_updated',
        rowKey: 'a',
        row: { rowKey: 'a', slots: { name: 'Changed' } },
      }),
    )
    act(() => depart(target))
    const owner = source.registrations[0]!
    expect(owner.record('a', 'card')?.retained).toBeNull()
    layout.cardHidden = false
    events.resizes[0]!.fire()
    events.flush()
    expect(owner.record('a', 'card')?.retained).toBe(74)
    expect(geometry.reasonFloors.card).toBe(18)
    layout.font = 32
    layout.fallback = 96
    act(() => events.fonts.dispatchEvent(new Event('loadingdone')))
    events.flush()
    expect(owner.record('a', 'card')?.retained).toBe(74)
    layout.reason = 100
    events.resizes[0]!.fire()
    events.flush()
    expect(owner.record('a', 'card')?.retained).toBe(126)
    layout.reason = 20
    events.resizes[0]!.fire()
    events.flush()
    expect(owner.record('a', 'card')?.retained).toBe(126)
  })

  it('keeps captured allocation while typography and spacing change repeatedly', () => {
    const { layout } = numericDOM()
    const events = observers()
    const target = controller(['a'])
    const source = instrument(target)
    render(<Harness target={target} facade={source.facade} />)
    act(() => depart(target))
    layout.font = 22
    layout.zoom = 1.25
    layout.spacing = 4
    act(() => events.mutations[0]!.fire())
    events.flush()
    expect(source.registrations[0]!.record('a', 'wide')?.retained).toBe(41.25)
    layout.reason = 80
    events.resizes[0]!.fire()
    events.flush()
    expect(source.registrations[0]!.record('a', 'wide')?.retained).toBe(81)
  })
})

describe('React exact geometry record readers', () => {
  it('binds the first committed owner identity and never fills an initially unbound reader', () => {
    numericDOM()
    const target = controller(['a'])
    const source = instrument(target)
    const occurrence = target.rows.get()[0]!.occurrence
    const readers: (() => TableGeometryRecord | null)[] = []
    let geometry!: Geometry
    render(
      <Harness
        target={target}
        facade={source.facade}
        onRender={(value) => {
          readers.push(value.recordReader('a', occurrence, 'wide'))
        }}
        onCommit={(value) => {
          geometry = value
        }}
      />,
    )
    const owner = source.registrations[0]!
    const identity = owner.measurement('a', 'wide')!.record
    expect(source.registrations).toHaveLength(1)
    expect(readers[0]!()).toBeNull()
    expect(readers.at(-1)!()).toBe(identity)
    const wide = geometry.recordReader('a', occurrence, 'wide')
    const card = geometry.recordReader('a', occurrence, 'card')
    expect(wide).not.toBe(card)
    expect(wide()).toBe(identity)
    expect(card()).toBe(owner.measurement('a', 'card')!.record)
    expect(geometry.recordReader('a', occurrence + 1, 'wide')()).toBeNull()
    expect(geometry.recordReader('absent', occurrence, 'card')()).toBeNull()

    act(() => owner.invalidateLayout('wide'))
    expect(wide()).toBe(identity)
    expect(card()).toBe(identity)
    expect(readers[0]!()).toBeNull()
  })

  it('commits a newly rendered hidden occurrence without waiting for a measurement notification', () => {
    const { layout } = numericDOM()
    layout.wideHidden = true
    layout.cardHidden = true
    const target = controller([])
    const source = instrument(target)
    const readers: (() => TableGeometryRecord | null)[] = []
    render(
      <Harness
        target={target}
        facade={source.facade}
        onRender={(geometry) => {
          const row = target.rows.get()[0]
          if (row)
            readers.push(
              geometry.recordReader(row.rowKey, row.occurrence, 'card'),
            )
        }}
      />,
    )
    act(() => snapshot(target, ['a']))
    const owner = source.registrations[0]!
    expect(owner.measure).not.toHaveBeenCalled()
    expect(owner.tracks()).toBeNull()
    expect(readers[0]!()).toBeNull()
    expect(readers.at(-1)!()).toBe(owner.measurement('a', 'card')!.record)
    expect(source.registrations).toHaveLength(1)
  })

  it('keeps both readers through live updates, reorder and pending departure until Apply', () => {
    numericDOM()
    const target = controller()
    const source = instrument(target)
    let geometry!: Geometry
    render(
      <Harness
        target={target}
        facade={source.facade}
        onCommit={(value) => {
          geometry = value
        }}
      />,
    )
    const occurrence = target.rows.get()[0]!.occurrence
    const readers = (['wide', 'card'] as const).map((projection) =>
      geometry.recordReader('a', occurrence, projection),
    )
    const identity = readers[0]!()!
    act(() => {
      target.ingestDelta({
        kind: 'row_updated',
        rowKey: 'a',
        row: { rowKey: 'a', slots: { name: 'updated' } },
      })
      expect(readers.map((read) => read())).toEqual([identity, identity])
      target.ingestDelta({
        kind: 'row_moved',
        rowKey: 'a',
        own: true,
        row: { rowKey: 'a', slots: { name: 'moved' } },
        position: 1,
      })
      expect(readers.every((read) => read() === identity)).toBe(true)
    })
    expect(target.rows.get().map((row) => row.rowKey)).toEqual(['b', 'a'])
    act(() => {
      depart(target, 'a', false)
      expect(readers.every((read) => read() === identity)).toBe(true)
    })
    act(() => {
      target.apply()
      // The DOM has not flushed: the readers already refuse final departure.
      expect(readers.map((read) => read())).toEqual([null, null])
    })
    expect(target.expandedText.get().size).toBe(0)
    expect(source.registrations).toHaveLength(1)
  })

  it.each([
    'reset',
    'refusal',
    'window',
    'search',
    'reuse',
    'departure',
  ] as const)(
    'retires the saved scope synchronously on %s with no expanded text',
    (change) => {
      numericDOM()
      const target = controller(['a'])
      const source = instrument(target)
      const renders = vi.fn()
      let geometry!: Geometry
      render(
        <Harness
          target={target}
          facade={source.facade}
          onRender={renders}
          onCommit={(value) => {
            geometry = value
          }}
        />,
      )
      const occurrence = target.rows.get()[0]!.occurrence
      const wide = geometry.recordReader('a', occurrence, 'wide')
      const card = geometry.recordReader('a', occurrence, 'card')
      const identity = wide()
      expect(identity).not.toBeNull()
      const intent = target.expandedText.get()
      const before = renders.mock.calls.length
      act(() => {
        if (change === 'reset') target.resetWindowRequests()
        if (change === 'refusal') target.ingestRefusal('forbidden')
        if (change === 'window') snapshot(target, ['a'])
        if (change === 'search') target.setSearch('another window')
        if (change === 'reuse')
          target.ingestOwnCreate(
            { rowKey: 'a', slots: { name: 'reused' } },
            0,
            1,
            true,
            null,
            null,
          )
        if (change === 'departure') depart(target)
        expect(renders).toHaveBeenCalledTimes(before)
        expect(wide()).toBeNull()
        expect(card()).toBeNull()
      })
      expect(target.expandedText.get()).toBe(intent)
      expect(renders.mock.calls.length).toBeGreaterThan(before)
      if (change === 'reset' || change === 'search') {
        // Reset keeps its geometry identity; search reissues it. Both leave an
        // outgoing row whose inactive text window only the controller knows.
        const outgoing = source.registrations[0]!.measurement(
          'a',
          'wide',
        )!.record
        if (change === 'reset') expect(outgoing).toBe(identity)
        else expect(outgoing).not.toBe(identity)
        expect(geometry.recordReader('a', occurrence, 'wide')()).toBeNull()
        expect(geometry.recordReader('a', occurrence, 'card')()).toBeNull()
      }
      act(() => snapshot(target, ['a']))
      const next = geometry.recordReader(
        'a',
        target.rows.get()[0]!.occurrence,
        'wide',
      )
      expect(next()).toBe(
        source.registrations[0]!.measurement('a', 'wide')!.record,
      )
      expect(next()).not.toBe(identity)
      expect(wide()).toBeNull()
      expect(card()).toBeNull()
    },
  )

  it('gives replacement controllers and remounts their own binding without reviving saved readers', async () => {
    numericDOM()
    const events = observers()
    const first = controller(['a'])
    const old = instrument(first)
    const second = controller(['a'])
    const next = instrument(second)
    let geometry!: Geometry
    const committed = (value: Geometry) => {
      geometry = value
    }
    const view = render(
      <Harness target={first} facade={old.facade} onCommit={committed} />,
    )
    const previous = geometry.recordReader(
      'a',
      first.rows.get()[0]!.occurrence,
      'wide',
    )
    const previousIdentity = previous()
    expect(previousIdentity).not.toBeNull()
    events.resizes[0]!.fire()
    const late = [...events.queued.values()]
    const replacementRenders: (() => TableGeometryRecord | null)[] = []
    view.rerender(
      <Harness
        target={second}
        facade={next.facade}
        onCommit={committed}
        onRender={(value) => {
          replacementRenders.push(
            value.recordReader('a', second.rows.get()[0]!.occurrence, 'wide'),
          )
        }}
      />,
    )
    const replacement = replacementRenders.at(-1)!
    expect(replacementRenders[0]!()).toBeNull()
    expect(replacement()).toBe(
      next.registrations[0]!.measurement('a', 'wide')!.record,
    )
    expect(replacement()).not.toBe(previousIdentity)
    expect(previous()).toBeNull()
    view.unmount()
    expect(replacement()).toBeNull()
    render(
      <Harness target={second} facade={next.facade} onCommit={committed} />,
    )
    const remounted = geometry.recordReader(
      'a',
      second.rows.get()[0]!.occurrence,
      'card',
    )
    const identity = next.registrations[1]!.measurement('a', 'card')!.record
    expect(remounted()).toBe(identity)
    await act(async () => {
      late.forEach((callback) => callback(0))
      events.resizes[0]!.fire()
      events.mutations[0]!.fire()
      events.ready()
      await Promise.resolve()
    })
    expect(previous()).toBeNull()
    expect(replacement()).toBeNull()
    expect(remounted()).toBe(identity)
    expect(old.registrations).toHaveLength(1)
    expect(next.registrations).toHaveLength(2)
  })

  it('keeps StrictMode replay and late callbacks inside their registration lifetime', async () => {
    numericDOM()
    const events = observers()
    const target = controller(['a'])
    const source = instrument(target)
    const readers: (() => TableGeometryRecord | null)[] = []
    const tree = (
      <StrictMode>
        <Harness
          target={target}
          facade={source.facade}
          onCommit={(geometry) => {
            readers.push(
              geometry.recordReader(
                'a',
                target.rows.get()[0]!.occurrence,
                'wide',
              ),
            )
          }}
        />
      </StrictMode>
    )
    const view = render(tree)
    expect(source.registrations).toHaveLength(2)
    expect(source.registrations[0]!.dispose).toHaveBeenCalledTimes(1)
    const replayed = readers.at(-1)!
    expect(readers[0]!()).toBeNull()
    expect(replayed()).toBe(
      source.registrations[1]!.measurement('a', 'wide')!.record,
    )
    events.resizes[1]!.fire()
    const late = [...events.queued.values()]
    view.unmount()
    expect(readers.every((read) => read() === null)).toBe(true)
    render(tree)
    const remounted = readers.at(-1)!
    const identity = source.registrations[3]!.measurement('a', 'wide')!.record
    await act(async () => {
      late.forEach((callback) => callback(0))
      events.resizes[1]!.fire()
      events.mutations[1]!.fire()
      events.ready()
      await Promise.resolve()
    })
    expect(readers[0]!()).toBeNull()
    expect(replayed()).toBeNull()
    expect(remounted()).toBe(identity)
    expect(source.registrations).toHaveLength(4)
  })
})

describe('React table geometry lifecycle and focus', () => {
  it('replays StrictMode setup and releases every observer, registration and queued callback', async () => {
    const { reads } = numericDOM()
    const events = observers()
    const addWindow = vi.spyOn(window, 'addEventListener')
    const removeWindow = vi.spyOn(window, 'removeEventListener')
    const addDocument = vi.spyOn(document, 'addEventListener')
    const removeDocument = vi.spyOn(document, 'removeEventListener')
    const addFonts = vi.spyOn(events.fonts, 'addEventListener')
    const removeFonts = vi.spyOn(events.fonts, 'removeEventListener')
    const target = controller(['a'])
    const source = instrument(target)
    const view = render(
      <StrictMode>
        <Harness target={target} facade={source.facade} />
      </StrictMode>,
    )
    expect(source.registrations).toHaveLength(2)
    expect(source.registrations[0]!.dispose).toHaveBeenCalledTimes(1)
    expect(source.registrations[1]!.tracks()?.tableWidth).toBe(400)
    events.resizes.at(-1)!.fire()
    const late = [...events.queued.values()]
    view.unmount()
    expect(
      source.registrations.every(
        (owner) => vi.mocked(owner.dispose).mock.calls.length === 1,
      ),
    ).toBe(true)
    expect(
      events.resizes.every(
        (observer) => observer.disconnect.mock.calls.length === 1,
      ),
    ).toBe(true)
    expect(
      events.mutations.every(
        (observer) => observer.disconnect.mock.calls.length === 1,
      ),
    ).toBe(true)
    expect(events.queued.size).toBe(0)
    for (const [add, remove, event] of [
      [addWindow, removeWindow, 'resize'],
      [addDocument, removeDocument, 'visibilitychange'],
      [addFonts, removeFonts, 'loadingdone'],
    ] as const) {
      const registrations = add.mock.calls.filter(([name]) => name === event)
      expect(registrations).toHaveLength(2)
      expect(remove.mock.calls.filter(([name]) => name === event)).toHaveLength(
        2,
      )
      for (const [, listener] of registrations)
        expect(remove).toHaveBeenCalledWith(event, listener)
    }
    reads.length = 0
    await act(async () => {
      late.forEach((callback) => callback(0))
      events.resizes.forEach((observer) => observer.fire())
      events.mutations.forEach((observer) => observer.fire())
      events.ready()
      window.dispatchEvent(new Event('resize'))
      document.dispatchEvent(new Event('visibilitychange'))
      events.fonts.dispatchEvent(new Event('loadingdone'))
      await Promise.resolve()
    })
    expect(reads).toEqual([])
    expect(events.queued.size).toBe(0)
  })

  it('ignores the old font-ready continuation while a replacement mount is active', async () => {
    numericDOM()
    const events = observers()
    const first = controller(['a'])
    const old = instrument(first)
    const second = controller(['b'])
    const next = instrument(second)
    const view = render(<Harness target={first} facade={old.facade} />)
    view.rerender(<Harness target={second} facade={next.facade} />)
    await act(async () => {
      events.ready()
      await Promise.resolve()
    })
    expect(old.registrations[0]!.invalidateLayout).not.toHaveBeenCalled()
    expect(next.registrations[0]!.invalidateLayout).toHaveBeenCalledTimes(2)
    expect(next.registrations[0]!.tracks()?.tableWidth).toBe(400)
  })

  it('replaces controllers and remounts reused keys without old pixels or callbacks', () => {
    const { layout } = numericDOM()
    const events = observers()
    const first = controller(['a'])
    const old = instrument(first)
    const second = controller(['a'])
    const next = instrument(second)
    const view = render(<Harness target={first} facade={old.facade} />)
    events.resizes[0]!.fire()
    const late = [...events.queued.values()]
    layout.main = 66.5
    view.rerender(<Harness target={second} facade={next.facade} />)
    expect(old.registrations[0]!.dispose).toHaveBeenCalledTimes(1)
    act(() => {
      late.forEach((callback) => callback(0))
      depart(first)
      depart(second)
    })
    expect(old.captures).not.toHaveBeenCalled()
    expect(next.registrations[0]!.record('a', 'wide')?.retained).toBe(66.5)
    view.unmount()
    snapshot(second, ['a'])
    layout.main = 20.5
    render(<Harness target={second} facade={next.facade} />)
    act(() => depart(second))
    expect(next.registrations[1]!.record('a', 'wide')?.retained).toBe(20.5)
  })

  it.each(['visible', 'hidden', 'disabled', 'absent'] as const)(
    'returns gone-control focus to %s search or the stable root',
    (search) => {
      numericDOM()
      const target = controller()
      const view = render(<Harness target={target} search={search} />)
      const button = view.container.querySelector<HTMLElement>(
        '[data-id="control-a"]',
      )!
      button.focus()
      act(() => depart(target))
      expect(document.activeElement).toBe(
        view.container.querySelector(
          search === 'visible'
            ? '[data-id="hilos-table-search"]'
            : '[data-id="geometry-root"]',
        ),
      )
    },
  )

  it('preserves another explicit focus choice and an active modal trap', () => {
    numericDOM()
    const target = controller()
    const view = render(<Harness target={target} />)
    view.container.querySelector<HTMLElement>('[data-id="control-a"]')!.focus()
    const outside = document.createElement('button')
    document.body.append(outside)
    act(() => {
      depart(target)
      outside.focus()
    })
    expect(document.activeElement).toBe(outside)
    const opener = view.container.querySelector<HTMLElement>(
      '[data-id="control-b"]',
    )!
    opener.focus()
    const modal = document.createElement('div')
    modal.tabIndex = -1
    document.body.append(modal)
    const trap = new FocusTrap()
    trap.activate(modal, 'dialog')
    act(() => depart(target, 'b'))
    expect(document.activeElement).toBe(modal)
    trap.release()
    expect(document.activeElement).toBe(
      view.container.querySelector('[data-id="hilos-table-search"]'),
    )
  })

  it('keeps connected modal openers and cancels captured focus offers on replacement', () => {
    numericDOM()
    const first = controller()
    const second = controller(['c'])
    const view = render(<Harness target={first} />)
    const modal = document.createElement('div')
    modal.tabIndex = -1
    document.body.append(modal)
    const trap = new FocusTrap()
    const opener = view.container.querySelector<HTMLElement>(
      '[data-id="control-a"]',
    )!
    opener.focus()
    trap.activate(modal, 'dialog')
    trap.release()
    expect(document.activeElement).toBe(opener)
    trap.activate(modal, 'dialog')
    act(() => depart(first))
    view.rerender(<Harness target={second} />)
    trap.release()
    expect(document.activeElement).toBe(modal)
  })

  it('returns a gone modal opener to search even when a new occurrence repeats its data-id', () => {
    numericDOM()
    const target = controller(['a'])
    const view = render(<Harness target={target} />)
    const opener = view.container.querySelector<HTMLElement>(
      '[data-id="control-a"]',
    )!
    const modal = document.createElement('div')
    modal.tabIndex = -1
    document.body.append(modal)
    opener.focus()
    const trap = new FocusTrap()
    trap.activate(modal, 'dialog')
    act(() => snapshot(target, ['a']))
    expect(view.container.querySelector('[data-id="control-a"]')).not.toBe(
      opener,
    )
    expect(document.activeElement).toBe(modal)
    trap.release()
    expect(document.activeElement).toBe(
      view.container.querySelector('[data-id="hilos-table-search"]'),
    )
  })

  it('renders static markup with no browser globals, registration or DOM reads', () => {
    const target = controller()
    const source = instrument(target)
    const root = document
    vi.stubGlobal('window', undefined)
    vi.stubGlobal('document', undefined)
    vi.stubGlobal('HTMLElement', undefined)
    try {
      const markup = renderToStaticMarkup(
        <Harness
          target={target}
          facade={source.facade}
          onRender={(geometry) => {
            const row = target.rows.get()[0]!
            expect(
              geometry.recordReader(row.rowKey, row.occurrence, 'wide')(),
            ).toBeNull()
          }}
        />,
      )
      expect(markup).toContain('control-a')
      expect(source.registrations).toEqual([])
    } finally {
      vi.unstubAllGlobals()
      expect(document).toBe(root)
    }
  })
})
