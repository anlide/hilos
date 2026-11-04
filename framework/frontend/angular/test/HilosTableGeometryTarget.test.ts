import { Component, signal } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { TableViewportGeometry } from '@hilos/core/base'
import { HilosTableGeometryTarget } from '../src/HilosTableGeometryTarget.js'
import {
  createTableViewportGeometry,
  type TableViewportGeometryAdapter,
} from '../src/tableViewportGeometry.js'

@Component({
  imports: [HilosTableGeometryTarget],
  template: `
    @if (shown()) {
      <div
        [hilosTableGeometryTarget]="adapter()"
        [geometryRowKey]="rowKey()"
        [geometryOccurrence]="occurrence()"
        geometryProjection="wide"
        geometryPart="main"
      ></div>
    }
  `,
})
class TargetHost {
  readonly shown = signal(true)
  readonly adapter = signal<TableViewportGeometryAdapter | null>(null)
  readonly rowKey = signal('a')
  readonly occurrence = signal(1)
}

/** One tile in its grid column: the same directive binds main, work and detail. */
@Component({
  imports: [HilosTableGeometryTarget],
  template: `
    <table></table>
    <div class="row">
      <div class="col" data-box="column">
        <div
          class="card hilos-table-tile"
          data-box="tile"
          [hilosTableGeometryTarget]="adapter()"
          geometryRowKey="a"
          [geometryOccurrence]="occurrence()"
          geometryProjection="tiles"
          geometryPart="main"
        >
          @if (live()) {
            <div class="ratio ratio-4x3"><img alt="live picture" /></div>
            <div class="card-body">
              <div
                data-box="detail"
                [hilosTableGeometryTarget]="adapter()"
                geometryRowKey="a"
                [geometryOccurrence]="occurrence()"
                geometryProjection="tiles"
                geometryPart="detail"
              >
                detail values
              </div>
              <div
                data-box="work"
                [hilosTableGeometryTarget]="adapter()"
                geometryRowKey="a"
                [geometryOccurrence]="occurrence()"
                geometryProjection="tiles"
                geometryPart="work"
              >
                work payload
              </div>
            </div>
          } @else {
            <div class="card-body">
              <div class="hilos-table-record-space">
                <span data-box="reason">Removed</span>
              </div>
            </div>
          }
        </div>
      </div>
    </div>
  `,
})
class TileHost {
  readonly adapter = signal<TableViewportGeometryAdapter | null>(null)
  readonly occurrence = signal(1)
  readonly live = signal(true)
}

const adapters: TableViewportGeometryAdapter[] = []
afterEach(() => {
  TestBed.resetTestingModule()
  for (const adapter of adapters.splice(0)) adapter.dispose()
  vi.restoreAllMocks()
  document.body.replaceChildren()
})

function adapter(owner = new TableViewportGeometry()) {
  if (!owner.record('a')) owner.replaceWindow(['a'])
  const result = createTableViewportGeometry(owner.view)
  adapters.push(result)
  return result
}

