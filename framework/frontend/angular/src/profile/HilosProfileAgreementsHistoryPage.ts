import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosLegalAgreementsStore,
  createHilosLegalRevisionReader,
  describeHilosLegalRevision,
  formatHilosLegalAcceptanceDate,
  formatHilosLegalDate,
  hilosLegalDocumentLabel,
  hilosLegalRevisionHistory,
  subscribeSignal,
  type HilosLegalAgreementsState,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
  type HilosLegalRevisionDialog,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { HilosLegalChanges } from '../legal/HilosLegalChanges.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'

type LegalHistory = ReturnType<
  ReturnType<typeof hilosLegalRevisionHistory>['get']
>

/** Declared revisions and lazy read-only dialogs. */
@Component({
  selector: 'hilos-profile-agreements-history-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosFormError,
    HilosModal,
    HilosPageHeading,
    HilosLegalChanges,
    HilosLegalRevisionText,
  ],
  template: `
    <div data-id="profile-agreements-history-view">
      <hilos-page-heading />
      @if (state()) {
        @if (history().length === 0) {
          <p class="text-body-secondary">
            This project publishes no legal documents
          </p>
        }
        @for (document of history(); track document.document) {
          <section
            class="mb-4"
            data-id="legal-history-document"
            [attr.data-document]="document.document"
          >
            <h2 class="h6 text-uppercase text-body-secondary mb-2">
              {{ documentLabel(document.document) }}
            </h2>
            @for (
              revision of document.revisions.slice().reverse();
              track revision.revisionId
            ) {
              <div
                class="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
                data-id="legal-history-revision"
                [attr.data-revision]="revision.revisionId"
              >
                <i
                  class="bi bi-file-earmark-text fs-5 text-body-secondary"
                  aria-hidden="true"
                ></i>
                <div class="flex-grow-1 text-break">
                  <div class="fw-semibold small">
                    {{ date(revision.publishedOn) }}
                    @if (current(document.document) === revision.revisionId) {
                      <span
                        class="badge text-bg-primary ms-1"
                        data-id="legal-history-current"
                        >current</span
                      >
                    }
                    @if (
                      acceptedAt(document.document, revision.revisionId) !==
                      null
                    ) {
                      <span
                        class="badge text-bg-success ms-1"
                        data-id="legal-history-accepted"
                        >you accepted ·
                        {{
                          acceptanceDate(
                            acceptedAt(document.document, revision.revisionId)!
                          )
                        }}</span
                      >
                    }
                  </div>
                  <div class="small text-body-secondary">
                    {{ description(revision) }}
                  </div>
                </div>
                <div class="d-flex gap-2">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-id="legal-history-open"
                    (click)="
                      reader().open(document.document, revision.revisionId)
                    "
                  >
                    Open
                  </button>
                  @if (revision.origin !== 'first') {
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      data-id="legal-history-compare"
                      (click)="
                        reader().compare(document.document, revision.revisionId)
                      "
                    >
                      Compare
                    </button>
                  }
                </div>
              </div>
            }
          </section>
        }
      } @else {
        <p class="text-body-secondary">Loading revision history…</p>
      }
      <hilos-modal
        [open]="dialog() !== null"
        [title]="title()"
        initialFocus="dialog"
        size="wide"
        (openChange)="closeDialog($event)"
      >
        @if (dialog(); as detail) {
          <div
            [attr.data-id]="
              detail.kind === 'text'
                ? 'legal-revision-text-modal'
                : 'legal-changes-modal'
            "
          >
            <div class="visually-hidden" role="status" aria-live="polite">
              {{ detail.busy ? 'Loading revision…' : '' }}
            </div>
            <p
              class="small text-body-secondary"
              [class.invisible]="!detail.busy"
              data-id="legal-dialog-loading"
              [attr.aria-hidden]="!detail.busy"
            >
              Loading revision…
            </p>
            <hilos-form-error
              [message]="detail.refusal"
              dataId="legal-dialog-refusal"
              [announce]="true"
            />
            @if (detail.text; as text) {
              <hilos-legal-revision-text [clauses]="text.clauses" />
            }
            @if (detail.changes; as changes) {
              <hilos-legal-changes
                [changes]="changes.changes"
                [fromLabel]="date(changes.fromRevisionId)"
                [toLabel]="date(changes.toRevisionId)"
              />
            }
          </div>
        }
        <ng-template #modalActions let-requestClose="requestClose"
          ><button
            type="button"
            class="btn btn-secondary"
            data-id="legal-history-close"
            (click)="requestClose()"
          >
            Close
          </button></ng-template
        >
      </hilos-modal>
    </div>
  `,
})
export class HilosProfileAgreementsHistoryPage {
  readonly context = input.required<HilosLegalContext>()
  protected readonly reader = computed(() =>
    createHilosLegalRevisionReader(this.context()),
  )
  protected readonly state = signal<HilosLegalAgreementsState | null>(null)
  protected readonly history = signal<LegalHistory>([])
  protected readonly dialog = signal<HilosLegalRevisionDialog | null>(null)
  protected readonly documentLabel = hilosLegalDocumentLabel
  protected readonly date = formatHilosLegalDate
  protected readonly acceptanceDate = formatHilosLegalAcceptanceDate
  protected readonly description = describeHilosLegalRevision
  protected readonly title = computed(() => {
    const dialog = this.dialog()
    return dialog
      ? `${hilosLegalDocumentLabel(dialog.document)} — ${dialog.kind === 'text' ? 'revision text' : 'what changed'}`
      : ''
  })

  constructor() {
    effect((onCleanup) => {
      const store = createHilosLegalAgreementsStore(this.context())
      const history = hilosLegalRevisionHistory(this.context())
      const reader = this.reader()
      const stop = store.start()
      this.state.set(store.state.get())
      this.history.set(history.get())
      this.dialog.set(reader.dialog.get())
      const offState = subscribeSignal(store.state, (state) =>
        this.state.set(state),
      )
      const offHistory = subscribeSignal(history, (history) =>
        this.history.set(history),
      )
      const offDialog = subscribeSignal(reader.dialog, (dialog) =>
        this.dialog.set(dialog),
      )
      onCleanup(() => {
        offState()
        offHistory()
        offDialog()
        stop()
        reader.close()
      })
    })
  }

  protected acceptedAt(
    document: HilosLegalDocumentKey,
    revisionId: string,
  ): number | null {
    return (
      this.state()
        ?.documents.find((item) => item.document === document)
        ?.accepted.find((item) => item.revisionId === revisionId)?.acceptedAt ??
      null
    )
  }
  protected current(document: HilosLegalDocumentKey): string | undefined {
    return this.state()?.documents.find((item) => item.document === document)
      ?.current.revisionId
  }
  protected closeDialog(open: boolean): void {
    if (!open) this.reader().close()
  }
}
