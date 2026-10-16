// HilosChangeLogReceiptModal — the local read-only detail surface of the Change
// Log section. The core reader owns requests, snapshots, attribution, value
// sides and pagination; HilosModal owns the keyboard and focus. Hide's
// focus/scroll placement is the only DOM effect this view adds, and it waits for
// the collapsed portion to render (afterNextRender), which Vue writes as
// nextTick. A record whose key is not masked links to the Tracked table page
// once the router gives that page an address; following it closes the dialog.
// Not exported from the package root: the page that opens it owns its reader.
// Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  computed,
  inject,
  input,
  viewChild,
} from '@angular/core'
import {
  CHANGE_LOG_RECEIPT_COPY,
  CHANGE_LOG_RECORD_ROUTE_PARAM,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  changeLogEntryDetails,
  changeLogReceiptAttribution,
  changeLogReceiptPagination,
  changeLogRecordRouteParam,
  formatChangeLogReceiptTitle,
  formatChangeLogTime,
  HilosPages,
  isHiddenValue,
  type HilosChangeLogEntry,
  type HilosChangeLogReceiptDialog,
  type HilosChangeLogReceiptReader,
} from '@hilos/core'

import { HilosFormError } from '../../HilosFormError.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosLongText } from '../../HilosLongText.js'
import { HilosModal } from '../../HilosModal.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'

