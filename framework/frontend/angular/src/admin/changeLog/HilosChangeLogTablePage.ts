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
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_SECTION,
  changeLogTableColumnCounts,
  formatChangeLogColumnCounts,
  formatChangeLogTableChanges,
  formatChangeLogTableLastChange,
  readHilosChangeLogTable,
  type HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'
import { HilosPages, hilosTabLinks, hilosTimeZone } from '@hilos/core/base'

import {
  HilosLink,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

import { HilosChangeLogTableShell } from './HilosChangeLogTableShell.js'

@Component({
  selector: 'hilos-change-log-table-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogTableShell, HilosLink],
  template: `
    <hilos-change-log-table-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        @if (table(); as current) {
          @if (!current.journaled) {
            <p class="alert alert-secondary small py-2">{{ copy.untracked }}</p>
          }
          <div class="row row-cols-1 row-cols-sm-3 g-3 mb-3">
            @if (current.journaled) {
              @if (pagePath(pages.CHANGE_LOG_TABLE_HISTORY); as path) {
                <div class="col">
                  <a
                    [hilosLink]="path"
                    class="card h-100 text-decoration-none link-body-emphasis"
                    data-id="hilos-change-log-table-link-history"
                  >
                    <div class="card-body p-3">
                      <h2
                        class="small fw-normal d-flex align-items-center gap-2 text-body-secondary mb-1"
                      >
                        <i class="bi bi-activity" aria-hidden="true"></i>Changes
                        in 24 hours
                      </h2>
                      <div class="fs-4 lh-1 mb-1">
                        {{
                          formatChangeLogTableChanges(current.changesLast24h)
                        }}
                      </div>
                      <div class="small text-body-secondary">
                        Last change: {{ lastChange(current.lastChangeAt) }}
                      </div>
                    </div>
                  </a>
                </div>
              }
              @if (pagePath(pages.CHANGE_LOG_TABLE_COLUMNS); as path) {
                <div class="col">
                  <a
                    [hilosLink]="path"
                    class="card h-100 text-decoration-none link-body-emphasis"
                    data-id="hilos-change-log-table-link-columns"
                  >
                    <div class="card-body p-3">
                      <h2
                        class="small fw-normal d-flex align-items-center gap-2 text-body-secondary mb-1"
                      >
                        <i
                          class="bi bi-layout-three-columns"
                          aria-hidden="true"
                        ></i
                        >Columns
                      </h2>
                      <div class="fs-4 lh-1 mb-1">
                        {{ current.columns.length }}
                      </div>
                      <div class="small text-body-secondary">
                        {{
                          formatChangeLogColumnCounts(
                            changeLogTableColumnCounts(current)
                          )
                        }}
                      </div>
                    </div>
                  </a>
                </div>
              }
            }
            @if (pagePath(pages.CHANGE_LOG_TABLE_TRIGGERS); as path) {
              <div class="col">
                <a
                  [hilosLink]="path"
                  class="card h-100 text-decoration-none link-body-emphasis"
                  data-id="hilos-change-log-table-link-triggers"
                >
                  <div class="card-body p-3">
                    <h2
                      class="small fw-normal d-flex align-items-center gap-2 text-body-secondary mb-1"
                    >
                      <i class="bi bi-lightning-charge" aria-hidden="true"></i
                      >Triggers
                    </h2>
                    <div class="fs-4 lh-1 mb-1">
                      {{ current.triggers.length }}
                    </div>
                    <div class="small text-body-secondary">
                      Service {{ current.triggers.length }}
                    </div>
                  </div>
                </a>
              </div>
            }
          </div>
        }
      </ng-template>
    </hilos-change-log-table-shell>
  `,
})
export class HilosChangeLogTablePage {
  protected readonly page = HilosPages.CHANGE_LOG_TABLE
  protected readonly pages = HilosPages

  /** The project's connection, page scope and tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()
  protected readonly copy = CHANGE_LOG_TABLE_COPY
  protected readonly changeLogTableColumnCounts = changeLogTableColumnCounts
  protected readonly formatChangeLogColumnCounts = formatChangeLogColumnCounts
  protected readonly formatChangeLogTableChanges = formatChangeLogTableChanges
  private readonly router = inject(HILOS_ROUTER)
  private readonly identity = hilosSignal(this.router.pageIdentity)
  private readonly route = hilosSignal(this.router.currentRoute)
  private readonly timeZone = hilosSignal(hilosTimeZone)
  private readonly tabs = computed(() =>
    hilosTabLinks(
      this.identity()?.pageTabs ?? [],
      this.route().params,
      this.router.resolvePath,
    ),
  )
  private readonly pageTable = signal<unknown>(undefined)
  protected readonly table = computed(() =>
    readHilosChangeLogTable(this.pageTable()),
  )
  constructor() {
    effect((onCleanup) => {
      onCleanup(
        mirrorHilosSignal(
          this.context().scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
          this.pageTable,
        ),
      )
    })
  }

  /** Only tabs supplied for this instance and built by the project get links. */
  protected pagePath(page: string): string | undefined {
    return this.tabs().find((tab) => tab.page === page)?.to
  }

  /** Format the moment again when the reader's effective time zone changes. */
  protected lastChange(at: string | null): string {
    this.timeZone()
    return formatChangeLogTableLastChange(at)
  }
}
