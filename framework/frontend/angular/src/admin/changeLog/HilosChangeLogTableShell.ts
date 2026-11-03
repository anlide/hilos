import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  effect,
  inject,
  Injector,
  input,
  signal,
  viewChild,
  type TemplateRef,
} from '@angular/core'
import {
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  changeLogTableJournalSince,
  formatChangeLogTableOwner,
  readHilosChangeLogTable,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogTableDetail,
} from '@hilos/core/admin/change-log'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
} from '@hilos/core/base'
import {
  HilosAdminPage,
  HILOS_ROUTER,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** The current table's permitted entity head and page-owned body template. */
@Component({
  selector: 'hilos-change-log-table-shell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, NgTemplateOutlet],
  template: `
    <hilos-admin-page
      [page]="page()"
      headKind="entity"
      headTitleClass="font-monospace fs-5"
      tabsLabel="Table pages"
    >
      <ng-template #headVisual>
        @if (current()) {
          <span
            class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
            aria-hidden="true"
            data-id="hilos-change-log-table-head-icon"
          >
            <i class="bi bi-table"></i>
          </span>
        }
      </ng-template>
      <ng-template #headBadges>
        @if (current(); as table) {
          <span
            class="d-inline-flex flex-wrap gap-2"
            data-id="hilos-change-log-table-head-badges"
          >
            <span class="badge bg-body-secondary text-body border">{{
              formatChangeLogTableOwner(table.owner)
            }}</span>
            @if (table.journaled) {
              <span
                class="badge bg-success-subtle text-success-emphasis border border-success-subtle"
              >
                <i class="bi bi-journal-check me-1" aria-hidden="true"></i>
                {{ copy.journaled }}
              </span>
            }
          </span>
        }
      </ng-template>
      <ng-template #headDescription>
        @if (current(); as table) {
          <p
            class="text-body-secondary mb-0"
            data-id="hilos-change-log-table-head-description"
          >
            @if (!table.journaled) {
              {{ copy.untracked }}
            } @else if (since() !== null) {
              Journaled since migration {{ migration() }}
            }
          </p>
        }
      </ng-template>
      <ng-template #pageBody>
        @if (current()) {
          @if (bodyInjector(); as injector) {
            <ng-container
              [ngTemplateOutlet]="body() ?? null"
              [ngTemplateOutletInjector]="injector"
            />
          }
        }
      </ng-template>
    </hilos-admin-page>
  `,
})
export class HilosChangeLogTableShell {
  /** The existing connection, page scopes and tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()
  /** The subscribing table page whose navigation link is current. */
  readonly page = input.required<string>()

  private readonly router = inject(HILOS_ROUTER)
  protected readonly body = contentChild<TemplateRef<unknown>>('pageBody')
  // The caller's body needs the real admin page's heading and view-mode providers.
  protected readonly bodyInjector = viewChild(HilosAdminPage, {
    read: Injector,
  })
  protected readonly current = signal<HilosChangeLogTableDetail | null>(null)
  protected readonly copy = copy
  protected readonly formatChangeLogTableOwner = formatChangeLogTableOwner
  protected readonly since = computed(() => {
    const table = this.current()
    return table ? changeLogTableJournalSince(table) : null
  })
  protected readonly migration = computed(() =>
    String(this.since()).padStart(3, '0'),
  )

  constructor() {
    effect((onCleanup) => {
      const { scopes } = this.context()
      const pageTable = scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION)
      const current = computedSignal(() => {
        const page = scopes.page()?.key
        const route = this.router.currentRoute.get()
        if (
          (page !== HilosPages.CHANGE_LOG_TABLE &&
            page !== HilosPages.CHANGE_LOG_TABLE_COLUMNS &&
            page !== HilosPages.CHANGE_LOG_TABLE_TRIGGERS &&
            page !== HilosPages.CHANGE_LOG_TABLE_HISTORY) ||
          route.page !== page ||
          this.router.pageError.get() !== null
        )
          return null
        const table = readHilosChangeLogTable(pageTable.get())
        return table?.name === route.params[CHANGE_LOG_TABLE_ROUTE_PARAM]
          ? table
          : null
      })
      const unbindName = bindPageInstanceName(
        scopes,
        HilosPages.CHANGE_LOG_TABLE,
        computedSignal(() => current.get()?.name),
      )
      const stop = mirrorHilosSignal(current, this.current)
      onCleanup(() => {
        unbindName()
        stop()
        this.current.set(null)
      })
    })
  }
}
