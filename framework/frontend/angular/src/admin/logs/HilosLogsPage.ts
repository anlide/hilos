// The logs overview keeps the complete summary and a page-scoped By node window.
// The subscription supplies both; later overview frames and table windows refresh
// them by push. The core owns their data, forecast and empty-state wording. This
// view supplies the cells and links into the rotations awaiting takeout.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosLogNodesTable,
  createHilosLogsOverview,
  formatLogsOverviewBytes,
  formatLogsOverviewCount,
  formatLogsOverviewGrowth,
  formatLogsOverviewRecentAt,
  formatLogsOverviewRotationAt,
  hasLogsOverviewNodes,
  hasLogsOverviewRecent,
  HILOS_ROTATION_STATE_DUE,
  logLevelVariant,
  logRotationsPath,
  logsOverviewBatchesNote,
  logsOverviewForecastNote,
  logsOverviewGrowthNote,
  logsOverviewNodesDue,
  logsOverviewRecent,
  logsOverviewRecentBadge,
  logsOverviewRecentEmptyLead,
  logsOverviewRecentEmptyTitle,
  logsOverviewRecentLead,
  logsOverviewRecentLevel,
  logsOverviewRecentOrigin,
  logsOverviewRecentPath,
  logsOverviewRecentTabLabel,
  logsOverviewState,
  logsOverviewTakeoutHeadline,
  logsOverviewThresholdPassed,
  NODE_ARCHIVE_BYTES_FIELD,
  NODE_BATCHES_DUE_FOR_TAKEOUT_FIELD,
  NODE_GROWTH_BYTES_PER_DAY_FIELD,
  NODE_ID_FIELD,
  NODE_LAST_ROTATION_AT_FIELD,
  NODE_LIVE_BYTES_FIELD,
  RECENT_TAB_ERRORS,
  RECENT_TAB_WARNINGS,
} from '@hilos/core/admin/logs'
import {
  formatMoment,
  HilosPages,
  hilosTimeZone,
  subscribeSignal,
} from '@hilos/core/base'
import type {
  HilosLogsOverview,
  HilosLogsOverviewRecentEntry,
} from '@hilos/core/base'
import type {
  HilosLogNodesContext,
  HilosLogsRecentTab,
} from '@hilos/core/admin/logs'

import {
  HilosAdminPage,
  HilosHideable,
  HilosLink,
  HilosTableCell,
  HilosViewportTable,
  hilosSignal,
} from '@hilos/angular/base'

