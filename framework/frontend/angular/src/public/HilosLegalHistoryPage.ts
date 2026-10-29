import { isPlatformBrowser } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  PLATFORM_ID,
  signal,
} from '@angular/core'
import {
  createHilosLegalHistory,
  LEGAL_HISTORY_PUBLISHED_ON_FIELD,
  LEGAL_HISTORY_ORIGIN_FIELD,
  LEGAL_RECONSENT_COPY,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
  type HilosLegalHistoryRow,
  type HilosLegalRevisionDialog,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { HilosStaticPage } from '../HilosStaticPage.js'
import { HilosTableCell } from '../HilosTableCell.js'
import { HilosViewportTable } from '../HilosViewportTable.js'
import { mirrorHilosSignal } from '../hilosSignal.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'

/** The public document's own table and lazy revision reader. */
@Component({
  selector: 'hilos-legal-history-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosFormError,
    HilosModal,
    HilosStaticPage,
    HilosViewportTable,
    HilosTableCell,
    HilosLegalRevisionText,
  ],
  template: `
    <hilos-static-page
      [title]="terms() ? 'Terms' : 'Privacy'"
      [icon]="terms() ? 'bi-file-earmark-text' : 'bi-shield-lock'"
      [subtitle]="
        terms()
          ? 'The terms of using this project: the revision in force and every one before it.'
          : 'What this project keeps about you: the revision in force, every one before it, and wiping this browser.'
      "
      [navigation]="navigation()"
    >
      <div data-id="public-legal-history" [attr.data-document]="document()">
        <hilos-viewport-table [controller]="history().controller">
          <ng-template [hilosTableCell]="publishedOn" let-row>
            <span class="fw-semibold">{{ row.publishedLabel }}</span>
            @if (row.inForce) {
              <span
                class="badge text-bg-primary ms-1"
                data-id="public-legal-history-current"
                [attr.data-revision]="row.revisionId"
                >{{ copy().inForce }}</span
              >
            }
            @if (row.accepted) {
              <span
                class="badge text-bg-success ms-1"
                data-id="public-legal-history-accepted"
                [attr.data-revision]="row.revisionId"
                >{{ copy().youAccepted }}</span
              >
            }
          </ng-template>
          <ng-template [hilosTableCell]="origin" let-row>
            <span class="text-body-secondary">{{ row.changeLabel }}</span>
          </ng-template>
          <ng-template hilosTableCell="actions" let-row>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              data-id="public-legal-history-open"
              data-hilos-focus-return="public-legal-history-open"
              [attr.data-document]="document()"
              [attr.data-revision]="row.revisionId"
              (click)="open(row)"
            >
              {{ copy().open }}
            </button>
          </ng-template>
        </hilos-viewport-table>
      </div>
      <hilos-modal
        [open]="dialog() !== null"
        [title]="title()"
        initialFocus="dialog"
        size="wide"
        (openChange)="close($event)"
      >
        @if (dialog(); as detail) {
          <div
            data-id="public-legal-history-modal"
            [attr.data-document]="document()"
            [attr.data-revision]="detail.revisionId"
          >
            <div class="visually-hidden" role="status" aria-live="polite">
              {{ detail.busy ? copy().revisionLoading : '' }}
            </div>
            <p
              class="small text-body-secondary"
              [class.invisible]="!detail.busy"
              data-id="legal-dialog-loading"
              [attr.aria-hidden]="!detail.busy"
            >
              {{ copy().revisionLoading }}
            </p>
            <hilos-form-error
              [message]="detail.refusal"
              dataId="legal-dialog-refusal"
              [announce]="true"
            />
            @if (detail.text; as text) {
              <hilos-legal-revision-text [clauses]="text.clauses" />
            }
          </div>
        }
        <ng-template #modalActions let-requestClose="requestClose"
          ><button
            type="button"
            class="btn btn-secondary"
            data-id="public-legal-history-close"
            (click)="requestClose()"
          >
            {{ closeLabel }}
          </button></ng-template
        >
      </hilos-modal>
    </hilos-static-page>
  `,
})
export class HilosLegalHistoryPage {
  readonly context = input.required<HilosLegalContext>()
  readonly document = input.required<HilosLegalDocumentKey>()
  readonly navigation = input<HilosStaticPageNavigation>()
  private readonly platformId = inject(PLATFORM_ID)
  protected readonly history = computed(() =>
    createHilosLegalHistory(this.context(), this.document()),
  )
  protected readonly terms = computed(() => this.document() === 'terms')
  protected readonly copy = computed(() => this.history().copy)
  protected readonly publishedOn = LEGAL_HISTORY_PUBLISHED_ON_FIELD
  protected readonly origin = LEGAL_HISTORY_ORIGIN_FIELD
  protected readonly closeLabel = LEGAL_RECONSENT_COPY.close
  protected readonly title = signal('')
  protected readonly dialog = signal<HilosLegalRevisionDialog | null>(null)

  constructor() {
    effect((onCleanup) => {
      if (!isPlatformBrowser(this.platformId)) return
      const history = this.history()
      const off = mirrorHilosSignal(history.reader.dialog, this.dialog)
      const stop = history.start()
      onCleanup(() => {
        off()
        stop()
      })
    })
  }

  protected open(row: HilosLegalHistoryRow): void {
    this.title.set(row.revisionTitle)
    void this.history().reader.open(row.revisionId)
  }

  protected close(shown: boolean): void {
    if (!shown) this.history().reader.close()
  }
}
