// HilosChangeLogTablePage — the framework Tracked table page
// (HilosPages.CHANGE_LOG_TABLE). It draws one table's section: its header, its
// columns, its journal service triggers, and the history window the view builds
// after the page answers, only for a journaled table. The address names the
// table and may narrow the history to one record; the receipt modal reads
// through the Change Log's own reading action. The core owns the section, the
// history table and every word, so a project mounts this view by passing its
// HilosChangeLogReceiptContext. A new table, record or journal label builds a new
// history window, and the single-pass @for tracked by that window's controller
// gives it a new table — what Vue writes as a key. Everything built from the context input is a
// computed and every subscription lives in an effect, so a new context gets a new
// window and a new reader and the old ones are released by the same pass.
// Bootstrap classes only (styling-rules.md).
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
  CHANGE_LOG_HISTORY_ACTOR_LABEL_FIELD,
  CHANGE_LOG_HISTORY_CHANGES_FIELD,
  CHANGE_LOG_HISTORY_CREATED_AT_FIELD,
  CHANGE_LOG_HISTORY_MUTATION_FIELD,
  CHANGE_LOG_HISTORY_RECORD_KEY_FIELD,
  CHANGE_LOG_ON_BEHALF_OF_TEXT,
  CHANGE_LOG_RECORD_ROUTE_PARAM,
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  changeLogChannelBadge,
  changeLogColumnModeCard,
  changeLogRecordRouteParam,
  createHilosChangeLogHistoryTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogColumnStorage,
  formatChangeLogHistoryChanges,
  formatChangeLogMutation,
  formatChangeLogOnBehalfOf,
  formatChangeLogRecordKey,
  formatChangeLogTableActivity,
  formatChangeLogTableOwner,
  formatChangeLogTime,
  formatChangeLogTrigger,
  formatChangeLogWho,
  readChangeLogRecordRouteParam,
  readHilosChangeLogTable,
  type HilosChangeLogHistoryRow,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogReceiptDialog,
} from '@hilos/core/admin/change-log'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  computedSignal,
  isHiddenValue,
  subscribeSignal,
  type HilosRouter,
} from '@hilos/core/base'

import {
  HilosAdminPage,
  HilosHiddenMark,
  HilosLink,
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosChangeLogReceiptModal } from './HilosChangeLogReceiptModal.js'

// Distinct ids so two pages in one document never share an aria-labelledby
// target — what Vue and React write as useId.
let tablePageSeq = 0

/** The navigator the page reads its address from; Vue and React refuse to build without one too. */
function injectRouter(): HilosRouter {
  const router = inject(HILOS_ROUTER, { optional: true })
  if (!router) {
    throw new Error('HilosChangeLogTablePage requires a provided Hilos router.')
  }

  return router
}

