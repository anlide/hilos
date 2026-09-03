// HilosLogsWorkersPage — the framework Hilos by-worker page
// (HilosPages.LOGS_WORKERS): the same stream list as the by-key page, but only the
// workers and with the one distinction that page folds away — the monopolistic worker
// against the ordinary ones. A row is one worker stream ON ONE NODE, so the node
// column, the node filter and the node in the footnote exist only where nodes have
// names. Search, the node filter and the All / Monopolistic only switch ride the open
// viewport filter map (server-side, no local filtering); the window is re-served by the
// page whenever the cluster picture moves. The screen commands nothing: the only way
// out of it is the Open button into the viewer (HIL-388). All table logic, the row
// view-model, the empty-state discrimination and the wording are the core headless's
// (hilosLogWorkers); this view owns only the markup, so a project mounts it by passing
// its HilosLogWorkersContext. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
  HILOS_LOG_WORKER_TYPE_OPTIONS,
  HilosPages,
  WORKER_BATCH_COUNT_FIELD,
  WORKER_BYTES_FIELD,
  WORKER_FILTER_NODE,
  WORKER_FILTER_TYPE,
  WORKER_NAME_FIELD,
  WORKER_NODE_FIELD,
  createHilosLogWorkersHeader,
  createHilosLogWorkersTable,
  formatLogWorkerState,
  formatLogWorkerType,
  formatLogWorkerWeight,
  hasLogWorkerNodes,
  logWorkerViewerPath,
  logWorkersEmptyState,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosLogWorkerRow,
  HilosLogWorkersContext,
  HilosLogWorkersHeader,
  HilosTableColumn,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** The framework by-worker page: the worker stream list and its type distinction. */
