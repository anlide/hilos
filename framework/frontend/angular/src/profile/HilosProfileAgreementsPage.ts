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
  describeHilosLegalAgreement,
  formatHilosLegalDate,
  hilosLegalDocumentLabel,
  HILOS_PAGE_ROUTES,
  HilosPages,
  subscribeSignal,
  type HilosLegalAgreement,
  type HilosLegalAgreementsState,
  type HilosLegalAgreementTexts,
  type HilosLegalChange,
  type HilosLegalClause,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosLink } from '../HilosLink.js'
import { HilosModal } from '../HilosModal.js'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { HilosLegalChanges } from '../legal/HilosLegalChanges.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'

/** Personal agreement coverage with read-only text and comparison dialogs. */
@Component({
  selector: 'hilos-profile-agreements-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosLink,
    HilosModal,
    HilosPageHeading,
    HilosLegalChanges,
    HilosLegalRevisionText,
  ],
  template: `
    <div data-id="profile-agreements-view">
      <hilos-page-heading />
      @if (state() && texts()) {
        <p class="small text-body-secondary">
          These are the revisions you accepted. The current text may have
          changed since then.
        </p>
        @if (rows().length === 0) {
          <p class="text-body-secondary">
            This project publishes no legal documents
          </p>
        }
        @for (row of rows(); track row.agreement.document) {
          <div
            class="py-3 border-bottom"
            data-id="legal-agreement-row"
            [attr.data-document]="row.agreement.document"
          >
            <div class="d-flex align-items-center gap-3">
              <i
                [class]="
                  'bi fs-5 text-body-secondary ' +
                  (row.agreement.document === 'terms'
                    ? 'bi-file-earmark-check'
                    : 'bi-shield-check')
                "
                aria-hidden="true"
              ></i>
              <div class="flex-grow-1 text-break">
                <h2 class="h6 mb-1">
                  {{ documentLabel(row.agreement.document) }}
                </h2>
                <div class="small" data-id="legal-agreement-state">
                  {{ row.summary }}
                </div>
                <div class="small text-body-secondary">{{ row.standard }}</div>
              </div>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-id="legal-agreement-open"
                (click)="openText(row.agreement)"
              >
                Open
              </button>
            </div>
            @if (row.notice) {
              <div
                [class]="
                  'alert small py-2 mt-3 mb-0 ' +
                  (row.agreement.standing === 'covered'
                    ? 'alert-secondary'
                    : 'alert-warning')
                "
                data-id="legal-agreement-notice"
              >
                {{ row.notice }}
                @if (row.canCompare) {
                  <button
                    type="button"
                    class="btn btn-link btn-sm p-0 ms-1 align-baseline"
                    data-id="legal-agreement-changes-open"
                    (click)="openChanges(row.agreement)"
                  >
                    What changed
                  </button>
                }
              </div>
            }
          </div>
        }
        <div class="d-flex align-items-center gap-3 py-3 border-bottom mt-3">
          <i
            class="bi bi-clock-history fs-5 text-body-secondary"
            aria-hidden="true"
          ></i>
          <div class="flex-grow-1">
            <h2 class="h6 mb-0">Revision history</h2>
          </div>
          <a
            [hilosLink]="historyPath"
            class="btn btn-sm btn-outline-secondary"
            data-id="profile-agreements-history-open"
            >Open</a
          >
        </div>
      } @else {
        <p class="text-body-secondary">Loading agreements…</p>
      }
      <hilos-modal
        [open]="textDialog() !== null"
        [title]="textDialog()?.title ?? ''"
        initialFocus="dialog"
        size="wide"
        (openChange)="textDialog.set($event ? textDialog() : null)"
      >
        @if (textDialog(); as text) {
          <div data-id="legal-revision-text-modal">
            <hilos-legal-revision-text [clauses]="text.clauses" />
          </div>
        }
        <ng-template #modalActions let-requestClose="requestClose"
          ><button
            type="button"
            class="btn btn-secondary"
            data-id="legal-text-close"
            (click)="requestClose()"
          >
            Close
          </button></ng-template
        >
      </hilos-modal>
      <hilos-modal
        [open]="changesDialog() !== null"
        [title]="changesDialog()?.title ?? ''"
        initialFocus="dialog"
        size="wide"
        (openChange)="changesDialog.set($event ? changesDialog() : null)"
      >
        @if (changesDialog(); as diff) {
          <div data-id="legal-changes-modal">
            <hilos-legal-changes
              [changes]="diff.changes"
              [fromLabel]="diff.from"
              [toLabel]="diff.to"
            />
          </div>
        }
        <ng-template #modalActions let-requestClose="requestClose"
          ><button
            type="button"
            class="btn btn-secondary"
            data-id="legal-changes-close"
            (click)="requestClose()"
          >
            Close
          </button></ng-template
        >
      </hilos-modal>
    </div>
  `,
})
export class HilosProfileAgreementsPage {
  readonly context = input.required<HilosLegalContext>()
  protected readonly state = signal<HilosLegalAgreementsState | null>(null)
  protected readonly texts = signal<HilosLegalAgreementTexts | null>(null)
  protected readonly rows = computed(
    () =>
      this.state()?.documents.map((agreement) => ({
        agreement,
        ...describeHilosLegalAgreement(agreement),
      })) ?? [],
  )
  protected readonly textDialog = signal<{
    title: string
    clauses: HilosLegalClause[]
  } | null>(null)
  protected readonly changesDialog = signal<{
    title: string
    changes: HilosLegalChange[]
    from: string
    to: string
  } | null>(null)
  protected readonly documentLabel = hilosLegalDocumentLabel
  protected readonly historyPath =
    HILOS_PAGE_ROUTES[HilosPages.PROFILE_AGREEMENTS_HISTORY]!

  constructor() {
    effect((onCleanup) => {
      const store = createHilosLegalAgreementsStore(this.context())
      const stop = store.start()
      this.state.set(store.state.get())
      this.texts.set(store.texts.get())
      const offState = subscribeSignal(store.state, (state) =>
        this.state.set(state),
      )
      const offTexts = subscribeSignal(store.texts, (texts) =>
        this.texts.set(texts),
      )
      onCleanup(() => {
        offState()
        offTexts()
        stop()
      })
    })
  }

  protected openText(agreement: HilosLegalAgreement): void {
    const text = this.texts()?.documents.find(
      (entry) => entry.document === agreement.document,
    )
    if (!text) return
    const held = agreement.held
    this.textDialog.set({
      title: `${hilosLegalDocumentLabel(agreement.document)} · ${formatHilosLegalDate((held ?? agreement.current).publishedOn)}`,
      clauses:
        held && held.revisionId !== agreement.current.revisionId
          ? (text.held ?? text.current)
          : text.current,
    })
  }
  protected openChanges(agreement: HilosLegalAgreement): void {
    const text = this.texts()?.documents.find(
      (entry) => entry.document === agreement.document,
    )
    if (!text || !agreement.held) return
    this.changesDialog.set({
      title: `${hilosLegalDocumentLabel(agreement.document)} — what changed`,
      changes: text.changes,
      from: formatHilosLegalDate(agreement.held.publishedOn),
      to: formatHilosLegalDate(agreement.current.publishedOn),
    })
  }
}
