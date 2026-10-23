// HilosChangeLogPage — the framework Change Log dashboard (HilosPages.CHANGE_LOG).
// The page response supplies one journal overview; the feed asks for its own
// frozen table window. The core owns the figures, empty-state words, row labels
// and table declaration; this view draws them inside the admin shell, so a
// project mounts it by passing its HilosChangeLogReceiptContext. A feed row opens
// the receipt modal over the page's one shared reader, which leaving the page,
// a new page identity or a reset of the page scope empties. Everything built
// from the context input is a computed and every subscription lives in an
// effect, so a new context gets a new feed and a new reader and the old ones are
// released by the same pass. Bootstrap classes only (styling-rules.md).
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
  CHANGE_LOG_FEED_ACTION_FIELD,
  CHANGE_LOG_FEED_ACTOR_LABEL_FIELD,
  CHANGE_LOG_FEED_CREATED_AT_FIELD,
  CHANGE_LOG_FEED_TOUCHED_FIELD,
  CHANGE_LOG_NO_RECEIPT_TEXT,
  CHANGE_LOG_ON_BEHALF_OF_TEXT,
  CHANGE_LOG_OPEN_TABLES_LABEL,
  CHANGE_LOG_OVERVIEW_SECTION,
  CHANGE_LOG_SECTION_NOTE,
  CHANGE_LOG_TILE_JOURNAL_LABEL,
  CHANGE_LOG_TILE_TRACKED_LABEL,
  CHANGE_LOG_TILE_TRACKED_NOTE,
  changeLogChannelBadge,
  changeLogFeedEmpty,
  changeLogJournalNote,
  createHilosChangeLogFeedTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogCoverage,
  formatChangeLogEntries,
  formatChangeLogFeedOpenLabel,
  formatChangeLogOnBehalfOf,
  formatChangeLogTime,
  formatChangeLogTouched,
  formatChangeLogWho,
  readHilosChangeLogOverview,
  type HilosChangeLogFeedRow,
  type HilosChangeLogOverview,
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

