import { Directive, ElementRef, effect, inject, input } from '@angular/core'
import type { TableProjection } from '@hilos/core/base'
import type { TableViewportGeometryAdapter } from './tableViewportGeometry.js'

/** Internal real-node binding, scoped to one adapter and owner-issued occurrence. */
@Directive({ selector: '[hilosTableGeometryTarget]' })
export class HilosTableGeometryTarget {
  readonly hilosTableGeometryTarget =
    input<TableViewportGeometryAdapter | null>(null)
  readonly geometryRowKey = input.required<string>()
  readonly geometryOccurrence = input.required<number>()
  readonly geometryProjection = input.required<TableProjection>()
  readonly geometryPart = input.required<'main' | 'work' | 'detail'>()
  private readonly element = inject<ElementRef<HTMLElement>>(ElementRef)

  constructor() {
    effect((onCleanup) => {
      const adapter = this.hilosTableGeometryTarget()
      const rowKey = this.geometryRowKey()
      const occurrence = this.geometryOccurrence()
      const projection = this.geometryProjection()
      const part = this.geometryPart()
      if (adapter)
        onCleanup(
          adapter.bind(
            rowKey,
            occurrence,
            projection,
            part,
            this.element.nativeElement,
          ),
        )
    })
  }
}
