// HilosAnalyticsPage — the Analytics section overview: three aggregates from
// the core's captured snapshot beneath the shared catalog head. Counts and
// periods stay together for this page opening. Bootstrap classes only
// (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  analyticsOverviewTiles,
  createHilosAnalyticsOverview,
  type HilosAnalyticsContext,
  type HilosAnalyticsOverviewSnapshot,
} from '@hilos/core/admin/analytics'
import { HilosPages } from '@hilos/core/base'

import { HilosAdminPage, mirrorHilosSignal } from '@hilos/angular/base'

/** The Analytics overview: week, day and hour visitors from the first valid answer. */
@Component({
  selector: 'hilos-analytics-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="section"
      tabsLabel="Analytics pages"
    >
      @if (tiles(); as tiles) {
        <div
          class="row row-cols-1 row-cols-sm-3 g-3 mb-4"
          data-id="hilos-analytics-overview-tiles"
        >
          @for (tile of tiles; track tile.key) {
            <div class="col">
              <dl
                class="border rounded-3 p-3 h-100 mb-0"
                [attr.data-id]="'hilos-analytics-visitors-' + tile.key"
              >
                <dt class="small text-body-secondary fw-normal">
                  {{ tile.label }}
                </dt>
                <dd
                  class="fs-4 fw-semibold lh-sm my-1"
                  [attr.data-id]="
                    'hilos-analytics-visitors-' + tile.key + '-count'
                  "
                >
                  {{ tile.count }}
                </dd>
                <dd
                  class="small text-body-secondary mb-0"
                  [attr.data-id]="
                    'hilos-analytics-visitors-' + tile.key + '-since'
                  "
                >
                  {{ tile.since }}
                </dd>
              </dl>
            </div>
          }
        </div>
      } @else {
        <div
          class="row row-cols-1 row-cols-sm-3 g-3 placeholder-glow"
          aria-hidden="true"
          data-id="hilos-analytics-overview-skeleton"
        >
          @for (key of ['week', 'day', 'hour']; track key) {
            <div class="col">
              <div class="border rounded-3 p-3 h-100">
                <span class="placeholder col-7 d-block mb-2 rounded"></span>
                <span
                  class="placeholder placeholder-lg col-3 d-block mb-2 rounded"
                ></span>
                <span class="placeholder col-9 d-block rounded"></span>
              </div>
            </div>
          }
        </div>
      }
    </hilos-admin-page>
  `,
})
export class HilosAnalyticsPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosAnalyticsContext>()

  /** The admin page key the shell draws the identity of. */
  protected readonly page = HilosPages.ANALYTICS
  private readonly scopes = computed(() => this.context().scopes)
  private readonly overview = computed(() =>
    createHilosAnalyticsOverview(this.scopes()),
  )
  private readonly snapshot = signal<
    HilosAnalyticsOverviewSnapshot | undefined
  >(undefined)
  protected readonly tiles = computed(() => {
    const snapshot = this.snapshot()
    return snapshot === undefined ? undefined : analyticsOverviewTiles(snapshot)
  })

  constructor() {
    effect((onCleanup) => {
      const overview = this.overview()
      overview.start()
      const unmirror = mirrorHilosSignal(overview.snapshot, this.snapshot)
      onCleanup(() => {
        unmirror()
        overview.dispose()
      })
    })
  }
}
