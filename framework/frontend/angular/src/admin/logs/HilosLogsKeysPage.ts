// HilosLogsKeysPage — the framework Hilos by-key page (HilosPages.LOGS_KEYS):
// which log streams the installation has, what each of them weighs, and how fast it
// grows. A key is a file name that survives rotation, and a row is one key ON ONE
// NODE — the same worker-0.log on two machines is two files, carried off apart — so
// the node column and the node filter exist only where nodes have names. Search, the
// node filter and the All / Agents / Workers switch ride the open viewport filter map
// (server-side, no local filtering); the window is re-served by the page whenever the
// cluster picture moves. The screen commands nothing: the only way out of it is the
// Open button into the viewer (HIL-388). The monopolistic workers are folded in with
// the ordinary ones here, and the daemon's own streams are not in the list at all.
// All table logic, the row view-model, the empty-state discrimination and the wording
// are the core headless's (hilosLogKeys); this view owns only the markup, so a project
// mounts it by passing its HilosLogKeysContext. Bootstrap classes only
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
  HILOS_LOG_CLASS_OPTIONS,
  HILOS_PAGE_ROUTES,
  HilosPages,
  KEY_BATCH_COUNT_FIELD,
  KEY_BYTES_FIELD,
  KEY_FILTER_CLASS,
  KEY_FILTER_NODE,
  KEY_GROWTH_PER_DAY_FIELD,
  KEY_NAME_FIELD,
  KEY_NODE_FIELD,
  createHilosLogKeysHeader,
  createHilosLogKeysTable,
  formatLogKeyClass,
  formatLogKeyGrowth,
  formatLogKeyState,
  formatLogKeyWeight,
  hasLogKeyNodes,
  logKeyViewerPath,
  logKeysEmptyState,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosLogKeyRow,
  HilosLogKeysContext,
  HilosLogKeysHeader,
  HilosTableColumn,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** The framework by-key page: the stream list with its filters and empty states. */
