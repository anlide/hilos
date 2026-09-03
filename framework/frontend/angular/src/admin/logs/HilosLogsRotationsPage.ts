// HilosLogsRotationsPage — the framework Hilos rotation-history page
// (HilosPages.LOGS_ROTATIONS): what already lies in the log archive, what it weighs,
// and what the retention rule recommends carrying off before the installation runs
// out of room. A row is one batch ON ONE NODE — the same rotation moment on two
// machines is two directories, carried off apart — so the node column and the node
// filter exist only where nodes have names. Search, the node filter and the
// All / awaiting switch ride the open viewport filter map (server-side, no local
// filtering); the window is re-served by the page whenever the cluster picture or
// the rule moves. A recommended batch carries the one command of this screen: a
// modal saying where the batch lies and how to copy it off, and a confirmation that
// it was (HIL-483) — the badge then repaints when the holding node's next index
// arrives, not when the ack does. Deleting a taken batch is HIL-382, taking a
// confirmation back is HIL-759, and there is no way through to the viewer yet
// because it takes no batch address (HIL-388). All table logic, the row
// view-model, the empty-state discrimination and the wording are the core headless's
// (hilosLogRotations); this view owns only the markup, so a project mounts it by
// passing its HilosLogRotationsContext. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_PAGE_ROUTES,
  HILOS_ROTATION_STATE_DUE,
  HILOS_ROTATION_STATE_OPTIONS,
  HILOS_ROTATION_STATE_TAKEN,
  HilosPages,
  ROTATION_BATCH_AT_FIELD,
  ROTATION_BYTES_FIELD,
  ROTATION_FILTER_NODE,
  ROTATION_FILTER_STATE,
  ROTATION_NODE_FIELD,
  createHilosLogRotationsActions,
  createHilosLogRotationsHeader,
  createHilosLogRotationsTable,
  formatRetentionRule,
  formatRotationFileCounts,
  formatRotationRule,
  formatRotationState,
  formatRotationWeight,
  hasRotationNodes,
  rotationTakeoutAddress,
  rotationTakeoutCommand,
  rotationsEmptyState,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosLogRotationRow,
  HilosLogRotationsContext,
  HilosLogRotationsHeader,
  HilosTableColumn,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

// The retention badge: a recommendation is a warning and not a fault, a taken
// batch is settled, and a kept one is the quiet default.
const RETENTION_CLASS: Record<string, string> = {
  [HILOS_ROTATION_STATE_DUE]: 'text-bg-warning',
  [HILOS_ROTATION_STATE_TAKEN]: 'text-bg-secondary',
}

