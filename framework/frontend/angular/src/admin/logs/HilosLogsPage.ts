// HilosLogsPage — the framework Hilos logs page (HilosPages.LOGS): the root of the
// section and its overview. It answers two questions at once, "is anything wrong with
// the journals" and "where do I go from here", so it keeps the shell's cards to its
// child pages and puts its own figures underneath them, in the shell's body slot.
// Everything on it arrives in ONE frame of the page's own signal and refreshes itself
// by push; there is no table viewport, because the per-node rows are one per node that
// reported and fit in that frame whole. The screen commands nothing: the takeout banner
// and the per-node badge are ordinary navigation into the rotations history. Which of
// the two empty states it is in, and the wording of every figure, are the core
// headless's (hilosLogsOverview); this view owns only the markup, so a project mounts
// it by passing its HilosLogsOverviewContext. Bootstrap classes only
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
  createHilosLogsOverview,
  formatLogsOverviewBytes,
  formatLogsOverviewCount,
  formatLogsOverviewGrowth,
  formatLogsOverviewRecentAt,
  formatLogsOverviewRotationAt,
  hasLogsOverviewNodes,
  hasLogsOverviewRecent,
  HILOS_PAGE_ROUTES,
  HilosPages,
  logLevelVariant,
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
  RECENT_TAB_ERRORS,
  RECENT_TAB_WARNINGS,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosLogsOverview,
  HilosLogsOverviewContext,
  HilosLogsOverviewRecentEntry,
  HilosLogsRecentTab,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'

/** The framework logs overview: tiles, the takeout banner and the per-node table. */
@Component({
  selector: 'hilos-logs-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink],
  template: `
    <hilos-admin-page [page]="page">
      <ng-container ngProjectAs="[body]">
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
            <div class="border rounded-3 p-3 h-100">
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
                <span class="small">Written per day</span>
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
              <!-- Last of the three on purpose: the note above qualifies the
              figure, and this line says what the figure MEANS for the disk. The
              consequence is read after the caveat, and it is shown even while the
              caveat stands - the rate is there to divide by either way. -->
              @if (growthForecast(); as forecast) {
                <div
                  class="small text-body-secondary"
                  data-id="hilos-logs-growth-forecast"
                >
                  {{ forecast }}
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
              <div class="small text-body-secondary">
                The raw pair counted in
              </div>
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
                  {{
                    formatBytes(overview()?.totalWeightAgentKeysBytes ?? null)
                  }}
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
                      {{ entry.message }}
                      <span class="d-block text-body-secondary">
                        {{ recentOrigin(entry) }}
                      </span>
                    </span>
                    @if (entry.traceFrames !== null) {
                      <span
                        class="badge text-bg-light border flex-shrink-0"
                        title="This entry carries a stack trace"
                        data-id="hilos-logs-recent-row-trace"
                      >
                        <i
                          class="bi bi-info-circle me-1"
                          aria-hidden="true"
                        ></i>
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
          <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
            <h2 class="h6 text-uppercase text-body-secondary mb-0">By node</h2>
            <span
              class="badge text-bg-success-subtle text-success-emphasis border border-success-subtle"
            >
              <i class="bi bi-broadcast me-1" aria-hidden="true"></i>
              Updates itself
            </span>
          </div>
          <p class="small text-body-secondary">
            A journal is written on the node the event happened on and stays
            there. The tiles above add every node together; here you can see
            whose journal is growing faster.
          </p>
          <!-- An ordinary table and not the viewport one: the rows are one per node
          that reported and ride the same frame as the tiles, so a descriptor, a
          pager and a busy state would all be paid for a list that never pages. -->
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Node</th>
                  <th scope="col">Last rotation</th>
                  <th scope="col" class="text-end d-none d-lg-table-cell">
                    Live
                  </th>
                  <th scope="col" class="text-end d-none d-lg-table-cell">
                    Archive
                  </th>
                  <th scope="col" class="text-end d-none d-lg-table-cell">
                    Per day
                  </th>
                  <th scope="col">To take out</th>
                </tr>
              </thead>
              <tbody>
                @for (node of nodes(); track node.nodeId) {
                  <tr [attr.data-id]="'hilos-logs-node-' + node.nodeId">
                    <td>
                      <span class="badge text-bg-light border">
                        {{ node.nodeId }}
                      </span>
                      <!-- The sub-line carries whatever the hidden columns were
                      carrying, so a narrow screen loses the layout and not the
                      figures. -->
                      @if (node.available) {
                        <div class="small text-body-secondary d-lg-none">
                          {{ formatBytes(node.liveBytes) }} ·
                          {{ formatBytes(node.archiveBytes) }} ·
                          {{ formatBytes(node.growthBytesPerDay) }}
                        </div>
                      }
                    </td>
                    <!-- A node that could not read its own store says so once, in
                    place of its figures. Dashes across every column would read as
                    six separate unknowns instead of one node that did not answer. -->
                    @if (!node.available) {
                      <td
                        colspan="5"
                        class="small text-body-secondary"
                        [attr.data-id]="'hilos-logs-node-nodata-' + node.nodeId"
                      >
                        No data — this node could not read its log store
                      </td>
                    } @else {
                      <td class="small">
                        {{ formatRotationAt(node.lastRotationAt) }}
                      </td>
                      <td class="text-end d-none d-lg-table-cell">
                        {{ formatBytes(node.liveBytes) }}
                      </td>
                      <td class="text-end d-none d-lg-table-cell">
                        {{ formatBytes(node.archiveBytes) }}
                      </td>
                      <td class="text-end d-none d-lg-table-cell">
                        {{ formatBytes(node.growthBytesPerDay) }}
                      </td>
                      <td>
                        @if ((node.batchesDueForTakeout ?? 0) > 0) {
                          <a
                            [hilosLink]="rotationsPath"
                            class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle text-decoration-none"
                            [attr.data-id]="
                              'hilos-logs-node-due-' + node.nodeId
                            "
                          >
                            {{ node.batchesDueForTakeout }}
                          </a>
                        } @else {
                          <span class="text-body-secondary small">—</span>
                        }
                      </td>
                    }
                  </tr>
                }
              </tbody>
            </table>
          </div>
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
      </ng-container>
    </hilos-admin-page>
  `,
})
export class HilosLogsPage {
  /** The project context: the connection the screen's frames arrive on. */
  readonly context = input.required<HilosLogsOverviewContext>()

