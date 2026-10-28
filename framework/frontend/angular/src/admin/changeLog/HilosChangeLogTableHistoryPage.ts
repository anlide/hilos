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
  changeLogRecordRouteParam,
  createHilosChangeLogHistoryTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogHistoryChanges,
  formatChangeLogMutation,
  formatChangeLogOnBehalfOf,
  formatChangeLogRecordKey,
  formatChangeLogTime,
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
  hilosTimeZone,
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

/** The navigator the page reads its address from; Vue and React refuse to build without one too. */
function injectRouter(): HilosRouter {
  const router = inject(HILOS_ROUTER, { optional: true })
  if (!router) {
    throw new Error(
      'HilosChangeLogTableHistoryPage requires a provided Hilos router.',
    )
  }

  return router
}

/** Record history and its receipt reader. */
@Component({
  selector: 'hilos-change-log-table-history-page',
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
    <hilos-admin-page [page]="page">
      <p>
        @if (overviewPath(); as path) {
          <a [hilosLink]="path" data-id="hilos-change-log-table-overview"
            >Overview</a
          >
        }
      </p>
      @if (table(); as current) {
        @if (!current.journaled) {
          <p class="alert alert-secondary small py-2">{{ copy.untracked }}</p>
        }
      }
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
                    <span class="badge bg-body-secondary text-body border mt-1">
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
      <hilos-change-log-receipt-modal [reader]="reader()" [dialog]="dialog()"
    /></hilos-admin-page>
  `,
})
export class HilosChangeLogTableHistoryPage {
  /** The project's connection, page scope, and shared tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()

  protected readonly page = HilosPages.CHANGE_LOG_TABLE_HISTORY
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
  protected readonly recordKey = formatChangeLogRecordKey
  protected readonly mutation = formatChangeLogMutation
  protected readonly historyChanges = formatChangeLogHistoryChanges
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly time = (moment: string): string => {
    this.timeZone()
    return formatChangeLogTime(moment)
  }
  protected readonly who = formatChangeLogWho
  protected readonly onBehalfOf = formatChangeLogOnBehalfOf
  protected readonly channelBadge = changeLogChannelBadge
  protected readonly isHidden = isHiddenValue
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
    this.router.resolvePath(HilosPages.CHANGE_LOG_TABLE_HISTORY, {
      [CHANGE_LOG_TABLE_ROUTE_PARAM]: this.tableId(),
    }),
  )

  protected readonly overviewPath = computed(() =>
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
    return this.router.resolvePath(HilosPages.CHANGE_LOG_TABLE_HISTORY, {
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
