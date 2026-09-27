import { NgTemplateOutlet } from '@angular/common'
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { HilosLegalChange } from '@hilos/core'

/** Responsive comparison retaining both sides inside each clause. */
@Component({
  selector: 'hilos-legal-changes',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgTemplateOutlet],
  template: `
    <ng-template #clauseSide let-side>
      @if (side) {
        <div class="small text-body-secondary">
          {{
            side.source === 'standard'
              ? 'Hilos standard text'
              : 'Project deviation'
          }}
        </div>
        <strong>{{ side.statement }}.</strong>
        @for (paragraph of side.text.split('\\n\\n'); track $index) {
          <p class="mb-2">{{ paragraph }}</p>
        }
        @if (side.direction) {
          <span class="small text-body-secondary">{{ side.direction }}</span>
        }
      } @else {
        <span class="text-body-secondary">Not present</span>
      }
    </ng-template>
    <div data-id="legal-changes" class="text-break">
      @if (changes().length === 0) {
        <p class="text-body-secondary mb-0" data-id="legal-changes-empty">
          No clause changed
        </p>
      } @else {
        <div
          class="d-none d-md-block border rounded"
          data-id="legal-changes-wide"
        >
          <div class="row g-0 border-bottom small fw-semibold bg-body-tertiary">
            <div class="col-6 px-3 py-2 border-end">
              Before — revision {{ fromLabel() }}
            </div>
            <div class="col-6 px-3 py-2">After — revision {{ toLabel() }}</div>
          </div>
          @for (change of changes(); track change.clauseKey) {
            <div class="row g-0 border-bottom" data-id="legal-change-row">
              <div class="col-12 px-3 pt-2 small fw-semibold">
                {{ change.title
                }}<span
                  class="badge text-bg-light border ms-1 text-capitalize"
                  data-id="legal-change-kind"
                  >{{ change.kind }}</span
                >
                <span class="text-body-secondary fw-normal ms-1">{{
                  change.clauseKey
                }}</span>
              </div>
              @for (side of [change.before, change.after]; track $index) {
                <div class="col-6 px-3 py-2" [class.border-end]="$index === 0">
                  <ng-container
                    [ngTemplateOutlet]="clauseSide"
                    [ngTemplateOutletContext]="{ $implicit: side }"
                  />
                </div>
              }
            </div>
          }
        </div>
        <div class="d-md-none" data-id="legal-changes-narrow">
          @for (change of changes(); track change.clauseKey) {
            <div class="border rounded mb-2" data-id="legal-change-row">
              <div
                class="px-3 py-2 border-bottom small fw-semibold bg-body-tertiary"
              >
                {{ change.title
                }}<span
                  class="badge text-bg-light border ms-1 text-capitalize"
                  data-id="legal-change-kind"
                  >{{ change.kind }}</span
                >
                <span class="text-body-secondary fw-normal ms-1">{{
                  change.clauseKey
                }}</span>
              </div>
              @for (side of [change.before, change.after]; track $index) {
                <div class="px-3 py-2" [class.border-bottom]="$index === 0">
                  <div class="small fw-semibold">
                    {{ $index === 0 ? 'Before' : 'After' }} — revision
                    {{ $index === 0 ? fromLabel() : toLabel() }}
                  </div>
                  <ng-container
                    [ngTemplateOutlet]="clauseSide"
                    [ngTemplateOutletContext]="{ $implicit: side }"
                  />
                </div>
              }
            </div>
          }
        </div>
      }
    </div>
  `,
})
export class HilosLegalChanges {
  readonly changes = input.required<HilosLegalChange[]>()
  readonly fromLabel = input.required<string>()
  readonly toLabel = input.required<string>()
}