/** The framework rotation-history page: the archive, the rule and the takeout dialog. */
@Component({
  selector: 'hilos-logs-rotations-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosAdminPage,
    HilosLink,
    HilosModal,
    HilosViewportTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <div
        class="d-flex flex-wrap align-items-center gap-3 border rounded-3 p-3 mb-4"
      >
        <i class="bi bi-sliders text-body-secondary" aria-hidden="true"></i>
        <div class="flex-grow-1">
          @if (header(); as rule) {
            <div class="fw-semibold small" data-id="hilos-rotation-rule">
              {{ formatRule(rule) }}
            </div>
            <div class="small text-body-secondary">
              {{ formatRetention(rule) }}
            </div>
          } @else {
            <div class="small text-body-secondary">
              The rule in force is not known yet.
            </div>
          }
          <div class="small text-body-secondary">
            {{
              clustered()
                ? 'One rule for the whole cluster'
                : 'One rule for the installation'
            }}
          </div>
        </div>
        <a
          [hilosLink]="settingsHref"
          class="btn btn-sm btn-outline-secondary text-nowrap"
          data-id="hilos-rotation-settings"
        >
          Log settings
        </a>
      </div>

      <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
        @if (clustered()) {
          <div>
            <label class="form-label" for="hilos-rotation-node">Node</label>
            <select
              id="hilos-rotation-node"
              class="form-select"
              [value]="nodeFilter()"
              data-id="hilos-rotation-node"
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
          aria-label="Retention state"
        >
          @for (option of stateOptions; track option.value) {
            <button
              type="button"
              class="btn btn-outline-secondary"
              [class.active]="stateFilter() === option.value"
              [attr.aria-pressed]="stateFilter() === option.value"
              [attr.data-id]="'hilos-rotation-state-' + (option.value || 'all')"
              (click)="setState(option.value)"
            >
              {{ option.label }}
            </button>
          }
        </div>
      </div>

      <hilos-viewport-table
        label="Rotation batches"
        [controller]="rotations().controller"
        [columns]="columns()"
        [searchable]="true"
        searchPlaceholder="Search by batch date or node…"
      >
        <ng-template #row let-row>
          <td>
            <div class="fw-semibold small">{{ batchTime(row) }}</div>
            <code class="small text-body-secondary">{{ row.path }}</code>
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
          <td class="small">{{ formatFileCounts(row) }}</td>
          <td class="text-end d-none d-lg-table-cell">
            {{ formatWeight(row) }}
          </td>
          <td>
            <span [class]="'badge ' + retentionClass(row)">
              {{ formatState(row) }}
            </span>
          </td>
          <td class="text-end text-nowrap">
            @if (offersTakeout(row)) {
              <button
                type="button"
                class="btn btn-sm btn-warning"
                data-id="hilos-rotation-takeout"
                (click)="openTakeout(row)"
              >
                How to carry it off
              </button>
            }
          </td>
        </ng-template>

        <ng-template #empty>
          @if (emptyState() === 'unknown') {
            <div data-id="hilos-rotation-empty-unknown">
              <div class="fw-semibold">
                The cluster picture has not arrived yet
              </div>
              <p class="mb-0">
                Nobody has reported yet, so there are no figures — not zero of
                them.
              </p>
            </div>
          } @else if (emptyState() === 'unreadable') {
            <div data-id="hilos-rotation-empty-unreadable">
              <div class="fw-semibold">The log directory cannot be read</div>
              <p class="mb-0">
                No node could read its log store. Check the log directory
                setting and the permissions on it.
              </p>
            </div>
          } @else if (emptyState() === 'nomatch') {
            <div data-id="hilos-rotation-empty-nomatch">
              <div class="fw-semibold">Nothing matches</div>
              <p class="mb-2">There are batches — just not these.</p>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-id="hilos-rotation-clear-filters"
                (click)="clearFilters()"
              >
                Clear the filters
              </button>
            </div>
          } @else {
            <div data-id="hilos-rotation-empty-never">
              <div class="fw-semibold">Nothing has rotated yet</div>
              <p class="mb-0">
                The archive fills at the first rotation; until then there is
                nothing to carry off.
              </p>
            </div>
          }
        </ng-template>
      </hilos-viewport-table>

      <p class="small text-body-secondary mt-2 mb-0">
        <button
          type="button"
          class="btn btn-link btn-sm p-0 align-baseline"
          data-id="hilos-rotation-legend"
          (click)="legendOpen.set(true)"
        >
          Files
        </button>
        — three numbers in a row: agent / worker / monopolistic worker.
      </p>

      <hilos-modal
        [(open)]="takeoutOpen"
        [title]="takeoutTitle()"
        [closeOnBackdrop]="!takeout.busy()"
        [closeOnEsc]="!takeout.busy()"
      >
        <hilos-action-error [action]="takeout" />
        <p>
          This batch is recommended for carrying off: it is older than the
          retention rule keeps. The system does
          <strong>not delete it</strong> — you copy it where you keep cold logs,
          and then confirm that you have.
        </p>
        @if (takeoutAddress() && takeoutCommand()) {
          <div class="fw-semibold mb-1">Where it lies</div>
          <pre
            class="border rounded-2 p-2 bg-body-tertiary mb-3"
            data-id="hilos-rotation-takeout-path"
          ><code>{{ takeoutAddress() }}</code></pre>
          <div class="fw-semibold mb-1">How to take it</div>
          <pre
            class="border rounded-2 p-2 bg-body-tertiary mb-3"
            data-id="hilos-rotation-takeout-command"
          ><code>{{ takeoutCommand() }}</code></pre>
        } @else {
          <!-- A node that reported no log root has no address to give, and this
          screen must not offer its own: the page worker knows where ITS logs live,
          and that directory is on the wrong machine. Confirming is still possible —
          the operator may know the path from the node itself. -->
          <div class="alert alert-secondary small py-2">
            This node did not report where its logs live, so there is no address
            to copy from here. Look it up on the node itself.
          </div>
        }
        @if (clustered() && takeoutRow()?.node) {
          <div class="alert alert-warning small py-2 mb-0">
            The batch lies on node
            <span class="font-monospace">{{ takeoutRow()?.node }}</span> and
            only there: logs do not converge anywhere. Take it from that node,
            and the confirmation covers this batch on this node.
          </div>
        } @else {
          <div class="alert alert-secondary small py-2 mb-0">
            Once confirmed, the batch becomes available to the cleaner — until
            then it will not be touched.
          </div>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="takeout.busy()"
            (click)="requestClose()"
          >
            Close
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="takeout.loading()"
            data-id="hilos-rotation-takeout-confirm"
            (click)="submitTakeout()"
          >
            I have taken this batch
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal [(open)]="legendOpen" title="What is in a batch">
        <p>
          A batch is one archive directory, written by one rotation on one node.
          The three numbers count the files in it by the stream that wrote them:
        </p>
        <ul class="mb-3">
          <li><strong>agent</strong> — one file per agent that logged.</li>
          <li>
            <strong>worker</strong> — one per worker process, the monopolistic
            ones apart.
          </li>
          <li>
            <strong>monopolistic worker</strong> — the workers that hold work
            which cannot be done in two hands.
          </li>
        </ul>
        <p class="mb-0">
          The daemon's own two files are a fourth class and are not counted here
          — they belong to the node rather than to anything the installation
          runs. The weight column still includes them: that is what the
          directory costs.
        </p>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosLogsRotationsPage {
  /** The project context: scope stores, the connection, and the action lifecycle. */
  readonly context = input.required<HilosLogRotationsContext>()

  protected readonly page = HilosPages.LOGS_ROTATIONS
  protected readonly stateOptions = HILOS_ROTATION_STATE_OPTIONS
  protected readonly formatFileCounts = formatRotationFileCounts
  protected readonly formatRetention = formatRetentionRule
  protected readonly formatRule = formatRotationRule
  protected readonly formatState = formatRotationState
  protected readonly formatWeight = formatRotationWeight

  // The rule line leads to the general settings screen: the log settings page does
  // not exist in the registry yet (HIL-391 adds it and re-points this link).
  protected readonly settingsHref =
    HILOS_PAGE_ROUTES[HilosPages.SETTINGS] ?? '/'

  protected readonly rotations = computed(() =>
    createHilosLogRotationsTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosLogRotationsActions(this.context()),
  )
  private readonly headerHandle = computed(() =>
    createHilosLogRotationsHeader(this.context()),
  )

  // The header and the window state, mirrored from the (per-context) core signals
  // into Angular signals so the template re-renders on every frame.
  protected readonly header = signal<HilosLogRotationsHeader | null>(null)
  private readonly rowCount = signal(0)
  private readonly search = signal('')

  // Domain filters: the node and the state ride the open filter map so the backend
  // narrows the window (no local filtering). Empty clears the filter.
  protected readonly nodeFilter = signal('')
  protected readonly stateFilter = signal('')

  // The takeout dialog: how to carry one batch off, and the button that records
  // that it was. Only a recommended batch offers it — a kept one is not being asked
  // for, and a taken one has already been answered.
  protected readonly takeoutOpen = signal(false)
  protected readonly takeoutRow = signal<HilosLogRotationRow | null>(null)
  protected readonly takeout = createHilosTrackedAction()

  protected readonly legendOpen = signal(false)

  // The node column and the node filter exist only where nodes have names: in a
  // single-node installation a column repeating one name and a filter offering one
  // option would both be furniture for a choice that does not exist.
  protected readonly clustered = computed(() => hasRotationNodes(this.header()))

  // Declared as the loose HilosTableColumn rather than the row-typed form: the Files
  // column is three counts at once and belongs to no single field, so keying it to
  // one of them would name the column after a third of what it shows. The sortable
  // keys are the exported wire constants, which is where a typo would actually cost
  // something — they travel to the backend as the sort field.
  //
  // The node and weight columns drop out of the header below `lg`, where their
  // values move into the sub-line of the batch cell: a narrow screen gets a shorter
  // table rather than one that scrolls sideways.
  protected readonly columns = computed<HilosTableColumn[]>(() => [
    { key: ROTATION_BATCH_AT_FIELD, label: 'Batch', sortable: true },
    ...(this.clustered()
      ? [
          {
            key: ROTATION_NODE_FIELD,
            label: 'Node',
            sortable: true,
            headerClass: 'd-none d-lg-table-cell',
          },
        ]
      : []),
    { key: 'files', label: 'Files' },
    {
      key: ROTATION_BYTES_FIELD,
      label: 'Weight',
      sortable: true,
      headerClass: 'text-end d-none d-lg-table-cell',
    },
    { key: 'retention', label: 'Retention' },
    { key: 'actions', label: '', headerClass: 'text-end' },
  ])

  // Which of the four empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  protected readonly emptyState = computed(() =>
    rotationsEmptyState(
      this.header(),
      this.rowCount(),
      this.search() !== '' ||
        this.nodeFilter() !== '' ||
        this.stateFilter() !== '',
    ),
  )

  // A snapshot of the row the dialog opened on, so a window re-served underneath it
  // (the page re-sends one whenever the picture moves) does not swap the batch the
  // operator is reading the address of.
  protected readonly takeoutAddress = computed(() => {
    const row = this.takeoutRow()

    return row === null ? null : rotationTakeoutAddress(row)
  })
  protected readonly takeoutCommand = computed(() => {
    const row = this.takeoutRow()

    return row === null ? null : rotationTakeoutCommand(row)
  })
  protected readonly takeoutTitle = computed(() => {
    const row = this.takeoutRow()
    if (row === null) {
      return 'Carrying off a batch'
    }

    return `Carrying off the batch of ${this.batchTime(row)}${row.node ? ` · ${row.node}` : ''}`
  })

  constructor() {
    // Bind the server-windowed table and start listening for the header once the
    // context input is bound; the header also arrives once as the answer to the
    // subscription. The same effect re-binds on a context swap and unbinds on destroy.
    effect((onCleanup) => {
      const rotations = this.rotations()
      const headerHandle = this.headerHandle()
      headerHandle.start()
      rotations.start()
      this.header.set(headerHandle.header.get())
      this.rowCount.set(rotations.controller.rows.get().length)
      this.search.set(rotations.controller.search.get())
      const unsubscribes = [
        subscribeSignal(headerHandle.header, (next) => {
          this.header.set(next)
        }),
        subscribeSignal(rotations.controller.rows, (next) => {
          this.rowCount.set(next.length)
        }),
        subscribeSignal(rotations.controller.search, (next) => {
          this.search.set(next)
        }),
      ]
      onCleanup(() => {
        for (const unsubscribe of unsubscribes) {
          unsubscribe()
        }
        rotations.dispose()
        headerHandle.dispose()
      })
    })
  }

  // The batch's own name is its rotation time; the archive directory under it is
  // what an operator types into scp, so both are in the cell.
  protected batchTime(row: HilosLogRotationRow): string {
    return new Date(row.batchAt * 1000).toLocaleString()
  }

  protected retentionClass(row: HilosLogRotationRow): string {
    return RETENTION_CLASS[row.retentionState] ?? 'text-bg-light border'
  }

  protected offersTakeout(row: HilosLogRotationRow): boolean {
    return row.retentionState === HILOS_ROTATION_STATE_DUE
  }

  protected openTakeout(row: HilosLogRotationRow): void {
    this.takeout.clearError()
    this.takeoutRow.set(row)
    this.takeoutOpen.set(true)
  }

  protected async submitTakeout(): Promise<void> {
    const row = this.takeoutRow()
    if (row === null || this.takeout.busy()) {
      return
    }
    // The dialog closes on the server's word and not on the click: the refusals
    // this can meet — the batch is gone, it is protected again — are the whole
    // reason the confirmation travels to the node that holds the directory.
    if (await this.takeout.run(this.actions().sendTakeoutConfirm(row))) {
      this.takeoutOpen.set(false)
    }
  }

  protected onNode(event: Event): void {
    this.setNode((event.target as HTMLSelectElement).value)
  }

  protected setState(value: string): void {
    this.stateFilter.set(value)
    this.rotations().controller.setFilter(ROTATION_FILTER_STATE, value)
  }

  protected clearFilters(): void {
    this.rotations().controller.setSearch('')
    this.setNode('')
    this.setState('')
  }

  private setNode(value: string): void {
    this.nodeFilter.set(value)
    this.rotations().controller.setFilter(ROTATION_FILTER_NODE, value)
  }
}
