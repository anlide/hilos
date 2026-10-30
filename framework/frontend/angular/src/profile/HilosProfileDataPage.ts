import {
  ChangeDetectionStrategy,
  Component,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosProfileDataExport,
  HILOS_DATA_EXPORT_COPY,
  type HilosDataExportContext,
} from '@hilos/core/base'
import { HilosProfileHead } from './HilosProfileHead.js'
import { HilosDataExport } from './HilosDataExport.js'

/** The profile's personal-copy section, over the shared export flow. */
@Component({
  selector: 'hilos-profile-data-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileHead, HilosDataExport],
  template: `<section data-id="profile-data-view">
    <hilos-profile-head [context]="context()" />
    @if (copy(); as current) {
      <hilos-data-export
        [store]="current.store"
        [flow]="current.flow"
        [lead]="lead"
        [titled]="false"
      />
    }
  </section>`,
})
export class HilosProfileDataPage {
  readonly context = input.required<HilosDataExportContext>()
  protected readonly lead = HILOS_DATA_EXPORT_COPY.sectionLead
  protected readonly copy = signal<ReturnType<
    typeof createHilosProfileDataExport
  > | null>(null)
  constructor() {
    effect((onCleanup) => {
      const next = createHilosProfileDataExport(this.context())
      next.store.start()
      this.copy.set(next)
      onCleanup(() => {
        next.flow.dispose()
        next.store.dispose()
      })
    })
  }
}
