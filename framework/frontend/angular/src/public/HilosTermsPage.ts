// HilosTermsPage — the tier-2 public /terms page (HIL-501): the text of the
// Terms revision in force, read from the project's legal catalog, with the
// project's own prose as an optional introduction (the projected content) above it.
//
// Top to bottom: the heading, the reader's line, the introduction, the text and
// the revision history. The reader's line says where the person stands and,
// while a decision is due, carries the deadline plate, "Accept the new revision"
// and "What changed". Only the Terms are accepted here; the line turns green on
// the server's answer, never ahead of it. The history is everybody's, a guest's
// included: every revision opens through the page's own read.
//
// The store starts only in the browser. Prerendering carries the heading,
// introduction and loading line without live listeners. Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
  inject,
  PLATFORM_ID,
} from '@angular/core'
import { isPlatformBrowser } from '@angular/common'
import {
  createHilosLegalRevisionReader,
  createHilosLegalTermsStore,
  describeHilosLegalRevision,
  describeHilosLegalTermsReader,
  formatHilosLegalDate,
  hilosLegalReconsentPerson,
  LEGAL_RECONSENT_COPY,
  LEGAL_TERMS_COPY,
  sessionAccountStanding,
  subscribeSignal,
  TERMS_REVISION_TEXT_ACTION,
  type HilosAccountStanding,
  type HilosLegalAgreement,
  type HilosLegalContext,
  type HilosLegalHistoryRevision,
  type HilosLegalRevisionDialog,
  type HilosLegalTerms,
  type HilosLegalTermsStore,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { hilosSignal } from '../hilosSignal.js'
import { HilosStaticPage } from '../HilosStaticPage.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosLegalChanges } from '../legal/HilosLegalChanges.js'
import { HilosLegalDocumentReader } from '../legal/HilosLegalDocumentReader.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'

/** The public Terms page: the reader's standing, the text in force and the history. */
@Component({
  selector: 'hilos-terms-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosFormError,
    HilosModal,
    HilosStaticPage,
    LoadingButton,
    HilosLegalChanges,
    HilosLegalDocumentReader,
    HilosLegalRevisionText,
  ],
  template: `
    <hilos-static-page
      title="Terms"
      icon="bi-file-earmark-text"
      subtitle="The terms of using this project: the revision in force and every one before it."
      [navigation]="navigation()"
    >
      @if (line().state !== 'unpublished') {
        <div
          class="mb-4"
          data-id="terms-reader"
          [attr.data-state]="line().state"
        >
          <hilos-legal-document-reader
            [line]="line()"
            [copy]="copy"
            [accepting]="accepting()"
            [refusal]="comparing() ? null : refusal()"
            (accept)="onAccept()"
            (compare)="comparing.set(true)"
          />
        </div>
      }

      <ng-content />

      @if (terms() === undefined) {
        <p class="text-body-secondary" data-id="terms-loading">
          {{ copy.loading }}
        </p>
      } @else if (terms() === null) {
        <p class="text-body-secondary" data-id="terms-none">
          {{ copy.nonePublished }}
        </p>
      } @else if (terms(); as section) {
        <div data-id="terms-text">
          <hilos-legal-revision-text [clauses]="section.clauses" />
        </div>
        <h2
          class="h6 text-uppercase text-body-secondary mb-2 mt-4"
          data-id="terms-history"
        >
          {{ copy.history }}
        </h2>
        @for (revision of history(); track revision.revisionId) {
          <div
            class="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
            data-id="terms-history-revision"
            [attr.data-revision]="revision.revisionId"
          >
            <i
              class="bi bi-file-earmark-text fs-5 text-body-secondary"
              aria-hidden="true"
            ></i>
            <div class="flex-grow-1 text-break">
              <div class="fw-semibold small">
                {{ date(revision.publishedOn) }}
                @if (revision.revisionId === section.current.revisionId) {
                  <span
                    class="badge text-bg-primary ms-1"
                    data-id="terms-history-current"
                    >{{ copy.inForce }}</span
                  >
                }
                @if (accepted().has(revision.revisionId)) {
                  <span
                    class="badge text-bg-success ms-1"
                    data-id="terms-history-accepted"
                    >{{ copy.youAccepted }}</span
                  >
                }
              </div>
              <div class="small text-body-secondary">
                {{ description(revision) }}
              </div>
            </div>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              data-id="terms-history-open"
              (click)="reader().open('terms', revision.revisionId)"
            >
              {{ copy.open }}
            </button>
          </div>
        }
      }

      <hilos-modal
        [open]="dialog() !== null"
        [title]="revisionTitle()"
        initialFocus="dialog"
        size="wide"
        (openChange)="closeRevision($event)"
      >
        @if (dialog(); as detail) {
          <div data-id="terms-revision-modal">
            <div class="visually-hidden" role="status" aria-live="polite">
              {{ detail.busy ? copy.revisionLoading : '' }}
            </div>
            <p
              class="small text-body-secondary"
              [class.invisible]="!detail.busy"
              data-id="legal-dialog-loading"
              [attr.aria-hidden]="!detail.busy"
            >
              {{ copy.revisionLoading }}
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
            data-id="terms-revision-close"
            (click)="requestClose()"
          >
            {{ reconsentCopy.close }}
          </button></ng-template
        >
      </hilos-modal>

      <hilos-modal
        [open]="changes() !== null"
        [busy]="accepting()"
        [title]="changesTitle()"
        initialFocus="dialog"
        size="wide"
        (openChange)="closeChanges($event)"
      >
        @if (changes(); as shown) {
          <div data-id="terms-changes-modal">
            <hilos-legal-changes
              [changes]="shown.changes"
              [fromLabel]="publishedOf(shown.fromRevisionId)"
              [toLabel]="publishedOf(shown.toRevisionId)"
            />
            @if (line().canAccept) {
              <div class="mt-3">
                <hilos-form-error
                  [message]="refusal()"
                  dataId="terms-changes-refusal"
                  [announce]="true"
                />
              </div>
            }
          </div>
        }
        <ng-template #modalActions let-requestClose="requestClose"
          ><button
            type="button"
            class="btn btn-secondary"
            data-id="terms-changes-close"
            [disabled]="accepting()"
            (click)="requestClose()"
          >
            {{ reconsentCopy.close }}
          </button>
          @if (line().canAccept) {
            <button
              hilosLoadingButton
              class="btn-primary"
              data-id="terms-changes-accept"
              [loading]="accepting()"
              (click)="onAccept()"
            >
              {{ reconsentCopy.accept }}
            </button>
          }
        </ng-template>
      </hilos-modal>
    </hilos-static-page>
  `,
})
export class HilosTermsPage {
  private readonly platformId = inject(PLATFORM_ID)
  /** Optional navigation across sibling public pages. */
  readonly navigation = input<HilosStaticPageNavigation>()
  /** The connection, scopes and action lifecycle the page reads and accepts through. */
  readonly context = input.required<HilosLegalContext>()
  protected readonly copy = LEGAL_TERMS_COPY
  protected readonly reconsentCopy = LEGAL_RECONSENT_COPY
  protected readonly date = formatHilosLegalDate
  protected readonly description = describeHilosLegalRevision
  protected readonly store = computed<HilosLegalTermsStore>(() =>
    createHilosLegalTermsStore(this.context()),
  )
  protected readonly reader = computed(() =>
    createHilosLegalRevisionReader(this.context(), {
      textAction: TERMS_REVISION_TEXT_ACTION,
    }),
  )
  protected readonly terms = signal<HilosLegalTerms | null | undefined>(
    undefined,
  )
  protected readonly agreement = signal<HilosLegalAgreement | null | undefined>(
    undefined,
  )
  protected readonly accepting = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly standing = signal<HilosAccountStanding | null>(null)
  protected readonly dialog = signal<HilosLegalRevisionDialog | null>(null)
  protected readonly person = hilosSignal(hilosLegalReconsentPerson)
  /** Whether the comparison of the held revision with the one in force is open. */
  protected readonly comparing = signal(false)