/** The Change Log dashboard: the two tiles, the feed and the receipt modal. */
@Component({
  selector: 'hilos-change-log-page',
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
    @let current = overview();
    <hilos-admin-page [page]="page">
      <p class="text-body-secondary">{{ text.sectionNote }}</p>

      <div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-database" aria-hidden="true"></i>
              <span class="small">{{ text.journalLabel }}</span>
            </div>
            <div class="fs-4 lh-1 mb-1" data-id="hilos-change-log-tile-journal">
              {{ current ? journalEntries(current.journalEntries) : '—' }}
            </div>
            <div class="small text-body-secondary">
              {{ current ? journalNote(current) : '—' }}
            </div>
          </div>
        </div>
        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-table" aria-hidden="true"></i>
              <span class="small">{{ text.trackedLabel }}</span>
            </div>
            <div class="fs-4 lh-1 mb-1" data-id="hilos-change-log-tile-tracked">
              {{ current ? coverage(current) : '—' }}
            </div>
            <div class="small text-body-secondary">
              {{ text.trackedNote }}
            </div>
          </div>
        </div>
      </div>

      <hilos-viewport-table [controller]="feed().controller">
        <ng-template [hilosTableCell]="keys.createdAt" let-row>{{
          time(row.createdAt)
        }}</ng-template>
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
        <ng-template [hilosTableCell]="keys.action" let-row>
          @if (row.receiptId !== null) {
            <code>{{ row.action ?? '—' }}</code>
          } @else {
            <span class="text-body-secondary small">{{ text.noReceipt }}</span>
          }
        </ng-template>
        <ng-template [hilosTableCell]="keys.touched" let-row>
          <code class="small text-body-secondary">{{
            touched(row.touched)
          }}</code>
        </ng-template>
        <ng-template [hilosTableCell]="keys.actions" let-row>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary text-wrap"
            data-hilos-focus-return="change-log-open"
            [attr.data-id]="'hilos-change-log-open-' + row.rowKey"
            (click)="openDetails(row)"
          >
            {{ openLabel(row) }}
          </button>
        </ng-template>
        <ng-template #empty>
          <div
            data-id="hilos-change-log-feed-empty"
            [attr.data-state]="emptyState().kind"
          >
            <i
              class="bi bi-inbox fs-1 text-body-secondary mb-2 d-block"
              aria-hidden="true"
            ></i>
            <div class="fw-semibold small mb-1">{{ emptyState().title }}</div>
            <p class="small text-body-secondary mb-3">
              {{ emptyState().hint }}
            </p>
            @if (
              emptyState().kind === 'untracked' && tablesPath !== undefined
            ) {
              <a
                [hilosLink]="tablesPath"
                class="btn btn-sm btn-outline-secondary"
                data-id="hilos-change-log-open-tables"
                >{{ text.openTables }}</a
              >
            }
          </div>
        </ng-template>
      </hilos-viewport-table>
      <hilos-change-log-receipt-modal [reader]="reader()" [dialog]="dialog()" />
    </hilos-admin-page>
  `,
})
export class HilosChangeLogPage {
  /** The project's connection, page scope, and shared tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()

  protected readonly page = HilosPages.CHANGE_LOG
  protected readonly text = {
    sectionNote: CHANGE_LOG_SECTION_NOTE,
    journalLabel: CHANGE_LOG_TILE_JOURNAL_LABEL,
    trackedLabel: CHANGE_LOG_TILE_TRACKED_LABEL,
    trackedNote: CHANGE_LOG_TILE_TRACKED_NOTE,
    onBehalfOf: CHANGE_LOG_ON_BEHALF_OF_TEXT,
    noReceipt: CHANGE_LOG_NO_RECEIPT_TEXT,
    openTables: CHANGE_LOG_OPEN_TABLES_LABEL,
  } as const
  protected readonly keys = {
    createdAt: CHANGE_LOG_FEED_CREATED_AT_FIELD,
    actorLabel: CHANGE_LOG_FEED_ACTOR_LABEL_FIELD,
    action: CHANGE_LOG_FEED_ACTION_FIELD,
    touched: CHANGE_LOG_FEED_TOUCHED_FIELD,
    actions: HILOS_TABLE_ACTIONS_KEY,
  } as const
  protected readonly journalEntries = formatChangeLogEntries
  protected readonly journalNote = (
    overview: HilosChangeLogOverview,
  ): string => {
    this.timeZone()
    return changeLogJournalNote(overview)
  }
  protected readonly coverage = formatChangeLogCoverage
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly time = (moment: string): string => {
    this.timeZone()
    return formatChangeLogTime(moment)
  }
  protected readonly who = formatChangeLogWho
  protected readonly onBehalfOf = formatChangeLogOnBehalfOf
  protected readonly channelBadge = changeLogChannelBadge
  protected readonly touched = formatChangeLogTouched
  protected readonly openLabel = formatChangeLogFeedOpenLabel
  protected readonly isHidden = isHiddenValue

  // The navigator the Open tables link resolves through and whose leaving and
  // new page identity close the receipt; mounted without one, there is no link.
  private readonly router = inject(HILOS_ROUTER, { optional: true })
  protected readonly tablesPath = this.router?.resolvePath(
    HilosPages.CHANGE_LOG_TABLES,
    {},
  )

  // Mirrored from the page's overview section, which derives from the context input.
  private readonly pageOverview = signal<unknown>(undefined)
  protected readonly overview = computed(() =>
    readHilosChangeLogOverview(this.pageOverview()),
  )
  protected readonly emptyState = computed(() =>
    changeLogFeedEmpty(this.overview()),
  )
  protected readonly feed = computed(() => {
    const context = this.context()
    const section = context.scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION)
    return createHilosChangeLogFeedTable(context, {
      // The core calls the getter itself, so it reads the section signal: the
      // core's own computed of the offered options follows it, where a snapshot
      // would freeze the table filter on the first answer. The filter control
      // calls it inside an Angular computed, which sees no core signal, so the
      // getter reads this view's mirror of the same section too — that read is
      // what redraws the dropdown when a later answer names other tables.
      tables: () => {
        this.pageOverview()
        return readHilosChangeLogOverview(section.get())?.journaledTables ?? []
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
          this.context().scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION),
          this.pageOverview,
        ),
      )
    })
    // Bind the feed's window once the context binds, and release it on a new
    // context or when the page is destroyed.
    effect((onCleanup) => {
      const feed = this.feed()
      feed.start()
      onCleanup(() => feed.dispose())
    })
    effect((onCleanup) => {
      const context = this.context()
      const reader = this.reader()
      const router = this.router
      const offDialog = mirrorHilosSignal(reader.dialog, this.dialog)
      const offLeave = router?.onLeave(() => reader.close())
      const offIdentity = router
        ? subscribeSignal(router.pageIdentity, () => reader.close())
        : undefined
      // A re-decided page replaces its scope even when the address stays the
      // same. Discard attribution synchronously, before a viewer can render the
      // new scope.
      const offScope = subscribeSignal(
        computedSignal(() => context.scopes.page()),
        () => reader.close(),
      )
      onCleanup(() => {
        offLeave?.()
        offIdentity?.()
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
   * Open only the address carried by the selected feed row.
   *
   * @param row The feed row whose button was pressed.
   */
  protected openDetails(row: HilosChangeLogFeedRow): void {
    const reader = this.reader()
    if (row.kind === 'receipt' && row.receiptId !== null) {
      void reader.openReceipt(row.receiptId)
    } else if (row.kind === 'entry' && row.entryId !== null) {
      void reader.openEntry(row.entryId)
    }
  }
}