describe('HilosTableGeometryTarget', () => {
  it('binds the real ElementRef and cleans up precisely when its tuple changes or disappears', () => {
    const geometry = adapter()
    const cleanups: ReturnType<typeof vi.fn>[] = []
    const bind = vi.spyOn(geometry, 'bind').mockImplementation(() => {
      const cleanup = vi.fn()
      cleanups.push(cleanup)
      return cleanup
    })
    const fixture = TestBed.createComponent(TargetHost)
    fixture.componentInstance.adapter.set(geometry)
    fixture.detectChanges()
    const node = (fixture.nativeElement as HTMLElement).querySelector('div')!
    expect(bind).toHaveBeenLastCalledWith('a', 1, 'wide', 'main', node)
    fixture.componentInstance.occurrence.set(2)
    fixture.detectChanges()
    expect(cleanups[0]).toHaveBeenCalledExactlyOnceWith()
    expect(bind).toHaveBeenLastCalledWith('a', 2, 'wide', 'main', node)
    fixture.componentInstance.shown.set(false)
    fixture.detectChanges()
    expect(cleanups[1]).toHaveBeenCalledExactlyOnceWith()
    expect(bind).toHaveBeenCalledTimes(2)
  })

  it('rebinds the same mounted node to a replacement controller adapter', () => {
    const first = adapter()
    const second = adapter()
    const cleanup = vi.fn()
    const bindFirst = vi.spyOn(first, 'bind').mockReturnValue(cleanup)
    const bindSecond = vi.spyOn(second, 'bind')
    const fixture = TestBed.createComponent(TargetHost)
    fixture.componentInstance.adapter.set(first)
    fixture.detectChanges()
    const node = (fixture.nativeElement as HTMLElement).querySelector('div')!
    fixture.componentInstance.adapter.set(second)
    fixture.detectChanges()
    expect(bindFirst).toHaveBeenCalledTimes(1)
    expect(cleanup).toHaveBeenCalledExactlyOnceWith()
    expect(bindSecond).toHaveBeenCalledExactlyOnceWith(
      'a',
      1,
      'wide',
      'main',
      node,
    )
    fixture.destroy()
  })

  it('binds tile main, detail and work as the tiles projection, keeping the card on departure', () => {
    const geometry = adapter()
    const cleanups = new Map<string, ReturnType<typeof vi.fn>>()
    const bind = vi
      .spyOn(geometry, 'bind')
      .mockImplementation((_rowKey, _occurrence, _projection, part) => {
        const cleanup = vi.fn()
        cleanups.set(part, cleanup)
        return cleanup
      })
    const fixture = TestBed.createComponent(TileHost)
    fixture.componentInstance.adapter.set(geometry)
    fixture.detectChanges()
    const host = fixture.nativeElement as HTMLElement
    const tile = host.querySelector<HTMLElement>('[data-box="tile"]')!
    expect(bind).toHaveBeenCalledTimes(3)
    expect(bind).toHaveBeenCalledWith('a', 1, 'tiles', 'main', tile)
    expect(bind).toHaveBeenCalledWith(
      'a',
      1,
      'tiles',
      'detail',
      host.querySelector('[data-box="detail"]'),
    )
    expect(bind).toHaveBeenCalledWith(
      'a',
      1,
      'tiles',
      'work',
      host.querySelector('[data-box="work"]'),
    )
    fixture.componentInstance.live.set(false)
    fixture.detectChanges()
    expect(cleanups.get('detail')).toHaveBeenCalledExactlyOnceWith()
    expect(cleanups.get('work')).toHaveBeenCalledExactlyOnceWith()
    expect(cleanups.get('main')).not.toHaveBeenCalled()
    expect(host.querySelector('[data-box="column"] > .card')).toBe(tile)
    expect(bind).toHaveBeenCalledTimes(3)
  })

  it('captures the whole image-and-body tile before its live subtree is destroyed', () => {
    const owner = new TableViewportGeometry()
    const geometry = adapter(owner)
    const boxes = new Map<string, DOMRect>([
      ['tile', new DOMRect(0, 0, 180, 300.5)],
      ['detail', new DOMRect(0, 170, 180, 40.25)],
      ['work', new DOMRect(0, 216.25, 180, 20.5)],
      ['reason', new DOMRect(0, 120, 180, 20)],
    ])
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
      function (this: HTMLElement) {
        return boxes.get(this.dataset.box ?? '') ?? new DOMRect(0, 0, 0, 0)
      },
    )
    const computedStyle = window.getComputedStyle.bind(window)
    vi.spyOn(window, 'getComputedStyle').mockImplementation(
      (element) =>
        new Proxy(computedStyle(element), {
          get(target, property) {
            // The surviving grid column carries the g-3 vertical gutter.
            if (
              property === 'marginTop' &&
              (element as HTMLElement).dataset.box === 'column'
            )
              return '16px'
            return Reflect.get(target, property)
          },
        }),
    )
    const fixture = TestBed.createComponent(TileHost)
    const host = fixture.nativeElement as HTMLElement
    document.body.append(host)
    fixture.componentInstance.adapter.set(geometry)
    fixture.componentInstance.occurrence.set(owner.record('a')!.occurrence)
    fixture.detectChanges()
    const table = host.querySelector('table')!
    geometry.commit(host, table, [])
    const occurrence = owner.record('a')!.occurrence
    const pixels = () => geometry.recordPixels('a', occurrence, 'tiles')
    expect(pixels()).toBeUndefined()
    owner.withPresentationUpdate({ kind: 'rows', rowKeys: ['a'] }, () => {
      owner.depart('a', 'deleted')
      fixture.componentInstance.live.set(false)
      fixture.detectChanges()
    })
    expect(host.querySelector('img')).toBeNull()
    expect(host.textContent).not.toMatch(/detail values|work payload/)
    geometry.commit(host, table, [])
    // Only inner body space is published; shell and gutter remain in layout.
    expect(pixels()).toBe(300.5)
    fixture.destroy()
  })
})
