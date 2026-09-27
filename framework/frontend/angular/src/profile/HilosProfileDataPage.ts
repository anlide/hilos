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
} from '@hilos/core'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { HilosDataExport } from './HilosDataExport.js'

/** The profile's personal-copy section, over the shared export flow. */
@Component({
  selector: 'hilos-profile-data-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosPageHeading, HilosDataExport],
  template: `<section data-id="profile-data-view">
    <hilos-page-heading />
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
