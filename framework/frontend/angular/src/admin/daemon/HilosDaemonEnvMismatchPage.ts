import { NgClass } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import { computedSignal, HilosPages, subscribeSignal } from '@hilos/core/base'
import {
  createHilosDaemonEnvMismatchTables,
  daemonEnvMismatchEmptyState,
  daemonEnvMismatchNodeColumnKey,
  daemonEnvMismatchNodes,
  DAEMON_ENV_SENSITIVE_HINT,
  DAEMON_ENV_MISMATCH_KEY_FIELD,
  readHilosDaemonEnvMismatchSummary,
  type HilosDaemonEnvMismatchContext,
  type HilosDaemonEnvMismatchSummary,
} from '@hilos/core/admin/daemon'
import {
  HilosAdminPage,
  HilosSkeleton,
  HilosViewportTable,
  HilosTableCell,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosDaemonEnvMismatchCell } from './HilosDaemonEnvMismatchCell.js'

/** Angular view of the Daemon EnvMismatchPage. */
@Component({
  selector: 'hilos-daemon-env-mismatch-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    NgClass,
    HilosAdminPage,
    HilosSkeleton,
    HilosViewportTable,
    HilosTableCell,
    HilosDaemonEnvMismatchCell,
  ],
  template: `@let summary = this.summary();
    @let tables = this.tables();
    @let nodes = this.nodes();
    @let comparison = this.comparison();
    @let emptyState = this.emptyState();

    <hilos-admin-page [page]="page" headKind="section" tabsLabel="Daemon pages">
      @if (summary !== null && !summary.clustered) {
        <div
          class="alert alert-secondary d-flex align-items-start gap-3 py-3"
          data-id="hilos-daemon-env-mismatch-standalone"
        >
          <i class="bi bi-slash-circle fs-4" aria-hidden="true"></i>
          <div>
            <div class="fw-semibold mb-1">
              This page does not exist on a single-node installation
            </div>
            <p class="small mb-0">
              There is one node,
              <code data-id="hilos-daemon-env-mismatch-standalone-node">{{
                summary.servingNodeId
              }}</code
              >, and nothing to compare it with.
            </p>
          </div>
        </div>
      } @else if (summary === null || comparison === null) {
        <div data-id="hilos-daemon-env-mismatch-waiting">
          <p class="text-body-secondary">Waiting for the cluster picture…</p>
          <hilos-skeleton></hilos-skeleton>
        </div>
      } @else {
        <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
          <div class="col">
            <div
              class="border rounded-3 p-3 h-100"
              [ngClass]="[
              comparison.answering.length &lt; nodes.length
                ? 'border-warning'
                : '',
            ]"
              data-id="hilos-daemon-env-mismatch-tile-answering"
              [attr.data-tone]="
              comparison.answering.length &lt; nodes.length ? 'warning' : ''
            "
            >
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-hdd-network" aria-hidden="true"></i
                ><span class="small">Nodes answering</span>
              </div>
              <div
                class="fs-5 lh-1 mb-1"
                [ngClass]="[
                comparison.answering.length &lt; nodes.length
                  ? 'text-warning'
                  : '',
              ]"
              >
                {{ comparison.answering.length }} of {{ nodes.length }}
              </div>
              <div class="small text-body-secondary">
                Silent nodes show as ?
              </div>
            </div>
          </div>

          <div class="col">
            <div
              class="border rounded-3 p-3 h-100"
              [ngClass]="[
              comparison.diverged &gt; 0 ? 'border-danger' : 'border-success',
            ]"
              data-id="hilos-daemon-env-mismatch-tile-diverged"
              [attr.data-tone]="comparison.diverged &gt; 0 ? 'danger' : 'success'"
            >
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-exclamation-octagon" aria-hidden="true"></i
                ><span class="small">Diverged</span>
              </div>
              <div
                class="fs-5 lh-1 mb-1"
                [ngClass]="[
                comparison.diverged &gt; 0 ? 'text-danger' : 'text-success',
              ]"
              >
                {{ comparison.diverged }}
              </div>
              <div class="small text-body-secondary">
                Nodes report different values
              </div>
            </div>
          </div>

          <div class="col">
            <div
              class="border rounded-3 p-3 h-100"
              [ngClass]="[
              comparison.sourcesDiffer &gt; 0
                ? 'border-warning'
                : 'border-success',
            ]"
              data-id="hilos-daemon-env-mismatch-tile-sources"
              [attr.data-tone]="comparison.sourcesDiffer &gt; 0 ? 'warning' : 'success'"
            >
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-node-plus" aria-hidden="true"></i
                ><span class="small">Different sources</span>
              </div>
              <div
                class="fs-5 lh-1 mb-1"
                [ngClass]="[
                comparison.sourcesDiffer &gt; 0 ? 'text-warning' : 'text-success',
              ]"
              >
                {{ comparison.sourcesDiffer }}
              </div>
              <div class="small text-body-secondary">
                Same value from different sources
              </div>
            </div>
          </div>

          <div class="col">
            <div
              class="border rounded-3 p-3 h-100"
              data-id="hilos-daemon-env-mismatch-tile-per-node"
              data-tone=""
            >
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-check-circle" aria-hidden="true"></i
                ><span class="small">Differ by design</span>
              </div>
              <div class="fs-5 lh-1 mb-1">
                {{ comparison.perNode.length }}
              </div>
              <div class="small text-body-secondary">
                Each node has its own; that is normal
              </div>
            </div>
          </div>
        </div>

        <hilos-viewport-table [controller]="tables.diverged">
          <ng-template [hilosTableCell]="DAEMON_ENV_MISMATCH_KEY_FIELD" let-row>
            <code class="fw-semibold">{{ row.key }}</code>
            @if (row.sensitive) {
              <i
                class="bi bi-shield-lock text-body-secondary ms-1"
                [title]="DAEMON_ENV_SENSITIVE_HINT"
                aria-hidden="true"
              ></i>
              <span class="visually-hidden">{{
                DAEMON_ENV_SENSITIVE_HINT
              }}</span>
            }
          </ng-template>
          @for (node of nodes; track node.nodeId) {
            <ng-template
              [hilosTableCell]="daemonEnvMismatchNodeColumnKey(node.nodeId)"
              let-row
            >
              <hilos-daemon-env-mismatch-cell
                [row]="row"
                [nodeId]="node.nodeId"
                [servingNodeId]="summary.servingNodeId"
                [valuesMatch]="false"
              ></hilos-daemon-env-mismatch-cell>
            </ng-template>
          }
          <ng-template #empty>
            @if (emptyState === 'alone') {
              <div data-id="hilos-daemon-env-mismatch-empty-alone">
                <div class="fw-semibold">
                  Fewer than two nodes answer, so there is nothing to compare
                  yet.
                </div>
              </div>
            } @else if (emptyState === 'agree') {
              <div data-id="hilos-daemon-env-mismatch-empty-agree">
                <div class="fw-semibold">
                  The answering nodes agree on every key they share.
                </div>
              </div>
            }
          </ng-template>
        </hilos-viewport-table>

        @if (comparison.sourcesDiffer > 0) {
          <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
            <h2 class="h6 text-uppercase text-body-secondary mb-0">
              Values match, sources differ
            </h2>
          </div>
          <hilos-viewport-table [controller]="tables.sourcesDiffer">
            <ng-template
              [hilosTableCell]="DAEMON_ENV_MISMATCH_KEY_FIELD"
              let-row
            >
              <code class="fw-semibold">{{ row.key }}</code>
              @if (row.sensitive) {
                <i
                  class="bi bi-shield-lock text-body-secondary ms-1"
                  [title]="DAEMON_ENV_SENSITIVE_HINT"
                  aria-hidden="true"
                ></i>
                <span class="visually-hidden">{{
                  DAEMON_ENV_SENSITIVE_HINT
                }}</span>
              }
            </ng-template>
            @for (node of nodes; track node.nodeId) {
              <ng-template
                [hilosTableCell]="daemonEnvMismatchNodeColumnKey(node.nodeId)"
                let-row
              >
                <hilos-daemon-env-mismatch-cell
                  [row]="row"
                  [nodeId]="node.nodeId"
                  [servingNodeId]="summary.servingNodeId"
                  [valuesMatch]="true"
                ></hilos-daemon-env-mismatch-cell>
              </ng-template>
            }
          </hilos-viewport-table>
        }

        @if (comparison.perNode.length > 0) {
          <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
            <h2 class="h6 text-uppercase text-body-secondary mb-0">
              Differ by design
            </h2>
          </div>
          <div
            class="d-flex flex-wrap gap-1 mb-3"
            data-id="hilos-daemon-env-mismatch-per-node"
          >
            @for (key of comparison.perNode; track key) {
              <span
                class="badge bg-body-tertiary text-body border font-monospace"
                [attr.data-id]="'hilos-daemon-env-mismatch-per-node-' + key"
              >
                {{ key }}
              </span>
            }
          </div>
        }
      }
    </hilos-admin-page> `,
})
export class HilosDaemonEnvMismatchPage {
  readonly context = input.required<HilosDaemonEnvMismatchContext>()
  protected readonly page = HilosPages.DAEMON_ENV_MISMATCH
  protected readonly DAEMON_ENV_SENSITIVE_HINT = DAEMON_ENV_SENSITIVE_HINT
  protected readonly DAEMON_ENV_MISMATCH_KEY_FIELD =
    DAEMON_ENV_MISMATCH_KEY_FIELD
  protected readonly daemonEnvMismatchNodeColumnKey =
    daemonEnvMismatchNodeColumnKey
  protected readonly summary = signal<HilosDaemonEnvMismatchSummary | null>(
    null,
  )
  private readonly source = computed(() =>
    readHilosDaemonEnvMismatchSummary(this.context().scopes),
  )
  protected readonly tables = computed(() => {
    const source = this.source()
    return createHilosDaemonEnvMismatchTables(
      this.context(),
      computedSignal(() => daemonEnvMismatchNodes(source.get())),
    )
  })
  protected readonly nodes = computed(() =>
    daemonEnvMismatchNodes(this.summary()),
  )
  protected readonly comparison = computed(
    () => this.summary()?.comparison ?? null,
  )
  protected readonly emptyState = computed(() => {
    const comparison = this.comparison()
    return comparison !== null ? daemonEnvMismatchEmptyState(comparison) : null
  })
  constructor() {
    effect((onCleanup) => {
      const source = this.source()
      const tables = this.tables()
      let started = false
      const ensureStarted = () => {
        if (!started && source.get()?.clustered === true) {
          started = true
          tables.start()
        }
      }
      const stops = [
        mirrorHilosSignal(source, this.summary),
        subscribeSignal(source, ensureStarted),
      ]
      ensureStarted()
      onCleanup(() => {
        stops.forEach((stop) => stop())
        tables.dispose()
      })
    })
  }
}