  protected readonly line = computed(() =>
    describeHilosLegalTermsReader(this.terms(), this.agreement(), {
      person: this.person(),
      frozen: this.standing()?.frozen === true,
      now: Date.now(),
    }),
  )
  protected readonly history = computed<HilosLegalHistoryRevision[]>(
    () => this.terms()?.revisions.slice().reverse() ?? [],
  )
  protected readonly accepted = computed(
    () =>
      new Set(
        this.person() === null
          ? []
          : (this.agreement()?.accepted.map((item) => item.revisionId) ?? []),
      ),
  )
  protected readonly changes = computed(() =>
    this.comparing() && this.line().canCompare
      ? (this.terms()?.changes ?? null)
      : null,
  )
  protected readonly changesTitle = computed(() => {
    const changes = this.changes()
    return changes === null
      ? ''
      : this.copy.changesTitle
          .replace('{from}', this.publishedOf(changes.fromRevisionId))
          .replace('{to}', this.publishedOf(changes.toRevisionId))
  })
  protected readonly revisionTitle = computed(() => {
    const dialog = this.dialog()
    return dialog === null
      ? ''
      : this.copy.revisionTitle.replace(
          '{date}',
          this.publishedOf(dialog.revisionId),
        )
  })

  constructor() {
    effect((onCleanup) => {
      if (!isPlatformBrowser(this.platformId)) return
      const store = this.store()
      const reader = this.reader()
      const standing = sessionAccountStanding(this.context().scopes)
      const stop = store.start()
      this.terms.set(store.terms.get())
      this.agreement.set(store.agreement.get())
      this.accepting.set(store.accepting.get())
      this.refusal.set(store.refusal.get())
      this.standing.set(standing.get())
      this.dialog.set(reader.dialog.get())
      const off = [
        subscribeSignal(store.terms, (value) => this.terms.set(value)),
        subscribeSignal(store.agreement, (value) => this.agreement.set(value)),
        subscribeSignal(store.accepting, (value) => this.accepting.set(value)),
        subscribeSignal(store.refusal, (value) => this.refusal.set(value)),
        subscribeSignal(standing, (value) => this.standing.set(value)),
        subscribeSignal(reader.dialog, (value) => this.dialog.set(value)),
      ]
      onCleanup(() => {
        for (const unsubscribe of off) unsubscribe()
        stop()
        reader.close()
      })
    })
    // A comparison that has nothing left to show - accepted, or the revision moved - is closed, not parked.
    effect(() => {
      if (this.changes() === null) this.comparing.set(false)
    })
  }

  /**
   * The publication day of a revision the page lists, or its id when it lists none.
   *
   * @param revisionId The revision.
   */
  protected publishedOf(revisionId: string): string {
    const revision = this.terms()?.revisions.find(
      (item) => item.revisionId === revisionId,
    )

    return revision ? formatHilosLegalDate(revision.publishedOn) : revisionId
  }

  protected async onAccept(): Promise<void> {
    if (await this.store().accept()) this.comparing.set(false)
  }

  protected closeRevision(open: boolean): void {
    if (!open) this.reader().close()
  }

  protected closeChanges(open: boolean): void {
    if (!open) this.comparing.set(false)
  }
}