@Component({
  selector: 'hilos-logs-keys-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <p class="text-body-secondary">
        A key is the file name that survives rotation: the same stream goes on
        being written under that name into the next batch.
        @if (clustered()) {
          A row here is a key <em>on a node</em>: the same
          <code>worker-0.log</code> on two nodes is two files, carried off
          apart.
        }
      </p>

      <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
        @if (clustered()) {
          <div>
            <label class="form-label" for="hilos-log-key-node">Node</label>
            <select
              id="hilos-log-key-node"
              class="form-select"
              [value]="nodeFilter()"
              data-id="hilos-log-key-node"
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
          aria-label="Stream class"
        >
          @for (option of classOptions; track option.value) {
            <button
              type="button"
              class="btn btn-outline-secondary"
              [class.active]="classFilter() === option.value"
              [attr.aria-pressed]="classFilter() === option.value"
              [attr.data-id]="'hilos-log-key-class-' + (option.value || 'all')"
              (click)="setClass(option.value)"
            >
              {{ option.label }}
            </button>
          }
        </div>
      </div>

      <hilos-viewport-table
        label="Log streams"
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
            single-node installation too, where only the weight and the growth were
            hidden. -->
            <div class="small text-body-secondary d-lg-none">
              @if (clustered()) {
                {{ row.node }} ·
              }
              {{ formatWeight(row) }} · {{ formatGrowth(row) }}
            </div>
          </td>
          @if (clustered()) {
            <td class="d-none d-lg-table-cell">{{ row.node }}</td>
          }
          <td>
            <span class="badge text-bg-light border">
              {{ formatClass(row) }}
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
          <td class="text-end d-none d-lg-table-cell">
            {{ formatGrowth(row) }}
          </td>
          <td class="text-end">
            <!-- A stream that is neither live nor archived has no file to open, and
            the headless answers with an empty address rather than a broken one. -->
            @if (viewerPath(row) !== '') {
              <a
                [hilosLink]="viewerPath(row)"
                class="btn btn-sm btn-outline-secondary text-nowrap"
                [attr.data-id]="'hilos-log-key-open-' + row.rowKey"
              >
                Open
              </a>
            }
          </td>
        </ng-template>

        <ng-template #empty>
          @if (emptyState() === 'unknown') {
            <div data-id="hilos-log-key-empty-unknown">
              <div class="fw-semibold">
                The cluster picture has not arrived yet
              </div>
              <p class="mb-0">
                Nobody has reported yet, so there are no figures — not zero of
                them.
              </p>
            </div>
          } @else if (emptyState() === 'unreadable') {
            <div data-id="hilos-log-key-empty-unreadable">
              <div class="fw-semibold">The log directory cannot be read</div>
              <p class="mb-0">
                No node could read its log store. Check the log directory
                setting and the permissions on it.
              </p>
            </div>
          } @else if (emptyState() === 'nomatch') {
            <div data-id="hilos-log-key-empty-nomatch">
              <div class="fw-semibold">Nothing matches</div>
              <p class="mb-2">There are streams — just not these.</p>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-id="hilos-log-key-clear-filters"
                (click)="clearFilters()"
              >
                Clear the filters
              </button>
            </div>
          } @else {
            <div data-id="hilos-log-key-empty-never">
              <div class="fw-semibold">Nothing has been logged yet</div>
              <p class="mb-0">
                The daemon has not written into this directory — an installation
                that has only just come up looks exactly like this.
              </p>
            </div>
          }
        </ng-template>
      </hilos-viewport-table>

      <p class="small text-body-secondary mt-3 mb-0">
        The weight answers "how much is taken", the growth answers "when the
        room runs out"; a stream that is no longer written has no growth.
        Monopolistic workers are folded in with the ordinary ones here — the
        split is shown by
        <a [hilosLink]="workersPath" data-id="hilos-log-key-workers-link">
          the workers page </a
        >. Search and sorting go to the server: while it counts, the table is
        busy rather than showing the old order as the new one.
      </p>
    </hilos-admin-page>
  `,
})
export class HilosLogsKeysPage {
  /** The project context: scope stores and the connection. */
  readonly context = input.required<HilosLogKeysContext>()

  protected readonly page = HilosPages.LOGS_KEYS
  protected readonly classOptions = HILOS_LOG_CLASS_OPTIONS
  protected readonly formatClass = formatLogKeyClass
  protected readonly formatGrowth = formatLogKeyGrowth
  protected readonly formatState = formatLogKeyState
  protected readonly formatWeight = formatLogKeyWeight
  protected readonly viewerPath = logKeyViewerPath

  // Where the split this page folds away is actually shown. HIL-385 left the phrase
  // as plain text because the by-worker page was still a stub; it is a screen now.
  protected readonly workersPath = HILOS_PAGE_ROUTES[HilosPages.LOGS_WORKERS]

  protected readonly streams = computed(() =>
    createHilosLogKeysTable(this.context()),
  )
  private readonly headerHandle = computed(() =>
    createHilosLogKeysHeader(this.context()),
  )

  // The header and the window state, mirrored from the (per-context) core signals
  // into Angular signals so the template re-renders on every frame.
  protected readonly header = signal<HilosLogKeysHeader | null>(null)
  private readonly rowCount = signal(0)
  private readonly search = signal('')

  // Domain filters: the node and the class ride the open filter map so the backend
  // narrows the window (no local filtering). Empty clears the filter.
  protected readonly nodeFilter = signal('')
  protected readonly classFilter = signal('')

  // The node column and the node filter exist only where nodes have names: in a
  // single-node installation a column repeating one name and a filter offering one
  // option would both be furniture for a choice that does not exist.
  protected readonly clustered = computed(() => hasLogKeyNodes(this.header()))

  // The sortable keys are the exported wire constants, which is where a typo would
  // actually cost something — they travel to the backend as the sort field. The
  // growth sorts under its own displayed name: the backend maps that name onto the
  // integer it orders by, so a stream nothing is known about sinks to the bottom of a
  // descending sort rather than opening it.
  //
  // The node, weight and growth columns drop out of the header below `lg`, where their
  // values move into the sub-line of the key cell: a narrow screen gets a shorter
  // table rather than one that scrolls sideways.
  protected readonly columns = computed<HilosTableColumn[]>(() => [
    { key: KEY_NAME_FIELD, label: 'Key', sortable: true },
    ...(this.clustered()
      ? [
          {
            key: KEY_NODE_FIELD,
            label: 'Node',
            sortable: true,
            headerClass: 'd-none d-lg-table-cell',
          },
        ]
      : []),
    { key: 'class', label: 'Class' },
    { key: 'state', label: 'State' },
    {
      key: KEY_BATCH_COUNT_FIELD,
      label: 'Batches',
      sortable: true,
      headerClass: 'text-end',
    },
    {
      key: KEY_BYTES_FIELD,
      label: 'Weight',
      sortable: true,
      headerClass: 'text-end d-none d-lg-table-cell',
    },
    {
      key: KEY_GROWTH_PER_DAY_FIELD,
      label: 'Per day',
      sortable: true,
      headerClass: 'text-end d-none d-lg-table-cell',
    },
    { key: 'open', label: '' },
  ])

  // Which of the four empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  protected readonly emptyState = computed(() =>
    logKeysEmptyState(
      this.header(),
      this.rowCount(),
      this.search() !== '' ||
        this.nodeFilter() !== '' ||
        this.classFilter() !== '',
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

  // A stream still being written is the live one; one left only in the archive is
  // quiet, and the two are told apart by weight of color rather than by wording alone.
  protected stateClass(row: HilosLogKeyRow): string {
    return row.live ? 'text-bg-success' : 'text-bg-light border'
  }

  protected onNode(event: Event): void {
    this.setNode((event.target as HTMLSelectElement).value)
  }

  protected setClass(value: string): void {
    this.classFilter.set(value)
    this.streams().controller.setFilter(KEY_FILTER_CLASS, value)
  }

  protected clearFilters(): void {
    this.streams().controller.setSearch('')
    this.setNode('')
    this.setClass('')
  }

  private setNode(value: string): void {
    this.nodeFilter.set(value)
    this.streams().controller.setFilter(KEY_FILTER_NODE, value)
  }
}