  protected readonly page = HilosPages.LOGS
  protected readonly rotationsPath =
    HILOS_PAGE_ROUTES[HilosPages.LOGS_ROTATIONS]
  protected readonly formatBytes = formatLogsOverviewBytes
  protected readonly formatCount = formatLogsOverviewCount
  protected readonly formatRotationAt = formatLogsOverviewRotationAt

  private readonly logs = computed(() =>
    createHilosLogsOverview(this.context()),
  )

  // The latest frame, mirrored from the (per-context) core signal into an Angular
  // signal so the template re-renders on every push.
  protected readonly overview = signal<HilosLogsOverview | null>(null)

  // The per-node table exists only where nodes have names: in a single-node
  // installation the whole idea of a node is absent, and a table of one row about
  // "this machine" would be furniture for a distinction that does not exist.
  protected readonly clustered = computed(() =>
    hasLogsOverviewNodes(this.overview()),
  )
  protected readonly nodes = computed(() => this.overview()?.nodes ?? [])

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
  protected readonly formatRecentAt = formatLogsOverviewRecentAt
  protected readonly recentPath = logsOverviewRecentPath

  constructor() {
    // The frame arrives once as the answer to the subscription and again on every
    // tick where the cluster picture moved; nothing is ever re-requested. The same
    // effect re-subscribes on a context swap and disposes on destroy.
    effect((onCleanup) => {
      const logs = this.logs()
      logs.start()
      this.overview.set(logs.overview.get())
      const unsubscribe = subscribeSignal(logs.overview, (next) => {
        this.overview.set(next)
      })
      onCleanup(() => {
        unsubscribe()
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
