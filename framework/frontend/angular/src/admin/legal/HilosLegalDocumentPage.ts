import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  computedSignal,
  createHilosLegalRevisionsTable,
  createHilosLegalConsentPreview,
  createHilosLegalReconsentPreview,
  hilosLegalDocumentLabel,
  LEGAL_TERMS_UNPUBLISHED_MESSAGE,
  HilosPages,
  HilosLegalRowKey,
  HILOS_TABLE_ACTIONS_KEY,
  LEGAL_CATALOG_REFUSAL_SECTION,
  LEGAL_DOCUMENT_SECTION,
  LEGAL_RECONSENT_COPY,
  legalAdminDocumentSchema,
  resolveHilosPath,
  subscribeSignal,
  type HilosLegalContext,
  type HilosLegalConsentTerms,
  type HilosLegalDocumentKey,
  type HilosLegalAdminDocument,
  type HilosLegalReconsentPreview,
  type HilosLegalReconsentView,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosLegalConsent } from '../../legal/HilosLegalConsent.js'
import { HilosLegalReconsent } from '../../legal/HilosLegalReconsent.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'

/** A document's adopted standard, deviations and revision history. */
@Component({
  selector: 'hilos-legal-document-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosLink,
    HilosTableCell,
    HilosViewportTable,
    HilosModal,
    HilosFormError,
    HilosLegalConsent,
    HilosLegalReconsent,
  ],
  template: `
    <hilos-admin-page [page]="page">
      @if (refusal() !== null) {
        <div
          class="alert alert-danger text-break"
          role="alert"
          data-id="legal-catalog-refusal"
        >
          <h2 class="h6">The legal document catalog could not be loaded</h2>
          <p class="mb-0">{{ refusal() }}</p>
        </div>
      } @else if (details(); as data) {
        <div class="alert alert-secondary small">
          Document texts live in code. A new revision is published by a
          deployment.
        </div>
        @if (data.set; as set) {
          <section data-id="legal-set" class="mb-4">
            <h2 class="h5">Hilos standard set {{ set.version }}</h2>
            <p class="small text-body-secondary">
              {{ set.publishedOn }} · {{ set.significance }} ·
              {{ set.clauses.length }} clauses
            </p>
            <ol class="list-group list-group-numbered">
              @for (clause of set.clauses; track clause.clauseKey) {
                <li class="list-group-item text-break">
                  <code class="small me-2">{{ clause.clauseKey }}</code
                  >{{ clause.statement }}
                </li>
              }
            </ol>
          </section>
        }
        @if (data.newerSet; as newer) {
          <section class="alert alert-warning" data-id="legal-set-newer">
            <h2 class="h6">
              Hilos has released set {{ newer.version }} for this document
            </h2>
            <p class="small">
              {{ newer.publishedOn }} · {{ newer.significance }}. The project
              keeps its declared set until it publishes a new revision.
            </p>
            <ul class="mb-0 small">
              @for (change of newer.changes; track change.clauseKey) {
                <li>
                  {{ change.kind }}: {{ change.title }}
                  <code>{{ change.clauseKey }}</code>
                </li>
              }
            </ul>
          </section>
        }
        <section class="mb-4">
          <h2 class="h5">Different in this project</h2>
          @if (!data.deviations.length) {
            <p class="text-body-secondary">
              No project deviations are declared.
            </p>
          }
          @for (deviation of data.deviations; track deviation.clauseKey) {
            <div
              class="border rounded p-3 mb-2 text-break"
              data-id="legal-deviation-row"
            >
              <h3 class="h6">
                {{ deviation.statement }}
                <span class="badge text-bg-secondary">{{
                  deviation.direction
                }}</span>
              </h3>
              <p class="small text-body-secondary">
                Standard: {{ deviation.standardStatement }} ·
                <code>{{ deviation.clauseKey }}</code>
              </p>
              @for (paragraph of deviation.text.split('\\n\\n'); track $index) {
                <p class="small mb-2">{{ paragraph }}</p>
              }
            </div>
          }
        </section>
        <section class="mb-4">
          <h2 class="h5">How a person sees it</h2>
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="legal-preview-consent"
            (click)="openPreview()"
          >
            <i class="bi bi-eye me-1" aria-hidden="true"></i>Consent screen at
            registration
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary ms-2"
            data-id="legal-preview-reconsent"
            (click)="openReconsent()"
          >
            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Re-consent
            screen
          </button>
        </section>
        <hilos-viewport-table [controller]="revisions().controller">
          <ng-template [hilosTableCell]="keys.rowKey" let-row
            ><div
              data-id="legal-revision-row"
              [attr.data-revision]="row.rowKey"
            >
              <strong>{{ row.rowKey }}</strong>
              @if (row.current) {
                <span class="badge text-bg-primary ms-2">Current</span>
              }
              @if (!row.declared) {
                <span class="badge text-bg-warning ms-2">Not in code</span>
              }
              @if (row.revision) {
                <div class="small text-body-secondary">
                  {{ row.revision.publishedOn }} ·
                  {{ row.revision.significance }} · Effective
                  {{ row.revision.effectiveOn }} · Set
                  {{ row.revision.setVersion }}
                </div>
              }
            </div></ng-template
          >
          <ng-template [hilosTableCell]="actionsKey" let-row
            ><a
              [hilosLink]="
                path(pages.LEGAL_REVISION, {
                  documentKey: data.document,
                  revisionId: row.rowKey,
                })
              "
              class="btn btn-sm btn-outline-secondary"
              data-id="legal-revision-open"
              [attr.data-revision]="row.rowKey"
              >Open</a
            ></ng-template
          >
        </hilos-viewport-table>
      }
      <hilos-modal
        [open]="previewOpen()"
        title="Consent screen at registration"
        initialFocus="dialog"
        (openChange)="$event ? undefined : preview().close()"
      >
        <div data-id="legal-consent-preview">
          <div class="visually-hidden" role="status" aria-live="polite">
            {{ previewError() ?? (previewLoading() ? 'Loading terms…' : '') }}
          </div>
          @if (previewLoading()) {
            <div
              class="placeholder-glow"
              data-id="legal-consent-loading"
              aria-busy="true"
            >
              <span class="placeholder col-12" aria-hidden="true"></span>
              <span class="placeholder col-9" aria-hidden="true"></span>
            </div>
          }
          @if (previewTerms(); as terms) {
            @if (terms.documents.length) {
              <hilos-legal-consent
                [terms]="terms"
                [accepted]="accepted()"
                [reading]="reading()"
                (acceptedChange)="accepted.set($event)"
                (readingChange)="reading.set($event)"
              />
            } @else {
              <p data-id="legal-consent-unpublished">
                {{ unpublishedMessage }}
              </p>
            }
            @if (terms.form === 'line' && terms.documents.length) {
              <p
                class="small text-body-secondary mb-0"
                data-id="auth-consent-line"
              >
                By creating an account you accept the
                @for (
                  item of terms.documents;
                  track item.document;
                  let index = $index
                ) {
                  @if (index) {
                    and the
                  }
                  <button
                    type="button"
                    class="btn btn-link btn-sm p-0"
                    data-id="legal-consent-read"
                    [attr.data-document]="item.document"
                    (click)="reading.set(item.document)"
                  >
                    {{ documentLabel(item.document) }}
                  </button>
                }
                .
              </p>
            }
          }
          <hilos-form-error
            [message]="previewError()"
            dataId="legal-consent-preview-error"
          />
        </div>
        <ng-template #modalActions>
          @if (previewError()) {
            <button
              type="button"
              class="btn btn-primary"
              data-id="legal-consent-preview-retry"
              (click)="openPreview()"
            >
              Try again
            </button>
          }
          <button
            type="button"
            class="btn btn-secondary"
            data-id="legal-consent-preview-close"
            (click)="preview().close()"
          >
            Close
          </button>
        </ng-template>
      </hilos-modal>
      <hilos-modal
        [open]="reconsentOpen()"
        title="Re-consent screen"
        initialFocus="dialog"
        (openChange)="$event ? undefined : reconsent().close()"
      >
        <div data-id="legal-reconsent-preview">
          <hilos-legal-reconsent
            variant="preview"
            [content]="reconsentPreview()"
            [view]="reconsentView()"
            [loading]="reconsentLoading()"
            [error]="reconsentError()"
            (retry)="openReconsent()"
            (show)="reconsent().show($event)"
          />
        </div>
        <ng-template #modalActions>
          <button
            type="button"
            class="btn btn-secondary"
            data-id="legal-reconsent-preview-close"
            (click)="reconsent().close()"
          >
            {{ reconsentCopy.close }}
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosLegalDocumentPage {
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL_DOCUMENT
  protected readonly pages = HilosPages
  protected readonly keys = HilosLegalRowKey
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  protected readonly path = resolveHilosPath
  protected readonly details = signal<HilosLegalAdminDocument | null>(null)
  protected readonly refusal = signal<string | null>(null)
  protected readonly preview = computed(() =>
    createHilosLegalConsentPreview(this.context()),
  )
  protected readonly previewOpen = signal(false)
  protected readonly previewTerms = signal<HilosLegalConsentTerms | null>(null)
  protected readonly previewLoading = signal(false)
  protected readonly previewError = signal<string | null>(null)
  // The "the terms have changed" screen as whoever held the previous revision
  // sees it today (HIL-500): the same component in a read-only modal.
  protected readonly reconsent = computed(() =>
    createHilosLegalReconsentPreview(this.context()),
  )
  protected readonly reconsentOpen = signal(false)
  protected readonly reconsentPreview =
    signal<HilosLegalReconsentPreview | null>(null)
  protected readonly reconsentLoading = signal(false)
  protected readonly reconsentError = signal<string | null>(null)
  protected readonly reconsentView = signal<HilosLegalReconsentView>({
    kind: 'changes',
  })
  protected readonly reconsentCopy = LEGAL_RECONSENT_COPY
  protected readonly accepted = signal(false)
  protected readonly reading = signal<HilosLegalDocumentKey | null>(null)
  protected readonly documentLabel = hilosLegalDocumentLabel
  protected readonly unpublishedMessage = LEGAL_TERMS_UNPUBLISHED_MESSAGE
  private readonly router = inject(HILOS_ROUTER)
  private readonly document = computedSignal(() =>
    String(this.router.currentRoute.get().params['documentKey'] ?? ''),
  )
  protected readonly revisions = computed(() =>
    createHilosLegalRevisionsTable(this.context(), this.document),
  )

  constructor() {
    effect((onCleanup) => {
      const context = this.context(),
        revisions = this.revisions()
      const source = context.scopes.pageDataSignal(LEGAL_DOCUMENT_SECTION)
      const refusal = context.scopes.pageDataSignal(
        LEGAL_CATALOG_REFUSAL_SECTION,
      )
      const read = () => {
        const parsed = legalAdminDocumentSchema.safeParse(source.get())
        this.details.set(parsed.success ? parsed.data : null)
      }
      const readRefusal = () => {
        const value = refusal.get()
        this.refusal.set(typeof value === 'string' ? value : null)
      }
      read()
      readRefusal()
      const preview = this.preview()
      this.previewOpen.set(preview.opened.get())
      this.previewTerms.set(preview.terms.get())
      this.previewLoading.set(preview.loading.get())
      this.previewError.set(preview.error.get())
      const off = [
        subscribeSignal(preview.opened, (value) => this.previewOpen.set(value)),
        subscribeSignal(preview.terms, (value) => this.previewTerms.set(value)),
        subscribeSignal(preview.loading, (value) =>
          this.previewLoading.set(value),
        ),
        subscribeSignal(preview.error, (value) => this.previewError.set(value)),
        subscribeSignal(source, read),
        subscribeSignal(refusal, readRefusal),
      ]
      const reconsent = this.reconsent()
      this.reconsentOpen.set(reconsent.opened.get())
      this.reconsentPreview.set(reconsent.preview.get())
      this.reconsentLoading.set(reconsent.loading.get())
      this.reconsentError.set(reconsent.error.get())
      this.reconsentView.set(reconsent.view.get())
      off.push(
        subscribeSignal(reconsent.opened, (value) =>
          this.reconsentOpen.set(value),
        ),
        subscribeSignal(reconsent.preview, (value) =>
          this.reconsentPreview.set(value),
        ),
        subscribeSignal(reconsent.loading, (value) =>
          this.reconsentLoading.set(value),
        ),
        subscribeSignal(reconsent.error, (value) =>
          this.reconsentError.set(value),
        ),
        subscribeSignal(reconsent.view, (value) =>
          this.reconsentView.set(value),
        ),
      )
      revisions.start()
      onCleanup(() => {
        for (const stop of off) stop()
        revisions.dispose()
        preview.close()
        reconsent.close()
      })
    })
  }
  protected openPreview(): void {
    this.accepted.set(false)
    this.reading.set(null)
    void this.preview().open()
  }
  protected openReconsent(): void {
    const details = this.details()
    if (details) void this.reconsent().open(details.document)
  }
}
