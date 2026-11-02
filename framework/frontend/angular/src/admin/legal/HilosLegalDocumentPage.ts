import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  computedSignal,
  createHilosLegalConsentPreview,
  createHilosLegalReconsentPreview,
  hilosLegalDocumentLabel,
  HilosPages,
  LEGAL_RECONSENT_COPY,
  type HilosLegalContext,
  type HilosLegalConsentTerms,
  type HilosLegalDocumentKey,
  type HilosLegalReconsentPreview,
  type HilosLegalReconsentView,
} from '@hilos/core/base'
import {
  createHilosLegalDocument,
  createHilosLegalSettingsTable,
  hilosLegalLapsedHref,
  hilosLegalLapsedLabel,
  HilosLegalSettingKey,
  type HilosLegalAdminDocument,
} from '@hilos/core/admin/legal'
import {
  HilosModal,
  HilosFormError,
  HilosLegalConsent,
  HilosLegalReconsent,
  HilosLink,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosLegalDocumentPageShell } from './HilosLegalDocumentPageShell.js'

/** Document provenance, current coverage and read-only agreement previews. */
@Component({
  selector: 'hilos-legal-document-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosLegalDocumentPageShell,
    HilosLink,
    HilosModal,
    HilosFormError,
    HilosLegalConsent,
    HilosLegalReconsent,
  ],
  template: `
    <hilos-legal-document-page-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        @if (details(); as data) {
          <div
            class="alert alert-secondary small text-break"
            data-id="legal-document-sources"
          >
            Standard sets: <code>{{ data.standardSetCatalogClass }}</code
            >.
            @if (data.projectCatalogClass) {
              Project revisions and deviations:
              <code>{{ data.projectCatalogClass }}</code
              >.
            }
            These declarations are read-only; publishing a revision means
            deploying it.
          </div>
          <section class="mb-4">
            <h2 class="h5">Current revision</h2>
            <div class="row row-cols-1 row-cols-sm-3 g-3">
              <div class="col">
                <section class="border rounded-3 p-3 h-100">
                  <h3 class="small text-body-secondary fw-normal">Covered</h3>
                  <div class="fs-4 fw-semibold" data-id="legal-count-covered">
                    {{ data.covered }}
                  </div>
                </section>
              </div>
              <div class="col">
                <section class="border rounded-3 p-3 h-100">
                  <h3 class="small text-body-secondary fw-normal">
                    Waiting for acceptance
                  </h3>
                  <div class="fs-4 fw-semibold" data-id="legal-count-window">
                    {{ data.window }}
                  </div>
                </section>
              </div>
              <div class="col">
                <section
                  class="border rounded-3 p-3 h-100"
                  data-id="legal-document-lapsed"
                >
                  <h3 class="small text-body-secondary fw-normal">
                    {{ lapsedLabel() }}
                  </h3>
                  <div class="fs-4 fw-semibold">
                    @if (data.lapsed > 0) {
                      <a
                        [hilosLink]="lapsedHref(data.document)"
                        data-id="legal-count-lapsed-link"
                      >
                        <span data-id="legal-count-lapsed">{{
                          data.lapsed
                        }}</span>
                      </a>
                    } @else {
                      <span data-id="legal-count-lapsed">{{
                        data.lapsed
                      }}</span>
                    }
                  </div>
                </section>
              </div>
            </div>
          </section>
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
              <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i
              >Re-consent screen
            </button>
          </section>
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
                  {{ terms.unpublishedMessage }}
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
      </ng-template>
    </hilos-legal-document-page-shell>
  `,
})
export class HilosLegalDocumentPage {
  /** The project's existing connection, actions and page scopes. */
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL_DOCUMENT
  protected readonly details = signal<
    HilosLegalAdminDocument | null | undefined
  >(undefined)
  protected readonly lapsedHref = hilosLegalLapsedHref
  protected readonly lapsedLabel = signal(hilosLegalLapsedLabel(undefined))
  private readonly binding = computed(() => {
    const context = this.context()
    const settings = createHilosLegalSettingsTable(
      context,
      HilosPages.LEGAL_DOCUMENT,
    )
    return {
      document: createHilosLegalDocument(context),
      settings,
      lapsedLabel: computedSignal(() =>
        hilosLegalLapsedLabel(
          settings.controller.rows
            .get()
            .find(
              ({ row }) =>
                row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
            )?.row?.value,
        ),
      ),
    }
  })
  protected readonly preview = computed(() =>
    createHilosLegalConsentPreview(this.context()),
  )
  protected readonly previewOpen = signal(false)
  protected readonly previewTerms = signal<HilosLegalConsentTerms | null>(null)
  protected readonly previewLoading = signal(false)
  protected readonly previewError = signal<string | null>(null)
  // The same read-only screen as a holder of the previous revision sees today.
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

  constructor() {
    effect((onCleanup) => {
      const binding = this.binding()
      const preview = this.preview()
      const reconsent = this.reconsent()
      const off = [
        mirrorHilosSignal(binding.document, this.details),
        mirrorHilosSignal(binding.lapsedLabel, this.lapsedLabel),
        mirrorHilosSignal(preview.opened, this.previewOpen),
        mirrorHilosSignal(preview.terms, this.previewTerms),
        mirrorHilosSignal(preview.loading, this.previewLoading),
        mirrorHilosSignal(preview.error, this.previewError),
        mirrorHilosSignal(reconsent.opened, this.reconsentOpen),
        mirrorHilosSignal(reconsent.preview, this.reconsentPreview),
        mirrorHilosSignal(reconsent.loading, this.reconsentLoading),
        mirrorHilosSignal(reconsent.error, this.reconsentError),
        mirrorHilosSignal(reconsent.view, this.reconsentView),
      ]
      binding.settings.start()
      onCleanup(() => {
        for (const stop of off) stop()
        binding.settings.dispose()
        binding.settings.controller.dispose()
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
