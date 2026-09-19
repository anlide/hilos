// HilosTableSelection — the panel that stands in place of the bar's controls
// while rows are marked: how many are marked, the way to take the whole filtered
// set, the operations the page declared, and the way to drop the marks. Under them
// stands the bar of the run those operations start and the report it ends with —
// the third place work is drawn in, and the one that belongs to the framework
// entirely (table-subscription.md, "Selection and bulk actions"). It holds NO rule
// about the marks: which rows they are, what the header checkbox shows and how a
// deleted row leaves the selection are all the controller's, and every control here
// is a call into it (multiframework-core.md). What it does hold is what only the
// view can know — which operation a confirmation is about, and which run the bar
// above the track is named after. Internal to the Angular view layer on purpose: it
// is not exported from index.ts, for the reason the bar and the footer are not.
// It is MOUNTED for the whole life of a table that declared bulk operations and only
// SHOWS itself while one of the three counts holds. Its confirmation is a HilosModal,
// and a dialog owns the page's scroll lock: it takes the lock when it opens, and a
// closed one releases it the moment it mounts, whosever lock that was. A panel that
// came and went with what the server sends — a report arriving, a window arriving with
// none of the marked rows left — would pass that lock around on nobody's behalf, and
// would take an open dialog off the screen with it. The Angular port of the Vue
// reference (vue/src/HilosTableSelection.vue), under the same names and words.
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal,
} from '@angular/core'
import type { TemplateRef, WritableSignal } from '@angular/core'
import { subscribeSignal } from '@hilos/core'
import type {
  HilosTableBulkAction,
  HilosTableBulkReport,
  HilosTableProgress as HilosTableProgressState,
  HilosTableSelectionTarget,
  ReadonlySignal,
  TableViewportController,
} from '@hilos/core'

import { HilosActionError } from './HilosActionError.js'
import { HilosModal } from './HilosModal.js'
import { HilosTableProgress } from './HilosTableProgress.js'
import type { BulkUntouchedContext } from './HilosViewportTable.js'
import { LoadingButton } from './LoadingButton.js'
import { createHilosTrackedAction } from './hilosTrackedAction.js'

/** The accessible name of the track, and the caption of a bar we did not start. */
const BAR_NAME = 'Working on the marked rows'

