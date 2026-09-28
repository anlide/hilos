import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core'
import {
  hilosLegalClauseIcon,
  hilosLegalConsentDeviations,
  hilosLegalDocumentLabel,
  type HilosLegalConsentTerms,
  type HilosLegalDocumentKey,
} from '@hilos/core'
import { HilosLegalRevisionText } from './HilosLegalRevisionText.js'

/** The current documents, shared by registration and the read-only admin preview. */
@Component({
  selector: 'hilos-legal-consent',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalRevisionText],
  template: `
    <div data-id="legal-consent">
      <div [hidden]="document() !== undefined">
        <p class="small text-body-secondary mb-3">
          This project runs on the standard Hilos terms. They are the same in
          every project built on the framework — you may have read them before.
          @if (deviations().length) {
            Below is only what this project does differently.
          }
        </p>
        <div class="border rounded mb-3">
          <button
            #standardToggle
            type="button"
            class="btn w-100 d-flex align-items-center gap-2 px-3 py-2 small text-start"
            data-id="legal-consent-standard-toggle"
            [attr.aria-expanded]="expanded()"
            (click)="expanded.set(!expanded())"
          >
            <i class="bi bi-shield-check text-success" aria-hidden="true"></i>
            <span class="flex-grow-1"
              >Standard Hilos terms · {{ clauses().length }} clauses</span
            >
            <i
              [class]="expanded() ? 'bi bi-chevron-up' : 'bi bi-chevron-down'"
              aria-hidden="true"
            ></i>
          </button>
          @if (expanded()) {
            <div class="border-top">
              @for (clause of clauses(); track clause.clauseKey) {
                <div
                  class="px-3 py-2 border-bottom small text-body-secondary text-break"
                  data-id="legal-consent-standard-item"
                >
                  {{ clause.standardStatement }}
                </div>
              }
            </div>
          }
        </div>
        @if (deviations().length) {
          <section class="border border-warning-subtle rounded mb-3">
            <h3
              class="h6 d-flex align-items-center gap-2 px-3 py-2 mb-0 border-bottom bg-warning-subtle text-warning-emphasis"
            >
              <i class="bi bi-exclamation-triangle" aria-hidden="true"></i
              >Different in this project · {{ deviations().length }}
            </h3>
            <ul class="list-unstyled mb-0 px-3">
              @for (clause of deviations(); track clause.clauseKey) {
                <li
                  class="d-flex gap-2 py-2 border-bottom text-break"
                  data-id="legal-consent-deviation"
                  [attr.data-clause]="clause.clauseKey"
                >
                  <i
                    [class]="
                      'bi text-body-secondary mt-1 ' + icon(clause.clauseKey)
                    "
                    aria-hidden="true"
                  ></i>
                  <div class="flex-grow-1">
                    <div class="small fw-semibold">
                      {{ clause.statement }}
                      <span
                        [class]="
                          'badge border ms-1 ' +
                          (clause.direction === 'stricter'
                            ? 'text-bg-warning-subtle bg-warning-subtle text-warning-emphasis border-warning-subtle'
                            : 'text-bg-success-subtle bg-success-subtle text-success-emphasis border-success-subtle')
                        "
                        data-id="legal-consent-direction"
                        >{{ clause.direction }}</span
                      >
                    </div>
                    <div class="small text-body-secondary">
                      Hilos standard: {{ clause.standardStatement }}
                    </div>
                  </div>
                </li>
              }
            </ul>
          </section>
        } @else {
          <div
            class="alert alert-success small py-2 mb-3"
            data-id="legal-consent-no-deviations"
          >
            <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
            This project does not differ from the standard — neither stricter
            nor looser. Only the standard terms need accepting.
          </div>
        }
        @if (terms().form === 'checkbox') {
          <div class="form-check mb-1">
            <label class="form-check-label small">
              <input
                #acceptInput
                type="checkbox"
                class="form-check-input"
                data-id="auth-consent-accept"
                [checked]="accepted()"
                [disabled]="disabled()"
                (change)="accept($event)"
              />
              {{
                deviations().length
                  ? 'I have read the differences and accept the terms'
                  : 'I accept the terms'
              }}
            </label>
          </div>
          <div class="small mb-3">
            @for (
              item of terms().documents;
              track item.document;
              let index = $index
            ) {
              @if (index) {
                ·
              }
              <button
                type="button"
                class="btn btn-link btn-sm p-0"
                data-id="legal-consent-read"
                [attr.data-document]="item.document"
                (click)="readingChange.emit(item.document)"
              >
                {{ label(item.document) }}
              </button>
            }
          </div>
        }
      </div>
      @if (document(); as readingDocument) {
        <div data-id="legal-consent-reading">
          <h3 #readingHeading tabindex="-1" class="h6">
            {{ label(readingDocument.document) }}
          </h3>
          <hilos-legal-revision-text [clauses]="readingDocument.clauses" />
          <button
            type="button"
            class="btn btn-link btn-sm px-0 mb-3"
            data-id="legal-consent-back"
            (click)="readingChange.emit(null)"
          >
            Back to the differences
          </button>
        </div>
      }
    </div>
  `,
})
export class HilosLegalConsent {
  readonly terms = input.required<HilosLegalConsentTerms>()
  readonly accepted = input(false)
  readonly reading = input<HilosLegalDocumentKey | null>(null)
  readonly disabled = input(false)
  readonly acceptedChange = output<boolean>()
  readonly readingChange = output<HilosLegalDocumentKey | null>()
  protected readonly expanded = signal(false)
  protected readonly clauses = computed(() =>
    this.terms().documents.flatMap((document) => document.clauses),
  )
  protected readonly deviations = computed(() =>
    hilosLegalConsentDeviations(this.terms()),
  )
  protected readonly document = computed(() =>
    this.terms().documents.find(
      (document) => document.document === this.reading(),
    ),
  )
  protected readonly icon = hilosLegalClauseIcon
  protected readonly label = hilosLegalDocumentLabel
  private readonly acceptInput =
    viewChild<ElementRef<HTMLInputElement>>('acceptInput')
  private readonly standardToggle =
    viewChild<ElementRef<HTMLButtonElement>>('standardToggle')
  private readonly readingHeading =
    viewChild<ElementRef<HTMLHeadingElement>>('readingHeading')
  private previousReading: HilosLegalDocumentKey | null = null

  constructor() {
    afterRenderEffect(() => {
      const reading = this.reading()
      if (reading === this.previousReading) return
      this.previousReading = reading
      if (reading !== null) this.readingHeading()?.nativeElement.focus()
      else this.standardToggle()?.nativeElement.focus()
    })
  }

  /** Move focus into the content after the async read mounts it. */
  focus(): void {
    if (this.reading() !== null) this.readingHeading()?.nativeElement.focus()
    else (this.acceptInput() ?? this.standardToggle())?.nativeElement.focus()
  }

  protected accept(event: Event): void {
    this.acceptedChange.emit((event.target as HTMLInputElement).checked)
  }
}