/** The Tracked table page: the header, the columns, the service triggers, the history and the receipt modal. */
@Component({
  selector: 'hilos-change-log-table-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosChangeLogReceiptModal,
    HilosHiddenMark,
    HilosLink,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    @let current = table();
    <hilos-admin-page [page]="page">
      @if (current === null) {
        <p class="text-body-secondary">—</p>
      } @else {
        <div
          class="d-flex flex-wrap align-items-center gap-2 border rounded-3 p-3 mb-3"
          data-id="hilos-change-log-table-header"
        >
          <i class="bi bi-table text-body-secondary" aria-hidden="true"></i>
          <code class="fs-6 fw-semibold text-break">{{ current.name }}</code>
          <span class="small text-body-secondary">{{
            owner(current.owner)
          }}</span>
          @if (current.journaled) {
            <span
              class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-auto"
            >
              <i class="bi bi-journal-check me-1" aria-hidden="true"></i>
              {{ copy.journaled }}
            </span>
            <div class="w-100 small text-body-secondary">
              {{ activity(current) }}
            </div>
          } @else {
            <span class="badge bg-body-secondary text-body border ms-auto">
              {{ text.untracked }}
            </span>
          }
        </div>

        @if (!current.journaled) {
          <p class="alert alert-secondary small py-2">{{ copy.untracked }}</p>
        } @else {
          <section class="mb-4" data-id="hilos-change-log-table-columns">
            <h2 [id]="columnsHeadingId" class="h5">{{ copy.columns }}</h2>
            <div class="table-responsive d-none d-md-block">
              <table
                class="table table-sm align-middle mb-0"
                [attr.aria-labelledby]="columnsHeadingId"
              >
                <thead>
                  <tr>
                    <th scope="col">{{ copy.column }}</th>
                    <th scope="col">{{ copy.storedAs }}</th>
                    <th scope="col">{{ copy.inJournal }}</th>
                  </tr>
                </thead>
                <tbody>
                  @for (column of current.columns; track column.name) {
                    <tr>
                      <td>
                        <code class="text-break">{{ column.name }}</code>
                      </td>
                      <td class="small">{{ storage(column) }}</td>
                      <td>
                        @if (modeCard(column.mode); as card) {
                          <span [class]="'badge text-wrap ' + card.tone">
                            <i
                              [class]="'bi ' + card.icon + ' me-1'"
                              aria-hidden="true"
                            ></i>
                            {{ card.label }}
                          </span>
                        } @else {
                          —
                        }
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
            <div
              class="d-md-none"
              role="list"
              [attr.aria-labelledby]="columnsHeadingId"
            >
              @for (column of current.columns; track column.name) {
                <div class="card mb-2" role="listitem">
                  <div class="card-body py-2 px-3">
                    <div class="fw-medium mb-1">
                      <code class="text-break">{{ column.name }}</code>
                    </div>
                    <dl class="row mb-0 small g-0">
                      <dt class="col-5 fw-normal text-body-secondary">
                        {{ copy.storedAs }}
                      </dt>
                      <dd class="col-7 mb-0">{{ storage(column) }}</dd>
                      <dt class="col-5 fw-normal text-body-secondary">
                        {{ copy.inJournal }}
                      </dt>
                      <dd class="col-7 mb-0">
                        @if (modeCard(column.mode); as card) {
                          <span [class]="'badge text-wrap ' + card.tone">
                            <i
                              [class]="'bi ' + card.icon + ' me-1'"
                              aria-hidden="true"
                            ></i>
                            {{ card.label }}
                          </span>
                        } @else {
                          —
                        }
                      </dd>
                    </dl>
                  </div>
                </div>
              }
            </div>
            @if (hasNoise()) {
              <p class="small text-body-secondary mt-2 mb-0">
                {{ copy.noisyNote }}
              </p>
            }
          </section>

          <section class="mb-4" data-id="hilos-change-log-table-triggers">
            <h2 [id]="triggersHeadingId" class="h5">{{ copy.triggers }}</h2>
            <p class="small text-body-secondary mb-2">
              {{ copy.triggersLead }}
            </p>
            <div class="table-responsive d-none d-md-block">
              <table
                class="table table-sm align-middle mb-0"
                [attr.aria-labelledby]="triggersHeadingId"
              >
                <thead>
                  <tr>
                    <th scope="col">{{ copy.trigger }}</th>
                    <th scope="col">{{ copy.owner }}</th>
                    <th scope="col">{{ copy.fires }}</th>
                    <th scope="col">{{ copy.writesTo }}</th>
                    <th scope="col">{{ copy.validFrom }}</th>
                  </tr>
                </thead>
                <tbody>
                  @for (trigger of current.triggers; track trigger.name) {
                    @let line = describeTrigger(trigger);
                    <tr>
                      <td>
                        <code class="text-break">{{ trigger.name }}</code>
                      </td>
                      <td>
                        <span
                          class="badge bg-body-secondary text-body border"
                          >{{ copy.service }}</span
                        >
                      </td>
                      <td class="small text-nowrap">{{ line.fires }}</td>
                      <td class="small">{{ line.writesTo }}</td>
                      <td class="small text-nowrap">{{ line.validFrom }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
            <div
              class="d-md-none"
              role="list"
              [attr.aria-labelledby]="triggersHeadingId"
            >
              @for (trigger of current.triggers; track trigger.name) {
                @let line = describeTrigger(trigger);
                <div class="card mb-2" role="listitem">
                  <div class="card-body py-2 px-3">
                    <div
                      class="d-flex flex-wrap align-items-center gap-1 fw-medium mb-1"
                    >
                      <code class="text-break">{{ trigger.name }}</code>
                      <span class="badge bg-body-secondary text-body border">{{
                        copy.service
                      }}</span>
                    </div>
                    <dl class="row mb-0 small g-0">
                      <dt class="col-5 fw-normal text-body-secondary">
                        {{ copy.fires }}
                      </dt>
                      <dd class="col-7 mb-0">{{ line.fires }}</dd>
                      <dt class="col-5 fw-normal text-body-secondary">
                        {{ copy.writesTo }}
                      </dt>
                      <dd class="col-7 mb-0">{{ line.writesTo }}</dd>
                      <dt class="col-5 fw-normal text-body-secondary">
                        {{ copy.validFrom }}
                      </dt>
                      <dd class="col-7 mb-0">{{ line.validFrom }}</dd>
                    </dl>
                  </div>
                </div>
              }
            </div>
            <p class="small text-body-secondary mt-2 mb-0">
              {{ copy.triggersFootnote }}
            </p>
          </section>

          @if (history(); as historyWindow) {
            <section>
              @if (record(); as only) {
                <div class="mb-2">
                  <span
                    class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle d-inline-flex align-items-center gap-1"
                    data-id="hilos-change-log-record-filter"
                  >
                    {{ copy.onlyRecord }} {{ recordKey(only) }}
                    @if (tablePath(); as path) {
                      <a
                        [hilosLink]="path"
                        class="link-primary"
                        data-id="hilos-change-log-record-filter-clear"
                        [attr.aria-label]="copy.showAllRecords"
                        ><i class="bi bi-x-lg" aria-hidden="true"></i
                      ></a>
                    }
                  </span>
                </div>
              }
              <!-- Tracked by the window's own controller, one per window: an
              item tracked by itself is reported (NG0956) when it is replaced
              whole, which is the very point here. -->
              @for (shown of [historyWindow]; track shown.controller) {
                <hilos-viewport-table [controller]="shown.controller">
                  <ng-template [hilosTableCell]="keys.createdAt" let-row>{{
                    time(row.createdAt)
                  }}</ng-template>
                  <ng-template [hilosTableCell]="keys.recordKey" let-row>
                    @let path = recordPath(row);
                    @if (path !== undefined) {
                      <a
                        [hilosLink]="path"
                        [attr.data-id]="
                          'hilos-change-log-history-record-' + row.rowKey
                        "
                        >{{ recordKey(row.recordKey) }}</a
                      >
                    } @else {
                      <span>{{ recordKey(row.recordKey) }}</span>
                    }
                  </ng-template>
                  <ng-template [hilosTableCell]="keys.mutation" let-row>
                    <span class="badge bg-body-secondary text-body border">{{
                      mutation(row.mutation)
                    }}</span>
                  </ng-template>
                  <ng-template [hilosTableCell]="keys.changes" let-row>
                    @for (line of historyChanges(row); track $index) {
                      <div class="small text-break">{{ line }}</div>
                    }
                  </ng-template>
                  <ng-template [hilosTableCell]="keys.actorLabel" let-row>
                    <span [class.text-body-secondary]="row.actorDeleted">
                      @if (isHidden(who(row))) {
                        <hilos-hidden-mark />
                        @if (row.actorId !== null) {
                          (#{{ row.actorId }})
                        }
                      } @else {
                        {{ who(row) }}
                      }
                    </span>
                    @if (row.receiptId === null) {
                      <div class="small text-body-secondary">
                        {{ copy.pastApplication }}
                      </div>
                    }
                    @if (row.subjectId !== null) {
                      <div class="small text-body-secondary">
                        {{ text.onBehalfOf }}
                        @if (isHidden(onBehalfOf(row))) {
                          <hilos-hidden-mark /> (#{{ row.subjectId }})
                        } @else {
                          {{ onBehalfOf(row) ?? '#' + row.subjectId }}
                        }
                      </div>
                    }
                    @if (row.receiptId !== null) {
                      @if (channelBadge(row.channel); as channel) {
                        <span
                          class="badge bg-body-secondary text-body border mt-1"
                        >
                          <i
                            [class]="'bi ' + channel.icon + ' me-1'"
                            aria-hidden="true"
                          ></i>
                          {{ channel.label }}
                        </span>
                      }
                    }
                  </ng-template>
                  <ng-template [hilosTableCell]="keys.actions" let-row>
                    @if (row.receiptId !== null) {
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary text-nowrap"
                        [attr.data-id]="
                          'hilos-change-log-history-receipt-' + row.rowKey
                        "
                        [attr.aria-label]="copy.receipt + ' #' + row.receiptId"
                        (click)="openReceipt(row)"
                      >
                        {{ copy.receipt }}
                      </button>
                    } @else {
                      <span class="small text-body-secondary text-nowrap">{{
                        copy.noReceipt
                      }}</span>
                    }
                  </ng-template>
                </hilos-viewport-table>
              }
              <p class="small text-body-secondary mt-2 mb-0">
                {{ copy.historyNote }}
              </p>
            </section>
          }
        }
      }
      <hilos-change-log-receipt-modal [reader]="reader()" [dialog]="dialog()" />
    </hilos-admin-page>
  `,
})
export class HilosChangeLogTablePage {
  /** The project's connection, page scope, and shared tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()

  protected readonly page = HilosPages.CHANGE_LOG_TABLE
  protected readonly copy = CHANGE_LOG_TABLE_COPY
  protected readonly text = {
    untracked: CHANGE_LOG_TABLES_UNTRACKED_NOTE,
    onBehalfOf: CHANGE_LOG_ON_BEHALF_OF_TEXT,
  } as const
  protected readonly keys = {
    createdAt: CHANGE_LOG_HISTORY_CREATED_AT_FIELD,
    recordKey: CHANGE_LOG_HISTORY_RECORD_KEY_FIELD,
    mutation: CHANGE_LOG_HISTORY_MUTATION_FIELD,
    changes: CHANGE_LOG_HISTORY_CHANGES_FIELD,
    actorLabel: CHANGE_LOG_HISTORY_ACTOR_LABEL_FIELD,
    actions: HILOS_TABLE_ACTIONS_KEY,
  } as const
  protected readonly owner = formatChangeLogTableOwner
  protected readonly activity = formatChangeLogTableActivity
  protected readonly storage = formatChangeLogColumnStorage
  protected readonly modeCard = changeLogColumnModeCard
  protected readonly describeTrigger = formatChangeLogTrigger
  protected readonly recordKey = formatChangeLogRecordKey
  protected readonly mutation = formatChangeLogMutation
  protected readonly historyChanges = formatChangeLogHistoryChanges
  protected readonly time = formatChangeLogTime
  protected readonly who = formatChangeLogWho
  protected readonly onBehalfOf = formatChangeLogOnBehalfOf
  protected readonly channelBadge = changeLogChannelBadge
  protected readonly isHidden = isHiddenValue
  private readonly seq = tablePageSeq++
  protected readonly columnsHeadingId = `hilos-change-log-table-columns-title-${this.seq}`
  protected readonly triggersHeadingId = `hilos-change-log-table-triggers-title-${this.seq}`

  // The navigator the address, the links and the receipt's closes come from.
  private readonly router = injectRouter()
  // The table and the raw record segment the address names now — read live:
  // another table or record on the same page is a new history. The segment stays
  // a string here, so an unchanged address never looks like a new record.
  private readonly tableId = hilosSignal(
    computedSignal(
      () =>
        this.router.currentRoute.get().params[CHANGE_LOG_TABLE_ROUTE_PARAM] ??
        '',
    ),
  )
  private readonly recordParam = hilosSignal(
    computedSignal(
      () =>
        this.router.currentRoute.get().params[CHANGE_LOG_RECORD_ROUTE_PARAM],
    ),
  )
  protected readonly record = computed(() =>
    readChangeLogRecordRouteParam(this.recordParam()),
  )
  protected readonly tablePath = computed(() =>
    this.router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
      [CHANGE_LOG_TABLE_ROUTE_PARAM]: this.tableId(),
    }),
  )

  // Mirrored from the page's table section, which derives from the context input.
  private readonly pageTable = signal<unknown>(undefined)
  protected readonly table = computed(() =>
    readHilosChangeLogTable(this.pageTable()),
  )
  private readonly journaled = computed(() => this.table()?.journaled === true)
  protected readonly hasNoise = computed(
    () => this.table()?.columns.some(({ mode }) => mode === 'noise') === true,
  )
  // A new window for every table, record, journal label and context; none for a
  // table the journal does not write. The record segment is split inside: a
  // split computed would hand over a new array on every published route.
  protected readonly history = computed(() => {
    const context = this.context()
    const table = this.tableId()
    const record = readChangeLogRecordRouteParam(this.recordParam())
    if (!this.journaled()) {
      return null
    }
    const section = context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION)
    return createHilosChangeLogHistoryTable(context, {
      table,
      record,
      // The core calls the getter itself, so it reads the section signal: the
      // core's own computed of the offered options follows it, where a snapshot
      // would freeze the field filter on the first answer. The filter control
      // calls it inside an Angular computed, which sees no core signal, so the
      // getter reads this view's mirror of the same section too — that read is
      // what redraws the dropdown when a later answer names other columns.
      fields: () => {
        this.pageTable()
        return (readHilosChangeLogTable(section.get())?.columns ?? [])
          .filter(({ mode }) => mode !== 'record_key')
          .map(({ name }) => name)
      },
    })
  })
  protected readonly reader = computed(() =>
    createHilosChangeLogReceiptReader(this.context()),
  )
  // Mirrored from the reader's dialog, which derives from the context input.
  protected readonly dialog = signal<HilosChangeLogReceiptDialog | null>(null)

  constructor() {
    effect((onCleanup) => {
      onCleanup(
        mirrorHilosSignal(
          this.context().scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
          this.pageTable,
        ),
      )
    })
    // The router clears its buffered page response after publishing the route.
    // Bind the window after the change that published it, once that synchronous
    // navigation has finished, and release it when the address, the journal
    // label or the context changes, or the page is destroyed.
    effect((onCleanup) => {
      const history = this.history()
      if (history === null) {
        return
      }
      history.start()
      onCleanup(() => history.dispose())
    })
    effect((onCleanup) => {
      const context = this.context()
      const reader = this.reader()
      const router = this.router
      const offDialog = mirrorHilosSignal(reader.dialog, this.dialog)
      const offLeave = router.onLeave(() => reader.close())
      const offIdentity = subscribeSignal(router.pageIdentity, () =>
        reader.close(),
      )
      const offRoute = subscribeSignal(router.currentRoute, () =>
        reader.close(),
      )
      // A re-decided page replaces its scope even when the address stays the
      // same. Discard attribution synchronously, before a viewer can render the
      // new scope.
      const offScope = subscribeSignal(
        computedSignal(() => context.scopes.page()),
        () => reader.close(),
      )
      onCleanup(() => {
        offLeave()
        offIdentity()
        offRoute()
        offScope()
        // Disposed, not closed as React does: React closes because StrictMode
        // runs its cleanup and the effect again over the same reader, and
        // Angular never runs an effect again over the same reader.
        reader.dispose()
        offDialog()
      })
    })
  }

  /**
   * Address this table narrowed to a record whose key is not masked.
   *
   * @param row History row whose record the link would open.
   */
  protected recordPath(row: HilosChangeLogHistoryRow): string | undefined {
    const key = row.recordKey
    const parts = (key ?? []).filter(
      (part): part is number | string => part !== null,
    )
    if (key === null || parts.length === 0 || parts.length !== key.length) {
      return undefined
    }
    return this.router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
      [CHANGE_LOG_TABLE_ROUTE_PARAM]: this.tableId(),
      [CHANGE_LOG_RECORD_ROUTE_PARAM]: changeLogRecordRouteParam(parts),
    })
  }

  /**
   * Open the receipt a history row carries, if it has one.
   *
   * @param row History row whose receipt button was pressed.
   */
  protected openReceipt(row: HilosChangeLogHistoryRow): void {
    if (row.receiptId !== null) {
      void this.reader().openReceipt(row.receiptId)
    }
  }
}