@Component({
  selector: 'hilos-logs-workers-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <p class="text-body-secondary">
        The same thing again, but only for the workers and with the distinction
        the by-key page deliberately loses: an ordinary worker or the
        monopolistic one.
      </p>

      <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
        @if (clustered()) {
          <div>
            <label class="form-label" for="hilos-log-worker-node">Node</label>
            <select
              id="hilos-log-worker-node"
              class="form-select"
              [value]="nodeFilter()"
              data-id="hilos-log-worker-node"
              (change)="onNode($event)"
            >
              <option value="">All nodes</option>
              @for (node of header()?.nodes ?? []; track node) {
                <option [value]="node">{{ node }}</option>
              }
            </select>
          </div>
        }
        <div
          class="btn-group btn-group-sm"
          role="group"
          aria-label="Worker type"
        >
          @for (option of typeOptions; track option.value) {
            <button
              type="button"
              class="btn btn-outline-secondary"
              [class.active]="typeFilter() === option.value"
              [attr.aria-pressed]="typeFilter() === option.value"
              [attr.data-id]="
                'hilos-log-worker-type-' + (option.value || 'all')
              "
              (click)="setType(option.value)"
            >
              {{ option.label }}
            </button>
          }
        </div>
      </div>

      <hilos-viewport-table
        label="Worker streams"
        [controller]="streams().controller"
        [columns]="columns()"
        [searchable]="true"
        searchPlaceholder="Search by key or node…"
      >
        <ng-template #row let-row>
          <td>
            <code class="fw-semibold small">{{ row.key }}</code>
            <!-- The sub-line carries whatever the hidden columns were carrying, so a
            narrow screen loses the layout and not the figures. It is there in a
            single-node installation too, where only the weight was hidden. -->
            <div class="small text-body-secondary d-lg-none">
              @if (clustered()) {
                {{ row.node }} ·
              }
              {{ formatWeight(row) }}
            </div>
          </td>
          @if (clustered()) {
            <td class="d-none d-lg-table-cell">{{ row.node }}</td>
          }
          <td>
            <span [class]="'badge ' + typeClass(row)">
              {{ formatType(row) }}
            </span>
          </td>
          <td>
            <span [class]="'badge ' + stateClass(row)">
              {{ formatState(row) }}
            </span>
          </td>
          <td class="text-end">{{ row.batchCount }}</td>
          <td class="text-end d-none d-lg-table-cell">
            {{ formatWeight(row) }}
          </td>
          <td class="text-end">
            <!-- A stream that is neither live nor archived has no file to open, and
            the headless answers with an empty address rather than a broken one. -->
            @if (viewerPath(row) !== '') {
              <a
                [hilosLink]="viewerPath(row)"
                class="btn btn-sm btn-outline-secondary text-nowrap"
                [attr.data-id]="'hilos-log-worker-open-' + row.rowKey"
              >
                Open
              </a>
            }
          </td>
        </ng-template>

        <ng-template #empty>
          @if (emptyState() === 'unknown') {
            <div data-id="hilos-log-worker-empty-unknown">
              <div class="fw-semibold">
                The cluster picture has not arrived yet
              </div>
              <p class="mb-0">
                Nobody has reported yet, so there are no figures — not zero of
                them.
              </p>
            </div>
          } @else if (emptyState() === 'unreadable') {
            <div data-id="hilos-log-worker-empty-unreadable">
              <div class="fw-semibold">The log directory cannot be read</div>
              <p class="mb-0">
                No node could read its log store. Check the log directory
                setting and the permissions on it.
              </p>
            </div>
          } @else if (emptyState() === 'nomatch') {
            <div data-id="hilos-log-worker-empty-nomatch">
              <div class="fw-semibold">Nothing matches</div>
              <p class="mb-2">There are worker streams — just not these.</p>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-id="hilos-log-worker-clear-filters"
                (click)="clearFilters()"
              >
                Clear the filters
              </button>
            </div>
          } @else {
            <div data-id="hilos-log-worker-empty-never">
              <div class="fw-semibold">Nothing has been logged yet</div>
              <p class="mb-0">
                No worker has written into this directory — an installation that
                has only just come up looks exactly like this.
              </p>
            </div>
          }
        </ng-template>
      </hilos-viewport-table>

      <div class="alert alert-secondary small py-3 mt-4 mb-0">
        <div class="fw-semibold mb-1">
          <i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Why a page of
          its own
        </div>
        @if (clustered()) {
          There is one monopolistic worker for the whole cluster and any number
          of ordinary ones, and they live on different machines. When the log
          has grown, the first question is whether all the workers grew or only
          the single one holding the shared work. The node column shows whose
          machine it is happening on.
        } @else {
          The monopolistic worker holds work that cannot be done by two hands at
          once. When the log has grown, the first question is whether the
          ordinary workers grew or that one did — they have different causes and
          different cures.
        }
      </div>
    </hilos-admin-page>
  `,
})
export class HilosLogsWorkersPage {
  /** The project context: scope stores and the connection. */
  readonly context = input.required<HilosLogWorkersContext>()

  protected readonly page = HilosPages.LOGS_WORKERS
  protected readonly typeOptions = HILOS_LOG_WORKER_TYPE_OPTIONS
  protected readonly formatState = formatLogWorkerState
  protected readonly formatType = formatLogWorkerType
  protected readonly formatWeight = formatLogWorkerWeight
  protected readonly viewerPath = logWorkerViewerPath

  protected readonly streams = computed(() =>
    createHilosLogWorkersTable(this.context()),
  )
  private readonly headerHandle = computed(() =>
    createHilosLogWorkersHeader(this.context()),
  )

  // The header and the window state, mirrored from the (per-context) core signals
  // into Angular signals so the template re-renders on every frame.
  protected readonly header = signal<HilosLogWorkersHeader | null>(null)
  private readonly rowCount = signal(0)
  private readonly search = signal('')

  // Domain filters: the node and the type ride the open filter map so the backend
  // narrows the window (no local filtering). Empty clears the filter.
  protected readonly nodeFilter = signal('')
  protected readonly typeFilter = signal('')

  // The node column, the node filter and the footnote's wording all follow the same
  // question: in a single-node installation a column repeating one name and a filter
  // offering one option would both be furniture for a choice that does not exist.
  protected readonly clustered = computed(() =>
    hasLogWorkerNodes(this.header()),
  )

  // The sortable keys are the exported wire constants, which is where a typo would
  // actually cost something — they travel to the backend as the sort field. The type
  // is not among them: the mockup draws no sort on its header, and a difference of two
  // steps does not read as an ordering — that is what the filter button is for.
  //
  // The node and weight columns drop out of the header below `lg`, where their values
  // move into the sub-line of the key cell: a narrow screen gets a shorter table
  // rather than one that scrolls sideways.
  protected readonly columns = computed<HilosTableColumn[]>(() => [
    { key: WORKER_NAME_FIELD, label: 'Key', sortable: true },
    ...(this.clustered()
      ? [
          {
            key: WORKER_NODE_FIELD,
            label: 'Node',
            sortable: true,
            headerClass: 'd-none d-lg-table-cell',
          },
        ]
      : []),
    { key: 'type', label: 'Type' },
    { key: 'state', label: 'State' },
    {
      key: WORKER_BATCH_COUNT_FIELD,
      label: 'Batches',
      sortable: true,
      headerClass: 'text-end',
    },
    {
      key: WORKER_BYTES_FIELD,
      label: 'Weight',
      sortable: true,
      headerClass: 'text-end d-none d-lg-table-cell',
    },
    { key: 'open', label: '' },
  ])

  // Which of the four empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  protected readonly emptyState = computed(() =>
    logWorkersEmptyState(
      this.header(),
      this.rowCount(),
      this.search() !== '' ||
        this.nodeFilter() !== '' ||
        this.typeFilter() !== '',
    ),
  )

  constructor() {
    // Bind the server-windowed table and start listening for the header once the
    // context input is bound; the header also arrives once as the answer to the
    // subscription. The same effect re-binds on a context swap and unbinds on destroy.
    effect((onCleanup) => {
      const streams = this.streams()
      const headerHandle = this.headerHandle()
      headerHandle.start()
      streams.start()
      this.header.set(headerHandle.header.get())
      this.rowCount.set(streams.controller.rows.get().length)
      this.search.set(streams.controller.search.get())
      const unsubscribes = [
        subscribeSignal(headerHandle.header, (next) => {
          this.header.set(next)
        }),
        subscribeSignal(streams.controller.rows, (next) => {
          this.rowCount.set(next.length)
        }),
        subscribeSignal(streams.controller.search, (next) => {
          this.search.set(next)
        }),
      ]
      onCleanup(() => {
        for (const unsubscribe of unsubscribes) {
          unsubscribe()
        }
        streams.dispose()
        headerHandle.dispose()
      })
    })
  }

  // The monopolistic worker is the one this screen was opened for, so its badge is the
  // one that carries color; the ordinary ones stay quiet.
  protected typeClass(row: HilosLogWorkerRow): string {
    return row.type === HILOS_LOG_WORKER_TYPE_MONOPOLISTIC
      ? 'text-bg-info-subtle text-info-emphasis border border-info-subtle'
      : 'text-bg-light border'
  }

  // A stream still being written is the live one; one left only in the archive is
  // quiet, and the two are told apart by weight of color rather than by wording alone.
  protected stateClass(row: HilosLogWorkerRow): string {
    return row.live ? 'text-bg-success' : 'text-bg-light border'
  }

  protected onNode(event: Event): void {
    this.setNode((event.target as HTMLSelectElement).value)
  }

  protected setType(value: string): void {
    this.typeFilter.set(value)
    this.streams().controller.setFilter(WORKER_FILTER_TYPE, value)
  }

  protected clearFilters(): void {
    this.streams().controller.setSearch('')
    this.setNode('')
    this.setType('')
  }

  private setNode(value: string): void {
    this.nodeFilter.set(value)
    this.streams().controller.setFilter(WORKER_FILTER_NODE, value)
  }
}
