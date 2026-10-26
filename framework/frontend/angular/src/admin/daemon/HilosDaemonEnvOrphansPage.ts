import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  HilosViewportTable,
  HilosTableCell,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosDaemonNodeShell } from './HilosDaemonNodeShell.js'
import {
  createHilosDaemonEnvOrphansTable,
  readHilosDaemonEnvOrphansPage,
  formatDaemonEnvValue,
  formatDaemonEnvSimilarTo,
  DAEMON_ENV_KEY_FIELD,
  DAEMON_ENV_VALUE_FIELD,
  DAEMON_ENV_SIMILAR_TO_FIELD,
  type HilosDaemonEnvContext,
  type HilosDaemonEnvOrphansPageState,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { HilosDaemonEnvValue } from './HilosDaemonEnvValue.js'

/** Angular view of the Daemon environment orphans page. */
@Component({
  selector: 'hilos-daemon-env-orphans-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosViewportTable,
    HilosTableCell,
    HilosDaemonNodeShell,
    HilosDaemonEnvValue,
  ],
  template: `
    @let nodeSilent = this.nodeSilent();
    @let orphans = this.orphans();

    <hilos-daemon-node-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        <hilos-viewport-table [controller]="orphans.controller">
          <ng-template [hilosTableCell]="DAEMON_ENV_KEY_FIELD" let-row>
            <code class="fw-semibold">{{ row.key }}</code>
          </ng-template>

          <ng-template [hilosTableCell]="DAEMON_ENV_VALUE_FIELD" let-row>
            @if (!nodeSilent) {
              <span class="d-inline-flex align-items-center gap-2 flex-wrap">
                <hilos-daemon-env-value
                  [view]="formatDaemonEnvValue(row.value, 'string', true)"
                  [required]="row.required ?? false"
                ></hilos-daemon-env-value>
                <span
                  class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                >
                  not in catalog
                </span>
              </span>
            }
          </ng-template>

          <ng-template [hilosTableCell]="DAEMON_ENV_SIMILAR_TO_FIELD" let-row>
            <span class="small text-body-secondary">{{
              formatDaemonEnvSimilarTo(row)
            }}</span>
          </ng-template>

          <ng-template #empty>
            @if (nodeSilent) {
              <span data-id="hilos-daemon-env-orphans-empty-silent">
                Nothing is read while the node is silent.
              </span>
            } @else {
              <span data-id="hilos-daemon-env-orphans-empty">
                Every line in .env is declared in the catalog.
              </span>
            }
          </ng-template>
        </hilos-viewport-table>
      </ng-template>
    </hilos-daemon-node-shell>
  `,
})
export class HilosDaemonEnvOrphansPage {
  private readonly router = inject(HILOS_ROUTER)
  private readonly route = hilosSignal(this.router.currentRoute)
  protected readonly nodeId = computed(
    () => this.route().params['nodeId'] ?? '',
  )

  readonly context = input.required<HilosDaemonEnvContext>()
  protected readonly page = HilosPages.DAEMON_ENV_ORPHANS
  protected readonly environment = signal<HilosDaemonEnvOrphansPageState>({
    node: null,
    nodeSilent: false,
  })
  protected readonly nodeSilent = computed(() => this.environment().nodeSilent)
  protected readonly orphans = computed(() =>
    createHilosDaemonEnvOrphansTable(this.context(), this.nodeId()),
  )
  constructor() {
    effect((onCleanup) => {
      const orphans = this.orphans()
      const stop = mirrorHilosSignal(
        readHilosDaemonEnvOrphansPage(this.context().scopes),
        this.environment,
      )
      orphans.start()
      onCleanup(() => {
        stop()
        orphans.dispose()
      })
    })
  }
  protected readonly formatDaemonEnvValue = formatDaemonEnvValue
  protected readonly formatDaemonEnvSimilarTo = formatDaemonEnvSimilarTo
  protected readonly DAEMON_ENV_KEY_FIELD = DAEMON_ENV_KEY_FIELD
  protected readonly DAEMON_ENV_VALUE_FIELD = DAEMON_ENV_VALUE_FIELD
  protected readonly DAEMON_ENV_SIMILAR_TO_FIELD = DAEMON_ENV_SIMILAR_TO_FIELD
}