/** The selection panel of a table that declared bulk operations. */
@Component({
  selector: 'hilos-table-selection',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosModal,
    HilosTableProgress,
    LoadingButton,
    NgTemplateOutlet,
  ],
  template: `
    @if (shown()) {
      <div
        class="d-flex flex-column gap-2 mb-2 p-2 rounded border bg-body-tertiary"
        role="group"
        aria-label="Selection"
        data-id="hilos-table-selection"
      >
        <div class="d-flex align-items-center flex-wrap gap-2">
          <span class="small fw-medium" data-id="hilos-table-selection-count">
            {{ countLabel() }}
          </span>

          <!-- Gone in the choice by condition: there it is already pressed, and a
          second press would change nothing (Flow F3). -->
          @if (!byFilter()) {
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              data-id="hilos-table-select-all-filtered"
              (click)="controller().selectAllByFilter()"
            >
              Select all matching the filter
            </button>
          }

          <!-- While a run is going the server refuses a second one on this table,
          and a button whose only outcome is a refusal is not a button. It is a
          courtesy and not a guarantee: a refusal that arrives anyway is still
          shown (Flow F5). -->
          @for (action of bulkActions(); track action.key) {
            <button
              type="button"
              class="btn btn-sm"
              [class.btn-outline-danger]="action.danger === true"
              [class.btn-outline-secondary]="action.danger !== true"
              [class.ms-auto]="action.key === pinnedKey()"
              [disabled]="bulkProgress() !== null"
              [attr.data-id]="'hilos-table-bulk-' + action.key"
              (click)="openConfirm(action)"
            >
              {{ action.label }}
            </button>
          }

          <button
            type="button"
            class="btn btn-sm btn-link text-decoration-none"
            data-id="hilos-table-selection-clear"
            (click)="controller().clearSelection()"
          >
            Clear
          </button>
        </div>

        <!-- The bar of the run: the framework gives the room, the track comes
        ready from the one component both other bars are drawn with, and the line
        above it names the operation while that is honest (Flow F9). -->
        @if (bulkProgress(); as progress) {
          <div data-id="hilos-table-progress-bulk">
            <div class="small mb-1">{{ barCaption() }}</div>
            <hilos-table-progress [progress]="progress" [label]="barName" />
          </div>
        }

        <!-- The outcome, announced as it appears: it comes from the server rather
        than from a press, so waiting for the reader to find it with their eyes
        would be waiting for nothing (Flow F13). -->
        @if (report(); as report) {
          <div
            class="alert py-2 px-3 small mb-0"
            [class.alert-warning]="untouchedCount() > 0"
            [class.alert-success]="untouchedCount() === 0"
            role="status"
            data-id="hilos-table-bulk-report"
          >
            <div class="d-flex align-items-start gap-2">
              <span class="fw-semibold flex-grow-1">{{ reportTitle() }}</span>
              <button
                type="button"
                class="btn-close"
                aria-label="Dismiss"
                data-id="hilos-table-bulk-report-close"
                (click)="dismiss.emit(report.progressKey)"
              ></button>
            </div>
            @if (untouchedCount() > 0) {
              <ul class="list-unstyled mb-0 mt-1">
                @for (row of report.untouched; track row.rowKey) {
                  <li>
                    <strong>
                      @if (bulkUntouched(); as template) {
                        <ng-container
                          [ngTemplateOutlet]="template"
                          [ngTemplateOutletContext]="{
                            $implicit: row.rowKey,
                            reason: row.reason,
                          }"
                        />
                      } @else {
                        {{ row.rowKey }}
                      }
                    </strong>
                    — {{ row.reason }}
                  </li>
                }
                <!-- The names that did not fit under the server's ceiling, as a
                number: a run that omitted names says so rather than quietly
                showing a shorter list (Flow F11). -->
                @if (report.untouchedOmitted > 0) {
                  <li>and {{ report.untouchedOmitted }} more</li>
                }
              </ul>
            }
          </div>
        }
      </div>
    }

    <!-- Outside the block above on purpose: the dialog outlives the strip, so that
    a panel going away under the reader cannot take an open dialog — and the
    page's scroll lock — with it. -->
    <hilos-modal
      [(open)]="confirmOpen"
      [title]="confirmAction()?.label ?? ''"
      initialFocus="dialog"
    >
      <hilos-action-error [action]="bulkTracked" />
      <p class="mb-0">{{ confirmBody() }}</p>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="bulkTracked.busy()"
          (click)="requestClose()"
        >
          Cancel
        </button>
        <button
          hilosLoadingButton
          [class.btn-danger]="confirmAction()?.danger === true"
          [class.btn-primary]="confirmAction()?.danger !== true"
          [loading]="bulkTracked.loading()"
          [disabled]="bulkTracked.busy()"
          data-id="hilos-table-bulk-confirm"
          (click)="submitBulk()"
        >
          {{ confirmAction()?.label }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosTableSelection<R> {
  /** The headless server-windowed controller the panel reads and drives. */
  readonly controller = input.required<TableViewportController<R>>()
  /**
   * Whether the panel stands in place of the bar's controls. The bar works it out,
   * because the same answer decides which of the two strips it draws.
   */
  readonly shown = input(false)
  /**
   * The report to draw, or null when none stands. The bar above resolves what a
   * reader dismissed, because the same answer decides whether this panel is on
   * screen at all — one rule, read in one place.
   */
  readonly report = input<HilosTableBulkReport | null>(null)
  /**
   * The human name of one row a run left untouched; the key is printed where the
   * page gives nothing, because the row itself has left the window by then.
   */
  readonly bulkUntouched = input<
    TemplateRef<BulkUntouchedContext> | undefined
  >()
  /** The reader dismissed the report of the run under this key. */
  readonly dismiss = output<string>()

  protected readonly barName = BAR_NAME

  // The declaration does not change over the life of a table, so its operations are
  // read off it rather than mirrored as a signal (tableFrame.ts,
  // HilosTableFrameState); they follow only the controller input itself.
  protected readonly bulkActions = computed(
    () => this.controller().frame.declaration?.bulkActions ?? [],
  )

  private readonly target = signal<HilosTableSelectionTarget | null>(null)
  private readonly count = signal(0)
  protected readonly bulkProgress = signal<HilosTableProgressState | null>(null)

  // The choice by condition says words and not a number: the size of a large set is
  // a ceiling rather than a count, so "12 480 marked" would be a lie, while what is
  // on this page is the truth about what is on screen (Flow F3).
  protected readonly byFilter = computed(() => this.target()?.kind === 'filter')
  protected readonly countLabel = computed(() =>
    this.byFilter()
      ? 'All rows matching the filter'
      : `${this.count()} marked on this page`,
  )

  // The button the mockup pins to the right, away from the two that only narrow or
  // drop the choice: the destructive operation where the page declared one, and the
  // first operation otherwise — the shape of the row is the same either way.
  protected readonly pinnedKey = computed(() => {
    const actions = this.bulkActions()

    return (actions.find((action) => action.danger === true) ?? actions[0])?.key
  })

  // The run this panel started, remembered as the pair the bar is named by. Naming
  // it from the declaration is honest only while the bar is the very run we asked
  // for: a bar under another key is somebody else's work, and this panel knows
  // nothing about what it is (Flow F9).
  private readonly started = signal<{
    progressKey: string
    label: string
  } | null>(null)

  protected readonly barCaption = computed(() => {
    const progress = this.bulkProgress()
    if (progress === null) {
      return ''
    }
    const ours = this.started()
    if (ours === null || ours.progressKey !== progress.progressKey) {
      return BAR_NAME
    }

    return progress.total === null
      ? ours.label
      : `${ours.label}: ${progress.current} of ${progress.total}`
  })

  // The untouched a report speaks of: the ones it named, and the ones that did not
  // fit under the server's ceiling. Both count towards the headline, because a run
  // that omitted names still left those rows alone (Flow F10).
  protected readonly untouchedCount = computed(() => {
    const report = this.report()

    return report === null
      ? 0
      : report.untouched.length + report.untouchedOmitted
  })

  protected readonly reportTitle = computed(() => {
    const report = this.report()
    if (report === null) {
      return ''
    }
    const changed = `Changed ${report.touched} ${report.touched === 1 ? 'row' : 'rows'}`

    return this.untouchedCount() === 0
      ? changed
      : `${changed}, ${this.untouchedCount()} untouched`
  })

  // Which operation the open confirmation is about. The dialog draws its title and
  // its confirming button from it, so the two cannot say different things.
  protected readonly confirmAction = signal<HilosTableBulkAction | null>(null)
  protected readonly confirmOpen = signal(false)

  protected readonly bulkTracked = createHilosTrackedAction()

  protected readonly confirmBody = computed(() =>
    this.byFilter()
      ? 'Every row matching the current filter will be affected, including rows that are not on this page'
      : `${this.count()} ${this.count() === 1 ? 'row' : 'rows'} on this page will be affected`,
  )

  constructor() {
    // The controller arrives via input (not at construction) and carries core
    // signals, so mirror them into the Angular signals above once it is bound;
    // the cleanup drops the subscriptions if the controller is replaced.
    effect((onCleanup) => {
      const controller = this.controller()
      const bind = <T>(
        source: ReadonlySignal<T>,
        target: WritableSignal<T>,
      ): (() => void) => {
        target.set(source.get())

        return subscribeSignal(source, (value) => target.set(value))
      }
      const subscriptions = [
        bind(controller.selection.target, this.target),
        bind(controller.selection.count, this.count),
        bind(controller.progress.bulk, this.bulkProgress),
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })
  }

  protected openConfirm(action: HilosTableBulkAction): void {
    this.bulkTracked.clearError()
    this.confirmAction.set(action)
    this.confirmOpen.set(true)
  }

  // Authoritative-backend: the reply answers ACCEPTANCE and nothing more — the work
  // itself is watched through the bar and ends in the report — so a success closes
  // the dialog, while a refusal keeps it open with the sentence the page sent back.
  // The target is read HERE rather than when the button was pressed: between the two
  // the reader may have cleared a checkbox (Flow F7).
  protected async submitBulk(): Promise<void> {
    const action = this.confirmAction()
    const runTarget = this.target()
    if (action === null || runTarget === null || this.bulkTracked.busy()) {
      return
    }
    const handle = action.run(runTarget)
    if (!(await this.bulkTracked.run(handle))) {
      return
    }
    const accepted = (await handle.done).reply
    if (accepted !== undefined) {
      this.started.set({
        progressKey: accepted.progressKey,
        label: action.label,
      })
    }
    this.confirmOpen.set(false)
  }
}