/** The framework logs overview: tiles, the takeout banner and the per-node table. */
@Component({
  selector: 'hilos-logs-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosHideable,
    HilosLink,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="section" tabsLabel="Logs pages">
      <div
        class="row row-cols-1 row-cols-sm-2 g-3 mb-3"
        data-id="hilos-logs-tiles"
      >
        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-clock-history" aria-hidden="true"></i>
              <span class="small">Last rotation</span>
            </div>
            <!-- The tile names when it last happened and how many batches there
            are. It does NOT say "on schedule": that would be a verdict on the
            rotation setting, which is not in this frame, and reassuring an admin
            falsely here costs more than saying less. -->
            <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-rotation">
              {{ formatRotationAt(overview()?.lastRotationAt ?? null) }}
            </div>
            <div class="small text-body-secondary">{{ batchesNote() }}</div>
          </div>
        </div>

        <div class="col">
          <div
            class="border rounded-3 p-3 h-100"
            [class.border-danger]="thresholdPassed()"
            data-id="hilos-logs-growth-tile"
          >
            <div class="d-flex align-items-center gap-2 mb-1">
              <i
                class="bi bi-graph-up-arrow"
                [class.text-danger]="thresholdPassed()"
                [class.text-body-secondary]="!thresholdPassed()"
                aria-hidden="true"
              ></i>
              <span class="small text-body-secondary">Written per day</span>
            </div>
            <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-growth">
              {{ growth() }}
            </div>
            @if (growthNote(); as note) {
              <div
                class="small text-body-secondary"
                data-id="hilos-logs-growth-note"
              >
                {{ note }}
              </div>
            }
            <!-- A reached threshold remains a warning before a rate is known. -->
            @if (growthForecast(); as forecast) {
              <div
                class="small"
                [class.text-danger-emphasis]="thresholdPassed()"
                [class.text-body-secondary]="!thresholdPassed()"
                data-id="hilos-logs-growth-forecast"
              >
                @if (thresholdPassed()) {
                  <i
                    class="bi bi-exclamation-triangle me-1"
                    aria-hidden="true"
                  ></i>
                }
                <hilos-hideable [value]="forecast" />
              </div>
            }
          </div>
        </div>
      </div>

      <!-- One set of the three stream classes, in the order of the filters on the
      streams screen: daemon, agents, workers. A row of its own rather than a fifth
      tile in the row above: a class missing from a set of three is seen at once,
      and at the tail of a list of five it is not - which is how the daemon streams
      stayed off this screen. -->
      <div
        class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-4"
        data-id="hilos-logs-class-tiles"
      >
        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-hdd-stack" aria-hidden="true"></i>
              <span class="small">Daemon streams</span>
            </div>
            <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-daemon">
              {{ formatCount(overview()?.logKeysPerDaemon ?? null) }}
              <span class="fs-6 text-body-secondary">
                ·
                {{
                  formatBytes(overview()?.totalWeightDaemonKeysBytes ?? null)
                }}
              </span>
            </div>
            <div class="small text-body-secondary">The raw pair counted in</div>
          </div>
        </div>

        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-cpu" aria-hidden="true"></i>
              <span class="small">Agent streams</span>
            </div>
            <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-agents">
              {{ formatCount(overview()?.logKeysPerAgent ?? null) }}
              <span class="fs-6 text-body-secondary">
                ·
                {{ formatBytes(overview()?.totalWeightAgentKeysBytes ?? null) }}
              </span>
            </div>
            <div class="small text-body-secondary">
              Live and archived together
            </div>
          </div>
        </div>

        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-diagram-3" aria-hidden="true"></i>
              <span class="small">Worker streams</span>
            </div>
            <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-workers">
              {{ formatCount(overview()?.logKeysPerWorker ?? null) }}
              <span class="fs-6 text-body-secondary">
                ·
                {{
                  formatBytes(overview()?.totalWeightWorkerKeysBytes ?? null)
                }}
              </span>
            </div>
            <div class="small text-body-secondary">
              Monopolistic ones included
            </div>
          </div>
        </div>
      </div>

      @if (batchesDue() > 0) {
        <div
          class="alert alert-warning d-flex align-items-start gap-3 py-3"
          data-id="hilos-logs-takeout"
        >
          <i class="bi bi-box-arrow-up fs-5" aria-hidden="true"></i>
          <div class="flex-grow-1">
            <div class="fw-semibold small mb-1">
              {{ takeoutHeadline() }}
              @if (nodesDue().length) {
                — on {{ nodesDue().join(', ') }}
              }
            </div>
            <div class="small">
              They are past the retention you set. Until you take them and
              confirm it, they free no space — nothing is deleted here on its
              own.
            </div>
          </div>
          <a
            [hilosLink]="rotationsPath"
            class="btn btn-sm btn-warning text-nowrap"
            data-id="hilos-logs-takeout-open"
          >
            Show them
          </a>
        </div>
      }

      @if (state() === 'figures') {
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
          <h2 class="h6 text-uppercase text-body-secondary mb-0">
            Recent failures
          </h2>
        </div>
        <p class="small text-body-secondary">{{ recentLead() }}</p>
        <!-- Two tabs and never one feed: warnings always outnumber errors and would
        bury them. -->
        <div
          class="border rounded-3 overflow-hidden mb-2"
          data-id="hilos-logs-recent"
        >
          <ul
            class="nav nav-tabs px-2 pt-2 bg-body-tertiary"
            role="tablist"
            data-id="hilos-logs-recent-tabs"
          >
            @for (tab of recentTabs; track tab) {
              <li class="nav-item" role="presentation">
                <button
                  type="button"
                  role="tab"
                  class="nav-link py-1 px-3 small"
                  [class.active]="recentTab() === tab"
                  [attr.aria-selected]="recentTab() === tab"
                  aria-controls="hilos-logs-recent-panel"
                  [attr.data-id]="'hilos-logs-recent-tab-' + tab"
                  (click)="recentTab.set(tab)"
                >
                  {{ recentTabLabel(tab) }}
                  <span
                    class="badge ms-1"
                    [class]="'text-bg-' + levelVariant(recentLevelOf(tab))"
                    [attr.data-id]="'hilos-logs-recent-count-' + tab"
                  >
                    {{ recentBadge(tab) }}
                  </span>
                </button>
              </li>
            }
            <li
              class="ms-auto small text-body-secondary py-1"
              role="presentation"
            >
              Over the last hour
            </li>
          </ul>
          <div id="hilos-logs-recent-panel" role="tabpanel">
            @if (hasRecent()) {
              @for (entry of recentEntries(); track $index) {
                <a
                  [hilosLink]="recentPath(entry)"
                  class="d-flex align-items-start gap-2 py-2 px-2 border-bottom text-decoration-none link-body-emphasis"
                  data-id="hilos-logs-recent-row"
                >
                  <span
                    class="small fw-semibold flex-shrink-0"
                    [class]="'text-' + levelVariant(recentLevel())"
                    >{{ recentLevel() }}</span
                  >
                  <span class="small text-body-secondary flex-shrink-0">
                    {{ formatRecentAt(entry.at) }}
                  </span>
                  <span class="flex-grow-1 small">
                    <hilos-hideable [value]="entry.message" />
                    <span class="d-block text-body-secondary">
                      {{ recentOrigin(entry) }}
                    </span>
                  </span>
                  @if (entry.traceFrames !== null) {
                    <span
                      class="badge bg-body-tertiary text-body-emphasis border flex-shrink-0"
                      title="This entry carries a stack trace"
                      data-id="hilos-logs-recent-row-trace"
                    >
                      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                      {{ entry.traceFrames }}
                    </span>
                  }
                  <i
                    class="bi bi-chevron-right text-body-secondary flex-shrink-0"
                    aria-hidden="true"
                  ></i>
                </a>
              }
            } @else {
              <!-- Good news, and it must not read as "no data": the picture IS here,
              and it says nothing of this kind happened in the window. -->
              <div
                class="p-3 d-flex align-items-center gap-3 bg-body-tertiary"
                data-id="hilos-logs-recent-empty"
              >
                <i
                  class="bi bi-check2-circle fs-4 text-success"
                  aria-hidden="true"
                ></i>
                <div>
                  <div class="fw-semibold small">
                    {{ recentEmptyTitle() }}
                  </div>
                  <div class="small text-body-secondary">
                    {{ recentEmptyLead() }}
                  </div>
                </div>
              </div>
            }
          </div>
        </div>
      }

      @if (clustered()) {
        <hilos-viewport-table
          [controller]="nodesHandle().controller"
          class="mt-4"
        >
          <ng-template [hilosTableCell]="nodeIdField" let-row>
            <span
              class="badge bg-body-tertiary text-body-emphasis border"
              [attr.data-id]="'hilos-logs-node-' + row.nodeId"
            >
              {{ row.nodeId }}
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="nodeLastRotationAtField" let-row>
            <span [attr.data-id]="'hilos-logs-node-rotation-' + row.nodeId">
              {{ formatNodeRotationAt(row.lastRotationAt) }}
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="nodeLiveBytesField" let-row>
            <span [attr.data-id]="'hilos-logs-node-live-' + row.nodeId">
              {{ formatBytes(row.liveBytes) }}
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="nodeArchiveBytesField" let-row>
            {{ formatBytes(row.archiveBytes) }}
          </ng-template>
          <ng-template [hilosTableCell]="nodeGrowthBytesPerDayField" let-row>
            {{ formatBytes(row.growthBytesPerDay) }}
          </ng-template>
          <ng-template [hilosTableCell]="nodeBatchesDueForTakeoutField" let-row>
            @if ((row.batchesDueForTakeout ?? 0) > 0) {
              <a
                [hilosLink]="rotationsPath"
                class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle text-decoration-none"
                [attr.data-id]="'hilos-logs-node-due-' + row.nodeId"
              >
                {{ row.batchesDueForTakeout }}
              </a>
            } @else {
              <span class="text-body-secondary small">—</span>
            }
          </ng-template>
        </hilos-viewport-table>
      }

      @if (state() === 'unknown') {
        <div
          class="alert alert-secondary small py-3 mt-4"
          data-id="hilos-logs-empty-unknown"
        >
          <div class="fw-semibold mb-1">
            <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
            The cluster picture has not arrived yet
          </div>
          Nobody has reported yet, so the tiles are empty rather than zero: a
          zero would say nothing has ever rotated, and here we simply do not
          know.
        </div>
      } @else if (state() === 'unreadable') {
        <div
          class="alert alert-secondary small py-3 mt-4"
          data-id="hilos-logs-empty-unreadable"
        >
          <div class="fw-semibold mb-1">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            The log directory cannot be read
          </div>
          @if (clustered()) {
            No node could read its log store.
          } @else {
            The log store could not be read.
          }
          Check the log directory setting and the permissions on it. The tiles
          stay empty rather than zero — a zero would be a measurement nobody
          took.
        </div>
      }
    </hilos-admin-page>
  `,
})
export class HilosLogsPage {
  /** The project context: the connection and page-scoped table stores. */
  readonly context = input.required<HilosLogNodesContext>()

  protected readonly page = HilosPages.LOGS
  protected readonly rotationsPath = logRotationsPath(HILOS_ROTATION_STATE_DUE)
  protected readonly formatBytes = formatLogsOverviewBytes
  protected readonly formatCount = formatLogsOverviewCount
  protected readonly nodeIdField = NODE_ID_FIELD
  protected readonly nodeLastRotationAtField = NODE_LAST_ROTATION_AT_FIELD
  protected readonly nodeLiveBytesField = NODE_LIVE_BYTES_FIELD
  protected readonly nodeArchiveBytesField = NODE_ARCHIVE_BYTES_FIELD
  protected readonly nodeGrowthBytesPerDayField =
    NODE_GROWTH_BYTES_PER_DAY_FIELD
  protected readonly nodeBatchesDueForTakeoutField =
    NODE_BATCHES_DUE_FOR_TAKEOUT_FIELD
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly formatRotationAt = (moment: string | null): string => {
    this.timeZone()
    return formatLogsOverviewRotationAt(moment)
  }
  protected readonly formatNodeRotationAt = (moment: number | null): string => {
    this.timeZone()
    return moment === null
      ? '—'
      : (formatMoment(moment, { kind: 'dateTime' }) ?? '—')
  }

  private readonly logs = computed(() =>
    createHilosLogsOverview(this.context()),
  )
  protected readonly nodesHandle = computed(() =>
    createHilosLogNodesTable(this.context()),
  )

  // The latest frame, mirrored from the (per-context) core signal into an Angular
  // signal so the template re-renders on every push.
  protected readonly overview = signal<HilosLogsOverview | null>(null)

  // The full overview decides whether this installation has a node table.
  protected readonly clustered = computed(() =>
    hasLogsOverviewNodes(this.overview()),
  )
  protected readonly thresholdPassed = computed(() =>
    logsOverviewThresholdPassed(this.overview()),
  )

  // Which of the two empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  protected readonly state = computed(() => logsOverviewState(this.overview()))

  // The banner is about batches that already exist; at zero there is nothing to say,
  // and a banner saying so would be a warning about nothing.
  protected readonly batchesDue = computed(
    () => this.overview()?.batchesDueForTakeout ?? 0,
  )
  protected readonly nodesDue = computed(() =>
    logsOverviewNodesDue(this.overview()),
  )
  protected readonly takeoutHeadline = computed(() =>
    logsOverviewTakeoutHeadline(this.batchesDue()),
  )
  protected readonly batchesNote = computed(() =>
    logsOverviewBatchesNote(this.overview()),
  )
  protected readonly growth = computed(() =>
    formatLogsOverviewGrowth(this.overview()),
  )
  protected readonly growthNote = computed(() =>
    logsOverviewGrowthNote(this.overview()),
  )
  protected readonly growthForecast = computed(() =>
    logsOverviewForecastNote(this.overview()),
  )

  // The panel of last failures. It is drawn only where there ARE figures: saying
  // "nothing has gone wrong" about a picture that has not arrived would be good news
  // made up, and that is the one thing an empty state here must never look like.
  // The open tab is the screen's own state: both lists ride every frame, so a click
  // on a tab asks the server for nothing.
  protected readonly recentTabs: readonly HilosLogsRecentTab[] = [
    RECENT_TAB_ERRORS,
    RECENT_TAB_WARNINGS,
  ]
  protected readonly recentTab = signal<HilosLogsRecentTab>(RECENT_TAB_ERRORS)
  protected readonly recentEntries = computed(() =>
    logsOverviewRecent(this.overview(), this.recentTab()),
  )
  protected readonly hasRecent = computed(() =>
    hasLogsOverviewRecent(this.overview(), this.recentTab()),
  )
  protected readonly recentLevel = computed(() =>
    logsOverviewRecentLevel(this.recentTab()),
  )
  protected readonly recentLead = computed(() =>
    logsOverviewRecentLead(this.recentTab()),
  )
  protected readonly recentEmptyTitle = computed(() =>
    logsOverviewRecentEmptyTitle(this.recentTab()),
  )
  protected readonly recentEmptyLead = computed(() =>
    logsOverviewRecentEmptyLead(this.recentTab()),
  )
  protected readonly recentLevelOf = logsOverviewRecentLevel
  protected readonly recentTabLabel = logsOverviewRecentTabLabel
  protected readonly levelVariant = logLevelVariant
  protected readonly formatRecentAt = (moment: string): string => {
    this.timeZone()
    return formatLogsOverviewRecentAt(moment)
  }
  protected readonly recentPath = logsOverviewRecentPath

  constructor() {
    // Bind the overview and table push paths without requesting a second first
    // window. A context swap or destroy releases both bindings together.
    effect((onCleanup) => {
      const logs = this.logs()
      const nodesHandle = this.nodesHandle()
      logs.start()
      nodesHandle.start()
      this.overview.set(logs.overview.get())
      const unsubscribe = subscribeSignal(logs.overview, (next) => {
        this.overview.set(next)
      })
      onCleanup(() => {
        unsubscribe()
        nodesHandle.dispose()
        logs.dispose()
      })
    })
  }

  /**
   * Where an entry was written, as the row's sub-line says it.
   *
   * A method and not a bound function, because the origin is asked of the whole
   * picture and not of the row: the row's empty node id is a value, not a sign that
   * this installation runs on one machine.
   *
   * @param entry The entry the row draws.
   */
  protected recentOrigin(entry: HilosLogsOverviewRecentEntry): string {
    return logsOverviewRecentOrigin(this.overview(), entry)
  }

  /**
   * The counter inside one tab, read off the whole picture by that tab's own flag.
   *
   * @param tab The tab the counter sits in.
   */
  protected recentBadge(tab: HilosLogsRecentTab): string {
    return logsOverviewRecentBadge(this.overview(), tab)
  }
}