/** The read-only receipt dialog over the page's one shared reader. */
@Component({
  selector: 'hilos-change-log-receipt-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosFormError,
    HilosHiddenMark,
    HilosLink,
    HilosLongText,
    HilosModal,
  ],
  template: `
    @let current = dialog();
    <hilos-modal
      [open]="current !== null"
      (openChange)="!$event && reader().close()"
      [title]="title()"
      size="wide"
      initialFocus="dialog"
    >
      <div data-id="hilos-change-log-detail">
        <div class="visually-hidden" role="status" aria-live="polite">
          {{
            current?.busyInitial
              ? copy.loading
              : current?.busyMore
                ? copy.loadingMore
                : ''
          }}
        </div>
        <hilos-form-error
          [message]="current?.refusal ?? null"
          dataId="hilos-change-log-detail-error"
          [announce]="true"
        />
        <div class="mb-2">
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            [class.invisible]="!current?.refusal"
            [disabled]="
              !current?.refusal || current.busyInitial || current.busyMore
            "
            [attr.aria-hidden]="current?.refusal ? null : true"
            [attr.tabindex]="current?.refusal ? 0 : -1"
            data-id="hilos-change-log-retry"
            (click)="reader().retry()"
          >
            {{ copy.retry }}
          </button>
        </div>
        @if (current?.busyInitial) {
          <p class="text-body-secondary">{{ copy.loading }}</p>
        }
        @if (attribution(); as shown) {
          <dl class="row mb-2">
            <dt class="col-sm-4">{{ copy.when }}</dt>
            <dd class="col-sm-8">{{ shown.when }}</dd>
            <dt class="col-sm-4">{{ copy.who }}</dt>
            <dd class="col-sm-8 text-break">
              @if (isHidden(shown.actor.label)) {
                <hilos-hidden-mark /> (#{{ shown.actor.id }})
              } @else {
                {{ shown.actor.label }}
              }
              @if (shown.actor.explanation) {
                <div class="small text-body-secondary">
                  {{ shown.actor.explanation }}
                </div>
              }
            </dd>
            @if (shown.subject; as subject) {
              <dt class="col-sm-4">{{ copy.onBehalfOf }}</dt>
              <dd class="col-sm-8 text-break">
                @if (isHidden(subject.label)) {
                  <hilos-hidden-mark /> (#{{ subject.id }})
                } @else {
                  {{ subject.label }}
                }
                @if (subject.explanation) {
                  <div class="small text-body-secondary">
                    {{ subject.explanation }}
                  </div>
                }
                <div class="small text-body-secondary">
                  {{ copy.impersonation }}
                  @if (isHidden(shown.actor.label)) {
                    <hilos-hidden-mark /> (#{{ shown.actor.id }})
                  } @else {
                    {{ shown.actor.label }}
                  }
                </div>
              </dd>
            }
            <dt class="col-sm-4">{{ copy.channel }}</dt>
            <dd class="col-sm-8">
              @if (shown.channel; as channel) {
                <span class="badge bg-body-secondary text-body border">
                  <i
                    [class]="'bi ' + channel.icon + ' me-1'"
                    aria-hidden="true"
                  ></i>
                  {{ channel.label }}
                </span>
              } @else {
                {{ copy.unknown }}
              }
            </dd>
            <dt class="col-sm-4">{{ copy.source }}</dt>
            <dd class="col-sm-8">
              <code class="text-break">{{ shown.source }}</code>
            </dd>
            <dt class="col-sm-4">{{ copy.action }}</dt>
            <dd class="col-sm-8">
              <code class="text-break">{{ shown.action }}</code>
            </dd>
            <dt class="col-sm-4">{{ copy.agent }}</dt>
            <dd class="col-sm-8">
              <code class="text-break">{{ shown.agent }}</code>
            </dd>
          </dl>
        } @else if (current?.kind === 'entry' && current.entries.length) {
          <dl class="row mb-2">
            <dt class="col-sm-4">{{ copy.when }}</dt>
            <dd class="col-sm-8">
              {{ time(current.entries[0]!.createdAt) }}
            </dd>
            <dt class="col-sm-4">{{ copy.who }}</dt>
            <dd class="col-sm-8">
              {{ copy.unknownPerson }}
              <div class="small text-body-secondary">{{ copy.noReceipt }}</div>
            </dd>
          </dl>
        }

        @if (entries().length) {
          <section>
            <h2
              #changesHeading
              class="h6 mb-2"
              tabindex="-1"
              data-id="hilos-change-log-changes-heading"
            >
              {{ copy.whatChanged }}
            </h2>
            @for (entry of entries(); track entry.entryId) {
              <div
                class="border rounded-2 p-2 mb-2"
                [attr.data-id]="'hilos-change-log-entry-' + entry.entryId"
              >
                <h3 class="h6 d-flex flex-wrap align-items-center gap-2 mb-2">
                  @if (entry.recordPath !== undefined) {
                    <a
                      [hilosLink]="entry.recordPath"
                      [attr.data-id]="
                        'hilos-change-log-receipt-record-' + entry.entryId
                      "
                      (click)="reader().close()"
                      ><code class="text-break">{{
                        entry.details.record
                      }}</code></a
                    >
                  } @else {
                    <code class="text-break">{{ entry.details.record }}</code>
                  }
                  <span class="badge bg-body-secondary text-body border">{{
                    entry.details.mutation
                  }}</span>
                </h3>
                @if (entry.details.explanation) {
                  <p class="small text-body-secondary mb-1">
                    {{ entry.details.explanation }}
                  </p>
                }
                <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
                  @for (field of entry.details.fields; track field.field) {
                    <li>
                      <code class="text-break">{{ field.field }}</code>
                      @if (field.kind === 'fact') {
                        <span
                          class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1 text-wrap"
                        >
                          {{ field.oldValue.text }}
                        </span>
                      } @else {
                        <div class="row g-2 mt-1">
                          <div [class]="field.newValue ? 'col-sm-6' : 'col-12'">
                            <div class="small text-body-secondary mb-1">
                              {{ copy.oldValue }}
                            </div>
                            <!-- The tail is grouped so the name does not end in a
                            quote: SHELL-PARITY reads an expression that starts and
                            ends with one as a single literal, and would miss the
                            hilos-change-log-value- prefix. -->
                            <hilos-long-text
                              kind="output"
                              [text]="field.oldValue.text"
                              [dataId]="
                                'hilos-change-log-value-' +
                                (entry.entryId + '-' + field.field + '-old')
                              "
                            />
                          </div>
                          @if (field.newValue; as newValue) {
                            <div class="col-sm-6">
                              <div class="small text-body-secondary mb-1">
                                {{ copy.newValue }}
                              </div>
                              <hilos-long-text
                                kind="output"
                                [text]="newValue.text"
                                [dataId]="
                                  'hilos-change-log-value-' +
                                  (entry.entryId + '-' + field.field + '-new')
                                "
                              />
                            </div>
                          }
                        </div>
                      }
                    </li>
                  }
                </ul>
              </div>
            }
            @if (pagination(); as portion) {
              <div class="mt-3">
                <div
                  class="small text-body-secondary mb-2"
                  data-id="hilos-change-log-detail-count"
                >
                  {{ portion.count }}
                </div>
                <div class="d-flex flex-wrap gap-2">
                  @if (portion.showMore) {
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      [disabled]="current?.busyMore"
                      [attr.aria-busy]="current?.busyMore"
                      data-id="hilos-change-log-more"
                      (click)="reader().more()"
                    >
                      {{ copy.showMore }}
                    </button>
                  }
                  @if (portion.showHide) {
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      data-id="hilos-change-log-hide"
                      (click)="hide()"
                    >
                      {{ copy.hide }}
                    </button>
                  }
                </div>
              </div>
            }
          </section>
        }
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-primary"
          data-id="hilos-change-log-detail-close"
          (click)="requestClose()"
        >
          {{ copy.close }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosChangeLogReceiptModal {
  /** The current lazy snapshot, or null when closed. */
  readonly dialog = input.required<HilosChangeLogReceiptDialog | null>()
  /** Intents of the page's one shared receipt reader. */
  readonly reader = input.required<HilosChangeLogReceiptReader>()

  protected readonly copy = CHANGE_LOG_RECEIPT_COPY
  protected readonly isHidden = isHiddenValue
  protected readonly time = formatChangeLogTime

  // The navigator the record links resolve through; without one a record stays
  // text, as it does in Vue.
  private readonly router = inject(HILOS_ROUTER, { optional: true })
  private readonly injector = inject(Injector)
  private readonly changesHeading =
    viewChild<ElementRef<HTMLElement>>('changesHeading')

  protected readonly title = computed(() =>
    formatChangeLogReceiptTitle(this.dialog()),
  )
  protected readonly attribution = computed(() => {
    const header = this.dialog()?.header
    return header ? changeLogReceiptAttribution(header) : null
  })
  protected readonly entries = computed(() =>
    (this.dialog()?.entries ?? []).map((entry) => ({
      entryId: entry.entryId,
      details: changeLogEntryDetails(entry),
      recordPath: this.recordPath(entry),
    })),
  )
  protected readonly pagination = computed(() => {
    const dialog = this.dialog()
    return dialog ? changeLogReceiptPagination(dialog) : null
  })

  /** Restore the first portion, then return to the beginning of What changed. */
  protected hide(): void {
    this.reader().collapse()
    // The heading is placed once the collapsed portion is on screen.
    afterNextRender(
      () => {
        const heading = this.changesHeading()?.nativeElement
        heading?.focus({ preventScroll: true })
        heading?.scrollIntoView({ block: 'start' })
      },
      { injector: this.injector },
    )
  }

  /**
   * Address the Tracked table page narrowed to the entry's record, when that page
   * is built and no part of the key is masked.
   *
   * @param entry Journal entry of the open receipt.
   */
  private recordPath(entry: HilosChangeLogEntry): string | undefined {
    const key = entry.recordKey
    const parts = (key ?? []).filter(
      (part): part is number | string => part !== null,
    )
    if (key === null || parts.length === 0 || parts.length !== key.length) {
      return undefined
    }
    return this.router?.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
      [CHANGE_LOG_TABLE_ROUTE_PARAM]: entry.table,
      [CHANGE_LOG_RECORD_ROUTE_PARAM]: changeLogRecordRouteParam(parts),
    })
  }
}
