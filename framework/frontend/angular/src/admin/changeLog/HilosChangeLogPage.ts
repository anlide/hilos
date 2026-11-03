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
  CHANGE_LOG_OVERVIEW_SECTION,
  CHANGE_LOG_SECTION_NOTE,
  CHANGE_LOG_TILE_JOURNAL_LABEL,
  CHANGE_LOG_TILE_TRACKED_LABEL,
  CHANGE_LOG_TILE_TRACKED_NOTE,
  changeLogJournalNote,
  formatChangeLogCoverage,
  formatChangeLogEntries,
  readHilosChangeLogOverview,
  type HilosChangeLogOverview,
  type HilosChangeLogContext,
} from '@hilos/core/admin/change-log'
import { HilosPages, hilosTabLinks, hilosTimeZone } from '@hilos/core/base'

import {
  HilosAdminPage,
  HilosLink,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** The Change Log overview and its two journal figures. */
@Component({
  selector: 'hilos-change-log-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink],
  template: `
    @let current = overview();
    <hilos-admin-page
      [page]="page"
      headKind="section"
      tabsLabel="Change Log pages"
    >
      <p class="text-body-secondary">{{ text.sectionNote }}</p>

      <div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
        <div class="col">
          @if (feedPath(); as path) {
            <a
              [hilosLink]="path"
              class="d-block border rounded-3 p-3 h-100 text-body text-decoration-none"
              data-id="hilos-change-log-link-feed"
            >
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-database" aria-hidden="true"></i>
                <span class="small">{{ text.journalLabel }}</span>
              </div>
              <div
                class="fs-4 lh-1 mb-1"
                data-id="hilos-change-log-tile-journal"
              >
                {{ current ? journalEntries(current.journalEntries) : '—' }}
              </div>
              <div class="small text-body-secondary">
                {{ current ? journalNote(current) : '—' }}
              </div>
            </a>
          } @else {
            <div class="border rounded-3 p-3 h-100">
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-database" aria-hidden="true"></i>
                <span class="small">{{ text.journalLabel }}</span>
              </div>
              <div
                class="fs-4 lh-1 mb-1"
                data-id="hilos-change-log-tile-journal"
              >
                {{ current ? journalEntries(current.journalEntries) : '—' }}
              </div>
              <div class="small text-body-secondary">
                {{ current ? journalNote(current) : '—' }}
              </div>
            </div>
          }
        </div>
        <div class="col">
          @if (tablesPath(); as path) {
            <a
              [hilosLink]="path"
              class="d-block border rounded-3 p-3 h-100 text-body text-decoration-none"
              data-id="hilos-change-log-link-tables"
            >
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-table" aria-hidden="true"></i>
                <span class="small">{{ text.trackedLabel }}</span>
              </div>
              <div
                class="fs-4 lh-1 mb-1"
                data-id="hilos-change-log-tile-tracked"
              >
                {{ current ? coverage(current) : '—' }}
              </div>
              <div class="small text-body-secondary">
                {{ text.trackedNote }}
              </div>
            </a>
          } @else {
            <div class="border rounded-3 p-3 h-100">
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-table" aria-hidden="true"></i>
                <span class="small">{{ text.trackedLabel }}</span>
              </div>
              <div
                class="fs-4 lh-1 mb-1"
                data-id="hilos-change-log-tile-tracked"
              >
                {{ current ? coverage(current) : '—' }}
              </div>
              <div class="small text-body-secondary">
                {{ text.trackedNote }}
              </div>
            </div>
          }
        </div>
      </div>
    </hilos-admin-page>
  `,
})
export class HilosChangeLogPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosChangeLogContext>()

  protected readonly page = HilosPages.CHANGE_LOG
  protected readonly text = {
    sectionNote: CHANGE_LOG_SECTION_NOTE,
    journalLabel: CHANGE_LOG_TILE_JOURNAL_LABEL,
    trackedLabel: CHANGE_LOG_TILE_TRACKED_LABEL,
    trackedNote: CHANGE_LOG_TILE_TRACKED_NOTE,
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
  private readonly router = inject(HILOS_ROUTER)
  private readonly identity = hilosSignal(this.router.pageIdentity)
  private readonly route = hilosSignal(this.router.currentRoute)
  private readonly tabs = computed(() =>
    hilosTabLinks(
      this.identity()?.pageTabs ?? [],
      this.route().params,
      this.router.resolvePath,
    ),
  )
  protected readonly feedPath = computed(
    () =>
      this.tabs().find((tab) => tab.page === HilosPages.CHANGE_LOG_FEED)?.to,
  )
  protected readonly tablesPath = computed(
    () =>
      this.tabs().find((tab) => tab.page === HilosPages.CHANGE_LOG_TABLES)?.to,
  )
  // Mirrored from the page's overview section, which derives from the context input.
  private readonly pageOverview = signal<unknown>(undefined)
  protected readonly overview = computed(() =>
    readHilosChangeLogOverview(this.pageOverview()),
  )
  constructor() {
    effect((onCleanup) => {
      onCleanup(
        mirrorHilosSignal(
          this.context().scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION),
          this.pageOverview,
        ),
      )
    })
  }
}
