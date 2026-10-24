import { NgClass } from '@angular/common'
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
  createHilosDaemonEnvKeysTable,
  createHilosDaemonEnvOrphansTable,
  daemonEnvSummary,
  readHilosDaemonEnvPage,
  formatDaemonEnvValue,
  daemonEnvSourceBadge,
  formatDaemonEnvDeclaredBy,
  formatDaemonEnvDrift,
  formatDaemonEnvDriftNotice,
  formatDaemonEnvRequired,
  formatDaemonEnvSimilarTo,
  DAEMON_ENV_FILTER_ATTENTION,
  DAEMON_ENV_LADDER,
  DAEMON_ENV_PER_NODE_HINT,
  DAEMON_ENV_SENSITIVE_HINT,
  DAEMON_ENV_KEY_FIELD,
  DAEMON_ENV_TYPE_FIELD,
  DAEMON_ENV_VALUE_FIELD,
  DAEMON_ENV_REQUIRED_FIELD,
  DAEMON_ENV_DECLARED_BY_FIELD,
  DAEMON_ENV_SIMILAR_TO_FIELD,
  type HilosDaemonEnvContext,
  type HilosDaemonEnvPageState,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { HilosDaemonEnvValue } from './HilosDaemonEnvValue.js'
import { HilosDaemonEnvSourceBadge } from './HilosDaemonEnvSourceBadge.js'

