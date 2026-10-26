import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosDaemonPlacementView,
  readHilosDaemonOverview,
  type HilosDaemonContext,
  type HilosDaemonOverviewData,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { HilosAdminPage, mirrorHilosSignal } from '@hilos/angular/base'

import { HilosDaemonPlacement } from './HilosDaemonPlacement.js'

/** Agent placement with the project connection and page scope. */
@Component({
  selector: 'hilos-daemon-placement-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosDaemonPlacement],
  template: `
    <hilos-admin-page [page]="page" headKind="section" tabsLabel="Daemon pages">
      <hilos-daemon-placement
        [view]="placement()"
        [overview]="overview()"
      ></hilos-daemon-placement>
    </hilos-admin-page>
  `,
})
export class HilosDaemonPlacementPage {
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosDaemonContext>()
  protected readonly page = HilosPages.DAEMON_PLACEMENT
  protected readonly overviewSignal = computed(() =>
    readHilosDaemonOverview(this.context().scopes),
  )
  protected readonly placement = computed(() =>
    createHilosDaemonPlacementView(this.context(), this.overviewSignal()),
  )
  protected readonly overview = signal<HilosDaemonOverviewData | null>(null)

  constructor() {
    effect((onCleanup) => {
      const placement = this.placement()
      const stop = mirrorHilosSignal(this.overviewSignal(), this.overview)
      placement.start()
      onCleanup(() => {
        stop()
        placement.dispose()
      })
    })
  }
}
