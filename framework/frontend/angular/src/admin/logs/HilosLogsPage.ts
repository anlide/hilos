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
  HILOS_PAGE_ROUTES,
  HilosPages,
  createHilosLogsOverview,
  formatLogsOverviewBytes,
  formatLogsOverviewCount,
  formatLogsOverviewErrorAt,
  formatLogsOverviewGrowth,
  formatLogsOverviewRotationAt,
  hasLogsOverviewNodes,
  hasLogsOverviewRecentErrors,
  logsOverviewBatchesNote,
  logsOverviewErrorOrigin,
  logsOverviewErrorPath,
  logsOverviewGrowthNote,
  logsOverviewNodesDue,
  logsOverviewRecentErrors,
  logsOverviewRecentErrorsBadge,
  logsOverviewState,
  logsOverviewTakeoutHeadline,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosLogsOverview,
  HilosLogsOverviewContext,
  HilosLogsOverviewError,
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
          class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4 mt-1"
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
              Recent errors
            </h2>
            <span
              class="badge text-bg-danger"
              data-id="hilos-logs-recent-errors-count"
            >
              {{ recentErrorsBadge() }}
            </span>
            <span class="ms-auto small text-body-secondary">
              Over the last hour
            </span>
          </div>
          <p class="small text-body-secondary">
            An error asks somebody to go and look at it. Each line leads into
            the journal it was written in — the same node, the same file.
          </p>
          <!-- The row leads to the FILE and not to the line: the viewer address has
          no anchor for a line yet, and "the same place" is the next step rather than
          this one. -->
          @if (hasRecentErrors()) {
            <div
              class="border rounded-3 overflow-hidden mb-2"
              data-id="hilos-logs-recent-errors"
            >
              @for (error of recentErrors(); track $index) {
                <a
                  [hilosLink]="errorPath(error)"
                  class="d-flex align-items-start gap-2 py-2 px-2 border-bottom text-decoration-none link-body-emphasis"
                  data-id="hilos-logs-recent-error"
                >
                  <span class="small fw-semibold text-danger flex-shrink-0"
                    >ERROR</span
                  >
                  <span class="small text-body-secondary flex-shrink-0">
                    {{ formatErrorAt(error.at) }}
                  </span>
                  <span class="flex-grow-1 small">
                    {{ error.message }}
                    <span class="d-block text-body-secondary">
                      {{ errorOrigin(error) }}
                    </span>
                  </span>
                  @if (error.traceFrames !== null) {
                    <span
                      class="badge text-bg-light border flex-shrink-0"
                      title="This error carries a stack trace"
                      data-id="hilos-logs-recent-error-trace"
                    >
                      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                      {{ error.traceFrames }}
                    </span>
                  }
                  <i
                    class="bi bi-chevron-right text-body-secondary flex-shrink-0"
                    aria-hidden="true"
                  ></i>
                </a>
              }
            </div>
          } @else {
            <!-- Good news, and it must not read as "no data": the picture IS here,
            and it says nothing went wrong in the window. -->
            <div
              class="border rounded-3 p-3 mb-2 d-flex align-items-center gap-3 bg-body-tertiary"
              data-id="hilos-logs-recent-errors-empty"
            >
              <i
                class="bi bi-check2-circle fs-4 text-success"
                aria-hidden="true"
              ></i>
              <div>
                <div class="fw-semibold small">No errors in the last hour</div>
                <div class="small text-body-secondary">
                  Nothing has asked for attention in that time.
                </div>
              </div>
            </div>
          }
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
            No node could read its log store. Check the log directory setting
            and the permissions on it. The tiles stay empty rather than zero — a
            zero would be a measurement nobody took.
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

  // The panel of last failures. It is drawn only where there ARE figures: saying
  // "nothing has gone wrong" about a picture that has not arrived would be good news
  // made up, and that is the one thing an empty state here must never look like.
  protected readonly recentErrors = computed(() =>
    logsOverviewRecentErrors(this.overview()),
  )
  protected readonly hasRecentErrors = computed(() =>
    hasLogsOverviewRecentErrors(this.overview()),
  )
  protected readonly recentErrorsBadge = computed(() =>
    logsOverviewRecentErrorsBadge(this.overview()),
  )
  protected readonly formatErrorAt = formatLogsOverviewErrorAt
  protected readonly errorPath = logsOverviewErrorPath

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
   * Where a failure was written, as the row's sub-line says it.
   *
   * A method and not a bound function, because the origin is asked of the whole
   * picture and not of the row: the row's empty node id is a value, not a sign that
   * this installation runs on one machine.
   *
   * @param error The failure the row draws.
   */
  protected errorOrigin(error: HilosLogsOverviewError): string {
    return logsOverviewErrorOrigin(this.overview(), error)
  }
}