/** Angular view of the Daemon EnvPage. */
@Component({
  selector: 'hilos-daemon-env-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    NgClass,
    HilosViewportTable,
    HilosTableCell,
    HilosDaemonNodeShell,
    HilosDaemonEnvValue,
    HilosDaemonEnvSourceBadge,
  ],
  template: `
    @let nodeSilent = this.nodeSilent();
    @let summary = this.summary();
    @let keys = this.keys();
    @let orphans = this.orphans();
    @let keysFilter = this.keysFilter();

    <hilos-daemon-node-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        <div class="hilos-stack mb-3">
          <p
            class="small text-body-secondary mb-0 invisible"
            aria-hidden="true"
          >
            This node is silent. Only the node itself reads its environment, so
            nothing is shown until it answers again.
          </p>
          @if (nodeSilent) {
            <p
              class="small text-body-secondary mb-0"
              data-id="hilos-daemon-env-silent"
            >
              This node is silent. Only the node itself reads its environment,
              so nothing is shown until it answers again.
            </p>
          }
        </div>

        @if (!nodeSilent && summary !== null) {
          <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
            <div class="col">
              <div class="border rounded-3 p-3 h-100">
                <div
                  class="d-flex align-items-center gap-2 text-body-secondary mb-1"
                >
                  <i class="bi bi-list-ul" aria-hidden="true"></i
                  ><span class="small">Keys in catalog</span>
                </div>
                <div
                  class="fs-5 lh-1 mb-1 text-break"
                  data-id="hilos-daemon-env-keys"
                >
                  {{ summary.keyCount }}
                </div>
                <div class="small text-body-secondary">
                  Declared by the framework and the project
                </div>
              </div>
            </div>
            <div class="col">
              <div
                class="border rounded-3 p-3 h-100"
                [ngClass]="{ 'border-danger': summary.missingRequired.length &gt; 0 }"
              >
                <div
                  class="d-flex align-items-center gap-2 text-body-secondary mb-1"
                >
                  <i class="bi bi-exclamation-octagon" aria-hidden="true"></i
                  ><span class="small">Required without a value</span>
                </div>
                <div
                  class="fs-5 lh-1 mb-1 text-break"
                  [ngClass]="{ 'text-danger': summary.missingRequired.length &gt; 0 }"
                  data-id="hilos-daemon-env-missing"
                >
                  {{ summary.missingRequired.length }}
                </div>
                <div class="small text-body-secondary">
                  {{
                    summary.missingRequired.length > 0
                      ? 'The node will not start with this set'
                      : 'All present'
                  }}
                </div>
              </div>
            </div>
            <div class="col">
              <div
                class="border rounded-3 p-3 h-100"
                [ngClass]="{ 'border-warning': summary.fromExample.length &gt; 0 }"
              >
                <div
                  class="d-flex align-items-center gap-2 text-body-secondary mb-1"
                >
                  <i class="bi bi-file-earmark-text" aria-hidden="true"></i
                  ><span class="small">From the sample</span>
                </div>
                <div
                  class="fs-5 lh-1 mb-1 text-break"
                  [ngClass]="{ 'text-warning': summary.fromExample.length &gt; 0 }"
                  data-id="hilos-daemon-env-example"
                >
                  {{ summary.fromExample.length }}
                </div>
                <div class="small text-body-secondary">
                  {{
                    summary.fromExample.length > 0
                      ? '.env.example instead of a setting'
                      : 'The sample is not read'
                  }}
                </div>
              </div>
            </div>
            <div class="col">
              <div
                class="border rounded-3 p-3 h-100"
                [ngClass]="{ 'border-warning': summary.drifted.length &gt; 0 }"
              >
                <div
                  class="d-flex align-items-center gap-2 text-body-secondary mb-1"
                >
                  <i class="bi bi-arrow-repeat" aria-hidden="true"></i
                  ><span class="small">Drifted from disk</span>
                </div>
                <div
                  class="fs-5 lh-1 mb-1 text-break"
                  [ngClass]="{ 'text-warning': summary.drifted.length &gt; 0 }"
                  data-id="hilos-daemon-env-drift"
                >
                  {{ summary.drifted.length }}
                </div>
                <div class="small text-body-secondary">
                  {{
                    summary.drifted.length > 0
                      ? 'The file was edited, the process was not restarted'
                      : 'The file and the process agree'
                  }}
                </div>
              </div>
            </div>
          </div>
        }

        @if (!nodeSilent && summary !== null) {
          @if (summary.missingRequired.length > 0) {
            <div
              class="alert alert-danger d-flex align-items-start gap-3 py-2 small"
              data-id="hilos-daemon-env-alarm-missing"
            >
              <i
                class="bi bi-exclamation-octagon fs-5 flex-shrink-0"
                aria-hidden="true"
              ></i>
              <div>
                @for (
                  k of summary.missingRequired;
                  track k;
                  let index = $index
                ) {
                  <code>{{ k }}</code>
                  @if (index < summary.missingRequired.length - 1) {
                    ,
                  }
                }
                — the catalog
                {{
                  summary.missingRequired.length > 1
                    ? 'declares them required, and they have no value'
                    : 'declares it required, and it has no value'
                }}
                in the launch environment or in the files. The node's next start
                — a planned restart, a move, a crash — will be refused.
              </div>
            </div>
          }

          @if (summary.fromExample.length > 0) {
            <div
              class="alert alert-warning d-flex align-items-start gap-3 py-2 small"
              data-id="hilos-daemon-env-alarm-example"
            >
              <i
                class="bi bi-file-earmark-text fs-5 flex-shrink-0"
                aria-hidden="true"
              ></i>
              <div>
                {{
                  summary.fromExample.length > 1
                    ? 'The values of'
                    : 'The value of'
                }}
                @for (k of summary.fromExample; track k; let index = $index) {
                  <code>{{ k }}</code>
                  @if (index < summary.fromExample.length - 1) {
                    ,
                  }
                }
                came from <code>.env.example</code>.
              </div>
            </div>
          }

          @for (row of summary.drifted; track row.key) {
            @if (formatDaemonEnvDriftNotice(row) !== null) {
              <div
                class="alert alert-warning d-flex align-items-start gap-3 py-2 small"
                [attr.data-id]="'hilos-daemon-env-alarm-drift-' + row.key"
              >
                <i
                  class="bi bi-arrow-repeat fs-5 flex-shrink-0"
                  aria-hidden="true"
                ></i>
                <div>
                  In the process <code>{{ row.key }}</code> is
                  <code>{{ formatDaemonEnvDriftNotice(row)?.process }}</code
                  >; on disk it is already
                  <code>{{ formatDaemonEnvDriftNotice(row)?.disk }}</code
                  >. A live daemon does not pick up a file edit: it applies when
                  the node restarts.
                </div>
              </div>
            }
          }
        }

        <hilos-viewport-table [controller]="keys.controller">
          <ng-template [hilosTableCell]="DAEMON_ENV_KEY_FIELD" let-row>
            <code class="fw-semibold">{{ row.key }}</code>
            @if (row.perNode) {
              <i
                class="bi bi-hdd-network text-body-secondary ms-1"
                [title]="DAEMON_ENV_PER_NODE_HINT"
                aria-hidden="true"
              ></i>
              <span class="visually-hidden">{{
                DAEMON_ENV_PER_NODE_HINT
              }}</span>
            }
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

          <ng-template [hilosTableCell]="DAEMON_ENV_TYPE_FIELD" let-row>
            <span class="small text-body-secondary">{{ row.type }}</span>
          </ng-template>

          <ng-template [hilosTableCell]="DAEMON_ENV_VALUE_FIELD" let-row>
            @if (!nodeSilent) {
              <span class="d-inline-flex align-items-center gap-2 flex-wrap">
                <hilos-daemon-env-value
                  [view]="
                    formatDaemonEnvValue(row.value, row.type, row.sensitive)
                  "
                  [required]="row.required ?? false"
                ></hilos-daemon-env-value>
                <hilos-daemon-env-source-badge
                  [source]="row.source"
                ></hilos-daemon-env-source-badge>
                @if (formatDaemonEnvDrift(row) !== null) {
                  <span
                    class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                  >
                    {{ formatDaemonEnvDrift(row) }}
                  </span>
                }
              </span>
            }
          </ng-template>

          <ng-template [hilosTableCell]="DAEMON_ENV_REQUIRED_FIELD" let-row>
            @if (row.required) {
              <i
                class="bi bi-check-lg text-body-secondary"
                aria-hidden="true"
              ></i>
              <span class="visually-hidden">{{
                formatDaemonEnvRequired(row)
              }}</span>
            } @else {
              <span class="text-body-secondary" aria-hidden="true">·</span>
              <span class="visually-hidden">Optional</span>
            }
          </ng-template>

          <ng-template [hilosTableCell]="DAEMON_ENV_DECLARED_BY_FIELD" let-row>
            <span class="small text-body-secondary">{{
              formatDaemonEnvDeclaredBy(row)
            }}</span>
          </ng-template>

          <ng-template #empty>
            @if (nodeSilent) {
              <span data-id="hilos-daemon-env-keys-empty-silent">
                Nothing is read while the node is silent.
              </span>
            } @else if (keysFilter[DAEMON_ENV_FILTER_ATTENTION] === true) {
              <span data-id="hilos-daemon-env-keys-empty-attention">
                No key needs attention.
              </span>
            } @else {
              No rows.
            }
          </ng-template>
        </hilos-viewport-table>

        <h2
          id="hilos-daemon-env-ladder-heading"
          class="h6 text-uppercase text-body-secondary mb-2 mt-4"
        >
          Reading ladder
        </h2>
        <div
          class="border rounded-3 overflow-hidden mb-3"
          data-id="hilos-daemon-env-ladder"
        >
          <div class="table-responsive">
            <table
              class="table table-sm align-middle mb-0"
              aria-labelledby="hilos-daemon-env-ladder-heading"
            >
              <tbody>
                @for (
                  source of DAEMON_ENV_LADDER;
                  track source;
                  let ix = $index
                ) {
                  <tr>
                    <td
                      class="text-center text-body-secondary small text-nowrap px-3"
                    >
                      {{ source === 'missing' ? '' : ix + 1 }}
                    </td>
                    <td class="text-nowrap">
                      <hilos-daemon-env-source-badge
                        [source]="source"
                      ></hilos-daemon-env-source-badge>
                    </td>
                    <td class="small text-body-secondary text-break w-100">
                      {{ daemonEnvSourceBadge(source).hint }}
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </div>

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
export class HilosDaemonEnvPage {
  private readonly router = inject(HILOS_ROUTER)
  private readonly route = hilosSignal(this.router.currentRoute)
  protected readonly nodeId = computed(
    () => this.route().params['nodeId'] ?? '',
  )
  protected readonly now = Date.now

  readonly context = input.required<HilosDaemonEnvContext>()
  protected readonly page = HilosPages.DAEMON_ENV
  protected readonly environment = signal<HilosDaemonEnvPageState>({
    node: null,
    environment: null,
    nodeSilent: false,
  })
  protected readonly nodeSilent = computed(() => this.environment().nodeSilent)
  protected readonly summary = computed(() => {
    const environment = this.environment().environment
    return environment ? daemonEnvSummary(environment.keys) : null
  })
  protected readonly keys = computed(() =>
    createHilosDaemonEnvKeysTable(this.context(), this.nodeId()),
  )
  protected readonly orphans = computed(() =>
    createHilosDaemonEnvOrphansTable(this.context(), this.nodeId()),
  )
  protected readonly keysFilter = signal<Record<string, unknown>>({})
  constructor() {
    effect((onCleanup) => {
      const keys = this.keys()
      const orphans = this.orphans()
      const stops = [
        mirrorHilosSignal(
          readHilosDaemonEnvPage(this.context().scopes),
          this.environment,
        ),
        mirrorHilosSignal(keys.controller.filter, this.keysFilter),
      ]
      keys.start()
      orphans.start()
      onCleanup(() => {
        stops.forEach((stop) => stop())
        keys.dispose()
        orphans.dispose()
      })
    })
  }
  protected readonly formatDaemonEnvValue = formatDaemonEnvValue
  protected readonly daemonEnvSourceBadge = daemonEnvSourceBadge
  protected readonly formatDaemonEnvDeclaredBy = formatDaemonEnvDeclaredBy
  protected readonly formatDaemonEnvDrift = formatDaemonEnvDrift
  protected readonly formatDaemonEnvDriftNotice = formatDaemonEnvDriftNotice
  protected readonly formatDaemonEnvRequired = formatDaemonEnvRequired
  protected readonly formatDaemonEnvSimilarTo = formatDaemonEnvSimilarTo
  protected readonly DAEMON_ENV_FILTER_ATTENTION = DAEMON_ENV_FILTER_ATTENTION
  protected readonly DAEMON_ENV_LADDER = DAEMON_ENV_LADDER
  protected readonly DAEMON_ENV_PER_NODE_HINT = DAEMON_ENV_PER_NODE_HINT
  protected readonly DAEMON_ENV_SENSITIVE_HINT = DAEMON_ENV_SENSITIVE_HINT
  protected readonly DAEMON_ENV_KEY_FIELD = DAEMON_ENV_KEY_FIELD
  protected readonly DAEMON_ENV_TYPE_FIELD = DAEMON_ENV_TYPE_FIELD
  protected readonly DAEMON_ENV_VALUE_FIELD = DAEMON_ENV_VALUE_FIELD
  protected readonly DAEMON_ENV_REQUIRED_FIELD = DAEMON_ENV_REQUIRED_FIELD
  protected readonly DAEMON_ENV_DECLARED_BY_FIELD = DAEMON_ENV_DECLARED_BY_FIELD
  protected readonly DAEMON_ENV_SIMILAR_TO_FIELD = DAEMON_ENV_SIMILAR_TO_FIELD
}
