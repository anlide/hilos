import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
} from '@angular/core'
import type {
  HilosLegalPublicDocumentReader,
  HilosLegalPublicDocumentCopy,
} from '@hilos/core/base'
import { HilosFormError } from '../HilosFormError.js'
import { LoadingButton } from '../LoadingButton.js'

/** The shared public Terms/Privacy reader line; verdicts and words come from core. */
@Component({
  selector: 'hilos-legal-document-reader',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError, LoadingButton],
  template: `
    @if (line().state === 'due') {
      <div class="alert alert-warning mb-0">
        <p class="mb-2" data-id="legal-document-reader-line">
          <i
            class="bi bi-exclamation-triangle-fill me-1"
            aria-hidden="true"
          ></i>
          {{ line().line }}
        </p>
        @if (line().plate; as plate) {
          <div
            class="d-flex flex-wrap align-items-center gap-2 rounded px-3 py-2 mb-2 small"
            [class]="
              'bg-' + plate.tone + '-subtle text-' + plate.tone + '-emphasis'
            "
            data-id="legal-document-reader-plate"
            [attr.data-tone]="plate.tone"
          >
            <i [class]="'bi ' + plate.icon" aria-hidden="true"></i>
            <strong>{{ plate.text }}</strong>
            @if (plate.detail !== null) {
              <span>{{ plate.detail }}</span>
            }
          </div>
        }
        @if (line().onlyPerson; as onlyPerson) {
          <p class="small mb-2" data-id="legal-document-reader-impersonated">
            {{ onlyPerson }}
          </p>
        }
        <div class="d-flex flex-wrap gap-2">
          @if (line().canAccept) {
            <button
              hilosLoadingButton
              class="btn-sm btn-primary"
              data-id="legal-document-accept"
              [loading]="accepting()"
              (click)="accept.emit()"
            >
              {{ copy().accept }}
            </button>
          }
          @if (line().canCompare) {
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              data-id="legal-document-changes-open"
              (click)="compare.emit()"
            >
              {{ copy().whatChanged }}
            </button>
          }
        </div>
        @if (line().canAccept) {
          <hilos-form-error
            [message]="refusal()"
            dataId="legal-document-accept-refusal"
            [announce]="true"
          />
        }
      </div>
    } @else {
      <div class="d-flex flex-wrap align-items-center gap-2">
        <p
          class="mb-0"
          [class.invisible]="hidden()"
          data-id="legal-document-reader-line"
          [attr.aria-hidden]="hidden()"
        >
          @if (green()) {
            <i
              class="bi bi-check-circle-fill text-success me-1"
              aria-hidden="true"
            ></i>
          }
          {{ line().line }}
        </p>
        @if (line().canCompare) {
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-id="legal-document-changes-open"
            (click)="compare.emit()"
          >
            {{ copy().whatChanged }}
          </button>
        }
      </div>
    }
  `,
})
export class HilosLegalDocumentReader {
  readonly line = input.required<HilosLegalPublicDocumentReader>()
  readonly copy = input.required<HilosLegalPublicDocumentCopy>()
  readonly accepting = input(false)
  readonly refusal = input<string | null>(null)
  readonly accept = output<void>()
  readonly compare = output<void>()
  protected readonly hidden = computed(() => this.line().state === 'loading')
  protected readonly green = computed(
    () => this.line().state === 'covered' || this.line().state === 'reworded',
  )
}
