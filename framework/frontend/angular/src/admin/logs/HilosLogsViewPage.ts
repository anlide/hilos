// HilosLogsViewPage — the framework Hilos log viewer (HilosPages.LOGS_VIEW):
// the lines of ONE file on ONE node, for an administrator who came to work out what
// already happened. The node, the source (the live journal or one archived batch)
// and the stream together ARE the address of this screen, so choosing another file
// rewrites the address in place rather than navigating — it is another file of the
// same page, and a navigation would re-subscribe it and drop the catalog. Nothing
// is filtered here: the level and the substring are fields of the read request, and
// the server answers with what matched. The Follow switch runs the live tail, and
// there is no Refresh button on purpose: freshness arrives as a push. Scrolling up
// releases only the STICKING — the tail keeps running, what arrives while the
// reader is up waits beside the pane, and the return control at the bottom carries
// its count; so nothing ever moves under the reader's eyes, and nothing is lost
// without saying so. The catalog, the address, the read, the buffer and the row
// view-model are the core headless's (hilosLogViewer), including the threshold that
// decides "at the tail" and the wording of the notes — this view owns only the
// markup and the scrolling, so a project mounts it by passing its
// HilosLogViewerContext and the Vue and React ports measure the same edge.
// Bootstrap classes only, save the one pre-wrap declaration the pane calls for
// because Bootstrap has no utility for it (styling-rules.md, same exception as
// HilosActionError).
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core'
import {
  HILOS_LOG_LEVEL_OPTIONS,
  HilosPages,
  LOG_SOURCE_LIVE,
  createHilosLogViewer,
  createSignal,
  hasLogViewerNodes,
  isLogViewerPinned,
  logLevelVariant,
  logViewerNodeOf,
  logViewerPaneState,
  logViewerStreamsOf,
  readLogViewerAddress,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosLogViewerAddress,
  HilosLogViewerCatalog,
  HilosLogViewerContext,
  HilosLogViewerEntry,
  HilosLogViewerNotice,
  HilosLogViewerRow,
  HilosLogViewerSelection,
  PageRouteMatch,
  ReadonlySignal,
  Unsubscribe,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'

/** The icon one note is drawn with; its wording belongs to the headless. */
const NOTICE_ICONS: Record<HilosLogViewerNotice, string> = {
  rotated: 'bi-arrow-repeat',
  skipped: 'bi-fast-forward',
  dropped: 'bi-scissors',
  stopped: 'bi-stop-circle',
}

/** The framework log viewer: one file of one node, with its live tail. */
@Component({
  selector: 'hilos-logs-view-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage],
  template: `
    <hilos-admin-page [page]="page">
      <div class="border rounded-3 p-3 mb-3">
        <div class="row g-2 align-items-end">
          @if (clustered()) {
            <div class="col-6 col-md-2">
              <label
                class="form-label small fw-semibold mb-1"
                for="hilos-log-node"
              >
                Node
              </label>
              <select
                id="hilos-log-node"
                class="form-select form-select-sm"
                [value]="selection().nodeId ?? ''"
                data-id="hilos-log-node"
                (change)="onNode($event)"
              >
                <option value="" disabled>Choose a node</option>
                @for (entry of catalog()?.nodes ?? []; track entry.nodeId) {
                  <option [value]="entry.nodeId">{{ entry.nodeId }}</option>
                }
              </select>
            </div>
          }
          <div class="col-6 col-md-3">
            <label
              class="form-label small fw-semibold mb-1"
              for="hilos-log-source"
            >
              Source
            </label>
            <select
              id="hilos-log-source"
              class="form-select form-select-sm"
              [value]="sourceValue(selection().source)"
              data-id="hilos-log-source"
              (change)="onSource($event)"
            >
              <option [value]="live">Live journal</option>
              @for (batch of batches(); track batch) {
                <option [value]="batch">Batch — {{ batchLabel(batch) }}</option>
              }
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label
              class="form-label small fw-semibold mb-1"
              for="hilos-log-stream"
            >
              Stream
            </label>
            <select
              id="hilos-log-stream"
              class="form-select form-select-sm"
              [value]="selection().stream ?? ''"
              data-id="hilos-log-stream"
              (change)="onStream($event)"
            >
              <option value="">Choose a stream</option>
              @for (stream of streams(); track stream.key) {
                <option [value]="stream.key">{{ stream.key }}</option>
              }
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label
              class="form-label small fw-semibold mb-1"
              for="hilos-log-level"
            >
              Level
            </label>
            <select
              id="hilos-log-level"
              class="form-select form-select-sm"
              [value]="level()"
              data-id="hilos-log-level"
              (change)="onLevel($event)"
            >
              @for (option of levelOptions; track option.value) {
                <option [value]="option.value">{{ option.label }}</option>
              }
            </select>
          </div>
          <div class="col-6 col-md-2">
            <span class="form-label small fw-semibold mb-1 d-block"
              >Follow</span
            >
            <div class="form-check form-switch mb-1">
              <input
                id="hilos-log-follow"
                class="form-check-input"
                type="checkbox"
                [checked]="followRequested()"
                [disabled]="!canFollow()"
                data-id="hilos-log-follow"
                (change)="onFollow($event)"
              />
              <label class="form-check-label small" for="hilos-log-follow">
                tail
              </label>
            </div>
            @if (!canFollow()) {
              <span
                class="small text-body-secondary"
                data-id="hilos-log-follow-off"
              >
                An archived batch has no tail.
              </span>
            }
          </div>
          <div class="col-12">
            <label class="visually-hidden" for="hilos-log-substring">
              Search inside the lines
            </label>
            <input
              id="hilos-log-substring"
              type="search"
              class="form-control form-control-sm"
              placeholder="Search inside the lines"
              [value]="substring()"
              data-id="hilos-log-substring"
              (change)="onSubstring($event)"
            />
          </div>
        </div>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          [disabled]="!hasMore() || busy()"
          data-id="hilos-log-earlier"
          (click)="viewer().readOlder()"
        >
          <i class="bi bi-arrow-up me-1" aria-hidden="true"></i>Earlier
        </button>
        <span class="small text-body-secondary" data-id="hilos-log-count">
          {{ entryCount() }} entries shown
        </span>
        @if (following()) {
          <span
            class="ms-auto badge text-bg-success-subtle text-success-emphasis border border-success-subtle"
            data-id="hilos-log-tail-badge"
          >
            <i class="bi bi-broadcast me-1" aria-hidden="true"></i>Tail is
            running
          </span>
        }
      </div>

      <div class="position-relative">
        <div
          #pane
          class="border rounded-3 bg-body-tertiary py-2 overflow-auto"
          style="max-height: 26rem; white-space: pre-wrap"
          data-id="hilos-log-pane"
          (scroll)="onScroll()"
        >
          @if (refusal(); as text) {
            <p class="px-3 mb-0 small text-danger" data-id="hilos-log-refusal">
              {{ text }}
            </p>
          } @else if (paneState() === 'unknown') {
            <p
              class="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-unknown"
            >
              The cluster picture has not arrived yet, so there is nothing to
              choose between — not nothing to read.
            </p>
          } @else if (paneState() === 'unreadable') {
            <p
              class="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-unreadable"
            >
              No node could read its log store.
            </p>
          } @else if (paneState() === 'empty') {
            <p
              class="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-catalog"
            >
              No streams have been reported yet.
            </p>
          } @else if (paneState() === 'unchosen') {
            <p
              class="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-unchosen"
            >
              Choose a stream to read.
            </p>
          } @else if (paneState() === 'missing') {
            <p
              class="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-missing"
            >
              This file cannot be read. The rotation may have carried it off, or
              nothing has written to it yet.
            </p>
          } @else if (paneState() === 'nomatch') {
            <p
              class="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-nomatch"
            >
              Nothing in this file matched.
            </p>
          } @else if (paneState() === 'silent') {
            <p
              class="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-silent"
            >
              This file is empty.
            </p>
          } @else {
            @for (row of rows(); track row.key) {
              @if (row.kind === 'notice') {
                <div
                  class="d-flex align-items-center justify-content-center gap-2 px-3 py-1 small text-body-secondary"
                  data-id="hilos-log-notice"
                >
                  <i
                    [class]="'bi ' + noticeIcons[row.notice]"
                    aria-hidden="true"
                  ></i>
                  <span>{{ row.text }}</span>
                </div>
              } @else {
                <div data-id="hilos-log-entry">
                  <div
                    class="d-flex gap-2 px-3 py-1 font-monospace small text-break border-start border-4"
                    [class]="
                      'border-' +
                      levelVariant(row.level) +
                      (row.orphan ? ' opacity-75' : '')
                    "
                  >
                    <span
                      class="fw-semibold text-nowrap"
                      [class]="'text-' + levelVariant(row.level)"
                    >
                      {{ row.level }}
                    </span>
                    <span class="text-body-secondary text-nowrap">{{
                      row.time
                    }}</span>
                    <span class="flex-grow-1">{{ row.text }}</span>
                    @if (row.frames.length > 0) {
                      <button
                        type="button"
                        class="btn btn-sm btn-link p-0 text-decoration-none text-nowrap"
                        [attr.aria-expanded]="isOpen(row)"
                        [attr.aria-label]="
                          'Call stack of this entry, ' +
                          row.frames.length +
                          ' frames'
                        "
                        data-id="hilos-log-stack-toggle"
                        (click)="toggle(row)"
                      >
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i
                        >{{ row.frames.length }}
                      </button>
                    }
                  </div>
                  @if (row.frames.length > 0 && isOpen(row)) {
                    <div class="bg-body" data-id="hilos-log-stack">
                      @for (frame of row.frames; track $index) {
                        <div
                          class="d-flex gap-2 px-3 py-1 font-monospace small text-break opacity-75 border-start border-4"
                          [class]="'border-' + levelVariant(row.level)"
                        >
                          <span class="flex-grow-1">{{ frame.text }}</span>
                        </div>
                      }
                    </div>
                  }
                </div>
              }
            }
          }
        </div>
        @if (!pinned()) {
          <button
            type="button"
            class="btn btn-sm btn-primary position-absolute bottom-0 start-50 translate-middle-x mb-2"
            data-id="hilos-log-back-to-tail"
            (click)="viewer().returnToTail()"
          >
            Back to the tail{{
              pendingLines() > 0 ? ' · ' + pendingLines() + ' new' : ''
            }}
          </button>
        }
      </div>
    </hilos-admin-page>
  `,
})
export class HilosLogsViewPage {
  /** The project context: the connection and the action lifecycle. */
  readonly context = input.required<HilosLogViewerContext>()

  protected readonly page = HilosPages.LOGS_VIEW
  protected readonly live = LOG_SOURCE_LIVE
  protected readonly levelOptions = HILOS_LOG_LEVEL_OPTIONS
  protected readonly noticeIcons = NOTICE_ICONS
  protected readonly levelVariant = logLevelVariant

  private readonly router = inject(HILOS_ROUTER, { optional: true })

  // The address IS what this screen is showing, so the navigator is what it reads
  // and writes. A router-less mount (none in practice) still reads files — it just
  // keeps the choice out of the location bar.
  private readonly address: HilosLogViewerAddress = this.router ?? {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_VIEW,
      params: {},
      admin: true,
    }),
    replacePath: () => {},
  }

  protected readonly viewer = computed(() =>
    createHilosLogViewer(this.context(), this.address),
  )

  // The pane is the scrolling element, so it is the one whose position answers
  // "is the reader at the tail" — the threshold itself is the headless's, so that
  // the Vue and React ports do not each invent their own.
  private readonly pane = viewChild<ElementRef<HTMLElement>>('pane')

  // Everything the pane draws, mirrored from the (per-context) core signals into
  // Angular signals so the template re-renders on every read and every pushed line.
  protected readonly catalog = signal<HilosLogViewerCatalog | null>(null)
  protected readonly selection = signal<HilosLogViewerSelection>(
    readLogViewerAddress(this.address.currentRoute.get().params),
  )
  protected readonly rows = signal<readonly HilosLogViewerRow[]>([])
  protected readonly level = signal('')
  protected readonly substring = signal('')
  protected readonly busy = signal(false)
  protected readonly readable = signal(true)
  protected readonly hasMore = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly followRequested = signal(false)
  protected readonly following = signal(false)
  protected readonly canFollow = signal(false)
  protected readonly pinned = signal(true)
  protected readonly pendingLines = signal(0)

  // The node select exists only where nodes have names: a picker with one nameless
  // option is furniture for a choice that does not exist.
  protected readonly clustered = computed(() =>
    hasLogViewerNodes(this.catalog()),
  )
  protected readonly node = computed(() =>
    logViewerNodeOf(this.catalog(), this.selection().nodeId),
  )
  protected readonly streams = computed(() =>
    logViewerStreamsOf(this.node(), this.selection().source),
  )

  // Which state the pane is in — the discrimination is the headless's, because it
  // is the same question in all three view frameworks. The count is of ROWS and
  // not of entries: a file with no lines that has just been rotated has something
  // to say, and "this file is empty" would hide the note saying it.
  protected readonly paneState = computed(() =>
    logViewerPaneState(
      this.catalog(),
      this.selection(),
      this.rows().length,
      this.readable(),
      this.level() !== '' || this.substring() !== '',
    ),
  )

  // The counter beside the Earlier button counts entries, because that is the word
  // it uses; a note is not one.
  protected readonly entryCount = computed(
    () => this.rows().filter((row) => row.kind === 'entry').length,
  )

  // Newest batch first: an operator opening the archive is looking for what just
  // happened far more often than for what happened last month.
  protected readonly batches = computed(() =>
    [...(this.node()?.batches ?? [])].reverse(),
  )

  // Which stacks are open, by entry key. The key survives a page of older lines
  // arriving above, so an opened stack stays open when the pane grows upwards.
  private readonly opened = signal(new Set<string>())

  constructor() {
    // Read the catalog and the first page once the context input is bound, and stop
    // on destroy; the same effect re-binds on a context swap.
    effect((onCleanup) => {
      const viewer = this.viewer()
      viewer.start()
      const unsubscribes = [
        this.mirror(viewer.catalog, this.catalog.set),
        this.mirror(viewer.selection, this.selection.set),
        this.mirror(viewer.rows, this.rows.set),
        this.mirror(viewer.level, this.level.set),
        this.mirror(viewer.substring, this.substring.set),
        this.mirror(viewer.busy, this.busy.set),
        this.mirror(viewer.readable, this.readable.set),
        this.mirror(viewer.hasMore, this.hasMore.set),
        this.mirror(viewer.refusal, this.refusal.set),
        this.mirror(viewer.followRequested, this.followRequested.set),
        this.mirror(viewer.following, this.following.set),
        this.mirror(viewer.canFollow, this.canFollow.set),
        this.mirror(viewer.pinned, this.pinned.set),
        this.mirror(viewer.pendingLines, this.pendingLines.set),
      ]
      onCleanup(() => {
        for (const unsubscribe of unsubscribes) {
          unsubscribe()
        }
        viewer.dispose()
      })
    })

    // Sticking to the bottom happens after the rows are drawn, because the height to
    // scroll to does not exist until then; an ordinary effect would let the browser
    // paint one frame with the old scroll position and the pane would jump on every
    // batch of lines.
    afterRenderEffect(() => {
      this.rows()
      if (!this.pinned()) {
        return
      }
      const element = this.pane()?.nativeElement
      if (element !== undefined) {
        element.scrollTop = element.scrollHeight
      }
    })
  }

  /**
   * Mirror one core signal into its Angular twin: the current value first, because
   * subscribing alone would leave the template on the default until the next frame.
   *
   * @param source The core signal to follow.
   * @param set The Angular signal's setter.
   */
  private mirror<T>(
    source: ReadonlySignal<T>,
    set: (value: T) => void,
  ): Unsubscribe {
    set(source.get())

    return subscribeSignal(source, set)
  }

  /** The value the source select carries for one choice. */
  protected sourceValue(
    source: typeof LOG_SOURCE_LIVE | number | null,
  ): string {
    return source === null ? '' : String(source)
  }

  protected batchLabel(batch: number): string {
    return new Date(batch * 1000).toLocaleString()
  }

  protected onNode(event: Event): void {
    this.viewer().select({ nodeId: (event.target as HTMLSelectElement).value })
  }

  protected onSource(event: Event): void {
    const value = (event.target as HTMLSelectElement).value
    this.viewer().select({
      source: value === LOG_SOURCE_LIVE ? LOG_SOURCE_LIVE : Number(value),
    })
  }

  protected onStream(event: Event): void {
    const value = (event.target as HTMLSelectElement).value
    this.viewer().select({ stream: value === '' ? null : value })
  }

  protected onLevel(event: Event): void {
    this.viewer().setLevel((event.target as HTMLSelectElement).value)
  }

  protected onSubstring(event: Event): void {
    this.viewer().setSubstring((event.target as HTMLInputElement).value)
  }

  protected onFollow(event: Event): void {
    this.viewer().setFollow((event.target as HTMLInputElement).checked)
  }

  protected onScroll(): void {
    const element = this.pane()?.nativeElement
    if (element === undefined) {
      return
    }

    this.viewer().setPinned(
      isLogViewerPinned(
        element.scrollTop,
        element.scrollHeight,
        element.clientHeight,
      ),
    )
  }

  protected isOpen(entry: HilosLogViewerEntry): boolean {
    return this.opened().has(entry.key)
  }

  protected toggle(entry: HilosLogViewerEntry): void {
    const next = new Set(this.opened())
    if (!next.delete(entry.key)) {
      next.add(entry.key)
    }
    this.opened.set(next)
  }
}
