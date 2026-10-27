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
  changeLogChannelBadge,
  changeLogFeedEmpty,
  createHilosChangeLogFeedTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogFeedOpenLabel,
  formatChangeLogOnBehalfOf,
  formatChangeLogTime,
  formatChangeLogTouched,
  formatChangeLogWho,
  readHilosChangeLogOverview,
  type HilosChangeLogFeedRow,
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

/** The Change Log feed and its intent-driven receipt reader. */
@Component({
  selector: 'hilos-change-log-feed-page',
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
export class HilosChangeLogFeedPage {
  /** The project's connection, page scope, and shared tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()

  protected readonly page = HilosPages.CHANGE_LOG_FEED
  protected readonly text = {
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
