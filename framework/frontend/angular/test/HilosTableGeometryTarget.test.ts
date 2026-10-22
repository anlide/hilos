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

const adapters: TableViewportGeometryAdapter[] = []
afterEach(() => {
  TestBed.resetTestingModule()
  for (const adapter of adapters.splice(0)) adapter.dispose()
})

function adapter() {
  const owner = new TableViewportGeometry()
  owner.replaceWindow(['a'])
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
})
